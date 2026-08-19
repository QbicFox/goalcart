import LeaderboardIcon from '@mui/icons-material/Leaderboard';
import SavingsIcon from '@mui/icons-material/Savings';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
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

import {
  applyMissionRecommendation,
  fetchMissionPerformance,
  fetchMissionRecommendations,
} from '../api/revenue';
import ConfirmDialog from '../components/ConfirmDialog';
import EmptyState from '../components/EmptyState';
import NumberPagination from '../components/NumberPagination';
import PageContainer from '../components/PageContainer';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import EstimatedProfitCard from '../components/revenue/EstimatedProfitCard';
import FunnelVisual from '../components/revenue/FunnelVisual';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import StatRow from '../components/revenue/StatRow';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber, formatPercent } from '../lib/format';
import { REWARD_LABELS } from '../templates/rewardLabel';
import type { MissionPerformanceRow, RecommendationCandidate } from '../types';

/** Sortable table columns (§16) — commercial outcomes first. */
type SortKey =
  | 'name'
  | 'views'
  | 'progressed'
  | 'completed'
  | 'converted'
  | 'conversion_rate'
  | 'upsell_assisted'
  | 'attributed_revenue'
  | 'profit_impact';

interface Column {
  key: SortKey;
  label: string;
  align: 'left' | 'right';
  tooltip?: string;
}

