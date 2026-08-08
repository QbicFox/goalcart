import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import LinearProgress from '@mui/material/LinearProgress';
import Paper from '@mui/material/Paper';
import Slider from '@mui/material/Slider';
import Stack from '@mui/material/Stack';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from 'react';

import { formatCurrency } from '../lib/format';
import type { DisplaySettingsInput, Goal } from '../types';

interface GoalPreviewDialogProps {
  goal: Goal | null;
  onClose: () => void;
}

const PRESETS = [0, 25, 50, 75, 100];

const REWARD_LABELS: Record<string, string> = {
  free_shipping: __('Free shipping', 'goalcart'),
  percent_discount: __('% discount', 'goalcart'),
  fixed_discount: __('Fixed discount', 'goalcart'),
  free_gift: __('Free gift', 'goalcart'),
  coupon: __('Coupon', 'goalcart'),
};

/**
 * Substitute the Phase 13 message placeholders with the simulated values.
 */
function interpolate(template: string, goal: Goal, percent: number) {
  const current = (goal.target * percent) / 100;
  const remaining = Math.max(0, goal.target - current);
  const reward = goal.reward_type ? (REWARD_LABELS[goal.reward_type] ?? goal.reward_type) : '';

  return template
    .replace(/\{current\}/g, formatCurrency(current))
    .replace(/\{target\}/g, formatCurrency(goal.target))
    .replace(/\{remaining\}/g, formatCurrency(remaining))
    .replace(/\{percentage\}/g, String(percent))
    .replace(/\{reward\}/g, reward)
    .replace(/\{goal_name\}/g, goal.name);
}

/**
 * Goal preview (Phase 9 "preview" action). A lightweight mock of the
 * customer progress card at a simulated progress state — the full admin
 * preview system (simulated cart, device widths, template rendering) is
 * Phase 15. Read-only: preview never touches the real cart.
 */
export default function GoalPreviewDialog({ goal, onClose }: GoalPreviewDialogProps) {
  const [percent, setPercent] = useState(50);

  const display = (goal?.display_settings ?? {}) as DisplaySettingsInput;

  const message = useMemo(() => {
    if (!goal) {
      return '';
    }

    if (percent >= 100) {
      return display.completed_message
        ? interpolate(display.completed_message, goal, percent)
        : __('Goal reached — your reward is unlocked!', 'goalcart');
    }

    const template = display.message ?? __('Only {remaining} left to unlock {reward}!', 'goalcart');

    return interpolate(template, goal, percent);
  }, [goal, percent, display.message, display.completed_message]);

  const completed = goal !== null && percent >= 100;

  return (
    <Dialog open={goal !== null} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>{__('Goal preview', 'goalcart')}</DialogTitle>
      <DialogContent>
        <Stack spacing={3}>
          <Paper
            variant="outlined"
            sx={{
              p: 3,
              border: '1px solid',
              borderColor: 'divider',
              borderRadius: 2,
            }}
          >
            <Stack spacing={1.5}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>
                  {display.title || goal?.name}
                </Typography>
                {completed && goal?.reward_type && (
                  <Chip
                    size="small"
                    color="success"
                    label={REWARD_LABELS[goal.reward_type] ?? goal.reward_type}
                  />
                )}
              </Box>

              <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                <Box sx={{ flex: 1 }}>
                  <LinearProgress
                    variant="determinate"
                    value={percent}
                    color={completed ? 'success' : 'primary'}
                    sx={{ height: 10, borderRadius: 5 }}
                  />
                </Box>
                <Typography
                  variant="body2"
                  color="text.secondary"
                  sx={{ minWidth: 44, textAlign: 'right' }}
                >
                  {percent}%
                </Typography>
              </Box>

              <Typography variant="body2" color="text.secondary">
                {message}
              </Typography>
            </Stack>
          </Paper>

          <Box>
            <Typography variant="body2" color="text.secondary" gutterBottom>
              {__('Simulated progress', 'goalcart')}
            </Typography>
            <ToggleButtonGroup
              size="small"
              exclusive
              value={percent}
              onChange={(_event, value) => value !== null && setPercent(value)}
              aria-label={__('Simulated progress', 'goalcart')}
            >
              {PRESETS.map((value) => (
                <ToggleButton key={value} value={value}>
                  {value}%
                </ToggleButton>
              ))}
            </ToggleButtonGroup>
            <Slider
              value={percent}
              min={0}
              max={100}
              step={5}
              onChange={(_event, value) => setPercent(value as number)}
              aria-label={__('Simulated progress slider', 'goalcart')}
              sx={{ mt: 2 }}
            />
          </Box>
        </Stack>
      </DialogContent>
    </Dialog>
  );
}
