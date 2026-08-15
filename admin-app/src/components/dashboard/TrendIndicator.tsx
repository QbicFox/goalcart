import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import { formatPercentValue } from '../../lib/format';

/** A signed percentage-point change (e.g. 18.4 for +18.4%), plus semantics. */
export interface Trend {
  /** Signed percentage-point change; null = not computable. */
  change: number | null;
  /** When true, a decrease is treated as positive (e.g. costs/refunds). */
  invert?: boolean;
  /** Context label shown after the value (defaults to "vs previous period"). */
  context?: string;
}

interface TrendIndicatorProps {
  trend: Trend;
}

/**
 * A trend read-out that always carries context (UICHANGES.md §8):
 * an up/down arrow, the signed percentage and a "vs previous period"
 * label. Color encodes the *interpreted* direction (an increase is good
 * unless `invert`), and the arrow + sign keep it readable without color.
 */
export default function TrendIndicator({ trend }: TrendIndicatorProps) {
  const { change, invert = false, context = __('vs previous period', 'goalcart') } = trend;

  if (change === null || !Number.isFinite(change)) {
    return (
      <Typography variant="caption" color="text.disabled">
        — {context}
      </Typography>
    );
  }

  const up = change >= 0;
  const good = invert ? !up : up;
  const color = good ? 'success.main' : 'error.main';
  const Icon = up ? ArrowUpwardIcon : ArrowDownwardIcon;

  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, color }}>
      <Icon sx={{ fontSize: 14 }} />
      <Typography variant="caption" sx={{ fontWeight: 600, color }}>
        {`${up ? '+' : '-'}${formatPercentValue(Math.abs(change))}`}
      </Typography>
      <Typography variant="caption" color="text.secondary">
        {context}
      </Typography>
    </Box>
  );
}
