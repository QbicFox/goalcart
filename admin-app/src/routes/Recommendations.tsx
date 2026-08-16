import CheckCircleOutlineOutlinedIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import TipsAndUpdatesIcon from '@mui/icons-material/TipsAndUpdates';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Collapse from '@mui/material/Collapse';
import Divider from '@mui/material/Divider';
import LinearProgress from '@mui/material/LinearProgress';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type ReactElement } from 'react';

import { fetchGoals } from '../api/goals';
import { applyGoalRecommendation, fetchCostCoverage, fetchGoalRecommendations } from '../api/revenue';
import { getBootData } from '../boot';
import ConfirmDialog from '../components/ConfirmDialog';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber, formatPercent, formatPercentValue } from '../lib/format';
import { REWARD_LABELS } from '../templates/rewardLabel';
import type { CostCoveragePayload, GoalRecommendationsPayload, RecommendationCandidate, RecommendationGoalHistory } from '../types';

/**
 * Business-friendly confidence label (Improvement.md §33 — the raw 0–100
 * score stays in the Advanced details). High ≥ 75, Medium ≥ 60, else Low.
 */
function confidenceTier(confidence: number): { label: string; color: 'success' | 'warning' | 'default'; icon: ReactElement } {
  if (confidence >= 75) {
    return { label: __('High', 'faracart'), color: 'success', icon: <CheckCircleOutlineOutlinedIcon fontSize="small" /> };
  }
  if (confidence >= 60) {
    return { label: __('Medium', 'faracart'), color: 'warning', icon: <InfoOutlinedIcon fontSize="small" /> };
  }
  return { label: __('Low', 'faracart'), color: 'default', icon: <InfoOutlinedIcon fontSize="small" /> };
}

/** Data-sufficiency tier translated to business language (§45). */
function sufficiencyLabel(status: string): string {
  if (status === 'high_confidence') {
    return __('Good data', 'faracart');
  }
  if (status === 'reliable') {
    return __('Moderate data', 'faracart');
  }
  return __('Limited data', 'faracart');
}

/** Small caption/value stat (optional progress bar) used in the cards. */
function StatBox({
  label,
  value,
  bar,
}: {
  label: string;
  value: string;
  bar?: number;
}) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" sx={{ fontWeight: 600 }}>
        {value}
      </Typography>
      {bar !== undefined && (
        <LinearProgress
          variant="determinate"
          value={Math.min(100, Math.max(0, bar))}
          sx={{ height: 4, borderRadius: 2, mt: 0.5 }}
        />
      )}
    </Box>
  );
}

/** Compact label/value pair for the raw scoring factors. */
function Factor({ label, value }: { label: string; value: string }) {
  return (
    <Box sx={{ display: 'flex', alignItems: 'baseline', gap: 1 }}>
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" sx={{ fontWeight: 600 }}>
        {value}
      </Typography>
    </Box>
  );
}

/**
 * The raw scoring detail block (Improvement.md §33 — score, component
 * scores, ratios and availability flags). Only rendered inside the
 * "Advanced details" expander of the top recommendation card — never as
 * the primary experience.
 */
