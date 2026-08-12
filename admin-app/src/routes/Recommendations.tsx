import CheckCircleOutlineOutlinedIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import TipsAndUpdatesIcon from '@mui/icons-material/TipsAndUpdates';
import { useQuery } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Collapse from '@mui/material/Collapse';
import Divider from '@mui/material/Divider';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type ReactElement } from 'react';

import { updateGoal } from '../api/goals';
import { fetchGoalRecommendations } from '../api/revenue';
import ConfirmDialog from '../components/ConfirmDialog';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber, formatPercent, formatPercentValue } from '../lib/format';
import type { GoalRecommendationsPayload, RecommendationCandidate } from '../types';

/** Reward-type filter options (matches the analytics reward filter). */
const REWARD_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: __('Auto (from goal)', 'goalcart') },
  { value: 'free_shipping', label: __('Free shipping', 'goalcart') },
  { value: 'percent_discount', label: __('% discount', 'goalcart') },
  { value: 'fixed_discount', label: __('Fixed discount', 'goalcart') },
  { value: 'free_gift', label: __('Free gift', 'goalcart') },
  { value: 'coupon', label: __('Coupon', 'goalcart') },
];

/** Distribution bucket label (AOV-relative ranges from the engine). */
function formatBucket(key: string): string {
  return key.replace('_', '–').replace('>', '> ').replace('<', '< ');
}

/**
 * Business-friendly confidence label (Improvement.md §33 — the raw 0–100
 * score stays in the Advanced details). High ≥ 75, Medium ≥ 60, else Low.
 */
function confidenceTier(confidence: number): { label: string; color: 'success' | 'warning' | 'default'; icon: ReactElement } {
  if (confidence >= 75) {
    return { label: __('High', 'goalcart'), color: 'success', icon: <CheckCircleOutlineOutlinedIcon fontSize="small" /> };
  }
  if (confidence >= 60) {
    return { label: __('Medium', 'goalcart'), color: 'warning', icon: <InfoOutlinedIcon fontSize="small" /> };
  }
  return { label: __('Low', 'goalcart'), color: 'default', icon: <InfoOutlinedIcon fontSize="small" /> };
}

/** Data-sufficiency tier translated to business language (§45). */
function sufficiencyLabel(status: string): string {
  if (status === 'high_confidence') {
    return __('Good data', 'goalcart');
  }
  if (status === 'reliable') {
    return __('Moderate data', 'goalcart');
  }
  return __('Limited data', 'goalcart');
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
 * "Advanced details" expander of the top card and candidate rows — never
 * as the primary experience.
 */
function AdvancedDetails({ candidate }: { candidate: RecommendationCandidate }) {
  const factors = candidate.factors;

  return (
    <Stack spacing={1.5}>
      <Typography variant="body2" sx={{ fontWeight: 600 }}>
        {__('Advanced details', 'goalcart')}
      </Typography>

      <Box
        sx={{
          display: 'grid',
          gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' },
          gap: 1.5,
        }}
      >
        <StatBox label={__('Score', 'goalcart')} value={`${formatNumber(candidate.score)} / 100`} bar={candidate.score} />
        <StatBox
          label={__('Confidence', 'goalcart')}
          value={formatPercentValue(candidate.confidence)}
          bar={candidate.confidence}
        />
        <StatBox
          label={__('Expected completion', 'goalcart')}
          value={formatPercent(candidate.expected_completion_rate)}
        />
        <StatBox
          label={__('Reachable orders', 'goalcart')}
          value={formatPercentValue(candidate.reachable_orders_pct)}
        />
        {candidate.reward_cost !== null && (
          <StatBox label={__('Estimated reward cost', 'goalcart')} value={formatCurrency(candidate.reward_cost)} />
        )}
      </Box>

      <Box>
        <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 600 }}>
          {__('Scoring factors', 'goalcart')}
        </Typography>
        <Box
          sx={{
            display: 'grid',
            gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' },
            gap: 1.5,
            mt: 0.75,
          }}
        >
          <StatBox label={__('Reachability', 'goalcart')} value={formatNumber(factors.reachability_score)} />
          <StatBox label={__('Distance', 'goalcart')} value={formatNumber(factors.distance_score)} />
          <StatBox label={__('Economics', 'goalcart')} value={formatNumber(factors.economics_score)} />
          <StatBox label={__('History', 'goalcart')} value={formatNumber(factors.history_score)} />
        </Box>
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, mt: 1 }}>
          {factors.aov_ratio !== null && (
            <Factor label={__('AOV ratio', 'goalcart')} value={`${formatNumber(factors.aov_ratio)}×`} />
          )}
          {factors.median_ratio !== null && (
            <Factor label={__('Median ratio', 'goalcart')} value={`${formatNumber(factors.median_ratio)}×`} />
          )}
          <Factor label={__('Reach share', 'goalcart')} value={formatPercent(factors.reach_share)} />
          <Factor label={__('Already at share', 'goalcart')} value={formatPercent(factors.already_at_share)} />
          {factors.margin_pct !== null && (
            <Factor label={__('Margin', 'goalcart')} value={formatPercentValue(factors.margin_pct)} />
          )}
        </Box>
      </Box>
    </Stack>
  );
}

