import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { createMission, fetchMission, updateMission } from '../api/missions';
import CompositeChildrenEditor from '../components/mission-builder/CompositeChildrenEditor';
import ConditionFields from '../components/mission-builder/ConditionFields';
import DisplayFields from '../components/mission-builder/DisplayFields';
import MissionTypePicker from '../components/mission-builder/MissionTypePicker';
import PreviewPanel from '../components/preview/PreviewPanel';
import { usePreview } from '../components/preview/usePreview';
import RewardFields from '../components/mission-builder/RewardFields';
import SectionCard from '../components/mission-builder/SectionCard';
import TargetFields from '../components/mission-builder/TargetFields';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { useStickyBarActions } from '../providers/ActionBarProvider';
import { useFullscreen } from '../providers/FullscreenProvider';
import PageContainer from '../components/PageContainer';
import type { Mission, MissionChildInput, MissionInput, MissionType } from '../types';

/** Fresh-mission defaults (mirror the backend save-arg defaults). */
function emptyMission(): MissionInput {
  return {
    name: '',
    description: '',
    status: 'active',
    type: 'amount',
    target: 0,
    calculation_mode: 'subtotal',
    categories: [],
    products: [],
    excluded_products: [],
    tags: [],
    attributes: [],
    customer_roles: [],
    customer_state: [],
    first_order: false,
    vip: false,
    vip_min_spend: 0,
    vip_min_orders: 0,
    shipping_zones: [],
    cart_coupons: [],
    cart_min_items: 0,
    schedule_days: [],
    schedule_start_time: '',
    schedule_end_time: '',
    operator: 'and',
    children: [],
    reward_type: null,
    reward_value: null,
    reward_max_value: null,
    reward_meta: { stacking: 'none', label: '' },
    priority: 10,
    exclusive: false,
    max_completions_per_user: null,
    starts_at: null,
    ends_at: null,
    display_settings: {},
  };
}

/** Map a REST mission onto the builder form model (children included). */
function missionToInput(mission: Mission): MissionInput {
  const children: MissionChildInput[] = (mission.children ?? []).map((child) => ({
    type: (child.type as MissionType) ?? 'amount',
    target: Number(child.target ?? 0),
    calculation_mode: String(child.calculation_mode ?? 'subtotal'),
    categories: Array.isArray(child.categories) ? child.categories.map(Number) : [],
    products: Array.isArray(child.products) ? child.products.map(Number) : [],
  }));

  return {
    name: mission.name,
    description: mission.description ?? '',
    status: mission.status,
    type: mission.type,
    target: mission.target,
    calculation_mode: mission.calculation_mode,
    categories: mission.categories ?? [],
    products: mission.products ?? [],
    excluded_products: mission.excluded_products ?? [],
    tags: mission.tags ?? [],
    attributes: mission.attributes ?? [],
    customer_roles: mission.customer_roles ?? [],
    customer_state: mission.customer_state ?? [],
    first_order: Boolean(mission.first_order),
    vip: Boolean(mission.vip),
    vip_min_spend: Number(mission.vip_min_spend ?? 0),
    vip_min_orders: Number(mission.vip_min_orders ?? 0),
    shipping_zones: mission.shipping_zones ?? [],
    cart_coupons: mission.cart_coupons ?? [],
    cart_min_items: Number(mission.cart_min_items ?? 0),
    schedule_days: mission.schedule_days ?? [],
    schedule_start_time: String(mission.schedule_start_time ?? ''),
    schedule_end_time: String(mission.schedule_end_time ?? ''),
    operator: mission.operator ?? 'and',
    children,
    reward_type: mission.reward_type,
    reward_value: mission.reward_value,
    reward_max_value: mission.reward_max_value,
    reward_meta: {
      stacking: 'none',
      label: '',
      ...(mission.reward_meta ?? {}),
    },
    priority: mission.priority,
    exclusive: Boolean(mission.exclusive),
    max_completions_per_user: mission.max_completions_per_user ?? null,
    starts_at: mission.starts_at,
    ends_at: mission.ends_at,
    display_settings: mission.display_settings ?? {},
  };
}

