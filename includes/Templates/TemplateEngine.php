<?php
/**
 * Template engine for the FaraCart pluggable progress templates.
 *
 * @package FaraCart
 */

namespace FaraCart\Templates;

use FaraCart\Missions\Mission;
use FaraCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class TemplateEngine
 *
 * The single place that resolves *which* template + settings render for a
 * mission or a campaign, and the single validator for template settings.
 *
 * Resolution order (same for the live storefront and the admin preview,
 * so what a merchant previews is what customers see):
 *
 *   1. item override  — mission display_settings / campaign display_rules:
 *      `template_id` + `template_settings`
 *   2. scope default  — plugin settings `template_defaults[scope]` plus
 *      the stored per-template default appearance
 *      `template_settings[scope][template_id]`
 *   3. store-wide fallback (missions only) — the Appearance setting
 *      `frontend_template` + the `frontend_*` appearance tokens
 *   4. hardcoded fallback — `template-1` (missions only; campaigns with no
 *      template render per-mission cards, the pre-engine behavior)
 *
 * If a stored template_id is not registered (e.g. an old Phase 12 id
 * such as 'card', or a template that was removed), resolution falls back
 * to the scope default / store-wide value rather than mapping it to a
 * different template — old template ids are never translated, and a
 * removed template can never break rendering.
 *
 * Settings validation is schema-driven: every field is sanitized against
 * the template's schema (colors, ranges, enums, tag-free CSS), so
 * client-side validation is never trusted.
 */
final class TemplateEngine {

	/**
	 * Mission scope.
	 *
	 * @var string
	 */
	const SCOPE_MISSION = 'mission';

	/**
	 * Campaign scope.
	 *
	 * @var string
	 */
	const SCOPE_CAMPAIGN = 'campaign';

	/**
	 * Hardcoded fallback template for missions.
	 *
	 * @var string
	 */
	const FALLBACK_MISSION = 'template-1';