function AdvancedDetails({ candidate }: { candidate: RecommendationCandidate }) {
  const factors = candidate.factors;

  return (
    <Stack spacing={1.5}>
      <Typography variant="body2" sx={{ fontWeight: 600 }}>
        {__('Advanced details', 'faracart')}
      </Typography>

      <Box
        sx={{
          display: 'grid',
          gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' },
          gap: 1.5,
        }}
      >
        <StatBox label={__('Score', 'faracart')} value={`${formatNumber(candidate.score)} / 100`} bar={candidate.score} />
        <StatBox
          label={__('Confidence', 'faracart')}
          value={formatPercentValue(candidate.confidence)}
          bar={candidate.confidence}
        />
        <StatBox
          label={__('Expected completion', 'faracart')}
          value={formatPercent(candidate.expected_completion_rate)}
        />
        <StatBox
          label={__('Reachable orders', 'faracart')}
          value={formatPercentValue(candidate.reachable_orders_pct)}
        />
        {candidate.reward_cost !== null && (
          <StatBox label={__('Estimated reward cost', 'faracart')} value={formatCurrency(candidate.reward_cost)} />
        )}
      </Box>

      <Box>
        <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 600 }}>
          {__('Scoring factors', 'faracart')}
        </Typography>
        <Box
          sx={{
            display: 'grid',
            gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' },
            gap: 1.5,
            mt: 0.75,
          }}
        >
          <StatBox label={__('Reachability', 'faracart')} value={formatNumber(factors.reachability_score)} />
          <StatBox label={__('Distance', 'faracart')} value={formatNumber(factors.distance_score)} />
          <StatBox label={__('Economics', 'faracart')} value={formatNumber(factors.economics_score)} />
          <StatBox label={__('History', 'faracart')} value={formatNumber(factors.history_score)} />
        </Box>
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, mt: 1 }}>
          {factors.aov_ratio !== null && (
            <Factor label={__('AOV ratio', 'faracart')} value={`${formatNumber(factors.aov_ratio)}×`} />
          )}
          {factors.median_ratio !== null && (
            <Factor label={__('Median ratio', 'faracart')} value={`${formatNumber(factors.median_ratio)}×`} />
          )}
          <Factor label={__('Reach share', 'faracart')} value={formatPercent(factors.reach_share)} />
          <Factor label={__('Already at share', 'faracart')} value={formatPercent(factors.already_at_share)} />
          {/* margin_pct is a 0–1 rate (e.g. 0.6 = 60%) — formatPercent
              multiplies by 100; formatPercentValue would print "0.6%". */}
          {factors.margin_pct !== null && (
            <Factor label={__('Margin', 'faracart')} value={formatPercent(factors.margin_pct)} />
          )}
        </Box>
      </Box>
    </Stack>
  );
}

/**
 * The "Current Goal" block of the recommendation detail (UPSELL_REFACTOR
 * §9): current threshold, reward, completion + purchase rates, attributed
 * sales and estimated profit — all real analytics data, never fabricated.
 */
function CurrentGoalBlock({ history }: { history: RecommendationGoalHistory | null }) {
  if (!history) {
    return null;
  }

  const profitValue =
    history.profit_available && history.estimated_profit !== null
      ? formatCurrency(history.estimated_profit)
      : __('Not available', 'faracart');

  return (
    <Box sx={{ mb: 2 }}>
      <Typography variant="body2" sx={{ fontWeight: 600 }}>
        {__('Current goal', 'faracart')}
      </Typography>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' }, gap: 1.5, mt: 1 }}>
        <StatBox label={__('Current target', 'faracart')} value={formatCurrency(history.current_target)} />
        <StatBox
          label={__('Reward', 'faracart')}
          value={history.reward_type ? (REWARD_LABELS[history.reward_type] ?? history.reward_type) : __('None', 'faracart')}
        />
        <StatBox
          label={__('Completion rate', 'faracart')}
          value={history.completion_rate === null ? '—' : formatPercent(history.completion_rate)}
        />
        <StatBox
          label={__('Purchase rate', 'faracart')}
          value={history.purchase_rate === null ? '—' : formatPercent(history.purchase_rate)}
        />
        <StatBox label={__('Attributed sales', 'faracart')} value={formatCurrency(history.attributed_sales)} />
        <StatBox label={__('Estimated profit', 'faracart')} value={profitValue} />
        <StatBox
          label={__('Upsell-assisted completions', 'faracart')}
          value={formatNumber(history.upsell_assisted)}
        />
      </Box>
    </Box>
  );
}

