import PaletteIcon from "@mui/icons-material/Palette";
import RestartAltIcon from "@mui/icons-material/RestartAlt";
import RocketLaunchIcon from "@mui/icons-material/RocketLaunch";
import StorefrontIcon from "@mui/icons-material/Storefront";
import Alert from "@mui/material/Alert";
import Box from "@mui/material/Box";
import Button from "@mui/material/Button";
import Chip from "@mui/material/Chip";
import FormControl from "@mui/material/FormControl";
import Grid from "@mui/material/Grid";
import InputLabel from "@mui/material/InputLabel";
import MenuItem from "@mui/material/MenuItem";
import Paper from "@mui/material/Paper";
import Select from "@mui/material/Select";
import Skeleton from "@mui/material/Skeleton";
import Stack from "@mui/material/Stack";
import Tab from "@mui/material/Tab";
import Tabs from "@mui/material/Tabs";
import Typography from "@mui/material/Typography";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import { useEffect, useMemo, useRef, useState } from "react";
import { fetchSettingsEnvelope, saveSettings } from "../api/settings";
import { getBootData } from "../boot";
import { useSnackbar } from "../components/notifications/SnackbarProvider";
import PageContainer from "../components/PageContainer";
import PreviewControls from "../components/preview/PreviewControls";
import PreviewWidget from "../components/preview/PreviewWidget";
import {
	PRESET_PERCENTS,
	tokensFromSettings,
	type PreviewPreset,
} from "../components/preview/types";
import { useStickyBarActions } from "../providers/ActionBarProvider";
import { useFullscreen } from "../providers/FullscreenProvider";
import SchemaForm from "../templates/SchemaForm";
import { templateById, useTemplates } from "../templates/useTemplates";
import { bool } from "../templates/utils";
import type {
	ProgressCampaign,
	ProgressMission,
	TemplateDefinition,
	TemplateScope,
	TemplateSettingsValue,
} from "../types";

const SCOPES: TemplateScope[] = ["mission", "campaign"];

/**
 * A sample mission for the live previews — the reference design's demo
 * values scaled to a preview-state fraction (0 = empty cart → 1 =
 * completed), plus two sample recommended products so every template
 * demonstrates its actual capabilities. Preview-only; never used as
 * production data.
 */
function sampleMissionAt(
	fraction: number,
	overrides: Partial<ProgressMission> = {},
): ProgressMission {
	const target = overrides.target ?? 2000000;
	const percentage = Math.min(100, Math.round(fraction * 100));
	const completed = fraction >= 1;
	const current = Math.round(target * fraction);
	const remaining = Math.max(0, target - current);

	return {
		mission_id: 1,
		campaign_id: 0,
		mission_name: __("Free shipping", "faracart"),
		mission_type: "amount",
		is_money: true,
		icon: "🎯",
		template: "template-1",
		template_settings: {},
		current,
		target,
		remaining,
		percentage,
		completed,
		state: completed
			? "completed"
			: percentage >= 80
				? "nearly_complete"
				: "progressing",
		message: completed
			? __("You reached your mission!", "faracart")
			: __("Only %s left to reach your mission", "faracart").replace(
					"%s",
					remaining.toLocaleString(),
				),
		reward: { type: "free_shipping", value: null, max_value: null, meta: {} },
		suggestions: [
			{
				id: 1,
				name: __("Classic cotton t-shirt", "faracart"),
				permalink: "#",
				price: 290000,
				price_html: "",
				image: "",
				stock_status: "instock",
				source: "suggestion",
			},
			{
				id: 2,
				name: __("Baseball cap", "faracart"),
				permalink: "#",
				price: 180000,
				price_html: "",
				image: "",
				stock_status: "instock",
				source: "suggestion",
			},
		],
		reward_state: "locked",
		eligible: true,
		reason: "",
		conflict: { resolved: true, reason: "" },
		...overrides,
	};
}

