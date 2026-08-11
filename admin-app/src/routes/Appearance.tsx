import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PaletteIcon from '@mui/icons-material/Palette';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import RocketLaunchIcon from '@mui/icons-material/RocketLaunch';
import StorefrontIcon from '@mui/icons-material/Storefront';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import FormControl from '@mui/material/FormControl';
import InputLabel from '@mui/material/InputLabel';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Select from '@mui/material/Select';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from 'react';

import { getBootData } from '../boot';
import { fetchSettingsEnvelope, saveSettings } from '../api/settings';
import PageContainer from '../components/PageContainer';
import PreviewWidget from '../components/preview/PreviewWidget';
import { tokensFromSettings } from '../components/preview/types';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { useStickyBarActions } from '../providers/ActionBarProvider';
import SchemaForm from '../templates/SchemaForm';
import { templateById, useTemplates } from '../templates/useTemplates';
import { bool } from '../templates/utils';
import type {
  ProgressCampaign,
  ProgressGoal,
  TemplateDefinition,
  TemplateScope,
  TemplateSettingsValue,
} from '../types';

const SCOPES: TemplateScope[] = ['goal', 'campaign'];

/** A sample in-progress goal for the live previews. */
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

/** Three sample milestones for the campaign previews. */
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

/**
 * The live preview of one template with its current draft appearance —
 * rendered through PreviewWidget (the same component the Phase 15 preview
 * dialogs use), so what the merchant sees here matches the storefront.
 */
function ScopeLivePreview({
  scope,
  id,
  drafts,
  templates,
  tokens,
  currency,
}: {
  scope: TemplateScope;
  id: string;
  drafts: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
  templates: TemplateDefinition[];
  tokens: ReturnType<typeof tokensFromSettings>;
  currency: string;
}) {
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

  // The sample milestones must carry the campaign's id so PreviewWidget's
  // grouping (goal.campaign_id → campaign) actually joins them into the
  // campaign and renders it through the selected campaign template —
  // otherwise they fall through as standalone goal cards and the campaign
  // preview always shows the wrong (basic) rendering.
  return (
    <PreviewWidget
      goals={sampleMilestones().map((goal) => ({
        ...goal,
        campaign_id: campaign.campaign_id,
      }))}
      campaigns={[campaign]}
      currency={currency}
      tokens={tokens}
      rewardState="locked"
      animation={animation}
    />
  );
}

/**
 * The single active template panel: the schema-driven appearance form (the
 * same SchemaForm the Goal and Campaign builders use) plus a "reset to
 * template defaults" action that restores the factory schema defaults.
 * Only ever mounted for the template currently selected in the dropdown.
 */
function TemplateSettingsPanel({
  scope,
  definition,
  drafts,
  onChange,
  onReset,
}: {
  scope: TemplateScope;
  definition: TemplateDefinition;
  drafts: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
  onChange: (id: string, next: TemplateSettingsValue) => void;
  onReset: (id: string) => void;
}) {
  return (
    <Paper variant="outlined" sx={{ p: { xs: 2, md: 2.5 } }}>
      <Stack spacing={2}>
        <Box>
          <Typography variant="overline" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>
            {__('Template appearance', 'goalcart')}
          </Typography>
          <Stack
            direction="row"
            sx={{
              alignItems: 'baseline',
              justifyContent: 'space-between',
              gap: 1,
              flexWrap: 'wrap',
            }}
          >
            <Box>
              <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
                {definition.label}
              </Typography>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                {definition.description}
              </Typography>
            </Box>
            <Typography variant="caption" color="text.secondary">
              {`v${definition.version}`}
            </Typography>
          </Stack>
        </Box>
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
    </Paper>
  );
}

