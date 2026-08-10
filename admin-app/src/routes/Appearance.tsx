import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardActionArea from '@mui/material/CardActionArea';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import Chip from '@mui/material/Chip';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import Grid from '@mui/material/Grid';
import PaletteIcon from '@mui/icons-material/Palette';
import Paper from '@mui/material/Paper';
import RadioButtonUncheckedIcon from '@mui/icons-material/RadioButtonUnchecked';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from 'react';

import { getBootData } from '../boot';
import { fetchSettingsEnvelope, saveSettings } from '../api/settings';
import PageContainer from '../components/PageContainer';
import PreviewWidget from '../components/preview/PreviewWidget';
import { tokensFromSettings } from '../components/preview/types';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import SchemaForm from '../templates/SchemaForm';
import { campaignRenderer, goalRenderer } from '../templates/registry';
import { templateById, useTemplates } from '../templates/useTemplates';
import { bool, num, str } from '../templates/utils';
import type {
  ProgressCampaign,
  ProgressGoal,
  TemplateDefinition,
  TemplateScope,
  TemplateSettingsValue,
} from '../types';

const SCOPES: TemplateScope[] = ['goal', 'campaign'];

/**
 * The card surface shared by every preview thumbnail and live preview —
 * mirrors the storefront `.goalcart` container driven by the resolved
 * template settings (accent/bg/border/text/radius).
 */
function cardSurface(settings: TemplateSettingsValue) {
  return {
    background: str(settings, 'bg', '#ffffff'),
    border: `1px solid ${str(settings, 'border', '#dcdcde')}`,
    borderRadius: num(settings, 'radius', 10),
    color: str(settings, 'text', '#1d2327'),
    padding: '0.875rem 1rem',
    fontSize: 13,
  };
}

/** A sample in-progress goal for the thumbnails and live previews. */
function sampleGoal(overrides: Partial<ProgressGoal> = {}): ProgressGoal {
  return {
    goal_id: 1,
    campaign_id: 0,
    goal_name: __('Free shipping', 'goalcart'),
    goal_type: 'amount',
    is_money: true,
    icon: '🎯',
    template: 'basic',
    template_settings: {},
    current: 93,
    target: 150,
    remaining: 57,
    percentage: 62,
    completed: false,
    state: 'progressing',
    message: __('Only $57.00 left to reach your goal', 'goalcart'),
    reward: { type: 'free_shipping', value: null, max_value: null, meta: {} },
    suggestions: [],
    reward_state: 'locked',
    eligible: true,
    reason: '',
    conflict: { resolved: true, reason: '' },
    ...overrides,
  };
}

/** Three sample milestones for the campaign thumbnails / previews. */
function sampleMilestones(): ProgressGoal[] {
  return [
    sampleGoal({
      goal_id: 1,
      goal_name: __('Free shipping', 'goalcart'),
      target: 100,
      current: 93,
      remaining: 7,
      percentage: 93,
      reward: { type: 'free_shipping', value: null, max_value: null, meta: {} },
    }),
    sampleGoal({
      goal_id: 2,
      goal_name: __('Free gift', 'goalcart'),
      target: 200,
      current: 93,
      remaining: 107,
      percentage: 46,
      reward: { type: 'free_gift', value: null, max_value: null, meta: {} },
    }),
    sampleGoal({
      goal_id: 3,
      goal_name: __('10% off', 'goalcart'),
      target: 300,
      current: 93,
      remaining: 207,
      percentage: 31,
      reward: { type: 'percent_discount', value: 10, max_value: null, meta: {} },
    }),
  ];
}

/** A goal-template thumbnail rendered by its real registry renderer. */
function GoalThumb({
  definition,
  settings,
  currency,
}: {
  definition: TemplateDefinition;
  settings: TemplateSettingsValue;
  currency: string;
}) {
  const Renderer = goalRenderer(definition.id);

  return (
    <Box sx={cardSurface(settings)}>
      <Renderer
        goal={sampleGoal({ template: definition.id })}
        currency={currency}
        settings={settings}
        animation={false}
      />
    </Box>
  );
}