/** Three sample milestones for the campaign previews, at a state fraction. */
function sampleMilestonesAt(fraction: number): ProgressMission[] {
	return [
		sampleMissionAt(fraction, {
			mission_id: 1,
			mission_name: __("Free shipping", "faracart"),
			target: 100,
			reward: { type: "free_shipping", value: null, max_value: null, meta: {} },
		}),
		sampleMissionAt(fraction, {
			mission_id: 2,
			mission_name: __("Free gift", "faracart"),
			target: 200,
			reward: { type: "free_gift", value: null, max_value: null, meta: {} },
		}),
		sampleMissionAt(fraction, {
			mission_id: 3,
			mission_name: __("10% off", "faracart"),
			target: 300,
			reward: {
				type: "percent_discount",
				value: 10,
				max_value: null,
				meta: {},
			},
		}),
	];
}

/**
 * The live preview of one template with its current draft appearance —
 * rendered through PreviewWidget (the same component the preview
 * dialogs use), so what the merchant sees here matches the storefront.
 * Framed like the Mission/Campaign builder preview (PreviewPanel): a chip
 * header (progress + template), the rendered widget on a gray stage, and
 * the Preview state control (empty cart → completed) below.
 */
function ScopeLivePreview({
	scope,
	id,
	drafts,
	templates,
	tokens,
	currency,
	preset,
	onPresetChange,
}: {
	scope: TemplateScope;
	id: string;
	drafts: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
	templates: TemplateDefinition[];
	tokens: ReturnType<typeof tokensFromSettings>;
	currency: string;
	preset: PreviewPreset;
	onPresetChange: (preset: PreviewPreset) => void;
}) {
	if (scope === "campaign" && id === "") {
		return (
			<Alert severity="info" variant="outlined">
				{__(
					"No campaign template selected — each milestone renders as its own mission card on the storefront.",
					"faracart",
				)}
			</Alert>
		);
	}

	const definition = templates.find((template) => template.id === id);

	if (!definition) {
		return null;
	}

	const settings = drafts[scope][id] ?? definition.settings;
	const animation = bool(settings, "animation", true);
	// The sample missions scale with the chosen preview state (empty → done).
	const fraction = PRESET_PERCENTS[preset];

	// The sample milestones must carry the campaign's id so PreviewWidget's
	// grouping (mission.campaign_id → campaign) actually joins them into the
	// campaign and renders it through the selected campaign template —
	// otherwise they fall through as standalone mission cards and the campaign
	// preview always shows the wrong (basic) rendering.
	const campaign: ProgressCampaign = {
		campaign_id: 999,
		name: __("Sample campaign", "faracart"),
		template: id,
		settings,
	};

	const missions =
		scope === "mission"
			? [sampleMissionAt(fraction)]
			: sampleMilestonesAt(fraction).map((mission) => ({
					...mission,
					campaign_id: campaign.campaign_id,
				}));

	const completedCount = missions.filter((mission) => mission.completed).length;
	const percent = Math.round(missions[0].percentage);
	const stateLabel =
		scope === "campaign"
			? sprintf(
					/* translators: %1$d: completed milestones, %2$d: total milestones. */
					__("%1$d/%2$d milestones", "faracart"),
					completedCount,
					missions.length,
				)
			: sprintf(__("%d%% progress", "faracart"), percent);

	return (
		<Stack spacing={2}>
			{/* The rendered preview — the same frame the Mission/Campaign
            builders use (chips + gray stage). */}
			<Paper
				variant="outlined"
				sx={{ p: { xs: 2, md: 3 }, bgcolor: "#f6f7f7" }}
			>
				<Box
					sx={{
						display: "flex",
						alignItems: "center",
						justifyContent: "space-between",
						mb: 2,
						gap: 1,
					}}
				>
					<Chip size="small" variant="outlined" label={stateLabel} />
					<Chip size="small" variant="outlined" label={definition.label} />
				</Box>

				{scope === "mission" ? (
					<PreviewWidget
						missions={missions}
						currency={currency}
						tokens={tokens}
						templateOverride={id}
						settingsOverride={settings}
						rewardState="auto"
						animation={animation}
					/>
				) : (
					<PreviewWidget
						missions={missions}
						campaigns={[campaign]}
						currency={currency}
						tokens={tokens}
						rewardState="auto"
						animation={animation}
					/>
				)}
			</Paper>

			{/* Preview state — the same control the Mission/Campaign builders
            use, so the appearance preview shows any progress state. */}
			<Paper variant="outlined" sx={{ p: 2.5 }}>
				<PreviewControls value={{ preset }} onApplyPreset={onPresetChange} />
			</Paper>
		</Stack>
	);
}