/**
 * Appearance (pluggable template engine): the storefront progress UI is
 * template-driven, independently for Goals and Campaigns.
 *
 *  - Tabs switch between the Goal and Campaign scopes (only one is visible
 *    at a time),
 *  - a dropdown lists every registered template for the active scope,
 *    defaulting to that scope's current default template,
 *  - selecting a template shows only that template's live preview + the
 *    schema-driven appearance form — mounted lazily, so no inactive
 *    template's form ever sits in the DOM,
 *  - the save action persists the scope default + every edited template's
 *    appearance through `POST /goalcart/v1/settings` as `template_defaults`
 *    + `template_settings` (identical semantics to the previous layout).
 *
 * The legacy `frontend_*` surface stays honored by the engine as the
 * fallback for templates that were never configured.
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

  // Active tab: 0 = Goal, 1 = Campaign.
  const [tab, setTab] = useState(0);
  const scope = SCOPES[tab];

  // Working copy: scope defaults + per-template default appearance.
  // Seeded once from the registry payload, whose `settings` field already
  // carries the effective defaults (stored appearance merged over the
  // schema defaults and legacy tokens), so no draft is ever empty.
  const [defaults, setDefaults] = useState<Record<TemplateScope, string>>({
    goal: 'basic',
    campaign: '',
  });
  const [drafts, setDrafts] = useState<
    Record<TemplateScope, Record<string, TemplateSettingsValue>>
  >({
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

    for (const scopeItem of SCOPES) {
      for (const definition of templates[scopeItem]) {
        next[scopeItem][definition.id] = definition.settings;
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

    for (const scopeItem of SCOPES) {
      for (const definition of templates[scopeItem]) {
        const draft = drafts[scopeItem][definition.id];

        if (draft && JSON.stringify(draft) !== JSON.stringify(definition.settings)) {
          merged[scopeItem][definition.id] = draft;
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

    for (const scopeItem of SCOPES) {
      for (const definition of templates[scopeItem]) {
        next[scopeItem][definition.id] = definition.settings;
      }
    }

    setDrafts(next);
  };

  const resetTemplate = (id: string) => {
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

  // Sticky bottom bar: Save appearance + Discard changes (moved out of
  // the page body into the dashboard's bottom action bar). Hidden until
  // the template registry has loaded. The handlers read the drafts /
  // defaults / stored settings, so those are deps too — re-registering
  // on every edit keeps the bar's Save from ever persisting stale drafts.
  useStickyBarActions(
    [saveMutation.isPending, Boolean(templates), templates, settings, drafts, defaults],
    () =>
      templates ? (
        <>
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
        </>
      ) : null
  );

  if (templatesQuery.isLoading) {
    return (
      <PageContainer
        title={__('Appearance', 'goalcart')}
        description={__('Customize how the cart progress UI looks on your storefront.', 'goalcart')}
      >
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={52} />
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

  const scopeTemplates = templates[scope];
  const isCampaign = scope === 'campaign';

  // The currently configured template for the active scope ('' = no
  // campaign template). The dropdown is empty-able only for campaigns.
  const selectedId = defaults[scope];
  const definition = templateById(templates, scope, selectedId);

  return (
    <PageContainer
      title={__('Appearance', 'goalcart')}
      description={__(
        'Pick the default progress template and tune its appearance — separately for Goals and Campaigns.',
        'goalcart'
      )}
    >
      <Stack spacing={3}>
        <Tabs
          value={tab}
          onChange={(_event, next) => setTab(next)}
          variant="fullWidth"
          sx={{ borderBottom: 1, borderColor: 'divider' }}
          aria-label={__('Template scope', 'goalcart')}
        >
          <Tab
            id="appearance-tab-goal"
            aria-controls="appearance-panel-goal"
            icon={<RocketLaunchIcon />}
            iconPosition="start"
            label={__('Goal', 'goalcart')}
          />
          <Tab
            id="appearance-tab-campaign"
            aria-controls="appearance-panel-campaign"
            icon={<StorefrontIcon />}
            iconPosition="start"
            label={__('Campaign', 'goalcart')}
          />
        </Tabs>

        <Paper
          variant="outlined"
          role="tabpanel"
          id={`appearance-panel-${scope}`}
          aria-labelledby={`appearance-tab-${scope}`}
          sx={{ p: { xs: 2.5, md: 3 } }}
        >
          <Stack spacing={2.5}>
            <Box>
              <Typography variant="h6" component="h3" gutterBottom>
                {isCampaign ? __('Campaign template', 'goalcart') : __('Goal template', 'goalcart')}
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

            {/* Template dropdown — list the active scope's registered templates. */}
            <FormControl size="small" fullWidth>
              <InputLabel id="appearance-template-label">
                {isCampaign ? __('Campaign template', 'goalcart') : __('Goal template', 'goalcart')}
              </InputLabel>
              <Select
                labelId="appearance-template-label"
                label={
                  isCampaign ? __('Campaign template', 'goalcart') : __('Goal template', 'goalcart')
                }
                value={selectedId}
                onChange={(event) =>
                  setDefaults((prev) => ({ ...prev, [scope]: String(event.target.value) }))
                }
              >
                {isCampaign && (
                  <MenuItem value="">
                    <em>{__('No campaign template', 'goalcart')}</em>
                  </MenuItem>
                )}
                {scopeTemplates.map((template) => (
                  <MenuItem key={template.id} value={template.id}>
                    {template.label}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>

            {scopeTemplates.length === 0 && (
              <Alert severity="info" variant="outlined">
                {__(
                  'No templates are registered for this scope yet. Add one on the backend template registry.',
                  'goalcart'
                )}
              </Alert>
            )}

            {!definition && !isCampaign && scopeTemplates.length > 0 && (
              <Alert severity="warning" variant="outlined">
                {__(
                  'The stored default template is no longer registered. The storefront falls back to the Basic template until you pick another one here.',
                  'goalcart'
                )}
              </Alert>
            )}

            {/* Live preview — always shown when a template is selected, or
                for the campaign scope (where '' = no template is a valid choice). */}
            {(definition || isCampaign) && (
              <Box>
                <Typography
                  variant="overline"
                  color="text.secondary"
                  sx={{ display: 'block', mb: 1 }}
                >
                  {__('Live preview', 'goalcart')}
                </Typography>
                <Box sx={{ maxWidth: 440 }}>
                  <ScopeLivePreview
                    scope={scope}
                    id={selectedId}
                    drafts={drafts}
                    templates={scopeTemplates}
                    tokens={tokens}
                    currency={currency}
                  />
                </Box>
              </Box>
            )}

            {definition && (
              <TemplateSettingsPanel
                scope={scope}
                definition={definition}
                drafts={drafts}
                onChange={(id, next) =>
                  setDrafts((prev) => ({ ...prev, [scope]: { ...prev[scope], [id]: next } }))
                }
                onReset={resetTemplate}
              />
            )}
          </Stack>
        </Paper>
      </Stack>
    </PageContainer>
  );
}
