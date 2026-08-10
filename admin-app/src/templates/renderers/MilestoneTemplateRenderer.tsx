import Box from '@mui/material/Box';

import { formatCurrency, formatNumber } from '../../lib/format';
import TemplateBar from '../TemplateBar';
import { bool, str } from '../utils';
import type { GoalTemplateProps } from '../registry';

/**
 * Milestone template body — the goal's own threshold as a single rung
 * (dot + target) above the bar. `showLabels` toggles the target text and
 * `dotColor` / `doneColor` drive the rung colors.
 */
export default function MilestoneTemplateRenderer({
  goal,
  currency,
  settings,
  animation,
}: GoalTemplateProps) {
  const showLabels = bool(settings, 'showLabels', true);
  const dotColor = str(settings, 'dotColor', '#dcdcde');
  const doneColor = str(settings, 'doneColor', '#00a32a');
  const target = goal.is_money ? formatCurrency(goal.target, currency) : formatNumber(goal.target);

  return (
    <Box sx={{ mb: 1 }}>
      <Box
        component="ol"
        sx={{
          display: 'flex',
          alignItems: 'center',
          gap: 0.375,
          margin: '0 0 0.875rem',
          padding: 0,
          listStyle: 'none',
          flexWrap: 'wrap',
        }}
      >
        <Box
          component="li"
          sx={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 0.3,
            color: goal.completed ? undefined : '#646970',
            fontSize: 12,
            fontWeight: 500,
          }}
        >
          <Box
            sx={{
              width: 12,
              height: 12,
              borderRadius: '50%',
              background: goal.completed ? doneColor : dotColor,
              transition: 'background 0.3s ease',
            }}
          />
          {showLabels && <Box component="span">{target}</Box>}
        </Box>
      </Box>
      <TemplateBar
        settings={settings}
        percent={goal.percentage}
        completed={goal.completed}
        animation={animation}
      />
    </Box>
  );
}