/**
 * The single active template panel: the schema-driven appearance form (the
 * same SchemaForm the Mission and Campaign builders use) plus a "reset to
 * template defaults" action that restores the factory schema defaults.
 * Only ever mounted for the template currently selected in the dropdown.
 */
function TemplateSettingsPanel({
	scope,
	definition,
	drafts,
	onChange,
	onReset,
}: {
	scope: TemplateScope;
	definition: TemplateDefinition;
	drafts: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
	onChange: (id: string, next: TemplateSettingsValue) => void;
	onReset: (id: string) => void;
}) {
	return (
		<Paper variant="outlined" sx={{ p: { xs: 2, md: 2.5 } }}>
			<Stack spacing={2}>
				<Box>
					<Typography
						variant="overline"
						color="text.secondary"
						sx={{ display: "block", mb: 0.5 }}
					>
						{__("Template appearance", "faracart")}
					</Typography>
					<Stack
						direction="row"
						sx={{
							alignItems: "baseline",
							justifyContent: "space-between",
							gap: 1,
							flexWrap: "wrap",
						}}
					>
						<Box>
							<Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
								{definition.label}
							</Typography>
						</Box>
					</Stack>
				</Box>
				<SchemaForm
					schema={definition.schema}
					value={drafts[scope][definition.id] ?? definition.settings}
					onChange={(next) => onChange(definition.id, next)}
				/>
				<Box>
					<Button
						size="small"
						startIcon={<RestartAltIcon />}
						onClick={() => onReset(definition.id)}
					>
						{__("Reset to template defaults", "faracart")}
					</Button>
				</Box>
			</Stack>
		</Paper>
	);
}

/**
 * Appearance (pluggable template engine): the storefront progress UI is
 * template-driven, independently for Missions and Campaigns.
 *
 *  - the layout mirrors the Mission/Campaign builders: a two-column grid
 *    with the settings on the right (RTL) and a sticky live preview on
 *    the left (single column on small screens),
 *  - Tabs switch between the Mission and Campaign scopes (only one is visible
 *    at a time),
 *  - a dropdown lists every registered template for the active scope,
 *    defaulting to that scope's current default template,
 *  - selecting a template shows only that template's live preview (left
 *    column) + the schema-driven appearance form (right column) — mounted
 *    lazily, so no inactive template's form ever sits in the DOM,
 *  - the save action persists the scope default + every edited template's
 *    appearance through `POST /faracart/v1/settings` as `template_defaults`
 *    + `template_settings` (identical semantics to the previous layout).
 *
 * The legacy `frontend_*` surface stays honored by the engine as the
 * fallback for templates that were never configured.
 */
