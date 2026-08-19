import AddIcon from '@mui/icons-material/Add';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import DeleteIcon from '@mui/icons-material/Delete';
import EditIcon from '@mui/icons-material/Edit';
import FlagIcon from '@mui/icons-material/Flag';
import SearchIcon from '@mui/icons-material/Search';
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
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { deleteMission, duplicateMission, fetchMissions, updateMission } from '../api/missions';
import type { Mission } from '../types';
import ConfirmDialog from '../components/ConfirmDialog';
import EmptyState from '../components/EmptyState';
import NumberPagination from '../components/NumberPagination';
import PageContainer from '../components/PageContainer';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { formatCurrency, formatNumber, formatSchedule } from '../lib/format';

const REWARD_LABELS: Record<string, string> = {
  free_shipping: __('Free shipping', 'faracart'),
  percent_discount: __('% discount', 'faracart'),
  fixed_discount: __('Fixed discount', 'faracart'),
  free_gift: __('Free gift', 'faracart'),
  coupon: __('Coupon', 'faracart'),
};

const TYPE_LABELS: Record<string, string> = {
  amount: __('Amount', 'faracart'),
  quantity: __('Quantity', 'faracart'),
  distinct_quantity: __('Distinct quantity', 'faracart'),
  category: __('Category', 'faracart'),
  product: __('Product', 'faracart'),
  weight: __('Weight', 'faracart'),
  composite: __('Composite', 'faracart'),
};

function statusChip(mission: Mission) {
  return mission.status === 'active' ? (
    <Chip label={__('Active', 'faracart')} size="small" color="success" variant="outlined" />
  ) : (
    <Chip label={__('Inactive', 'faracart')} size="small" color="default" variant="outlined" />
  );
}

function rewardLabel(mission: Mission): string {
  if (!mission.reward_type) {
    return __('None', 'faracart');
  }

  const base = REWARD_LABELS[mission.reward_type] ?? mission.reward_type;

  if (mission.reward_value !== null) {
    const value =
      mission.reward_type === 'percent_discount'
        ? `${mission.reward_value}%`
        : formatCurrency(mission.reward_value);

    return sprintf(__('%1$s (%2$s)', 'faracart'), base, value);
  }

  return base;
}

function targetLabel(mission: Mission): string {
  const countTypes = ['quantity', 'distinct_quantity', 'weight'];

  if (countTypes.includes(mission.type) || mission.calculation_mode === 'quantity') {
    return formatNumber(mission.target);
  }

  return formatCurrency(mission.target);
}

/**
 * The per-user completion limit label: "Unlimited", "Once",
 * "3 times" — the compact form the list column shows.
 */
function completionLimitLabel(mission: Mission): string {
  const limit = mission.max_completions_per_user;

  if (limit === null || limit === undefined) {
    return __('Unlimited', 'faracart');
  }

  if (limit === 1) {
    return __('Once', 'faracart');
  }

  return sprintf(
    /* translators: %d: number of times. */
    __('%d times', 'faracart'),
    limit
  );
}

/**
 * Missions (Mission Management UI). Professional mission CRUD list:
 *
 * - columns: name, type, reward, status, priority, schedule, completion
 *   stats, actions
 * - actions: create, edit, duplicate, enable/disable, delete, preview
 * - server-side search + status filter + pagination (admin lists)
 *
 * Completion stats are a placeholder until the analytics
 * foundation exposes them.
 */
