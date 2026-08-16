import InsightsIcon from '@mui/icons-material/Insights';
import LocalShippingIcon from '@mui/icons-material/LocalShipping';
import PaymentsIcon from '@mui/icons-material/Payments';
import PercentIcon from '@mui/icons-material/Percent';
import ShoppingCartCheckoutIcon from '@mui/icons-material/ShoppingCartCheckout';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import { useQuery } from '@tanstack/react-query';
import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
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
import KpiCard from '../components/dashboard/KpiCard';
import EstimatedProfitCard from '../components/revenue/EstimatedProfitCard';
import FunnelVisual from '../components/revenue/FunnelVisual';
import PageContainer from '../components/PageContainer';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import StatRow from '../components/revenue/StatRow';
import { useDateRange } from '../date-range/DateRangeContext';
import {
  formatCompact,
  formatCurrency,
  formatNumber,
  formatPercent,
  formatShortDay,
  percentChange,
} from '../lib/format';
import type { AovAnalysis, RevenueSummary } from '../types';

const COLORS = {
  primary: '#2271b1',
  primaryLight: '#72aee6',
  success: '#00a32a',
  warning: '#996800',
  grid: '#dcdcde',
  tick: '#50575e',
};

/** Signed percentage (e.g. +8.7% / -3.1%) for the basket-increase KPI. */
function formatSignedPercent(value: number): string {
  const formatted = formatPercent(Math.abs(value));
  return value > 0 ? `+${formatted}` : value < 0 ? `-${formatted}` : formatPercent(value);
}

/** Deterministic plain-English insights (§15) — only shown when the data supports them. */
function buildInsights(summary: RevenueSummary, aov: AovAnalysis): Insight[] {
  const insights: Insight[] = [];
  const funnel = summary.funnel;

  if (summary.orders > 0) {
    insights.push({
      icon: <TrendingUpIcon fontSize="small" />,
      title: __('Good performance', 'faracart'),
      body: sprintf(
        /* translators: 1: number of purchased orders. */
        __('FaraCart influenced %1$s purchases during this period.', 'faracart'),
        formatNumber(summary.orders)
      ),
    });
  }

  if (aov.comparison_available && Math.abs(aov.percentage_change) >= 0.001) {
    const change = formatSignedPercent(aov.percentage_change);
    const magnitude = formatPercent(Math.abs(aov.percentage_change));

    insights.push({
      icon: <PercentIcon fontSize="small" />,
      title: __('Average basket', 'faracart'),
      body:
        aov.percentage_change >= 0
          ? sprintf(
              /* translators: 1: signed percentage. */
              __('Customers interacting with FaraCart spent %1$s more per order on average (observed impact).', 'faracart'),
              change
            )
          : sprintf(
              /* translators: 1: percentage. */
              __('Customers interacting with FaraCart spent %1$s less per order on average (observed impact).', 'faracart'),
              magnitude
            ),
    });
  }

  if (funnel.conversion_rate !== null && funnel.completed > 0) {
    if (funnel.conversion_rate < 0.3) {
      insights.push({
        icon: <InsightsIcon fontSize="small" />,
        title: __('Optimization opportunity', 'faracart'),
        body: sprintf(
          /* translators: 1: purchase rate percentage. */
          __('Only %1$s of completed goals were followed by an attributed purchase.', 'faracart'),
          formatPercent(funnel.conversion_rate)
        ),
      });
    } else {
      insights.push({
        icon: <InsightsIcon fontSize="small" />,
        title: __('Purchases', 'faracart'),
        body: sprintf(
          /* translators: 1: purchase rate percentage. */
          __('%1$s of completed goals were followed by an attributed purchase.', 'faracart'),
          formatPercent(funnel.conversion_rate)
        ),
      });
    }
  }

  if (summary.profit_available && summary.profit_impact !== null) {
    insights.push({
      icon: <PaymentsIcon fontSize="small" />,
      title: __('Estimated profit', 'faracart'),
      body: sprintf(
        /* translators: 1: estimated profit currency value. */
        __('FaraCart generated an estimated profit of %1$s after reward and shipping costs.', 'faracart'),
        formatCurrency(summary.profit_impact)
      ),
    });
  } else if (summary.orders > 0 && summary.profit_reason_code === 'missing_product_cost') {
    insights.push({
      icon: <PaymentsIcon fontSize="small" />,
      title: __('Profit not estimated yet', 'faracart'),
      body: __('Add product cost data to see the estimated profit of FaraCart.', 'faracart'),
    });
  }

  return insights.slice(0, 3);
}

