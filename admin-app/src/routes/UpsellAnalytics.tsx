import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import { useQuery } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Avatar from '@mui/material/Avatar';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
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
import { useMemo, useState } from 'react';

import { fetchUpsellAnalytics, fetchUpsellProduct } from '../api/revenue';
import EmptyState from '../components/EmptyState';
import NumberPagination from '../components/NumberPagination';
import PageContainer from '../components/PageContainer';
import RevenueToolbar from '../components/revenue/RevenueToolbar';
import { useDateRange } from '../date-range/DateRangeContext';
import { formatCurrency, formatNumber, formatPercent } from '../lib/format';
import type { UpsellAnalyticsRow, UpsellComponentScores, UpsellRecommendation } from '../types';

/** Sort modes for the analytics table — all re-based on commercial outcomes. */
type SortMode = 'top' | 'lowest' | 'conversion' | 'margin';

const SORT_OPTIONS: Array<{ value: SortMode; label: string }> = [
  { value: 'top', label: __('Top performing', 'faracart') },
  { value: 'lowest', label: __('Lowest performing', 'faracart') },
  { value: 'conversion', label: __('Best conversion', 'faracart') },
  { value: 'margin', label: __('Highest margin', 'faracart') },
];

const COMPONENT_LABELS: Array<{ key: keyof UpsellComponentScores; label: string; help: string }> = [
  {
    key: 'price_gap',
    label: __('Price gap', 'faracart'),
    help: __(
      'Products priced close to the amount remaining until the mission usually make a better suggestion.',
      'faracart'
    ),
  },
  {
    key: 'relevance',
    label: __('Relevance', 'faracart'),
    help: __(
      'How related this product is to the items already in the customer’s cart.',
      'faracart'
    ),
  },
  {
    key: 'popularity',
    label: __('Popularity', 'faracart'),
    help: __('Products purchased more often in your store rank higher.', 'faracart'),
  },
  {
    key: 'inventory',
    label: __('Inventory', 'faracart'),
    help: __('In-stock products are prioritized.', 'faracart'),
  },
  {
    key: 'margin',
    label: __('Margin', 'faracart'),
    help: __(
      'When product cost is recorded, its profit is also considered in the ranking.',
      'faracart'
    ),
  },
  {
    key: 'conversion',
    label: __('Conversion', 'faracart'),
    help: __('How often this product has actually led to an order when suggested.', 'faracart'),
  },
];

/**
 * Sort one mode of the analytics rows (client-side views). Phase 8 re-bases
 * the four views on commercial outcomes (§35): "Top performing" is ordered
 * by purchases then sales (what actually converts), "Lowest performing" by
 * the underperformers, "Best conversion" by the purchase rate (products
 * without impressions sort last — no denominator), "Highest margin" by
 * the sampled margin (unavailable margins last).
 */
function sortRows(rows: UpsellAnalyticsRow[], mode: SortMode): UpsellAnalyticsRow[] {
  const copy = [...rows];

  switch (mode) {
    case 'lowest':
      return copy.sort((a, b) => a.orders - b.orders || a.revenue - b.revenue);
    case 'conversion':
      return copy.sort(
        (a, b) =>
          (b.impressions > 0 ? b.conversion_rate : -1) -
          (a.impressions > 0 ? a.conversion_rate : -1)
      );
    case 'margin':
      return copy.sort((a, b) => (b.margin_pct ?? -1) - (a.margin_pct ?? -1));
    case 'top':
    default:
      return copy.sort(
        (a, b) => b.orders - a.orders || b.revenue - a.revenue || b.impressions - a.impressions
      );
  }
}

/**
 * A percentage from the real funnel counts, "—" when there is no
 * denominator (e.g. no impressions) — never a fabricated 0% (§43).
 */
