import LeaderboardIcon from '@mui/icons-material/Leaderboard';
import SavingsIcon from '@mui/icons-material/Savings';
import { useQuery } from '@tanstack/react-query';
import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
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
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState, type ReactNode } from 'react';

import { fetchGoalPerformance } from '../api/revenue';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import EstimatedProfitCard from '../components/revenue/EstimatedProfitCard';
import FunnelVisual from '../components/revenue/FunnelVisual';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber, formatPercent } from '../lib/format';
import { REWARD_LABELS } from '../templates/rewardLabel';
import type { GoalPerformanceRow } from '../types';

/** Sortable table columns (§16) — commercial outcomes first. */
type SortKey =
  | 'name'
  | 'views'
  | 'progressed'
  | 'completed'
  | 'converted'
  | 'conversion_rate'
  | 'attributed_revenue'
  | 'profit_impact';

interface Column {
  key: SortKey;
  label: string;
  align: 'left' | 'right';
  tooltip?: string;
}

const COLUMNS: Column[] = [
  { key: 'name', label: __('Goal', 'goalcart'), align: 'left' },
  { key: 'views', label: __('Viewed', 'goalcart'), align: 'right' },
  { key: 'progressed', label: __('Progressed', 'goalcart'), align: 'right' },
  { key: 'completed', label: __('Completed', 'goalcart'), align: 'right' },
  {
    key: 'converted',
    label: __('Purchased', 'goalcart'),
    align: 'right',
    tooltip: __(
      'A qualifying WooCommerce order was actually associated with this goal — a purchase, not a goal completion.',
      'goalcart'
    ),
  },
  {
    key: 'conversion_rate',
    label: __('Purchase Rate', 'goalcart'),
    align: 'right',
    tooltip: __('Percentage of completed goals that were followed by an attributed purchase.', 'goalcart'),
  },
  {
    key: 'attributed_revenue',
    label: __('Sales', 'goalcart'),
    align: 'right',
    tooltip: __('Sales attributed to Goal Cart — the incremental order value driven by this goal.', 'goalcart'),
  },
  {
    key: 'profit_impact',
    label: __('Estimated Profit', 'goalcart'),
    align: 'right',
    tooltip: __('Estimated, not guaranteed — based on available product cost, reward and shipping data.', 'goalcart'),
  },
];

/** Data-sufficiency states translated into business language (§45). */
const SUFFICIENCY: Record<'low' | 'medium' | 'high', { label: string; hint: string }> = {
  low: {
    label: __('Limited data', 'goalcart'),
    hint: __('More customer activity is needed for a more reliable analysis.', 'goalcart'),
  },
  medium: {
    label: __('Moderate data', 'goalcart'),
    hint: __('Analysis is based on a moderate number of customer sessions.', 'goalcart'),
  },
  high: {
    label: __('Good data', 'goalcart'),
    hint: __('Analysis is based on a healthy number of customer sessions.', 'goalcart'),
  },
};

/** Numeric sort value — unavailable profit/rate sorts last, never first. */
function sortValue(row: GoalPerformanceRow, key: SortKey): number | string {
  if (key === 'profit_impact') {
    return row.profit_available && row.profit_impact !== null ? row.profit_impact : Number.NEGATIVE_INFINITY;
  }
  if (key === 'conversion_rate') {
    return row.conversion_rate === null ? Number.NEGATIVE_INFINITY : row.conversion_rate;
  }
  const value = row[key];
  return typeof value === 'number' ? value : String(value);
}

/** Signed percentage (e.g. +8.7% / -3.1%) — for the basket-increase read. */
function formatSignedPercent(value: number): string {
  const formatted = formatPercent(Math.abs(value));
  return value > 0 ? `+${formatted}` : value < 0 ? `-${formatted}` : formatPercent(value);
}

/** One label/value row in the detail drawer. */
function DetailRow({ label, value, explanation }: { label: string; value: string; explanation?: string }) {
  return (
    <Box>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 2 }}>
        <Typography variant="body2" color="text.secondary">
          {label}
        </Typography>
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {value}
        </Typography>
      </Box>
      {explanation && (
        <Typography variant="caption" color="text.secondary" component="p">
          {explanation}
        </Typography>
      )}
    </Box>
  );
}

/** One stat cell of the drawer's performance summary (§20). */
function StatCell({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Paper variant="outlined" sx={{ p: 1.5 }}>
      <Typography variant="caption" color="text.secondary" component="p">
        {label}
      </Typography>
      <Typography variant="h6" component="p" sx={{ m: 0, fontWeight: 600 }}>
        {value}
      </Typography>
      {hint && (
        <Typography variant="caption" color="text.secondary" component="p">
          {hint}
        </Typography>
      )}
    </Paper>
  );
}

