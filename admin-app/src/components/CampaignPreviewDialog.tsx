import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Grid from '@mui/material/Grid';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';

import type { Campaign, CampaignGoal } from '../types';
import PreviewControls from './preview/PreviewControls';
import PreviewWidget from './preview/PreviewWidget';
import { usePreviewDialog } from './preview/usePreviewDialog';
import { DEVICE_WIDTHS, tokensFromSettings } from './preview/types';
import type { PreviewDevice } from './preview/types';
import { templateById } from '../templates/useTemplates';

interface CampaignPreviewDialogProps {
  campaign: Campaign | null;
  onClose: () => void;
}

const COUNT_TYPES = ['quantity', 'distinct_quantity', 'weight'];

/** Whether a milestone type measures money (mirrors Goal::is_money_goal). */
function isMoneyType(type: string): boolean {
  return !COUNT_TYPES.includes(type);
}

/** The campaign's top milestone — the anchor for the state presets. */
function topMilestone(campaign: Campaign): CampaignGoal | null {
  let top: CampaignGoal | null = null;

  for (const goal of campaign.goals) {
    if (!top || goal.target > top.target) {
      top = goal;
    }
  }

  return top;
}

/** Amount/quantity implied by a state preset relative to the top target. */
function presetTargets(campaign: Campaign, fraction: number): { amount: number; quantity: number } {
  const top = topMilestone(campaign);
  const value = (top ? Number(top.target) : 0) * fraction;

  return top && isMoneyType(top.type)
    ? { amount: value, quantity: 0 }
    : { amount: 0, quantity: value };
}

const DEVICE_LABELS: Record<PreviewDevice, string> = {
  mobile: __('Mobile', 'goalcart'),
  tablet: __('Tablet', 'goalcart'),
  desktop: __('Desktop', 'goalcart'),
};

/**
 * Campaign preview (Phase 15: Admin Preview System). The milestone ladder
 * as customers see it — every milestone goal evaluated server-side
 * against the same SIMULATED cart, rendered with the real storefront
 * widget mirror at the chosen device width and template. The presets
 * (empty cart / 25% / 50% / 75% / completed) are anchored to the
 * campaign's top milestone target, so mid states naturally show several
 * completed milestones ("multiple milestones"). Read-only: preview never
 * touches the real WooCommerce cart, and publish gating is ignored so
 * scheduled campaigns can be seen before they go live.
 */
export default function CampaignPreviewDialog({ campaign, onClose }: CampaignPreviewDialogProps) {
  const { controls, patch, applyPreset, previewQuery, settingsQuery, templatesQuery } =
    usePreviewDialog({
      target: campaign,
      derive: (current: Campaign) => ({
        // '' = auto: the payload's campaign group carries the campaign's
        // resolved template + settings (the backend resolves them from the
        // campaign display_rules, identically to the live frontend).
        templateDefault: '',
        targetsFor: (fraction) => presetTargets(current, fraction),
        paramsFor: () => ({ campaignId: current.id }),
      }),
    });

  const settings = settingsQuery.data?.data;
  const templates = templatesQuery.data;
  const tokens = tokensFromSettings(settings);
  const frameWidth = DEVICE_WIDTHS[controls.deviceWidth];
  const goals = previewQuery.data?.goals ?? [];
  const completedCount = goals.filter((goal) => goal.completed).length;
  const forcedTemplate = controls.template
    ? templateById(templates, 'campaign', controls.template)
    : undefined;
  const resolvedTemplate = controls.template || previewQuery.data?.campaigns?.[0]?.template || '';
  const resolvedTemplateLabel =
    forcedTemplate?.label ??
    templateById(templates, 'campaign', resolvedTemplate)?.label ??
    (resolvedTemplate || __('Auto', 'goalcart'));

  return (
    <Dialog open={campaign !== null} onClose={onClose} maxWidth="lg" fullWidth>
      <DialogTitle>
        {campaign
          ? sprintf(__('Preview: %s', 'goalcart'), campaign.name)
          : __('Campaign preview', 'goalcart')}
      </DialogTitle>
      <DialogContent>
        <Grid container spacing={3}>
          <Grid size={{ xs: 12, md: 4, lg: 3 }}>
            <Paper variant="outlined" sx={{ p: 2.5 }}>
              <PreviewControls
                value={controls}
                onPatch={patch}
                onApplyPreset={applyPreset}
                templates={templates?.campaign ?? []}
              />
            </Paper>
          </Grid>

          <Grid size={{ xs: 12, md: 8, lg: 9 }}>
            <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 }, bgcolor: '#f6f7f7' }}>
              <Box
                sx={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  mb: 2,
                  gap: 1,
                }}
              >
                <Typography variant="subtitle2" color="text.secondary">
                  {DEVICE_LABELS[controls.deviceWidth]} · {frameWidth}px
                </Typography>{' '}
                <Chip
                  size="small"
                  variant="outlined"
                  label={sprintf(
                    /* translators: %1$d: completed milestones, %2$d: total milestones. */
                    __('%1$d/%2$d milestones', 'goalcart'),
                    completedCount,
                    goals.length
                  )}
                />
                <Chip size="small" variant="outlined" label={resolvedTemplateLabel} />
              </Box>

              <Box sx={{ maxWidth: frameWidth, margin: '0 auto' }}>
                {previewQuery.isError ? (
                  <Alert severity="error" variant="outlined">
                    {previewQuery.error instanceof Error
                      ? previewQuery.error.message
                      : __('Could not load the preview.', 'goalcart')}
                  </Alert>
                ) : previewQuery.data ? (
                  goals.length === 0 ? (
                    <Alert severity="info" variant="outlined">
                      {__('No milestones in this campaign yet.', 'goalcart')}
                    </Alert>
                  ) : (
                    <>
                      {' '}
                      <PreviewWidget
                        goals={goals}
                        campaigns={previewQuery.data.campaigns}
                        currency={previewQuery.data.currency}
                        tokens={tokens}
                        templateOverride={controls.template || undefined}
                        settingsOverride={forcedTemplate?.settings}
                        rewardState={controls.rewardState}
                        animation={settings?.frontend_animation ?? true}
                      />
                      {previewQuery.isFetching && previewQuery.isPlaceholderData && (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          sx={{ display: 'block', mt: 1, textAlign: 'center' }}
                        >
                          {__('Updating…', 'goalcart')}
                        </Typography>
                      )}
                    </>
                  )
                ) : (
                  <Skeleton variant="rounded" height={180} />
                )}
              </Box>
            </Paper>
          </Grid>
        </Grid>
      </DialogContent>
    </Dialog>
  );
}
