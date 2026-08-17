import FlagIcon from '@mui/icons-material/Flag';
import InsightsIcon from '@mui/icons-material/Insights';
import PaymentsIcon from '@mui/icons-material/Payments';
import PercentIcon from '@mui/icons-material/Percent';
import RefreshIcon from '@mui/icons-material/Refresh';
import ShoppingCartCheckoutIcon from '@mui/icons-material/ShoppingCartCheckout';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Divider from '@mui/material/Divider';
import IconButton from '@mui/material/IconButton';
import LinearProgress from '@mui/material/LinearProgress';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, type ReactNode } from 'react';
import { Link as RouterLink } from 'react-router-dom';
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

import { fetchMissions } from '../api/missions';
import { fetchMissionPerformance, fetchRevenueOverview, fetchUpsellAnalytics } from '../api/revenue';
import { getBootData } from '../boot';
import DateRangeFilter from '../components/date-range/DateRangeFilter';
import EmptyState from '../components/EmptyState';
import KpiCard from '../components/dashboard/KpiCard';
import type { Trend } from '../components/dashboard/TrendIndicator';
import EstimatedProfitCard from '../components/revenue/EstimatedProfitCard';
import PageContainer from '../components/PageContainer';
import { useDateRange } from '../date-range/DateRangeContext';
import {
  formatCompact,
  formatCurrency,
  formatNumber,
  formatPercent,
  formatShortDay,
  percentChange,
} from '../lib/format';
import type { MissionPerformanceRow, RevenueSummary, RevenueTrendPoint, UpsellAnalyticsRow } from '../types';

const COLORS = {
  grid: '#dcdcde',
  tick: '#50575e',
  sales: '#00a32a',
  completions: '#72aee6',
};

/** One deterministic, data-backed optimization opportunity. */
interface Opportunity {
  icon: ReactNode;
  title: string;
  body: string;
  action?: { label: string; to?: string; href?: string };
}

/** Totals across the upsell analytics rows. */
interface UpsellTotals {
  impressions: number;
  clicks: number;
  adds: number;
  orders: number;
  revenue: number;
}

function sumUpsells(rows: UpsellAnalyticsRow[]): UpsellTotals {
  return rows.reduce<UpsellTotals>(
    (acc, row) => ({
      impressions: acc.impressions + row.impressions,
      clicks: acc.clicks + row.clicks,
      adds: acc.adds + row.adds,
      orders: acc.orders + row.orders,
      revenue: acc.revenue + row.revenue,
    }),
    { impressions: 0, clicks: 0, adds: 0, orders: 0, revenue: 0 }
  );
}

/**
 * Deterministic optimization opportunities (§15) — each is emitted only
 * when the underlying data supports it, and each has a concrete action.
 */
function buildOpportunities(
  summary: RevenueSummary,
  topMission: MissionPerformanceRow | null,
  upsellAssisted: number,
  productsUrl: string
): Opportunity[] {
  const opportunities: Opportunity[] = [];

  if (summary.profit_reason_code === 'missing_product_cost') {
    opportunities.push({
      icon: <WarningAmberIcon fontSize="small" color="warning" />,
      title: __('Product cost data is incomplete', 'faracart'),
      body: __(
        'Add product costs to unlock Estimated Profit — FaraCart never invents a margin.',
        'faracart'
      ),
      action: { label: __('Add costs', 'faracart'), href: productsUrl },
    });
  }

  const purchaseRate = summary.funnel.conversion_rate;
  if (summary.funnel.completed > 0 && purchaseRate !== null && purchaseRate < 0.3) {
    opportunities.push({
      icon: <WarningAmberIcon fontSize="small" color="warning" />,
      title: __('Many completions don’t become purchases', 'faracart'),
      body: sprintf(
        /* translators: %s: purchase rate. */
        __('Only %s of completed missions were followed by a purchase. Review your mission targets.', 'faracart'),
        formatPercent(purchaseRate)
      ),
      action: { label: __('Review', 'faracart'), to: '/optimization/missions' },
    });
  }

  if (upsellAssisted > 0) {
    opportunities.push({
      icon: <TrendingUpIcon fontSize="small" color="success" />,
      title: __('Upsells are assisting completions', 'faracart'),
      body: sprintf(
        /* translators: %s: number of assisted completions. */
        __('Smart Upsells assisted %s mission completions this period.', 'faracart'),
        formatNumber(upsellAssisted)
      ),
      action: { label: __('View performance', 'faracart'), to: '/optimization/upsells' },
    });
  }

  if (topMission && topMission.attributed_revenue > 0) {
    opportunities.push({
      icon: <InsightsIcon fontSize="small" color="primary" />,
      title: sprintf(
        /* translators: %s: mission name. */
        __('%s is your top performer', 'faracart'),
        topMission.name
      ),
      body: sprintf(
        /* translators: %s: attributed sales. */
        __('It generated %s in attributed sales this period.', 'faracart'),
        formatCurrency(topMission.attributed_revenue)
      ),
      action: { label: __('View missions', 'faracart'), to: '/revenue/missions' },
    });
  }

  return opportunities.slice(0, 4);
}