/** Drawer section heading. */
function SectionTitle({ children }: { children: ReactNode }) {
  return (
    <Typography variant="h6" component="h3" sx={{ fontSize: '0.95rem' }}>
      {children}
    </Typography>
  );
}

/**
 * The Goal Performance detail drawer (Improvement.md §20).
 *
 * Opened by clicking a goal row. Answers "what happened commercially for
 * this goal?" — performance summary, the full customer journey with
 * stage-to-stage drop-off, costs (estimated profit with all its data
 * states) and, behind accordions, the advanced attribution details and
 * engine metadata. Completion and purchase stay visually distinct (§17).
 */
function GoalDetailDrawer({ row, onClose }: { row: GoalPerformanceRow | null; onClose: () => void }) {
  const open = row !== null;

  const profitValue =
    row && row.profit_available && row.profit_impact !== null
      ? formatCurrency(row.profit_impact)
      : row && row.profit_reason_code === 'missing_product_cost'
        ? __('Not available', 'goalcart')
        : '—';

  const profitHint =
    row && row.profit_available && row.profit_impact !== null
      ? __('Estimated, not guaranteed', 'goalcart')
      : row && row.profit_reason_code === 'missing_product_cost'
        ? __('Add product cost data to estimate profit.', 'goalcart')
        : undefined;

  const sufficiency = row ? SUFFICIENCY[row.data_sufficiency] : SUFFICIENCY.low;
  const aovImpact =
    row && row.average_cart_value > 0 ? row.incremental_cart_value / row.average_cart_value : null;

  return (
    <Drawer anchor="right" open={open} onClose={onClose}>
      {row && (
        <Box
          role="dialog"
          aria-label={__('Goal performance details', 'goalcart')}
          sx={{
            width: { xs: '100vw', sm: 500 },
            maxWidth: '100vw',
            p: 3,
            display: 'flex',
            flexDirection: 'column',
            gap: 2.5,
            overflowY: 'auto',
          }}
        >
          {/* Header — goal name + reward + target. */}
          <Box>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
              <Typography variant="h5" sx={{ fontWeight: 600 }}>
                {row.name}
              </Typography>
              {row.reward_type && (
                <Chip size="small" variant="outlined" label={REWARD_LABELS[row.reward_type] ?? row.reward_type} />
              )}
            </Box>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
              {sprintf(
                /* translators: %s: formatted goal target. */
                __('Target: %s', 'goalcart'),
                formatNumber(row.target)
              )}
            </Typography>
          </Box>

          <Divider />

          {/* Performance summary (§20). */}
          <Box>
            <SectionTitle>{__('Performance Summary', 'goalcart')}</SectionTitle>
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, 1fr)' }, gap: 1.5, mt: 1 }}>
              <StatCell label={__('Viewed', 'goalcart')} value={formatNumber(row.views)} />
              <StatCell label={__('Progressed', 'goalcart')} value={formatNumber(row.progressed)} />
              <StatCell label={__('Completed', 'goalcart')} value={formatNumber(row.completed)} />
              <StatCell
                label={__('Purchased', 'goalcart')}
                value={formatNumber(row.converted)}
                hint={__('after Goal Cart interaction', 'goalcart')}
              />
              <StatCell label={__('Attributed Sales', 'goalcart')} value={formatCurrency(row.attributed_revenue)} />
              <StatCell label={__('Estimated Profit', 'goalcart')} value={profitValue} hint={profitHint} />
            </Box>
          </Box>

          {/* Customer journey — the detailed funnel with drop-off (§20/§23). */}
          <Box>
            <SectionTitle>{__('Customer Journey', 'goalcart')}</SectionTitle>
            <Box sx={{ mt: 1 }}>
              <FunnelVisual
                showTransitions
                funnel={{
                  views: row.views,
                  progressed: row.progressed,
                  completed: row.completed,
                  converted: row.converted,
                  completion_rate: row.completion_rate,
                  conversion_rate: row.conversion_rate,
                }}
              />
            </Box>
            <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
              {__(
                'A completion means the customer reached the goal target. A purchase means a qualifying order was actually associated with the goal.',
                'goalcart'
              )}
            </Typography>
          </Box>

          <Divider />

          {/* Costs — reward cost, shipping cost and estimated profit in every
              data state (reuses the shared EstimatedProfitCard). */}
          <Box>
            <SectionTitle>{__('Costs', 'goalcart')}</SectionTitle>
            <Box sx={{ mt: 1 }}>
              <EstimatedProfitCard
                profitImpact={row.profit_impact}
                profitAvailable={row.profit_available}
                profitReason={row.profit_reason}
                profitReasonCode={row.profit_reason_code}
                profitDetails={row.profit_details}
                costCoverage={row.cost_coverage}
                costSources={row.cost_sources}
                storeHasCostData={row.store_has_cost_data}
              />
            </Box>
          </Box>

          <Divider />

          {/* Advanced attribution details (§20 Revenue). */}
          <Accordion
            disableGutters
            square
            sx={{ boxShadow: 'none', border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
          >
            <AccordionSummary expandIcon={<ExpandMoreIcon />}>
              <SectionTitle>{__('Advanced attribution details', 'goalcart')}</SectionTitle>
            </AccordionSummary>
            <AccordionDetails sx={{ pt: 0 }}>
              <Stack spacing={1}>
                <DetailRow
                  label={__('Direct revenue', 'goalcart')}
                  value={formatCurrency(row.attributed_revenue)}
                  explanation={__(
                    'The incremental order value from orders where customers progressed or completed this goal before ordering.',
                    'goalcart'
                  )}
                />
                <DetailRow
                  label={__('Assisted revenue', 'goalcart')}
                  value={formatCurrency(row.assisted_revenue)}
                  explanation={__(
                    'Order totals from orders that were only exposed to this goal, never progressed.',
                    'goalcart'
                  )}
                />
                <DetailRow
                  label={__('Influenced revenue', 'goalcart')}
                  value={formatCurrency(row.influenced_revenue)}
                  explanation={__(
                    'Order totals of every order associated with this goal — distinct orders, never double counted.',
                    'goalcart'
                  )}
                />
                <DetailRow
                  label={__('Incremental cart value', 'goalcart')}
                  value={formatCurrency(row.incremental_cart_value)}
                  explanation={__(
                    'Average cart value after goal exposure minus the value at first exposure, per session.',
                    'goalcart'
                  )}
                />
                <Typography variant="caption" color="text.secondary" component="p">
                  {__(
                    'Direct revenue is the incremental amount this goal moved — it is a slice of the influenced total, not an extra value on top of it.',
                    'goalcart'
                  )}
                </Typography>
              </Stack>
            </AccordionDetails>
          </Accordion>

          {/* Advanced engine metadata (§20 Advanced). */}
          <Accordion
            disableGutters
            square
            sx={{ boxShadow: 'none', border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
          >
            <AccordionSummary expandIcon={<ExpandMoreIcon />}>
              <SectionTitle>{__('Advanced', 'goalcart')}</SectionTitle>
            </AccordionSummary>
            <AccordionDetails sx={{ pt: 0 }}>
              <Stack spacing={1}>
                <DetailRow
                  label={__('Attribution model', 'goalcart')}
                  value={__('Direct & assisted', 'goalcart')}
                  explanation={__(
                    'Customers who progressed or completed the goal before ordering are direct; exposure-only sessions are assisted.',
                    'goalcart'
                  )}
                />
                <DetailRow
                  label={__('Attribution window', 'goalcart')}
                  value={sprintf(
                    /* translators: %d: number of days. */
                    __('%d days before the order', 'goalcart'),
                    row.attribution_window_days
                  )}
                />
                <DetailRow label={__('Data sufficiency', 'goalcart')} value={sufficiency.label} explanation={sufficiency.hint} />
                <DetailRow
                  label={__('Average basket increase', 'goalcart')}
                  value={aovImpact !== null ? formatSignedPercent(aovImpact) : '—'}
                  explanation={__(
                    'Observed impact — incremental cart value relative to the baseline. It does not prove that Goal Cart caused the difference.',
                    'goalcart'
                  )}
                />
                <DetailRow
                  label={__('Attributed orders', 'goalcart')}
                  value={formatNumber(row.converted)}
                  explanation={__('Distinct orders associated with this goal in the selected period.', 'goalcart')}
                />
              </Stack>
            </AccordionDetails>
          </Accordion>
        </Box>
      )}
    </Drawer>
  );
}

/**
 * Goal Performance (Phase 5 redesign).
 *
 * Per-goal commercial outcomes from `GET /goalcart/v1/revenue/goals`:
 * the funnel counts (viewed → progressed → completed → purchased), the
 * purchase rate (purchased / completed — never confused with completion
 * rate, §17/§28), sales attributed to Goal Cart and estimated profit.
 * Each row opens a detail drawer with the full customer journey, costs
 * and the advanced attribution details behind accordions (§20).
 */
export default function GoalPerformance() {
  const { range } = useDateRange();
  const [goalId, setGoalId] = useState<number>(0);
  const [sortKey, setSortKey] = useState<SortKey>('attributed_revenue');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [selected, setSelected] = useState<GoalPerformanceRow | null>(null);

  const query = useQuery({
    queryKey: ['revenue', 'goals', { from: range.from, to: range.to, goalId }],
    queryFn: () =>
      fetchGoalPerformance({
        from: range.from,
        to: range.to,
        goal_id: goalId || undefined,
      }),
  });

  const sorted = useMemo(() => {
    const items = query.data?.items ?? [];
    const copy = [...items];
    const direction = sortDir === 'asc' ? 1 : -1;

    copy.sort((a, b) => {
      const left = sortValue(a, sortKey);
      const right = sortValue(b, sortKey);

      // Unavailable values (no profit / no purchase rate) always sort
      // last, in either direction — never first.
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
  }, [query.data, sortKey, sortDir]);

  const handleSort = (key: SortKey) => {
    if (key === sortKey) {
      setSortDir((current) => (current === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortKey(key);
      setSortDir(key === 'name' ? 'asc' : 'desc');
    }
  };

  return (
    <PageContainer
      title={__('Goal Performance', 'goalcart')}
      description={__(
        'Which goals generated purchases, sales and profit? Completion is not the same as purchase.',
        'goalcart'
      )}
    >
      <RevenueToolbar goalId={goalId} onGoalChange={setGoalId} />

      {query.isError && (
        <Alert severity="error" variant="outlined">
          {query.error instanceof Error
            ? query.error.message
            : __('Could not load goal performance.', 'goalcart')}
        </Alert>
      )}

      {query.isLoading ? (
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={72} />
          <Skeleton variant="rounded" height={360} />
        </Stack>
      ) : !query.isError && sorted.length === 0 ? (
        <EmptyState
          icon={<LeaderboardIcon fontSize="large" />}
          title={__('No goal performance yet', 'goalcart')}
          description={__(
            'Once customers start interacting with your goals, Goal Cart will show purchases, sales and profit per goal here.',
            'goalcart'
          )}
        />
      ) : (
        <TableContainer component={Paper} variant="outlined" sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                {COLUMNS.map((column) => {
                  const cell = (
                    <TableCell
                      key={column.key}
                      align={column.align}
                      sortDirection={sortKey === column.key ? sortDir : false}
                    >
                      <TableSortLabel
                        active={sortKey === column.key}
                        direction={sortKey === column.key ? sortDir : 'asc'}
                        onClick={() => handleSort(column.key)}
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
              {sorted.map((row) => (
                <TableRow
                  key={row.goal_id}
                  hover
                  tabIndex={0}
                  sx={{ cursor: 'pointer', '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: -2 } }}
                  onClick={() => setSelected(row)}
                  onKeyDown={(event) => {
                    // Keyboard access to the detail drawer (§53): Enter /
                    // Space open the same row details as a click.
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault();
                      setSelected(row);
                    }
                  }}
                  aria-label={sprintf(
                    /* translators: %s: goal name. */
                    __('Open performance details for %s', 'goalcart'),
                    row.name
                  )}
                >
                  <TableCell sx={{ fontWeight: 600 }}>{row.name}</TableCell>
                  <TableCell align="right">{formatNumber(row.views)}</TableCell>
                  <TableCell align="right">{formatNumber(row.progressed)}</TableCell>
                  <TableCell align="right">{formatNumber(row.completed)}</TableCell>
                  <TableCell align="right">{formatNumber(row.converted)}</TableCell>
                  <TableCell align="right">
                    {row.conversion_rate === null ? '—' : formatPercent(row.conversion_rate)}
                  </TableCell>
                  <TableCell align="right">{formatCurrency(row.attributed_revenue)}</TableCell>
                  <TableCell align="right">
                    {row.profit_available && row.profit_impact !== null ? (
                      formatCurrency(row.profit_impact)
                    ) : (
                      <Tooltip
                        title={
                          row.profit_reason_code === 'missing_product_cost'
                            ? __('Add product cost data to estimate profit.', 'goalcart')
                            : row.profit_reason_code === 'incomplete_product_cost'
                              ? __('Some orders do not have complete cost information.', 'goalcart')
                              : __('Not enough attributed order data yet.', 'goalcart')
                        }
                        arrow
                      >
                        <Box
                          component="span"
                          sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.5, color: 'text.secondary' }}
                        >
                          <SavingsIcon sx={{ fontSize: 14 }} />
                          —
                        </Box>
                      </Tooltip>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <GoalDetailDrawer row={selected} onClose={() => setSelected(null)} />
    </PageContainer>
  );
}