/** The primary recommendation card (§33) — business outcome first. */
function TopRecommendationCard({
  candidate,
  goalId,
  goalName,
  goalHistory,
  detailsOpen,
  onToggleDetails,
  onApply,
  onDismiss,
}: {
  candidate: RecommendationCandidate;
  goalId: number;
  /** The selected goal's name — makes it unmistakable which goal the card belongs to. */
  goalName: string | null;
  goalHistory: RecommendationGoalHistory | null;
  detailsOpen: boolean;
  onToggleDetails: () => void;
  onApply: (candidate: RecommendationCandidate) => void;
  onDismiss: () => void;
}) {
  const tier = confidenceTier(candidate.confidence);
  const profitAvailable = candidate.expected_profit_available && candidate.expected_profit !== null;

  return (
    <Paper variant="outlined" sx={{ p: 2.5, borderColor: 'primary.main', borderWidth: 2, position: 'relative' }}>
      <Chip
        size="small"
        color="primary"
        icon={<TipsAndUpdatesIcon />}
        label={__('Top recommendation', 'faracart')}
        sx={{ position: 'absolute', top: -12, insetInlineStart: 16 }}
      />
      {goalName && (
        <Chip
          size="small"
          variant="outlined"
          color="primary"
          label={goalName}
          sx={{ position: 'absolute', top: -12, insetInlineEnd: 16 }}
        />
      )}

      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 3, alignItems: 'flex-end' }}>
        <Box sx={{ minWidth: 190 }}>
          <Typography variant="caption" color="text.secondary">
            {__('Recommended Goal Target', 'faracart')}
          </Typography>
          <Typography variant="h4" component="p" sx={{ m: 0, fontWeight: 700 }}>
            {formatCurrency(candidate.threshold)}
          </Typography>
        </Box>

        <Box>
          <Chip
            size="small"
            variant="outlined"
            color={tier.color}
            icon={tier.icon}
            label={`${__('Confidence', 'faracart')}: ${tier.label}`}
          />
        </Box>

        <Box>
          <Typography variant="caption" color="text.secondary">
            {__('Expected impact', 'faracart')}
          </Typography>
          <Typography variant="h6" component="p" sx={{ m: 0 }}>
            +{formatPercentValue(candidate.expected_aov_impact.low)} – +{formatPercentValue(candidate.expected_aov_impact.high)}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {__('average basket value', 'faracart')}
          </Typography>
        </Box>

        <Box>
          <Typography variant="caption" color="text.secondary">
            {__('Expected profit', 'faracart')}
          </Typography>
          <Typography variant="h6" component="p" sx={{ m: 0 }}>
            {profitAvailable ? formatCurrency(candidate.expected_profit as number) : __('Not available', 'faracart')}
          </Typography>
          {!profitAvailable && (
            <Typography variant="caption" color="text.secondary">
              {__('Add product cost data to estimate profitability.', 'faracart')}
            </Typography>
          )}
        </Box>
      </Box>

      {/* Why? — the plain-English reasons belong on the primary view (§33). */}
      <Box sx={{ mt: 2 }}>
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {__('Why?', 'faracart')}
        </Typography>
        <Stack spacing={0.5} sx={{ mt: 0.5 }}>
          {candidate.reasons.map((reason, index) => (
            <Typography key={`${reason}-${index}`} variant="body2" color="text.secondary">
              • {reason}
            </Typography>
          ))}
        </Stack>
      </Box>

      <Collapse in={detailsOpen} timeout="auto" unmountOnExit>
        <Box sx={{ mt: 1.5, pt: 1.5, borderTop: 1, borderColor: 'divider' }}>
          <CurrentGoalBlock history={goalHistory} />
          <AdvancedDetails candidate={candidate} />
        </Box>
      </Collapse>

      <Divider sx={{ my: 2 }} />

      <Stack direction="row" spacing={1}>
        <Button
          variant="contained"
          startIcon={<CheckCircleOutlineOutlinedIcon />}
          disabled={goalId < 1}
          onClick={() => onApply(candidate)}
        >
          {__('Apply recommendation', 'faracart')}
        </Button>
        <Button variant="outlined" onClick={onToggleDetails} aria-expanded={detailsOpen}>
          {detailsOpen ? __('Hide details', 'faracart') : __('View details', 'faracart')}
        </Button>
        <Button variant="text" color="inherit" onClick={onDismiss}>
          {__('Dismiss', 'faracart')}
        </Button>
      </Stack>
    </Paper>
  );
}

