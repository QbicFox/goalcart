import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/Delete';
import FlagIcon from '@mui/icons-material/Flag';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useState } from 'react';

import { deleteGoal, fetchGoals } from '../api/goals';
import type { Goal } from '../types';
import ConfirmDialog from '../components/ConfirmDialog';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import { useSnackbar } from '../components/notifications/SnackbarProvider';

const REWARD_LABELS: Record<string, string> = {
  free_shipping: __('Free shipping', 'goalcart'),
  percent_discount: __('% discount', 'goalcart'),
  fixed_discount: __('Fixed discount', 'goalcart'),
  free_gift: __('Free gift', 'goalcart'),
  coupon: __('Coupon', 'goalcart'),
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
    return sprintf(__('%1$s (%2$s)', 'goalcart'), base, goal.reward_value);
  }

  return base;
}

/**
 * Goals (P08-T03): page container for goal management.
 *
 * Phase 8 ships a minimal read-only list (name, type, target, status,
 * reward) with delete-through-confirmation-dialog, demonstrating the
 * API client, server state, loading/error states, notifications and
 * confirmation dialogs end to end. Phase 9 replaces this list with the
 * full Goal CRUD + builder UI, reusing these primitives.
 */
export default function Goals() {
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();
  const [pendingDelete, setPendingDelete] = useState<Goal | null>(null);

  const goalsQuery = useQuery({
    queryKey: ['goals'],
    queryFn: () => fetchGoals({ per_page: 100 }),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteGoal(id),
    onSuccess: () => {
      notify(__('The goal was deleted.', 'goalcart'));
      setPendingDelete(null);
      void queryClient.invalidateQueries({ queryKey: ['goals'] });
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
      setPendingDelete(null);
    },
  });

  const goals = goalsQuery.data?.items ?? [];

  return (
    <PageContainer
      title={__('Goals', 'goalcart')}
      description={__(
        'Cart goals drive the storefront progress bars and rewards. The full goal builder arrives in the next phase.',
        'goalcart'
      )}
      actions={
        <Button variant="contained" startIcon={<AddIcon />} disabled>
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

      {goalsQuery.isLoading ? (
        <Stack spacing={1}>
          <Skeleton variant="rounded" height={48} />
          <Skeleton variant="rounded" height={320} />
        </Stack>
      ) : !goalsQuery.isError && goals.length === 0 ? (
        <EmptyState
          icon={<FlagIcon fontSize="large" />}
          title={__('No goals yet', 'goalcart')}
          description={__(
            'Goals define what you want shoppers to reach — a cart amount, quantity, category spend and more. The builder is coming in the next phase.',
            'goalcart'
          )}
        />
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{__('Name', 'goalcart')}</TableCell>
                <TableCell>{__('Type', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Target', 'goalcart')}</TableCell>
                <TableCell>{__('Reward', 'goalcart')}</TableCell>
                <TableCell>{__('Status', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Actions', 'goalcart')}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {goals.map((goal) => (
                <TableRow key={goal.id} hover>
                  <TableCell>
                    <Box>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {goal.name}
                      </Typography>
                      {goal.campaign_id !== null && (
                        <Typography variant="caption" color="text.secondary">
                          {sprintf(__('Campaign #%d', 'goalcart'), goal.campaign_id)}
                        </Typography>
                      )}
                    </Box>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2" sx={{ textTransform: 'capitalize' }}>
                      {goal.type.replace(/_/g, ' ')}
                    </Typography>
                  </TableCell>
                  <TableCell align="right">
                    <Typography variant="body2">{goal.target}</Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">{rewardLabel(goal)}</Typography>
                  </TableCell>
                  <TableCell>{statusChip(goal)}</TableCell>
                  <TableCell align="right">
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
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
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
    </PageContainer>
  );
}
