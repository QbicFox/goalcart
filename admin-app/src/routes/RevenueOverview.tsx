import AddCardIcon from '@mui/icons-material/AddCard';
import InsightsIcon from '@mui/icons-material/Insights';
import PaymentsIcon from '@mui/icons-material/Payments';
import PercentIcon from '@mui/icons-material/Percent';
import SavingsIcon from '@mui/icons-material/Savings';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
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
import { useMemo, useState, type ReactNode } from 'react';
import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip as ChartTooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { fetchRevenueOverview } from '../api/revenue';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCompact, formatCurrency, formatNumber, formatPercent, formatShortDay } from '../lib/format';

const COLORS = {
  primary: '#2271b1',
  primaryLight: '#72aee6',
  success: '#00a32a',
  warning: '#996800',
  grid: '#dcdcde',
  tick: '#50575e',
};

function KpiCard({ label, value, icon, hint }: { label: string; value: string; icon: ReactNode; hint?: string }) {
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
 * Revenue Overview (Phase 33.6).
 *
 * The Revenue Optimization landing page: attribution KPIs (goal-driven /
 * assisted / influenced revenue, orders, reward cost, estimated profit),
 * the daily revenue trend, the AOV impact comparison and shipping stats —
 * all from `GET /goalcart/v1/revenue/overview` and sliced by the shared
 * date range + goal filter.
 */
export default function RevenueOverview() {
  const { range } = useDateRange();
  const [goalId, setGoalId] = useState<number>(0);

  const query = useQuery({
    queryKey: ['revenue', 'overview', { from: range.from, to: range.to, goalId }],
    queryFn: () =>
      fetchRevenueOverview({
        from: range.from,
        to: range.to,
        goal_id: goalId || undefined,
      }),
  });

  const data = query.data;
  const summary = data?.summary;
  const isEmpty =
    !query.isLoading && !query.isError && data !== undefined && summary?.orders === 0 && summary?.funnel.views === 0;

  const trendData = useMemo(
    () =>
      (data?.trend ?? []).map((point) => ({
        ...point,
        label: formatShortDay(point.date),
      })),
    [data]
  );

  return (
    <PageContainer
      title={__('Revenue Overview', 'goalcart')}
      description={__(
        'How much additional revenue Goal Cart influenced, and at what estimated cost.',
        'goalcart'
      )}
    >
      <RevenueToolbar goalId={goalId} onGoalChange={setGoalId} />

      {query.isError && (
        <Alert severity="error" variant="outlined">
          {query.error instanceof Error
            ? query.error.message
            : __('Could not load the revenue overview.', 'goalcart')}
        </Alert>
      )}

      {query.isLoading ? (
        <Stack spacing={2}>
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)', xl: 'repeat(7, 1fr)' },
              gap: 2,
            }}
          >
            {Array.from({ length: 7 }).map((_, index) => (
              <Skeleton key={index} variant="rounded" height={112} />
            ))}
          </Box>
          <Skeleton variant="rounded" height={300} />
          <Skeleton variant="rounded" height={220} />
        </Stack>
      ) : isEmpty ? (
        <EmptyState
          icon={<TrendingUpIcon fontSize="large" />}
          title={__('No revenue data yet', 'goalcart')}
          description={__(
            'No goal-influenced orders were attributed in this range. Widen the date range or check that event tracking and revenue attribution are enabled.',
            'goalcart'
          )}
        />
      ) : data && summary ? (
        <>
          {/* KPI cards */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)', xl: 'repeat(7, 1fr)' },
              gap: 2,
            }}
          >
            <KpiCard
              label={__('Goal-influenced revenue', 'goalcart')}
              value={formatCurrency(summary.goal_influenced_revenue)}
              icon={<PaymentsIcon fontSize="small" />}
              hint={sprintf(
                /* translators: 1: attributed orders. */
                __('%1$s attributed orders', 'goalcart'),
                formatNumber(summary.orders)
              )}
            />
            <KpiCard
              label={__('Goal-driven revenue', 'goalcart')}
              value={formatCurrency(summary.goal_driven_revenue)}
              icon={<TrendingUpIcon fontSize="small" />}
              hint={__('Direct incremental value', 'goalcart')}
            />
            <KpiCard
              label={__('Incremental cart value', 'goalcart')}
              value={formatCurrency(data.incremental_cart_value.average)}
              icon={<AddCardIcon fontSize="small" />}
              hint={__('Average lift per session', 'goalcart')}
            />
            <KpiCard
              label={__('AOV impact', 'goalcart')}
              value={`${(data.aov.percentage_change * 100).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`}
              icon={<PercentIcon fontSize="small" />}
              hint={data.aov.comparison_available ? __('vs store-wide AOV', 'goalcart') : __('Observed only', 'goalcart')}
            />
            <KpiCard
              label={__('Goal conversion rate', 'goalcart')}
              value={formatPercent(summary.funnel.conversion_rate ?? 0)}
              icon={<InsightsIcon fontSize="small" />}
              hint={__('Orders per completion', 'goalcart')}
            />
            <KpiCard
              label={__('Reward cost', 'goalcart')}
              value={formatCurrency(summary.reward_cost)}
              icon={<SavingsIcon fontSize="small" />}
              hint={
                summary.reward_cost_available
                  ? __('Estimated', 'goalcart')
                  : __('Data unavailable', 'goalcart')
              }
            />
            <KpiCard
              label={__('Estimated profit', 'goalcart')}
              value={
                summary.profit_available && summary.profit_impact !== null
                  ? formatCurrency(summary.profit_impact)
                  : '—'
              }
              icon={<AddCardIcon fontSize="small" />}
              hint={
                summary.profit_available
                  ? __('After reward cost', 'goalcart')
                  : (summary.profit_reason ?? __('Requires margin data', 'goalcart'))
              }
            />
          </Box>

          {/* Daily trend */}
          <Card variant="outlined">
            <CardContent>
              <Typography variant="h6" component="h3" gutterBottom>
                {__('Revenue trend', 'goalcart')}
              </Typography>
              <Box role="img" aria-label={__('Daily revenue and funnel trend', 'goalcart')} sx={{ width: '100%', height: 300 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <ComposedChart data={trendData} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke={COLORS.grid} vertical={false} />
                    <XAxis
                      dataKey="label"
                      tick={{ fontSize: 11, fill: COLORS.tick }}
                      tickLine={false}
                      minTickGap={28}
                    />
                    <YAxis
                      yAxisId="count"
                      tick={{ fontSize: 11, fill: COLORS.tick }}
                      tickLine={false}
                      axisLine={false}
                      allowDecimals={false}
                      width={40}
                    />
                    <YAxis
                      yAxisId="revenue"
                      orientation="right"
                      tick={{ fontSize: 11, fill: COLORS.tick }}
                      tickLine={false}
                      axisLine={false}
                      width={52}
                      tickFormatter={(value: number) => formatCompact(value)}
                    />
                    <ChartTooltip
                      contentStyle={{ borderRadius: 4, fontSize: 13 }}
                      formatter={(value: unknown, name: unknown) => {
                        const label = String(name);
                        const isMoney = label === __('Revenue', 'goalcart') || label === __('Incremental', 'goalcart');

                        return [isMoney ? formatCurrency(Number(value)) : formatNumber(Number(value)), label];
                      }}
                    />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    <Bar
                      yAxisId="count"
                      dataKey="completions"
                      name={__('Completions', 'goalcart')}
                      fill={COLORS.primaryLight}
                      radius={[3, 3, 0, 0]}
                    />
                    <Bar
                      yAxisId="count"
                      dataKey="conversions"
                      name={__('Conversions', 'goalcart')}
                      fill={COLORS.primary}
                      radius={[3, 3, 0, 0]}
                    />
                    <Line
                      yAxisId="revenue"
                      dataKey="revenue"
                      name={__('Revenue', 'goalcart')}
                      stroke={COLORS.success}
                      strokeWidth={2}
                      dot={false}
                      activeDot={{ r: 3 }}
                    />
                    <Line
                      yAxisId="revenue"
                      dataKey="incremental_revenue"
                      name={__('Incremental', 'goalcart')}
                      stroke={COLORS.warning}
                      strokeWidth={2}
                      strokeDasharray="4 3"
                      dot={false}
                      activeDot={{ r: 3 }}
                    />
                  </ComposedChart>
                </ResponsiveContainer>
              </Box>
            </CardContent>
          </Card>

          {/* AOV + shipping panels */}
          <Box sx={{ display: 'grid', gridTemplateColumns: { md: 'repeat(2, 1fr)' }, gap: 2, alignItems: 'stretch' }}>
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography variant="h6" component="h3" gutterBottom>
                {__('Average order value — observed impact', 'goalcart')}
              </Typography>
              <Stack spacing={1.25}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Store-wide AOV', 'goalcart')}
                  </Typography>
                  <Typography variant="body1" sx={{ fontWeight: 600 }}>
                    {formatCurrency(data.aov.overall_aov)}
                  </Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Goal-exposed AOV', 'goalcart')}
                  </Typography>
                  <Typography variant="body1" sx={{ fontWeight: 600 }}>
                    {formatCurrency(data.aov.exposed_aov)}
                  </Typography>
                </Box>
                {data.aov.non_exposed_aov !== null && (
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                    <Typography variant="body2" color="text.secondary">
                      {__('Non-exposed AOV', 'goalcart')}
                    </Typography>
                    <Typography variant="body1" sx={{ fontWeight: 600 }}>
                      {formatCurrency(data.aov.non_exposed_aov)}
                    </Typography>
                  </Box>
                )}
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Change', 'goalcart')}
                  </Typography>
                  <Chip
                    size="small"
                    variant="outlined"
                    color={data.aov.absolute_change >= 0 ? 'success' : 'error'}
                    label={sprintf(
                      /* translators: 1: absolute change, 2: percentage change. */
                      __('%1$s (%2$s)', 'goalcart'),
                      formatCurrency(data.aov.absolute_change),
                      formatPercent(data.aov.percentage_change)
                    )}
                  />
                </Box>
                <Typography variant="caption" color="text.secondary">
                  {__('Labeled observed impact — Goal Cart does not claim causality.', 'goalcart')}
                </Typography>
              </Stack>
            </Paper>

            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography variant="h6" component="h3" gutterBottom>
                {__('Shipping', 'goalcart')}
              </Typography>
              {!data.shipping.available ? (
                <Typography variant="body2" color="text.secondary">
                  {__('Shipping data is not available in this window.', 'goalcart')}
                </Typography>
              ) : (
                <Stack spacing={1.25}>
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                    <Typography variant="body2" color="text.secondary">
                      {__('Average shipping', 'goalcart')}
                    </Typography>
                    <Typography variant="body1" sx={{ fontWeight: 600 }}>
                      {formatCurrency(data.shipping.average_shipping)}
                    </Typography>
                  </Box>
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                    <Typography variant="body2" color="text.secondary">
                      {__('Free shipping share', 'goalcart')}
                    </Typography>
                    <Typography variant="body1" sx={{ fontWeight: 600 }}>
                      {formatPercent(
                        data.shipping.orders && data.shipping.orders > 0
                          ? data.shipping.free_shipping_orders / data.shipping.orders
                          : 0
                      )}
                    </Typography>
                  </Box>
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                    <Typography variant="body2" color="text.secondary">
                      {__('Orders with shipping', 'goalcart')}
                    </Typography>
                    <Typography variant="body1" sx={{ fontWeight: 600 }}>
                      {formatNumber(data.shipping.orders_with_shipping)}
                    </Typography>
                  </Box>
                  {Object.entries(data.shipping.by_method).map(([method, stats]) => (
                    <Box key={method} sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                      <Typography variant="body2" color="text.secondary" noWrap>
                        {method}
                      </Typography>
                      <Typography variant="body2">
                        {formatCurrency(stats.average)} · {formatNumber(stats.orders)}
                      </Typography>
                    </Box>
                  ))}
                </Stack>
              )}
            </Paper>
          </Box>
        </>
      ) : null}
    </PageContainer>
  );
}
