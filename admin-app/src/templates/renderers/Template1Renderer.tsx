import AccessTimeIcon from '@mui/icons-material/AccessTime';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import LocalShippingIcon from '@mui/icons-material/LocalShipping';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import type { GoalTemplateProps } from '../registry';
import { bool, num, str } from '../utils';
import {
  AmountSummary,
  GoalBar,
  GoalCta,
  GoalIcon,
  PercentChip,
  addMoreLabel,
  goalPercent,
  isExpiredGoal,
} from './goalShared';

/**
 * Template 1 — Classic Progress Card (Concept 01).
 *
 * Icon badge + goal label/title + percentage chip, a horizontal progress
 * bar, the current/remaining amounts and a CTA. Renders the completed
 * (green, check) and expired (muted, clock) states. The most
 * general-purpose template.
 */
export default function Template1Renderer({ goal, currency, settings, animation }: GoalTemplateProps) {
  const percent = goalPercent(goal);
  const accent = str(settings, 'accent', '#f97316');
  const text = str(settings, 'text', '#1f2937');
  const muted = str(settings, 'secondaryText', '#9ca3af');
  const compact = str(settings, 'density', 'comfortable') === 'compact';

  if (isExpiredGoal(goal)) {
    return (
      <Box sx={{ opacity: 0.75 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <GoalIcon
            goal={goal}
            FallbackIcon={AccessTimeIcon}
            bg="#e5e7eb"
            color="#9ca3af"
            size={36}
          />
          <Box sx={{ flex: '1 1 auto', minWidth: 0 }}>
            <Typography sx={{ fontSize: 12, fontWeight: 500, color: muted }}>
              {__('Expired', 'faracart')}
            </Typography>
            <Typography sx={{ fontSize: 14, fontWeight: 700, color: muted }}>
              {__('This goal has ended', 'faracart')}
            </Typography>
          </Box>
          <Box
            component="span"
            sx={{
              fontSize: 12,
              color: muted,
              background: '#e5e7eb',
              px: 1,
              py: 0.25,
              borderRadius: 999,
              whiteSpace: 'nowrap',
            }}
          >
            {__('Expired', 'faracart')}
          </Box>
        </Box>
      </Box>
    );
  }

  if (goal.completed) {
    return (
      <Box
        sx={{
          background: '#f0fdf4',
          border: '1px solid #bbf7d0',
          borderRadius: 2,
          p: compact ? 1.5 : 2,
        }}
      >
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, mb: 1 }}>
          <GoalIcon
            goal={goal}
            FallbackIcon={CheckCircleIcon}
            bg="#dcfce7"
            color="#16a34a"
            size={36}
          />
          <Box>
            <Typography sx={{ fontSize: 12, fontWeight: 500, color: '#16a34a' }}>
              {__('Goal completed', 'faracart')} 🎉
            </Typography>
            <Typography sx={{ fontSize: 14, fontWeight: 700, color: '#166534' }}>
              {goal.goal_name}
            </Typography>
          </Box>
        </Box>
        <GoalBar
          percent={100}
          completed
          animation={animation}
          track="#bbf7d0"
          height={num(settings, 'barHeight', 10)}
          color="#16a34a"
        />
      </Box>
    );
  }

  return (
    <Box>
      {/* Icon + label/title + percent chip */}
      <Box sx={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 1, mb: 1.5 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.25, minWidth: 0 }}>
          {bool(settings, 'showIcon', true) && (
            <GoalIcon
              goal={goal}
              FallbackIcon={LocalShippingIcon}
              bg={str(settings, 'iconBg', '#ffedd5')}
              color={str(settings, 'iconColor', accent)}
              size={36}
            />
          )}
          <Box sx={{ minWidth: 0 }}>
            <Typography sx={{ fontSize: 12, fontWeight: 500, color: muted }}>
              {__('Shopping goal', 'faracart')}
            </Typography>
            <Typography
              sx={{ fontSize: 14, fontWeight: 700, color: text, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
            >
              {goal.goal_name}
            </Typography>
          </Box>
        </Box>
        {bool(settings, 'showPercent', true) && (
          <PercentChip
            percent={percent}
            color={accent}
            bg={`${accent}14`}
            border={`${accent}26`}
          />
        )}
      </Box>

      {/* Progress bar */}
      <GoalBar
        percent={percent}
        completed={goal.completed}
        animation={animation}
        track="#f3f4f6"
        height={num(settings, 'barHeight', 10)}
        color={accent}
      />

      {/* Amounts */}
      {bool(settings, 'showAmounts', true) && (
        <Box sx={{ mt: 1.25 }}>
          <AmountSummary goal={goal} currency={currency} settings={settings} highlightColor={accent} />
        </Box>
      )}

      {/* CTA */}
      {bool(settings, 'showCta', true) && (
        <GoalCta
          goal={goal}
          currency={currency}
          settings={settings}
          variant={str(settings, 'buttonStyle', 'solid') === 'outline' ? 'outline' : 'solid'}
        >
          {addMoreLabel(goal, currency)}
        </GoalCta>
      )}
    </Box>
  );
}