/** The primary recommendation card (§33) — business outcome first. */
function TopRecommendationCard({
  candidate,
  goalId,
  detailsOpen,
  onToggleDetails,
  onApply,
  onDismiss,
}: {
  candidate: RecommendationCandidate;
  goalId: number;
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
        label={__('Top recommendation', 'goalcart')}
        sx={{ position: 'absolute', top: -12, insetInlineStart: 16 }}
      />

      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 3, alignItems: 'flex-end' }}>
        <Box sx={{ minWidth: 190 }}>
          <Typography variant="caption" color="text.secondary">
            {__('Recommended Goal Target', 'goalcart')}
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
            label={`${__('Confidence', 'goalcart')}: ${tier.label}`}
          />
        </Box>

        <Box>
          <Typography variant="caption" color="text.secondary">
            {__('Expected impact', 'goalcart')}
          </Typography>
          <Typography variant="h6" component="p" sx={{ m: 0 }}>
            +{formatPercentValue(candidate.expected_aov_impact.low)} – +{formatPercentValue(candidate.expected_aov_impact.high)}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {__('average basket value', 'goalcart')}
          </Typography>
        </Box>

        <Box>
          <Typography variant="caption" color="text.secondary">
            {__('Expected profit', 'goalcart')}
          </Typography>
          <Typography variant="h6" component="p" sx={{ m: 0 }}>
            {profitAvailable ? formatCurrency(candidate.expected_profit as number) : __('Not available', 'goalcart')}
          </Typography>
          {!profitAvailable && (
            <Typography variant="caption" color="text.secondary">
              {__('Add product cost data to estimate profitability.', 'goalcart')}
            </Typography>
          )}
        </Box>
      </Box>

      {/* Why? — the plain-English reasons belong on the primary view (§33). */}
      <Box sx={{ mt: 2 }}>
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {__('Why?', 'goalcart')}
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
          {__('Apply recommendation', 'goalcart')}
        </Button>
        <Button variant="outlined" onClick={onToggleDetails} aria-expanded={detailsOpen}>
          {detailsOpen ? __('Hide details', 'goalcart') : __('View details', 'goalcart')}
        </Button>
        <Button variant="text" color="inherit" onClick={onDismiss}>
          {__('Dismiss', 'goalcart')}
        </Button>
        {goalId < 1 && (
          <Typography variant="caption" color="text.secondary" sx={{ alignSelf: 'center' }}>
            {__('Select a goal above to enable applying.', 'goalcart')}
          </Typography>
        )}
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
          {__('Analyzed store data', 'goalcart')}
        </Typography>
      </Stack>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' }, gap: 2 }}>
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Average order value', 'goalcart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {formatCurrency(data.aov)}
          </Typography>
        </Stack>
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Median order value', 'goalcart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {formatCurrency(data.median)}
          </Typography>
        </Stack>
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Orders analyzed', 'goalcart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {formatNumber(payload.orders)}
          </Typography>
        </Stack>
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Window', 'goalcart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {sprintf(
              /* translators: 1: days. */
              __('%d days', 'goalcart'),
              payload.window_days
            )}
          </Typography>
        </Stack>
        {data.shipping.available && (
          <Stack spacing={0.5}>
            <Typography variant="caption" color="text.secondary">
              {__('Avg. shipping', 'goalcart')}
            </Typography>
            <Typography variant="body1" sx={{ fontWeight: 600 }}>
              {formatCurrency(data.shipping.average_shipping ?? 0)}
            </Typography>
          </Stack>
        )}
        {data.margin && data.margin.available && (
          <Stack spacing={0.5}>
            <Typography variant="caption" color="text.secondary">
              {__('Avg. margin', 'goalcart')}
            </Typography>
            <Typography variant="body1" sx={{ fontWeight: 600 }}>
              {formatPercent(data.margin.average_margin_pct ?? 0)}
            </Typography>
          </Stack>
        )}
        <Stack spacing={0.5}>
          <Typography variant="caption" color="text.secondary">
            {__('Data sufficiency', 'goalcart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {sufficiencyLabel(payload.status)}
          </Typography>
        </Stack>
      </Box>

      {/* Order distribution */}
      <Box sx={{ mt: 2 }}>
        <Typography variant="caption" color="text.secondary">
          {__('Order value distribution (share of orders)', 'goalcart')}
        </Typography>
        <Stack spacing={0.75} sx={{ mt: 1 }}>
          {Object.entries(data.distribution).map(([bucket, share]) => (
            <Box key={bucket}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.25 }}>
                <Typography variant="caption">{formatBucket(bucket)}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {formatPercent(share)}
                </Typography>
              </Box>
              <LinearProgress
                variant="determinate"
                value={Math.min(100, share * 100)}
                sx={{ height: 5, borderRadius: 3 }}
              />
            </Box>
          ))}
        </Stack>
      </Box>
    </Paper>
  );
}

