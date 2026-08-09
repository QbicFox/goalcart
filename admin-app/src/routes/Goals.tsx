import AddIcon from '@mui/icons-material/Add';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import DeleteIcon from '@mui/icons-material/Delete';
import EditIcon from '@mui/icons-material/Edit';
import FlagIcon from '@mui/icons-material/Flag';
import SearchIcon from '@mui/icons-material/Search';
import VisibilityIcon from '@mui/icons-material/Visibility';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TablePagination from '@mui/material/TablePagination';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { deleteGoal, duplicateGoal, fetchGoals, updateGoal } from '../api/goals';
import type { Goal } from '../types';
import ConfirmDialog from '../components/ConfirmDialog';
import EmptyState from '../components/EmptyState';
import GoalPreviewDialog from '../components/GoalPreviewDialog';
import PageContainer from '../components/PageContainer';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { formatCurrency, formatNumber, formatSchedule } from '../lib/format';

const REWARD_LABELS: Record<string, string> = {
  free_shipping: __('Free shipping', 'goalcart'),
  percent_discount: __('% discount', 'goalcart'),
  fixed_discount: __('Fixed discount', 'goalcart'),
  free_gift: __('Free gift', 'goalcart'),
  coupon: __('Coupon', 'goalcart'),
};

const TYPE_LABELS: Record<string, string> = {
  amount: __('Amount', 'goalcart'),
  quantity: __('Quantity', 'goalcart'),
  distinct_quantity: __('Distinct quantity', 'goalcart'),
  category: __('Category', 'goalcart'),
  product: __('Product', 'goalcart'),
  weight: __('Weight', 'goalcart'),
  composite: __('Composite', 'goalcart'),
};

function statusChip(goal: Goal) {
  return goal.status === 'active' ? (
    <Chip label={__('Active', 'goalcart')} size="small" color="success" variant="outlined" />
  ) : (
    <Chip label={__('Inactive', 'goalcart')} size="small" color="default" variant="outlined" />
  );
}

function rewardLabel(goal: Goal): string {
  if (!goal.reward_type) {
    return __('None', 'goalcart');
  }

  const base = REWARD_LABELS[goal.reward_type] ?? goal.reward_type;

  if (goal.reward_value !== null) {
    const value =
      goal.reward_type === 'percent_discount'
        ? `${goal.reward_value}%`
        : formatCurrency(goal.reward_value);

    return sprintf(__('%1$s (%2$s)', 'goalcart'), base, value);
  }

  return base;
}

function targetLabel(goal: Goal): string {
  const countTypes = ['quantity', 'distinct_quantity', 'weight'];

  if (countTypes.includes(goal.type) || goal.calculation_mode === 'quantity') {
    return formatNumber(goal.target);
  }

  return formatCurrency(goal.target);
}

/**
 * Goals (Phase 9: Goal Management UI). Professional goal CRUD list:
 *
 * - columns: name, type, reward, status, priority, schedule, completion
 *   stats, actions
 * - actions: create, edit, duplicate, enable/disable, delete, preview
 * - server-side search + status filter + pagination (Phase 23: admin lists)
 *
 * Completion stats are a placeholder until the Phase 16/17 analytics
 * foundation exposes them.
 */
