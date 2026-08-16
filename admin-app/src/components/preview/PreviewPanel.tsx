import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';

import { templateById } from '../../templates/useTemplates';
import type { CampaignInput, ProgressCampaign, TemplateScope } from '../../types';
import PreviewControls from './PreviewControls';
import PreviewWidget from './PreviewWidget';
import { DEVICE_WIDTHS, tokensFromSettings } from './types';
import type { PreviewDevice } from './types';
import type { PreviewState } from './usePreview';

const DEVICE_LABELS: Record<PreviewDevice, string> = {
  mobile: __('Mobile', 'faracart'),
  tablet: __('Tablet', 'faracart'),
  desktop: __('Desktop', 'faracart'),
};

interface PreviewPanelProps {
  /** The previewed scope: goal (one card) or campaign (milestone group). */
  scope: TemplateScope;
  /** The shared preview state (controls + queries) from usePreview. */
  preview: PreviewState;
  /**
   * The current campaign form values — used to synthesize a forced
   * campaign-template group in the preview frame when the admin picks a
   * template override the form does not configure yet.
   */
  campaign?: CampaignInput | null;
}

/**
 * The Phase 15 preview frame: preview controls + the real storefront
 * widget mirror at the chosen device width. Shared by the Goal and
 * Campaign builders (sticky column) so the preview rendering can never
 * drift between the two scopes — the same components the old preview
 * dialogs used.
 */
export default function PreviewPanel({ scope, preview, campaign }: PreviewPanelProps) {
  const { controls, patch, applyPreset, previewQuery, settingsQuery, templatesQuery } = preview;
  const settings = settingsQuery.data?.data;
  const templates = templatesQuery.data;
  const tokens = tokensFromSettings(settings);
  const frameWidth = DEVICE_WIDTHS[controls.deviceWidth];
  const goals = previewQuery.data?.goals ?? [];
  const completedCount = goals.filter((goal) => goal.completed).length;
  const percent = goals[0] ? Math.round(goals[0].percentage) : 0;

  // A forced template override renders with that template's global default
  // appearance (from the registry); '' renders each goal with its own
  // resolved template + settings from the payload.
  const forcedTemplate = controls.template
    ? templateById(templates, scope, controls.template)
    : undefined;
  const resolvedTemplate =
    controls.template ||
    (scope === 'goal'
      ? previewQuery.data?.goals[0]?.template
      : previewQuery.data?.campaigns?.[0]?.template) ||
    (scope === 'goal' ? (settings?.frontend_template ?? 'template-1') : '') ||
    'template-1';
  const resolvedTemplateLabel =
    forcedTemplate?.label ??
    templateById(templates, scope, resolvedTemplate)?.label ??
    (scope === 'campaign' && resolvedTemplate === 'template-1'
      ? __('Auto', 'faracart')
      : resolvedTemplate);

  // Forced campaign template: synthesize the campaign group so the whole
  // milestone group renders through the chosen campaign template even
  // when the form configures none yet. The group keeps the payload's id
  // so PreviewWidget's campaign grouping still matches the goal rows.
  const payloadGroup = previewQuery.data?.campaigns?.[0];
  const campaignGroups: ProgressCampaign[] =
    scope === 'campaign' && forcedTemplate
      ? [
          {
            campaign_id: payloadGroup?.campaign_id ?? -1,
            name: payloadGroup?.name ?? campaign?.name ?? '',
            template: forcedTemplate.id,
            settings: forcedTemplate.settings,
          },
        ]
      : (previewQuery.data?.campaigns ?? []);

  const rewardLabel =
    scope === 'campaign'
      ? sprintf(
          /* translators: %1$d: completed milestones, %2$d: total milestones. */
          __('%1$d/%2$d milestones', 'faracart'),
          completedCount,
          goals.length
        )
      : sprintf(__('%d%% progress', 'faracart'), percent);

  return (
    <Stack spacing={2}>
      <Paper variant="outlined" sx={{ p: 2.5 }}>
        <PreviewControls
          value={controls}
          onPatch={patch}
          onApplyPreset={applyPreset}
          templates={templates?.[scope] ?? []}
        />
      </Paper>

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
          </Typography>
          <Chip size="small" variant="outlined" label={rewardLabel} />
          <Chip size="small" variant="outlined" label={resolvedTemplateLabel} />
        </Box>

        <Box sx={{ maxWidth: frameWidth, margin: '0 auto' }}>
          {previewQuery.isError ? (
            <Alert severity="error" variant="outlined">
              {previewQuery.error instanceof Error
                ? previewQuery.error.message
                : __('Could not load the preview.', 'faracart')}
            </Alert>
          ) : previewQuery.data ? (
            scope === 'campaign' && goals.length === 0 ? (
              <Alert severity="info" variant="outlined">
                {__('No milestones in this campaign yet.', 'faracart')}
              </Alert>
            ) : (
              <>
                <PreviewWidget
                  goals={goals}
                  campaigns={campaignGroups}
                  currency={previewQuery.data.currency}
                  tokens={tokens}
                  templateOverride={scope === 'goal' ? controls.template || undefined : undefined}
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
                    {__('Updating…', 'faracart')}
                  </Typography>
                )}
              </>
            )
          ) : (
            <Skeleton variant="rounded" height={180} />
          )}
        </Box>
      </Paper>
    </Stack>
  );
}
