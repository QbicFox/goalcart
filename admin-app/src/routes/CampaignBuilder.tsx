import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import CloseIcon from '@mui/icons-material/Close';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';

import Grid from '@mui/material/Grid';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { createCampaign, fetchCampaign, updateCampaign } from '../api/campaigns';
import { fetchGoals } from '../api/goals';
import SectionCard from '../components/goal-builder/SectionCard';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import PageContainer from '../components/PageContainer';
import { formatCurrency, formatNumber } from '../lib/format';
import SchemaForm from '../templates/SchemaForm';
import { useTemplates } from '../templates/useTemplates';
import type { Campaign, CampaignInput, Goal } from '../types';

const REWARD_LABELS: Record<string, string> = {
  free_shipping: __('Free shipping', 'goalcart'),
  percent_discount: __('% discount', 'goalcart'),
  fixed_discount: __('Fixed discount', 'goalcart'),
  free_gift: __('Free gift', 'goalcart'),
  coupon: __('Coupon', 'goalcart'),
};

const WEEKDAYS = [
  { value: 1, label: __('Monday', 'goalcart') },
  { value: 2, label: __('Tuesday', 'goalcart') },
  { value: 3, label: __('Wednesday', 'goalcart') },
  { value: 4, label: __('Thursday', 'goalcart') },
  { value: 5, label: __('Friday', 'goalcart') },
  { value: 6, label: __('Saturday', 'goalcart') },
  { value: 7, label: __('Sunday', 'goalcart') },
];

/** Fresh-campaign defaults (mirror the backend save-arg defaults). */
function emptyCampaign(): CampaignInput {
  return {
    name: '',
    description: '',
    status: 'active',
    starts_at: null,
    ends_at: null,
    priority: 10,
    display_rules: {},
    goals: [],
  };
}

/** Map a REST campaign onto the builder form model. */
function campaignToInput(campaign: Campaign): CampaignInput {
  return {
    name: campaign.name,
    description: campaign.description ?? '',
    status: campaign.status,
    starts_at: campaign.starts_at,
    ends_at: campaign.ends_at,
    priority: campaign.priority,
    display_rules: campaign.display_rules ?? {},
    goals: (campaign.goals ?? [])
      .slice()
      .sort((a, b) => a.menu_order - b.menu_order)
      .map((goal) => goal.id),
  };
}

function targetLabel(goal: Goal): string {
  const countTypes = ['quantity', 'distinct_quantity', 'weight'];

  if (countTypes.includes(goal.type) || goal.calculation_mode === 'quantity') {
    return formatNumber(goal.target);
  }

  return formatCurrency(goal.target);
}

/**
 * Campaign Builder (Phase 10). A form for creating and editing campaigns
 * — Basic information, Schedule, Priority and Milestones (goal ordering)
 * — wired to the Phase 10 REST CRUD endpoints. New campaigns use
 * `/campaigns/new`, existing ones `/campaigns/:id/edit`.
 */