export default function Goals() {
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();
  const navigate = useNavigate();

  const [page, setPage] = useState(0);
  const [perPage, setPerPage] = useState(10);
  const [status, setStatus] = useState<'' | 'active' | 'inactive'>('');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [pendingDelete, setPendingDelete] = useState<Goal | null>(null);
  const [previewGoal, setPreviewGoal] = useState<Goal | null>(null);

  // Debounce the search box so typing doesn't fire a request per key.
  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search), 300);
    return () => window.clearTimeout(timer);
  }, [search]);

  const goalsQuery = useQuery({
    queryKey: ['goals', { page, perPage, status, search: debouncedSearch }],
    queryFn: () =>
      fetchGoals({ page: page + 1, per_page: perPage, status, search: debouncedSearch }),
  });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ['goals'] });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteGoal(id),
    onSuccess: () => {
      notify(__('The goal was deleted.', 'goalcart'));
      setPendingDelete(null);
      invalidate();
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
      setPendingDelete(null);
    },
  });

  const duplicateMutation = useMutation({
    mutationFn: (id: number) => duplicateGoal(id),
    onSuccess: (copy) => {
      notify(sprintf(__('Duplicated “%s”.', 'goalcart'), copy.name));
      invalidate();
    },
    onError: (error: Error) => notify(error.message, 'error'),
  });

  const toggleMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'active' | 'inactive' }) =>
      updateGoal(id, { status }),
    onSuccess: () => {
      notify(__('The goal status was updated.', 'goalcart'));
      invalidate();
    },
    onError: (error: Error) => notify(error.message, 'error'),
  });

  const goals = goalsQuery.data?.items ?? [];
  const total = goalsQuery.data?.total ?? 0;

  return (
    <PageContainer
      title={__('Goals', 'goalcart')}
      description={__('Cart goals drive the storefront progress bars and rewards.', 'goalcart')}
      actions={
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => navigate('/goals/new')}>
          {__('Add goal', 'goalcart')}
        </Button>
      }
    >
      {goalsQuery.isError && (
        <Alert severity="error" variant="outlined">
          {goalsQuery.error instanceof Error
            ? goalsQuery.error.message
            : __('Could not load the goals.', 'goalcart')}
        </Alert>
      )}

      {/* Search + filter toolbar */}
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
        <TextField
          label={__('Search goals', 'goalcart')}
          size="small"
          fullWidth
          sx={{ maxWidth: { sm: 340 } }}
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setPage(0);
          }}
          slotProps={{
            input: {
              startAdornment: (
                <InputAdornment position="start">
                  <SearchIcon fontSize="small" />
                </InputAdornment>
              ),
            },
          }}
        />
        <TextField
          select
          label={__('Status', 'goalcart')}
          size="small"
          sx={{ minWidth: 160 }}
          value={status}
          onChange={(event) => {
            setStatus(event.target.value as '' | 'active' | 'inactive');
            setPage(0);
          }}
        >
          <MenuItem value="">{__('All statuses', 'goalcart')}</MenuItem>
          <MenuItem value="active">{__('Active', 'goalcart')}</MenuItem>
          <MenuItem value="inactive">{__('Inactive', 'goalcart')}</MenuItem>
        </TextField>
      </Stack>

      {goalsQuery.isLoading ? (
        <Stack spacing={1}>
          <Skeleton variant="rounded" height={48} />
          <Skeleton variant="rounded" height={320} />
        </Stack>
      ) : !goalsQuery.isError && goals.length === 0 && !debouncedSearch && !status ? (
        <EmptyState
          icon={<FlagIcon fontSize="large" />}
          title={__('No goals yet', 'goalcart')}
          description={__(
            'Goals define what you want shoppers to reach — a cart amount, quantity, category spend and more. Create your first goal to start.',
            'goalcart'
          )}
          action={
            <Button
              variant="contained"
              startIcon={<AddIcon />}
              onClick={() => navigate('/goals/new')}
            >
              {__('Add your first goal', 'goalcart')}
            </Button>
          }
        />
      ) : (
        <>
          <TableContainer component={Paper} variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>{__('Name', 'goalcart')}</TableCell>
                  <TableCell>{__('Type', 'goalcart')}</TableCell>
                  <TableCell>{__('Reward', 'goalcart')}</TableCell>
                  <TableCell>{__('Status', 'goalcart')}</TableCell>
                  <TableCell align="right">{__('Priority', 'goalcart')}</TableCell>
                  <TableCell>{__('Schedule', 'goalcart')}</TableCell>
                  <TableCell>{__('Completion', 'goalcart')}</TableCell>
                  <TableCell align="right">{__('Actions', 'goalcart')}</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {goals.map((goal) => (
                  <TableRow key={goal.id} hover>
                    <TableCell>
                      <Box>
                        <Stack direction="row" spacing={0.75} alignItems="center">
                          <Typography variant="body2" sx={{ fontWeight: 600 }}>
                            {goal.name}
                          </Typography>
                          {goal.exclusive && (
                            <Tooltip
                              title={__(
                                'Exclusive: when this goal is reached, lower-priority goals are skipped.',
                                'goalcart'
                              )}
                            >
                              <Chip
                                label={__('Exclusive', 'goalcart')}
                                size="small"
                                color="warning"
                                variant="outlined"
                              />
                            </Tooltip>
                          )}
                        </Stack>
                        <Typography variant="caption" color="text.secondary">
                          {targetLabel(goal)}
                        </Typography>
                        {goal.campaign_id !== null && (
                          <Typography variant="caption" color="text.secondary" component="div">
                            {sprintf(__('Campaign #%d', 'goalcart'), goal.campaign_id)}
                          </Typography>
                        )}
                      </Box>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">{TYPE_LABELS[goal.type] ?? goal.type}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">{rewardLabel(goal)}</Typography>
                    </TableCell>
                    <TableCell>
                      <Stack direction="row" spacing={1} alignItems="center">
                        {statusChip(goal)}
                        <Tooltip
                          title={
                            goal.status === 'active'
                              ? __('Disable', 'goalcart')
                              : __('Enable', 'goalcart')
                          }
                        >
                          <Switch
                            size="small"
                            checked={goal.status === 'active'}
                            onChange={(_event, checked) =>
                              toggleMutation.mutate({
                                id: goal.id,
                                status: checked ? 'active' : 'inactive',
                              })
                            }
                            inputProps={{
                              'aria-label': sprintf(
                                /* translators: %s: goal name. */
                                __('Toggle %s', 'goalcart'),
                                goal.name
                              ),
                            }}
                          />
                        </Tooltip>
                      </Stack>
                    </TableCell>
                    <TableCell align="right">
                      <Typography variant="body2">{goal.priority}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">
                        {formatSchedule(goal.starts_at, goal.ends_at)}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Tooltip title={__('Analytics arrive in a later phase.', 'goalcart')}>
                        <Typography variant="body2" color="text.disabled">
                          —
                        </Typography>
                      </Tooltip>
                    </TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                        <Tooltip title={__('Preview', 'goalcart')}>
                          <IconButton
                            size="small"
                            aria-label={sprintf(__('Preview %s', 'goalcart'), goal.name)}
                            onClick={() => setPreviewGoal(goal)}
                          >
                            <VisibilityIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                        <Tooltip title={__('Edit', 'goalcart')}>
                          <IconButton
                            size="small"
                            aria-label={sprintf(__('Edit %s', 'goalcart'), goal.name)}
                            onClick={() => navigate(`/goals/${goal.id}/edit`)}
                          >
                            <EditIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                        <Tooltip title={__('Duplicate', 'goalcart')}>
                          <IconButton
                            size="small"
                            aria-label={sprintf(__('Duplicate %s', 'goalcart'), goal.name)}
                            disabled={duplicateMutation.isPending}
                            onClick={() => duplicateMutation.mutate(goal.id)}
                          >
                            <ContentCopyIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                        <Tooltip title={__('Delete', 'goalcart')}>
                          <IconButton
                            size="small"
                            color="error"
                            aria-label={sprintf(__('Delete %s', 'goalcart'), goal.name)}
                            onClick={() => setPendingDelete(goal)}
                          >
                            <DeleteIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}

                {goals.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={8}>
                      <Typography
                        variant="body2"
                        color="text.secondary"
                        sx={{ py: 3, textAlign: 'center' }}
                      >
                        {__('No goals match your search.', 'goalcart')}
                      </Typography>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>

          <TablePagination
            component="div"
            count={total}
            page={page}
            rowsPerPage={perPage}
            rowsPerPageOptions={[10, 25, 50]}
            onPageChange={(_event, nextPage) => setPage(nextPage)}
            onRowsPerPageChange={(event) => {
              setPerPage(Number(event.target.value));
              setPage(0);
            }}
          />
        </>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title={__('Delete this goal?', 'goalcart')}
        description={
          pendingDelete
            ? sprintf(
                /* translators: %s: goal name. */
                __('“%s” will be permanently deleted. This cannot be undone.', 'goalcart'),
                pendingDelete.name
              )
            : undefined
        }
        confirmLabel={__('Delete', 'goalcart')}
        destructive
        busy={deleteMutation.isPending}
        onConfirm={() => {
          if (pendingDelete) {
            deleteMutation.mutate(pendingDelete.id);
          }
        }}
        onCancel={() => setPendingDelete(null)}
      />

      <GoalPreviewDialog goal={previewGoal} onClose={() => setPreviewGoal(null)} />
    </PageContainer>
  );
}
