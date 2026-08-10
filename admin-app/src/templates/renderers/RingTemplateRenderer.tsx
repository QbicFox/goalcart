import Box from '@mui/material/Box';

import { formatNumber } from '../../lib/format';
import { bool, num, str } from '../utils';
import type { GoalTemplateProps } from '../registry';

/**
 * Ring template body — a circular gauge (SVG circle, not a fill bar).
 *
 * Mirrors the storefront `ringPanel()` markup exactly: a track circle +
 * a progress circle whose stroke-dashoffset draws the percentage, with
 * the locale-formatted percent readout centered inside. The resolved
 * settings drive the size, stroke width, track color and readout toggle;
 * `accent` colors the progress stroke.
 */
export default function RingTemplateRenderer({
  goal,
  settings,
  animation,
}: GoalTemplateProps) {
  const percent = Math.max(0, Math.min(100, goal.percentage));
  const size = num(settings, 'ringSize', 120);
  const stroke = num(settings, 'strokeWidth', 12);
  const accent = str(settings, 'accent', '#2271b1');
  const trackColor = str(settings, 'trackColor', '#f0f0f1');
  const showPercent = bool(settings, 'showPercent', true);
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;
  const center = size / 2;

  return (
    <Box sx={{ position: 'relative', width: 'fit-content', maxWidth: '100%', marginInline: 'auto' }}>
      <svg
        viewBox={`0 0 ${size} ${size}`}
        width={size}
        height={size}
        role="img"
        style={{ display: 'block', maxWidth: '100%', height: 'auto' }}
      >
        <circle
          cx={center}
          cy={center}
          r={radius}
          fill="none"
          stroke={trackColor}
          strokeWidth={stroke}
        />
        <circle
          cx={center}
          cy={center}
          r={radius}
          fill="none"
          stroke={accent}
          strokeWidth={stroke}
          strokeLinecap={percent <= 0 ? 'butt' : 'round'}
          strokeDasharray={circumference}
          strokeDashoffset={circumference * (1 - percent / 100)}
          transform={`rotate(-90 ${center} ${center})`}
          style={{ transition: animation ? 'stroke-dashoffset 0.6s ease' : 'none' }}
        />
      </svg>
      {showPercent && (
        <Box
          component="span"
          sx={{
            position: 'absolute',
            inset: 0,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontWeight: 800,
            lineHeight: 1,
            letterSpacing: '-0.02em',
            fontVariantNumeric: 'tabular-nums',
            color: 'inherit',
            pointerEvents: 'none',
          }}
        >
          {formatNumber(Math.round(percent))}%
        </Box>
      )}
    </Box>
  );
}
