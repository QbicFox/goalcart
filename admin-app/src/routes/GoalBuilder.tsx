import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from 'react';
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
    operator: 'and',
    children: [],
    reward_type: null,
    reward_value: null,
    reward_max_value: null,
    reward_meta: { stacking: 'none', label: '' },
    priority: 10,
    exclusive: false,
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

  useEffect(() => {
    if (goalQuery.data) {
      setValues(goalToInput(goalQuery.data));
    }
  }, [goalQuery.data]);
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

  const canSave = useMemo(() => values.name.trim().length > 0, [values.name]);

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

        {/* 7. Priority & conflicts (Phase 26) */}
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
                : __('Create goal', 'goalcart')}
          </Button>
          <Button variant="outlined" onClick={() => navigate('/goals')}>
            {__('Cancel', 'goalcart')}
          </Button>
        </Paper>
      </Stack>
    </PageContainer>
  );
}
