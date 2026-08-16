import CheckCircleOutlineOutlinedIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import InsightsIcon from '@mui/icons-material/Insights';
import LeaderboardIcon from '@mui/icons-material/Leaderboard';
import LocalShippingIcon from '@mui/icons-material/LocalShipping';
import PaymentsIcon from '@mui/icons-material/Payments';
import PercentIcon from '@mui/icons-material/Percent';
import ShoppingCartCheckoutIcon from '@mui/icons-material/ShoppingCartCheckout';
import VisibilityIcon from '@mui/icons-material/Visibility';
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
import TableSortLabel from '@mui/material/TableSortLabel';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
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
import NumberPagination from '../components/NumberPagination';
import EntityAutocomplete from '../components/goal-builder/EntityAutocomplete';
import EstimatedProfitCard from '../components/revenue/EstimatedProfitCard';
import FunnelVisual from '../components/revenue/FunnelVisual';
import StatRow from '../components/revenue/StatRow';
import PageContainer from '../components/PageContainer';
import { useDateRange } from '../date-range/DateRangeContext';
import {
  formatCompact,
  formatCurrency,
  formatNumber,
  formatPercent,
  formatPercentValue,
} from '../lib/format';
import type { AnalyticsRewardFilter, AnalyticsSummary, GoalPerformanceRow } from '../types';

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
  { value: '', label: __('All rewards', 'faracart') },
  { value: 'free_shipping', label: __('Free shipping', 'faracart') },
  { value: 'percent_discount', label: __('% discount', 'faracart') },
  { value: 'fixed_discount', label: __('Fixed discount', 'faracart') },
  { value: 'free_gift', label: __('Free gift', 'faracart') },
  { value: 'coupon', label: __('Coupon', 'faracart') },
];

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

/** Full date label for the "Analyzing:" caption (§29). */
function formatAnalyzingDate(dateStr: string): string {
  const boot = getBootData();

  try {
    return new Intl.DateTimeFormat(boot.locale.replace('_', '-'), {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    }).format(new Date(`${dateStr}T12:00:00`));
  } catch {
    return dateStr;
  }
}

interface KpiCardProps {
  label: string;
  value: string;
  icon: ReactNode;
  hint?: string;
  tooltip?: string;
}

/** One KPI card with the label + icon above the value. */
function KpiCard({ label, value, icon, hint, tooltip }: KpiCardProps) {
  const content = (
    <Card variant="outlined" sx={{ height: '100%' }}>
      <CardContent
        sx={{ p: 2, '&:last-child': { pb: 2 }, display: 'flex', flexDirection: 'column', gap: 1 }}
      >
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, color: 'text.secondary' }}>
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

  return tooltip ? (
    <Tooltip title={tooltip} arrow>
      <Box>{content}</Box>
    </Tooltip>
  ) : (
    content
  );
}

/** Sortable comparison-table columns (§27). */
type ComparisonSortKey =
  | 'name'
  | 'views'
  | 'completed'
  | 'converted'
  | 'conversion_rate'
  | 'attributed_revenue'
  | 'profit_impact';

interface ComparisonColumn {
  key: ComparisonSortKey;
  label: string;
  align: 'left' | 'right';
  tooltip?: string;
}

const COMPARISON_COLUMNS: ComparisonColumn[] = [
  { key: 'name', label: __('Goal', 'faracart'), align: 'left' },
  { key: 'views', label: __('Views', 'faracart'), align: 'right' },
  { key: 'completed', label: __('Completed', 'faracart'), align: 'right' },
  {
    key: 'converted',
    label: __('Purchased', 'faracart'),
    align: 'right',
    tooltip: __(
      'A qualifying WooCommerce order was actually associated with this goal — a purchase, not a goal completion.',
      'faracart'
    ),
  },
  {
    key: 'conversion_rate',
    label: __('Purchase Rate', 'faracart'),
    align: 'right',
    tooltip: __(
      'Percentage of completed goals that were followed by an attributed purchase.',
      'faracart'
    ),
  },
  {
    key: 'attributed_revenue',
    label: __('Sales', 'faracart'),
    align: 'right',
    tooltip: __(
      'Sales attributed to FaraCart — the incremental order value driven by this goal.',
      'faracart'
    ),
  },
  {
    key: 'profit_impact',
    label: __('Estimated Profit', 'faracart'),
    align: 'right',
    tooltip: __(
      'Estimated, not guaranteed — based on available product cost, reward and shipping data.',
      'faracart'
    ),
  },
];