export default function Appearance() {
	const queryClient = useQueryClient();
	const { notify } = useSnackbar();
	const { fullscreen } = useFullscreen();
	const templatesQuery = useTemplates();
	const settingsQuery = useQuery({
		queryKey: ["settings"],
		queryFn: fetchSettingsEnvelope,
	});

	const templates = templatesQuery.data;
	const settings = settingsQuery.data?.data;
	const tokens = tokensFromSettings(settings);
	const currency = useMemo(() => {
		try {
			return getBootData().currency;
		} catch {
			return "USD";
		}
	}, []);

	// The sticky preview column sticks below the WP admin bar in embedded
	// mode (32px) and flush in full-screen mode where the app's own header
	// is fixed and the content area scrolls internally.
	const stickyTop = fullscreen ? 8 : 40;

	// Active tab: 0 = Mission, 1 = Campaign.
	const [tab, setTab] = useState(0);
	const scope = SCOPES[tab];

	// Preview-state preset for the live preview (the same control the
	// Mission/Campaign builders use: empty cart → completed).
	const [preset, setPreset] = useState<PreviewPreset>("50");

	// Working copy: scope defaults + per-template default appearance.
	// Seeded once from the registry payload, whose `settings` field already
	// carries the effective defaults (stored appearance merged over the
	// schema defaults and legacy tokens), so no draft is ever empty.
	const [defaults, setDefaults] = useState<Record<TemplateScope, string>>({
		mission: "template-1",
		campaign: "",
	});
	const [drafts, setDrafts] = useState<
		Record<TemplateScope, Record<string, TemplateSettingsValue>>
	>({
		mission: {},
		campaign: {},
	});
	const seeded = useRef(false);

	useEffect(() => {
		if (!templates || seeded.current) {
			return;
		}

		seeded.current = true;
		setDefaults({
			mission: templates.defaults.mission || "template-1",
			campaign: templates.defaults.campaign || "",
		});

		const next: Record<TemplateScope, Record<string, TemplateSettingsValue>> = {
			mission: {},
			campaign: {},
		};

		for (const scopeItem of SCOPES) {
			for (const definition of templates[scopeItem]) {
				next[scopeItem][definition.id] = definition.settings;
			}
		}

		setDrafts(next);
	}, [templates]);

	const saveMutation = useMutation({
		mutationFn: (values: {
			template_defaults: Record<TemplateScope, string>;
			template_settings?: Record<
				TemplateScope,
				Record<string, TemplateSettingsValue>
			>;
		}) => saveSettings(values),
		onSuccess: (saved) => {
			notify(__("Appearance saved.", "faracart"));

			// Keep the envelope shape in the shared cache so the Settings page
			// (and the preview dialogs) still find `data` after this save, then
			// refresh the registry payload (its `settings`/`defaults` now carry
			// the persisted values).
			const meta = settingsQuery.data?.meta ?? {};
			void queryClient.setQueryData(["settings"], { data: saved, meta });
			void settingsQuery.refetch();
			void queryClient.invalidateQueries({ queryKey: ["templates"] });
		},
		onError: (error: Error) => {
			notify(error.message, "error");
		},
	});

	const handleSave = () => {
		if (!templates) {
			return;
		}

		// Persist a template's appearance only when its draft diverges from
		// the effective default the backend served. Unchanged templates keep
		// their stored settings (merged from the server state) — and
		// templates that were never configured stay unconfigured, so the
		// legacy `frontend_*` fallback keeps applying instead of being
		// silently frozen by an unrelated save.
		const stored = settings?.template_settings;
		const merged: Record<
			TemplateScope,
			Record<string, TemplateSettingsValue>
		> = {
			mission: { ...(stored?.mission ?? {}) },
			campaign: { ...(stored?.campaign ?? {}) },
		};
		let changed = false;

		for (const scopeItem of SCOPES) {
			for (const definition of templates[scopeItem]) {
				const draft = drafts[scopeItem][definition.id];

				if (
					draft &&
					JSON.stringify(draft) !== JSON.stringify(definition.settings)
				) {
					merged[scopeItem][definition.id] = draft;
					changed = true;
				}
			}
		}

		const payload: {
			template_defaults: Record<TemplateScope, string>;
			template_settings?: Record<
				TemplateScope,
				Record<string, TemplateSettingsValue>
			>;
		} = { template_defaults: defaults };

		if (changed) {
			payload.template_settings = merged;
		}

		saveMutation.mutate(payload);
	};

	const discardChanges = () => {
		if (!templates) {
			return;
		}

		setDefaults({
			mission: templates.defaults.mission || "template-1",
			campaign: templates.defaults.campaign || "",
		});

		const next: Record<TemplateScope, Record<string, TemplateSettingsValue>> = {
			mission: {},
			campaign: {},
		};

		for (const scopeItem of SCOPES) {
			for (const definition of templates[scopeItem]) {
				next[scopeItem][definition.id] = definition.settings;
			}
		}

		setDrafts(next);
	};

	const resetTemplate = (id: string) => {
		const definition = templateById(templates, scope, id);

		if (!definition) {
			return;
		}

		const factory: TemplateSettingsValue = {};

		for (const field of definition.schema) {
			factory[field.key] = field.default;
		}

		setDrafts((prev) => ({
			...prev,
			[scope]: { ...prev[scope], [id]: factory },
		}));
	};

	// Sticky bottom bar: Save appearance + Discard changes (moved out of
	// the page body into the dashboard's bottom action bar). Hidden until
	// the template registry has loaded. The handlers read the drafts /
	// defaults / stored settings, so those are deps too — re-registering
	// on every edit keeps the bar's Save from ever persisting stale drafts.
	useStickyBarActions(
		[
			saveMutation.isPending,
			Boolean(templates),
			templates,
			settings,
			drafts,
			defaults,
		],
		() =>
			templates ? (
				<>
					<Button
						variant="contained"
						startIcon={<PaletteIcon />}
						disabled={saveMutation.isPending}
						onClick={handleSave}
						sx={{ minWidth: 160 }}
					>
						{saveMutation.isPending
							? __("Saving…", "faracart")
							: __("Save appearance", "faracart")}
					</Button>
					<Button
						variant="outlined"
						color="inherit"
						startIcon={<RestartAltIcon />}
						disabled={saveMutation.isPending}
						onClick={discardChanges}
					>
						{__("Discard changes", "faracart")}
					</Button>
				</>
			) : null,
	);

	if (templatesQuery.isLoading) {
		return (
			<PageContainer
				title={__("Appearance", "faracart")}
				description={__(
					"Customize how the cart progress UI looks on your storefront.",
					"faracart",
				)}
			>
				<Stack spacing={2}>
					<Skeleton variant="rounded" height={52} />
					<Skeleton variant="rounded" height={140} />
					<Skeleton variant="rounded" height={200} />
				</Stack>
			</PageContainer>
		);
	}

	if (templatesQuery.isError || !templates) {
		return (
			<PageContainer
				title={__("Appearance", "faracart")}
				description={__(
					"Customize how the cart progress UI looks on your storefront.",
					"faracart",
				)}
			>
				<Alert severity="error" variant="outlined">
					{templatesQuery.error instanceof Error
						? templatesQuery.error.message
						: __("Could not load the progress templates.", "faracart")}
				</Alert>
			</PageContainer>
		);
	}

	const scopeTemplates = templates[scope];
	const isCampaign = scope === "campaign";

	// The currently configured template for the active scope ('' = no
	// campaign template). The dropdown is empty-able only for campaigns.
	const selectedId = defaults[scope];
	const definition = templateById(templates, scope, selectedId);

	return (
		<PageContainer
			title={__("Appearance", "faracart")}
			description={__(
				"Pick the default progress template and tune its appearance — separately for Missions and Campaigns.",
				"faracart",
			)}
		>
			<Grid container spacing={3}>
				{/* Right column (RTL): the appearance settings — the scope tabs,
            the template dropdown and the schema-driven appearance form. */}
				<Grid size={{ xs: 12, md: 7, lg: 8 }}>
					<Stack spacing={3}>
						<Tabs
							value={tab}
							onChange={(_event, next) => setTab(next)}
							variant="fullWidth"
							sx={{ borderBottom: 1, borderColor: "divider" }}
							aria-label={__("Template scope", "faracart")}
						>
							<Tab
								id="appearance-tab-mission"
								aria-controls="appearance-panel-mission"
								icon={<RocketLaunchIcon />}
								iconPosition="start"
								label={__("Mission", "faracart")}
							/>
							<Tab
								id="appearance-tab-campaign"
								aria-controls="appearance-panel-campaign"
								icon={<StorefrontIcon />}
								iconPosition="start"
								label={__("Campaign", "faracart")}
							/>
						</Tabs>

						<Paper
							variant="outlined"
							role="tabpanel"
							id={`appearance-panel-${scope}`}
							aria-labelledby={`appearance-tab-${scope}`}
							sx={{ p: { xs: 2.5, md: 3 } }}
						>
							<Stack spacing={2.5}>
								<Box>
									<Typography variant="h6" component="h3" gutterBottom>
										{isCampaign
											? __("Campaign template", "faracart")
											: __("Mission template", "faracart")}
									</Typography>
									<Typography variant="body2" color="text.secondary">
										{isCampaign
											? __(
													"The default template that renders a whole campaign on the storefront (e.g. the milestone chain).",
													"faracart",
												)
											: __(
													"The default template for every mission that does not pin its own on the Mission Builder.",
													"faracart",
												)}
									</Typography>
								</Box>

								{/* Template dropdown — list the active scope's registered templates. */}
								<FormControl size="small" fullWidth>
									<InputLabel id="appearance-template-label">
										{isCampaign
											? __("Campaign template", "faracart")
											: __("Mission template", "faracart")}
									</InputLabel>
									<Select
										labelId="appearance-template-label"
										label={
											isCampaign
												? __("Campaign template", "faracart")
												: __("Mission template", "faracart")
										}
										value={selectedId}
										onChange={(event) =>
											setDefaults((prev) => ({
												...prev,
												[scope]: String(event.target.value),
											}))
										}
									>
										{isCampaign && (
											<MenuItem value="">
												<em>{__("No campaign template", "faracart")}</em>
											</MenuItem>
										)}
										{scopeTemplates.map((template) => (
											<MenuItem key={template.id} value={template.id}>
												{template.label}
											</MenuItem>
										))}
									</Select>
								</FormControl>

								{scopeTemplates.length === 0 && (
									<Alert severity="info" variant="outlined">
										{__(
											"No templates are registered for this scope yet. Add one on the backend template registry.",
											"faracart",
										)}
									</Alert>
								)}

								{!definition && !isCampaign && scopeTemplates.length > 0 && (
									<Alert severity="warning" variant="outlined">
										{__(
											"The stored default template is no longer registered. The storefront falls back to the default template until you pick another one here.",
											"faracart",
										)}
									</Alert>
								)}

								{definition && (
									<TemplateSettingsPanel
										scope={scope}
										definition={definition}
										drafts={drafts}
										onChange={(id, next) =>
											setDrafts((prev) => ({
												...prev,
												[scope]: { ...prev[scope], [id]: next },
											}))
										}
										onReset={resetTemplate}
									/>
								)}
							</Stack>
						</Paper>
					</Stack>
				</Grid>

				{/* Left column (RTL): the sticky live preview. Sticky only on
            desktop — on small screens the preview flows after the
            settings in a single column. Always shown when a template is
            selected, or for the campaign scope (where '' = no template
            is a valid choice). */}
				<Grid size={{ xs: 12, md: 5, lg: 4 }}>
					<Box
						sx={{ position: { xs: "static", md: "sticky" }, top: stickyTop }}
					>
						<Typography
							variant="overline"
							color="text.secondary"
							sx={{ display: "block", mb: 1 }}
						>
							{__("Live preview", "faracart")}
						</Typography>
						{(definition || isCampaign) && (
							<ScopeLivePreview
								scope={scope}
								id={selectedId}
								drafts={drafts}
								templates={scopeTemplates}
								tokens={tokens}
								currency={currency}
								preset={preset}
								onPresetChange={setPreset}
							/>
						)}
					</Box>
				</Grid>
			</Grid>
		</PageContainer>
	);
}