/** Whether a mission form measures money (mirrors Mission::is_money_mission). */
function isMoneyMission(values: MissionInput): boolean {
  const countTypes = ['quantity', 'distinct_quantity', 'weight'];

  return !countTypes.includes(values.type) && values.calculation_mode !== 'quantity';
}

/** Amount/quantity a state preset fraction should simulate for the form. */
function presetTargets(values: MissionInput, fraction: number): { amount: number; quantity: number } {
  const target = Number(values.target) || 0;
  const value = target * fraction;

  // Composite missions drive both bases so their children all move.
  if (values.type === 'composite') {
    return { amount: value, quantity: value };
  }

  return isMoneyMission(values) ? { amount: value, quantity: 0 } : { amount: 0, quantity: value };
}

/**
 * Mission Builder (Phase 9: Mission Management UI). A seven-section form for
 * creating and editing missions — Basic Information, Mission Type, Target,
 * Reward, Conditions, Display and Priority — wired to the Phase 7 REST
 * CRUD endpoints. New missions use `/missions/new`, existing ones
 * `/missions/:id/edit`; both render this page.
 *
 * The page is a two-column layout: the form on the right (RTL) and a
 * sticky live preview on the left, driven by the current form values
 * through the shared Phase 15 preview system (POST /preview accepts the
 * unsaved form payload).
 */
