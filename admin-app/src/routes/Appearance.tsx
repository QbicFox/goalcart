import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Controller, useForm, useWatch } from 'react-hook-form';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardActionArea from '@mui/material/CardActionArea';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import InputAdornment from '@mui/material/InputAdornment';
import PaletteIcon from '@mui/icons-material/Palette';
import Paper from '@mui/material/Paper';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import Skeleton from '@mui/material/Skeleton';
import Slider from '@mui/material/Slider';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import { fetchSettings, saveSettings } from '../api/settings';
import type { FrontendTemplate, GoalCartSettings } from '../types';
import PageContainer from '../components/PageContainer';
import { useSnackbar } from '../components/notifications/SnackbarProvider';

/** Mirrors the PHP Settings::defaults() (keep them in sync). */
const DEFAULT_SETTINGS: GoalCartSettings = {
  enabled: true,
  fullscreen_dashboard: true,
  currency_display: 'symbol',
  default_goal_behavior: 'all',
  calculation_mode: 'subtotal',
  frontend_template: 'basic',
  frontend_animation: true,
  frontend_locations: ['cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky'],
  frontend_mobile: 'show',
  frontend_bar_height: 10,
  frontend_accent: '#2271b1',
  frontend_bg: '#ffffff',
  frontend_border: '#dcdcde',
  frontend_text: '#1d2327',
  frontend_radius: 10,
  frontend_css_class: '',
  frontend_custom_css: '',
  calculation_include_tax: false,
  calculation_include_discount: true,
  calculation_include_shipping: true,
  calculation_include_sale: true,
  calculation_include_virtual: true,
  performance_caching: false,
  analytics_enabled: true,
  performance_suggestions: true,
  debug_mode: false,
  logging_enabled: false,
  developer_hooks: true,
};

const TEMPLATES: Array<{ value: FrontendTemplate; label: string; description: string }> = [
  {
    value: 'basic',
    label: __('Basic', 'goalcart'),
    description: __('Progress bar + message — the classic goal strip.', 'goalcart'),
  },
  {
    value: 'percentage',
    label: __('Percentage', 'goalcart'),
    description: __('A big percent readout above the bar.', 'goalcart'),
  },
  {
    value: 'milestone',
    label: __('Milestone', 'goalcart'),
    description: __('A goal ladder of dots and targets, bar underneath.', 'goalcart'),
  },
  {
    value: 'card',
    label: __('Card', 'goalcart'),
    description: __('Icon, title and reward bundled in a card.', 'goalcart'),
  },
];

/** Resolved appearance tokens for the live preview (storefront equivalents). */
interface Tokens {
  accent: string;
  bg: string;
  border: string;
  text: string;
  radius: number;
  barHeight: number;
}

function tokensOf(form: Partial<GoalCartSettings>): Tokens {
  return {
    accent: form.frontend_accent || DEFAULT_SETTINGS.frontend_accent,
    bg: form.frontend_bg || DEFAULT_SETTINGS.frontend_bg,
    border: form.frontend_border || DEFAULT_SETTINGS.frontend_border,
    text: form.frontend_text || DEFAULT_SETTINGS.frontend_text,
    radius: form.frontend_radius ?? DEFAULT_SETTINGS.frontend_radius,
    barHeight: form.frontend_bar_height ?? DEFAULT_SETTINGS.frontend_bar_height,
  };
}

/** The progress-bar visual (mirrors .goalcart-progress markup). */
function PreviewBar({ tokens, percent }: { tokens: Tokens; percent: number }) {
  const clamped = Math.max(0, Math.min(100, percent));
  return (
    <Box
      sx={{
        position: 'relative',
        height: tokens.barHeight,
        background: '#f0f0f1',
        borderRadius: 999,
        overflow: 'hidden',
        flex: '1 1 auto',
      }}
    >
      <Box
        sx={{
          position: 'absolute',
          insetInlineStart: 0,
          insetBlockStart: 0,
          height: '100%',
          width: `${clamped}%`,
          background: tokens.accent,
          borderRadius: 'inherit',
        }}
      />
    </Box>
  );
}