interface Insight {
  icon: ReactNode;
  title: string;
  body: string;
}

/** Which trend series are visible (default: sales + purchased orders, §14). */
type TrendMetric = 'sales' | 'orders' | 'completions' | 'incremental';

const TREND_PRIMARY: Array<{ value: TrendMetric; label: string }> = [
  { value: 'sales', label: __('Attributed Sales', 'faracart') },
  { value: 'orders', label: __('Purchased Orders', 'faracart') },
  { value: 'completions', label: __('Goal Completions', 'faracart') },
];

/**
 * The one canonical funnel (UICHANGES.md §18): views → progressed →
 * completed → purchased. Every stage shows its count and the percentage
 * carried from the previous stage, so the largest drop-off reads at a
 * glance. Only the Goal-detail drawer renders this same funnel again,
 * scoped to a specific goal (§18/§24).
 */
function CustomerJourneyFunnel({ funnel }: { funnel: RevenueSummary['funnel'] }) {
  return (
    <Card variant="outlined">
      <CardContent>
        <Typography variant="h6" component="h3" gutterBottom>
          {__('Customer Journey', 'faracart')}
        </Typography>
        <FunnelVisual
          showTransitions
          funnel={{
            views: funnel.views,
            progressed: funnel.progressed,
            completed: funnel.completed,
            converted: funnel.converted,
            completion_rate: funnel.completion_rate,
            conversion_rate: funnel.conversion_rate,
          }}
        />
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
          {__(
            'A completion means the customer reached the goal target. A purchase means a qualifying order was actually associated with the goal.',
            'faracart'
          )}
        </Typography>
      </CardContent>
    </Card>
  );
}

/**
 * Sales Performance (Phase 4 redesign of the Revenue Overview).
 *
 * Answers the store owner's questions at a glance — how much did Goal
 * Cart sell, how many customers purchased, how profitable was it — with
 * four business KPI cards (Improvement.md §5–§13), a simplified trend
 * (§14), deterministic insight cards (§15) and the technical attribution
 * detail moved behind an expandable drawer (§30). Same payload, same
 * route: `GET /faracart/v1/revenue/overview` sliced by the shared date
 * range + goal filter.
 */
