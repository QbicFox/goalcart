import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import { useQuery } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Avatar from '@mui/material/Avatar';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
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
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useState } from 'react';

import { fetchUpsellAnalytics, fetchUpsellProduct } from '../api/revenue';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber, formatPercent } from '../lib/format';
import type { UpsellAnalyticsRow, UpsellComponentScores, UpsellRecommendation } from '../types';

/** Sort modes for the analytics table (the spec's four views). */
type SortMode = 'score' | 'lowest' | 'conversion' | 'margin';

const SORT_OPTIONS: Array<{ value: SortMode; label: string }> = [
  { value: 'score', label: __('Top performing', 'goalcart') },
  { value: 'lowest', label: __('Lowest performing', 'goalcart') },
  { value: 'conversion', label: __('Best conversion', 'goalcart') },
  { value: 'margin', label: __('Highest margin', 'goalcart') },
];

const COMPONENT_LABELS: Array<{ key: keyof UpsellComponentScores; label: string }> = [
  { key: 'price_gap', label: __('Price gap', 'goalcart') },
  { key: 'relevance', label: __('Relevance', 'goalcart') },
  { key: 'popularity', label: __('Popularity', 'goalcart') },
  { key: 'inventory', label: __('Inventory', 'goalcart') },
  { key: 'margin', label: __('Margin', 'goalcart') },
  { key: 'conversion', label: __('Conversion', 'goalcart') },
];

/** Sort one mode of the analytics rows (client-side views). */
function sortRows(rows: UpsellAnalyticsRow[], mode: SortMode): UpsellAnalyticsRow[] {
  const copy = [...rows];

  switch (mode) {
    case 'lowest':
      return copy.sort((a, b) => a.upsell_score - b.upsell_score);
    case 'conversion':
      return copy.sort((a, b) => b.conversion_rate - a.conversion_rate);
    case 'margin':
      return copy.sort((a, b) => (b.margin_pct ?? -1) - (a.margin_pct ?? -1));
    case 'score':
    default:
      return copy.sort((a, b) => b.upsell_score - a.upsell_score);
  }
}

/** Per-product score-breakdown dialog (the P33-34 transparency contract). */
function ProductDetailDialog({
  productId,
  open,
  onClose,
}: {
  productId: number;
  open: boolean;
  onClose: () => void;
}) {
  const detailQuery = useQuery({
    queryKey: ['revenue', 'upsell-product', productId],
    queryFn: () => fetchUpsellProduct(productId),
    enabled: open && productId > 0,
  });

  const detail: UpsellRecommendation | null | undefined = detailQuery.data;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>
        {detailQuery.isLoading ? __('Loading product…', 'goalcart') : detail?.name ?? __('Product', 'goalcart')}
      </DialogTitle>
      <DialogContent dividers>
        {detailQuery.isLoading ? (
          <Stack spacing={1.5}>
            <Skeleton variant="rounded" height={24} />
            <Skeleton variant="rounded" height={180} />
            <Skeleton variant="rounded" height={90} />
          </Stack>
        ) : detailQuery.isError ? (
          <Typography variant="body2" color="text.secondary">
            {__('The product breakdown could not be loaded.', 'goalcart')}
          </Typography>
        ) : detail ? (
          <Stack spacing={2}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap' }}>
              {detail.image && (
                <Avatar src={detail.image} variant="rounded" sx={{ width: 56, height: 56 }} />
              )}
              <Box>
                <Typography variant="h5" component="p" sx={{ m: 0, fontWeight: 700 }}>
                  {formatNumber(detail.score)}
                  <Typography variant="caption" color="text.secondary" component="span" sx={{ ml: 1 }}>
                    {__('upsell score', 'goalcart')}
                  </Typography>
                </Typography>
                {detail.price !== null && (
                  <Typography variant="body2" color="text.secondary">
                    {formatCurrency(detail.price)}
                  </Typography>
                )}
              </Box>
              {detail.estimated_profit !== null && (
                <Chip
                  size="small"
                  variant="outlined"
                  color="success"
                  label={sprintf(
                    /* translators: 1: estimated per-unit profit. */
                    __('Est. profit %1$s', 'goalcart'),
                    formatCurrency(detail.estimated_profit)
                  )}
                />
              )}
            </Box>

            <Box>
              <Typography variant="subtitle2" gutterBottom>
                {__('Score breakdown', 'goalcart')}
              </Typography>
              <Stack spacing={1}>
                {COMPONENT_LABELS.map(({ key, label }) => (
                  <Box key={key}>
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.25 }}>
                      <Typography variant="caption">{label}</Typography>
                      <Typography variant="caption" color="text.secondary">
                        {formatNumber(detail.components[key])} / 100
                      </Typography>
                    </Box>
                    <LinearProgress
                      variant="determinate"
                      value={detail.components[key]}
                      sx={{ height: 5, borderRadius: 3 }}
                    />
                  </Box>
                ))}
              </Stack>
            </Box>

            <Box>
              <Typography variant="subtitle2" gutterBottom>
                {__('Why this product', 'goalcart')}
              </Typography>
              <Stack spacing={0.5}>
                {detail.reasons.map((reason) => (
                  <Typography key={reason} variant="body2" color="text.secondary">
                    • {reason}
                  </Typography>
                ))}
              </Stack>
            </Box>

            {detail.conversion.available && (
              <Box>
                <Typography variant="subtitle2" gutterBottom>
                  {__('Historical performance', 'goalcart')}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  {sprintf(
                    /* translators: 1: impressions, 2: orders, 3: conversion rate. */
                    __('%1$s impressions · %2$s orders · %3$s conversion', 'goalcart'),
                    formatNumber(detail.conversion.impressions),
                    formatNumber(detail.conversion.orders),
                    formatPercent(detail.conversion.conversion_rate)
                  )}
                </Typography>
              </Box>
            )}
          </Stack>
        ) : (
          <Typography variant="body2" color="text.secondary">
            {__('This product could not be scored.', 'goalcart')}
          </Typography>
        )}
      </DialogContent>
    </Dialog>
  );
}