export default function MissionBuilder() {
  const { id } = useParams();
  const editId = id ? Number(id) : null;
  const navigate = useNavigate();
  const { notify } = useSnackbar();
  const queryClient = useQueryClient();
  const { fullscreen } = useFullscreen();

  const [values, setValues] = useState<MissionInput>(emptyMission);

  const missionQuery = useQuery({
    queryKey: ['mission', editId],
    queryFn: () => fetchMission(editId as number),
    enabled: editId !== null,
  });

  // Seed the form once the mission loads. This is a guarded state
  // adjustment during render (tracking the already-seeded id) rather
  // than an effect, per react-hooks/set-state-in-effect.
  const mission = missionQuery.data;
  const [loadedMissionId, setLoadedMissionId] = useState<number | null>(null);

  if (mission && mission.id !== loadedMissionId) {
    setLoadedMissionId(mission.id);
    setValues(missionToInput(mission));
  }
  const saveMutation = useMutation({
    mutationFn: (input: MissionInput) => (editId ? updateMission(editId, input) : createMission(input)),
    onSuccess: () => {
      notify(
        editId ? __('The mission was updated.', 'faracart') : __('The mission was created.', 'faracart')
      );
      void queryClient.invalidateQueries({ queryKey: ['missions'] });
      // The detail cache must not serve the pre-save mission when the
      // builder is reopened (the list invalidate above only matches
      // ['missions', …], not the ['mission', id] detail query).
      if (editId !== null) {
        void queryClient.invalidateQueries({ queryKey: ['mission', editId] });
      }
      navigate('/missions');
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
    },
  });

  const patch = (data: Partial<MissionInput>) => setValues((prev) => ({ ...prev, ...data }));

  const changeType = (type: MissionType) => {
    setValues((prev) => ({
      ...prev,
      type,
      // Type-aware default calculation basis (Mission::default_calculation_mode).
      calculation_mode: type === 'product' ? 'quantity' : 'subtotal',
      categories: [],
      products: [],
      operator: 'and',
      children: [],
    }));
  };

  // Live preview: the target key includes the (possibly unsaved) form
  // values, so the preview refetches whenever the form changes (debounced
  // inside usePreview). The backend merges the form payload over the
  // stored row when editing, so the preview always reflects the current
  // form state.
  const previewTarget = useMemo(() => ({ id: editId ?? 0, values }), [editId, values]);

  const preview = usePreview({
    target: previewTarget,
    derive: (current) => ({
      targetsFor: (fraction) => presetTargets(current.values, fraction),
      paramsFor: () => ({ missionId: editId ?? undefined, mission: current.values }),
      payloadKey: `mission:${current.id}:${JSON.stringify(current.values)}`,
    }),
  });

  // The sticky preview column sticks below the WP admin bar in embedded
  // mode (32px) and flush in full-screen mode where the app's own header
  // is fixed and the content area scrolls internally.
  const stickyTop = fullscreen ? 8 : 40;

  const canSave = useMemo(() => values.name.trim().length > 0, [values.name]); // Sticky bottom bar: Save / Create + Cancel (moved out of the page
  // body into the dashboard's bottom action bar). Hidden while an edited
  // mission is still loading so it never saves the empty seed form. `values`
  // is a dep because the button reads it — re-registering on every form
  // change keeps the click handler from ever saving stale state.
  useStickyBarActions([saveMutation.isPending, canSave, editId, Boolean(mission), values], () =>
    editId && !mission ? null : (
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
              : __('Create mission', 'faracart')}
        </Button>
        <Button variant="outlined" onClick={() => navigate('/missions')}>
          {__('Cancel', 'faracart')}
        </Button>
      </>
    )
  );

  if (editId && missionQuery.isLoading) {
    return (
      <PageContainer
        title={__('Edit mission', 'faracart')}
        description={__('Loading the mission…', 'faracart')}
      >
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={120} />
          <Skeleton variant="rounded" height={200} />
          <Skeleton variant="rounded" height={160} />
        </Stack>
      </PageContainer>
    );
  }

  if (missionQuery.isError) {
    return (
      <PageContainer
        title={__('Edit mission', 'faracart')}
        description={__('Mission editor', 'faracart')}
      >
        <Alert severity="error" variant="outlined">
          {missionQuery.error instanceof Error
            ? missionQuery.error.message
            : __('Could not load the mission.', 'faracart')}
        </Alert>
      </PageContainer>
    );
  }

  return (
    <PageContainer
      title={editId ? __('Edit mission', 'faracart') : __('Add mission', 'faracart')}
      description={__(
        'Define what shoppers need to reach and what they earn for getting there.',
        'faracart'
      )}
      actions={
        <Button startIcon={<ArrowBackIcon />} onClick={() => navigate('/missions')}>
          {__('Back to missions', 'faracart')}
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
              description={__('The internal identity of the mission.', 'faracart')}
            >
              <Stack spacing={2}>
                <TextField
                  label={__('Name', 'faracart')}
                  required
                  fullWidth
                  value={values.name}
                  placeholder={__('e.g. Free shipping over 500K', 'faracart')}
                  error={values.name.trim().length === 0}
                  helperText={
                    values.name.trim().length === 0
                      ? __('Give the mission a name.', 'faracart')
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
                  placeholder={__('Internal notes about this mission.', 'faracart')}
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
                        {__('Inactive missions never evaluate on the storefront.', 'faracart')}
                      </Typography>
                    </Box>
                  }
                />
              </Stack>
            </SectionCard>

            {/* 2. Mission type */}
            <SectionCard
              title={__('Mission type', 'faracart')}
              description={__('What the mission measures.', 'faracart')}
            >
              <MissionTypePicker value={values.type} onChange={changeType} />
            </SectionCard>

            {/* 3. Target */}
            <SectionCard
              title={__('Target', 'faracart')}
              description={__('The threshold shoppers need to reach.', 'faracart')}
            >
              <TargetFields values={values} onValueChange={patch} />
            </SectionCard>

            {/* 3b. Composite operator + children */}
            {values.type === 'composite' && (
              <SectionCard
                title={__('Composite conditions', 'faracart')}
                description={__(
                  'Children are evaluated against the same cart. AND requires every child, OR completes at the best child.',
                  'faracart'
                )}
                action={
                  <TextField
                    select
                    label={__('Operator', 'faracart')}
                    size="small"
                    sx={{ minWidth: 140 }}
                    value={values.operator}
                    onChange={(event) => patch({ operator: event.target.value as 'and' | 'or' })}
                  >
                    <MenuItem value="and">{__('AND (all children required)', 'faracart')}</MenuItem>
                    <MenuItem value="or">{__('OR (any child completes)', 'faracart')}</MenuItem>
                  </TextField>
                }
              >
                <CompositeChildrenEditor
                  children={values.children}
                  onChange={(children) => patch({ children })}
                />
              </SectionCard>
            )}

            {/* 4. Reward */}
            <SectionCard
              title={__('Reward', 'faracart')}
              description={__('What the customer earns when the mission is reached.', 'faracart')}
            >
              <RewardFields values={values} onValueChange={patch} />
            </SectionCard>

            {/* 5. Conditions */}
            <SectionCard
              title={__('Conditions', 'faracart')}
              description={__('Restrictions and schedule for this mission.', 'faracart')}
            >
              <ConditionFields values={values} onValueChange={patch} />
            </SectionCard>

            {/* 6. Display */}
            <SectionCard
              title={__('Display', 'faracart')}
              description={__(
                'Customer-facing copy and template for the progress widget.',
                'faracart'
              )}
            >
              <DisplayFields values={values} onValueChange={patch} />
            </SectionCard>

            {/* 7. Per-user completion limit (Phase 36) */}
            <SectionCard
              title={__('Completion limit', 'faracart')}
              description={__(
                'How many times the same shopper may complete this mission. Each completion cycle grants the reward once; after the limit is reached the mission no longer appears as completable for that shopper.',
                'faracart'
              )}
            >
              <Stack spacing={2}>
                <FormControlLabel
                  control={
                    <Switch
                      checked={values.max_completions_per_user === null}
                      onChange={(event) =>
                        patch({
                          max_completions_per_user: event.target.checked
                            ? null
                            : Math.max(1, values.max_completions_per_user ?? 1),
                        })
                      }
                    />
                  }
                  label={
                    <Box>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {__('Unlimited', 'faracart')}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {__(
                          'The shopper may complete this mission as many times as they want (default for existing missions).',
                          'faracart'
                        )}
                      </Typography>
                    </Box>
                  }
                />
                {values.max_completions_per_user !== null && (
                  <TextField
                    label={__('Times each user can complete', 'faracart')}
                    type="number"
                    fullWidth
                    sx={{ maxWidth: 220 }}
                    slotProps={{ htmlInput: { min: 1, step: 1 } }}
                    value={values.max_completions_per_user}
                    helperText={__(
                      'A positive whole number. When the shopper reaches this many completions, further completions (and rewards) are blocked server-side.',
                      'faracart'
                    )}
                    onChange={(event) => {
                      const raw = event.target.value;
                      // Only positive integers are valid (reject negatives,
                      // decimals and empty) — empty keeps the previous value so
                      // the field never locks the mission to zero.
                      const parsed = /^[1-9]\d*$/.test(raw) ? Number(raw) : null;
                      patch({
                        max_completions_per_user: parsed ?? values.max_completions_per_user,
                      });
                    }}
                  />
                )}
              </Stack>
            </SectionCard>

            {/* 8. Priority & conflicts (Phase 26) */}
            <SectionCard
              title={__('Priority & conflicts', 'faracart')}
              description={__(
                'How this mission competes when several missions are active. Lower priority numbers win first; a mission inside a campaign is also ordered by its campaign priority.',
                'faracart'
              )}
            >
              <Stack spacing={2}>
                <TextField
                  label={__('Priority', 'faracart')}
                  type="number"
                  fullWidth
                  sx={{ maxWidth: 220 }}
                  value={values.priority}
                  helperText={__('Lower numbers win conflicts.', 'faracart')}
                  onChange={(event) =>
                    patch({ priority: Math.max(0, Number(event.target.value) || 0) })
                  }
                />
                <FormControlLabel
                  control={
                    <Switch
                      checked={values.exclusive}
                      onChange={(event) => patch({ exclusive: event.target.checked })}
                    />
                  }
                  label={
                    <Box>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {__('Exclusive (mutually exclusive)', 'faracart')}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {__(
                          'When this mission is reached, every lower-priority mission is skipped and never grants its reward. Higher-priority missions are unaffected.',
                          'faracart'
                        )}
                      </Typography>
                    </Box>
                  }
                />
              </Stack>
            </SectionCard>
          </Stack>
        </Grid>

        {/* Left column (RTL): the sticky live preview. Sticky only on
            desktop — on small screens the preview flows after the form
            in a single column. */}
        <Grid size={{ xs: 12, md: 5, lg: 4 }}>
          <Box sx={{ position: { xs: 'static', md: 'sticky' }, top: stickyTop }}>
            <PreviewPanel scope="mission" preview={preview} />
          </Box>
        </Grid>
      </Grid>
    </PageContainer>
  );
}