/** Primary trend chart: attributed sales (line) + mission completions (bar). */
function SalesTrendChart({ data }: { data: RevenueTrendPoint[] }) {
  const points = data.map((point) => ({ ...point, label: formatShortDay(point.date) }));

  return (
    <Box
      role="img"
      aria-label={__('Daily attributed sales and mission completions trend', 'faracart')}
      sx={{ width: '100%', height: 260 }}
    >
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
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
            formatter={(value: unknown, name: unknown, item?: { dataKey?: unknown }) => {
              const isMoney = item?.dataKey === 'revenue';

              return [isMoney ? formatCurrency(Number(value)) : formatNumber(Number(value)), String(name)];
            }}
          />
          <Legend wrapperStyle={{ fontSize: 12 }} />
          <Bar
            yAxisId="count"
            dataKey="completions"
            name={__('Mission Completions', 'faracart')}
            fill={COLORS.completions}
            radius={[3, 3, 0, 0]}
          />
          <Line
            yAxisId="revenue"
            dataKey="revenue"
            name={__('Attributed Sales', 'faracart')}
            stroke={COLORS.sales}
            strokeWidth={2}
            dot={false}
            activeDot={{ r: 3 }}
          />
        </ComposedChart>
      </ResponsiveContainer>
    </Box>
  );
}

/** Top missions summary card (top 5 by attributed sales) + "View all". */
function MissionPerformanceCard({ missions, loading }: { missions: MissionPerformanceRow[]; loading: boolean }) {
  return (
    <Card variant="outlined" sx={{ height: '100%' }}>
      <CardContent sx={{ height: '100%' }}>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1.5 }}>
          <Typography variant="h6" component="h2">
            {__('Mission Performance', 'faracart')}
          </Typography>
          <Button component={RouterLink} to="/revenue/missions" size="small">
            {__('View all', 'faracart')}
          </Button>
        </Box>
        {loading ? (
          <Stack spacing={2}>
            {Array.from({ length: 3 }).map((_, index) => (
              <Skeleton key={index} variant="rounded" height={40} />
            ))}
          </Stack>
        ) : missions.length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            {__('No mission performance yet.', 'faracart')}
          </Typography>
        ) : (
          <Stack spacing={1.75}>
            {missions.map((mission) => (
              <Box key={mission.mission_id}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 1, mb: 0.5 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                    {mission.name}
                  </Typography>
                  <Typography variant="caption" color="text.secondary" sx={{ whiteSpace: 'nowrap' }}>
                    {sprintf(
                      /* translators: 1: purchases, 2: sales. */
                      __('%1$s purchases · %2$s sales', 'faracart'),
                      formatNumber(mission.converted),
                      formatCurrency(mission.attributed_revenue)
                    )}
                  </Typography>
                </Box>
                <LinearProgress
                  variant="determinate"
                  value={mission.completion_rate === null ? 0 : Math.min(100, mission.completion_rate * 100)}
                  sx={{ height: 6, borderRadius: 3 }}
                />
              </Box>
            ))}
          </Stack>
        )}
      </CardContent>
    </Card>
  );
}