/** A campaign-template thumbnail rendered by its real registry renderer. */
function CampaignThumb({
  definition,
  settings,
  currency,
}: {
  definition: TemplateDefinition;
  settings: TemplateSettingsValue;
  currency: string;
}) {
  const Renderer = campaignRenderer(definition.id);

  if (!Renderer) {
    return null;
  }

  const campaign: ProgressCampaign = {
    campaign_id: 999,
    name: __('Campaign', 'goalcart'),
    template: definition.id,
    settings,
  };

  return (
    <Box sx={cardSurface(settings)}>
      <Renderer
        campaign={campaign}
        goals={sampleMilestones().map((goal) => ({ ...goal, campaign_id: 999 }))}
        currency={currency}
        settings={settings}
        animation={false}
      />
    </Box>
  );
}

/** The "no campaign template" card thumbnail — plain per-goal cards. */
function NoCampaignThumb() {
  return (
    <Stack spacing={0.75} sx={{ width: '100%' }}>
      {[62, 40, 28].map((width) => (
        <Box
          key={width}
          sx={{
            height: 8,
            borderRadius: 999,
            background: '#dcdcde',
            width: `${width}%`,
            opacity: 0.8,
          }}
        />
      ))}
    </Stack>
  );
}

/**
 * The scope default picker — one card per registered template (thumbnails
 * rendered through the template registry), plus the "no campaign
 * template" option for the campaign scope.
 */
function ScopeTemplatePicker({
  scope,
  templates,
  defaults,
  drafts,
  currency,
  onSelect,
}: {
  scope: TemplateScope;
  templates: TemplateDefinition[];
  defaults: Record<TemplateScope, string>;
  drafts: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
  currency: string;
  onSelect: (id: string) => void;
}) {
  const selected = defaults[scope];

  const cardStyles = (isSelected: boolean) => ({
    height: '100%',
    border: isSelected ? '2px solid' : '1px solid',
    borderColor: isSelected ? 'primary.main' : 'divider',
    boxShadow: isSelected ? 3 : 0,
    transition: 'transform 0.15s ease, box-shadow 0.15s ease',
    '&:hover': { transform: 'translateY(-2px)', boxShadow: 2 },
  });

  const renderSelect = (id: string, thumb: React.ReactNode, label: string, caption: string) => {
    const isSelected = selected === id;

    return (
      <Grid item xs={12} sm={6} md={4} key={id}>
        <Card variant={isSelected ? 'elevation' : 'outlined'} sx={cardStyles(isSelected)}>
          <CardActionArea
            onClick={() => onSelect(id)}
            aria-pressed={isSelected}
            sx={{ p: 2, height: '100%', display: 'flex', flexDirection: 'column' }}
          >
            <Box sx={{ width: '100%', flex: '1 1 auto', mb: 1.5 }}>{thumb}</Box>
            <Stack direction="row" alignItems="center" spacing={0.75} sx={{ width: '100%' }}>
              {isSelected ? (
                <CheckCircleIcon color="primary" fontSize="small" />
              ) : (
                <RadioButtonUncheckedIcon fontSize="small" color="disabled" />
              )}
              <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
                {label}
              </Typography>
            </Stack>
            <Typography variant="caption" color="text.secondary">
              {caption}
            </Typography>
          </CardActionArea>
        </Card>
      </Grid>
    );
  };

  return (
    <Grid container spacing={1.5}>
      {scope === 'campaign' &&
        renderSelect(
          '',
          <NoCampaignThumb />,
          __('No campaign template', 'goalcart'),
          __('Each milestone renders as its own goal card.', 'goalcart')
        )}
      {templates.map((definition) => {
        const settings = drafts[scope][definition.id] ?? definition.settings;

        return renderSelect(
          definition.id,
          scope === 'goal' ? (
            <GoalThumb definition={definition} settings={settings} currency={currency} />
          ) : (
            <CampaignThumb definition={definition} settings={settings} currency={currency} />
          ),
          definition.label,
          definition.description
        );
      })}
    </Grid>
  );
}

/**
 * The per-template appearance editors — one accordion per registered
 * template with the schema-driven form (the same SchemaForm the Goal and
 * Campaign builders use), plus a "reset to template defaults" action that
 * restores the factory schema defaults.
 */