export default function Missions() {
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();
  const navigate = useNavigate();

  const [page, setPage] = useState(0);
  const [perPage, setPerPage] = useState(10);
  const [status, setStatus] = useState<'' | 'active' | 'inactive'>('');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [pendingDelete, setPendingDelete] = useState<Mission | null>(null);

  // Debounce the search box so typing doesn't fire a request per key.
  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search), 300);
    return () => window.clearTimeout(timer);
  }, [search]);

  const missionsQuery = useQuery({
    queryKey: ['missions', { page, perPage, status, search: debouncedSearch }],
    queryFn: () =>
      fetchMissions({ page: page + 1, per_page: perPage, status, search: debouncedSearch }),
  });
  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['missions'] });
    // A toggled/duplicated/deleted mission must not linger in the detail
    // cache — reopening the builder within the 60 s stale window would
    // otherwise show the pre-mutation values.
    void queryClient.invalidateQueries({ queryKey: ['mission'] });
  };

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteMission(id),
    onSuccess: () => {
      notify(__('The mission was deleted.', 'faracart'));
      setPendingDelete(null);
      invalidate();
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
      setPendingDelete(null);
    },
  });

  const duplicateMutation = useMutation({
    mutationFn: (id: number) => duplicateMission(id),
    onSuccess: (copy) => {
      notify(sprintf(__('Duplicated “%s”.', 'faracart'), copy.name));
      invalidate();
    },
    onError: (error: Error) => notify(error.message, 'error'),
  });

  const toggleMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'active' | 'inactive' }) =>
      updateMission(id, { status }),
    onSuccess: () => {
      notify(__('The mission status was updated.', 'faracart'));
      invalidate();
    },
    onError: (error: Error) => notify(error.message, 'error'),
  });

  const missions = missionsQuery.data?.items ?? [];
  const total = missionsQuery.data?.total ?? 0;

  return (
    <PageContainer
      title={__('Missions', 'faracart')}
      description={__('Cart missions drive the storefront progress bars and rewards.', 'faracart')}
      actions={
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => navigate('/missions/new')}>
          {__('Add mission', 'faracart')}
        </Button>
      }
    >
      {missionsQuery.isError && (
        <Alert severity="error" variant="outlined">
          {missionsQuery.error instanceof Error
            ? missionsQuery.error.message
            : __('Could not load the missions.', 'faracart')}
        </Alert>
      )}

      {/* Search + filter toolbar */}
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
        <TextField
          label={__('Search missions', 'faracart')}
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
          label={__('Status', 'faracart')}
          size="small"
          sx={{ minWidth: 160 }}
          value={status}
          onChange={(event) => {
            setStatus(event.target.value as '' | 'active' | 'inactive');
            setPage(0);
          }}
        >
          <MenuItem value="">{__('All statuses', 'faracart')}</MenuItem>
          <MenuItem value="active">{__('Active', 'faracart')}</MenuItem>
          <MenuItem value="inactive">{__('Inactive', 'faracart')}</MenuItem>
        </TextField>
      </Stack>

      {missionsQuery.isLoading ? (
        <Stack spacing={1}>
          <Skeleton variant="rounded" height={48} />
          <Skeleton variant="rounded" height={320} />
        </Stack>
      ) : !missionsQuery.isError && missions.length === 0 && !debouncedSearch && !status ? (
        <EmptyState
          icon={<FlagIcon fontSize="large" />}
          title={__('No missions yet', 'faracart')}
          description={__(
            'Missions define what you want shoppers to reach — a cart amount, quantity, category spend and more. Create your first mission to start.',
            'faracart'
          )}
          action={
            <Button
              variant="contained"
              startIcon={<AddIcon />}
              onClick={() => navigate('/missions/new')}
            >
              {__('Add your first mission', 'faracart')}
            </Button>
          }
        />
      ) : (
        <>
          <TableContainer component={Paper} variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>{__('Name', 'faracart')}</TableCell>
                  <TableCell>{__('Type', 'faracart')}</TableCell>
                  <TableCell>{__('Reward', 'faracart')}</TableCell>
                  <TableCell>{__('Status', 'faracart')}</TableCell>
                  <TableCell align="right">{__('Priority', 'faracart')}</TableCell>
                  <TableCell>{__('Schedule', 'faracart')}</TableCell>
                  <TableCell>{__('Completion', 'faracart')}</TableCell>
                  <TableCell align="right">{__('Actions', 'faracart')}</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {missions.map((mission) => (
                  <TableRow key={mission.id} hover>
                    <TableCell>
                      <Box>
                        <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
                          <Typography variant="body2" sx={{ fontWeight: 600 }}>
                            {mission.name}
                          </Typography>
                          {mission.exclusive && (
                            <Tooltip
                              title={__(
                                'Exclusive: when this mission is reached, lower-priority missions are skipped.',
                                'faracart'
                              )}
                            >
                              <Chip
                                label={__('Exclusive', 'faracart')}
                                size="small"
                                color="warning"
                                variant="outlined"
                              />
                            </Tooltip>
                          )}
                        </Stack>
                        <Typography variant="caption" color="text.secondary">
                          {targetLabel(mission)}
                        </Typography>
                        {mission.campaign_id !== null && (
                          <Typography variant="caption" color="text.secondary" component="div">
                            {sprintf(__('Campaign #%d', 'faracart'), mission.campaign_id)}
                          </Typography>
                        )}
                      </Box>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">{TYPE_LABELS[mission.type] ?? mission.type}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">{rewardLabel(mission)}</Typography>
                    </TableCell>
                    <TableCell>
                      <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                        {statusChip(mission)}
                        <Tooltip
                          title={
                            mission.status === 'active'
                              ? __('Disable', 'faracart')
                              : __('Enable', 'faracart')
                          }
                        >
                          <Switch
                            size="small"
                            checked={mission.status === 'active'}
                            onChange={(_event, checked) =>
                              toggleMutation.mutate({
                                id: mission.id,
                                status: checked ? 'active' : 'inactive',
                              })
                            }
                            slotProps={{
                              input: {
                                'aria-label': sprintf(
                                  /* translators: %s: mission name. */
                                  __('Toggle %s', 'faracart'),
                                  mission.name
                                ),
                              },
                            }}
                          />
                        </Tooltip>
                      </Stack>
                    </TableCell>
                    <TableCell align="right">
                      <Typography variant="body2">{mission.priority}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">
                        {formatSchedule(mission.starts_at, mission.ends_at)}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Tooltip
                        title={
                          mission.max_completions_per_user === null ||
                          mission.max_completions_per_user === undefined
                            ? __(
                                'The shopper may complete this mission as many times as they want.',
                                'faracart'
                              )
                            : sprintf(
                                /* translators: %d: number of times. */
                                __(
                                  'Each shopper may complete this mission at most %d times.',
                                  'faracart'
                                ),
                                mission.max_completions_per_user
                              )
                        }
                      >
                        <Typography variant="body2">{completionLimitLabel(mission)}</Typography>
                      </Tooltip>
                    </TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end' }}>
                        <Tooltip title={__('Edit', 'faracart')}>
                          <IconButton
                            size="small"
                            aria-label={sprintf(__('Edit %s', 'faracart'), mission.name)}
                            onClick={() => navigate(`/missions/${mission.id}/edit`)}
                          >
                            <EditIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                        <Tooltip title={__('Duplicate', 'faracart')}>
                          <IconButton
                            size="small"
                            aria-label={sprintf(__('Duplicate %s', 'faracart'), mission.name)}
                            disabled={duplicateMutation.isPending}
                            onClick={() => duplicateMutation.mutate(mission.id)}
                          >
                            <ContentCopyIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                        <Tooltip title={__('Delete', 'faracart')}>
                          <IconButton
                            size="small"
                            color="error"
                            aria-label={sprintf(__('Delete %s', 'faracart'), mission.name)}
                            onClick={() => setPendingDelete(mission)}
                          >
                            <DeleteIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}

                {missions.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={8}>
                      <Typography
                        variant="body2"
                        color="text.secondary"
                        sx={{ py: 3, textAlign: 'center' }}
                      >
                        {__('No missions match your search.', 'faracart')}
                      </Typography>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>

          <NumberPagination
            count={total}
            page={page}
            rowsPerPage={perPage}
            onPageChange={setPage}
            rowsPerPageOptions={[10, 25, 50]}
            onRowsPerPageChange={(next) => {
              setPerPage(next);
              setPage(0);
            }}
          />
        </>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title={__('Delete this mission?', 'faracart')}
        description={
          pendingDelete
            ? sprintf(
                /* translators: %s: mission name. */
                __('“%s” will be permanently deleted. This cannot be undone.', 'faracart'),
                pendingDelete.name
              )
            : undefined
        }
        confirmLabel={__('Delete', 'faracart')}
        destructive
        busy={deleteMutation.isPending}
        onConfirm={() => {
          if (pendingDelete) {
            deleteMutation.mutate(pendingDelete.id);
          }
        }}
        onCancel={() => setPendingDelete(null)}
      />
    </PageContainer>
  );
}
