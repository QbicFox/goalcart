import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Controller, useForm, type Control, type Path } from 'react-hook-form';
import BuildIcon from '@mui/icons-material/Build';
import CalculateIcon from '@mui/icons-material/Calculate';
import SpeedIcon from '@mui/icons-material/Speed';
import StorefrontIcon from '@mui/icons-material/Storefront';
import TuneIcon from '@mui/icons-material/Tune';
import { __ } from '@wordpress/i18n';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState } from 'react';

import { fetchSettingsEnvelope, saveSettings } from '../api/settings';
import SectionCard from '../components/goal-builder/SectionCard';
import PageContainer from '../components/PageContainer';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { useFullscreen } from '../providers/FullscreenProvider';
import type { FrontendLocation, GoalCartSettings } from '../types';

/* ------------------------------------------------------------------ *
 * Field helpers (mirror the reference plugin's Settings page)
 * ------------------------------------------------------------------ */

/** A switch-backed boolean setting. */
function BooleanField({
  control,
  name,
  label,
  description,
}: {
  control: Control<GoalCartSettings>;
  name: Path<GoalCartSettings>;
  label: string;
  description?: string;
}) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field }) => (
        <FormControlLabel
          control={
            <Switch
              checked={Boolean(field.value)}
              onChange={(event) => field.onChange(event.target.checked)}
            />
          }
          label={
            <Box>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {label}
              </Typography>
              {description && (
                <Typography variant="caption" color="text.secondary">
                  {description}
                </Typography>
              )}
            </Box>
          }
          sx={{ m: 0, width: '100%' }}
        />
      )}
    />
  );
}

/** A select of string options. */
function SelectField({
  control,
  name,
  label,
  description,
  options,
}: {
  control: Control<GoalCartSettings>;
  name: Path<GoalCartSettings>;
  label: string;
  description?: string;
  options: Array<{ value: string; label: string }>;
}) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field }) => (
        <TextField
          select
          size="small"
          fullWidth
          label={label}
          value={String(field.value)}
          onChange={(event) => field.onChange(event.target.value)}
          helperText={description}
          sx={{ maxWidth: 360 }}
        >
          {options.map((option) => (
            <MenuItem key={option.value} value={option.value}>
              {option.label}
            </MenuItem>
          ))}
        </TextField>
      )}
    />
  );
}

/** The six storefront widget locations as a checkbox group. */
const LOCATION_OPTIONS: Array<{ value: FrontendLocation; label: string }> = [
  { value: 'cart', label: __('Cart page', 'goalcart') },
  { value: 'mini-cart', label: __('Mini cart', 'goalcart') },
  { value: 'checkout', label: __('Checkout', 'goalcart') },
  { value: 'shop', label: __('Shop / archives', 'goalcart') },
  { value: 'product', label: __('Product pages', 'goalcart') },
  { value: 'sticky', label: __('Sticky bottom bar', 'goalcart') },
];

function LocationField({
  control,
  name,
  description,
}: {
  control: Control<GoalCartSettings>;
  name: Path<GoalCartSettings>;
  description: string;
}) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field }) => {
        const selected: FrontendLocation[] = Array.isArray(field.value) ? field.value : [];

        return (
          <Box>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 0.5 }}>
              {description}
            </Typography>
            <Stack
              direction="row"
              useFlexGap
              sx={{
                flexWrap: 'wrap',
                gap: 0,
                '& .MuiFormControlLabel-root': { mr: 0, minWidth: 190 },
              }}
            >
              {LOCATION_OPTIONS.map((option) => {
                const checked = selected.includes(option.value);

                return (
                  <FormControlLabel
                    key={option.value}
                    control={
                      <Checkbox
                        size="small"
                        checked={checked}
                        onChange={(event) => {
                          const next = new Set(selected);

                          if (event.target.checked) {
                            next.add(option.value);
                          } else {
                            next.delete(option.value);
                          }

                          field.onChange(Array.from(next));
                        }}
                      />
                    }
                    label={option.label}
                  />
                );
              })}
            </Stack>
          </Box>
        );
      }}
    />
  );
}

