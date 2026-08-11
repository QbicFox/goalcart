import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import LeaderboardIcon from '@mui/icons-material/Leaderboard';
import { useQuery } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Collapse from '@mui/material/Collapse';
import IconButton from '@mui/material/IconButton';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useState } from 'react';

import { fetchGoalPerformance } from '../api/revenue';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import FunnelVisual from '../components/revenue/FunnelVisual';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber, formatPercent } from '../lib/format';
import type { GoalPerformanceRow } from '../types';

/** Per-goal row with an expandable funnel + revenue detail panel. */
function GoalRow({ row }: { row: GoalPerformanceRow }) {
  const [expanded, setExpanded] = useState(false);

  return (
    <>
      <TableRow hover sx={{ cursor: 'pointer' }} onClick={() => setExpanded((current) => !current)}>
        <TableCell sx={{ fontWeight: 600 }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
            {row.name}
            <IconButton
              size="small"
              aria-label={__('Toggle goal detail', 'goalcart')}
              onClick={(event) => {
                event.stopPropagation();
                setExpanded((current) => !current);
              }}
              sx={{ ml: 'auto' }}
            >
              <ExpandMoreIcon
                fontSize="small"
                sx={{ transform: expanded ? 'rotate(180deg)' : 'none', transition: 'transform 0.2s' }}
              />
            </IconButton>
          </Box>
        </TableCell>
        <TableCell align="right">{formatNumber(row.views)}</TableCell>
        <TableCell align="right">{formatNumber(row.progressed)}</TableCell>
        <TableCell align="right">{formatNumber(row.completed)}</TableCell>
        <TableCell align="right">{formatNumber(row.converted)}</TableCell>
        <TableCell align="right">{row.completion_rate === null ? '—' : formatPercent(row.completion_rate)}</TableCell>
        <TableCell align="right">{row.conversion_rate === null ? '—' : formatPercent(row.conversion_rate)}</TableCell>
        <TableCell align="right">{formatCurrency(row.attributed_revenue)}</TableCell>
        <TableCell align="right">{formatCurrency(row.reward_cost)}</TableCell>
        <TableCell align="right">
          {row.profit_available && row.profit_impact !== null ? (
            formatCurrency(row.profit_impact)
          ) : (
            <Chip size="small" variant="outlined" color="default" label={__('n/a', 'goalcart')} />
          )}
        </TableCell>
      </TableRow>
      <TableRow>
        <TableCell style={{ paddingBottom: 0, paddingTop: 0 }} colSpan={10}>
          <Collapse in={expanded} timeout="auto" unmountOnExit>
            <Box sx={{ p: 2, display: 'grid', gridTemplateColumns: { md: 'repeat(2, 1fr)' }, gap: 2 }}>
              <Box>
                <Typography variant="subtitle2" gutterBottom>
                  {__('Funnel', 'goalcart')}
                </Typography>
                <FunnelVisual
                  compact
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
              <Stack spacing={1}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Target', 'goalcart')}
                  </Typography>
                  <Typography variant="body2">{formatNumber(row.target)}</Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Average cart value', 'goalcart')}
                  </Typography>
                  <Typography variant="body2">{formatCurrency(row.average_cart_value)}</Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Incremental cart value', 'goalcart')}
                  </Typography>
                  <Typography variant="body2">{formatCurrency(row.incremental_cart_value)}</Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
                  <Typography variant="body2" color="text.secondary">
                    {__('Assisted revenue', 'goalcart')}
                  </Typography>
                  <Typography variant="body2">{formatCurrency(row.assisted_revenue)}</Typography>
                </Box>
              </Stack>
            </Box>
          </Collapse>
        </TableCell>
      </TableRow>
    </>
  );
}

/**
 * Goal Performance (Phase 33.6).
 *
 * Per-goal revenue metrics from `GET /goalcart/v1/revenue/goals`: funnel
 * counts, completion/conversion rates, cart-value lift, attributed +
 * assisted revenue, reward cost and profit impact. Each row expands into
 * the funnel visual + detail panel.
 */
export default function GoalPerformance() {
  const { range } = useDateRange();
  const [goalId, setGoalId] = useState<number>(0);

  const query = useQuery({
    queryKey: ['revenue', 'goals', { from: range.from, to: range.to, goalId }],
    queryFn: () =>
      fetchGoalPerformance({
        from: range.from,
        to: range.to,
        goal_id: goalId || undefined,
      }),
  });

  const items = query.data?.items ?? [];

  return (
    <PageContainer
      title={__('Goal Performance', 'goalcart')}
      description={__(
        'How every goal converts, how much cart value it lifts, and what it costs.',
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
      ) : items.length === 0 ? (
        <EmptyState
          icon={<LeaderboardIcon fontSize="large" />}
          title={__('No goal activity', 'goalcart')}
          description={__(
            'No goals recorded events in this range. Widen the date range or check that tracking is enabled.',
            'goalcart'
          )}
        />
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{__('Goal', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Views', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Progressed', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Completed', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Converted', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Completion', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Conversion', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Attributed revenue', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Reward cost', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Est. profit', 'goalcart')}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {items.map((row) => (
                <GoalRow key={row.goal_id} row={row} />
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
    </PageContainer>
  );
}
