import AccountTreeIcon from '@mui/icons-material/AccountTree';
import CallSplitIcon from '@mui/icons-material/CallSplit';
import PaymentsIcon from '@mui/icons-material/Payments';
import { useQuery } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useState, type ReactNode } from 'react';

import { fetchRevenueAttribution } from '../api/revenue';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import FunnelVisual from '../components/revenue/FunnelVisual';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber } from '../lib/format';
import type { RevenueAttributionPayload } from '../types';

function MetricCard({
  label,
  value,
  icon,
  hint,
}: {
  label: string;
  value: string;
  icon: ReactNode;
  hint?: string;
}) {
  return (
    <Card variant="outlined">
      <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1, color: 'text.secondary' }}>
          {icon}
          <Typography variant="body2" color="text.secondary" noWrap>
            {label}
          </Typography>
        </Box>
        <Typography variant="h5" component="p" sx={{ m: 0, fontWeight: 600 }}>
          {value}
        </Typography>
        {hint && (
          <Typography variant="caption" color="text.secondary">
            {hint}
          </Typography>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * Attribution Dashboard (Phase 33.6).
 *
 * The revenue funnel (views → progressed → completed → converted), the
 * direct vs assisted attribution model split, incremental cart value and
 * profit impact — from `GET /goalcart/v1/revenue/attribution`.
 */
export default function AttributionDashboard() {
  const { range } = useDateRange();
  const [goalId, setGoalId] = useState<number>(0);

  const query = useQuery({
    queryKey: ['revenue', 'attribution', { from: range.from, to: range.to, goalId }],
    queryFn: () =>
      fetchRevenueAttribution({
        from: range.from,
        to: range.to,
        goal_id: goalId || undefined,
      }),
  });

  const data: RevenueAttributionPayload | undefined = query.data;
  const summary = data?.summary;
  const isEmpty =
    !query.isLoading && !query.isError && data !== undefined && summary?.orders === 0 && summary?.funnel.views === 0;

  return (
    <PageContainer
      title={__('Revenue Attribution', 'goalcart')}
      description={__(
        'The funnel and the direct vs assisted models behind FaraCart’s attributed revenue.',
        'goalcart'
      )}
    >
      <RevenueToolbar goalId={goalId} onGoalChange={setGoalId} />

      {query.isError && (
        <Alert severity="error" variant="outlined">
          {query.error instanceof Error
            ? query.error.message
            : __('Could not load the attribution dashboard.', 'goalcart')}
        </Alert>
      )}

      {query.isLoading ? (
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={260} />
          <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' }, gap: 2 }}>
            <Skeleton variant="rounded" height={120} />
            <Skeleton variant="rounded" height={120} />
            <Skeleton variant="rounded" height={120} />
          </Box>
        </Stack>
      ) : isEmpty ? (
        <EmptyState
          icon={<CallSplitIcon fontSize="large" />}
          title={__('No attributed orders yet', 'goalcart')}
          description={__(
            'Orders are attributed once they reach a revenue-producing status. No goal-influenced orders were found in this range.',
            'goalcart'
          )}
        />
      ) : data && summary ? (
        <>
          <Box sx={{ display: 'grid', gridTemplateColumns: { md: 'repeat(3, 1fr)' }, gap: 2 }}>
            {/* UICHANGES.md §30 — user-facing terminology for the attribution
                model: Direct revenue / Assisted revenue / Influenced sales
                (the same labels the Sales Performance advanced section and
                the Goal Detail drawer use). Never the internal field names. */}
            <MetricCard
              label={__('Direct revenue', 'goalcart')}
              value={formatCurrency(summary.goal_driven_revenue)}
              icon={<PaymentsIcon fontSize="small" />}
              hint={__('Direct incremental value', 'goalcart')}
            />
            <MetricCard
              label={__('Assisted revenue', 'goalcart')}
              value={formatCurrency(summary.goal_assisted_revenue)}
              icon={<AccountTreeIcon fontSize="small" />}
              hint={__('Pure-assisted order totals', 'goalcart')}
            />
            <MetricCard
              label={__('Influenced sales', 'goalcart')}
              value={formatCurrency(summary.goal_influenced_revenue)}
              icon={<CallSplitIcon fontSize="small" />}
              hint={sprintf(
                /* translators: 1: attributed orders. */
                __('%1$s distinct orders', 'goalcart'),
                formatNumber(summary.orders)
              )}
            />
          </Box>

          <Box sx={{ display: 'grid', gridTemplateColumns: { md: 'repeat(2, 1fr)' }, gap: 2, alignItems: 'stretch' }}>
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography variant="h6" component="h3" gutterBottom>
                {__('Funnel', 'goalcart')}
              </Typography>
              <FunnelVisual funnel={summary.funnel} />
            </Paper>

            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography variant="h6" component="h3" gutterBottom>
                {__('Incremental cart value', 'goalcart')}
              </Typography>
              <Stack spacing={1.25}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Average lift per session', 'goalcart')}
                  </Typography>
                  <Typography variant="body1" sx={{ fontWeight: 600 }}>
                    {formatCurrency(data.incremental_cart_value.average)}
                  </Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Total lift', 'goalcart')}
                  </Typography>
                  <Typography variant="body1" sx={{ fontWeight: 600 }}>
                    {formatCurrency(data.incremental_cart_value.total)}
                  </Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Average baseline', 'goalcart')}
                  </Typography>
                  <Typography variant="body1" sx={{ fontWeight: 600 }}>
                    {formatCurrency(data.incremental_cart_value.average_baseline)}
                  </Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Sessions', 'goalcart')}
                  </Typography>
                  <Typography variant="body1" sx={{ fontWeight: 600 }}>
                    {formatNumber(data.incremental_cart_value.sessions)}
                  </Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Data sufficiency', 'goalcart')}
                  </Typography>
                  <Chip
                    size="small"
                    variant="outlined"
                    color={
                      data.incremental_cart_value.data_sufficiency === 'high'
                        ? 'success'
                        : data.incremental_cart_value.data_sufficiency === 'medium'
                          ? 'warning'
                          : 'default'
                    }
                    label={data.incremental_cart_value.data_sufficiency}
                  />
                </Box>
              </Stack>
            </Paper>
          </Box>

          <Paper variant="outlined" sx={{ p: 2 }}>
            <Typography variant="h6" component="h3" gutterBottom>
              {__('Estimated profit impact', 'goalcart')}
            </Typography>
            {summary.profit_available && summary.profit_impact !== null ? (
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
                <Typography variant="h4" component="p" sx={{ m: 0, fontWeight: 600 }}>
                  {formatCurrency(summary.profit_impact)}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  {sprintf(
                    /* translators: 1: reward cost. */
                    __('after %1$s estimated reward cost', 'goalcart'),
                    formatCurrency(summary.reward_cost)
                  )}
                </Typography>
              </Box>
            ) : (
              <Typography variant="body2" color="text.secondary">
                {summary.profit_reason ??
                  __('Profit impact is unavailable because product cost data is not available.', 'goalcart')}{' '}
                {__('Revenue-only analytics continue to work.', 'goalcart')}
              </Typography>
            )}
          </Paper>
        </>
      ) : null}
    </PageContainer>
  );
}