const COLUMNS: Column[] = [
  { key: 'name', label: __('Mission', 'faracart'), align: 'left' },
  { key: 'views', label: __('Viewed', 'faracart'), align: 'right' },
  { key: 'progressed', label: __('Progressed', 'faracart'), align: 'right' },
  { key: 'completed', label: __('Completed', 'faracart'), align: 'right' },
  {
    key: 'converted',
    label: __('Purchased', 'faracart'),
    align: 'right',
    tooltip: __(
      'A qualifying WooCommerce order was actually associated with this mission — a purchase, not a mission completion.',
      'faracart'
    ),
  },
  {
    key: 'conversion_rate',
    label: __('Purchase Rate', 'faracart'),
    align: 'right',
    tooltip: __(
      'Percentage of completed missions that were followed by an attributed purchase.',
      'faracart'
    ),
  },
  {
    key: 'upsell_assisted',
    label: __('Upsell-assisted', 'faracart'),
    align: 'right',
    tooltip: __(
      'Completions where the customer also saw a product recommendation for this mission in the same session (UPSELL_REFACTOR §30).',
      'faracart'
    ),
  },
  {
    key: 'attributed_revenue',
    label: __('Sales', 'faracart'),
    align: 'right',
    tooltip: __(
      'Sales attributed to FaraCart — the incremental order value driven by this mission.',
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

/** Data-sufficiency states translated into business language (§45). */
const SUFFICIENCY: Record<'low' | 'medium' | 'high', { label: string; hint: string }> = {
  low: {
    label: __('Limited data', 'faracart'),
    hint: __('More customer activity is needed for a more reliable analysis.', 'faracart'),
  },
  medium: {
    label: __('Moderate data', 'faracart'),
    hint: __('Analysis is based on a moderate number of customer sessions.', 'faracart'),
  },
  high: {
    label: __('Good data', 'faracart'),
    hint: __('Analysis is based on a healthy number of customer sessions.', 'faracart'),
  },
};

/** Numeric sort value — unavailable profit/rate sorts last, never first. */
function sortValue(row: MissionPerformanceRow, key: SortKey): number | string {
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

/** Business-friendly confidence label (High ≥ 75, Medium ≥ 60, else Low). */
function confidenceLabel(confidence: number): string {
  if (confidence >= 75) {
    return __('High', 'faracart');
  }
  if (confidence >= 60) {
    return __('Medium', 'faracart');
  }
  return __('Low', 'faracart');
}

/** Signed percentage (e.g. +8.7% / -3.1%) — for the basket-increase read. */
function formatSignedPercent(value: number): string {
  const formatted = formatPercent(Math.abs(value));
  return value > 0 ? `+${formatted}` : value < 0 ? `-${formatted}` : formatPercent(value);
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
 * The Recommendations section of the mission detail drawer (UPSELL_REFACTOR
 * §34): current target → recommended target → why → confidence → apply.
 *
 * Reads the same recommendation payload as the Recommendations page;
 * applying goes through the dedicated apply endpoint (only the target
 * changes) and records the feedback-loop event. The section title uses
 * the canonical §40 label (Recommendations), matching the navigation.
 */
function MissionOptimizationSection({
  missionId,
  currentTarget,
}: {
  missionId: number;
  currentTarget: number;
}) {
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();
  const [applyTarget, setApplyTarget] = useState<RecommendationCandidate | null>(null);

  const recQuery = useQuery({
    queryKey: ['revenue', 'recommendations', { missionId, drawer: true }],
    queryFn: () => fetchMissionRecommendations({ mission_id: missionId }),
    enabled: missionId > 0,
  });

  const applyMutation = useMutation({
    mutationFn: async (threshold: number) => {
      await applyMissionRecommendation(missionId, threshold);
    },
    onSuccess: () => {
      notify(__('Mission target updated.', 'faracart'));
      setApplyTarget(null);
      queryClient.invalidateQueries({ queryKey: ['missions'] });
      queryClient.invalidateQueries({ queryKey: ['revenue'] });
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
      setApplyTarget(null);
    },
  });

  const top = recQuery.data?.recommendation;

  return (
    <Box>
      <SectionTitle>{__('Recommendations', 'faracart')}</SectionTitle>
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 0.25 }}>
        {__('What target should this mission use — based on real store data.', 'faracart')}
      </Typography>

      {recQuery.isLoading ? (
        <Skeleton variant="rounded" height={110} sx={{ mt: 1 }} />
      ) : !recQuery.data?.available || !top ? (
        <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
          {recQuery.data?.insufficient_reason ??
            __('No recommendation is available for this mission yet.', 'faracart')}
        </Typography>
      ) : (
        <Box sx={{ mt: 1, display: 'flex', flexDirection: 'column', gap: 1 }}>
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 1.5 }}>
            <StatCell
              label={__('Current target', 'faracart')}
              value={formatCurrency(currentTarget)}
            />
            <StatCell
              label={__('Recommended target', 'faracart')}
              value={formatCurrency(top.threshold)}
            />
          </Box>
          <Box>
            <Chip
              size="small"
              variant="outlined"
              color={
                top.confidence >= 75 ? 'success' : top.confidence >= 60 ? 'warning' : 'default'
              }
              label={sprintf(
                /* translators: 1: confidence label. */
                __('Confidence: %1$s', 'faracart'),
                confidenceLabel(top.confidence)
              )}
            />
          </Box>
          {top.reasons.length > 0 && (
            <Box>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {__('Why?', 'faracart')}
              </Typography>
              <Stack spacing={0.25} sx={{ mt: 0.25 }}>
                {top.reasons.slice(0, 3).map((reason, index) => (
                  <Typography key={`${reason}-${index}`} variant="body2" color="text.secondary">
                    • {reason}
                  </Typography>
                ))}
              </Stack>
            </Box>
          )}
          <Box>
            <Button
              size="small"
              variant="contained"
              onClick={() => setApplyTarget(top)}
              sx={{ mt: 0.5 }}
            >
              {__('Apply recommendation', 'faracart')}
            </Button>
          </Box>
        </Box>
      )}

      <ConfirmDialog
        open={applyTarget !== null}
        title={__('Apply recommendation?', 'faracart')}
        description={
          applyTarget ? (
            <>
              {sprintf(
                /* translators: 1: current target, 2: recommended target. */
                __(
                  'Current target %1$s → recommended target %2$s? This changes a production mission — the action is not reversible from here.',
                  'faracart'
                ),
                formatCurrency(currentTarget),
                formatCurrency(applyTarget.threshold)
              )}
            </>
          ) : undefined
        }
        confirmLabel={__('Apply', 'faracart')}
        busy={applyMutation.isPending}
        onConfirm={() => {
          if (applyTarget) {
            applyMutation.mutate(applyTarget.threshold);
          }
        }}
        onCancel={() => setApplyTarget(null)}
      />
    </Box>
  );
}

/**
 * The Mission Performance detail drawer (Improvement.md §20).
 *
 * Opened by clicking a mission row. Answers "what happened commercially for
 * this mission?" — performance summary, the full customer journey with
 * stage-to-stage drop-off, costs (estimated profit with all its data
 * states) and, behind accordions, the advanced attribution details and
 * engine metadata. Completion and purchase stay visually distinct (§17).
 */
function MissionDetailDrawer({
  row,
  onClose,
}: {
  row: MissionPerformanceRow | null;
  onClose: () => void;
}) {
  const open = row !== null;

  const profitValue =
    row && row.profit_available && row.profit_impact !== null
      ? formatCurrency(row.profit_impact)
      : row && row.profit_reason_code === 'missing_product_cost'
        ? __('Not available', 'faracart')
        : '—';

  const profitHint =
    row && row.profit_available && row.profit_impact !== null
      ? __('Estimated, not guaranteed', 'faracart')
      : row && row.profit_reason_code === 'missing_product_cost'
        ? __('Add product cost data to estimate profit.', 'faracart')
        : undefined;

  const sufficiency = row ? SUFFICIENCY[row.data_sufficiency] : SUFFICIENCY.low;
  const aovImpact =
    row && row.average_cart_value > 0 ? row.incremental_cart_value / row.average_cart_value : null;

  return (
    <Drawer anchor="right" open={open} onClose={onClose}>
      {row && (
        <Box
          role="dialog"
          aria-label={__('Mission performance details', 'faracart')}
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
          {/* Header — mission name + reward + target. */}
          <Box>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
              <Typography variant="h5" sx={{ fontWeight: 600 }}>
                {row.name}
              </Typography>
              {row.reward_type && (
                <Chip
                  size="small"
                  variant="outlined"
                  label={REWARD_LABELS[row.reward_type] ?? row.reward_type}
                />
              )}
            </Box>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
              {sprintf(
                /* translators: %s: formatted mission target. */
                __('Target: %s', 'faracart'),
                formatNumber(row.target)
              )}
            </Typography>
          </Box>

          <Divider />

          {/* Performance summary (§20). */}
          <Box>
            <SectionTitle>{__('Performance Summary', 'faracart')}</SectionTitle>
            <Box
              sx={{
                display: 'grid',
                gridTemplateColumns: { xs: 'repeat(2, 1fr)' },
                gap: 1.5,
                mt: 1,
              }}
            >
              <StatCell label={__('Viewed', 'faracart')} value={formatNumber(row.views)} />
              <StatCell label={__('Progressed', 'faracart')} value={formatNumber(row.progressed)} />
              <StatCell label={__('Completed', 'faracart')} value={formatNumber(row.completed)} />
              <StatCell
                label={__('Purchased', 'faracart')}
                value={formatNumber(row.converted)}
                hint={__('after FaraCart interaction', 'faracart')}
              />
              <StatCell
                label={__('Attributed Sales', 'faracart')}
                value={formatCurrency(row.attributed_revenue)}
              />
              <StatCell
                label={__('Estimated Profit', 'faracart')}
                value={profitValue}
                hint={profitHint}
              />
            </Box>
          </Box>

          {/* Customer journey — the detailed funnel with drop-off (§20/§23). */}
          <Box>
            <SectionTitle>{__('Customer Journey', 'faracart')}</SectionTitle>
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
                'A completion means the customer reached the mission target. A purchase means a qualifying order was actually associated with the mission.',
                'faracart'
              )}
            </Typography>
          </Box>

          <Divider />

          {/* Costs — reward cost, shipping cost and estimated profit in every
              data state (reuses the shared EstimatedProfitCard). */}
          <Box>
            <SectionTitle>{__('Costs', 'faracart')}</SectionTitle>
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

          {/* Upsells (UPSELL_REFACTOR §34) — the mission's own upsell funnel:
              impressions → clicks → adds → assisted completions →
              purchases, all session+mission-scoped from the same event log
              the Upsells page reads. The section title uses the canonical
              §40 label (Upsells), matching the navigation. */}
          <Box>
            <SectionTitle>{__('Upsells', 'faracart')}</SectionTitle>
            <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 0.25 }}>
              {__(
                'Products recommended to customers who were working toward this mission.',
                'faracart'
              )}
            </Typography>
            <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 1.5, mt: 1 }}>
              <StatCell
                label={__('Impressions', 'faracart')}
                value={formatNumber(row.upsell_funnel.impressions)}
              />
              <StatCell
                label={__('Clicks', 'faracart')}
                value={formatNumber(row.upsell_funnel.clicks)}
              />
              <StatCell
                label={__('Added to cart', 'faracart')}
                value={formatNumber(row.upsell_funnel.adds)}
              />
              <StatCell
                label={__('Purchased', 'faracart')}
                value={formatNumber(row.upsell_funnel.orders)}
              />
              <StatCell
                label={__('Assisted completions', 'faracart')}
                value={formatNumber(row.upsell_assisted)}
              />
              <StatCell
                label={__('Assisted rate', 'faracart')}
                value={
                  row.upsell_assisted_rate === null ? '—' : formatPercent(row.upsell_assisted_rate)
                }
                hint={__('of completions that saw a recommendation', 'faracart')}
              />
            </Box>
          </Box>

          <Divider />

          {/* Recommendations (UPSELL_REFACTOR §34) — current vs recommended
              target with an explicit apply action. */}
          <MissionOptimizationSection missionId={row.mission_id} currentTarget={row.target} />

          <Divider />

          {/* Advanced attribution details (§20 Revenue). */}
          <Accordion
            disableGutters
            square
            sx={{ boxShadow: 'none', border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
          >
            <AccordionSummary expandIcon={<ExpandMoreIcon />}>
              <SectionTitle>{__('Advanced attribution details', 'faracart')}</SectionTitle>
            </AccordionSummary>
            <AccordionDetails sx={{ pt: 0 }}>
              <Stack spacing={1}>
                <StatRow
                  label={__('Direct revenue', 'faracart')}
                  value={formatCurrency(row.attributed_revenue)}
                  explanation={__(
                    'The incremental order value from orders where customers progressed or completed this mission before ordering.',
                    'faracart'
                  )}
                />
                <StatRow
                  label={__('Assisted revenue', 'faracart')}
                  value={formatCurrency(row.assisted_revenue)}
                  explanation={__(
                    'Order totals from orders that were only exposed to this mission, never progressed.',
                    'faracart'
                  )}
                />
                <StatRow
                  label={__('Influenced sales', 'faracart')}
                  value={formatCurrency(row.influenced_revenue)}
                  explanation={__(
                    'Order totals of every order associated with this mission — distinct orders, never double counted.',
                    'faracart'
                  )}
                />
                <StatRow
                  label={__('Incremental cart value', 'faracart')}
                  value={formatCurrency(row.incremental_cart_value)}
                  explanation={__(
                    'Average cart value after mission exposure minus the value at first exposure, per session.',
                    'faracart'
                  )}
                />
                <Typography variant="caption" color="text.secondary" component="p">
                  {__(
                    'Direct revenue is the incremental amount this mission moved — it is a slice of the influenced total, not an extra value on top of it.',
                    'faracart'
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
              <SectionTitle>{__('Advanced', 'faracart')}</SectionTitle>
            </AccordionSummary>
            <AccordionDetails sx={{ pt: 0 }}>
              <Stack spacing={1}>
                <StatRow
                  label={__('Attribution model', 'faracart')}
                  value={__('Direct & assisted', 'faracart')}
                  explanation={__(
                    'Customers who progressed or completed the mission before ordering are direct; exposure-only sessions are assisted.',
                    'faracart'
                  )}
                />
                <StatRow
                  label={__('Attribution window', 'faracart')}
                  value={sprintf(
                    /* translators: %d: number of days. */
                    __('%d days before the order', 'faracart'),
                    row.attribution_window_days
                  )}
                />
                <StatRow
                  label={__('Data sufficiency', 'faracart')}
                  value={sufficiency.label}
                  explanation={sufficiency.hint}
                />
                <StatRow
                  label={__('Average basket increase', 'faracart')}
                  value={aovImpact !== null ? formatSignedPercent(aovImpact) : '—'}
                  explanation={__(
                    'Observed impact — incremental cart value relative to the baseline. It does not prove that FaraCart caused the difference.',
                    'faracart'
                  )}
                />
                <StatRow
                  label={__('Attributed orders', 'faracart')}
                  value={formatNumber(row.converted)}
                  explanation={__(
                    'Distinct orders associated with this mission in the selected period.',
                    'faracart'
                  )}
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
 * Mission Performance (redesign).
 *
 * Per-mission commercial outcomes from `GET /faracart/v1/revenue/missions`:
 * the funnel counts (viewed → progressed → completed → purchased), the
 * purchase rate (purchased / completed — never confused with completion
 * rate, §17/§28), sales attributed to FaraCart and estimated profit.
 * Each row opens a detail drawer with the full customer journey, costs
 * and the advanced attribution details behind accordions (§20).
 */
export default function MissionPerformance() {
  const { range } = useDateRange();
  const [missionId, setMissionId] = useState<number>(0);
  const [sortKey, setSortKey] = useState<SortKey>('attributed_revenue');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [selected, setSelected] = useState<MissionPerformanceRow | null>(null);
  const [page, setPage] = useState(0);

  const query = useQuery({
    queryKey: ['revenue', 'missions', { from: range.from, to: range.to, missionId }],
    queryFn: () =>
      fetchMissionPerformance({
        from: range.from,
        to: range.to,
        mission_id: missionId || undefined,
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
    setPage(0);
  };

  const PER_PAGE = 10;
  const pageCount = Math.max(1, Math.ceil(sorted.length / PER_PAGE));
  const safePage = Math.min(page, pageCount - 1);
  const pagedRows = sorted.slice(safePage * PER_PAGE, (safePage + 1) * PER_PAGE);

  return (
    <PageContainer
      title={__('Mission Performance', 'faracart')}
      description={__(
        'Which missions generated purchases, sales and profit? Completion is not the same as purchase.',
        'faracart'
      )}
    >
      <RevenueToolbar missionId={missionId} onMissionChange={setMissionId} />

      {query.isError && (
        <Alert severity="error" variant="outlined">
          {query.error instanceof Error
            ? query.error.message
            : __('Could not load mission performance.', 'faracart')}
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
          title={__('No mission performance yet', 'faracart')}
          description={__(
            'Once customers start interacting with your missions, FaraCart will show purchases, sales and profit per mission here.',
            'faracart'
          )}
        />
      ) : (
        <>
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
                {pagedRows.map((row) => (
                  <TableRow
                    key={row.mission_id}
                    hover
                    tabIndex={0}
                    sx={{
                      cursor: 'pointer',
                      '&:focus-visible': {
                        outline: '2px solid',
                        outlineColor: 'primary.main',
                        outlineOffset: -2,
                      },
                    }}
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
                      /* translators: %s: mission name. */
                      __('Open performance details for %s', 'faracart'),
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
                    <TableCell align="right">{formatNumber(row.upsell_assisted)}</TableCell>
                    <TableCell align="right">{formatCurrency(row.attributed_revenue)}</TableCell>
                    <TableCell align="right">
                      {row.profit_available && row.profit_impact !== null ? (
                        formatCurrency(row.profit_impact)
                      ) : (
                        <Tooltip
                          title={
                            row.profit_reason_code === 'missing_product_cost'
                              ? __('Add product cost data to estimate profit.', 'faracart')
                              : row.profit_reason_code === 'incomplete_product_cost'
                                ? __(
                                    'Some orders do not have complete cost information.',
                                    'faracart'
                                  )
                                : __('Not enough attributed order data yet.', 'faracart')
                          }
                          arrow
                        >
                          <Box
                            component="span"
                            sx={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: 0.5,
                              color: 'text.secondary',
                            }}
                          >
                            <SavingsIcon sx={{ fontSize: 14 }} />—
                          </Box>
                        </Tooltip>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>

          <NumberPagination
            count={sorted.length}
            page={safePage}
            rowsPerPage={PER_PAGE}
            onPageChange={setPage}
          />
        </>
      )}

      <MissionDetailDrawer row={selected} onClose={() => setSelected(null)} />
    </PageContainer>
  );
}