/** The locked reward chip (mirrors .goalcart-reward markup). */
function PreviewReward() {
  return (
    <Box
      component="span"
      sx={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: '0.4rem',
        padding: '0.25rem 0.625rem',
        borderRadius: 999,
        fontSize: 12,
        fontWeight: 600,
        background: '#f0f0f1',
        color: '#646970',
      }}
    >
      <span aria-hidden>🔒</span>
      <span>{__('Free shipping', 'goalcart')}</span>
    </Box>
  );
}

/** A small, inline thumbnail of one template variant (template cards). */
function TemplateThumb({ template, tokens }: { template: FrontendTemplate; tokens: Tokens }) {
  if (template === 'percentage') {
    return (
      <Stack direction="row" alignItems="center" spacing={1} sx={{ width: '100%' }}>
        <Typography sx={{ fontSize: 20, fontWeight: 800, color: tokens.accent, lineHeight: 1 }}>
          62%
        </Typography>
        <PreviewBar tokens={tokens} percent={62} />
      </Stack>
    );
  }

  if (template === 'milestone') {
    return (
      <Stack spacing={1} sx={{ width: '100%' }}>
        <Stack direction="row" alignItems="center" justifyContent="space-between">
          {[
            { target: '50$', done: true },
            { target: '100$', done: true },
            { target: '150$', done: false },
          ].map((step) => (
            <Stack key={step.target} direction="row" alignItems="center" spacing={0.5}>
              <Box
                sx={{
                  width: 10,
                  height: 10,
                  borderRadius: '50%',
                  background: step.done ? tokens.accent : tokens.border,
                }}
              />
              <Typography sx={{ fontSize: 10, color: step.done ? tokens.text : '#646970' }}>
                {step.target}
              </Typography>
            </Stack>
          ))}
        </Stack>
        <PreviewBar tokens={tokens} percent={62} />
      </Stack>
    );
  }

  if (template === 'card') {
    return (
      <Stack spacing={0.75} sx={{ width: '100%' }}>
        <Stack direction="row" alignItems="center" spacing={0.75}>
          <Typography sx={{ fontSize: 18, lineHeight: 1 }} aria-hidden>
            🎯
          </Typography>
          <Typography sx={{ fontSize: 13, fontWeight: 700, flex: 1 }}>
            {__('Free shipping', 'goalcart')}
          </Typography>
          <PreviewReward />
        </Stack>
        <PreviewBar tokens={tokens} percent={62} />
      </Stack>
    );
  }

  return (
    <Stack spacing={0.75} sx={{ width: '100%' }}>
      <PreviewBar tokens={tokens} percent={62} />
      <Typography sx={{ fontSize: 11, fontWeight: 500 }}>
        {__('Only $38.00 left to reach your goal', 'goalcart')}
      </Typography>
    </Stack>
  );
}

