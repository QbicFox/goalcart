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

import type { Goal } from '../types';
import PreviewControls from './preview/PreviewControls';
import PreviewWidget from './preview/PreviewWidget';
import { usePreviewDialog } from './preview/usePreviewDialog';
import { DEVICE_WIDTHS, tokensFromSettings } from './preview/types';
import type { PreviewDevice } from './preview/types';
import { templateById } from '../templates/useTemplates';

interface GoalPreviewDialogProps {
  goal: Goal | null;
  onClose: () => void;
}

/** Whether a goal's progress is measured in money (mirrors Goal::is_money_goal). */
function isMoneyGoal(goal: Goal): boolean {
  const countTypes = ['quantity', 'distinct_quantity', 'weight'];

  return !countTypes.includes(goal.type) && goal.calculation_mode !== 'quantity';
}

/** Amount/quantity implied by a state preset for a goal. */
function presetTargets(goal: Goal, fraction: number): { amount: number; quantity: number } {
  const value = (Number(goal.target) || 0) * fraction;

  // Composite goals drive both bases so their children all move.
  if (goal.type === 'composite') {
    return { amount: value, quantity: value };
  }

  return isMoneyGoal(goal) ? { amount: value, quantity: 0 } : { amount: 0, quantity: value };
}

const DEVICE_LABELS: Record<PreviewDevice, string> = {
  mobile: __('Mobile', 'goalcart'),
  tablet: __('Tablet', 'goalcart'),
  desktop: __('Desktop', 'goalcart'),
};

/**
 * Goal preview (Phase 15: Admin Preview System). The full customer
 * experience, evaluated server-side against a SIMULATED cart — state
 * presets (empty cart / 25% / 50% / 75% / completed), simulated cart
 * amount & quantity, simulated reward state, device width and template
 * variant — and rendered with the real storefront widget mirror at the
 * chosen width. Read-only: preview never touches the real WooCommerce
 * cart, and publish gating is ignored so drafts can be seen first.
 */
export default function GoalPreviewDialog({ goal, onClose }: GoalPreviewDialogProps) {
  const { controls, patch, applyPreset, previewQuery, settingsQuery, templatesQuery } =
    usePreviewDialog({
      target: goal,
      derive: (current: Goal) => ({
        // '' = auto: the backend resolves the goal's own template + settings
        // (item override → scope default → legacy → fallback) into the
        // payload, exactly like the live frontend.
        templateDefault: '',
        targetsFor: (fraction) => presetTargets(current, fraction),
        paramsFor: () => ({ goalId: current.id }),
      }),
    });

  const settings = settingsQuery.data?.data;
  const templates = templatesQuery.data;
  const tokens = tokensFromSettings(settings);
  const frameWidth = DEVICE_WIDTHS[controls.deviceWidth];
  const featured = previewQuery.data?.goals[0];
  const percent = featured ? Math.round(featured.percentage) : 0;

  // A forced template override renders with that template's global default
  // appearance (from the registry); '' renders each goal with its own
  // resolved template + settings from the payload.
  const forcedTemplate = controls.template
    ? templateById(templates, 'goal', controls.template)
    : undefined;
  const resolvedTemplate =
    controls.template || featured?.template || settings?.frontend_template || 'template-1';
  const resolvedTemplateLabel =
    forcedTemplate?.label ??
    templateById(templates, 'goal', resolvedTemplate)?.label ??
    resolvedTemplate;

  return (
    <Dialog open={goal !== null} onClose={onClose} maxWidth="lg" fullWidth>
      <DialogTitle>
        {goal ? sprintf(__('Preview: %s', 'goalcart'), goal.name) : __('Goal preview', 'goalcart')}
      </DialogTitle>
      <DialogContent>
        <Grid container spacing={3}>
          <Grid size={{ xs: 12, md: 4, lg: 3 }}>
            <Paper variant="outlined" sx={{ p: 2.5 }}>
              <PreviewControls
                value={controls}
                onPatch={patch}
                onApplyPreset={applyPreset}
                templates={templates?.goal ?? []}
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
                  label={sprintf(__('%d%% progress', 'goalcart'), percent)}
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
                  <>
                    {' '}
                    <PreviewWidget
                      goals={previewQuery.data.goals}
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
