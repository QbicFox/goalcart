import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { createGoal, fetchGoal, updateGoal } from '../api/goals';
import CompositeChildrenEditor from '../components/goal-builder/CompositeChildrenEditor';
import ConditionFields from '../components/goal-builder/ConditionFields';
import DisplayFields from '../components/goal-builder/DisplayFields';
import GoalTypePicker from '../components/goal-builder/GoalTypePicker';
import RewardFields from '../components/goal-builder/RewardFields';
import SectionCard from '../components/goal-builder/SectionCard';
import TargetFields from '../components/goal-builder/TargetFields';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { useStickyBarActions } from '../providers/ActionBarProvider';
import PageContainer from '../components/PageContainer';
import type { Goal, GoalChildInput, GoalInput, GoalType } from '../types';

/** Fresh-goal defaults (mirror the backend save-arg defaults). */
function emptyGoal(): GoalInput {
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

/** Map a REST goal onto the builder form model (children included). */
function goalToInput(goal: Goal): GoalInput {
  const children: GoalChildInput[] = (goal.children ?? []).map((child) => ({
    type: (child.type as GoalType) ?? 'amount',
    target: Number(child.target ?? 0),
    calculation_mode: String(child.calculation_mode ?? 'subtotal'),
    categories: Array.isArray(child.categories) ? child.categories.map(Number) : [],
    products: Array.isArray(child.products) ? child.products.map(Number) : [],
  }));

  return {
    name: goal.name,
    description: goal.description ?? '',
    status: goal.status,
    type: goal.type,
    target: goal.target,
    calculation_mode: goal.calculation_mode,
    categories: goal.categories ?? [],
    products: goal.products ?? [],
    excluded_products: goal.excluded_products ?? [],
    tags: goal.tags ?? [],
    attributes: goal.attributes ?? [],
    customer_roles: goal.customer_roles ?? [],
    customer_state: goal.customer_state ?? [],
    first_order: Boolean(goal.first_order),
    vip: Boolean(goal.vip),
    vip_min_spend: Number(goal.vip_min_spend ?? 0),
    vip_min_orders: Number(goal.vip_min_orders ?? 0),
    shipping_zones: goal.shipping_zones ?? [],
    cart_coupons: goal.cart_coupons ?? [],
    cart_min_items: Number(goal.cart_min_items ?? 0),
    schedule_days: goal.schedule_days ?? [],
    schedule_start_time: String(goal.schedule_start_time ?? ''),
    schedule_end_time: String(goal.schedule_end_time ?? ''),
    operator: goal.operator ?? 'and',
    children,
    reward_type: goal.reward_type,
    reward_value: goal.reward_value,
    reward_max_value: goal.reward_max_value,
    reward_meta: {
      stacking: 'none',
      label: '',
      ...(goal.reward_meta ?? {}),
    },
    priority: goal.priority,
    exclusive: Boolean(goal.exclusive),
    max_completions_per_user: goal.max_completions_per_user ?? null,
    starts_at: goal.starts_at,
    ends_at: goal.ends_at,
    display_settings: goal.display_settings ?? {},
  };
}

/**
 * Goal Builder (Phase 9: Goal Management UI). A seven-section form for
 * creating and editing goals — Basic Information, Goal Type, Target,
 * Reward, Conditions, Display and Priority — wired to the Phase 7 REST
 * CRUD endpoints. New goals use `/goals/new`, existing ones
 * `/goals/:id/edit`; both render this page.
 */
export default function GoalBuilder() {
  const { id } = useParams();
  const editId = id ? Number(id) : null;
  const navigate = useNavigate();
  const { notify } = useSnackbar();
  const queryClient = useQueryClient();

  const [values, setValues] = useState<GoalInput>(emptyGoal);

  const goalQuery = useQuery({
    queryKey: ['goal', editId],
    queryFn: () => fetchGoal(editId as number),
    enabled: editId !== null,
  });

  // Seed the form once the goal loads. This is a guarded state
  // adjustment during render (tracking the already-seeded id) rather
  // than an effect, per react-hooks/set-state-in-effect.
  const goal = goalQuery.data;
  const [loadedGoalId, setLoadedGoalId] = useState<number | null>(null);

  if (goal && goal.id !== loadedGoalId) {
    setLoadedGoalId(goal.id);
    setValues(goalToInput(goal));
  }
  const saveMutation = useMutation({
    mutationFn: (input: GoalInput) => (editId ? updateGoal(editId, input) : createGoal(input)),
    onSuccess: () => {
      notify(
        editId ? __('The goal was updated.', 'goalcart') : __('The goal was created.', 'goalcart')
      );
      void queryClient.invalidateQueries({ queryKey: ['goals'] });
      // The detail cache must not serve the pre-save goal when the
      // builder is reopened (the list invalidate above only matches
      // ['goals', …], not the ['goal', id] detail query).
      if (editId !== null) {
        void queryClient.invalidateQueries({ queryKey: ['goal', editId] });
      }
      navigate('/goals');
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
    },
  });

  const patch = (data: Partial<GoalInput>) => setValues((prev) => ({ ...prev, ...data }));

  const changeType = (type: GoalType) => {
    setValues((prev) => ({
      ...prev,
      type,
      // Type-aware default calculation basis (Goal::default_calculation_mode).
      calculation_mode: type === 'product' ? 'quantity' : 'subtotal',
      categories: [],
      products: [],
      operator: 'and',
      children: [],
    }));
  };

  const canSave = useMemo(() => values.name.trim().length > 0, [values.name]); // Sticky bottom bar: Save / Create + Cancel (moved out of the page
  // body into the dashboard's bottom action bar). Hidden while an edited
  // goal is still loading so it never saves the empty seed form. `values`
  // is a dep because the button reads it — re-registering on every form
  // change keeps the click handler from ever saving stale state.
  useStickyBarActions([saveMutation.isPending, canSave, editId, Boolean(goal), values], () =>
    editId && !goal ? null : (
      <>
        <Button
          variant="contained"
          disabled={!canSave || saveMutation.isPending}
          onClick={() => saveMutation.mutate(values)}
        >
          {saveMutation.isPending
            ? __('Saving…', 'goalcart')
            : editId
              ? __('Save changes', 'goalcart')
              : __('Create goal', 'goalcart')}
        </Button>
        <Button variant="outlined" onClick={() => navigate('/goals')}>
          {__('Cancel', 'goalcart')}
        </Button>
      </>
    )
  );

  if (editId && goalQuery.isLoading) {
    return (
      <PageContainer
        title={__('Edit goal', 'goalcart')}
        description={__('Loading the goal…', 'goalcart')}
      >
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={120} />
          <Skeleton variant="rounded" height={200} />
          <Skeleton variant="rounded" height={160} />
        </Stack>
      </PageContainer>
    );
  }

  if (goalQuery.isError) {
    return (
      <PageContainer
        title={__('Edit goal', 'goalcart')}
        description={__('Goal editor', 'goalcart')}
      >
        <Alert severity="error" variant="outlined">
          {goalQuery.error instanceof Error
            ? goalQuery.error.message
            : __('Could not load the goal.', 'goalcart')}
        </Alert>
      </PageContainer>
    );
  }

  return (
    <PageContainer
      title={editId ? __('Edit goal', 'goalcart') : __('Add goal', 'goalcart')}
      description={__(
        'Define what shoppers need to reach and what they earn for getting there.',
        'goalcart'
      )}
      actions={
        <Button startIcon={<ArrowBackIcon />} onClick={() => navigate('/goals')}>
          {__('Back to goals', 'goalcart')}
        </Button>
      }
    >
      <Stack spacing={3}>
        {/* 1. Basic information */}
        <SectionCard
          title={__('Basic information', 'goalcart')}
          description={__('The internal identity of the goal.', 'goalcart')}
        >
          <Stack spacing={2}>
            <TextField
              label={__('Name', 'goalcart')}
              required
              fullWidth
              value={values.name}
              placeholder={__('e.g. Free shipping over 500K', 'goalcart')}
              error={values.name.trim().length === 0}
              helperText={
                values.name.trim().length === 0
                  ? __('Give the goal a name.', 'goalcart')
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
              placeholder={__('Internal notes about this goal.', 'goalcart')}
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
                    {__('Inactive goals never evaluate on the storefront.', 'goalcart')}
                  </Typography>
                </Box>
              }
            />
          </Stack>
        </SectionCard>

        {/* 2. Goal type */}
        <SectionCard
          title={__('Goal type', 'goalcart')}
          description={__('What the goal measures.', 'goalcart')}
        >
          <GoalTypePicker value={values.type} onChange={changeType} />
        </SectionCard>

        {/* 3. Target */}
        <SectionCard
          title={__('Target', 'goalcart')}
          description={__('The threshold shoppers need to reach.', 'goalcart')}
        >
          <TargetFields values={values} onValueChange={patch} />
        </SectionCard>

        {/* 3b. Composite operator + children */}
        {values.type === 'composite' && (
          <SectionCard
            title={__('Composite conditions', 'goalcart')}
            description={__(
              'Children are evaluated against the same cart. AND requires every child, OR completes at the best child.',
              'goalcart'
            )}
            action={
              <TextField
                select
                label={__('Operator', 'goalcart')}
                size="small"
                sx={{ minWidth: 140 }}
                value={values.operator}
                onChange={(event) => patch({ operator: event.target.value as 'and' | 'or' })}
              >
                <MenuItem value="and">{__('AND (all children required)', 'goalcart')}</MenuItem>
                <MenuItem value="or">{__('OR (any child completes)', 'goalcart')}</MenuItem>
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
          title={__('Reward', 'goalcart')}
          description={__('What the customer earns when the goal is reached.', 'goalcart')}
        >
          <RewardFields values={values} onValueChange={patch} />
        </SectionCard>

        {/* 5. Conditions */}
        <SectionCard
          title={__('Conditions', 'goalcart')}
          description={__('Restrictions and schedule for this goal.', 'goalcart')}
        >
          <ConditionFields values={values} onValueChange={patch} />
        </SectionCard>

        {/* 6. Display */}
        <SectionCard
          title={__('Display', 'goalcart')}
          description={__('Customer-facing copy and template for the progress widget.', 'goalcart')}
        >
          <DisplayFields values={values} onValueChange={patch} />
        </SectionCard>

        {/* 7. Per-user completion limit (Phase 36) */}
        <SectionCard
          title={__('Completion limit', 'goalcart')}
          description={__(
            'How many times the same shopper may complete this goal. Each completion cycle grants the reward once; after the limit is reached the goal no longer appears as completable for that shopper.',
            'goalcart'
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
                    {__('Unlimited', 'goalcart')}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {__(
                      'The shopper may complete this goal as many times as they want (default for existing goals).',
                      'goalcart'
                    )}
                  </Typography>
                </Box>
              }
            />
            {values.max_completions_per_user !== null && (
              <TextField
                label={__('Times each user can complete', 'goalcart')}
                type="number"
                fullWidth
                sx={{ maxWidth: 220 }}
                slotProps={{ htmlInput: { min: 1, step: 1 } }}
                value={values.max_completions_per_user}
                helperText={__(
                  'A positive whole number. When the shopper reaches this many completions, further completions (and rewards) are blocked server-side.',
                  'goalcart'
                )}
                onChange={(event) => {
                  const raw = event.target.value;
                  // Only positive integers are valid (reject negatives,
                  // decimals and empty) — empty keeps the previous value so
                  // the field never locks the goal to zero.
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
          title={__('Priority & conflicts', 'goalcart')}
          description={__(
            'How this goal competes when several goals are active. Lower priority numbers win first; a goal inside a campaign is also ordered by its campaign priority.',
            'goalcart'
          )}
        >
          <Stack spacing={2}>
            <TextField
              label={__('Priority', 'goalcart')}
              type="number"
              fullWidth
              sx={{ maxWidth: 220 }}
              value={values.priority}
              helperText={__('Lower numbers win conflicts.', 'goalcart')}
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
                    {__('Exclusive (mutually exclusive)', 'goalcart')}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {__(
                      'When this goal is reached, every lower-priority goal is skipped and never grants its reward. Higher-priority goals are unaffected.',
                      'goalcart'
                    )}
                  </Typography>
                </Box>
              }
            />
          </Stack>
        </SectionCard>
      </Stack>
    </PageContainer>
  );
}
