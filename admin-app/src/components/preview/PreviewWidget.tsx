import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import type { ComponentType } from 'react';

import { formatCurrency } from '../../lib/format';
import { campaignRenderer, goalRenderer } from '../../templates/registry';
import type { CampaignTemplateProps } from '../../templates/registry';
import { rewardLabel } from '../../templates/rewardLabel';
import { bool, num, str } from '../../templates/utils';
import type { ProgressCampaign, ProgressGoal, TemplateSettingsValue } from '../../types';
import type { PreviewRewardState, PreviewTokens } from './types';

interface PreviewWidgetProps {
  /** Evaluated goals (single goal or a campaign's milestones in order). */
  goals: ProgressGoal[];
  /** Campaign template groups (pluggable engine) from the payload. */
  campaigns?: ProgressCampaign[];
  /** ISO currency code from the payload (or the boot data). */
  currency: string;
  /** Storefront appearance tokens (legacy fallback base). */
  tokens: PreviewTokens;
  /**
   * Forced template override ('' = per-goal): renders every card through
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

/** Human-readable reason a goal was suppressed by a conflict (Phase 26). */
function conflictReasonLabel(reason: string): string {
  switch (reason) {
    case 'exclusive':
      return __('Blocked by an exclusive goal', 'goalcart');
    case 'not_first':
      return __('Skipped — a higher-priority goal wins', 'goalcart');
    case 'not_best':
      return __('Skipped — another reward is better', 'goalcart');
    case 'lower_priority':
      return __('Skipped — lower priority', 'goalcart');
    default:
      return __('Skipped by a conflict', 'goalcart');
  }
}

/** The eligible goals, in payload order. */
function eligibleGoals(goals: ProgressGoal[]): ProgressGoal[] {
  return goals.filter((goal) => goal.eligible !== false);
}

/**
 * The effective settings for one card: legacy appearance tokens as the
 * base, then the goal's own resolved template settings (or the forced
 * override's global defaults when previewing a different template).
 */
function effectiveSettings(
  goal: ProgressGoal,
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

  const own = templateOverride ? (settingsOverride ?? {}) : (goal.template_settings ?? {});

  return { ...base, ...own };
}

/** The reward chip — locked or unlocked (mirrors .goalcart-reward). */
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
  goal,
  currency,
  tokens,
}: {
  goal: ProgressGoal;
  currency: string;
  tokens: PreviewTokens;
}) {
  const items = goal.suggestions ?? [];

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
            {item.price_html || (item.price !== null ? formatCurrency(item.price, currency) : '')}
          </Box>
        </Box>
      ))}
    </Box>
  );
}

/**
 * One goal's card — mirrors goalContainer() in assets/js/frontend.js:
 * reward chip + template body (via the React template registry) + message
 * + suggestions. The card surface comes from the goal's effective
 * settings (accent/bg/border/text/radius), exactly like the storefront's
 * per-card CSS custom properties.
 */
function GoalCard({
  goal,
  currency,
  tokens,
  template,
  settings,
  rewardState,
  animation,
}: {
  goal: ProgressGoal;
  currency: string;
  tokens: PreviewTokens;
  template: string;
  settings: TemplateSettingsValue;
  rewardState: PreviewRewardState;
  animation: boolean;
}) {
  const Renderer = goalRenderer(template);
  const chipState: 'locked' | 'unlocked' =
    rewardState === 'auto' ? (goal.completed ? 'unlocked' : 'locked') : rewardState;
  const nearlyComplete = goal.state === 'nearly_complete';
  const showReward = template !== 'card' || bool(settings, 'showReward', true);
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
      {/* GoalContainer head: the reward chip, right-aligned. */}
      {goal.reward?.type && showReward && (
        <Box sx={{ display: 'flex', justifyContent: 'flex-end', mb: 0.5 }}>
          <RewardChip label={rewardLabel(goal.reward)} state={chipState} />
        </Box>
      )}

      {/* Template body (pluggable template engine). */}
      <Renderer
        goal={goal}
        currency={currency}
        settings={settings}
        animation={animation}
      />

      {/* GoalMessage (Phase 13 state styling). */}
      {showMessage && (
        <Typography
          sx={{
            mt: 1,
            fontWeight: nearlyComplete ? 600 : 500,
            color: nearlyComplete ? str(settings, 'accent', tokens.accent) : undefined,
          }}
        >
          {goal.message}
        </Typography>
      )}

      {/* Conflict resolution note (Phase 26). */}
      {goal.conflict && goal.conflict.resolved === false && (
        <Box sx={{ mt: 1 }}>
          <Chip
            size="small"
            color="warning"
            variant="outlined"
            label={conflictReasonLabel(goal.conflict.reason)}
          />
        </Box>
      )}

      {/* SuggestionList (Phase 14). */}
      <SuggestionList goal={goal} currency={currency} tokens={tokens} />
    </Box>
  );
}

/**
 * The storefront progress widget, rendered in React for the Phase 15
 * admin preview. Mirrors the component flow of assets/js/frontend.js —
 * campaign groups render through their campaign template (e.g. the
 * milestone chain), everything else as one GoalContainer card per
 * eligible goal — with the resolved template settings, so the admin sees
 * exactly what customers see.
 */
export default function PreviewWidget({
  goals,
  campaigns = [],
  currency,
  tokens,
  templateOverride,
  settingsOverride,
  rewardState,
  animation,
}: PreviewWidgetProps) {
  const cards = eligibleGoals(goals);

  if (!cards.length) {
    return null;
  }

  // Group eligible goals by campaign so a campaign template (e.g. the
  // milestone chain) renders the whole group as one unit — the same
  // grouping renderWidget() performs on the storefront.
  const campaignById = new Map(campaigns.map((campaign) => [campaign.campaign_id, campaign]));
  const groupOrder: number[] = [];
  const groups = new Map<number, ProgressGoal[]>();
  const standalone: ProgressGoal[] = [];

  for (const goal of cards) {
    const campaign = goal.campaign_id ? campaignById.get(goal.campaign_id) : undefined;

    if (campaign && campaign.template && campaignRenderer(campaign.template)) {
      const list = groups.get(goal.campaign_id as number) ?? [];
      list.push(goal);
      groups.set(goal.campaign_id as number, list);
      if (list.length === 1) {
        groupOrder.push(goal.campaign_id as number);
      }
    } else {
      standalone.push(goal);
    }
  }	  return (
    <Stack spacing={1} sx={{ width: '100%' }}>
      {groupOrder.map((campaignId) => {
        const campaign = campaignById.get(campaignId) as ProgressCampaign;
        const Renderer = campaignRenderer(campaign.template) as ComponentType<CampaignTemplateProps>;
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
              goals={groups.get(campaignId) as ProgressGoal[]}
              currency={currency}
              settings={groupSettings}
              animation={animation}
            />
          </Box>
        );
      })}

      {standalone.map((goal) => (
        <GoalCard
          key={goal.goal_id}
          goal={goal}
          currency={currency}
          tokens={tokens}
          template={templateOverride || goal.template || 'basic'}
          settings={effectiveSettings(goal, tokens, templateOverride, settingsOverride)}
          rewardState={rewardState}
          animation={animation}
        />
      ))}
    </Stack>
  );
}
