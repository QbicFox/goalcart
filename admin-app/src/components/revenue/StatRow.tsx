import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';

interface StatRowProps {
  label: string;
  value: string;
  explanation?: string;
}

/**
 * One label/value row for the revenue/analytics detail panels — the
 * shared implementation of the per-page AttributionRow / DetailRow /
 * StatRow copies (RevenueOverview, MissionPerformance, Analytics). Renders
 * the label left, the value right, and an optional plain-English
 * explanation below.
 */
export default function StatRow({ label, value, explanation }: StatRowProps) {
  return (
    <Box>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 2 }}>
        <Typography variant="body2" color="text.secondary">
          {label}
        </Typography>
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {value}
        </Typography>
      </Box>
      {explanation && (
        <Typography variant="caption" color="text.secondary" component="p">
          {explanation}
        </Typography>
      )}
    </Box>
  );
}