/**
 * Upsell Analytics (Phase 33.6).
 *
 * The top-products upsell table from
 * `GET /goalcart/v1/revenue/upsells?analytics=1` — impressions / clicks /
 * adds / orders / conversion / revenue / estimated profit / upsell score —
 * with the spec's four views (top / lowest performing, best conversion,
 * highest margin) and a per-product score-breakdown drill-down.
 */
export default function UpsellAnalytics() {
  const { range } = useDateRange();
  const [goalId, setGoalId] = useState<number>(0);
  const [limit, setLimit] = useState<number>(20);
  const [sortMode, setSortMode] = useState<SortMode>('score');
  const [detailProductId, setDetailProductId] = useState<number>(0);

  const query = useQuery({
    queryKey: ['revenue', 'upsell-analytics', { from: range.from, to: range.to, goalId, limit }],
    queryFn: () =>
      fetchUpsellAnalytics({
        from: range.from,
        to: range.to,
        goal_id: goalId || undefined,
        limit,
      }),
  });

  const rows = sortRows(query.data ?? [], sortMode);

  return (
    <PageContainer
      title={__('Upsell Analytics', 'goalcart')}
      description={__(
        'Which recommended products convert — impressions, orders, revenue and profit per product.',
        'goalcart'
      )}
    >
      <RevenueToolbar goalId={goalId} onGoalChange={setGoalId}>
        <TextField
          select
          label={__('Limit', 'goalcart')}
          size="small"
          sx={{ minWidth: 110 }}
          value={limit}
          onChange={(event) => setLimit(Number(event.target.value))}
        >
          <MenuItem value={10}>10</MenuItem>
          <MenuItem value={20}>20</MenuItem>
          <MenuItem value={50}>50</MenuItem>
        </TextField>
      </RevenueToolbar>

      <ToggleButtonGroup
        exclusive
        size="small"
        value={sortMode}
        onChange={(_, next) => next && setSortMode(next)}
        aria-label={__('Sort products', 'goalcart')}
        sx={{ alignSelf: 'flex-start' }}
      >
        {SORT_OPTIONS.map((option) => (
          <ToggleButton key={option.value} value={option.value}>
            {option.label}
          </ToggleButton>
        ))}
      </ToggleButtonGroup>

      {query.isError && (
        <Alert severity="error" variant="outlined">
          {query.error instanceof Error
            ? query.error.message
            : __('Could not load upsell analytics.', 'goalcart')}
        </Alert>
      )}

      {query.isLoading ? (
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={72} />
          <Skeleton variant="rounded" height={420} />
        </Stack>
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<TrendingUpIcon fontSize="large" />}
          title={__('No upsell activity yet', 'goalcart')}
          description={__(
            'Upsell impressions and orders are recorded once the storefront reports them. Widen the date range or check that tracking is enabled.',
            'goalcart'
          )}
        />
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{__('Product', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Impressions', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Clicks', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Adds', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Orders', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Conversion', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Revenue', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Est. profit', 'goalcart')}</TableCell>
                <TableCell align="right">{__('Score', 'goalcart')}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.product_id} hover sx={{ cursor: 'pointer' }} onClick={() => setDetailProductId(row.product_id)}>
                  <TableCell sx={{ fontWeight: 600 }}>{row.name}</TableCell>
                  <TableCell align="right">{formatNumber(row.impressions)}</TableCell>
                  <TableCell align="right">{formatNumber(row.clicks)}</TableCell>
                  <TableCell align="right">{formatNumber(row.adds)}</TableCell>
                  <TableCell align="right">
                    <Chip
                      size="small"
                      variant="outlined"
                      color={row.orders > 0 ? 'success' : 'default'}
                      label={formatNumber(row.orders)}
                    />
                  </TableCell>
                  <TableCell align="right">{formatPercent(row.conversion_rate)}</TableCell>
                  <TableCell align="right">{formatCurrency(row.revenue)}</TableCell>
                  <TableCell align="right">
                    {row.profit_available && row.estimated_profit !== null
                      ? formatCurrency(row.estimated_profit)
                      : '—'}
                  </TableCell>
                  <TableCell align="right">
                    <Chip size="small" variant="outlined" color="primary" label={formatNumber(row.upsell_score)} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <Typography variant="caption" color="text.secondary">
        {__('Click a product row for its full score breakdown.', 'goalcart')}
      </Typography>

      <ProductDetailDialog
        productId={detailProductId}
        open={detailProductId > 0}
        onClose={() => setDetailProductId(0)}
      />
    </PageContainer>
  );
}