/** The analyzed store data (§33 keeps the context behind the "why"). */
function AnalyzedData({ payload }: { payload: GoalRecommendationsPayload }) {
  const data = payload.data;

  if (!data) {
    return null;
  }

  return (
    <Paper variant="outlined" sx={{ p: 2 }}>
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1.5 }}>
        <InfoOutlinedIcon fontSize="small" color="action" />
        <Typography variant="h6" component="h3" sx={{ mb: 0 }}>
          {__('Analyzed store data', 'faracart')}
        </Typography>
      </Stack>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' }, gap: 2 }}>
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Average order value', 'faracart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {formatCurrency(data.aov)}
          </Typography>
        </Stack>
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Median order value', 'faracart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {formatCurrency(data.median)}
          </Typography>
        </Stack>
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Orders analyzed', 'faracart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {formatNumber(payload.orders)}
          </Typography>
        </Stack>
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Window', 'faracart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {sprintf(
              /* translators: 1: days. */
              __('%d days', 'faracart'),
              payload.window_days
            )}
          </Typography>
        </Stack>
        {data.shipping.available && (
          <Stack spacing={0.5}>
            <Typography variant="caption" color="text.secondary">
              {__('Avg. shipping', 'faracart')}
            </Typography>
            <Typography variant="body1" sx={{ fontWeight: 600 }}>
              {formatCurrency(data.shipping.average_shipping ?? 0)}
            </Typography>
          </Stack>
        )}
        {data.margin && data.margin.available && (
          <Stack spacing={0.5}>
            <Typography variant="caption" color="text.secondary">
              {__('Avg. margin', 'faracart')}
            </Typography>
            <Typography variant="body1" sx={{ fontWeight: 600 }}>
              {/* average_margin_pct is a 0–1 rate; the formatter renders
                  null as "—" (never a fabricated 0%). */}
              {formatPercent(data.margin.average_margin_pct)}
            </Typography>
          </Stack>
        )}
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Data sufficiency', 'faracart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {sufficiencyLabel(payload.status)}
          </Typography>
        </Stack>
      </Box>

      {/* Order distribution — the engine sends an array of buckets with a
          0–1 `share` rate each; render the bucket's translated label and
          the share as a real percentage (never NaN on a 0-denominator). */}
      <Box sx={{ mt: 2 }}>
        <Typography variant="caption" color="text.secondary">
          {__('Order value distribution (share of orders)', 'faracart')}
        </Typography>
        <Stack spacing={0.75} sx={{ mt: 1 }}>
          {data.distribution.map((bucket) => (
            <Box key={bucket.label}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.25 }}>
                <Typography variant="caption">{bucket.label}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {formatPercent(bucket.share)}
                </Typography>
              </Box>
              <LinearProgress
                variant="determinate"
                value={Math.min(100, Math.max(0, bucket.share * 100))}
                sx={{ height: 5, borderRadius: 3 }}
              />
            </Box>
          ))}
        </Stack>
      </Box>
    </Paper>
  );
}

/**
 * Recommendations (Phase 33.4 engine — UPSELL_REFACTOR §4/§5/§8;
 * UICHANGES.md §40 label).
 *
 * The admin-facing surface that answers "what Goal configuration should
 * I use?" — the `GET /faracart/v1/revenue/goal-recommendations` payload:
 * analyzed store data plus the single best recommendation. The backend
 * engine generates and ranks every eligible candidate deterministically
 * (score desc, ties → lower threshold) and returns the best one as
 * `recommendation`; this page renders ONLY that one — never a list of
 * competing candidates (UICHANGES.md Best-Recommendation UX). It
 * recommends Goal targets and reward economics only — never products
 * (product recommendations belong to Upsells, §11/§59). The card answers
 * "what threshold should I use and why?" (§9: Current Goal → Recommended
 * Goal → Why?), the raw scoring details live behind the Advanced details
 * expander, and an unavailable expected profit explains how to enable it
 * (§24). Applying is always an explicit admin action (ConfirmDialog → the
 * dedicated apply endpoint, which changes only the goal target and
 * records the feedback-loop event) — the engine itself never modifies a
 * goal (§10/§41).
 *
 * Goal selection is REQUIRED: the page opens with no goal selected and
 * shows an instruction state (the API is not called, no fake loading),
 * the admin picks exactly one goal, and the analysis runs only for that
 * goal (`goal_id` is required by the endpoint and echoed back for
 * ownership validation). There is no "all goals" mode and no reward-type
 * filter — reward type stays part of each goal's data model, it is just
 * never an independent page-level filter. Switching goals clears the
 * previous goal's card before the new one loads, so a stale
 * recommendation can never survive a goal change.
 */