/** The full-size live preview driven by the current form values. */
function LivePreview({ form }: { form: Partial<GoalCartSettings> }) {
  const tokens = tokensOf(form);
  const template = TEMPLATES.find((t) => t.value === form.frontend_template) || TEMPLATES[0];

  return (
    <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 }, bgcolor: '#f6f7f7' }}>
      <Typography variant="subtitle2" gutterBottom color="text.secondary">
        {__('Live preview', 'goalcart')}
      </Typography>
      <Box
        sx={{
          maxWidth: 440,
          margin: '0 auto',
          background: tokens.bg,
          border: `1px solid ${tokens.border}`,
          borderRadius: tokens.radius,
          boxShadow: '0 6px 24px rgba(0,0,0,0.08)',
          color: tokens.text,
          padding: '1rem 1.125rem',
          fontSize: 14,
        }}
      >
        <Stack direction="row" justifyContent="flex-end" sx={{ mb: 0.75 }}>
          <PreviewReward />
        </Stack>

        {form.frontend_template === 'percentage' && (
          <Stack direction="row" alignItems="center" spacing={1.5}>
            <Typography sx={{ fontSize: 28, fontWeight: 800, color: tokens.accent, lineHeight: 1 }}>
              62%
            </Typography>
            <PreviewBar tokens={tokens} percent={62} />
          </Stack>
        )}

        {form.frontend_template === 'milestone' && (
          <Stack spacing={1} sx={{ mb: 1 }}>
            <Stack direction="row" alignItems="center" justifyContent="space-between">
              {[
                { target: '50$', done: true },
                { target: '100$', done: true },
                { target: '150$', done: false },
              ].map((step) => (
                <Stack key={step.target} direction="row" alignItems="center" spacing={0.5}>
                  <Box
                    sx={{
                      width: 12,
                      height: 12,
                      borderRadius: '50%',
                      background: step.done ? tokens.accent : tokens.border,
                    }}
                  />
                  <Typography sx={{ fontSize: 12, fontWeight: 500, color: step.done ? tokens.text : '#646970' }}>
                    {step.target}
                  </Typography>
                </Stack>
              ))}
            </Stack>
            <PreviewBar tokens={tokens} percent={62} />
          </Stack>
        )}

        {form.frontend_template === 'card' && (
          <Stack spacing={0.75} sx={{ mb: 1 }}>
            <Stack direction="row" alignItems="center" spacing={0.75}>
              <Typography sx={{ fontSize: 24, lineHeight: 1 }} aria-hidden>
                🎯
              </Typography>
              <Typography sx={{ fontWeight: 700, flex: 1 }}>
                {__('Free shipping', 'goalcart')}
              </Typography>
            </Stack>
            <PreviewBar tokens={tokens} percent={62} />
          </Stack>
        )}

        {form.frontend_template === 'basic' && <PreviewBar tokens={tokens} percent={62} />}

        <Typography sx={{ mt: 1, fontWeight: 500 }}>
          {__('Only $38.00 left to reach your goal', 'goalcart')}
        </Typography>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
          {template.label} — {template.description}
        </Typography>
      </Box>
    </Paper>
  );
}

/** A hex-color field with a native color picker in the adornment. */
function ColorField({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string;
  onChange: (next: string) => void;
}) {
  const safe = /^#[0-9a-fA-F]{6}$/.test(value) ? value : '#2271b1';

  return (
    <TextField
      label={label}
      value={value}
      size="small"
      onChange={(event) => onChange(event.target.value)}
      slotProps={{
        input: {
          startAdornment: (
            <InputAdornment position="start">
              <input
                type="color"
                value={safe}
                onChange={(event) => onChange(event.target.value)}
                aria-label={label}
                style={{
                  width: 28,
                  height: 28,
                  padding: 0,
                  border: 'none',
                  background: 'transparent',
                  cursor: 'pointer',
                }}
              />
            </InputAdornment>
          ),
        },
      }}
    />
  );
}

/**
 * Appearance (P12-T01 / P12-T02): storefront progress templates.
 *
 * Choose the template variant, tune the appearance tokens (colors,
 * radius, bar height, animation), add a custom class or custom CSS, and
 * watch a live preview — all persisted through `POST /goalcart/v1/settings`.
 * The storefront widgets (Phase 11) consume the same settings, so the
 * changes apply the moment they are saved.
 */