/** Numeric sort value — unavailable profit/rate sorts last, never first. */
function comparisonSortValue(row: GoalPerformanceRow, key: ComparisonSortKey): number | string {
  if (key === 'profit_impact') {
    return row.profit_available && row.profit_impact !== null
      ? row.profit_impact
      : Number.NEGATIVE_INFINITY;
  }
  if (key === 'conversion_rate') {
    return row.conversion_rate === null ? Number.NEGATIVE_INFINITY : row.conversion_rate;
  }
  const value = row[key];
  return typeof value === 'number' ? value : String(value);
}

interface Insight {
  icon: ReactNode;
  title: string;
  body: string;
}

/** Deterministic drop-off / outcome insights (§26) — only shown when the
 *  data supports them, never claiming causality. */
function buildInsights(summary: AnalyticsSummary, comparison: GoalPerformanceRow[]): Insight[] {
  const insights: Insight[] = [];
  const funnel = summary.funnel;

  if (!funnel) {
    return insights;
  }

  // Largest drop-off: customers who saw a goal but never progressed.
  if (funnel.views > 0 && funnel.progressed < funnel.views) {
    const dropOff = (funnel.views - funnel.progressed) / funnel.views;
    insights.push({
      icon: <InsightsIcon fontSize="small" />,
      title: __('Largest drop-off', 'faracart'),
      body: sprintf(
        /* translators: 1: percentage. */
        __('%1$s of customers who viewed a goal did not progress toward it.', 'faracart'),
        formatPercent(dropOff)
      ),
    });
  }

  // Completion → purchase conversion.
  if (funnel.completed > 0 && funnel.conversion_rate !== null) {
    if (funnel.conversion_rate < 0.3) {
      insights.push({
        icon: <PercentIcon fontSize="small" />,
        title: __('Purchase conversion', 'faracart'),
        body: sprintf(
          /* translators: 1: percentage. */
          __(
            'Completion is strong, but purchase conversion is weak — only %1$s of completed goals were followed by an attributed purchase.',
            'faracart'
          ),
          formatPercent(funnel.conversion_rate)
        ),
      });
    } else {
      insights.push({
        icon: <PercentIcon fontSize="small" />,
        title: __('Purchases', 'faracart'),
        body: sprintf(
          /* translators: 1: percentage. */
          __('%1$s of completed goals were followed by an attributed purchase.', 'faracart'),
          formatPercent(funnel.conversion_rate)
        ),
      });
    }
  }

  // Best performer by attributed sales.
  if (comparison.length > 0) {
    const best = comparison.reduce((a, b) => (b.attributed_revenue > a.attributed_revenue ? b : a));

    if (best.attributed_revenue > 0) {
      insights.push({
        icon: <LeaderboardIcon fontSize="small" />,
        title: __('Best performer', 'faracart'),
        body: sprintf(
          /* translators: 1: goal name, 2: attributed sales amount. */
          __('%1$s generated the highest attributed sales (%2$s).', 'faracart'),
          best.name,
          formatCurrency(best.attributed_revenue)
        ),
      });
    }
  }

  // Profit guidance.
  if (summary.profit_available && summary.estimated_profit !== null) {
    insights.push({
      icon: <PaymentsIcon fontSize="small" />,
      title: __('Estimated profit', 'faracart'),
      body: sprintf(
        /* translators: 1: estimated profit amount. */
        __(
          'FaraCart generated an estimated profit of %1$s after reward and shipping costs.',
          'faracart'
        ),
        formatCurrency(summary.estimated_profit)
      ),
    });
  } else if (
    (summary.purchased_orders ?? 0) > 0 &&
    summary.profit_reason_code === 'missing_product_cost'
  ) {
    insights.push({
      icon: <PaymentsIcon fontSize="small" />,
      title: __('Profit not estimated yet', 'faracart'),
      body: __('Add product cost data to see the estimated profit of FaraCart.', 'faracart'),
    });
  }

  return insights.slice(0, 3);
}

