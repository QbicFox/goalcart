import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import type { ComponentType } from 'react';

import { formatCurrency } from '../../lib/format';
import { CAMPAIGN_RENDERERS, MISSION_RENDERERS } from '../../templates/registry';
import type { CampaignTemplateProps } from '../../templates/registry';
import { rewardLabel } from '../../templates/rewardLabel';
import { bool, num, str } from '../../templates/utils';
import type { ProgressCampaign, ProgressMission, TemplateSettingsValue } from '../../types';
import type { PreviewRewardState, PreviewTokens } from './types';

interface PreviewWidgetProps {
  /** Evaluated missions (single mission or a campaign's milestones in order). */
  missions: ProgressMission[];
  /** Campaign template groups (pluggable engine) from the payload. */
  campaigns?: ProgressCampaign[];
  /** ISO currency code from the payload (or the boot data). */
  currency: string;
  /** Storefront appearance tokens (legacy fallback base). */
  tokens: PreviewTokens;
  /**
   * Forced template override ('' = per-mission): renders every card through
   * the given template so admins can preview any variant. Requires
   * `settingsOverride` to supply that template's default appearance.
   */
  templateOverride?: string;
  /** The global default settings of the forced override template. */
  settingsOverride?: TemplateSettingsValue | null;
  /** Reward chip state: auto (from completion) or forced. */
  rewardState: PreviewRewardState;
  /** Whether the bar fill animates (Phase 12 `frontend_animation`). */
  animation: boolean;
}

/** Human-readable reason a mission was suppressed by a conflict (Phase 26). */
function conflictReasonLabel(reason: string): string {
  switch (reason) {
    case 'exclusive':
      return __('Blocked by an exclusive mission', 'faracart');
    case 'not_first':
      return __('Skipped — a higher-priority mission wins', 'faracart');
    case 'not_best':
      return __('Skipped — another reward is better', 'faracart');
    case 'lower_priority':
      return __('Skipped — lower priority', 'faracart');
    default:
      return __('Skipped by a conflict', 'faracart');
  }
}

/** The eligible missions, in payload order. */
function eligibleMissions(missions: ProgressMission[]): ProgressMission[] {
  return missions.filter((mission) => mission.eligible !== false);
}

/**
 * The effective settings for one card: legacy appearance tokens as the
 * base, then the mission's own resolved template settings (or the forced
 * override's global defaults when previewing a different template).
 */
function effectiveSettings(
  mission: ProgressMission,
  tokens: PreviewTokens,
  templateOverride: string | undefined,
  settingsOverride: TemplateSettingsValue | null | undefined
): TemplateSettingsValue {
  const base: TemplateSettingsValue = {
    accent: tokens.accent,
    bg: tokens.bg,
    border: tokens.border,
    text: tokens.text,
    radius: tokens.radius,
    barHeight: tokens.barHeight,
  };

  const own = templateOverride ? (settingsOverride ?? {}) : (mission.template_settings ?? {});

  return { ...base, ...own };
}

/** The reward chip — locked or unlocked (mirrors .faracart-reward). */
function RewardChip({ label, state }: { label: string; state: 'locked' | 'unlocked' }) {
  const unlocked = state === 'unlocked';

  return (
    <Box
      component="span"
      sx={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: '0.4rem',
        padding: '0.25rem 0.625rem',
        borderRadius: 999,
        fontSize: 12,
        fontWeight: 600,
        lineHeight: 1.6,
        background: unlocked ? 'rgba(0,163,42,0.12)' : '#f0f0f1',
        color: unlocked ? '#007017' : '#646970',
      }}
    >
      <Box component="span" aria-hidden sx={{ fontSize: 13, lineHeight: 1 }}>
        {unlocked ? '✓' : '🔒'}
      </Box>
      <Box component="span">{label}</Box>
    </Box>
  );
}

/** The Phase 14 suggestion list (name + server-formatted price). */
function SuggestionList({
  mission,
  currency,
  tokens,
}: {
  mission: ProgressMission;
  currency: string;
  tokens: PreviewTokens;
}) {
  const items = mission.suggestions ?? [];

  if (!items.length) {
    return null;
  }

  return (
    <Box
      component="ul"
      sx={{
        margin: '0.875rem 0 0',
        padding: '0.75rem 0 0',
        listStyle: 'none',
        borderTop: `1px dashed ${tokens.border}`,
      }}
    >
      {items.map((item) => (
        <Box
          component="li"
          key={item.id}
          sx={{
            display: 'flex',
            justifyContent: 'space-between',
            gap: 1.5,
            '& + &': { mt: 0.375 },
          }}
        >
          <Box component="span" sx={{ fontWeight: 500 }}>
            {item.name}
          </Box>
          <Box component="span" sx={{ color: '#646970', whiteSpace: 'nowrap' }}>
            {item.price !== null ? formatCurrency(item.price, currency) : item.price_html}
          </Box>
        </Box>
      ))}
    </Box>
  );
}

/**
 * One mission's card — mirrors missionContainer() in assets/js/frontend.js:
 * reward chip + template body (via the React template registry) + message
 * + suggestions. The card surface comes from the mission's effective
 * settings (accent/bg/border/text/radius), exactly like the storefront's
 * per-card CSS custom properties.
 */