function TemplateSettingsAccordions({
  scope,
  templates,
  defaults,
  drafts,
  onChange,
  onReset,
}: {
  scope: TemplateScope;
  templates: TemplateDefinition[];
  defaults: Record<TemplateScope, string>;
  drafts: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
  onChange: (id: string, next: TemplateSettingsValue) => void;
  onReset: (id: string) => void;
}) {
  const [expanded, setExpanded] = useState<string | null>(null);

  return (
    <Stack spacing={1}>
      {templates.map((definition) => {
        const isDefault = defaults[scope] === definition.id;

        return (
          <Accordion
            key={definition.id}
            expanded={expanded === definition.id}
            onChange={(_event, isOpen) => setExpanded(isOpen ? definition.id : null)}
            variant="outlined"
            disableGutters
            sx={{ '&:before': { display: 'none' } }}
          >
            <AccordionSummary
              expandIcon={<ExpandMoreIcon />}
              sx={{ '& .MuiAccordionSummary-content': { alignItems: 'center', gap: 1 } }}
            >
              <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
                {definition.label}
              </Typography>
              {isDefault && (
                <Chip size="small" color="primary" variant="outlined" label={__('Default', 'goalcart')} />
              )}
              <Box sx={{ flex: 1 }} />
              <Typography variant="caption" color="text.secondary">
                {`v${definition.version}`}
              </Typography>
            </AccordionSummary>
            <AccordionDetails>
              <Stack spacing={2}>
                <SchemaForm
                  schema={definition.schema}
                  value={drafts[scope][definition.id] ?? definition.settings}
                  onChange={(next) => onChange(definition.id, next)}
                />
                <Box>
                  <Button
                    size="small"
                    startIcon={<RestartAltIcon />}
                    onClick={() => onReset(definition.id)}
                  >
                    {__('Reset to template defaults', 'goalcart')}
                  </Button>
                </Box>
              </Stack>
            </AccordionDetails>
          </Accordion>
        );
      })}
    </Stack>
  );
}

/**
 * The live preview of the scope's default template with its current draft
 * appearance — rendered through PreviewWidget (the same component the
 * Phase 15 preview dialogs use), so what the merchant sees here matches
 * the storefront.
 */
function ScopeLivePreview({
  scope,
  defaults,
  drafts,
  templates,
  tokens,
  currency,
}: {
  scope: TemplateScope;
  defaults: Record<TemplateScope, string>;
  drafts: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
  templates: TemplateDefinition[];
  tokens: ReturnType<typeof tokensFromSettings>;
  currency: string;
}) {
  const id = defaults[scope];

  if (scope === 'campaign' && id === '') {
    return (
      <Alert severity="info" variant="outlined">
        {__(
          'No campaign template selected — each milestone renders as its own goal card on the storefront.',
          'goalcart'
        )}
      </Alert>
    );
  }

  const definition = templates.find((template) => template.id === id);

  if (!definition) {
    return null;
  }

  const settings = drafts[scope][id] ?? definition.settings;
  const animation = bool(settings, 'animation', true);

  if (scope === 'goal') {
    return (
      <PreviewWidget
        goals={[sampleGoal()]}
        currency={currency}
        tokens={tokens}
        templateOverride={id}
        settingsOverride={settings}
        rewardState="locked"
        animation={animation}
      />
    );
  }

  const campaign: ProgressCampaign = {
    campaign_id: 999,
    name: __('Sample campaign', 'goalcart'),
    template: id,
    settings,
  };

  return (
    <PreviewWidget
      goals={sampleMilestones().map((goal) => ({ ...goal, campaign_id: 999 }))}
      campaigns={[campaign]}
      currency={currency}
      tokens={tokens}
      rewardState="locked"
      animation={animation}
    />
  );
}

/**
 * Appearance (pluggable template engine): the storefront progress UI is
 * now template-driven, independently for Goals and Campaigns.
 *
 *  - pick the scope default template (goals always render one; campaigns
 *    may render per-goal cards instead),
 *  - edit every registered template's default appearance through the
 *    generic schema-driven form — a new template registered on the
 *    backend automatically gets a working form here,
 *  - watch a live preview that resolves templates identically to the
 *    storefront (item override → scope default → legacy → fallback).
 *
 * Persisted through `POST /goalcart/v1/settings` as `template_defaults`
 * + `template_settings`; the legacy `frontend_*` surface stays honored by
 * the engine as the fallback for templates that were never configured.
 */
