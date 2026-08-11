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
import { useState } from 'react';

import { updateGoal } from '../api/goals';
import { fetchGoalRecommendations } from '../api/revenue';
import ConfirmDialog from '../components/ConfirmDialog';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import { useSnackbar } from '../components/notifications/SnackbarProvider';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber, formatPercent, formatPercentValue } from '../lib/format';
import type { RecommendationCandidate } from '../types';

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
 * Smart Goal Recommendations (Phase 33.6).
 *
 * The `GET /goalcart/v1/revenue/goal-recommendations` payload: analyzed
 * store data, ranked candidate thresholds with score/confidence/expected
 * impact/reasons, and the top recommendation. Applying a recommendation
 * is always an explicit admin action (ConfirmDialog → update the goal's
 * target) — the engine itself never modifies a goal (P33-53).
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
      notify(
        sprintf(
          /* translators: 1: formatted threshold. */
          __('Goal target updated to %1$s.', 'goalcart'),
          applyTarget ? formatCurrency(applyTarget.threshold) : ''
        )
      );
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
          {/* Analyzed store data */}
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
                  {formatCurrency(payload.data.aov)}
                </Typography>
              </Stack>
              <Stack spacing={0.5}>
                <Typography variant="caption" color="text.secondary">
                  {__('Median order value', 'goalcart')}
                </Typography>
                <Typography variant="body1" sx={{ fontWeight: 600 }}>
                  {formatCurrency(payload.data.median)}
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
                    __('%1$s days', 'goalcart'),
                    payload.window_days
                  )}
                </Typography>
              </Stack>
              {payload.data.shipping.available && (
                <Stack spacing={0.5}>
                  <Typography variant="caption" color="text.secondary">
                    {__('Avg. shipping', 'goalcart')}
                  </Typography>
                  <Typography variant="body1" sx={{ fontWeight: 600 }}>
                    {formatCurrency(payload.data.shipping.average_shipping ?? 0)}
                  </Typography>
                </Stack>
              )}
              {payload.data.margin && payload.data.margin.available && (
                <Stack spacing={0.5}>
                  <Typography variant="caption" color="text.secondary">
                    {__('Avg. margin', 'goalcart')}
                  </Typography>
                  <Typography variant="body1" sx={{ fontWeight: 600 }}>
                    {formatPercent(payload.data.margin.average_margin_pct ?? 0)}
                  </Typography>
                </Stack>
              )}
              <Stack spacing={0.5}>
                <Typography variant="caption" color="text.secondary">
                  {__('Confidence tier', 'goalcart')}
                </Typography>
                <Chip
                  size="small"
                  variant="outlined"
                  label={payload.status}
                  color={payload.status === 'high_confidence' ? 'success' : payload.status === 'reliable' ? 'primary' : 'warning'}
                />
              </Stack>
            </Box>

            {/* Order distribution */}
            <Box sx={{ mt: 2 }}>
              <Typography variant="caption" color="text.secondary">
                {__('Order value distribution (share of orders)', 'goalcart')}
              </Typography>
              <Stack spacing={0.75} sx={{ mt: 1 }}>
                {Object.entries(payload.data.distribution).map(([bucket, share]) => (
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

          {/* Top recommendation */}
          {top && !topDismissed && (
            <Paper
              variant="outlined"
              sx={{ p: 2.5, borderColor: 'primary.main', borderWidth: 2, position: 'relative' }}
            >
              <Chip
                size="small"
                color="primary"
                icon={<TipsAndUpdatesIcon />}
                label={__('Top recommendation', 'goalcart')}
                sx={{ position: 'absolute', top: -12, insetInlineStart: 16 }}
              />
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 3, alignItems: 'flex-end' }}>
                <Box>
                  <Typography variant="caption" color="text.secondary">
                    {__('Recommended threshold', 'goalcart')}
                  </Typography>
                  <Typography variant="h4" component="p" sx={{ m: 0, fontWeight: 700 }}>
                    {formatCurrency(top.threshold)}
                  </Typography>
                </Box>
                <Box>
                  <Typography variant="caption" color="text.secondary">
                    {__('Confidence', 'goalcart')}
                  </Typography>
                  <Typography variant="h6" component="p" sx={{ m: 0 }}>
                    {formatPercent(top.confidence / 100)}
                  </Typography>
                </Box>
                <Box>
                  <Typography variant="caption" color="text.secondary">
                    {__('Expected AOV impact', 'goalcart')}
                  </Typography>
                  <Typography variant="h6" component="p" sx={{ m: 0 }}>
                    +{formatPercentValue(top.expected_aov_impact.low)} – +{formatPercentValue(top.expected_aov_impact.high)}
                  </Typography>
                </Box>
                <Box>
                  <Typography variant="caption" color="text.secondary">
                    {__('Expected completion', 'goalcart')}
                  </Typography>
                  <Typography variant="h6" component="p" sx={{ m: 0 }}>
                    {formatPercent(top.expected_completion_rate)}
                  </Typography>
                </Box>
                <Box>
                  <Typography variant="caption" color="text.secondary">
                    {__('Expected profit', 'goalcart')}
                  </Typography>
                  <Typography variant="h6" component="p" sx={{ m: 0 }}>
                    {top.expected_profit_available && top.expected_profit !== null
                      ? formatCurrency(top.expected_profit)
                      : '—'}
                  </Typography>
                </Box>
              </Box>

              <Collapse in={showTopDetails} timeout="auto" unmountOnExit>
                <Box sx={{ mt: 1.5, pt: 1.5, borderTop: 1, borderColor: 'divider' }}>
                  <Stack spacing={0.5}>
                    {top.reasons.map((reason) => (
                      <Typography key={reason} variant="body2" color="text.secondary">
                        • {reason}
                      </Typography>
                    ))}
                  </Stack>
                  {top.reward_cost !== null && (
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                      {sprintf(
                        /* translators: 1: reward cost. */
                        __('Estimated reward cost: %1$s', 'goalcart'),
                        formatCurrency(top.reward_cost)
                      )}
                    </Typography>
                  )}
                </Box>
              </Collapse>

              <Divider sx={{ my: 2 }} />

              <Stack direction="row" spacing={1}>
                <Button
                  variant="contained"
                  startIcon={<CheckCircleOutlineOutlinedIcon />}
                  disabled={goalId < 1}
                  onClick={() => handleApply(top)}
                >
                  {__('Apply recommendation', 'goalcart')}
                </Button>
                <Button variant="outlined" onClick={() => setShowTopDetails((current) => !current)}>
                  {showTopDetails ? __('Hide details', 'goalcart') : __('View details', 'goalcart')}
                </Button>
                <Button variant="text" color="inherit" onClick={() => setTopDismissed(true)}>
                  {__('Dismiss', 'goalcart')}
                </Button>
                {goalId < 1 && (
                  <Typography variant="caption" color="text.secondary" sx={{ alignSelf: 'center' }}>
                    {__('Select a goal above to enable applying.', 'goalcart')}
                  </Typography>
                )}
              </Stack>
            </Paper>
          )}

          {/* Restore the dismissed top recommendation. */}
          {top && topDismissed && (
            <Button size="small" variant="text" startIcon={<TipsAndUpdatesIcon />} onClick={() => setTopDismissed(false)}>
              {__('Show the top recommendation again', 'goalcart')}
            </Button>
          )}

          {/* Candidate list */}
          <Paper variant="outlined" sx={{ p: 2 }}>
            <Typography variant="h6" component="h3" gutterBottom>
              {__('Ranked candidates', 'goalcart')}
            </Typography>
            <Stack spacing={1.5}>
              {payload.candidates.map((candidate, index) => {
                const expanded = expandedCandidate === index;

                return (
                  <Paper key={candidate.threshold} variant="outlined" sx={{ p: 1.5 }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap' }}>
                      <Box sx={{ minWidth: 150 }}>
                        <Typography variant="body2" color="text.secondary">
                          {__('Threshold', 'goalcart')}
                        </Typography>
                        <Typography variant="body1" sx={{ fontWeight: 600 }}>
                          {formatCurrency(candidate.threshold)}
                        </Typography>
                      </Box>
                      <Box sx={{ flexGrow: 1, minWidth: 180 }}>
                        <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.25 }}>
                          <Typography variant="caption" color="text.secondary">
                            {__('Score', 'goalcart')}
                          </Typography>
                          <Typography variant="caption">{formatNumber(candidate.score)} / 100</Typography>
                        </Box>
                        <LinearProgress
                          variant="determinate"
                          value={Math.min(100, candidate.score)}
                          sx={{ height: 6, borderRadius: 3 }}
                        />
                      </Box>
                      <Chip
                        size="small"
                        variant="outlined"
                        label={sprintf(
                          /* translators: 1: confidence percent. */
                          __('%1$s confidence', 'goalcart'),
                          formatPercent(candidate.confidence / 100)
                        )}
                      />
                      <Chip
                        size="small"
                        variant="outlined"
                        color="default"
                        label={sprintf(
                          /* translators: 1: reachable orders percent. */
                          __('%1$s reachable', 'goalcart'),
                          formatPercent(candidate.reachable_orders_pct / 100)
                        )}
                      />
                      <Button
                        size="small"
                        variant="outlined"
                        endIcon={<ExpandMoreIcon sx={{ transform: expanded ? 'rotate(180deg)' : 'none' }} />}
                        onClick={() => setExpandedCandidate(expanded ? null : index)}
                      >
                        {__('Details', 'goalcart')}
                      </Button>
                      <Button size="small" variant="contained" disabled={goalId < 1} onClick={() => handleApply(candidate)}>
                        {__('Apply', 'goalcart')}
                      </Button>
                    </Box>
                    <Collapse in={expanded} timeout="auto" unmountOnExit>
                      <Box sx={{ mt: 1.5, pt: 1.5, borderTop: 1, borderColor: 'divider' }}>
                        <Stack spacing={0.5}>
                          {candidate.reasons.map((reason) => (
                            <Typography key={reason} variant="body2" color="text.secondary">
                              • {reason}
                            </Typography>
                          ))}
                        </Stack>
                        {candidate.reward_cost !== null && (
                          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                            {sprintf(
                              /* translators: 1: reward cost. */
                              __('Estimated reward cost: %1$s', 'goalcart'),
                              formatCurrency(candidate.reward_cost)
                            )}
                          </Typography>
                        )}
                      </Box>
                    </Collapse>
                  </Paper>
                );
              })}
            </Stack>
          </Paper>
        </>
      ) : null}

      <ConfirmDialog
        open={applyTarget !== null}
        title={__('Apply recommendation?', 'goalcart')}
        description={
          applyTarget
            ? sprintf(
                /* translators: 1: threshold, 2: goal name. */
                __('Set the goal target to %1$s? This changes a production goal — the action is not reversible from here.', 'goalcart'),
                formatCurrency(applyTarget.threshold)
              )
            : undefined
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