/**
 * Analytics — Goal Conversion & Purchase Analysis (Phase 6 redesign of
 * the Phase 17 dashboard).
 *
 * Answers "what happens after customers see and complete my goals?" — the
 * purchase funnel (views → progressed → completed → purchased, §23), the
 * completion-vs-purchase comparison (§25), per-goal purchase outcomes
 * (§27), deterministic drop-off insights (§26) and the advanced
 * attribution details behind an expander (§30). The legacy interaction
 * metrics (trend chart, top campaigns, top suggested products) are
 * preserved but moved behind a "Detailed activity" accordion — nothing is
 * removed, the business outcomes lead.
 *
 * All data still comes from `GET /faracart/v1/analytics` (same endpoint,
 * extended payload — legacy fields untouched).
 */
export default function Analytics() {
  const { range } = useDateRange();
  const [campaignId, setCampaignId] = useState<number>(0);
  const [goalId, setGoalId] = useState<number>(0);
  const [reward, setReward] = useState<AnalyticsRewardFilter>('');
  const [productId, setProductId] = useState<number>(0);
  const [sortKey, setSortKey] = useState<ComparisonSortKey>('attributed_revenue');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [comparisonPage, setComparisonPage] = useState(0);
  const [productsPage, setProductsPage] = useState(0);

  const analyticsQuery = useQuery({
    queryKey: [
      'analytics',
      { from: range.from, to: range.to, campaignId, goalId, reward, productId },
    ],
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
  const funnel = summary?.funnel ?? null;

  // Pagination for the legacy "Top suggested products" table.
  const topProducts = data?.top_suggested_products ?? [];
  const PRODUCTS_PER_PAGE = 10;
  const productsPageCount = Math.max(1, Math.ceil(topProducts.length / PRODUCTS_PER_PAGE));
  const safeProductsPage = Math.min(productsPage, productsPageCount - 1);
  const pagedTopProducts = topProducts.slice(
    safeProductsPage * PRODUCTS_PER_PAGE,
    (safeProductsPage + 1) * PRODUCTS_PER_PAGE
  );

  // The purchase pipeline is unavailable when the active filter cannot be
  // expressed in attribution (product_id) — the legacy sections still work.
  const purchaseUnavailable =
    !analyticsQuery.isLoading && !analyticsQuery.isError && funnel === null;

  const isEmpty =
    !analyticsQuery.isLoading &&
    !analyticsQuery.isError &&
    !summary?.impressions &&
    !(funnel && (funnel.views > 0 || funnel.converted > 0));

  // §44 — "No purchases yet" is distinct from "no analytics data": customers
  // interacted with goals but no attributed purchase was recorded.
  const hasNoPurchases =
    !analyticsQuery.isLoading &&
    !analyticsQuery.isError &&
    !purchaseUnavailable &&
    funnel !== null &&
    funnel.views > 0 &&
    funnel.converted === 0;

  // Pre-format the trend series with short day labels for the X axis.
  const trendData = useMemo(
    () =>
      (data?.trend ?? []).map((point) => ({
        ...point,
        label: formatShortDay(point.date),
      })),
    [data]
  );

  const comparison = useMemo(() => {
    const rows = data?.goal_comparison ?? [];
    const copy = [...rows];
    const direction = sortDir === 'asc' ? 1 : -1;

    copy.sort((a, b) => {
      const left = comparisonSortValue(a, sortKey);
      const right = comparisonSortValue(b, sortKey);

      // Unavailable values always sort last, in either direction.
      const leftUnavailable = left === Number.NEGATIVE_INFINITY;
      const rightUnavailable = right === Number.NEGATIVE_INFINITY;

      if (leftUnavailable !== rightUnavailable) {
        return leftUnavailable ? 1 : -1;
      }
      if (left === right) {
        return 0;
      }
      if (typeof left === 'string' && typeof right === 'string') {
        return left.localeCompare(right) * direction;
      }
      return ((left as number) - (right as number)) * direction;
    });

    return copy;
  }, [data, sortKey, sortDir]);

  // Pagination for the goal comparison table.
  const COMPARISON_PER_PAGE = 10;
  const comparisonPageCount = Math.max(1, Math.ceil(comparison.length / COMPARISON_PER_PAGE));
  const safeComparisonPage = Math.min(comparisonPage, comparisonPageCount - 1);
  const pagedComparison = comparison.slice(
    safeComparisonPage * COMPARISON_PER_PAGE,
    (safeComparisonPage + 1) * COMPARISON_PER_PAGE
  );

  const insights = useMemo(
    () => (data && summary ? buildInsights(summary, data.goal_comparison ?? []) : []),
    [data, summary]
  );

  const handleComparisonSort = (key: ComparisonSortKey) => {
    if (key === sortKey) {
      setSortDir((current) => (current === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortKey(key);
      setSortDir(key === 'name' ? 'asc' : 'desc');
    }
    setComparisonPage(0);
  };

  // Derived purchase-analysis values (§24/§25). The average purchased
  // order uses the influenced order totals (not the incremental amount) so
  // "average order value of the attributed purchased orders" is honest.
  const purchasedOrders = summary?.purchased_orders ?? null;
  const averagePurchasedOrder =
    purchasedOrders !== null && purchasedOrders > 0 && (summary?.influenced_sales ?? 0) > 0
      ? (summary?.influenced_sales ?? 0) / purchasedOrders
      : null;

  return (
    <PageContainer
      title={__('Goal Conversion & Purchase Analysis', 'faracart')}
      description={__(
        'What happens after customers see and complete your goals — purchases, sales and profit.',
        'faracart'
      )}
    >
      {/* Filter toolbar (P17-T02, preserved) */}
      <Stack
        direction={{ xs: 'column', lg: 'row' }}
        spacing={1.5}
        useFlexGap
        sx={{ alignItems: { xs: 'stretch', lg: 'center' }, flexWrap: 'wrap' }}
      >
        <DateRangeFilter />

        <TextField
          select
          label={__('Campaign', 'faracart')}
          size="small"
          sx={{ minWidth: 190, flexGrow: { lg: 1 } }}
          value={campaignId}
          onChange={(event) => setCampaignId(Number(event.target.value))}
        >
          <MenuItem value={0}>{__('All campaigns', 'faracart')}</MenuItem>
          {(campaignsQuery.data?.items ?? []).map((campaign) => (
            <MenuItem key={campaign.id} value={campaign.id}>
              {campaign.name}
            </MenuItem>
          ))}
        </TextField>

        <TextField
          select
          label={__('Goal', 'faracart')}
          size="small"
          sx={{ minWidth: 190, flexGrow: { lg: 1 } }}
          value={goalId}
          onChange={(event) => setGoalId(Number(event.target.value))}
        >
          <MenuItem value={0}>{__('All goals', 'faracart')}</MenuItem>
          {(goalsQuery.data?.items ?? []).map((goal) => (
            <MenuItem key={goal.id} value={goal.id}>
              {goal.name}
            </MenuItem>
          ))}
        </TextField>

        <TextField
          select
          label={__('Reward', 'faracart')}
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
            label={__('Product', 'faracart')}
            value={productId ? [productId] : []}
            onChange={(ids) => setProductId(ids[0] ?? 0)}
            search={searchProducts}
            multiple={false}
            placeholder={__('All products', 'faracart')}
          />
        </Box>
      </Stack>

      {/* The exact range every metric on this page uses (§29). */}
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
        {sprintf(
          /* translators: 1: start date, 2: end date. */
          __('Analyzing: %1$s – %2$s', 'faracart'),
          formatAnalyzingDate(range.from),
          formatAnalyzingDate(range.to)
        )}
      </Typography>

      {analyticsQuery.isError && (
        <Alert severity="error" variant="outlined">
          {analyticsQuery.error instanceof Error
            ? analyticsQuery.error.message
            : __('Could not load the analytics.', 'faracart')}
        </Alert>
      )}

      {analyticsQuery.isLoading ? (
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
          <Skeleton variant="rounded" height={260} />
          <Skeleton variant="rounded" height={240} />
          <Skeleton variant="rounded" height={300} />
        </Stack>
      ) : isEmpty ? (
        <EmptyState
          icon={<InsightsIcon fontSize="large" />}
          title={__('No sales data yet', 'faracart')}
          description={__(
            'Once customers start interacting with your goals, FaraCart will show purchases, sales and profit insights here.',
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
        <Stack spacing={2} sx={{ mt: 2 }}>
          {purchaseUnavailable && (
            <Alert severity="info" variant="outlined">
              {__(
                'Purchase analysis is not available for this product filter. Remove the product filter to see the funnel, purchases and goal comparison.',
                'faracart'
              )}
            </Alert>
          )}

          {/* Primary KPI row (§22): purchases, purchase rate, sales, profit. */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' },
              gap: 2,
            }}
          >
            <KpiCard
              label={__('Purchased Orders', 'faracart')}
              value={funnel ? formatNumber(funnel.converted) : '—'}
              icon={<ShoppingCartCheckoutIcon fontSize="small" />}
              hint={__('after FaraCart interaction', 'faracart')}
              tooltip={__(
                'Distinct orders associated with a goal — a purchase, not a goal completion.',
                'faracart'
              )}
            />
            <KpiCard
              label={__('Purchase Rate', 'faracart')}
              value={summary.purchase_rate === null ? '—' : formatPercent(summary.purchase_rate)}
              icon={<PercentIcon fontSize="small" />}
              tooltip={__(
                'Percentage of completed goals that were followed by an attributed purchase.',
                'faracart'
              )}
            />
            <KpiCard
              label={__('Attributed Sales', 'faracart')}
              value={
                summary.attributed_sales === null ? '—' : formatCurrency(summary.attributed_sales)
              }
              icon={<PaymentsIcon fontSize="small" />}
              hint={__('Sales attributed to FaraCart', 'faracart')}
              tooltip={__(
                'The incremental order value from orders where customers interacted with a goal before purchasing.',
                'faracart'
              )}
            />
            <EstimatedProfitCard
              profitImpact={summary.estimated_profit}
              profitAvailable={summary.profit_available}
              profitReason={summary.profit_reason}
              profitReasonCode={summary.profit_reason_code ?? 'insufficient_data'}
              profitDetails={summary.profit_details}
              costCoverage={summary.cost_coverage}
              costSources={summary.cost_sources}
              storeHasCostData={summary.store_has_cost_data}
            />
          </Box>

          {/* Secondary KPIs (§22): goal views + completions. */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(2, minmax(0, 260px))' },
              gap: 2,
            }}
          >
            <KpiCard
              label={__('Goal Views', 'faracart')}
              value={funnel ? formatNumber(funnel.views) : '—'}
              icon={<VisibilityIcon fontSize="small" />}
              tooltip={__('How many times goal widgets were seen.', 'faracart')}
            />
            <KpiCard
              label={__('Goal Completions', 'faracart')}
              value={funnel ? formatNumber(funnel.completed) : '—'}
              icon={<CheckCircleOutlineOutlinedIcon fontSize="small" />}
              tooltip={__(
                'How many times customers reached a goal target. Completion is not the same as purchase.',
                'faracart'
              )}
            />
          </Box>

          {/* Customer journey — the full purchase funnel (§23). */}
          {funnel && (
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
          )}

          {/* Purchase analysis — completion vs purchase comparison (§24/§25). */}
          {funnel && (
            <Card variant="outlined">
              <CardContent>
                <Typography variant="h6" component="h3" gutterBottom>
                  {__('Purchase Analysis', 'faracart')}
                </Typography>
                <Stack spacing={1}>
                  <StatRow
                    label={__('Goals Completed', 'faracart')}
                    value={formatNumber(funnel.completed)}
                  />
                  <StatRow
                    label={__('Purchased After Completion', 'faracart')}
                    value={formatNumber(funnel.converted)}
                  />
                  <StatRow
                    label={__('Purchase Rate', 'faracart')}
                    value={
                      funnel.conversion_rate === null ? '—' : formatPercent(funnel.conversion_rate)
                    }
                    explanation={__('Purchased orders ÷ completed goals.', 'faracart')}
                  />
                  <Divider />
                  <StatRow
                    label={__('Attributed Sales', 'faracart')}
                    value={
                      summary.attributed_sales === null
                        ? '—'
                        : formatCurrency(summary.attributed_sales)
                    }
                    explanation={__('Sales generated after FaraCart interaction.', 'faracart')}
                  />
                  <StatRow
                    label={__('Average Purchased Order', 'faracart')}
                    value={
                      averagePurchasedOrder === null ? '—' : formatCurrency(averagePurchasedOrder)
                    }
                    explanation={__(
                      'Average order value of the attributed purchased orders.',
                      'faracart'
                    )}
                  />
                  <Divider />
                  <StatRow
                    label={__('Estimated Profit', 'faracart')}
                    value={
                      summary.profit_available && summary.estimated_profit !== null
                        ? formatCurrency(summary.estimated_profit)
                        : '—'
                    }
                    explanation={
                      summary.profit_available
                        ? __('Estimated, not guaranteed.', 'faracart')
                        : summary.profit_reason_code === 'missing_product_cost'
                          ? __('Add product cost data to estimate profit.', 'faracart')
                          : undefined
                    }
                  />
                </Stack>
              </CardContent>
            </Card>
          )}

          {/* Goal comparison (§27): sortable purchase outcomes per goal. */}
          {data.goal_comparison !== null && (
            <Card variant="outlined">
              <CardContent sx={{ p: 0, '&:last-child': { pb: 0 } }}>
                <Box sx={{ p: 2, pb: 1 }}>
                  <Typography variant="h6" component="h3">
                    {__('Goal Comparison', 'faracart')}
                  </Typography>
                </Box>
                {data.goal_comparison.length === 0 ? (
                  <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>
                    {__('No goal purchase activity in this range.', 'faracart')}
                  </Typography>
                ) : (
                  <>
                    <TableContainer>
                      <Table size="small">
                        <TableHead>
                          <TableRow>
                            {COMPARISON_COLUMNS.map((column) => {
                              const cell = (
                                <TableCell
                                  key={column.key}
                                  align={column.align}
                                  sortDirection={sortKey === column.key ? sortDir : false}
                                >
                                  <TableSortLabel
                                    active={sortKey === column.key}
                                    direction={sortKey === column.key ? sortDir : 'asc'}
                                    onClick={() => handleComparisonSort(column.key)}
                                  >
                                    {column.label}
                                  </TableSortLabel>
                                </TableCell>
                              );

                              return column.tooltip ? (
                                <Tooltip key={column.key} title={column.tooltip} arrow>
                                  {cell}
                                </Tooltip>
                              ) : (
                                cell
                              );
                            })}
                          </TableRow>
                        </TableHead>
                        <TableBody>
                          {pagedComparison.map((row) => (
                            <TableRow key={row.goal_id} hover>
                              <TableCell sx={{ fontWeight: 600 }}>{row.name}</TableCell>
                              <TableCell align="right">{formatNumber(row.views)}</TableCell>
                              <TableCell align="right">{formatNumber(row.completed)}</TableCell>
                              <TableCell align="right">{formatNumber(row.converted)}</TableCell>
                              <TableCell align="right">
                                {row.conversion_rate === null
                                  ? '—'
                                  : formatPercent(row.conversion_rate)}
                              </TableCell>
                              <TableCell align="right">
                                {formatCurrency(row.attributed_revenue)}
                              </TableCell>
                              <TableCell align="right">
                                {row.profit_available && row.profit_impact !== null
                                  ? formatCurrency(row.profit_impact)
                                  : '—'}
                              </TableCell>
                            </TableRow>
                          ))}
                        </TableBody>
                      </Table>
                    </TableContainer>
                    <Box sx={{ px: 2 }}>
                      <NumberPagination
                        count={comparison.length}
                        page={safeComparisonPage}
                        rowsPerPage={COMPARISON_PER_PAGE}
                        onPageChange={setComparisonPage}
                      />
                    </Box>
                  </>
                )}
              </CardContent>
            </Card>
          )}

          {/* Key insights — deterministic drop-off analysis (§26). */}
          {insights.length > 0 && (
            <Card variant="outlined">
              <CardContent>
                <Typography variant="h6" component="h3" gutterBottom>
                  {__('Key Insights', 'faracart')}
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
                      <Box
                        sx={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: 1,
                          color: 'text.secondary',
                          mb: 0.5,
                        }}
                      >
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

          {/* Advanced analytics (§30) — attribution details + data quality. */}
          {funnel && (
            <Accordion
              disableGutters
              square
              sx={{
                boxShadow: 'none',
                border: '1px solid',
                borderColor: 'divider',
                borderRadius: 1,
              }}
            >
              <AccordionSummary expandIcon={<ExpandMoreIcon />}>
                <Typography variant="h6" component="h3" sx={{ fontSize: '1rem' }}>
                  {__('Advanced Analytics', 'faracart')}
                </Typography>
              </AccordionSummary>
              <AccordionDetails>
                <Stack spacing={1.25}>
                  <StatRow
                    label={__('Direct revenue', 'faracart')}
                    value={
                      summary.attributed_sales === null
                        ? '—'
                        : formatCurrency(summary.attributed_sales)
                    }
                    explanation={__(
                      'Revenue from the incremental value of orders where customers progressed toward or completed a goal before ordering.',
                      'faracart'
                    )}
                  />
                  <StatRow
                    label={__('Assisted revenue', 'faracart')}
                    value={
                      summary.assisted_sales === null ? '—' : formatCurrency(summary.assisted_sales)
                    }
                    explanation={__(
                      'Order totals from orders that were only exposed to a goal, never progressed.',
                      'faracart'
                    )}
                  />
                  <StatRow
                    label={__('Influenced sales', 'faracart')}
                    value={
                      summary.influenced_sales === null
                        ? '—'
                        : formatCurrency(summary.influenced_sales)
                    }
                    explanation={__(
                      'Order totals of every order associated with a goal — distinct orders, never double counted.',
                      'faracart'
                    )}
                  />
                  <StatRow
                    label={__('Attributed orders', 'faracart')}
                    value={funnel ? formatNumber(funnel.converted) : '—'}
                    explanation={__(
                      'Distinct orders associated with a goal in the selected period.',
                      'faracart'
                    )}
                  />
                  <StatRow
                    label={__('Attribution window', 'faracart')}
                    value={__('30 days before the order', 'faracart')}
                    explanation={__(
                      'Only goal events within this window before an order are attributed to it.',
                      'faracart'
                    )}
                  />
                  {summary.cost_coverage && summary.cost_coverage.available && (
                    <StatRow
                      label={__('Cost data coverage', 'faracart')}
                      value={
                        summary.cost_coverage.coverage_pct === null
                          ? '—'
                          : sprintf(
                              /* translators: 1: percentage. */
                              __('%1$s of eligible order value', 'faracart'),
                              formatPercentValue(summary.cost_coverage.coverage_pct)
                            )
                      }
                      explanation={__(
                        'Estimated profit is calculated only for orders with complete cost data.',
                        'faracart'
                      )}
                    />
                  )}
                  <Divider />
                  <Box
                    sx={{ display: 'flex', gap: 1, alignItems: 'center', color: 'text.secondary' }}
                  >
                    <LocalShippingIcon fontSize="small" />
                    <Typography variant="caption">
                      {__(
                        'These metrics use the FaraCart attribution model — direct vs assisted, distinct orders, no double counting.',
                        'faracart'
                      )}
                    </Typography>
                  </Box>
                </Stack>
              </AccordionDetails>
            </Accordion>
          )}

          {/* Detailed activity — the preserved legacy interaction metrics. */}
          <Accordion
            disableGutters
            square
            sx={{ boxShadow: 'none', border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
          >
            <AccordionSummary expandIcon={<ExpandMoreIcon />}>
              <Typography variant="h6" component="h3" sx={{ fontSize: '1rem' }}>
                {__('Detailed Activity', 'faracart')}
              </Typography>
            </AccordionSummary>
            <AccordionDetails>
              <Stack spacing={2}>
                <Box>
                  <Typography variant="body2" color="text.secondary" gutterBottom>
                    {__(
                      'Raw interaction metrics from the event log — impressions, completions, revenue and suggestions.',
                      'faracart'
                    )}
                  </Typography>
                  <Box
                    role="img"
                    aria-label={__('Daily impressions, completions and revenue trend', 'faracart')}
                    sx={{ width: '100%', height: 280 }}
                  >
                    <ResponsiveContainer width="100%" height="100%">
                      <ComposedChart
                        data={trendData}
                        margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
                      >
                        <CartesianGrid
                          strokeDasharray="3 3"
                          stroke={COLORS.grid}
                          vertical={false}
                        />
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
                              label === __('Revenue', 'faracart')
                                ? formatCurrency(Number(value))
                                : formatNumber(Number(value));

                            return [formatted, label];
                          }}
                        />
                        <Legend wrapperStyle={{ fontSize: 12 }} />
                        <Bar
                          yAxisId="count"
                          dataKey="impressions"
                          name={__('Impressions', 'faracart')}
                          fill={COLORS.primaryLight}
                          radius={[3, 3, 0, 0]}
                        />
                        <Bar
                          yAxisId="count"
                          dataKey="completions"
                          name={__('Completions', 'faracart')}
                          fill={COLORS.primary}
                          radius={[3, 3, 0, 0]}
                        />
                        <Line
                          yAxisId="revenue"
                          dataKey="revenue"
                          name={__('Revenue', 'faracart')}
                          stroke={COLORS.success}
                          strokeWidth={2}
                          dot={false}
                          activeDot={{ r: 3 }}
                        />
                      </ComposedChart>
                    </ResponsiveContainer>
                  </Box>
                </Box>

                <Box
                  sx={{
                    display: 'grid',
                    gridTemplateColumns: { md: 'repeat(2, 1fr)' },
                    gap: 2,
                    alignItems: 'stretch',
                  }}
                >
                  <Paper variant="outlined" sx={{ p: 2 }}>
                    <Typography variant="subtitle2" gutterBottom>
                      {__('Top campaigns', 'faracart')}
                    </Typography>
                    {data.top_campaigns.length === 0 ? (
                      <Typography variant="body2" color="text.secondary">
                        {__('No campaign activity in this range.', 'faracart')}
                      </Typography>
                    ) : (
                      <Box
                        role="img"
                        aria-label={__('Top campaigns by completions', 'faracart')}
                        sx={{ width: '100%', height: data.top_campaigns.length * 46 + 24 }}
                      >
                        <ResponsiveContainer width="100%" height="100%">
                          <BarChart
                            data={data.top_campaigns}
                            layout="vertical"
                            margin={{ top: 0, right: 12, bottom: 0, left: 0 }}
                          >
                            <CartesianGrid
                              strokeDasharray="3 3"
                              stroke={COLORS.grid}
                              horizontal={false}
                            />
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
                              name={__('Completions', 'faracart')}
                              fill={COLORS.primary}
                              radius={[0, 4, 4, 0]}
                            />
                          </BarChart>
                        </ResponsiveContainer>
                      </Box>
                    )}
                  </Paper>

                  <Paper variant="outlined" sx={{ p: 2 }}>
                    <Typography variant="subtitle2" gutterBottom>
                      {__('Top suggested products', 'faracart')}
                    </Typography>
                    {data.top_suggested_products.length === 0 ? (
                      <Typography variant="body2" color="text.secondary">
                        {__('No suggestion activity in this range.', 'faracart')}
                      </Typography>
                    ) : (
                      <>
                        <TableContainer>
                          <Table size="small">
                            <TableHead>
                              <TableRow>
                                <TableCell>{__('Product', 'faracart')}</TableCell>
                                <TableCell align="right">{__('Impressions', 'faracart')}</TableCell>
                                <TableCell align="right">{__('Clicks', 'faracart')}</TableCell>
                                <TableCell align="right">{__('Added', 'faracart')}</TableCell>
                                <TableCell align="right">{__('CTR', 'faracart')}</TableCell>
                                <TableCell align="right">{__('Add-to-cart', 'faracart')}</TableCell>
                              </TableRow>
                            </TableHead>
                            <TableBody>
                              {pagedTopProducts.map((product) => (
                                <TableRow key={product.product_id} hover>
                                  <TableCell sx={{ fontWeight: 600 }}>{product.name}</TableCell>
                                  <TableCell align="right">
                                    {formatNumber(product.impressions)}
                                  </TableCell>
                                  <TableCell align="right">
                                    {formatNumber(product.clicks)}
                                  </TableCell>
                                  <TableCell align="right">
                                    <Chip
                                      size="small"
                                      variant="outlined"
                                      color={product.added > 0 ? 'success' : 'default'}
                                      label={formatNumber(product.added)}
                                    />
                                  </TableCell>
                                  <TableCell align="right">{formatPercent(product.ctr)}</TableCell>
                                  <TableCell align="right">
                                    {formatPercent(product.add_to_cart_rate)}
                                  </TableCell>
                                </TableRow>
                              ))}
                            </TableBody>
                          </Table>
                        </TableContainer>
                        <NumberPagination
                          count={topProducts.length}
                          page={safeProductsPage}
                          rowsPerPage={PRODUCTS_PER_PAGE}
                          onPageChange={setProductsPage}
                        />
                      </>
                    )}
                  </Paper>
                </Box>
              </Stack>
            </AccordionDetails>
          </Accordion>
        </Stack>
      ) : null}
    </PageContainer>
  );
}