/** One ranked candidate — threshold + impact + confidence, details hidden. */
function CandidateRow({
  candidate,
  goalId,
  expanded,
  onToggle,
  onApply,
}: {
  candidate: RecommendationCandidate;
  goalId: number;
  expanded: boolean;
  onToggle: () => void;
  onApply: (candidate: RecommendationCandidate) => void;
}) {
  const tier = confidenceTier(candidate.confidence);

  return (
    <Paper variant="outlined" sx={{ p: 1.5 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap' }}>
        <Box sx={{ minWidth: 150 }}>
          <Typography variant="body2" color="text.secondary">
            {__('Threshold', 'goalcart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            {formatCurrency(candidate.threshold)}
          </Typography>
        </Box>
        <Box sx={{ minWidth: 180 }}>
          <Typography variant="body2" color="text.secondary">
            {__('Expected impact', 'goalcart')}
          </Typography>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            +{formatPercentValue(candidate.expected_aov_impact.low)} – +{formatPercentValue(candidate.expected_aov_impact.high)}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {__('average basket value', 'goalcart')}
          </Typography>
        </Box>
        <Chip
          size="small"
          variant="outlined"
          color={tier.color}
          icon={tier.icon}
          label={`${__('Confidence', 'goalcart')}: ${tier.label}`}
        />
        <Box sx={{ flexGrow: 1 }} />
        <Button
          size="small"
          variant="outlined"
          endIcon={<ExpandMoreIcon sx={{ transform: expanded ? 'rotate(180deg)' : 'none' }} />}
          onClick={onToggle}
          aria-expanded={expanded}
        >
          {__('Details', 'goalcart')}
        </Button>
        <Button size="small" variant="contained" disabled={goalId < 1} onClick={() => onApply(candidate)}>
          {__('Apply', 'goalcart')}
        </Button>
      </Box>
      <Collapse in={expanded} timeout="auto" unmountOnExit>
        <Box sx={{ mt: 1.5, pt: 1.5, borderTop: 1, borderColor: 'divider' }}>
          <Box sx={{ mb: 1.5 }}>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>
              {__('Why?', 'goalcart')}
            </Typography>
            <Stack spacing={0.5} sx={{ mt: 0.5 }}>
              {candidate.reasons.map((reason, index) => (
                <Typography key={`${reason}-${index}`} variant="body2" color="text.secondary">
                  • {reason}
                </Typography>
              ))}
            </Stack>
          </Box>
          <AdvancedDetails candidate={candidate} />
        </Box>
      </Collapse>
    </Paper>
  );
}

/**
 * Smart Recommendations (Phase 33.4 engine — Phase 7 simplified UI).
 *
 * The `GET /goalcart/v1/revenue/goal-recommendations` payload: analyzed
 * store data, ranked candidate thresholds with score/confidence/expected
 * impact/reasons, and the top recommendation. The primary card answers
 * "what threshold should I use and why?" (Improvement.md §33) — the raw
 * scoring details (score, component scores, ratios) live behind the
 * Advanced details expander, and an unavailable expected profit explains
 * how to enable it (§34). Applying a recommendation is always an explicit
 * admin action (ConfirmDialog → update the goal's target) — the engine
 * itself never modifies a goal (P33-53).
 */
export default function Recommendations() {
  const { range } = useDateRange();
  const queryClient = useQueryClient();
  const { notify } = useSnackbar();

  const [goalId, setGoalId] = useState<number>(0);
  const [rewardType, setRewardType] = useState<string>('');
  const [expandedCandidate, setExpandedCandidate] = useState<number | null>(null);
  const [applyTarget, setApplyTarget] = useState<RecommendationCandidate | null>(null);
  const [topDismissed, setTopDismissed] = useState<boolean>(false);
  const [showTopDetails, setShowTopDetails] = useState<boolean>(false);

  const query = useQuery({
    queryKey: ['revenue', 'recommendations', { from: range.from, to: range.to, goalId, rewardType }],
    queryFn: () =>
      fetchGoalRecommendations({
        from: range.from,
        to: range.to,
        goal_id: goalId || undefined,
        reward_type: rewardType || undefined,
        window_days: 90,
      }),
  });

  const payload = query.data;
  const top = payload?.recommendation;

  const applyMutation = useMutation({
    mutationFn: async (target: number) => {
      if (goalId < 1) {
        throw new Error(__('Select a goal to apply the recommendation to.', 'goalcart'));
      }

      await updateGoal(goalId, { target });
    },
    onSuccess: () => {
      notify(__('Goal target updated.', 'goalcart'));
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
      title={__('Smart Recommendations', 'goalcart')}
      description={__(
        'Which goal threshold this store should use — computed deterministically from your own order data.',
        'goalcart'
      )}
    >
      <RevenueToolbar goalId={goalId} onGoalChange={setGoalId}>
        <TextField
          select
          label={__('Reward type', 'goalcart')}
          size="small"
          sx={{ minWidth: 170 }}
          value={rewardType}
          onChange={(event) => setRewardType(event.target.value)}
        >
          {REWARD_OPTIONS.map((option) => (
            <MenuItem key={option.value} value={option.value}>
              {option.label}
            </MenuItem>
          ))}
        </TextField>
      </RevenueToolbar>

      {query.isError && (
        <Alert severity="error" variant="outlined">
          {query.error instanceof Error
            ? query.error.message
            : __('Could not load recommendations.', 'goalcart')}
        </Alert>
      )}

      {query.isLoading ? (
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={120} />
          <Skeleton variant="rounded" height={420} />
        </Stack>
      ) : !payload ? null : !payload.available ? (
        <EmptyState
          icon={<TipsAndUpdatesIcon fontSize="large" />}
          title={__('No recommendation available', 'goalcart')}
          description={payload.insufficient_reason ?? __('Not enough data for a reliable recommendation.', 'goalcart')}
        />
      ) : payload.data ? (
        <>
          {/* Top recommendation — business outcome first (§33). */}
          {top && !topDismissed && (
            <TopRecommendationCard
              candidate={top}
              goalId={goalId}
              detailsOpen={showTopDetails}
              onToggleDetails={() => setShowTopDetails((current) => !current)}
              onApply={handleApply}
              onDismiss={() => setTopDismissed(true)}
            />
          )}

          {/* Restore the dismissed top recommendation. */}
          {top && topDismissed && (
            <Button size="small" variant="text" startIcon={<TipsAndUpdatesIcon />} onClick={() => setTopDismissed(false)}>
              {__('Show the top recommendation again', 'goalcart')}
            </Button>
          )}

          {/* Analyzed store data — the context behind the "why". */}
          <AnalyzedData payload={payload} />

          {/* Ranked candidates — simplified rows, details behind an expander. */}
          {payload.candidates.length > 0 && (
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography variant="h6" component="h3" gutterBottom>
                {__('Ranked candidates', 'goalcart')}
              </Typography>
              <Stack spacing={1.5}>
                {payload.candidates.map((candidate, index) => (
                  <CandidateRow
                    key={candidate.threshold}
                    candidate={candidate}
                    goalId={goalId}
                    expanded={expandedCandidate === index}
                    onToggle={() => setExpandedCandidate(expandedCandidate === index ? null : index)}
                    onApply={handleApply}
                  />
                ))}
              </Stack>
            </Paper>
          )}
        </>
      ) : null}

      <ConfirmDialog
        open={applyTarget !== null}
        title={__('Apply recommendation?', 'goalcart')}
        description={
          applyTarget ? (
            <>
              {sprintf(
                /* translators: 1: threshold. */
                __('Set the goal target to %s?', 'goalcart'),
                formatCurrency(applyTarget.threshold)
              )}{' '}
              {__('This changes a production goal — the action is not reversible from here.', 'goalcart')}
            </>
          ) : undefined
        }
        confirmLabel={__('Apply', 'goalcart')}
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
