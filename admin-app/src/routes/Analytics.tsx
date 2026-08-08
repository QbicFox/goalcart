import AddShoppingCartIcon from '@mui/icons-material/AddShoppingCart';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import InsightsIcon from '@mui/icons-material/Insights';
import MouseIcon from '@mui/icons-material/Mouse';
import PaymentsIcon from '@mui/icons-material/Payments';
import PercentIcon from '@mui/icons-material/Percent';
import ShoppingCartIcon from '@mui/icons-material/ShoppingCart';
import VisibilityIcon from '@mui/icons-material/Visibility';
import { useQuery } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState, type ReactNode } from 'react';
import {
  Bar,
  BarChart,
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip as ChartTooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { fetchAnalytics } from '../api/analytics';
import { fetchCampaigns } from '../api/campaigns';
import { fetchGoals } from '../api/goals';
import { searchProducts } from '../api/search';
import { getBootData } from '../boot';
import DateRangeFilter from '../components/date-range/DateRangeFilter';
import EmptyState from '../components/EmptyState';
import EntityAutocomplete from '../components/goal-builder/EntityAutocomplete';
import PageContainer from '../components/PageContainer';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber } from '../lib/format';
import type { AnalyticsRewardFilter } from '../types';

/** Chart palette — WP admin blues + semantic accents. */
const COLORS = {
  primary: '#2271b1',
  primaryLight: '#72aee6',
  success: '#00a32a',
  grid: '#dcdcde',
  tick: '#50575e',
};

/** Reward filter dropdown options (matches Reward::types() + all). */
const REWARD_OPTIONS: Array<{ value: AnalyticsRewardFilter; label: string }> = [
  { value: '', label: __('All rewards', 'goalcart') },
  { value: 'free_shipping', label: __('Free shipping', 'goalcart') },
  { value: 'percent_discount', label: __('% discount', 'goalcart') },
  { value: 'fixed_discount', label: __('Fixed discount', 'goalcart') },
  { value: 'free_gift', label: __('Free gift', 'goalcart') },
  { value: 'coupon', label: __('Coupon', 'goalcart') },
];