export default function Appearance() {
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();

  const settingsQuery = useQuery({
    queryKey: ['settings'],
    queryFn: fetchSettings,
  });

  const { control, handleSubmit, reset } = useForm<GoalCartSettings>({
    defaultValues: DEFAULT_SETTINGS,
    values: settingsQuery.data,
  });

  // Live form values for the preview (reacts to every keystroke/slider).
  const watched = useWatch<GoalCartSettings>({ control });

  const saveMutation = useMutation({
    mutationFn: (values: GoalCartSettings) => saveSettings(values),
    onSuccess: (saved) => {
      notify(__('Appearance saved.', 'goalcart'));
      void queryClient.setQueryData(['settings'], saved);
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
    },
  });

  if (settingsQuery.isLoading) {
    return (
      <PageContainer
        title={__('Appearance', 'goalcart')}
        description={__('Customize how the cart progress UI looks on your storefront.', 'goalcart')}
      >
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={140} />
          <Skeleton variant="rounded" height={200} />
        </Stack>
      </PageContainer>
    );
  }

  if (settingsQuery.isError) {
    return (
      <PageContainer
        title={__('Appearance', 'goalcart')}
        description={__('Customize how the cart progress UI looks on your storefront.', 'goalcart')}
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
      title={__('Appearance', 'goalcart')}
      description={__('Customize how the cart progress UI looks on your storefront.', 'goalcart')}
    >
      <form
        onSubmit={handleSubmit((values) => saveMutation.mutate(values as GoalCartSettings))}
      >
        <Stack spacing={3}>
          <Grid container spacing={3}>
            <Grid item xs={12} lg={7}>
              {/* Template picker */}
              <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }}>
                <Typography variant="h6" component="h3" gutterBottom>
                  {__('Template', 'goalcart')}
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                  {__(
                    'The progress widget layout shown to customers on the cart, checkout and product pages.',
                    'goalcart'
                  )}
                </Typography>
                <Grid container spacing={1.5}>
                  <Controller
                    name="frontend_template"
                    control={control}
                    render={({ field }) => (
                      <>
                        {TEMPLATES.map((template) => {
                          const selected = field.value === template.value;
                          return (
                            <Grid item xs={12} sm={6} key={template.value}>
                              <Card
                                variant={selected ? 'elevation' : 'outlined'}
                                sx={{
                                  height: '100%',
                                  border: selected ? '2px solid' : '1px solid',
                                  borderColor: selected ? 'primary.main' : 'divider',
                                  boxShadow: selected ? 3 : 0,
                                  transition: 'transform 0.15s ease, box-shadow 0.15s ease',
                                  '&:hover': { transform: 'translateY(-2px)', boxShadow: 2 },
                                }}
                              >
                                <CardActionArea
                                  onClick={() => field.onChange(template.value)}
                                  aria-pressed={selected}
                                  sx={{ p: 2, height: '100%', display: 'flex', flexDirection: 'column' }}
                                >
                                  <Box sx={{ width: '100%', flex: '1 1 auto', mb: 1.5 }}>
                                    <TemplateThumb template={template.value} tokens={tokensOf(watched)} />
                                  </Box>
                                  <Stack direction="row" alignItems="center" spacing={0.75} sx={{ width: '100%' }}>
                                    {selected && <CheckCircleIcon color="primary" fontSize="small" />}
                                    <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
                                      {template.label}
                                    </Typography>
                                  </Stack>
                                  <Typography variant="caption" color="text.secondary">
                                    {template.description}
                                  </Typography>
                                </CardActionArea>
                              </Card>
                            </Grid>
                          );
                        })}
                      </>
                    )}
                  />
                </Grid>
              </Paper>
            </Grid>

            <Grid item xs={12} lg={5}>
              <LivePreview form={watched} />
            </Grid>
          </Grid>

          {/* Colors */}
          <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }}>
            <Typography variant="h6" component="h3" gutterBottom>
              {__('Colors', 'goalcart')}
            </Typography>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} flexWrap="wrap">
              <Controller
                name="frontend_accent"
                control={control}
                render={({ field }) => <ColorField label={__('Accent', 'goalcart')} value={field.value} onChange={field.onChange} />}
              />
              <Controller
                name="frontend_bg"
                control={control}
                render={({ field }) => <ColorField label={__('Background', 'goalcart')} value={field.value} onChange={field.onChange} />}
              />
              <Controller
                name="frontend_border"
                control={control}
                render={({ field }) => <ColorField label={__('Border', 'goalcart')} value={field.value} onChange={field.onChange} />}
              />
              <Controller
                name="frontend_text"
                control={control}
                render={({ field }) => <ColorField label={__('Text', 'goalcart')} value={field.value} onChange={field.onChange} />}
              />
            </Stack>
          </Paper>

          {/* Bar & animation */}
          <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }}>
            <Typography variant="h6" component="h3" gutterBottom>
              {__('Bar & animation', 'goalcart')}
            </Typography>
            <Grid container spacing={3}>
              <Grid item xs={12} sm={6}>
                <Typography variant="body2" color="text.secondary" gutterBottom>
                  {__('Bar height (px)', 'goalcart')}
                </Typography>
                <Controller
                  name="frontend_bar_height"
                  control={control}
                  render={({ field }) => (
                    <Slider
                      value={field.value}
                      min={4}
                      max={48}
                      onChange={(_, next) => field.onChange(next)}
                      valueLabelDisplay="auto"
                    />
                  )}
                />
              </Grid>
              <Grid item xs={12} sm={6}>
                <Typography variant="body2" color="text.secondary" gutterBottom>
                  {__('Corner radius (px)', 'goalcart')}
                </Typography>
                <Controller
                  name="frontend_radius"
                  control={control}
                  render={({ field }) => (
                    <Slider
                      value={field.value}
                      min={0}
                      max={40}
                      onChange={(_, next) => field.onChange(next)}
                      valueLabelDisplay="auto"
                    />
                  )}
                />
              </Grid>
            </Grid>
            <Controller
              name="frontend_animation"
              control={control}
              render={({ field }) => (
                <FormControlLabel
                  control={<Switch checked={field.value} onChange={field.onChange} />}
                  label={
                    <Box>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {__('Animate progress updates', 'goalcart')}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {__('Smoothly slide the bar fill when the cart changes.', 'goalcart')}
                      </Typography>
                    </Box>
                  }
                />
              )}
            />
          </Paper>

          {/* Advanced */}
          <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }}>
            <Typography variant="h6" component="h3" gutterBottom>
              {__('Advanced', 'goalcart')}
            </Typography>
            <Stack spacing={2}>
              <Controller
                name="frontend_css_class"
                control={control}
                render={({ field }) => (
                  <TextField
                    {...field}
                    label={__('Extra CSS class', 'goalcart')}
                    helperText={__(
                      'Added to every storefront progress widget so your theme can target it.',
                      'goalcart'
                    )}
                    size="small"
                  />
                )}
              />
              <Controller
                name="frontend_custom_css"
                control={control}
                render={({ field }) => (
                  <TextField
                    {...field}
                    label={__('Custom CSS', 'goalcart')}
                    helperText={__(
                      'Appended to the storefront widget styles (overrides any token).',
                      'goalcart'
                    )}
                    multiline
                    minRows={5}
                    fullWidth
                    sx={{ fontFamily: 'monospace' }}
                    slotProps={{ input: { sx: { fontFamily: 'ui-monospace, monospace' } } }}
                  />
                )}
              />
            </Stack>
          </Paper>

          <Stack direction="row" spacing={1.5}>
            <Button
              type="submit"
              variant="contained"
              disabled={saveMutation.isPending}
              startIcon={<PaletteIcon />}
              sx={{ minWidth: 160 }}
            >
              {saveMutation.isPending ? __('Saving…', 'goalcart') : __('Save appearance', 'goalcart')}
            </Button>
            <Button
              variant="outlined"
              color="inherit"
              startIcon={<RestartAltIcon />}
              disabled={saveMutation.isPending}
              onClick={() => {
                // Reset only the appearance surface — never clobber the
                // plugin-level toggles the merchant may have changed on the
                // Settings page (e.g. disabling Goal Cart entirely).
                const resetValues: GoalCartSettings = {
                  ...DEFAULT_SETTINGS,
                  enabled: watched.enabled ?? DEFAULT_SETTINGS.enabled,
                  fullscreen_dashboard: watched.fullscreen_dashboard ?? DEFAULT_SETTINGS.fullscreen_dashboard,
                };
                reset(resetValues);
                saveMutation.mutate(resetValues);
              }}
            >
              {__('Reset to defaults', 'goalcart')}
            </Button>
          </Stack>
        </Stack>
      </form>
    </PageContainer>
  );
}
