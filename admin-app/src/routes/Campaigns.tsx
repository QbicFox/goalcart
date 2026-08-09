import AddIcon from '@mui/icons-material/Add';
import CampaignIcon from '@mui/icons-material/Campaign';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import DeleteIcon from '@mui/icons-material/Delete';
import EditIcon from '@mui/icons-material/Edit';
import VisibilityIcon from '@mui/icons-material/Visibility';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
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
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';

import {
  deleteCampaign,
  duplicateCampaign,
  fetchCampaigns,
  updateCampaign,
} from '../api/campaigns';
import type { Campaign } from '../types';
import CampaignPreviewDialog from '../components/CampaignPreviewDialog';
import ConfirmDialog from '../components/ConfirmDialog';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { formatSchedule } from '../lib/format';

function statusChip(campaign: Campaign) {
  return campaign.status === 'active' ? (
    <Chip label={__('Active', 'goalcart')} size="small" color="success" variant="outlined" />
  ) : (
    <Chip label={__('Inactive', 'goalcart')} size="small" color="default" variant="outlined" />
  );
}

/**
 * Campaigns (Phase 10: Campaign Builder). Full campaign CRUD list:
 * name, milestones, status, priority, schedule and actions (create, edit,
 * duplicate, enable/disable, delete, preview). The builder itself lives
 * at `/campaigns/new` and `/campaigns/:id/edit`.
 */