/** Upsell performance summary card + "View" link (§13). */
function UpsellPerformanceCard({
  totals,
  assisted,
  loading,
}: {
  totals: UpsellTotals;
  assisted: number;
  loading: boolean;
}) {
  const rows = [
    { label: __('Impressions', 'faracart'), value: formatNumber(totals.impressions) },
    { label: __('Clicks', 'faracart'), value: formatNumber(totals.clicks) },
    { label: __('Added to cart', 'faracart'), value: formatNumber(totals.adds) },
    { label: __('Purchases', 'faracart'), value: formatNumber(totals.orders) },
    { label: __('Assisted completions', 'faracart'), value: formatNumber(assisted) },
    { label: __('Attributed sales', 'faracart'), value: formatCurrency(totals.revenue) },
  ];

  return (
    <Card variant="outlined" sx={{ height: '100%' }}>
      <CardContent sx={{ height: '100%' }}>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1.5 }}>
          <Typography variant="h6" component="h2">
            {__('Upsell Performance', 'faracart')}
          </Typography>
          <Button component={RouterLink} to="/optimization/upsells" size="small">
            {__('View', 'faracart')}
          </Button>
        </Box>
        {loading ? (
          <Stack spacing={2}>
            {Array.from({ length: 3 }).map((_, index) => (
              <Skeleton key={index} variant="rounded" height={40} />
            ))}
          </Stack>
        ) : (
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 1.5 }}>
            {rows.map((row) => (
              <Box key={row.label}>
                <Typography variant="caption" color="text.secondary" component="p">
                  {row.label}
                </Typography>
                <Typography variant="h6" component="p" sx={{ m: 0, fontWeight: 600 }}>
                  {row.value}
                </Typography>
              </Box>
            ))}
          </Box>
        )}
      </CardContent>
    </Card>
  );
}

/** Optimization opportunities list (§15). */
function OpportunitiesCard({ opportunities }: { opportunities: Opportunity[] }) {
  if (opportunities.length === 0) {
    return null;
  }

  return (
    <Card variant="outlined">
      <CardContent>
        <Typography variant="h6" component="h2" gutterBottom>
          {__('Optimization Opportunities', 'faracart')}
        </Typography>
        <Stack spacing={2}>
          {opportunities.map((opportunity, index) => (
            <Box key={opportunity.title}>
              {index > 0 && <Divider sx={{ mb: 2 }} />}
              <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'flex-start' }}>
                <Box sx={{ color: 'text.secondary', mt: 0.25 }}>{opportunity.icon}</Box>
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {opportunity.title}
                  </Typography>
                  <Typography variant="body2" color="text.secondary">
                    {opportunity.body}
                  </Typography>
                </Box>
                {opportunity.action &&
                  (opportunity.action.href ? (
                    <Button
                      size="small"
                      href={opportunity.action.href}
                      target="_blank"
                      rel="noreferrer"
                      sx={{ flexShrink: 0 }}
                    >
                      {opportunity.action.label}
                    </Button>
                  ) : (
                    <Button
                      size="small"
                      component={RouterLink}
                      to={opportunity.action.to ?? '/'}
                      sx={{ flexShrink: 0 }}
                    >
                      {opportunity.action.label}
                    </Button>
                  ))}
              </Box>
            </Box>
          ))}
        </Stack>
      </CardContent>
    </Card>
  );
}

/** Skeleton that mirrors the populated dashboard layout (§19). */
function DashboardSkeleton() {
  return (
    <Stack spacing={3}>
      <Box
        sx={{
          display: 'grid',
          gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(3, 1fr)' },
          gap: 2,
        }}
      >
        {Array.from({ length: 6 }).map((_, index) => (
          <Skeleton key={index} variant="rounded" height={140} />
        ))}
      </Box>
      <Skeleton variant="rounded" height={300} />
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' }, gap: 2 }}>
        <Skeleton variant="rounded" height={220} />
        <Skeleton variant="rounded" height={220} />
      </Box>
    </Stack>
  );
}

/** First-run onboarding for a store with no missions yet (§18). */
function Onboarding() {
  const steps = [
    __('Create your first Mission', 'faracart'),
    __('Configure Smart Upsells', 'faracart'),
    __('Start collecting performance data', 'faracart'),
  ];

  return (
    <Paper variant="outlined" sx={{ p: { xs: 3, md: 5 }, textAlign: 'center' }}>
      <Box sx={{ color: 'primary.main', display: 'flex', justifyContent: 'center', mb: 1.5 }}>
        <FlagIcon sx={{ fontSize: 48 }} />
      </Box>
      <Typography variant="h5" component="h2" gutterBottom>
        {__('Welcome to FaraCart', 'faracart')}
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 480, mx: 'auto' }}>
        {__('Increase cart value with Missions and Smart Upsells.', 'faracart')}
      </Typography>
      <Box
        component="ol"
        sx={{
          m: 0,
          mt: 3,
          p: 0,
          listStyle: 'none',
          display: 'flex',
          flexDirection: 'column',
          gap: 1,
          alignItems: 'center',
        }}
      >
        {steps.map((step, index) => (
          <Box component="li" key={step} sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Box
              sx={{
                width: 24,
                height: 24,
                borderRadius: '50%',
                bgcolor: 'primary.main',
                color: 'primary.contrastText',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: 13,
                fontWeight: 600,
              }}
            >
              {index + 1}
            </Box>
            <Typography variant="body2">{step}</Typography>
          </Box>
        ))}
      </Box>
      <Button component={RouterLink} to="/missions/new" variant="contained" sx={{ mt: 3 }}>
        {__('Create Mission', 'faracart')}
      </Button>
    </Paper>
  );
}