/** Format a 0–1 rate as a percentage string. */
function formatPercent(value: number): string {
  return `${(value * 100).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
}

/** Short day label for chart ticks (e.g. "Aug 1"). */
function formatShortDay(dateStr: string): string {
  const boot = getBootData();

  try {
    return new Intl.DateTimeFormat(boot.locale.replace('_', '-'), {
      month: 'short',
      day: 'numeric',
    }).format(new Date(`${dateStr}T12:00:00`));
  } catch {
    return dateStr.slice(5);
  }
}

/** Compact number for axis ticks (e.g. "1.2K"). */
function formatCompact(value: number): string {
  try {
    return new Intl.NumberFormat(undefined, {
      notation: 'compact',
      maximumFractionDigits: 1,
    }).format(value);
  } catch {
    return String(Math.round(value));
  }
}

interface KpiCardProps {
  label: string;
  value: string;
  icon: ReactNode;
}

/** One KPI card with the label + icon above the value. */
function KpiCard({ label, value, icon }: KpiCardProps) {
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
      </CardContent>
    </Card>
  );
}

/**
 * Analytics (Phase 17: Analytics Dashboard).
 *
 * The full measurement page behind the Phase 16 event pipeline:
 *
 *  - a filter toolbar (date range + campaign / goal / reward / product)
 *    driven by the shared DateRangeContext (P17-T02)
 *  - seven KPI cards (impressions, completions, completion rate, revenue
 *    influenced, average cart value, suggestion CTR, add-to-cart rate)
 *  - a daily trend ComposedChart (impressions + completions bars with a
 *    revenue line, P17-T03)
 *  - top campaigns (horizontal bar chart), top goals (ranked completion
 *    bars) and a top suggested products table
 *
 * All data comes from `GET /goalcart/v1/analytics` and every slice
 * refetches the payload in one query.
 */
export default function Analytics() {
  const { range } = useDateRange();
  const [campaignId, setCampaignId] = useState<number>(0);
  const [goalId, setGoalId] = useState<number>(0);
  const [reward, setReward] = useState<AnalyticsRewardFilter>('');
  const [productId, setProductId] = useState<number>(0);

  const analyticsQuery = useQuery({
    queryKey: ['analytics', { from: range.from, to: range.to, campaignId, goalId, reward, productId }],
    queryFn: () =>
      fetchAnalytics({
        from: range.from,
        to: range.to,
        campaign_id: campaignId || undefined,
        goal_id: goalId || undefined,
        reward: reward || undefined,
        product_id: productId || undefined,
      }),
  });

  // Filter dropdown options come from the existing list endpoints.
  const campaignsQuery = useQuery({
    queryKey: ['campaigns', 'filter-options'],
    queryFn: () => fetchCampaigns(),
  });

  const goalsQuery = useQuery({
    queryKey: ['goals', 'filter-options'],
    queryFn: () => fetchGoals({ per_page: 100 }),
  });

  const data = analyticsQuery.data;
  const summary = data?.summary;
  const isEmpty = !analyticsQuery.isLoading && !analyticsQuery.isError && !summary?.impressions;

  // Pre-format the trend series with short day labels for the X axis.
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
      title={__('Analytics', 'goalcart')}
      description={__(
        'Measure whether Goal Cart actually increases your average order value.',
        'goalcart'
      )}
    >
      {/* Filter toolbar (P17-T02) */}
      <Stack
        direction={{ xs: 'column', lg: 'row' }}
        spacing={1.5}
        alignItems={{ xs: 'stretch', lg: 'center' }}
        flexWrap="wrap"
        useFlexGap
      >
        <DateRangeFilter />

        <TextField
          select
          label={__('Campaign', 'goalcart')}
          size="small"
          sx={{ minWidth: 190, flexGrow: { lg: 1 } }}
          value={campaignId}
          onChange={(event) => setCampaignId(Number(event.target.value))}
        >
          <MenuItem value={0}>{__('All campaigns', 'goalcart')}</MenuItem>
          {(campaignsQuery.data?.items ?? []).map((campaign) => (
            <MenuItem key={campaign.id} value={campaign.id}>
              {campaign.name}
            </MenuItem>
          ))}
        </TextField>

        <TextField
          select
          label={__('Goal', 'goalcart')}
          size="small"
          sx={{ minWidth: 190, flexGrow: { lg: 1 } }}
          value={goalId}
          onChange={(event) => setGoalId(Number(event.target.value))}
        >
          <MenuItem value={0}>{__('All goals', 'goalcart')}</MenuItem>
          {(goalsQuery.data?.items ?? []).map((goal) => (
            <MenuItem key={goal.id} value={goal.id}>
              {goal.name}
            </MenuItem>
          ))}
        </TextField>

        <TextField
          select
          label={__('Reward', 'goalcart')}
          size="small"
          sx={{ minWidth: 170 }}
          value={reward}
          onChange={(event) => setReward(event.target.value as AnalyticsRewardFilter)}
        >
          {REWARD_OPTIONS.map((option) => (
            <MenuItem key={option.value} value={option.value}>
              {option.label}
            </MenuItem>
          ))}
        </TextField>

        <Box sx={{ minWidth: 220, maxWidth: { lg: 260 }, flexGrow: { lg: 1 } }}>
          <EntityAutocomplete
            label={__('Product', 'goalcart')}
            value={productId ? [productId] : []}
            onChange={(ids) => setProductId(ids[0] ?? 0)}
            search={searchProducts}
            multiple={false}
            placeholder={__('All products', 'goalcart')}
          />
        </Box>
      </Stack>

      {analyticsQuery.isError && (
        <Alert severity="error" variant="outlined">
          {analyticsQuery.error instanceof Error
            ? analyticsQuery.error.message
            : __('Could not load the analytics.', 'goalcart')}
        </Alert>
      )}

      {analyticsQuery.isLoading ? (
        <Stack spacing={2}>
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)', xl: 'repeat(7, 1fr)' },
              gap: 2,
            }}
          >
            {Array.from({ length: 7 }).map((_, index) => (
              <Skeleton key={index} variant="rounded" height={108} />
            ))}
          </Box>
          <Skeleton variant="rounded" height={300} />
          <Skeleton variant="rounded" height={240} />
        </Stack>
      ) : isEmpty ? (
        <EmptyState
          icon={<InsightsIcon fontSize="large" />}
          title={__('No analytics yet', 'goalcart')}
          description={__(
            'No goal impressions were recorded in this range. Widen the date range or check that event tracking is enabled on the storefront.',
            'goalcart'
          )}
        />
      ) : data && summary ? (
        <>
          {/* KPI cards (P17-T01) */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(3, 1fr)', xl: 'repeat(7, 1fr)' },
              gap: 2,
            }}
          >
            <KpiCard
              label={__('Impressions', 'goalcart')}
              value={formatNumber(summary.impressions)}
              icon={<VisibilityIcon fontSize="small" />}
            />
            <KpiCard
              label={__('Completions', 'goalcart')}
              value={formatNumber(summary.completions)}
              icon={<CheckCircleOutlineIcon fontSize="small" />}
            />
            <KpiCard
              label={__('Completion rate', 'goalcart')}
              value={formatPercent(summary.completion_rate)}
              icon={<PercentIcon fontSize="small" />}
            />
            <KpiCard
              label={__('Revenue influenced', 'goalcart')}
              value={formatCurrency(summary.revenue_influenced)}
              icon={<PaymentsIcon fontSize="small" />}
            />
            <KpiCard
              label={__('Avg. cart value', 'goalcart')}
              value={formatCurrency(summary.average_cart_value)}
              icon={<ShoppingCartIcon fontSize="small" />}
            />
            <KpiCard
              label={__('Suggestion CTR', 'goalcart')}
              value={formatPercent(summary.suggestion_ctr)}
              icon={<MouseIcon fontSize="small" />}
            />
            <KpiCard
              label={__('Add-to-cart rate', 'goalcart')}
              value={formatPercent(summary.suggestion_add_to_cart_rate)}
              icon={<AddShoppingCartIcon fontSize="small" />}
            />
          </Box>

          {/* Daily trend chart (P17-T03) */}
          <Card variant="outlined">
            <CardContent>
              <Typography variant="h6" component="h3" gutterBottom>
                {__('Activity over time', 'goalcart')}
              </Typography>
              <Box
                role="img"
                aria-label={__('Daily impressions, completions and revenue trend', 'goalcart')}
                sx={{ width: '100%', height: 300 }}
              >
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
                        const formatted =
                          label === __('Revenue', 'goalcart')
                            ? formatCurrency(Number(value))
                            : formatNumber(Number(value));

                        return [formatted, label];
                      }}
                    />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    <Bar
                      yAxisId="count"
                      dataKey="impressions"
                      name={__('Impressions', 'goalcart')}
                      fill={COLORS.primaryLight}
                      radius={[3, 3, 0, 0]}
                    />
                    <Bar
                      yAxisId="count"
                      dataKey="completions"
                      name={__('Completions', 'goalcart')}
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
                  </ComposedChart>
                </ResponsiveContainer>
              </Box>
            </CardContent>
          </Card>

          {/* Top campaigns + top goals */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { md: 'repeat(2, 1fr)' },
              gap: 2,
              alignItems: 'stretch',
            }}
          >
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography variant="h6" component="h3" gutterBottom>
                {__('Top campaigns', 'goalcart')}
              </Typography>
              {data.top_campaigns.length === 0 ? (
                <Typography variant="body2" color="text.secondary">
                  {__('No campaign activity in this range.', 'goalcart')}
                </Typography>
              ) : (
                <Box
                  role="img"
                  aria-label={__('Top campaigns by completions', 'goalcart')}
                  sx={{ width: '100%', height: data.top_campaigns.length * 46 + 24 }}
                >
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                      data={data.top_campaigns}
                      layout="vertical"
                      margin={{ top: 0, right: 12, bottom: 0, left: 0 }}
                    >
                      <CartesianGrid strokeDasharray="3 3" stroke={COLORS.grid} horizontal={false} />
                      <XAxis
                        type="number"
                        tick={{ fontSize: 11, fill: COLORS.tick }}
                        tickLine={false}
                        axisLine={false}
                        allowDecimals={false}
                      />
                      <YAxis
                        type="category"
                        dataKey="name"
                        width={130}
                        tick={{ fontSize: 11, fill: COLORS.tick }}
                        tickLine={false}
                        axisLine={false}
                      />
                      <ChartTooltip
                        contentStyle={{ borderRadius: 4, fontSize: 13 }}
                        formatter={(value: unknown, name: unknown) => [
                          formatNumber(Number(value)),
                          String(name),
                        ]}
                      />
                      <Bar
                        dataKey="completions"
                        name={__('Completions', 'goalcart')}
                        fill={COLORS.primary}
                        radius={[0, 4, 4, 0]}
                      />
                    </BarChart>
                  </ResponsiveContainer>
                </Box>
              )}
            </Paper>

            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography variant="h6" component="h3" gutterBottom>
                {__('Top goals', 'goalcart')}
              </Typography>
              {data.top_goals.length === 0 ? (
                <Typography variant="body2" color="text.secondary">
                  {__('No goal activity in this range.', 'goalcart')}
                </Typography>
              ) : (
                <Stack spacing={1.75}>
                  {data.top_goals.map((goal, index) => (
                    <Box key={goal.id}>
                      <Box
                        sx={{
                          display: 'flex',
                          justifyContent: 'space-between',
                          gap: 1,
                          mb: 0.5,
                          alignItems: 'baseline',
                        }}
                      >
                        <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                          {index + 1}. {goal.name}
                        </Typography>
                        <Typography variant="caption" color="text.secondary" sx={{ whiteSpace: 'nowrap' }}>
                          {sprintf(
                            /* translators: 1: completions, 2: impressions. */
                            __('%1$s of %2$s', 'goalcart'),
                            formatNumber(goal.completions),
                            formatNumber(goal.impressions)
                          )}
                        </Typography>
                      </Box>
                      <LinearProgress
                        variant="determinate"
                        value={Math.min(100, goal.completion_rate * 100)}
                        sx={{ height: 6, borderRadius: 3 }}
                      />
                    </Box>
                  ))}
                </Stack>
              )}
            </Paper>
          </Box>

          {/* Top suggested products */}
          <Paper variant="outlined" sx={{ overflow: 'hidden' }}>
            <Box sx={{ p: 2, pb: 1 }}>
              <Typography variant="h6" component="h3">
                {__('Top suggested products', 'goalcart')}
              </Typography>
            </Box>
            {data.top_suggested_products.length === 0 ? (
              <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>
                {__('No suggestion activity in this range.', 'goalcart')}
              </Typography>
            ) : (
              <TableContainer>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>{__('Product', 'goalcart')}</TableCell>
                      <TableCell align="right">{__('Impressions', 'goalcart')}</TableCell>
                      <TableCell align="right">{__('Clicks', 'goalcart')}</TableCell>
                      <TableCell align="right">{__('Added', 'goalcart')}</TableCell>
                      <TableCell align="right">{__('CTR', 'goalcart')}</TableCell>
                      <TableCell align="right">{__('Add-to-cart', 'goalcart')}</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {data.top_suggested_products.map((product) => (
                      <TableRow key={product.product_id} hover>
                        <TableCell sx={{ fontWeight: 600 }}>{product.name}</TableCell>
                        <TableCell align="right">{formatNumber(product.impressions)}</TableCell>
                        <TableCell align="right">{formatNumber(product.clicks)}</TableCell>
                        <TableCell align="right">
                          <Chip
                            size="small"
                            variant="outlined"
                            color={product.added > 0 ? 'success' : 'default'}
                            label={formatNumber(product.added)}
                          />
                        </TableCell>
                        <TableCell align="right">{formatPercent(product.ctr)}</TableCell>
                        <TableCell align="right">{formatPercent(product.add_to_cart_rate)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
          </Paper>
        </>
      ) : null}
    </PageContainer>
  );
}