export default function Campaigns() {
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();
  const navigate = useNavigate();

  const [pendingDelete, setPendingDelete] = useState<Campaign | null>(null);
  const [previewCampaign, setPreviewCampaign] = useState<Campaign | null>(null);

  const campaignsQuery = useQuery({
    queryKey: ['campaigns'],
    queryFn: fetchCampaigns,
  });
  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['campaigns'] });
    void queryClient.invalidateQueries({ queryKey: ['goals'] });
    // A toggled/duplicated/deleted campaign must not linger in the
    // detail cache — reopening the builder within the 60 s stale window
    // would otherwise show the pre-mutation values.
    void queryClient.invalidateQueries({ queryKey: ['campaign'] });
  };

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteCampaign(id),
    onSuccess: () => {
      notify(__('The campaign was deleted.', 'goalcart'));
      setPendingDelete(null);
      invalidate();
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
      setPendingDelete(null);
    },
  });

  const duplicateMutation = useMutation({
    mutationFn: (id: number) => duplicateCampaign(id),
    onSuccess: (copy) => {
      notify(sprintf(__('Duplicated “%s”.', 'goalcart'), copy.name));
      invalidate();
    },
    onError: (error: Error) => notify(error.message, 'error'),
  });

  const toggleMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'active' | 'inactive' }) =>
      updateCampaign(id, { status }),
    onSuccess: () => {
      notify(__('The campaign status was updated.', 'goalcart'));
      invalidate();
    },
    onError: (error: Error) => notify(error.message, 'error'),
  });

  const campaigns = campaignsQuery.data?.items ?? [];

  return (
    <PageContainer
      title={__('Campaigns', 'goalcart')}
      description={__(
        'Group goals into scheduled, prioritized milestones — e.g. free shipping at 500K, a gift at 1M, a discount at 1.5M.',
        'goalcart'
      )}
      actions={
        <Button
          variant="contained"
          startIcon={<AddIcon />}
          onClick={() => navigate('/campaigns/new')}
        >
          {__('Add campaign', 'goalcart')}
        </Button>
      }
    >
      {campaignsQuery.isError && (
        <Alert severity="error" variant="outlined">
          {campaignsQuery.error instanceof Error
            ? campaignsQuery.error.message
            : __('Could not load the campaigns.', 'goalcart')}
        </Alert>
      )}

      {campaignsQuery.isLoading ? (
        <Stack spacing={1}>
          <Skeleton variant="rounded" height={48} />
          <Skeleton variant="rounded" height={280} />
        </Stack>
      ) : !campaignsQuery.isError && campaigns.length === 0 ? (
        <EmptyState
          icon={<CampaignIcon fontSize="large" />}
          title={__('No campaigns yet', 'goalcart')}
          description={__(
            'Campaigns bundle multiple goals into scheduled, prioritized milestones. Create your first campaign to group goals around an event or season.',
            'goalcart'
          )}
          action={
            <Button
              variant="contained"
              startIcon={<AddIcon />}
              onClick={() => navigate('/campaigns/new')}
            >
              {__('Add your first campaign', 'goalcart')}
            </Button>
          }
        />
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{__('Name', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Milestones', 'goalcart')}</TableCell>
                <TableCell>{__('Status', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Priority', 'goalcart')}</TableCell>
                <TableCell>{__('Schedule', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Actions', 'goalcart')}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {campaigns.map((campaign) => (
                <TableRow key={campaign.id} hover>
                  <TableCell>
                    <Box>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {campaign.name}
                      </Typography>
                      {campaign.description && (
                        <Typography variant="caption" color="text.secondary">
                          {campaign.description}
                        </Typography>
                      )}
                    </Box>
                  </TableCell>
                  <TableCell align="right">
                    <Typography variant="body2">{campaign.goal_count}</Typography>
                  </TableCell>
                  <TableCell>
                    <Stack direction="row" spacing={1} alignItems="center">
                      {statusChip(campaign)}
                      <Tooltip
                        title={
                          campaign.status === 'active'
                            ? __('Disable', 'goalcart')
                            : __('Enable', 'goalcart')
                        }
                      >
                        <Switch
                          size="small"
                          checked={campaign.status === 'active'}
                          onChange={(_event, checked) =>
                            toggleMutation.mutate({
                              id: campaign.id,
                              status: checked ? 'active' : 'inactive',
                            })
                          }
                          inputProps={{
                            'aria-label': sprintf(
                              /* translators: %s: campaign name. */
                              __('Toggle %s', 'goalcart'),
                              campaign.name
                            ),
                          }}
                        />
                      </Tooltip>
                    </Stack>
                  </TableCell>
                  <TableCell align="right">
                    <Typography variant="body2">{campaign.priority}</Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">
                      {formatSchedule(campaign.starts_at, campaign.ends_at)}
                    </Typography>
                  </TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                      <Tooltip title={__('Preview', 'goalcart')}>
                        <IconButton
                          size="small"
                          aria-label={sprintf(__('Preview %s', 'goalcart'), campaign.name)}
                          onClick={() => setPreviewCampaign(campaign)}
                        >
                          <VisibilityIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                      <Tooltip title={__('Edit', 'goalcart')}>
                        <IconButton
                          size="small"
                          aria-label={sprintf(__('Edit %s', 'goalcart'), campaign.name)}
                          onClick={() => navigate(`/campaigns/${campaign.id}/edit`)}
                        >
                          <EditIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                      <Tooltip title={__('Duplicate', 'goalcart')}>
                        <IconButton
                          size="small"
                          aria-label={sprintf(__('Duplicate %s', 'goalcart'), campaign.name)}
                          disabled={duplicateMutation.isPending}
                          onClick={() => duplicateMutation.mutate(campaign.id)}
                        >
                          <ContentCopyIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                      <Tooltip title={__('Delete', 'goalcart')}>
                        <IconButton
                          size="small"
                          color="error"
                          aria-label={sprintf(__('Delete %s', 'goalcart'), campaign.name)}
                          onClick={() => setPendingDelete(campaign)}
                        >
                          <DeleteIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title={__('Delete this campaign?', 'goalcart')}
        description={
          pendingDelete
            ? sprintf(
                /* translators: %s: campaign name. */
                __(
                  '“%s” will be permanently deleted. Its goals are kept and detached — they can be reused by other campaigns.',
                  'goalcart'
                ),
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

      <CampaignPreviewDialog campaign={previewCampaign} onClose={() => setPreviewCampaign(null)} />
    </PageContainer>
  );
}
