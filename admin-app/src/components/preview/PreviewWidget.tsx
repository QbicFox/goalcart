import Box from '@mui/material/Box';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';

import { formatCurrency, formatNumber } from '../../lib/format';
import type { FrontendTemplate, ProgressGoal, ProgressReward } from '../../types';
import type { PreviewRewardState, PreviewTokens } from './types';

interface PreviewWidgetProps {
  /** Evaluated goals (single goal or a campaign's milestones in order). */
  goals: ProgressGoal[];
  /** ISO currency code from the payload (or the boot data). */
  currency: string;
  /** Storefront appearance tokens (from the Phase 12 settings). */
  tokens: PreviewTokens;
  /** The template variant to render. */
  template: FrontendTemplate;
  /** Reward chip state: auto (from completion) or forced. */
  rewardState: PreviewRewardState;
  /** Whether the bar fill animates (Phase 12 `frontend_animation`). */
  animation: boolean;
}

const REWARD_LABELS: Record<string, string> = {
  free_shipping: __('Free shipping', 'goalcart'),
  percent_discount: __('% discount', 'goalcart'),
  fixed_discount: __('Fixed discount', 'goalcart'),
  free_gift: __('Free gift', 'goalcart'),
  coupon: __('Coupon', 'goalcart'),
};

/** The featured goal: the first eligible one, else the first listed. */
function featuredGoal(goals: ProgressGoal[]): ProgressGoal | null {
  if (!goals.length) {
    return null;
  }

  for (const goal of goals) {
    if (goal.eligible !== false) {
      return goal;
    }
  }

  return goals[0];
}

/** Value-aware reward label (mirrors MessageEngine::reward_label). */
function rewardLabel(reward: ProgressReward): string {
  const base = REWARD_LABELS[reward.type ?? ''] ?? reward.type ?? '';

  if (reward.type === 'percent_discount' && reward.value !== null) {
    return sprintf(
      /* translators: %d: discount percentage. */
      __('%d%% discount', 'goalcart'),
      Math.round(reward.value)
    );
  }

  if (reward.type === 'fixed_discount' && reward.value !== null) {
    return sprintf(
      /* translators: %s: formatted discount amount. */
      __('Fixed %s off', 'goalcart'),
      formatCurrency(reward.value)
    );
  }

  return base;
}

/** The percentage fill bar (mirrors .goalcart-progress markup). */
function PreviewBar({
  tokens,
  percent,
  completed,
  animate,
}: {
  tokens: PreviewTokens;
  percent: number;
  completed: boolean;
  animate: boolean;
}) {
  const clamped = Math.max(0, Math.min(100, percent));

  return (
    <Box
      sx={{
        position: 'relative',
        height: tokens.barHeight,
        background: '#f0f0f1',
        borderRadius: 999,
        overflow: 'hidden',
        flex: '1 1 auto',
      }}
    >
      <Box
        sx={{
          position: 'absolute',
          insetInlineStart: 0,
          insetBlockStart: 0,
          height: '100%',
          width: `${clamped}%`,
          background: completed ? '#00a32a' : tokens.accent,
          borderRadius: 'inherit',
          transition: animate ? 'width 0.45s ease' : 'none',
        }}
      />
    </Box>
  );
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

/**
 * The milestone ladder (mirrors .goalcart-milestones). Renders every goal
 * as a rung (dot + target); a completed rung fills. When `showSingle`, a
 * lone goal still renders as one rung (the milestone template's hero).
 */
function MilestonesLadder({
  goals,
  currency,
  tokens,
  showSingle,
}: {
  goals: ProgressGoal[];
  currency: string;
  tokens: PreviewTokens;
  showSingle: boolean;
}) {
  if (goals.length < 2 && !showSingle) {
    return null;
  }

  return (
    <Box
      component="ol"
      sx={{
        display: 'flex',
        alignItems: 'center',
        gap: 0.375,
        margin: '0.875rem 0 0',
        padding: 0,
        listStyle: 'none',
        flexWrap: 'wrap',
        '& li + li::before': {
          content: '""',
          width: 22,
          height: 2,
          background: tokens.border,
          flexShrink: 0,
        },
      }}
    >
      {goals.map((goal) => (
        <Box
          component="li"
          key={goal.goal_id}
          sx={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 0.3,
            color: goal.completed ? tokens.text : '#646970',
            fontSize: 12,
            fontWeight: 500,
          }}
        >
          <Box
            sx={{
              width: 9,
              height: 9,
              borderRadius: '50%',
              background: goal.completed ? tokens.accent : tokens.border,
              transition: 'background 0.3s ease',
            }}
          />
          <Box component="span">
            {goal.is_money ? formatCurrency(goal.target, currency) : formatNumber(goal.target)}
          </Box>
        </Box>
      ))}
    </Box>
  );
} /** The Phase 14 suggestion list (name + server-formatted price). */
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
 * The storefront progress widget, rendered in React for the Phase 15
 * admin preview. Mirrors the component flow of assets/js/frontend.js —
 * GoalContainer → RewardStatus + template body + GoalMessage +
 * GoalMilestones + SuggestionList — with the appearance tokens from the
 * Phase 12 settings, so the admin sees exactly what customers see.
 */