function MissionCard({
  mission,
  currency,
  tokens,
  template,
  settings,
  rewardState,
  animation,
}: {
  mission: ProgressMission;
  currency: string;
  tokens: PreviewTokens;
  template: string;
  settings: TemplateSettingsValue;
  rewardState: PreviewRewardState;
  animation: boolean;
}) {
  // Property lookup (not a call result) keeps the component reference
  // static across renders — react-hooks/static-components.
  const Renderer = MISSION_RENDERERS[template] ?? MISSION_RENDERERS['template-1'];
  const chipState: 'locked' | 'unlocked' =
    rewardState === 'auto' ? (mission.completed ? 'unlocked' : 'locked') : rewardState;
  const nearlyComplete = mission.state === 'nearly_complete';
  const showReward = bool(settings, 'showReward', true);
  // Template 4 renders the recommended products inline (its body), so the
  // shared bottom suggestion list would duplicate them — suppress it.
  const showSuggestions = template !== 'template-4';
  const showMessage = bool(settings, 'showMessage', true);
  const cssClass = str(settings, 'cssClass', '');

  return (
    <Box
      className={cssClass || undefined}
      sx={{
        background: str(settings, 'bg', tokens.bg),
        border: `1px solid ${str(settings, 'border', tokens.border)}`,
        borderRadius: num(settings, 'radius', tokens.radius),
        boxShadow: '0 6px 24px rgba(0,0,0,0.08)',
        color: str(settings, 'text', tokens.text),
        padding: '1rem 1.125rem',
        fontSize: 14,
      }}
    >
      {/* MissionContainer head: the reward chip, right-aligned. */}
      {mission.reward?.type && showReward && (
        <Box sx={{ display: 'flex', justifyContent: 'flex-end', mb: 0.5 }}>
          <RewardChip label={rewardLabel(mission.reward)} state={chipState} />
        </Box>
      )}

      {/* Template body (pluggable template engine). */}
      <Renderer mission={mission} currency={currency} settings={settings} animation={animation} />

      {/* MissionMessage (Phase 13 state styling). */}
      {showMessage && (
        <Typography
          sx={{
            mt: 1,
            fontWeight: nearlyComplete ? 600 : 500,
            color: nearlyComplete ? str(settings, 'accent', tokens.accent) : undefined,
          }}
        >
          {mission.message}
        </Typography>
      )}

      {/* Conflict resolution note (Phase 26). */}
      {mission.conflict && mission.conflict.resolved === false && (
        <Box sx={{ mt: 1 }}>
          <Chip
            size="small"
            color="warning"
            variant="outlined"
            label={conflictReasonLabel(mission.conflict.reason)}
          />
        </Box>
      )}

      {/* SuggestionList (Phase 14) — hidden for template-4 whose body
          already renders the recommended products. */}
      {showSuggestions && <SuggestionList mission={mission} currency={currency} tokens={tokens} />}
    </Box>
  );
}

/**
 * The storefront progress widget, rendered in React for the Phase 15
 * admin preview. Mirrors the component flow of assets/js/frontend.js —
 * campaign groups render through their campaign template (e.g. the
 * milestone chain), everything else as one MissionContainer card per
 * eligible mission — with the resolved template settings, so the admin sees
 * exactly what customers see.
 */
export default function PreviewWidget({
  missions,
  campaigns = [],
  currency,
  tokens,
  templateOverride,
  settingsOverride,
  rewardState,
  animation,
}: PreviewWidgetProps) {
  const cards = eligibleMissions(missions);

  if (!cards.length) {
    return null;
  }

  // Group eligible missions by campaign so a campaign template (e.g. the
  // milestone chain) renders the whole group as one unit — the same
  // grouping renderWidget() performs on the storefront.
  const campaignById = new Map(campaigns.map((campaign) => [campaign.campaign_id, campaign]));
  const groupOrder: number[] = [];
  const groups = new Map<number, ProgressMission[]>();
  const standalone: ProgressMission[] = [];

  for (const mission of cards) {
    const campaign = mission.campaign_id ? campaignById.get(mission.campaign_id) : undefined;

    if (campaign && campaign.template && CAMPAIGN_RENDERERS[campaign.template]) {
      const list = groups.get(mission.campaign_id as number) ?? [];
      list.push(mission);
      groups.set(mission.campaign_id as number, list);
      if (list.length === 1) {
        groupOrder.push(mission.campaign_id as number);
      }
    } else {
      standalone.push(mission);
    }
  }
  return (
    <Stack spacing={1} sx={{ width: '100%' }}>
      {groupOrder.map((campaignId) => {
        const campaign = campaignById.get(campaignId) as ProgressCampaign;
        const Renderer = CAMPAIGN_RENDERERS[
          campaign.template
        ] as ComponentType<CampaignTemplateProps>;
        const groupSettings: TemplateSettingsValue = {
          accent: tokens.accent,
          bg: tokens.bg,
          border: tokens.border,
          text: tokens.text,
          radius: tokens.radius,
          barHeight: tokens.barHeight,
          ...(campaign.settings ?? {}),
        };

        return (
          <Box
            key={campaignId}
            sx={{
              background: str(groupSettings, 'bg', tokens.bg),
              border: `1px solid ${str(groupSettings, 'border', tokens.border)}`,
              borderRadius: num(groupSettings, 'radius', tokens.radius),
              boxShadow: '0 6px 24px rgba(0,0,0,0.08)',
              color: str(groupSettings, 'text', tokens.text),
              padding: '1rem 1.125rem',
              fontSize: 14,
            }}
          >
            <Renderer
              campaign={campaign}
              missions={groups.get(campaignId) as ProgressMission[]}
              currency={currency}
              settings={groupSettings}
              animation={animation}
            />
          </Box>
        );
      })}

      {standalone.map((mission) => (
        <MissionCard
          key={mission.mission_id}
          mission={mission}
          currency={currency}
          tokens={tokens}
          template={templateOverride || mission.template || 'template-1'}
          settings={effectiveSettings(mission, tokens, templateOverride, settingsOverride)}
          rewardState={rewardState}
          animation={animation}
        />
      ))}
    </Stack>
  );
}