export default function RevenueOverview() {
  const { range, comparison } = useDateRange();
  const [goalId, setGoalId] = useState<number>(0);
  const [visibleMetrics, setVisibleMetrics] = useState<TrendMetric[]>(['sales', 'orders']);
  const [showAdvancedTrend, setShowAdvancedTrend] = useState(false);

  const query = useQuery({
    queryKey: ['revenue', 'overview', { from: range.from, to: range.to, goalId }],
    queryFn: () =>
      fetchRevenueOverview({
        from: range.from,
        to: range.to,
        goal_id: goalId || undefined,
      }),
  });

  // Previous equal-length period — the "vs previous period" context for
  // the KPI trend indicators (§7/§8).
  const comparisonQuery = useQuery({
    queryKey: ['revenue', 'overview', { from: comparison.from, to: comparison.to, goalId }],
    queryFn: () =>
      fetchRevenueOverview({
        from: comparison.from,
        to: comparison.to,
        goal_id: goalId || undefined,
      }),
  });

  const data = query.data;
  const summary = data?.summary;
  const previous = comparisonQuery.data?.summary;
  const hasData = data !== undefined && summary !== undefined;

  // §44 — two distinct empty states: no interactions at all vs interactions
  // without any attributed purchase. "No purchases yet" is a real signal
  // (customers engage but do not buy), not the same as "no analytics data".
  const isEmpty = !query.isLoading && !query.isError && hasData && summary.orders === 0 && summary.funnel.views === 0;
  const hasNoPurchases =
    !isEmpty && !query.isLoading && !query.isError && hasData && summary.funnel.views > 0 && summary.orders === 0;

  const trendData = useMemo(
    () =>
      (data?.trend ?? []).map((point) => ({
        ...point,
        label: formatShortDay(point.date),
      })),
    [data]
  );

  const insights = useMemo(
    () => (data && summary ? buildInsights(summary, data.aov) : []),
    [data, summary]
  );

  const toggleMetric = (metric: TrendMetric) => {
    setVisibleMetrics((current) => {
      // Keep at least one series visible.
      if (current.includes(metric) && current.length === 1) {
        return current;
      }
      return current.includes(metric) ? current.filter((m) => m !== metric) : [...current, metric];
    });
  };

  return (
    <PageContainer
      title={__('Sales Performance', 'faracart')}
      description={__(
        'How did FaraCart perform? Sales attributed, purchased orders, average basket and estimated profit.',
        'faracart'
      )}
    >
      <RevenueToolbar goalId={goalId} onGoalChange={setGoalId} />

      {query.isError && (
        <Alert severity="error" variant="outlined">
          {query.error instanceof Error
            ? query.error.message
            : __('Could not load the sales overview.', 'faracart')}
        </Alert>
      )}

      {query.isLoading ? (
        <Stack spacing={2}>
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' },
              gap: 2,
            }}
          >
            {Array.from({ length: 4 }).map((_, index) => (
              <Skeleton key={index} variant="rounded" height={148} />
            ))}
          </Box>
          <Skeleton variant="rounded" height={300} />
          <Skeleton variant="rounded" height={180} />
        </Stack>
      ) : isEmpty ? (
        <EmptyState
          icon={<TrendingUpIcon fontSize="large" />}
          title={__('No sales data yet', 'faracart')}
          description={__(
            'Once customers start interacting with your goals, FaraCart will show sales, purchases and profit insights here.',
            'faracart'
          )}
        />
      ) : hasNoPurchases ? (
        <EmptyState
          icon={<ShoppingCartCheckoutIcon fontSize="large" />}
          title={__('No purchases yet', 'faracart')}
          description={__(
            'Customers are interacting with your goals, but no attributed purchases have been recorded for this period.',
            'faracart'
          )}
        />
      ) : data && summary ? (
        <Stack spacing={2}>
          {/* Four primary KPI cards (§5–§13). */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' },
              gap: 2,
            }}
          >
            <KpiCard
              label={__('Sales Attributed to FaraCart', 'faracart')}
              value={formatCurrency(summary.goal_driven_revenue)}
              icon={<PaymentsIcon fontSize="small" />}
              trend={{ change: percentChange(previous?.goal_driven_revenue, summary.goal_driven_revenue) }}
              hint={sprintf(
                /* translators: 1: purchased orders. */
                __('%1$s purchased orders', 'faracart'),
                formatNumber(summary.orders)
              )}
            >
              <HowCalculated summary={summary} />
            </KpiCard>

            <KpiCard
              label={__('Average Basket Increase', 'faracart')}
              value={
                data.aov.comparison_available
                  ? formatSignedPercent(data.aov.percentage_change)
                  : '—'
              }
              icon={<PercentIcon fontSize="small" />}
              hint={__('Observed impact', 'faracart')}
              accent={
                data.aov.comparison_available
                  ? data.aov.percentage_change >= 0
                    ? 'success'
                    : 'error'
                  : 'default'
              }
            >
              <BasketCompare aov={data.aov} />
            </KpiCard>

            <KpiCard
              label={__('Purchased Orders', 'faracart')}
              value={formatNumber(summary.orders)}
              icon={<ShoppingCartCheckoutIcon fontSize="small" />}
              trend={{ change: percentChange(previous?.orders, summary.orders) }}
              hint={__('after FaraCart interaction', 'faracart')}
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
              trend={
                summary.profit_available &&
                previous?.profit_available &&
                summary.profit_impact !== null &&
                previous.profit_impact !== null
                  ? { change: percentChange(previous.profit_impact, summary.profit_impact) }
                  : null
              }
            />
          </Box>

          {/* Sales performance trend (§14): sales + orders by default, with
              completions and an optional advanced incremental toggle. */}
          <Card variant="outlined">
            <CardContent>
              <Box
                sx={{
                  display: 'flex',
                  flexWrap: 'wrap',
                  gap: 1,
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  mb: 1,
                }}
              >
                <Typography variant="h6" component="h3">
                  {__('FaraCart Sales Performance', 'faracart')}
                </Typography>
                <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                  <ToggleButtonGroup
                    size="small"
                    value={visibleMetrics}
                    onChange={(_, next: TrendMetric[]) => next && next.length > 0 && setVisibleMetrics(next)}
                    aria-label={__('Trend metrics', 'faracart')}
                  >
                    {TREND_PRIMARY.map((option) => (
                      <ToggleButton key={option.value} value={option.value}>
                        {option.label}
                      </ToggleButton>
                    ))}
                  </ToggleButtonGroup>
                  <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
                    <Chip
                      size="small"
                      variant="outlined"
                      label={__('Advanced', 'faracart')}
                      onClick={() => setShowAdvancedTrend((shown) => !shown)}
                    />
                    {showAdvancedTrend && (
                      <ToggleButton
                        size="small"
                        value="incremental"
                        selected={visibleMetrics.includes('incremental')}
                        onChange={() => toggleMetric('incremental')}
                      >
                        {__('Additional Sales Value', 'faracart')}
                      </ToggleButton>
                    )}
                  </Stack>
                </Stack>
              </Box>

              <Box
                role="img"
                aria-label={__('Daily attributed sales and purchased orders trend', 'faracart')}
                sx={{ width: '100%', height: 300 }}
              >
                <ResponsiveContainer width="100%" height="100%">
                  <ComposedChart data={trendData} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke={COLORS.grid} vertical={false} />
                    <XAxis dataKey="label" tick={{ fontSize: 11, fill: COLORS.tick }} tickLine={false} minTickGap={28} />
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
                        const label = String(name);
                        // Detect money by the series data key, not the
                        // translated label — robust to locale changes.
                        const isMoney =
                          item?.dataKey === 'revenue' || item?.dataKey === 'incremental_revenue';

                        return [isMoney ? formatCurrency(Number(value)) : formatNumber(Number(value)), label];
                      }}
                    />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    {visibleMetrics.includes('completions') && (
                      <Bar
                        yAxisId="count"
                        dataKey="completions"
                        name={__('Goal Completions', 'faracart')}
                        fill={COLORS.primaryLight}
                        radius={[3, 3, 0, 0]}
                      />
                    )}
                    {visibleMetrics.includes('orders') && (
                      <Line
                        yAxisId="count"
                        dataKey="conversions"
                        name={__('Purchased Orders', 'faracart')}
                        stroke={COLORS.primary}
                        strokeWidth={2}
                        dot={false}
                        activeDot={{ r: 3 }}
                      />
                    )}
                    {visibleMetrics.includes('sales') && (
                      <Line
                        yAxisId="revenue"
                        dataKey="revenue"
                        name={__('Attributed Sales', 'faracart')}
                        stroke={COLORS.success}
                        strokeWidth={2}
                        dot={false}
                        activeDot={{ r: 3 }}
                      />
                    )}
                    {visibleMetrics.includes('incremental') && (
                      <Line
                        yAxisId="revenue"
                        dataKey="incremental_revenue"
                        name={__('Additional Sales Value', 'faracart')}
                        stroke={COLORS.warning}
                        strokeWidth={2}
                        strokeDasharray="4 3"
                        dot={false}
                        activeDot={{ r: 3 }}
                      />
                    )}
                  </ComposedChart>
                </ResponsiveContainer>
              </Box>
            </CardContent>
          </Card>

          {/* Customer journey — the canonical funnel (§18): views →
              progressed → completed → purchased, with the percentage each
              stage carries from the previous one so the biggest drop-off is
              visible at a glance. */}
          <CustomerJourneyFunnel funnel={summary.funnel} />

          {/* What happened? — deterministic insight cards (§15). */}
          {insights.length > 0 && (
            <Card variant="outlined">
              <CardContent>
                <Typography variant="h6" component="h3" gutterBottom>
                  {__('What happened?', 'faracart')}
                </Typography>
                <Box
                  sx={{
                    display: 'grid',
                    gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' },
                    gap: 2,
                  }}
                >
                  {insights.map((insight) => (
                    <Paper key={insight.title} variant="outlined" sx={{ p: 1.5 }}>
                      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, color: 'text.secondary', mb: 0.5 }}>
                        {insight.icon}
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {insight.title}
                        </Typography>
                      </Box>
                      <Typography variant="body2">{insight.body}</Typography>
                    </Paper>
                  ))}
                </Box>
              </CardContent>
            </Card>
          )}

          {/* Advanced attribution drawer (§30) + observed-impact disclaimer. */}
          <Accordion disableGutters square sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1, boxShadow: 'none' }}>
            <AccordionSummary expandIcon={<ExpandMoreIcon />}>
              <Typography variant="h6" component="h3" sx={{ fontSize: '1rem' }}>
                {__('Advanced attribution', 'faracart')}
              </Typography>
            </AccordionSummary>
            <AccordionDetails>
              <Stack spacing={1.25}>
                <StatRow
                  label={__('Direct revenue', 'faracart')}
                  value={formatCurrency(summary.goal_driven_revenue)}
                  explanation={__(
                    'Revenue from the incremental value of orders where customers progressed toward or completed a goal before ordering.',
                    'faracart'
                  )}
                />
                <StatRow
                  label={__('Assisted revenue', 'faracart')}
                  value={formatCurrency(summary.goal_assisted_revenue)}
                  explanation={__(
                    'Order totals from orders that were only exposed to a goal, never progressed.',
                    'faracart'
                  )}
                />
                <StatRow
                  label={__('Influenced sales', 'faracart')}
                  value={formatCurrency(summary.goal_influenced_revenue)}
                  explanation={__(
                    'Order totals of every order associated with a goal — distinct orders, never double counted.',
                    'faracart'
                  )}
                />
                <StatRow
                  label={__('Incremental cart value', 'faracart')}
                  value={formatCurrency(data.incremental_cart_value.average)}
                  explanation={__(
                    'Average cart value after goal exposure minus the value at first exposure, per session.',
                    'faracart'
                  )}
                />
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 2 }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Attributed orders', 'faracart')}
                  </Typography>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {formatNumber(summary.orders)}
                  </Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 2 }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Attribution window', 'faracart')}
                  </Typography>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {__('30 days before the order', 'faracart')}
                  </Typography>
                </Box>
                <Divider />
                <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', color: 'text.secondary' }}>
                  <LocalShippingIcon fontSize="small" />
                  <Typography variant="caption">
                    {__('AOV comparisons are observed impact — they do not prove that FaraCart caused the difference.', 'faracart')}
                  </Typography>
                </Box>
              </Stack>
            </AccordionDetails>
          </Accordion>
        </Stack>
      ) : null}
    </PageContainer>
  );
}