export default function CampaignBuilder() {
  const { id } = useParams();
  const editId = id ? Number(id) : null;
  const navigate = useNavigate();
  const { notify } = useSnackbar();
  const queryClient = useQueryClient();
  const { data: templates } = useTemplates();

  const [values, setValues] = useState<CampaignInput>(emptyCampaign);

  const displayRules = values.display_rules ?? {};
  const scheduleDays = Array.isArray(displayRules.schedule_days)
    ? (displayRules.schedule_days as number[])
    : [];
  const scheduleStart =
    typeof displayRules.schedule_start_time === 'string' ? displayRules.schedule_start_time : '';
  const scheduleEnd =
    typeof displayRules.schedule_end_time === 'string' ? displayRules.schedule_end_time : '';

  /** Patch display_rules. */
  const patchDisplay = (rules: Record<string, unknown>) =>
    patch({ display_rules: { ...displayRules, ...rules } });

  /** Toggle one weekday in the recurring set (empty clears the restriction). */
  const toggleScheduleDay = (day: number) =>
    patchDisplay({
      schedule_days: scheduleDays.includes(day)
        ? scheduleDays.filter((d) => d !== day)
        : [...scheduleDays, day],
    });

  const campaignQuery = useQuery({
    queryKey: ['campaign', editId],
    queryFn: () => fetchCampaign(editId as number),
    enabled: editId !== null,
  });

  // All goals, so milestones can be picked and ordered.
  const goalsQuery = useQuery({
    queryKey: ['goals', 'all'],
    queryFn: () => fetchGoals({ per_page: 100 }),
  });

  // Seed the form once the campaign loads. This is a guarded state
  // adjustment during render (tracking the already-seeded id) rather
  // than an effect, per react-hooks/set-state-in-effect.
  const campaign = campaignQuery.data;
  const [loadedCampaignId, setLoadedCampaignId] = useState<number | null>(null);

  if (campaign && campaign.id !== loadedCampaignId) {
    setLoadedCampaignId(campaign.id);
    setValues(campaignToInput(campaign));
  }

  const saveMutation = useMutation({
    mutationFn: (input: CampaignInput) =>
      editId ? updateCampaign(editId, input) : createCampaign(input),
    onSuccess: () => {
      notify(
        editId
          ? __('The campaign was updated.', 'goalcart')
          : __('The campaign was created.', 'goalcart')
      );
      void queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      void queryClient.invalidateQueries({ queryKey: ['goals'] });
      // The detail cache must not serve the pre-save campaign when the
      // builder is reopened (the list invalidate above only matches
      // ['campaigns'], not the ['campaign', id] detail query).
      if (editId !== null) {
        void queryClient.invalidateQueries({ queryKey: ['campaign', editId] });
      }
      navigate('/campaigns');
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
    },
  });

  const patch = (data: Partial<CampaignInput>) => setValues((prev) => ({ ...prev, ...data }));

  const goals = useMemo(() => goalsQuery.data?.items ?? [], [goalsQuery.data]);
  const goalsById = useMemo(() => new Map(goals.map((goal) => [goal.id, goal])), [goals]);

  const milestones = values.goals
    .map((goalId) => goalsById.get(goalId))
    .filter((goal): goal is Goal => goal !== undefined);

  const availableGoals = goals.filter((goal) => !values.goals.includes(goal.id));

  const move = (index: number, direction: -1 | 1) => {
    const next = [...values.goals];
    const target = index + direction;

    if (target < 0 || target >= next.length) {
      return;
    }

    [next[index], next[target]] = [next[target], next[index]];
    patch({ goals: next });
  };

  const remove = (index: number) => {
    patch({ goals: values.goals.filter((_goal, i) => i !== index) });
  };

  const canSave = useMemo(() => values.name.trim().length > 0, [values.name]);

  if (editId && campaignQuery.isLoading) {
    return (
      <PageContainer
        title={__('Edit campaign', 'goalcart')}
        description={__('Loading the campaign…', 'goalcart')}
      >
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={120} />
          <Skeleton variant="rounded" height={200} />
        </Stack>
      </PageContainer>
    );
  }

  if (campaignQuery.isError) {
    return (
      <PageContainer
        title={__('Edit campaign', 'goalcart')}
        description={__('Campaign editor', 'goalcart')}
      >
        <Alert severity="error" variant="outlined">
          {campaignQuery.error instanceof Error
            ? campaignQuery.error.message
            : __('Could not load the campaign.', 'goalcart')}
        </Alert>
      </PageContainer>
    );
  }

  return (
    <PageContainer
      title={editId ? __('Edit campaign', 'goalcart') : __('Add campaign', 'goalcart')}
      description={__(
        'Group goals into scheduled milestones — e.g. a summer sale with free shipping, a gift and a discount at different thresholds.',
        'goalcart'
      )}
      actions={
        <Button startIcon={<ArrowBackIcon />} onClick={() => navigate('/campaigns')}>
          {__('Back to campaigns', 'goalcart')}
        </Button>
      }
    >
      <Stack spacing={3}>
        {/* 1. Basic information */}
        <SectionCard
          title={__('Basic information', 'goalcart')}
          description={__('The identity and lifecycle of the campaign.', 'goalcart')}
        >
          <Stack spacing={2}>
            <TextField
              label={__('Name', 'goalcart')}
              required
              fullWidth
              value={values.name}
              placeholder={__('e.g. Summer Sale', 'goalcart')}
              error={values.name.trim().length === 0}
              helperText={
                values.name.trim().length === 0
                  ? __('Give the campaign a name.', 'goalcart')
                  : __('Internal name shown in the admin.', 'goalcart')
              }
              onChange={(event) => patch({ name: event.target.value })}
            />
            <TextField
              label={__('Description', 'goalcart')}
              fullWidth
              multiline
              minRows={2}
              value={values.description}
              placeholder={__('Internal notes about this campaign.', 'goalcart')}
              onChange={(event) => patch({ description: event.target.value })}
            />
            <FormControlLabel
              control={
                <Switch
                  checked={values.status === 'active'}
                  onChange={(event) =>
                    patch({ status: event.target.checked ? 'active' : 'inactive' })
                  }
                />
              }
              label={
                <Box>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {__('Active', 'goalcart')}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {__(
                      'Inactive campaigns never evaluate their goals on the storefront.',
                      'goalcart'
                    )}
                  </Typography>
                </Box>
              }
            />
          </Stack>
        </SectionCard>
        {/* 2. Schedule + priority */}
        <SectionCard
          title={__('Schedule & priority', 'goalcart')}
          description={__(
            'When the campaign runs, and how it competes with other campaigns.',
            'goalcart'
          )}
        >
          <Grid container spacing={2} sx={{ alignItems: 'flex-start' }}>
            <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
              <TextFieldDate
                label={__('Starts at', 'goalcart')}
                value={values.starts_at}
                onChange={(starts_at) => patch({ starts_at })}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
              <TextFieldDate
                label={__('Ends at', 'goalcart')}
                value={values.ends_at}
                onChange={(ends_at) => patch({ ends_at })}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
              <TextField
                select
                label={__('Priority', 'goalcart')}
                fullWidth
                value={values.priority}
                onChange={(event) => patch({ priority: Number(event.target.value) })}
                helperText={__('Lower numbers win conflicts.', 'goalcart')}
              >
                {[1, 5, 10, 20, 50].map((priority) => (
                  <MenuItem key={priority} value={priority}>
                    {priority}
                  </MenuItem>
                ))}
              </TextField>
            </Grid>
          </Grid>
        </SectionCard>{' '}
        {/* 3. Advanced schedule (Phase 32) */}
        <SectionCard
          title={__('Recurring schedule', 'goalcart')}
          description={__(
            'Optional Phase 32 rules: run the campaign only on selected weekdays and/or inside a daily time window. Goals inherit these rules unless they pin their own.',
            'goalcart'
          )}
        >
          <Grid container spacing={2} sx={{ alignItems: 'flex-start' }}>
            <Grid size={12}>
              <Box>
                <Typography variant="caption" color="text.secondary" component="div" gutterBottom>
                  {__('Repeat on days (optional)', 'goalcart')}
                </Typography>
                <Stack direction="row" spacing={0.75} useFlexGap sx={{ flexWrap: 'wrap' }}>
                  {WEEKDAYS.map((day) => {
                    const selected = scheduleDays.includes(day.value);
                    return (
                      <Chip
                        key={day.value}
                        label={day.label}
                        size="small"
                        color={selected ? 'primary' : 'default'}
                        variant={selected ? 'filled' : 'outlined'}
                        onClick={() => toggleScheduleDay(day.value)}
                      />
                    );
                  })}
                </Stack>
              </Box>
            </Grid>
            <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
              <TextField
                label={__('Daily from', 'goalcart')}
                type="time"
                fullWidth
                value={scheduleStart}
                onChange={(event) => patchDisplay({ schedule_start_time: event.target.value })}
                helperText={__('Optional start of the daily window.', 'goalcart')}
                slotProps={{ inputLabel: { shrink: true } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
              <TextField
                label={__('Daily until', 'goalcart')}
                type="time"
                fullWidth
                value={scheduleEnd}
                onChange={(event) => patchDisplay({ schedule_end_time: event.target.value })}
                helperText={__(
                  'A start later than the end means “after start OR before end” (crosses midnight).',
                  'goalcart'
                )}
                slotProps={{ inputLabel: { shrink: true } }}
              />
            </Grid>
          </Grid>
        </SectionCard>
        {/* 4. Display (pluggable template engine) */}
        <SectionCard
          title={__('Display', 'goalcart')}
          description={__(
            'How the campaign renders on the storefront. A campaign template governs the whole campaign (e.g. the milestone chain); without one, each milestone renders as its own goal card.',
            'goalcart'
          )}
        >
          <CampaignDisplayFields
            display={values.display_rules as Record<string, unknown>}
            templates={templates?.campaign ?? []}
            onChange={(display_rules) => patch({ display_rules })}
          />
        </SectionCard>
        {/* 5. Milestones (goal ordering) */}
        <SectionCard
          title={__('Milestones', 'goalcart')}
          description={__(
            'The ordered goals of the campaign. Shoppers unlock each goal in order as their cart grows.',
            'goalcart'
          )}
        >
          {goalsQuery.isLoading ? (
            <Skeleton variant="rounded" height={160} />
          ) : (
            <Stack spacing={2}>
              {milestones.length > 0 && (
                <Paper variant="outlined" sx={{ p: 1 }}>
                  {milestones.map((goal, index) => (
                    <Box
                      key={goal.id}
                      sx={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 1,
                        p: 1,
                        borderBottom: index < milestones.length - 1 ? '1px solid' : 'none',
                        borderColor: 'divider',
                      }}
                    >
                      <Box
                        sx={{
                          width: 30,
                          height: 30,
                          borderRadius: '50%',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          bgcolor: 'primary.main',
                          color: 'primary.contrastText',
                          fontSize: 13,
                          fontWeight: 600,
                          flexShrink: 0,
                        }}
                      >
                        {index + 1}
                      </Box>
                      <Box sx={{ flex: 1, minWidth: 0 }}>
                        <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                          {goal.name}
                        </Typography>
                        <Typography variant="caption" color="text.secondary" noWrap>
                          {sprintf('%s · %s', targetLabel(goal), rewardLabel(goal))}
                        </Typography>
                      </Box>
                      <Tooltip title={__('Move up', 'goalcart')}>
                        <span>
                          <IconButton
                            size="small"
                            disabled={index === 0}
                            onClick={() => move(index, -1)}
                          >
                            <ArrowUpwardIcon fontSize="small" />
                          </IconButton>
                        </span>
                      </Tooltip>
                      <Tooltip title={__('Move down', 'goalcart')}>
                        <span>
                          <IconButton
                            size="small"
                            disabled={index === milestones.length - 1}
                            onClick={() => move(index, 1)}
                          >
                            <ArrowDownwardIcon fontSize="small" />
                          </IconButton>
                        </span>
                      </Tooltip>
                      <Tooltip title={__('Remove', 'goalcart')}>
                        <span>
                          <IconButton size="small" color="error" onClick={() => remove(index)}>
                            <CloseIcon fontSize="small" />
                          </IconButton>
                        </span>
                      </Tooltip>
                    </Box>
                  ))}
                </Paper>
              )}

              {availableGoals.length > 0 ? (
                <Box>
                  <Typography variant="subtitle2" gutterBottom>
                    {__('Add a milestone', 'goalcart')}
                  </Typography>
                  <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
                    {availableGoals.map((goal) => (
                      <Chip
                        key={goal.id}
                        label={`${goal.name} · ${targetLabel(goal)}`}
                        onClick={() => patch({ goals: [...values.goals, goal.id] })}
                        variant="outlined"
                      />
                    ))}
                  </Stack>
                </Box>
              ) : (
                <Typography variant="body2" color="text.secondary">
                  {values.goals.length === 0
                    ? __('No goals yet — create a goal on the Goals page first.', 'goalcart')
                    : __('All goals are already milestones of this campaign.', 'goalcart')}
                </Typography>
              )}
            </Stack>
          )}
        </SectionCard>
        <Paper variant="outlined" sx={{ p: 2.5, display: 'flex', gap: 1.5 }}>
          <Button
            variant="contained"
            disabled={!canSave || saveMutation.isPending}
            onClick={() => saveMutation.mutate(values)}
          >
            {saveMutation.isPending
              ? __('Saving…', 'goalcart')
              : editId
                ? __('Save changes', 'goalcart')
                : __('Create campaign', 'goalcart')}
          </Button>
          <Button variant="outlined" onClick={() => navigate('/campaigns')}>
            {__('Cancel', 'goalcart')}
          </Button>
        </Paper>
      </Stack>
    </PageContainer>
  );
}

function rewardLabel(goal: Goal): string {
  if (!goal.reward_type) {
    return __('No reward', 'goalcart');
  }

  const base = REWARD_LABELS[goal.reward_type] ?? goal.reward_type;

  if (goal.reward_value !== null) {
    const value =
      goal.reward_type === 'percent_discount'
        ? `${goal.reward_value}%`
        : formatCurrency(goal.reward_value);
    return sprintf('%s (%s)', base, value);
  }

  return base;
}

/**
 * Campaign Display section (pluggable template engine): the campaign
 * template picker + schema-driven appearance form, stored in
 * `display_rules.template_id` / `display_rules.template_settings` and
 * validated server-side against the campaign-scope registry. "Default"
 * (no template) keeps the per-goal card rendering.
 */
function CampaignDisplayFields({
  display,
  templates,
  onChange,
}: {
  display: Record<string, unknown>;
  templates: Array<{
    id: string;
    label: string;
    description: string;
    schema: unknown[];
    settings: Record<string, string | number | boolean>;
  }>;
  onChange: (next: Record<string, unknown>) => void;
}) {
  const templateId = typeof display.template_id === 'string' ? display.template_id : '';
  const definition = templates.find((template) => template.id === templateId);
  const rawSettings = display.template_settings;
  const templateSettings =
    rawSettings && typeof rawSettings === 'object'
      ? (rawSettings as Record<string, string | number | boolean>)
      : (definition?.settings ?? {});

  const chooseTemplate = (next: string) => {
    if (!next) {
      const rest = { ...display };
      delete rest.template_id;
      delete rest.template_settings;
      onChange(rest);
      return;
    }

    const nextDefinition = templates.find((template) => template.id === next);

    onChange({
      ...display,
      template_id: next,
      template_settings: { ...(nextDefinition?.settings ?? {}) },
    });
  };

  return (
    <Stack spacing={2}>
      <TextField
        select
        label={__('Campaign template', 'goalcart')}
        fullWidth
        value={templateId}
        onChange={(event) => chooseTemplate(event.target.value)}
      >
        <MenuItem value="">
          <em>{__('Default (no campaign template)', 'goalcart')}</em>
        </MenuItem>
        {templates.map((template) => (
          <MenuItem key={template.id} value={template.id}>
            {template.label}
          </MenuItem>
        ))}
      </TextField>

      {definition && (
        <Box
          sx={{
            p: 2,
            border: '1px dashed',
            borderColor: 'divider',
            borderRadius: 2,
            bgcolor: 'background.default',
          }}
        >
          <Box
            sx={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: 1,
              mb: 2,
            }}
          >
            <Box>
              <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
                {definition.label}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {definition.description}
              </Typography>
            </Box>
            <Button size="small" startIcon={<RestartAltIcon />} onClick={() => chooseTemplate('')}>
              {__('Use global default', 'goalcart')}
            </Button>
          </Box>
          <SchemaForm
            schema={definition.schema as import('../types').TemplateField[]}
            value={templateSettings}
            onChange={(next) => onChange({ ...display, template_settings: next })}
          />
        </Box>
      )}
    </Stack>
  );
}

interface TextFieldDateProps {
  label: string;
  value: string | null;
  onChange: (value: string | null) => void;
}

/** datetime-local input mapping to/from the API's 'Y-m-d H:i:s'. */
function TextFieldDate({ label, value, onChange }: TextFieldDateProps) {
  const inputValue = value ? value.replace(' ', 'T').slice(0, 16) : '';

  return (
    <TextField
      label={label}
      type="datetime-local"
      fullWidth
      value={inputValue}
      onChange={(event) => {
        const raw = event.target.value;

        if ('' === raw) {
          onChange(null);
          return;
        }

        onChange(raw.replace('T', ' ') + ':00');
      }}
      slotProps={{ inputLabel: { shrink: true } }}
    />
  );
}