function funnelRate(numerator: number, denominator: number): string {
  return denominator > 0 ? formatPercent(numerator / denominator) : '—';
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
        {detailQuery.isLoading
          ? __('Loading product…', 'faracart')
          : (detail?.name ?? __('Product', 'faracart'))}
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
            {__('The product breakdown could not be loaded.', 'faracart')}
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
                  <Typography
                    variant="caption"
                    color="text.secondary"
                    component="span"
                    sx={{ ml: 1 }}
                  >
                    {__('upsell score', 'faracart')}
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
                    __('Est. profit %1$s', 'faracart'),
                    formatCurrency(detail.estimated_profit)
                  )}
                />
              )}
            </Box>

            <Box>
              <Typography variant="subtitle2" gutterBottom>
                {__('Score breakdown', 'faracart')}
              </Typography>
              <Stack spacing={1}>
                {COMPONENT_LABELS.map(({ key, label, help }) => (
                  <Box key={key}>
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.25 }}>
                      <Typography variant="caption" sx={{ fontWeight: 600 }}>
                        {label}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {formatNumber(detail.components[key])} / 100
                      </Typography>
                    </Box>
                    <LinearProgress
                      variant="determinate"
                      value={detail.components[key]}
                      sx={{ height: 5, borderRadius: 3 }}
                    />
                    <Typography
                      variant="caption"
                      color="text.secondary"
                      component="p"
                      sx={{ mt: 0.25 }}
                    >
                      {help}
                    </Typography>
                  </Box>
                ))}
              </Stack>
            </Box>

            <Box>
              <Typography variant="subtitle2" gutterBottom>
                {__('Why this product', 'faracart')}
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
                  {__('Historical performance', 'faracart')}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  {sprintf(
                    /* translators: 1: impressions, 2: orders, 3: conversion rate. */
                    __('%1$s impressions · %2$s orders · %3$s conversion', 'faracart'),
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
            {__('This product could not be scored.', 'faracart')}
          </Typography>
        )}
      </DialogContent>
    </Dialog>
  );
}

/**
 * Upsells (Phase 33.6 engine — UPSELL_REFACTOR §4/§32/§39; UICHANGES.md
 * §40 label).
 *
 * The customer-facing recommendation system's admin report: the
 * top-products upsell table from `GET /faracart/v1/revenue/upsells?analytics=1`.
 * The first screen answers "which suggested products actually help
 * customers reach Missions and generate purchases and sales?" (§35): Product
 * / Purchased Orders / Sales / Estimated profit / Conversion are the
 * primary columns; the interaction funnel (impressions, clicks, adds, CTR,
 * add-to-cart rate) and the upsell score sit behind a "Show interaction
 * details" toggle, and the full score breakdown stays available through
 * the per-product dialog. CTR and add-to-cart rate are derived client-side
 * from the real funnel counts (clicks/impressions, adds/impressions — "—"
 * without a denominator), never fabricated.
 */