/** "How is this calculated?" expander on the attributed-sales KPI (§5). */
function HowCalculated({ summary }: { summary: RevenueSummary }) {
  return (
    <Accordion
      disableGutters
      square
      sx={{ boxShadow: 'none', border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
    >
      <AccordionSummary expandIcon={<ExpandMoreIcon />} sx={{ '& .MuiAccordionSummary-content': { m: 0, py: 0.5 } }}>
        <Typography variant="body2">{__('How is this calculated?', 'faracart')}</Typography>
      </AccordionSummary>
      <AccordionDetails sx={{ pt: 0 }}>
        <Stack spacing={0.75}>
          <StatRow
            label={__('Direct revenue', 'faracart')}
            value={formatCurrency(summary.goal_driven_revenue)}
          />
          <StatRow
            label={__('Assisted revenue', 'faracart')}
            value={formatCurrency(summary.goal_assisted_revenue)}
          />
          <StatRow
            label={__('Influenced sales', 'faracart')}
            value={formatCurrency(summary.goal_influenced_revenue)}
          />
          <Typography variant="caption" color="text.secondary" component="p">
            {__(
              'Incremental revenue is the direct revenue shown above — the additional order value the goals moved. Attribution follows the FaraCart model: progressed/completed goals are direct, exposure-only goals are assisted, and every associated order is counted once.',
              'faracart'
            )}
          </Typography>
        </Stack>
      </AccordionDetails>
    </Accordion>
  );
}

/** "Compare" expander on the basket-increase KPI (§6). */
function BasketCompare({ aov }: { aov: AovAnalysis }) {
  return (
    <Accordion
      disableGutters
      square
      sx={{ boxShadow: 'none', border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
    >
      <AccordionSummary expandIcon={<ExpandMoreIcon />} sx={{ '& .MuiAccordionSummary-content': { m: 0, py: 0.5 } }}>
        <Typography variant="body2">{__('Compare', 'faracart')}</Typography>
      </AccordionSummary>
      <AccordionDetails sx={{ pt: 0 }}>
        {!aov.comparison_available ? (
          <Typography variant="body2" color="text.secondary">
            {__('Store-wide comparison is not available in this window.', 'faracart')}
          </Typography>
        ) : (
          <Stack spacing={0.75}>
            <StatRow label={__('Store average', 'faracart')} value={formatCurrency(aov.overall_aov)} />
            <StatRow label={__('Goal-exposed', 'faracart')} value={formatCurrency(aov.exposed_aov)} />
            <StatRow label={__('Difference', 'faracart')} value={formatCurrency(aov.absolute_change)} />
            <StatRow
              label={__('Percentage', 'faracart')}
              value={formatSignedPercent(aov.percentage_change)}
            />
            <Typography variant="caption" color="text.secondary" component="p">
              {__('Observed impact — this comparison does not prove that FaraCart caused the difference.', 'faracart')}
            </Typography>
          </Stack>
        )}
      </AccordionDetails>
    </Accordion>
  );
}

