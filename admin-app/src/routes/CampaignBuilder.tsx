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
import { fetchMissions } from '../api/missions';
import PreviewPanel from '../components/preview/PreviewPanel';
import { usePreview } from '../components/preview/usePreview';
import SectionCard from '../components/mission-builder/SectionCard';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import WheelDateTimeField from '../components/wheel-picker/WheelDateTimeField';
import WheelTimeField from '../components/wheel-picker/WheelTimeField';
import { useStickyBarActions } from '../providers/ActionBarProvider';
import { useFullscreen } from '../providers/FullscreenProvider';
import PageContainer from '../components/PageContainer';
import { formatCurrency, formatNumber } from '../lib/format';
import SchemaForm from '../templates/SchemaForm';
import { useTemplates } from '../templates/useTemplates';
import type { Campaign, CampaignInput, Mission } from '../types';

const REWARD_LABELS: Record<string, string> = {
  free_shipping: __('Free shipping', 'faracart'),
  percent_discount: __('% discount', 'faracart'),
  fixed_discount: __('Fixed discount', 'faracart'),
  free_gift: __('Free gift', 'faracart'),
  coupon: __('Coupon', 'faracart'),
};

const WEEKDAYS = [
  { value: 1, label: __('Monday', 'faracart') },
  { value: 2, label: __('Tuesday', 'faracart') },
  { value: 3, label: __('Wednesday', 'faracart') },
  { value: 4, label: __('Thursday', 'faracart') },
  { value: 5, label: __('Friday', 'faracart') },
  { value: 6, label: __('Saturday', 'faracart') },
  { value: 7, label: __('Sunday', 'faracart') },
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
    missions: [],
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
    missions: (campaign.missions ?? [])
      .slice()
      .sort((a, b) => a.menu_order - b.menu_order)
      .map((mission) => mission.id),
  };
}

function targetLabel(mission: Mission): string {
  const countTypes = ['quantity', 'distinct_quantity', 'weight'];

  if (countTypes.includes(mission.type) || mission.calculation_mode === 'quantity') {
    return formatNumber(mission.target);
  }

  return formatCurrency(mission.target);
}

/** Amount/quantity a state preset fraction should simulate for the form. */
function campaignPresetTargets(
  values: CampaignInput,
  missionsById: Map<number, Mission>,
  fraction: number
): { amount: number; quantity: number } {
  // Anchor the presets to the top milestone target (mirrors the campaign
  // preview dialog's topMilestone anchoring).
  let top: Mission | null = null;

  for (const missionId of values.missions) {
    const mission = missionsById.get(missionId);

    if (mission && (!top || mission.target > top.target)) {
      top = mission;
    }
  }

  const value = (top ? Number(top.target) || 0 : 0) * fraction;
  const countTypes = ['quantity', 'distinct_quantity', 'weight'];
  const isMoney = top ? !countTypes.includes(top.type) : true;

  return isMoney ? { amount: value, quantity: 0 } : { amount: 0, quantity: value };
}

/**
 * Campaign Builder. A form for creating and editing campaigns
 * — Basic information, Schedule, Priority and Milestones (mission ordering)
 * — wired to the REST CRUD endpoints. New campaigns use
 * `/campaigns/new`, existing ones `/campaigns/:id/edit`.
 *
 * The page is a two-column layout: the form on the right (RTL) and a
 * sticky live preview on the left, driven by the current form values
 * through the shared preview system.
 */