/** One row of the developer-hooks reference (Advanced tab). */
function HookRow({ type, hook, description }: { type: string; hook: string; description: string }) {
  return (
    <Box
      sx={{
        display: 'flex',
        alignItems: 'flex-start',
        gap: 1.25,
        py: 0.75,
        borderBottom: '1px solid',
        borderColor: 'divider',
        '&:last-child': { borderBottom: 0 },
      }}
    >
      <Chip
        size="small"
        variant="outlined"
        color={type === 'action' ? 'primary' : 'default'}
        label={type}
      />
      <Box sx={{ minWidth: 0 }}>
        <Typography
          variant="body2"
          sx={{ fontWeight: 600, fontFamily: 'monospace', wordBreak: 'break-all' }}
        >
          {hook}
        </Typography>
        <Typography variant="caption" color="text.secondary">
          {description}
        </Typography>
      </Box>
    </Box>
  );
}

/* ------------------------------------------------------------------ *
 * Settings page (Phase 18: full surface in five tabs)
 * ------------------------------------------------------------------ */

/**
 * Settings (Phase 18 — the full configuration surface).
 *
 * Five tabs over a single react-hook-form instance: General, Frontend,
 * Goal Calculation, Performance and Advanced. Every control maps 1:1 to
 * a persisted setting key validated server-side by the Phase 7 REST
 * schema; saving updates the query data so the form re-syncs, and the
 * full-screen toggle still previews live through FullscreenProvider.
 *
 * Mirrors the reference plugin's tabbed Settings page.
 */