export default function PreviewWidget({
  goals,
  currency,
  tokens,
  template,
  rewardState,
  animation,
}: PreviewWidgetProps) {
  const goal = featuredGoal(goals);

  if (!goal) {
    return null;
  }

  const percent = Math.max(0, Math.min(100, goal.percentage));
  const chipState: 'locked' | 'unlocked' =
    rewardState === 'auto' ? (goal.completed ? 'unlocked' : 'locked') : rewardState;
  const nearlyComplete = goal.state === 'nearly_complete';

  return (
    <Box
      sx={{
        background: tokens.bg,
        border: `1px solid ${tokens.border}`,
        borderRadius: tokens.radius,
        boxShadow: '0 6px 24px rgba(0,0,0,0.08)',
        color: tokens.text,
        padding: '1rem 1.125rem',
        fontSize: 14,
      }}
    >
      {/* GoalContainer head: the reward chip, right-aligned. */}
      {goal.reward?.type && (
        <Box sx={{ display: 'flex', justifyContent: 'flex-end', mb: 0.5 }}>
          <RewardChip label={rewardLabel(goal.reward)} state={chipState} />
        </Box>
      )}

      {/* Template body (Phase 12). */}
      {template === 'percentage' && (
        <Stack direction="row" alignItems="center" spacing={1.5}>
          <Typography
            sx={{
              fontSize: 28,
              fontWeight: 800,
              lineHeight: 1,
              letterSpacing: '-0.02em',
              fontVariantNumeric: 'tabular-nums',
              color: tokens.accent,
              minWidth: '3.2em',
            }}
          >
            {Math.round(percent)}%
          </Typography>
          <PreviewBar
            tokens={tokens}
            percent={percent}
            completed={goal.completed}
            animate={animation}
          />
        </Stack>
      )}

      {template === 'milestone' && (
        <Box sx={{ mb: 1 }}>
          <MilestonesLadder goals={goals} currency={currency} tokens={tokens} showSingle />
          <PreviewBar
            tokens={tokens}
            percent={percent}
            completed={goal.completed}
            animate={animation}
          />
        </Box>
      )}

      {template === 'card' && (
        <Box sx={{ display: 'flex', alignItems: 'center', gap: '0.625rem', flexWrap: 'wrap' }}>
          <Typography sx={{ fontSize: 24, lineHeight: 1 }} aria-hidden>
            {goal.icon || '🎯'}
          </Typography>
          <Typography sx={{ fontWeight: 700, flex: '1 1 auto', minWidth: 0 }}>
            {goal.goal_name}
          </Typography>
          <Box sx={{ flex: '0 0 100%' }}>
            <PreviewBar
              tokens={tokens}
              percent={percent}
              completed={goal.completed}
              animate={animation}
            />
          </Box>
        </Box>
      )}

      {template === 'basic' && (
        <PreviewBar
          tokens={tokens}
          percent={percent}
          completed={goal.completed}
          animate={animation}
        />
      )}

      {/* GoalMessage (Phase 13 state styling). */}
      <Typography
        sx={{
          mt: 1,
          fontWeight: nearlyComplete ? 600 : 500,
          color: nearlyComplete ? tokens.accent : tokens.text,
        }}
      >
        {goal.message}
      </Typography>

      {/* GoalMilestones below the body (except the milestone template,
          where the ladder is the hero and already rendered above). */}
      {goals.length >= 2 && template !== 'milestone' && (
        <MilestonesLadder goals={goals} currency={currency} tokens={tokens} showSingle={false} />
      )}

      {/* SuggestionList (Phase 14). */}
      <SuggestionList goal={goal} currency={currency} tokens={tokens} />
    </Box>
  );
}