export default function CampaignBuilder() {
  const { id } = useParams();
  const editId = id ? Number(id) : null;
  const navigate = useNavigate();
  const { notify } = useSnackbar();
  const queryClient = useQueryClient();
  const { data: templates } = useTemplates();
  const { fullscreen } = useFullscreen();

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

  // All missions, so milestones can be picked and ordered.
  const missionsQuery = useQuery({
    queryKey: ['missions', 'all'],
    queryFn: () => fetchMissions({ per_page: 100 }),
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
          ? __('The campaign was updated.', 'faracart')
          : __('The campaign was created.', 'faracart')
      );
      void queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      void queryClient.invalidateQueries({ queryKey: ['missions'] });
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

  const missions = useMemo(() => missionsQuery.data?.items ?? [], [missionsQuery.data]);
  const missionsById = useMemo(() => new Map(missions.map((mission) => [mission.id, mission])), [missions]);

  // Live preview: the target key includes the (possibly unsaved) form
  // values, so the preview refetches whenever the form changes
  // (debounced inside usePreview). The backend loads the milestone missions
  // by id and applies the form's name + display_rules, so the preview
  // reflects the current form state.
  const previewTarget = useMemo(
    () => ({ id: editId ?? 0, values, missionsById }),
    [editId, values, missionsById]
  );

  const preview = usePreview({
    target: previewTarget,
    derive: (current) => ({
      targetsFor: (fraction) => campaignPresetTargets(current.values, current.missionsById, fraction),
      paramsFor: () => ({ campaignId: editId ?? undefined, campaign: current.values }),
      payloadKey: `campaign:${current.id}:${JSON.stringify(current.values)}`,
    }),
  });

  // The sticky preview column sticks below the WP admin bar in embedded
  // mode (32px) and flush in full-screen mode where the app's own header
  // is fixed and the content area scrolls internally.
  const stickyTop = fullscreen ? 8 : 40;

  const milestones = values.missions
    .map((missionId) => missionsById.get(missionId))
    .filter((mission): mission is Mission => mission !== undefined);

  const availableMissions = missions.filter((mission) => !values.missions.includes(mission.id));

  const move = (index: number, direction: -1 | 1) => {
    const next = [...values.missions];
    const target = index + direction;

    if (target < 0 || target >= next.length) {
      return;
    }

    [next[index], next[target]] = [next[target], next[index]];
    patch({ missions: next });
  };

  const remove = (index: number) => {
    patch({ missions: values.missions.filter((_mission, i) => i !== index) });
  };

  const canSave = useMemo(() => values.name.trim().length > 0, [values.name]); // Sticky bottom bar: Save / Create + Cancel (moved out of the page
  // body into the dashboard's bottom action bar). Hidden while an edited
  // campaign is still loading so it never saves the empty seed form.
  // `values` is a dep because the button reads it — re-registering on
  // every form change keeps the click handler from ever saving stale
  // state.
  useStickyBarActions([saveMutation.isPending, canSave, editId, Boolean(campaign), values], () =>
    editId && !campaign ? null : (
      <>
        <Button
          variant="contained"
          disabled={!canSave || saveMutation.isPending}
          onClick={() => saveMutation.mutate(values)}
        >
          {saveMutation.isPending
            ? __('Saving…', 'faracart')
            : editId
              ? __('Save changes', 'faracart')
              : __('Create campaign', 'faracart')}
        </Button>
        <Button variant="outlined" onClick={() => navigate('/campaigns')}>
          {__('Cancel', 'faracart')}
        </Button>
      </>
    )
  );

  if (editId && campaignQuery.isLoading) {
    return (
      <PageContainer
        title={__('Edit campaign', 'faracart')}
        description={__('Loading the campaign…', 'faracart')}
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
        title={__('Edit campaign', 'faracart')}
        description={__('Campaign editor', 'faracart')}
      >
        <Alert severity="error" variant="outlined">
          {campaignQuery.error instanceof Error
            ? campaignQuery.error.message
            : __('Could not load the campaign.', 'faracart')}
        </Alert>
      </PageContainer>
    );
  }

  return (
    <PageContainer
      title={editId ? __('Edit campaign', 'faracart') : __('Add campaign', 'faracart')}
      description={__(
        'Group missions into scheduled milestones — e.g. a summer sale with free shipping, a gift and a discount at different thresholds.',
        'faracart'
      )}
      actions={
        <Button startIcon={<ArrowBackIcon />} onClick={() => navigate('/campaigns')}>
          {__('Back to campaigns', 'faracart')}
        </Button>
      }
    >
      <Grid container spacing={3}>
        {/* Right column (RTL): the edit form. */}
        <Grid size={{ xs: 12, md: 7, lg: 8 }}>
          <Stack spacing={3}>
            {/* 1. Basic information */}
            <SectionCard
              title={__('Basic information', 'faracart')}
              description={__('The identity and lifecycle of the campaign.', 'faracart')}
            >
              <Stack spacing={2}>
                <TextField
                  label={__('Name', 'faracart')}
                  required
                  fullWidth
                  value={values.name}
                  placeholder={__('e.g. Summer Sale', 'faracart')}
                  error={values.name.trim().length === 0}
                  helperText={
                    values.name.trim().length === 0
                      ? __('Give the campaign a name.', 'faracart')
                      : __('Internal name shown in the admin.', 'faracart')
                  }
                  onChange={(event) => patch({ name: event.target.value })}
                />
                <TextField
                  label={__('Description', 'faracart')}
                  fullWidth
                  multiline
                  minRows={2}
                  value={values.description}
                  placeholder={__('Internal notes about this campaign.', 'faracart')}
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
                        {__('Active', 'faracart')}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {__(
                          'Inactive campaigns never evaluate their missions on the storefront.',
                          'faracart'
                        )}
                      </Typography>
                    </Box>
                  }
                />
              </Stack>
            </SectionCard>
            {/* 2. Schedule + priority */}
            <SectionCard
              title={__('Schedule & priority', 'faracart')}
              description={__(
                'When the campaign runs, and how it competes with other campaigns.',
                'faracart'
              )}
            >
              <Grid container spacing={2} sx={{ alignItems: 'flex-start' }}>
                <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                  <WheelDateTimeField
                    label={__('Starts at', 'faracart')}
                    value={values.starts_at}
                    onChange={(starts_at) => patch({ starts_at })}
                  />
                </Grid>
                <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                  <WheelDateTimeField
                    label={__('Ends at', 'faracart')}
                    value={values.ends_at}
                    onChange={(ends_at) => patch({ ends_at })}
                  />
                </Grid>
                <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                  <TextField
                    select
                    label={__('Priority', 'faracart')}
                    fullWidth
                    value={values.priority}
                    onChange={(event) => patch({ priority: Number(event.target.value) })}
                    helperText={__('Lower numbers win conflicts.', 'faracart')}
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
            {/* 3. Advanced schedule */}
            <SectionCard
              title={__('Recurring schedule', 'faracart')}
              description={__(
                'Optional Phase 32 rules: run the campaign only on selected weekdays and/or inside a daily time window. Missions inherit these rules unless they pin their own.',
                'faracart'
              )}
            >
              <Grid container spacing={2} sx={{ alignItems: 'flex-start' }}>
                <Grid size={12}>
                  <Box>
                    <Typography
                      variant="caption"
                      color="text.secondary"
                      component="div"
                      gutterBottom
                    >
                      {__('Repeat on days (optional)', 'faracart')}
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
                </Grid>                <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                  <WheelTimeField
                    label={__('Daily from', 'faracart')}
                    value={scheduleStart}
                    onChange={(next) => patchDisplay({ schedule_start_time: next })}
                    helperText={__('Optional start of the daily window.', 'faracart')}
                  />
                </Grid>
                <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                  <WheelTimeField
                    label={__('Daily until', 'faracart')}
                    value={scheduleEnd}
                    onChange={(next) => patchDisplay({ schedule_end_time: next })}
                    helperText={__(
                      'A start later than the end means “after start OR before end” (crosses midnight).',
                      'faracart'
                    )}
                  />
                </Grid>
              </Grid>
            </SectionCard>
            {/* 4. Display (pluggable template engine) */}
            <SectionCard
              title={__('Display', 'faracart')}
              description={__(
                'How the campaign renders on the storefront. A campaign template governs the whole campaign (e.g. the milestone chain); without one, each milestone renders as its own mission card.',
                'faracart'
              )}
            >
              <CampaignDisplayFields
                display={values.display_rules as Record<string, unknown>}
                templates={templates?.campaign ?? []}
                onChange={(display_rules) => patch({ display_rules })}
              />
            </SectionCard>
            {/* 5. Milestones (mission ordering) */}
            <SectionCard
              title={__('Milestones', 'faracart')}
              description={__(
                'The ordered missions of the campaign. Shoppers unlock each mission in order as their cart grows.',
                'faracart'
              )}
            >
              {missionsQuery.isLoading ? (
                <Skeleton variant="rounded" height={160} />
              ) : (
                <Stack spacing={2}>
                  {milestones.length > 0 && (
                    <Paper variant="outlined" sx={{ p: 1 }}>
                      {milestones.map((mission, index) => (
                        <Box
                          key={mission.id}
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
                              {mission.name}
                            </Typography>
                            <Typography variant="caption" color="text.secondary" noWrap>
                              {sprintf('%s · %s', targetLabel(mission), rewardLabel(mission))}
                            </Typography>
                          </Box>
                          <Tooltip title={__('Move up', 'faracart')}>
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
                          <Tooltip title={__('Move down', 'faracart')}>
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
                          <Tooltip title={__('Remove', 'faracart')}>
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

                  {availableMissions.length > 0 ? (
                    <Box>
                      <Typography variant="subtitle2" gutterBottom>
                        {__('Add a milestone', 'faracart')}
                      </Typography>
                      <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
                        {availableMissions.map((mission) => (
                          <Chip
                            key={mission.id}
                            label={`${mission.name} · ${targetLabel(mission)}`}
                            onClick={() => patch({ missions: [...values.missions, mission.id] })}
                            variant="outlined"
                          />
                        ))}
                      </Stack>
                    </Box>
                  ) : (
                    <Typography variant="body2" color="text.secondary">
                      {values.missions.length === 0
                        ? __('No missions yet — create a mission on the Missions page first.', 'faracart')
                        : __('All missions are already milestones of this campaign.', 'faracart')}
                    </Typography>
                  )}
                </Stack>
              )}
            </SectionCard>
          </Stack>
        </Grid>

        {/* Left column (RTL): the sticky live preview. Sticky only on
            desktop — on small screens the preview flows after the form
            in a single column. */}
        <Grid size={{ xs: 12, md: 5, lg: 4 }}>
          <Box sx={{ position: { xs: 'static', md: 'sticky' }, top: stickyTop }}>
            <PreviewPanel scope="campaign" preview={preview} />
          </Box>
        </Grid>
      </Grid>
    </PageContainer>
  );
}

function rewardLabel(mission: Mission): string {
  if (!mission.reward_type) {
    return __('No reward', 'faracart');
  }

  const base = REWARD_LABELS[mission.reward_type] ?? mission.reward_type;

  if (mission.reward_value !== null) {
    const value =
      mission.reward_type === 'percent_discount'
        ? `${mission.reward_value}%`
        : formatCurrency(mission.reward_value);
    return sprintf('%s (%s)', base, value);
  }

  return base;
}

/**
 * Campaign Display section (pluggable template engine): the campaign
 * template picker + schema-driven appearance form, stored in
 * `display_rules.template_id` / `display_rules.template_settings` and
 * validated server-side against the campaign-scope registry. "Default"
 * (no template) keeps the per-mission card rendering.
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
        label={__('Campaign template', 'faracart')}
        fullWidth
        value={templateId}
        onChange={(event) => chooseTemplate(event.target.value)}
      >
        <MenuItem value="">
          <em>{__('Default (no campaign template)', 'faracart')}</em>
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
              {__('Use global default', 'faracart')}
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

