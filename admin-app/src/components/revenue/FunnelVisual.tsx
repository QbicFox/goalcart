import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { Fragment } from 'react';

import { formatNumber } from '../../lib/format';
import type { RevenueFunnel } from '../../types';

interface FunnelStage {
  key: keyof Pick<RevenueFunnel, 'views' | 'progressed' | 'completed' | 'converted'>;
  label: string;
  color: string;
}

/**
 * The four funnel stages (views → progressed → completed → purchased).
 * The final stage is labeled "Purchased" — never "Converted" — so the
 * commercial outcome (an attributed WooCommerce order) stays distinct
 * from mission completion (Improvement.md §17/§32).
 */
const STAGES: FunnelStage[] = [
  { key: 'views', label: __('Views', 'faracart'), color: '#72aee6' },
  { key: 'progressed', label: __('Progressed', 'faracart'), color: '#2271b1' },
  { key: 'completed', label: __('Completed', 'faracart'), color: '#00a32a' },
  { key: 'converted', label: __('Purchased', 'faracart'), color: '#996800' },
];

/** Format a 0–1 rate as a percentage ('' when no data). */
function formatRate(rate: number | null): string {
  return rate === null ? '—' : `${(rate * 100).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
}

/** Percentage from the previous stage to this one (null when no previous). */
function transitionPct(previous: number, current: number): number | null {
  return previous > 0 ? (current / previous) * 100 : null;
}

interface FunnelVisualProps {
  funnel: RevenueFunnel;
  /** Smaller bars (compact tables/rows instead of a standalone panel). */
  compact?: boolean;
  /**
   * Show the percentage each stage carries over from the previous one
   * between the bars — the drop-off read (e.g. "↓ 43%" views → progressed)
   * the mission detail drawer needs (Improvement.md §20/§23).
   */
  showTransitions?: boolean;
}

/**
 * Revenue funnel visualization (Attribution Dashboard / Mission
 * Performance; detail drawer). Four bars whose widths are
 * proportional to each stage's count, with the completion and purchase
 * rates below — a single-glance read of views → progressed → completed →
 * purchased. With `showTransitions`, the percentage carried from each
 * stage to the next is rendered between the bars.
 */
export default function FunnelVisual({ funnel, compact = false, showTransitions = false }: FunnelVisualProps) {
  const max = Math.max(1, funnel.views);

  return (
    <Box role="img" aria-label={__('Mission funnel: views, progressed, completed, purchased', 'faracart')}>
      <Box sx={{ display: 'flex', flexDirection: 'column', gap: 0.75 }}>
        {STAGES.map((stage, index) => {
          const count = funnel[stage.key];
          const width = Math.max(6, (count / max) * 100);
          const previous = index > 0 ? funnel[STAGES[index - 1].key] : 0;
          const pct = transitionPct(previous, count);

          return (
            <Fragment key={stage.key}>
              {showTransitions && index > 0 && (
                <Box
                  role="img"
                  aria-label={sprintf(
                    /* translators: 1: stage label, 2: percentage. */
                    __('%1$s carries %2$s of the previous stage', 'faracart'),
                    stage.label,
                    pct === null ? '—' : `${pct.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`
                  )}
                  sx={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: 0.5,
                    height: 18,
                    color: 'text.secondary',
                  }}
                >
                  <ArrowDownwardIcon sx={{ fontSize: 14 }} />
                  <Typography variant="caption" sx={{ fontWeight: 600 }}>
                    {pct === null ? '—' : `${pct.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`}
                  </Typography>
                </Box>
              )}
              <Box
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
            </Fragment>
          );
        })}
      </Box>

      <Box sx={{ display: 'flex', gap: 2.5, mt: 1 }}>
        <Typography variant="caption" color="text.secondary">
          {__('Completion', 'faracart')}: {formatRate(funnel.completion_rate)}
        </Typography>
        <Typography variant="caption" color="text.secondary">
          {__('Purchase rate', 'faracart')}: {formatRate(funnel.conversion_rate)}
        </Typography>
      </Box>
    </Box>
  );
}
