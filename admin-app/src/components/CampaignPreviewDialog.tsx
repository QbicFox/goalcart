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

import { formatCurrency, formatNumber } from '../lib/format';
import type { Campaign } from '../types';

interface CampaignPreviewDialogProps {
  campaign: Campaign | null;
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

function targetLabel(type: string, mode: string | undefined, target: number): string {
  if (
    type === 'quantity' ||
    type === 'distinct_quantity' ||
    type === 'weight' ||
    mode === 'quantity'
  ) {
    return formatNumber(target);
  }
  return formatCurrency(target);
}

/**
 * Campaign preview (Phase 10 "preview" feature). A lightweight mock of the
 * milestone ladder — how the campaign reads as an ordered set of
 * thresholds — with a simulated progress state. The full admin preview
 * system (simulated cart, device widths, template rendering) is Phase 15.
 * Read-only: preview never touches the real cart.
 */
export default function CampaignPreviewDialog({ campaign, onClose }: CampaignPreviewDialogProps) {
  const [percent, setPercent] = useState(50);

  const completedIndex = useMemo(() => {
    if (!campaign || campaign.goals.length === 0) {
      return -1;
    }

    return Math.min(campaign.goals.length - 1, Math.floor((campaign.goals.length * percent) / 100));
  }, [campaign, percent]);

  const milestones = campaign?.goals ?? [];

  return (
    <Dialog open={campaign !== null} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>{__('Campaign preview', 'goalcart')}</DialogTitle>
      <DialogContent>
        <Stack spacing={3}>
          <Paper variant="outlined" sx={{ p: 3 }}>
            <Stack spacing={1}>
              <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>
                {campaign?.name}
              </Typography>
              {campaign?.description && (
                <Typography variant="body2" color="text.secondary">
                  {campaign.description}
                </Typography>
              )}

              {milestones.length === 0 ? (
                <Typography variant="body2" color="text.secondary">
                  {__('No milestones in this campaign yet.', 'goalcart')}
                </Typography>
              ) : (
                <Stack spacing={1} sx={{ mt: 1 }}>
                  {milestones.map((milestone, index) => {
                    const reached = index <= completedIndex;
                    const reward = milestone.reward_type
                      ? (REWARD_LABELS[milestone.reward_type] ?? milestone.reward_type)
                      : '';

                    return (
                      <Box
                        key={milestone.id}
                        sx={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: 1.5,
                          p: 1.25,
                          borderRadius: 1,
                          border: '1px solid',
                          borderColor: reached ? 'success.main' : 'divider',
                          bgcolor: reached ? 'success.light' : 'transparent',
                          transition: 'background-color 200ms ease, border-color 200ms ease',
                        }}
                      >
                        <Box
                          sx={{
                            width: 28,
                            height: 28,
                            borderRadius: '50%',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: reached ? 'success.contrastText' : 'text.secondary',
                            bgcolor: reached ? 'success.main' : 'action.hover',
                            fontSize: 13,
                            fontWeight: 600,
                            flexShrink: 0,
                          }}
                        >
                          {index + 1}
                        </Box>
                        <Box sx={{ flex: 1, minWidth: 0 }}>
                          <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                            {targetLabel(milestone.type, undefined, milestone.target)}
                          </Typography>
                          <Typography variant="caption" color="text.secondary" noWrap>
                            {milestone.name}
                          </Typography>
                        </Box>
                        {reward && (
                          <Chip
                            size="small"
                            variant={reached ? 'filled' : 'outlined'}
                            color={reached ? 'success' : 'default'}
                            label={reward}
                          />
                        )}
                      </Box>
                    );
                  })}
                </Stack>
              )}

              <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mt: 1 }}>
                <Box sx={{ flex: 1 }}>
                  <LinearProgress
                    variant="determinate"
                    value={percent}
                    color={percent >= 100 ? 'success' : 'primary'}
                    sx={{ height: 8, borderRadius: 4 }}
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