/**
 * Dashboard — the Level-1 business summary (UICHANGES.md §3, §4).
 *
 * Answers "what happened?" at a glance: sales, estimated profit, mission
 * completions, purchased orders, purchase rate and average order value,
 * a compact sales + completions trend, the top missions, the upsell summary
 * and deterministic optimization opportunities. Deep analytics live in
 * Sales Performance; this page stays a decision-making summary.
 *
 * Data comes from the existing revenue endpoints (no business-logic
 * changes): overview (+ previous period for the trend context), mission
 * performance and upsell analytics, all sliced by the shared date range.
 */
export default function Dashboard() {
  const { range, comparison } = useDateRange();
  const queryClient = useQueryClient();
  const boot = getBootData();

  const missionsQuery = useQuery({
    queryKey: ['missions', 'summary'],
    queryFn: () => fetchMissions({ per_page: 100 }),
  });

  const overviewQuery = useQuery({
    queryKey: ['revenue', 'overview', { from: range.from, to: range.to }],
    queryFn: () => fetchRevenueOverview({ from: range.from, to: range.to }),
  });

  const comparisonQuery = useQuery({
    queryKey: ['revenue', 'overview', { from: comparison.from, to: comparison.to }],
    queryFn: () => fetchRevenueOverview({ from: comparison.from, to: comparison.to }),
  });

  const missionsPerfQuery = useQuery({
    queryKey: ['revenue', 'missions', { from: range.from, to: range.to }],
    queryFn: () => fetchMissionPerformance({ from: range.from, to: range.to }),
  });

  const upsellsQuery = useQuery({
    queryKey: ['revenue', 'upsell-analytics', { from: range.from, to: range.to, limit: 50 }],
    queryFn: () => fetchUpsellAnalytics({ from: range.from, to: range.to, limit: 50 }),
  });

  const summary = overviewQuery.data?.summary;
  const hasMissions = (missionsQuery.data?.total ?? 0) > 0;
  // No analytics yet = no mission views and no attributed orders this period.
  const hasData = summary !== undefined && (summary.funnel.views > 0 || summary.orders > 0);

  const topMissions = useMemo(() => {
    const items = missionsPerfQuery.data?.items ?? [];

    return [...items]
      .sort((a, b) => b.attributed_revenue - a.attributed_revenue || b.converted - a.converted)
      .slice(0, 5);
  }, [missionsPerfQuery.data]);

  const upsellTotals = useMemo(() => sumUpsells(upsellsQuery.data ?? []), [upsellsQuery.data]);

  const upsellAssisted = useMemo(
    () => (missionsPerfQuery.data?.items ?? []).reduce((sum, mission) => sum + mission.upsell_assisted, 0),
    [missionsPerfQuery.data]
  );

  const previous = comparisonQuery.data?.summary;
  const productsUrl = `${boot.adminUrl}edit.php?post_type=product`;

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['revenue'] });
    queryClient.invalidateQueries({ queryKey: ['missions'] });
  };

  // Trends are only derived from real previous-period values; a zero or
  // missing previous value yields "—" (never a fabricated percentage).
  const salesTrend: Trend = {
    change: summary ? percentChange(previous?.mission_driven_revenue, summary.mission_driven_revenue) : null,
  };
  const completionsTrend: Trend = {
    change: summary ? percentChange(previous?.funnel.completed, summary.funnel.completed) : null,
  };
  const ordersTrend: Trend = {
    change: summary ? percentChange(previous?.orders, summary.orders) : null,
  };
  const profitTrend: Trend | null =
    summary &&
    summary.profit_available &&
    previous?.profit_available &&
    summary.profit_impact !== null &&
    previous.profit_impact !== null
      ? { change: percentChange(previous.profit_impact, summary.profit_impact) }
      : null;

  const opportunities =
    summary === undefined
      ? []
      : buildOpportunities(summary, topMissions[0] ?? null, upsellAssisted, productsUrl);

  return (
    <PageContainer
      title={__('Overview', 'faracart')}
      description={__('A business summary of your Missions and Smart Upsells.', 'faracart')}
      actions={
        <>
          <DateRangeFilter />
          <Tooltip title={__('Refresh', 'faracart')}>
            <IconButton size="small" onClick={refresh} aria-label={__('Refresh', 'faracart')}>
              <RefreshIcon fontSize="small" />
            </IconButton>
          </Tooltip>
        </>
      }
    >
      {overviewQuery.isError ? (
        <Alert
          severity="error"
          variant="outlined"
          action={
            <Button color="inherit" size="small" onClick={refresh}>
              {__('Try again', 'faracart')}
            </Button>
          }
        >
          {__('We couldn’t load your analytics. Your Missions are still working normally.', 'faracart')}
        </Alert>
      ) : overviewQuery.isLoading || missionsQuery.isLoading ? (
        <DashboardSkeleton />
      ) : !hasMissions ? (
        <Onboarding />
      ) : !hasData ? (
        <EmptyState
          icon={<TrendingUpIcon fontSize="large" />}
          title={__('Your analytics are getting ready', 'faracart')}
          description={__(
            'Once customers interact with your Missions, performance data will appear here.',
            'faracart'
          )}
        />
      ) : summary && overviewQuery.data ? (
        <Stack spacing={3}>
          {/* Level 1 — business summary KPIs (§3, §7). */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(3, 1fr)' },
              gap: 2,
            }}
          >
            <KpiCard
              label={__('Sales Attributed to FaraCart', 'faracart')}
              value={formatCurrency(summary.mission_driven_revenue)}
              icon={<PaymentsIcon fontSize="small" />}
              trend={salesTrend}
              hint={sprintf(
                /* translators: %s: purchased orders. */
                __('%s purchased orders', 'faracart'),
                formatNumber(summary.orders)
              )}
            />
            <EstimatedProfitCard
              profitImpact={summary.profit_impact}
              profitAvailable={summary.profit_available}
              profitReason={summary.profit_reason}
              profitReasonCode={summary.profit_reason_code}
              profitDetails={summary.profit_details}
              costCoverage={summary.cost_coverage}
              costSources={summary.cost_sources}
              storeHasCostData={summary.store_has_cost_data}
              trend={profitTrend}
            />
            <KpiCard
              label={__('Mission Completions', 'faracart')}
              value={formatNumber(summary.funnel.completed)}
              icon={<FlagIcon fontSize="small" />}
              trend={completionsTrend}
              hint={sprintf(
                /* translators: %s: completion rate. */
                __('%s completion rate', 'faracart'),
                formatPercent(summary.funnel.completion_rate)
              )}
            />
            <KpiCard
              label={__('Purchased Orders', 'faracart')}
              value={formatNumber(summary.orders)}
              icon={<ShoppingCartCheckoutIcon fontSize="small" />}
              trend={ordersTrend}
              hint={__('after FaraCart interaction', 'faracart')}
            />
            <KpiCard
              label={__('Purchase Rate', 'faracart')}
              value={formatPercent(summary.funnel.conversion_rate)}
              icon={<PercentIcon fontSize="small" />}
              hint={__('purchases per completed mission', 'faracart')}
            />
            <KpiCard
              label={__('Average Order Value', 'faracart')}
              value={formatCurrency(overviewQuery.data.aov.exposed_aov)}
              icon={<InsightsIcon fontSize="small" />}
              hint={__('mission-exposed customers', 'faracart')}
            />
          </Box>

          {/* Primary chart — sales & mission performance over time (§11). */}
          <Card variant="outlined">
            <CardContent>
              <Typography variant="h6" component="h2" gutterBottom>
                {__('Sales & Mission Performance', 'faracart')}
              </Typography>
              <SalesTrendChart data={overviewQuery.data.trend} />
            </CardContent>
          </Card>

          {/* Level 2 — mission + upsell performance (§12, §13). */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' },
              gap: 2,
              alignItems: 'stretch',
            }}
          >
            <MissionPerformanceCard missions={topMissions} loading={missionsPerfQuery.isLoading} />
            <UpsellPerformanceCard
              totals={upsellTotals}
              assisted={upsellAssisted}
              loading={upsellsQuery.isLoading}
            />
          </Box>

          {/* Level 4 — optimization opportunities (§15). */}
          <OpportunitiesCard opportunities={opportunities} />
        </Stack>
      ) : null}
    </PageContainer>
  );
}