export default function Recommendations() {
  const { range } = useDateRange();
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();

  // Goal selection is REQUIRED (0 = no goal selected): the page never
  // analyzes an "all goals" context and never picks a goal automatically.
  const [goalId, setGoalId] = useState<number>(0);
  const [applyTarget, setApplyTarget] = useState<RecommendationCandidate | null>(null);
  const [topDismissed, setTopDismissed] = useState<boolean>(false);
  const [showTopDetails, setShowTopDetails] = useState<boolean>(false);

  // The store's goals (same query key RevenueToolbar uses, so it is a
  // shared cache): validates that the selected goal still exists and
  // supplies its name for the UI.
  const goalsQuery = useQuery({
    queryKey: ['goals', 'revenue-filter-options'],
    queryFn: () => fetchGoals({ per_page: 100 }),
  });

  const selectedGoal = (goalsQuery.data?.items ?? []).find((goal) => goal.id === goalId) ?? null;

  // A selected goal id that no longer exists (deleted/archived) is
  // invalid — never show recommendations (or a fake loading state) for it.
  const goalMissing = goalId > 0 && goalsQuery.isSuccess && selectedGoal === null;

  const handleGoalChange = (nextGoalId: number) => {
    setGoalId(nextGoalId);
    // Clear every goal-scoped UI state so a previous goal's card, details
    // or apply dialog can never linger while the new goal loads.
    setTopDismissed(false);
    setShowTopDetails(false);
    setApplyTarget(null);
  };

  const query = useQuery({
    queryKey: ['revenue', 'recommendations', { from: range.from, to: range.to, goalId }],
    queryFn: () =>
      fetchGoalRecommendations({
        from: range.from,
        to: range.to,
        goal_id: goalId,
        window_days: 90,
      }),
    // No recommendations without a selected goal: the API is not called
    // (and no fake loading state shown) until a valid goal is chosen.
    enabled: goalId > 0 && !goalMissing,
  });

  // Product-cost coverage (UPSELL_REFACTOR §24/§25/§26): when the store
  // lacks margin data, explain exactly how much of the catalog is covered
  // and where to add costs — never a guessed margin.
  const coverageQuery = useQuery({
    queryKey: ['revenue', 'cost-coverage'],
    queryFn: () => fetchCostCoverage(),
    enabled: query.data?.available === true && query.data?.data?.margin?.available === false,
  });

  const payload = query.data;
  const top = payload?.recommendation;
  const goalHistory = payload?.data?.goal_history ?? null;
  const coverage: CostCoveragePayload | undefined = coverageQuery.data;

  // Ownership guard: a payload must belong to the selected goal — if the
  // response's goal_id does not match, the recommendation is invalid and
  // is never rendered.
  const ownsGoal = payload ? payload.goal_id === goalId : false;

  const applyMutation = useMutation({
    mutationFn: async (target: number) => {
      if (goalId < 1) {
        throw new Error(__('Select a goal to apply the recommendation to.', 'faracart'));
      }

      await applyGoalRecommendation(goalId, target);
    },
    onSuccess: () => {
      notify(__('Goal target updated.', 'faracart'));
      setApplyTarget(null);
      queryClient.invalidateQueries({ queryKey: ['goals'] });
      queryClient.invalidateQueries({ queryKey: ['revenue'] });
    },
    onError: (error: Error) => {
      notify(error.message, 'error');
      setApplyTarget(null);
    },
  });

  const handleApply = (candidate: RecommendationCandidate) => {
    setApplyTarget(candidate);
  };

  return (
    <PageContainer
      title={__('Recommendations', 'faracart')}
      description={__(
        'Improve your Goals using store performance data — which target and reward configuration to use, and why.',
        'faracart'
      )}
    >
      {/* §39: the one-line distinction that removes the conceptual confusion. */}
      <Alert severity="info" variant="outlined" icon={<TipsAndUpdatesIcon fontSize="small" />}>
        {__(
          'Recommendations helps you choose better Goal targets and reward configurations. It does not recommend products — product recommendations for customers live under Upsells.',
          'faracart'
        )}
      </Alert>

      <RevenueToolbar goalId={goalId} onGoalChange={handleGoalChange} goalRequired />

      {goalId < 1 ? (
        <EmptyState
          icon={<TipsAndUpdatesIcon fontSize="large" />}
          title={__('Select a goal', 'faracart')}
          description={__(
            'To see the best optimization recommendation for a goal, first choose one of your store goals.',
            'faracart'
          )}
        />
      ) : goalMissing ? (
        <EmptyState
          icon={<TipsAndUpdatesIcon fontSize="large" />}
          title={__('The selected goal could not be found', 'faracart')}
          description={__('Please select another goal.', 'faracart')}
        />
      ) : query.isError ? (
        <Alert severity="error" variant="outlined">
          {query.error instanceof Error
            ? query.error.message
            : __('Could not load recommendations.', 'faracart')}
        </Alert>
      ) : query.isLoading ? (
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={120} />
          <Skeleton variant="rounded" height={420} />
        </Stack>
      ) : !payload ? null : !payload.available || !ownsGoal ? (
        <EmptyState
          icon={<TipsAndUpdatesIcon fontSize="large" />}
          title={__('No recommendation available', 'faracart')}
          description={payload.insufficient_reason ?? __('Not enough data for a reliable recommendation.', 'faracart')}
        />
      ) : payload.data ? (
        <>
          {/* Top recommendation — business outcome first (§33). */}
          {top && !topDismissed && (
            <TopRecommendationCard
              candidate={top}
              goalId={goalId}
              goalName={selectedGoal?.name ?? null}
              goalHistory={goalHistory}
              detailsOpen={showTopDetails}
              onToggleDetails={() => setShowTopDetails((current) => !current)}
              onApply={handleApply}
              onDismiss={() => setTopDismissed(true)}
            />
          )}

          {/* §24/§25/§26: margin data missing → show the coverage and the
              path to enable profit estimates instead of a guessed number. */}
          {payload.data.margin && !payload.data.margin.available && coverage && (
            <Alert
              severity="warning"
              variant="outlined"
              action={
                <Button
                  size="small"
                  color="inherit"
                  href={`${getBootData().adminUrl}edit.php?post_type=product`}
                  target="_blank"
                  rel="noreferrer"
                >
                  {__('Manage product costs', 'faracart')}
                </Button>
              }
            >
              {coverage.product_coverage.coverage_pct !== null
                ? sprintf(
                    /* translators: 1: products with cost, 2: total products, 3: coverage percentage. */
                    __('Product Cost Coverage: %1$s / %2$s products (%3$s). Profit estimates need product cost data — add it to enable Goal economics.', 'faracart'),
                    formatNumber(coverage.product_coverage.products_with_cost),
                    formatNumber(coverage.product_coverage.total_products),
                    // coverage_pct is already a 0–100 percentage point value
                    // (never divide by 100 and print as a 0–1 rate).
                    formatPercentValue(coverage.product_coverage.coverage_pct)
                  )
                : __('Profit estimates need product cost data. Add product costs on your products to enable Goal economics.', 'faracart')}
            </Alert>
          )}

          {/* Restore the dismissed top recommendation. */}
          {top && topDismissed && (
            <Button size="small" variant="text" startIcon={<TipsAndUpdatesIcon />} onClick={() => setTopDismissed(false)}>
              {__('Show the top recommendation again', 'faracart')}
            </Button>
          )}

          {/* Analyzed store data — the context behind the "why". */}
          <AnalyzedData payload={payload} />

        </>
      ) : null}

      <ConfirmDialog
        open={applyTarget !== null}
        title={__('Apply recommendation?', 'faracart')}
        description={
          applyTarget ? (
            <>
              {goalHistory ? (
                <>
                  {sprintf(
                    /* translators: 1: current target. */
                    __('Current target: %1$s', 'faracart'),
                    formatCurrency(goalHistory.current_target)
                  )}{' '}
                  {sprintf(
                    /* translators: 1: recommended target. */
                    __('→ %1$s', 'faracart'),
                    formatCurrency(applyTarget.threshold)
                  )}
                </>
              ) : (
                sprintf(
                  /* translators: 1: threshold. */
                  __('Set the goal target to %s?', 'faracart'),
                  formatCurrency(applyTarget.threshold)
                )
              )}{' '}
              {__('This changes a production goal — the action is not reversible from here.', 'faracart')}
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
    </PageContainer>
  );
}
