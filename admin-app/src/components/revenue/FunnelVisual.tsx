import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import { formatNumber } from '../../lib/format';
import type { RevenueFunnel } from '../../types';

interface FunnelStage {
  key: keyof Pick<RevenueFunnel, 'views' | 'progressed' | 'completed' | 'converted'>;
  label: string;
  color: string;
}

/** The four funnel stages (views → progressed → completed → converted). */
const STAGES: FunnelStage[] = [
  { key: 'views', label: __('Views', 'goalcart'), color: '#72aee6' },
  { key: 'progressed', label: __('Progressed', 'goalcart'), color: '#2271b1' },
  { key: 'completed', label: __('Completed', 'goalcart'), color: '#00a32a' },
  { key: 'converted', label: __('Converted', 'goalcart'), color: '#996800' },
];

/** Format a 0–1 rate as a percentage ('' when no data). */
function formatRate(rate: number | null): string {
  return rate === null ? '—' : `${(rate * 100).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
}

interface FunnelVisualProps {
  funnel: RevenueFunnel;
  /** Smaller bars (compact tables/rows instead of a standalone panel). */
  compact?: boolean;
}

/**
 * Revenue funnel visualization (Phase 33.6 Attribution Dashboard / Goal
 * Performance). Four bars whose widths are proportional to each stage's
 * count, with the completion and conversion rates below — a single-glance
 * read of views → progressed → completed → converted.
 */
export default function FunnelVisual({ funnel, compact = false }: FunnelVisualProps) {
  const max = Math.max(1, funnel.views);

  return (
    <Box role="img" aria-label={__('Goal funnel: views, progressed, completed, converted', 'goalcart')}>
      <Box sx={{ display: 'flex', flexDirection: 'column', gap: 0.75 }}>
        {STAGES.map((stage) => {
          const count = funnel[stage.key];
          const width = Math.max(6, (count / max) * 100);

          return (
            <Box
              key={stage.key}
              sx={{
                height: compact ? 22 : 26,
                width: `${width}%`,
                minWidth: 84,
                bgcolor: stage.color,
                borderRadius: 1,
                px: 1,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 1,
                color: '#fff',
                transition: 'width 0.3s ease',
              }}
            >
              <Typography variant="caption" sx={{ fontWeight: 600, whiteSpace: 'nowrap' }}>
                {stage.label}
              </Typography>
              <Typography variant="caption" sx={{ whiteSpace: 'nowrap', fontWeight: 600 }}>
                {formatNumber(count)}
              </Typography>
            </Box>
          );
        })}
      </Box>

      <Box sx={{ display: 'flex', gap: 2.5, mt: 1 }}>
        <Typography variant="caption" color="text.secondary">
          {__('Completion', 'goalcart')}: {formatRate(funnel.completion_rate)}
        </Typography>
        <Typography variant="caption" color="text.secondary">
          {__('Conversion', 'goalcart')}: {formatRate(funnel.conversion_rate)}
        </Typography>
      </Box>
    </Box>
  );
}