export default function UpsellAnalytics() {
  const { range } = useDateRange();
  const [missionId, setMissionId] = useState<number>(0);
  const [limit, setLimit] = useState<number>(20);
  const [sortMode, setSortMode] = useState<SortMode>('top');
  const [showDetails, setShowDetails] = useState<boolean>(false);
  const [detailProductId, setDetailProductId] = useState<number>(0);
  const [page, setPage] = useState(0);

  const query = useQuery({
    queryKey: ['revenue', 'upsell-analytics', { from: range.from, to: range.to, missionId, limit }],
    queryFn: () =>
      fetchUpsellAnalytics({
        from: range.from,
        to: range.to,
        mission_id: missionId || undefined,
        limit,
      }),
  });

  const rows = useMemo(() => sortRows(query.data ?? [], sortMode), [query.data, sortMode]);

  const PER_PAGE = 10;
  const pageCount = Math.max(1, Math.ceil(rows.length / PER_PAGE));
  const safePage = Math.min(page, pageCount - 1);
  const pagedRows = rows.slice(safePage * PER_PAGE, (safePage + 1) * PER_PAGE);

  // Commercial summary over the loaded rows — purchases, sales, conversion.
  const summary = useMemo(() => {
    const totalOrders = rows.reduce((sum, row) => sum + row.orders, 0);
    const totalSales = rows.reduce((sum, row) => sum + row.revenue, 0);
    const totalImpressions = rows.reduce((sum, row) => sum + row.impressions, 0);

    return {
      products: rows.length,
      orders: totalOrders,
      sales: totalSales,
      conversion: totalImpressions > 0 ? totalOrders / totalImpressions : null,
    };
  }, [rows]);

  return (
    <PageContainer
      title={__('Upsells', 'faracart')}
      description={__(
        'Upsells shows which products help customers reach Missions and generate additional sales — purchases, sales and estimated profit per product.',
        'faracart'
      )}
    >
      <RevenueToolbar missionId={missionId} onMissionChange={setMissionId}>
        <TextField
          select
          label={__('Limit', 'faracart')}
          size="small"
          sx={{ minWidth: 110 }}
          value={limit}
          onChange={(event) => {
            setLimit(Number(event.target.value));
            setPage(0);
          }}
        >
          <MenuItem value={10}>10</MenuItem>
          <MenuItem value={20}>20</MenuItem>
          <MenuItem value={50}>50</MenuItem>
        </TextField>
      </RevenueToolbar>

      <Stack
        direction={{ xs: 'column', md: 'row' }}
        spacing={1}
        useFlexGap
        sx={{ alignItems: 'flex-start', flexWrap: 'wrap' }}
      >
        <ToggleButtonGroup
          exclusive
          size="small"
          value={sortMode}
          onChange={(_, next) => {
            if (next) {
              setSortMode(next);
              setPage(0);
            }
          }}
          aria-label={__('Sort products', 'faracart')}
        >
          {SORT_OPTIONS.map((option) => (
            <ToggleButton key={option.value} value={option.value}>
              {option.label}
            </ToggleButton>
          ))}
        </ToggleButtonGroup>
        <Box sx={{ flexGrow: 1 }} />
        <Button
          size="small"
          variant="outlined"
          endIcon={<ExpandMoreIcon sx={{ transform: showDetails ? 'rotate(180deg)' : 'none' }} />}
          onClick={() => setShowDetails((current) => !current)}
          aria-expanded={showDetails}
        >
          {showDetails
            ? __('Hide interaction details', 'faracart')
            : __('Show interaction details', 'faracart')}
        </Button>
      </Stack>

      {query.isError && (
        <Alert severity="error" variant="outlined">
          {query.error instanceof Error
            ? query.error.message
            : __('Could not load upsell analytics.', 'faracart')}
        </Alert>
      )}

      {query.isLoading ? (
        <Stack spacing={2}>
          <Skeleton variant="rounded" height={72} />
          <Skeleton variant="rounded" height={420} />
        </Stack>
      ) : !query.isError && rows.length === 0 ? (
        <EmptyState
          icon={<TrendingUpIcon fontSize="large" />}
          title={__('No upsell activity yet', 'faracart')}
          description={__(
            'Upsell impressions and orders are recorded once the storefront reports them. Widen the date range or check that tracking is enabled.',
            'faracart'
          )}
        />
      ) : (
        <Stack spacing={2}>
          {/* Commercial summary (§35 — the first screen answers how many
              purchases and how much sales the suggestions generated). */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' },
              gap: 2,
            }}
          >
            <Card variant="outlined">
              <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                <Typography variant="caption" color="text.secondary">
                  {__('Products', 'faracart')}
                </Typography>
                <Typography variant="h5" component="p" sx={{ m: 0, fontWeight: 600 }}>
                  {formatNumber(summary.products)}
                </Typography>
              </CardContent>
            </Card>
            <Card variant="outlined">
              <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                <Typography variant="caption" color="text.secondary">
                  {__('Purchased Orders', 'faracart')}
                </Typography>
                <Typography variant="h5" component="p" sx={{ m: 0, fontWeight: 600 }}>
                  {formatNumber(summary.orders)}
                </Typography>
              </CardContent>
            </Card>
            <Card variant="outlined">
              <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                <Typography variant="caption" color="text.secondary">
                  {__('Sales', 'faracart')}
                </Typography>
                <Typography variant="h5" component="p" sx={{ m: 0, fontWeight: 600 }}>
                  {formatCurrency(summary.sales)}
                </Typography>
              </CardContent>
            </Card>
            <Card variant="outlined">
              <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                <Typography variant="caption" color="text.secondary">
                  {__('Conversion', 'faracart')}
                </Typography>
                <Typography variant="h5" component="p" sx={{ m: 0, fontWeight: 600 }}>
                  {summary.conversion !== null ? formatPercent(summary.conversion) : '—'}
                </Typography>
              </CardContent>
            </Card>
          </Box>

          <TableContainer component={Paper} variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>{__('Product', 'faracart')}</TableCell>
                  <TableCell align="right">{__('Orders', 'faracart')}</TableCell>
                  <TableCell align="right">{__('Sales', 'faracart')}</TableCell>
                  <TableCell align="right">{__('Estimated profit', 'faracart')}</TableCell>
                  <TableCell align="right">{__('Conversion', 'faracart')}</TableCell>
                  {showDetails && (
                    <>
                      <TableCell align="right">{__('Impressions', 'faracart')}</TableCell>
                      <TableCell align="right">{__('Clicks', 'faracart')}</TableCell>
                      <TableCell align="right">{__('Adds', 'faracart')}</TableCell>
                      <TableCell align="right">{__('CTR', 'faracart')}</TableCell>
                      <TableCell align="right">{__('Add-to-cart', 'faracart')}</TableCell>
                      <TableCell align="right">{__('Score', 'faracart')}</TableCell>
                    </>
                  )}
                </TableRow>
              </TableHead>
              <TableBody>
                {pagedRows.map((row) => (
                  <TableRow
                    key={row.product_id}
                    hover
                    tabIndex={0}
                    role="button"
                    sx={{ cursor: 'pointer' }}
                    onClick={() => setDetailProductId(row.product_id)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        setDetailProductId(row.product_id);
                      }
                    }}
                  >
                    <TableCell sx={{ fontWeight: 600 }}>{row.name}</TableCell>
                    <TableCell align="right">
                      <Chip
                        size="small"
                        variant="outlined"
                        color={row.orders > 0 ? 'success' : 'default'}
                        label={formatNumber(row.orders)}
                      />
                    </TableCell>
                    <TableCell align="right">{formatCurrency(row.revenue)}</TableCell>
                    <TableCell align="right">
                      {row.profit_available && row.estimated_profit !== null
                        ? formatCurrency(row.estimated_profit)
                        : '—'}
                    </TableCell>
                    <TableCell align="right">{funnelRate(row.orders, row.impressions)}</TableCell>
                    {showDetails && (
                      <>
                        <TableCell align="right">{formatNumber(row.impressions)}</TableCell>
                        <TableCell align="right">{formatNumber(row.clicks)}</TableCell>
                        <TableCell align="right">{formatNumber(row.adds)}</TableCell>
                        <TableCell align="right">
                          {funnelRate(row.clicks, row.impressions)}
                        </TableCell>
                        <TableCell align="right">{funnelRate(row.adds, row.impressions)}</TableCell>
                        <TableCell align="right">
                          <Chip
                            size="small"
                            variant="outlined"
                            color="primary"
                            label={formatNumber(row.upsell_score)}
                          />
                        </TableCell>
                      </>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>

          <NumberPagination
            count={rows.length}
            page={safePage}
            rowsPerPage={PER_PAGE}
            onPageChange={setPage}
          />

          <Typography variant="caption" color="text.secondary">
            {__('Click a product row for its full score breakdown.', 'faracart')}
          </Typography>
        </Stack>
      )}

      <ProductDetailDialog
        productId={detailProductId}
        open={detailProductId > 0}
        onClose={() => setDetailProductId(0)}
      />
    </PageContainer>
  );
}