export default function Settings() {
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();
  const { setFullscreen } = useFullscreen();

  const [tab, setTab] = useState(0);

  const settingsQuery = useQuery({
    queryKey: ['settings'],
    queryFn: fetchSettingsEnvelope,
  });

  const data = settingsQuery.data?.data;
  const meta = settingsQuery.data?.meta ?? {};
  const hooks = meta.hooks ?? [];

  // `values` keeps the form in sync with the server state as it loads and
  // after saves (the mutation's setQueryData below updates `values`, which
  // resets the form — no manual reset needed). The defaultValues mirror the
  // PHP Settings::defaults() (keep them in sync if those change).
  const { control, handleSubmit } = useForm<GoalCartSettings>({
    defaultValues: { enabled: true, fullscreen_dashboard: true },
    values: data,
  });

  const saveMutation = useMutation({
    mutationFn: (values: GoalCartSettings) => saveSettings(values),
    onSuccess: (saved) => {
      notify(__('Settings saved.', 'goalcart'));

      // Re-sync the form and refresh the meta (the debug log path appears
      // once logging is enabled).
      void queryClient.setQueryData(['settings'], { data: saved, meta });
      void settingsQuery.refetch();

      // Apply the full-screen toggle live (no page reload): the provider
      // toggles the body class that hides/shows the WP admin chrome.
      if (typeof saved.fullscreen_dashboard === 'boolean') {
        setFullscreen(saved.fullscreen_dashboard);
      }
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
      description={__('Plugin-wide configuration. Changes apply immediately.', 'goalcart')}
    >
      <form onSubmit={handleSubmit((values) => saveMutation.mutate(values))}>
        <Stack spacing={3}>
          <Tabs
            value={tab}
            onChange={(_, next) => setTab(next)}
            variant="scrollable"
            scrollButtons="auto"
            sx={{ borderBottom: 1, borderColor: 'divider' }}
          >
            <Tab icon={<TuneIcon />} iconPosition="start" label={__('General', 'goalcart')} />
            <Tab
              icon={<StorefrontIcon />}
              iconPosition="start"
              label={__('Frontend', 'goalcart')}
            />
            <Tab
              icon={<CalculateIcon />}
              iconPosition="start"
              label={__('Goal Calculation', 'goalcart')}
            />
            <Tab icon={<SpeedIcon />} iconPosition="start" label={__('Performance', 'goalcart')} />
            <Tab icon={<BuildIcon />} iconPosition="start" label={__('Advanced', 'goalcart')} />
          </Tabs>

          {tab === 0 && (
            <Stack spacing={2.5}>
              <SectionCard
                title={__('General', 'goalcart')}
                description={__('Master toggle, display and default behavior.', 'goalcart')}
              >
                <Stack spacing={2}>
                  <BooleanField
                    control={control}
                    name="enabled"
                    label={__('Enable Goal Cart', 'goalcart')}
                    description={__(
                      'Turn the storefront goals, rewards and progress bars on or off.',
                      'goalcart'
                    )}
                  />
                  <SelectField
                    control={control}
                    name="currency_display"
                    label={__('Currency display', 'goalcart')}
                    description={__(
                      'How money amounts are shown on the storefront widgets.',
                      'goalcart'
                    )}
                    options={[
                      { value: 'symbol', label: __('Symbol ($100)', 'goalcart') },
                      { value: 'code', label: __('Code (USD 100)', 'goalcart') },
                      { value: 'name', label: __('Name (US dollars)', 'goalcart') },
                    ]}
                  />
                  <SelectField
                    control={control}
                    name="default_goal_behavior"
                    label={__('Default goal behavior', 'goalcart')}
                    description={__(
                      'How multiple active goals are presented when the shopper has no campaign.',
                      'goalcart'
                    )}
                    options={[
                      { value: 'all', label: __('Show all goals', 'goalcart') },
                      { value: 'first', label: __('Show the first goal only', 'goalcart') },
                      { value: 'closest', label: __('Show the closest goal only', 'goalcart') },
                    ]}
                  />
                  <SelectField
                    control={control}
                    name="conflict_resolution"
                    label={__('Conflict resolution', 'goalcart')}
                    description={__(
                      'How completed goals grant rewards when several compete: combine them, grant only the highest-priority matching goal, or grant only the best reward. Per-goal exclusive flags and priorities are respected in every mode.',
                      'goalcart'
                    )}
                    options={[
                      {
                        value: 'cumulative',
                        label: __('Cumulative — all rewards stack', 'goalcart'),
                      },
                      {
                        value: 'first',
                        label: __('First matching goal only', 'goalcart'),
                      },
                      {
                        value: 'best',
                        label: __('Best reward only', 'goalcart'),
                      },
                    ]}
                  />
                  <SelectField
                    control={control}
                    name="calculation_mode"
                    label={__('Calculation mode', 'goalcart')}
                    description={__(
                      'Default money basis for goals that do not set their own.',
                      'goalcart'
                    )}
                    options={[
                      {
                        value: 'subtotal',
                        label: __('Subtotal (before discounts)', 'goalcart'),
                      },
                      {
                        value: 'discounted_subtotal',
                        label: __('Discounted subtotal', 'goalcart'),
                      },
                      {
                        value: 'total',
                        label: __('Cart total (incl. tax & shipping)', 'goalcart'),
                      },
                    ]}
                  />
                  <BooleanField
                    control={control}
                    name="fullscreen_dashboard"
                    label={__('Full-screen dashboard', 'goalcart')}
                    description={__(
                      'Hide the WordPress admin chrome and let the dashboard fill the whole browser window.',
                      'goalcart'
                    )}
                  />
                </Stack>
              </SectionCard>
            </Stack>
          )}

          {tab === 1 && (
            <Stack spacing={2.5}>
              <SectionCard
                title={__('Frontend', 'goalcart')}
                description={__('Where and how the storefront widgets appear.', 'goalcart')}
              >
                <Stack spacing={2}>
                  <LocationField
                    control={control}
                    name="frontend_locations"
                    description={__('Display locations', 'goalcart')}
                  />
                  <SelectField
                    control={control}
                    name="frontend_template"
                    label={__('Template', 'goalcart')}
                    description={__('Store-wide progress widget variant.', 'goalcart')}
                    options={[
                      { value: 'basic', label: __('Basic', 'goalcart') },
                      { value: 'percentage', label: __('Percentage', 'goalcart') },
                      { value: 'milestone', label: __('Milestone', 'goalcart') },
                      { value: 'card', label: __('Card', 'goalcart') },
                    ]}
                  />
                  <BooleanField
                    control={control}
                    name="frontend_animation"
                    label={__('Animate progress', 'goalcart')}
                    description={__('Slide the progress-bar fill on updates.', 'goalcart')}
                  />
                  <SelectField
                    control={control}
                    name="frontend_mobile"
                    label={__('Mobile behavior', 'goalcart')}
                    description={__(
                      'Whether the widgets render on small screens (≤782px).',
                      'goalcart'
                    )}
                    options={[
                      { value: 'show', label: __('Show on mobile', 'goalcart') },
                      { value: 'hide', label: __('Hide on mobile', 'goalcart') },
                    ]}
                  />
                  <BooleanField
                    control={control}
                    name="frontend_countdown"
                    label={__('Countdown timer', 'goalcart')}
                    description={__(
                      'Show a live countdown to the goal/campaign deadline (Phase 32).',
                      'goalcart'
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="frontend_celebrate"
                    label={__('Celebration animation', 'goalcart')}
                    description={__(
                      'Play a confetti burst when a goal is reached (Phase 32).',
                      'goalcart'
                    )}
                  />
                </Stack>
              </SectionCard>

              <SectionCard
                title={__('Sticky bar', 'goalcart')}
                description={__(
                  'The advanced sticky bottom/top bar (Phase 32): position, behavior, countdown and suggestions.',
                  'goalcart'
                )}
              >
                <Stack spacing={2}>
                  <SelectField
                    control={control}
                    name="sticky_position"
                    label={__('Position', 'goalcart')}
                    description={__('Where the sticky bar docks.', 'goalcart')}
                    options={[
                      { value: 'bottom', label: __('Bottom', 'goalcart') },
                      { value: 'top', label: __('Top', 'goalcart') },
                    ]}
                  />
                  <SelectField
                    control={control}
                    name="sticky_behavior"
                    label={__('Behavior', 'goalcart')}
                    description={__(
                      'Dismissible bars hide via the close button; auto-hide bars collapse after a few seconds and reappear on scroll.',
                      'goalcart'
                    )}
                    options={[
                      { value: 'dismissible', label: __('Dismissible', 'goalcart') },
                      { value: 'auto_hide', label: __('Auto-hide', 'goalcart') },
                    ]}
                  />
                  <SelectField
                    control={control}
                    name="sticky_display"
                    label={__('Layout', 'goalcart')}
                    description={__(
                      'Compact shows only the progress; full adds the message and reward.',
                      'goalcart'
                    )}
                    options={[
                      { value: 'compact', label: __('Compact', 'goalcart') },
                      { value: 'full', label: __('Full', 'goalcart') },
                    ]}
                  />
                  <Controller
                    control={control}
                    name="sticky_delay"
                    render={({ field }) => (
                      <TextField
                        {...field}
                        type="number"
                        size="small"
                        fullWidth
                        label={__('Appear delay (seconds)', 'goalcart')}
                        helperText={__(
                          'How long after page load before the bar appears.',
                          'goalcart'
                        )}
                        value={Number(field.value)}
                        onChange={(event) => field.onChange(Number(event.target.value) || 0)}
                        sx={{ maxWidth: 360 }}
                      />
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="sticky_countdown"
                    label={__('Countdown in sticky bar', 'goalcart')}
                    description={__(
                      'Show the deadline countdown inside the sticky bar.',
                      'goalcart'
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="sticky_suggestions"
                    label={__('Suggestions in sticky bar', 'goalcart')}
                    description={__(
                      'List the gap-closing product suggestions in the bar.',
                      'goalcart'
                    )}
                  />
                </Stack>
              </SectionCard>
            </Stack>
          )}

          {tab === 2 && (
            <Stack spacing={2.5}>
              <SectionCard
                title={__('Goal Calculation', 'goalcart')}
                description={__(
                  'Which cart money counts toward goals. Defaults match the storefront behavior before these options existed.',
                  'goalcart'
                )}
              >
                <Stack spacing={1}>
                  <BooleanField
                    control={control}
                    name="calculation_include_tax"
                    label={__('Include taxes', 'goalcart')}
                    description={__(
                      'Add line taxes to the subtotal-style money bases.',
                      'goalcart'
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="calculation_include_discount"
                    label={__('Include discounts', 'goalcart')}
                    description={__(
                      'When off, cart coupons and discounts do not reduce the discounted-subtotal basis.',
                      'goalcart'
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="calculation_include_shipping"
                    label={__('Include shipping', 'goalcart')}
                    description={__(
                      'When on, shipping charges count toward the cart-total basis.',
                      'goalcart'
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="calculation_include_sale"
                    label={__('Include sale items', 'goalcart')}
                    description={__(
                      'When off, products currently on sale are excluded from every goal.',
                      'goalcart'
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="calculation_include_virtual"
                    label={__('Include virtual products', 'goalcart')}
                    description={__(
                      'When off, virtual and downloadable products are excluded from every goal.',
                      'goalcart'
                    )}
                  />
                </Stack>
              </SectionCard>
            </Stack>
          )}

          {tab === 3 && (
            <Stack spacing={2.5}>
              <SectionCard
                title={__('Performance', 'goalcart')}
                description={__('Caching and what the storefront measures.', 'goalcart')}
              >
                <Stack spacing={1}>
                  <BooleanField
                    control={control}
                    name="performance_caching"
                    label={__('Cache progress payloads', 'goalcart')}
                    description={__(
                      'Serve repeat widget polls from a short-lived cache (10s) keyed by the cart — fewer goal evaluations, at the cost of a brief staleness window after cart changes.',
                      'goalcart'
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="analytics_enabled"
                    label={__('Analytics tracking', 'goalcart')}
                    description={__(
                      'Collect goal and suggestion events. Keep the goals running while turning event collection off.',
                      'goalcart'
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="performance_suggestions"
                    label={__('Product suggestions', 'goalcart')}
                    description={__(
                      'Show recommended products that help reach the goal.',
                      'goalcart'
                    )}
                  />
                  <SelectField
                    control={control}
                    name="suggestions_ranking"
                    label={__('Suggestion ranking', 'goalcart')}
                    description={__(
                      'How suggested products are ordered (Phase 32 advanced upsell ranking).',
                      'goalcart'
                    )}
                    options={[
                      { value: 'balanced', label: __('Balanced', 'goalcart') },
                      { value: 'price', label: __('Cheapest first', 'goalcart') },
                      { value: 'popularity', label: __('Most popular first', 'goalcart') },
                    ]}
                  />
                </Stack>
              </SectionCard>
            </Stack>
          )}

          {tab === 4 && (
            <Stack spacing={2.5}>
              <SectionCard
                title={__('Advanced', 'goalcart')}
                description={__('Debugging, logging and the developer surface.', 'goalcart')}
              >
                <Stack spacing={2}>
                  <BooleanField
                    control={control}
                    name="debug_mode"
                    label={__('Debug mode', 'goalcart')}
                    description={__(
                      'Write detailed (debug-level) entries to the plugin log when logging is enabled.',
                      'goalcart'
                    )}
                  />
                  <BooleanField
                    control={control}
                    name="logging_enabled"
                    label={__('Logging', 'goalcart')}
                    description={__(
                      'Write errors (and debug entries when debug mode is on) to a log file.',
                      'goalcart'
                    )}
                  />
                  {meta.log_path && (
                    <Box>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {__('Log file', 'goalcart')}
                      </Typography>
                      <Typography
                        variant="caption"
                        color="text.secondary"
                        sx={{ fontFamily: 'monospace', wordBreak: 'break-all' }}
                      >
                        {meta.log_path}
                      </Typography>
                    </Box>
                  )}
                  <Controller
                    control={control}
                    name="frontend_custom_css"
                    render={({ field }) => (
                      <TextField
                        {...field}
                        multiline
                        minRows={3}
                        size="small"
                        fullWidth
                        label={__('Custom CSS', 'goalcart')}
                        helperText={__(
                          'Appended to the storefront widget stylesheet (tags are stripped).',
                          'goalcart'
                        )}
                        sx={{ maxWidth: 560 }}
                      />
                    )}
                  />
                </Stack>
              </SectionCard>

              {Boolean(data?.developer_hooks) && (
                <SectionCard
                  title={__('Developer hooks', 'goalcart')}
                  description={__(
                    'The plugin’s public actions and filters — a reference for theme and plugin developers.',
                    'goalcart'
                  )}
                >
                  <Box sx={{ mt: -1 }}>
                    {hooks.map((hook) => (
                      <HookRow
                        key={`${hook.type}-${hook.hook}`}
                        type={hook.type}
                        hook={hook.hook}
                        description={hook.description}
                      />
                    ))}
                  </Box>
                </SectionCard>
              )}
            </Stack>
          )}

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