	/**
	 * Template registry instance.
	 *
	 * @var TemplateRegistry
	 */
	protected $registry;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param TemplateRegistry $registry Template registry.
	 * @param Settings         $settings Settings service.
	 */
	public function __construct( TemplateRegistry $registry, Settings $settings ) {
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * The underlying registry.
	 *
	 * @return TemplateRegistry
	 */
	public function registry() {
		return $this->registry;
	}

	/**
	 * Resolve the effective template + settings for a mission.
	 *
	 * @param Mission $mission Mission.
	 * @return array{template_id: string, settings: array<string, mixed>}
	 */
	public function resolve_mission( Mission $mission ) {
		$display    = $mission->display_settings();
		$store_wide = (string) $this->settings->get( 'frontend_template', self::FALLBACK_MISSION );

		return $this->resolve( self::SCOPE_MISSION, is_array( $display ) ? $display : array(), $store_wide );
	}

	/**
	 * Resolve the effective template + settings for a campaign.
	 *
	 * @param array<string, mixed> $display_rules Campaign display_rules.
	 * @return array{template_id: string, settings: array<string, mixed>}
	 */
	public function resolve_campaign( array $display_rules ) {
		return $this->resolve( self::SCOPE_CAMPAIGN, $display_rules, '' );
	}

	/**
	 * Core resolution.
	 *
	 * @param string $scope            SCOPE_MISSION | SCOPE_CAMPAIGN.
	 * @param array  $display          Item display config (display_settings /
	 *                                 display_rules).
	 * @param string $store_wide_template Store-wide mission template ('' for
	 *                                 campaigns, which have no store-wide
	 *                                 template setting).
	 * @return array{template_id: string, settings: array<string, mixed>}
	 */
	public function resolve( $scope, array $display, $store_wide_template = '' ) {
		$template_id = '';

		// 1. Item override: the mission/campaign pins its own template.
		$override    = isset( $display['template_id'] ) ? (string) $display['template_id'] : '';
		$template_id = $this->normalize_template_id( $scope, $override );

		// 2. Scope default from plugin settings.
		if ( '' === $template_id ) {
			$defaults = $this->settings->get( 'template_defaults', array() );
			$defaults = is_array( $defaults ) ? $defaults : array();
			$template_id = isset( $defaults[ $scope ] ) ? $this->normalize_template_id( $scope, $defaults[ $scope ] ) : '';
		}

		// 3. Store-wide fallback (mission scope only): the Appearance template
		// setting keeps working for missions that pin no template of their
		// own.
		if ( '' === $template_id && self::SCOPE_MISSION === $scope ) {
			$template_id = $this->normalize_template_id( $scope, $store_wide_template );
		}

		// 4. Hardcoded fallback: 'template-1' for missions; campaigns without
		// a template render per-mission cards (the pre-engine behavior).
		if ( '' === $template_id && self::SCOPE_MISSION === $scope ) {
			$template_id = self::FALLBACK_MISSION;
		}

		if ( '' === $template_id ) {
			return array(
				'template_id' => '',
				'settings'    => array(),
			);
		}

		$settings = $this->resolve_settings( $scope, $this->registry->get( $template_id ), $display );

		return array(
			'template_id' => $template_id,
			'settings'    => $settings,
		);
	}

	/**
	 * Whether a template id is registered and usable in a scope.
	 *
	 * @param string $scope SCOPE_MISSION | SCOPE_CAMPAIGN.
	 * @param mixed  $id    Candidate template id.
	 * @return bool
	 */
	public function is_registered( $scope, $id ) {
		if ( ! is_string( $id ) || '' === $id || ! $this->registry->has( $id ) ) {
			return false;
		}

		$template = $this->registry->get( $id );
		$tpl_scope = $template->scope();

		return $scope === $tpl_scope || 'both' === $tpl_scope;
	}

	/**
	 * Normalize a candidate template id for a scope ('' when invalid).
	 *
	 * Only currently registered template ids resolve; anything else
	 * (an old Phase 12 id such as 'card', or a removed template) returns
	 * '' so resolution falls through to the scope default / store-wide
	 * value / hardcoded fallback. Old template ids are never translated.
	 *
	 * @param string $scope SCOPE_MISSION | SCOPE_CAMPAIGN.
	 * @param mixed  $id    Candidate template id.
	 * @return string
	 */
	public function normalize_template_id( $scope, $id ) {
		if ( $this->is_registered( $scope, $id ) ) {
			return (string) $id;
		}

		return '';
	}

	/**
	 * Sanitize raw settings against a template's schema.
	 *
	 * Iterates the schema, sanitizes each present value (or applies the
	 * field default), and drops any key the schema does not declare — a
	 * bad stored or submitted value can never reach the storefront.
	 *
	 * @param Template $template Template instance.
	 * @param mixed    $raw      Raw settings (array or JSON-ish value).
	 * @return array<string, mixed> Complete, schema-conformant settings.
	 */
	public function sanitize_settings( Template $template, $raw ) {
		$clean = array();

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		foreach ( $template->schema() as $key => $field ) {
			$value = array_key_exists( $key, $raw ) ? $raw[ $key ] : ( isset( $field['default'] ) ? $field['default'] : '' );
			$clean[ $key ] = $this->sanitize_field( $field, $value );
		}

		return $clean;
	}

	/**
	 * Sanitize a settings payload for a whole scope (Settings REST save).
	 *
	 * @param string $scope SCOPE_MISSION | SCOPE_CAMPAIGN.
	 * @param mixed  $raw   Raw [ template_id => settings ] map.
	 * @return array<string, array<string, mixed>> Cleaned template => settings map.
	 */
	public function sanitize_scope_settings( $scope, $raw ) {
		$clean = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		foreach ( $raw as $template_id => $settings ) {
			if ( ! is_string( $template_id ) || ! $this->is_registered( $scope, $template_id ) ) {
				continue;
			}

			$clean[ $template_id ] = $this->sanitize_settings( $this->registry->get( $template_id ), $settings );
		}

		return $clean;
	}

	/**
	 * The stored template versions per scope (migration metadata).
	 *
	 * @return array<string, array<string, int>>
	 */
	public function versions() {
		$versions = array();

		foreach ( array( self::SCOPE_MISSION, self::SCOPE_CAMPAIGN ) as $scope ) {
			$versions[ $scope ] = array();

			foreach ( $this->registry->for_scope( $scope ) as $template ) {
				$versions[ $scope ][ $template->id() ] = $template->version();
			}
		}

		return $versions;
	}

	/**
	 * The scope default template ids (missions always resolve; campaigns may
	 * be '' = no campaign template → per-mission cards).
	 *
	 * @return array<string, string>
	 */
	public function default_ids() {
		$defaults = $this->settings->get( 'template_defaults', array() );
		$defaults = is_array( $defaults ) ? $defaults : array();

		return array(
			self::SCOPE_MISSION     => $this->resolve_mission_default_id( $defaults ),
			self::SCOPE_CAMPAIGN => isset( $defaults[ self::SCOPE_CAMPAIGN ] )
				? $this->normalize_template_id( self::SCOPE_CAMPAIGN, $defaults[ self::SCOPE_CAMPAIGN ] )
				: '',
		);
	}

	/**
	 * The full template registry payload for the admin (TemplatesController).
	 *
	 * @return array<string, mixed>
	 */
	public function data() {
		$data = array(
			'scopes'   => array( self::SCOPE_MISSION, self::SCOPE_CAMPAIGN ),
			'defaults' => $this->default_ids(),
		);

		foreach ( array( self::SCOPE_MISSION, self::SCOPE_CAMPAIGN ) as $scope ) {
			$definitions = array();

			foreach ( $this->registry->for_scope( $scope ) as $template ) {
				$definitions[] = array(
					'id'          => $template->id(),
					'label'       => $template->label(),
					'description' => $template->description(),
					'version'     => $template->version(),
					'scope'       => $template->scope(),
					'schema'      => $this->schema_fields( $template ),
					'settings'    => $this->stored_or_default( $scope, $template ),
				);
			}

			$data[ $scope ] = $definitions;
		}

		$data['versions'] = $this->versions();

		return $data;
	}

	/**
	 * Flatten a template's schema into an ordered list of field objects.
	 *
	 * @param Template $template Template instance.
	 * @return array<int, array<string, mixed>>
	 */
	protected function schema_fields( Template $template ) {
		$fields = array();

		foreach ( $template->schema() as $key => $field ) {
			$fields[] = array_merge( array( 'key' => $key ), $field );
		}

		return $fields;
	}

	/**
	 * The effective settings a template would render with right now.
	 *
	 * @param string   $scope    SCOPE_MISSION | SCOPE_CAMPAIGN.
	 * @param Template $template Template instance.
	 * @return array<string, mixed>
	 */
	protected function stored_or_default( $scope, Template $template ) {
		$stored = $this->settings->get( 'template_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$scope_stored = isset( $stored[ $scope ][ $template->id() ] ) ? $stored[ $scope ][ $template->id() ] : array();

		return $this->resolve_settings( $scope, $template, array( 'template_settings' => $scope_stored ) );
	}

	/**
	 * Merge the effective settings layers for a template.
	 *
	 * @param string   $scope    SCOPE_MISSION | SCOPE_CAMPAIGN.
	 * @param Template $template Template instance.
	 * @param array    $display  Item display config.
	 * @return array<string, mixed>
	 */
	protected function resolve_settings( $scope, Template $template, array $display ) {
		$stored = $this->settings->get( 'template_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();

		$scope_stored = null;
		if ( isset( $stored[ $scope ][ $template->id() ] ) && is_array( $stored[ $scope ][ $template->id() ] ) ) {
			$scope_stored = $stored[ $scope ][ $template->id() ];
		}

		$override = array();
		if ( isset( $display['template_settings'] ) && is_array( $display['template_settings'] ) ) {
			$override = $display['template_settings'];
		}

		$base = $template->default_settings();

		// A template that was never configured keeps tracking the legacy
		// Appearance surface (frontend_* tokens) so existing stores see no
		// visual change until they explicitly customize this template —
		// only for templates that opt in. The design templates ship their
		// own reference defaults and never inherit the legacy tokens, so an
		// unconfigured template renders exactly like its default design.
		if ( null === $scope_stored && empty( $override ) && $this->inherits_legacy( $template ) ) {
			$base = array_merge( $base, $this->legacy_tokens_for( $template ) );
		}

		if ( null !== $scope_stored ) {
			$base = array_merge( $base, $scope_stored );
		}

		if ( ! empty( $override ) ) {
			$base = array_merge( $base, $override );
		}

		return $this->sanitize_settings( $template, $base );
	}

	/**
	 * Whether a template inherits the legacy frontend_* appearance tokens.
	 *
	 * @param Template $template Template instance.
	 * @return bool
	 */
	protected function inherits_legacy( Template $template ) {
		return method_exists( $template, 'inherits_legacy' ) && $template->inherits_legacy();
	}

	/**
	 * Map the legacy frontend_* appearance tokens onto a template's
	 * schema keys (only the shared ones — template-specific fields keep
	 * their schema defaults).
	 *
	 * @param Template $template Template instance.
	 * @return array<string, mixed>
	 */
	protected function legacy_tokens_for( Template $template ) {
		$legacy = array(
			'accent'    => (string) $this->settings->get( 'frontend_accent', '#2271b1' ),
			'bg'        => (string) $this->settings->get( 'frontend_bg', '#ffffff' ),
			'border'    => (string) $this->settings->get( 'frontend_border', '#dcdcde' ),
			'text'      => (string) $this->settings->get( 'frontend_text', '#1d2327' ),
			'radius'    => (int) $this->settings->get( 'frontend_radius', 10 ),
			'barHeight' => (int) $this->settings->get( 'frontend_bar_height', 10 ),
			'animation' => (bool) $this->settings->get( 'frontend_animation', true ),
		);

		$schema = $template->schema();
		$tokens = array();

		foreach ( $legacy as $key => $value ) {
			if ( array_key_exists( $key, $schema ) ) {
				$tokens[ $key ] = $value;
			}
		}

		return $tokens;
	}

	/**
	 * Resolve the mission-scope default template id.
	 *
	 * @param array<string, mixed> $defaults template_defaults setting.
	 * @return string
	 */
	protected function resolve_mission_default_id( array $defaults ) {
		if ( isset( $defaults[ self::SCOPE_MISSION ] ) ) {
			$id = $this->normalize_template_id( self::SCOPE_MISSION, $defaults[ self::SCOPE_MISSION ] );

			if ( '' !== $id ) {
				return $id;
			}
		}

		$legacy = $this->normalize_template_id( self::SCOPE_MISSION, $this->settings->get( 'frontend_template', self::FALLBACK_MISSION ) );

		return '' !== $legacy ? $legacy : self::FALLBACK_MISSION;
	}

	/**
	 * Sanitize a single field value against its schema definition.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw value.
	 * @return mixed
	 */
	protected function sanitize_field( array $field, $value ) {
		$type = isset( $field['type'] ) ? $field['type'] : 'text';

		switch ( $type ) {
			case 'color':
				$color = sanitize_hex_color( (string) $value );

				return $color ? $color : $field['default'];

			case 'number':
				$number = (int) $value;

				if ( isset( $field['min'] ) ) {
					$number = max( (int) $field['min'], $number );
				}
				if ( isset( $field['max'] ) ) {
					$number = min( (int) $field['max'], $number );
				}

				return $number;

			case 'bool':
				return (bool) $value;

			case 'select':
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? array_keys( $field['options'] ) : array();

				return in_array( (string) $value, $options, true ) ? (string) $value : $field['default'];

			case 'css':
				// Admin-authored CSS: tag-free and bounded.
				return substr( trim( wp_strip_all_tags( (string) $value ) ), 0, 16000 );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
