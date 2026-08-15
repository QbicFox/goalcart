import type { ReactNode } from 'react';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Typography from '@mui/material/Typography';

import TrendIndicator, { type Trend } from './TrendIndicator';

type Accent = 'default' | 'success' | 'error' | 'warning';

const ACCENT_COLOR: Record<Accent, string> = {
  default: 'text.primary',
  success: 'success.main',
  error: 'error.main',
  warning: 'warning.main',
};

interface KpiCardProps {
  label: string;
  value: ReactNode;
  icon?: ReactNode;
  /** Supporting line under the value (e.g. "vs previous period" already via `trend`). */
  hint?: ReactNode;
  /** Signed change vs the previous period; renders an arrow + % + context. */
  trend?: Trend | null;
  accent?: Accent;
  /** Optional expander / supporting content at the bottom of the card. */
  children?: ReactNode;
}

/**
 * A restrained KPI card (UICHANGES.md §7/§8): label, value, an optional
 * trend indicator with comparison context, a muted hint and an optional
 * expander. The value color communicates meaning only when an accent is
 * passed — cards stay quiet by default.
 */
export default function KpiCard({
  label,
  value,
  icon,
  hint,
  trend,
  accent = 'default',
  children,
}: KpiCardProps) {
  return (
    <Card variant="outlined" sx={{ height: '100%' }}>
      <CardContent sx={{ display: 'flex', flexDirection: 'column', gap: 1, height: '100%' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, color: 'text.secondary' }}>
          {icon}
          <Typography variant="body2" color="text.secondary" noWrap>
            {label}
          </Typography>
        </Box>
        <Typography variant="h5" component="p" sx={{ m: 0, fontWeight: 600, color: ACCENT_COLOR[accent] }}>
          {value}
        </Typography>
        {trend && <TrendIndicator trend={trend} />}
        {hint && (
          <Typography variant="caption" color="text.secondary">
            {hint}
          </Typography>
        )}
        {children}
      </CardContent>
    </Card>
  );
}
