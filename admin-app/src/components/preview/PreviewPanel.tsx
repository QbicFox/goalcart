import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';

import { templateById } from '../../templates/useTemplates';
import type { TemplateScope } from '../../types';
import PreviewControls from './PreviewControls';
import PreviewWidget from './PreviewWidget';
import { tokensFromSettings } from './types';
import type { PreviewState } from './usePreview';

interface PreviewPanelProps {
  /** The previewed scope: mission (one card) or campaign (milestone group). */
  scope: TemplateScope;
  /** The shared preview state (controls + queries) from usePreview. */
  preview: PreviewState;
}

/**
 * The preview frame: the real storefront widget mirror rendered
 * ABOVE the (remaining) Preview Settings section, using the full width of
 * its column. Shared by the Mission and Campaign builders (sticky column) so
 * the preview rendering can never drift between the two scopes — the same
 * components the frontend uses.
 *
 * The template is never chosen here: the preview renders whatever the
 * backend resolved for the current form state (item override → scope
 * default → fallback) — the exact same template the storefront renders —
 * so a mission/campaign with a selected template previews that template and
 * one without previews the global default. The only preview setting left
 * is the progress state (empty cart → completed), which derives its
 * simulated values internally from the form target.
 */
export default function PreviewPanel({ scope, preview }: PreviewPanelProps) {
  const { controls, applyPreset, previewQuery, settingsQuery, templatesQuery } = preview;
  const settings = settingsQuery.data?.data;
  const templates = templatesQuery.data;
  const tokens = tokensFromSettings(settings);
  const missions = previewQuery.data?.missions ?? [];
  const completedCount = missions.filter((mission) => mission.completed).length;
  const percent = missions[0] ? Math.round(missions[0].percentage) : 0;

  // The resolved template comes from the preview payload — the backend's
  // TemplateEngine (the single template-resolution mechanism shared with
  // the storefront) already applied item override → scope default →
  // fallback. A campaign without its own template renders per-mission cards,
  // so its label falls back to the first milestone's resolved template.
  const resolvedTemplate =
    scope === 'mission'
      ? previewQuery.data?.missions[0]?.template ?? ''
      : (previewQuery.data?.campaigns?.[0]?.template ?? previewQuery.data?.missions[0]?.template ?? '');
  const resolvedTemplateLabel =
    templateById(templates, scope, resolvedTemplate)?.label ??
    templateById(templates, 'mission', resolvedTemplate)?.label ??
    (resolvedTemplate || undefined);

  const rewardLabel =
    scope === 'campaign'
      ? sprintf(
          /* translators: %1$d: completed milestones, %2$d: total milestones. */
          __('%1$d/%2$d milestones', 'faracart'),
          completedCount,
          missions.length
        )
      : sprintf(__('%d%% progress', 'faracart'), percent);

  return (
    <Stack spacing={2}>
      {/* The actual rendered preview — first, above the preview settings. */}
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
          <Chip size="small" variant="outlined" label={rewardLabel} />
          {resolvedTemplateLabel && (
            <Chip size="small" variant="outlined" label={resolvedTemplateLabel} />
          )}
        </Box>

        {previewQuery.isError ? (
          <Alert severity="error" variant="outlined">
            {previewQuery.error instanceof Error
              ? previewQuery.error.message
              : __('Could not load the preview.', 'faracart')}
          </Alert>
        ) : previewQuery.data ? (
          scope === 'campaign' && missions.length === 0 ? (
            <Alert severity="info" variant="outlined">
              {__('No milestones in this campaign yet.', 'faracart')}
            </Alert>
          ) : scope === 'mission' && missions.length > 0 && missions[0].target <= 0 ? (
            // A mission with no target yet (a fresh or cleared target field)
            // evaluates as trivially complete server-side (target ≤ 0 →
            // 100%). For an unsaved draft that is misleading — there is no
            // progress to preview — so show a configuring hint instead of
            // a fake "100% complete" card.
            <Alert severity="info" variant="outlined">
              {__('Set a target to preview progress.', 'faracart')}
            </Alert>
          ) : (
            <>
              <PreviewWidget
                missions={missions}
                campaigns={previewQuery.data.campaigns}
                currency={previewQuery.data.currency}
                tokens={tokens}
                rewardState="auto"
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
      </Paper>

      {/* Preview Settings — the remaining preview-level control. */}
      <Paper variant="outlined" sx={{ p: 2.5 }}>
        <PreviewControls value={controls} onApplyPreset={applyPreset} />
      </Paper>
    </Stack>
  );
}
