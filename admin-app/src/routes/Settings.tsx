import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Controller, useForm } from 'react-hook-form';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import FormControlLabel from '@mui/material/FormControlLabel';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import { fetchSettings, saveSettings } from '../api/settings';
import type { GoalCartSettings } from '../types';
import PageContainer from '../components/PageContainer';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { useFullscreen } from '../providers/FullscreenProvider';

/**
 * Settings (P08-T03): the first fully functional page.
 *
 * Loads the persisted settings through the Phase 7 REST API and saves
 * them with react-hook-form + a TanStack Query mutation. The full-screen
 * toggle also switches the live display mode instantly (FullscreenProvider
 * owns the body class from then on), so saving needs no page reload. The
 * full settings surface (goal calculation, performance, advanced) grows
 * here in Phase 18.
 */
export default function Settings() {
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();
  const { setFullscreen } = useFullscreen();

  const settingsQuery = useQuery({
    queryKey: ['settings'],
    queryFn: fetchSettings,
  });

  // `values` keeps the form in sync with the server state as it loads
  // and after refetches — the form only becomes the source of truth on
  // save.
  // `values` keeps the form in sync with the server state — after a save
  // the mutation's setQueryData below updates `values`, which resets the
  // form (no manual reset needed). The defaultValues mirror the PHP
  // Settings::defaults() (keep them in sync if those change).
  const { control, handleSubmit } = useForm<GoalCartSettings>({
    defaultValues: { enabled: true, fullscreen_dashboard: true },
    values: settingsQuery.data,
  });

  const saveMutation = useMutation({
    mutationFn: (values: GoalCartSettings) => saveSettings(values),
    onSuccess: (saved) => {
      notify(__('Settings saved.', 'goalcart'));
      // Updating the query data re-syncs the form via the `values` prop.
      void queryClient.setQueryData(['settings'], saved);
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
    },
  });

  if (settingsQuery.isLoading) {
    return (
      <PageContainer
        title={__('Settings', 'goalcart')}
        description={__('Plugin-wide configuration.', 'goalcart')}
      >
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={120} />
          <Skeleton variant="rounded" height={160} />
        </Stack>
      </PageContainer>
    );
  }

  if (settingsQuery.isError) {
    return (
      <PageContainer
        title={__('Settings', 'goalcart')}
        description={__('Plugin-wide configuration.', 'goalcart')}
      >
        <Alert severity="error" variant="outlined">
          {settingsQuery.error instanceof Error
            ? settingsQuery.error.message
            : __('Could not load the settings.', 'goalcart')}
        </Alert>
      </PageContainer>
    );
  }

  return (
    <PageContainer
      title={__('Settings', 'goalcart')}
      description={__('Plugin-wide configuration.', 'goalcart')}
    >
      <form onSubmit={handleSubmit((values) => saveMutation.mutate(values))}>
        <Stack spacing={3}>
          <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }}>
            <Typography variant="h6" component="h3" gutterBottom>
              {__('General', 'goalcart')}
            </Typography>
            <Stack spacing={1}>
              <Controller
                name="enabled"
                control={control}
                render={({ field }) => (
                  <FormControlLabel
                    control={<Switch checked={field.value} onChange={field.onChange} />}
                    label={
                      <Box>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {__('Enable Goal Cart', 'goalcart')}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {__(
                            'Turn the storefront goals, rewards and progress bars on or off.',
                            'goalcart'
                          )}
                        </Typography>
                      </Box>
                    }
                  />
                )}
              />
              <Controller
                name="fullscreen_dashboard"
                control={control}
                render={({ field }) => (
                  <FormControlLabel
                    control={
                      <Switch
                        checked={field.value}
                        onChange={(event) => {
                          field.onChange(event);
                          // Preview the mode instantly — the persisted
                          // value is saved with the form.
                          setFullscreen(event.target.checked);
                        }}
                      />
                    }
                    label={
                      <Box>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {__('Full-screen dashboard', 'goalcart')}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {__(
                            'Hide the WordPress admin chrome and let the dashboard fill the whole browser window.',
                            'goalcart'
                          )}
                        </Typography>
                      </Box>
                    }
                  />
                )}
              />
            </Stack>
          </Paper>

          <Box>
            <Button
              type="submit"
              variant="contained"
              disabled={saveMutation.isPending}
              sx={{ minWidth: 120 }}
            >
              {saveMutation.isPending ? __('Saving…', 'goalcart') : __('Save settings', 'goalcart')}
            </Button>
          </Box>
        </Stack>
      </form>
    </PageContainer>
  );
}