export default function Appearance() {
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();
  const templatesQuery = useTemplates();
  const settingsQuery = useQuery({ queryKey: ['settings'], queryFn: fetchSettingsEnvelope });

  const templates = templatesQuery.data;
  const settings = settingsQuery.data?.data;
  const tokens = tokensFromSettings(settings);
  const currency = useMemo(() => {
    try {
      return getBootData().currency;
    } catch {
      return 'USD';
    }
  }, []);

  // Working copy: scope defaults + per-template default appearance.
  // Seeded once from the registry payload, whose `settings` field already
  // carries the effective defaults (stored appearance merged over the
  // schema defaults and legacy tokens), so no draft is ever empty.
  const [defaults, setDefaults] = useState<Record<TemplateScope, string>>({
    goal: 'basic',
    campaign: '',
  });
  const [drafts, setDrafts] = useState<Record<TemplateScope, Record<string, TemplateSettingsValue>>>({
    goal: {},
    campaign: {},
  });
  const seeded = useRef(false);

  useEffect(() => {
    if (!templates || seeded.current) {
      return;
    }

    seeded.current = true;
    setDefaults({
      goal: templates.defaults.goal || 'basic',
      campaign: templates.defaults.campaign || '',
    });

    const next: Record<TemplateScope, Record<string, TemplateSettingsValue>> = {
      goal: {},
      campaign: {},
    };

    for (const scope of SCOPES) {
      for (const definition of templates[scope]) {
        next[scope][definition.id] = definition.settings;
      }
    }

    setDrafts(next);
  }, [templates]);

  const saveMutation = useMutation({
    mutationFn: (values: {
      template_defaults: Record<TemplateScope, string>;
      template_settings?: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
    }) => saveSettings(values),
    onSuccess: (saved) => {
      notify(__('Appearance saved.', 'goalcart'));

      // Keep the envelope shape in the shared cache so the Settings page
      // (and the preview dialogs) still find `data` after this save, then
      // refresh the registry payload (its `settings`/`defaults` now carry
      // the persisted values).
      const meta = settingsQuery.data?.meta ?? {};
      void queryClient.setQueryData(['settings'], { data: saved, meta });
      void settingsQuery.refetch();
      void queryClient.invalidateQueries({ queryKey: ['templates'] });
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
    },
  });

  const handleSave = () => {
    if (!templates) {
      return;
    }

    // Persist a template's appearance only when its draft diverges from
    // the effective default the backend served. Unchanged templates keep
    // their stored settings (merged from the server state) — and
    // templates that were never configured stay unconfigured, so the
    // legacy `frontend_*` fallback keeps applying instead of being
    // silently frozen by an unrelated save.
    const stored = settings?.template_settings;
    const merged: Record<TemplateScope, Record<string, TemplateSettingsValue>> = {
      goal: { ...(stored?.goal ?? {}) },
      campaign: { ...(stored?.campaign ?? {}) },
    };
    let changed = false;

    for (const scope of SCOPES) {
      for (const definition of templates[scope]) {
        const draft = drafts[scope][definition.id];

        if (draft && JSON.stringify(draft) !== JSON.stringify(definition.settings)) {
          merged[scope][definition.id] = draft;
          changed = true;
        }
      }
    }

    const payload: {
      template_defaults: Record<TemplateScope, string>;
      template_settings?: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
    } = { template_defaults: defaults };

    if (changed) {
      payload.template_settings = merged;
    }

    saveMutation.mutate(payload);
  };

  const discardChanges = () => {
    if (!templates) {
      return;
    }

    setDefaults({
      goal: templates.defaults.goal || 'basic',
      campaign: templates.defaults.campaign || '',
    });

    const next: Record<TemplateScope, Record<string, TemplateSettingsValue>> = {
      goal: {},
      campaign: {},
    };

    for (const scope of SCOPES) {
      for (const definition of templates[scope]) {
        next[scope][definition.id] = definition.settings;
      }
    }

    setDrafts(next);
  };

  const resetTemplate = (scope: TemplateScope, id: string) => {
    const definition = templateById(templates, scope, id);

    if (!definition) {
      return;
    }

    const factory: TemplateSettingsValue = {};

    for (const field of definition.schema) {
      factory[field.key] = field.default;
    }

    setDrafts((prev) => ({ ...prev, [scope]: { ...prev[scope], [id]: factory } }));
  };

  if (templatesQuery.isLoading) {
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

  if (templatesQuery.isError || !templates) {
    return (
      <PageContainer
        title={__('Appearance', 'goalcart')}
        description={__('Customize how the cart progress UI looks on your storefront.', 'goalcart')}
      >
        <Alert severity="error" variant="outlined">
          {templatesQuery.error instanceof Error
            ? templatesQuery.error.message
            : __('Could not load the progress templates.', 'goalcart')}
        </Alert>
      </PageContainer>
    );
  }

  return (
    <PageContainer
      title={__('Appearance', 'goalcart')}
      description={__(
        'Pick the default progress template and tune its appearance — separately for Goals and Campaigns.',
        'goalcart'
      )}
    >
      <Stack spacing={3}>
        {SCOPES.map((scope) => {
          const scopeTemplates = templates[scope];
          const isCampaign = scope === 'campaign';

          return (
            <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }} key={scope}>
              <Stack spacing={2.5}>
                <Box>
                  <Typography variant="h6" component="h3" gutterBottom>
                    {isCampaign ? __('Campaigns', 'goalcart') : __('Goals', 'goalcart')}
                  </Typography>
                  <Typography variant="body2" color="text.secondary">
                    {isCampaign
                      ? __(
                          'The default template that renders a whole campaign on the storefront (e.g. the milestone chain).',
                          'goalcart'
                        )
                      : __(
                          'The default template for every goal that does not pin its own on the Goal Builder.',
                          'goalcart'
                        )}
                  </Typography>
                </Box>

                <ScopeTemplatePicker
                  scope={scope}
                  templates={scopeTemplates}
                  defaults={defaults}
                  drafts={drafts}
                  currency={currency}
                  onSelect={(id) => setDefaults((prev) => ({ ...prev, [scope]: id }))}
                />

                {/* Live preview of the scope default + its draft appearance. */}
                <Box>
                  <Typography variant="overline" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
                    {__('Live preview', 'goalcart')}
                  </Typography>
                  <Box sx={{ maxWidth: 440 }}>
                    <ScopeLivePreview
                      scope={scope}
                      defaults={defaults}
                      drafts={drafts}
                      templates={scopeTemplates}
                      tokens={tokens}
                      currency={currency}
                    />
                  </Box>
                </Box>

                {/* Per-template default appearance (schema-driven). */}
                <Box>
                  <Typography variant="overline" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
                    {__('Template appearances', 'goalcart')}
                  </Typography>
                  <TemplateSettingsAccordions
                    scope={scope}
                    templates={scopeTemplates}
                    defaults={defaults}
                    drafts={drafts}
                    onChange={(id, next) =>
                      setDrafts((prev) => ({ ...prev, [scope]: { ...prev[scope], [id]: next } }))
                    }
                    onReset={(id) => resetTemplate(scope, id)}
                  />
                </Box>
              </Stack>
            </Paper>
          );
        })}

        <Stack direction="row" spacing={1.5}>
          <Button
            variant="contained"
            startIcon={<PaletteIcon />}
            disabled={saveMutation.isPending}
            onClick={handleSave}
            sx={{ minWidth: 160 }}
          >
            {saveMutation.isPending ? __('Saving…', 'goalcart') : __('Save appearance', 'goalcart')}
          </Button>
          <Button
            variant="outlined"
            color="inherit"
            startIcon={<RestartAltIcon />}
            disabled={saveMutation.isPending}
            onClick={discardChanges}
          >
            {__('Discard changes', 'goalcart')}
          </Button>
        </Stack>
      </Stack>
    </PageContainer>
  );
}
