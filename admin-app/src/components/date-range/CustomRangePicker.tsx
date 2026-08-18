import { __ } from '@wordpress/i18n';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useState } from 'react';

import { normalizeBounds } from '../../date-range/dateRange';
import { formatDateTime } from '../../lib/calendar';
import WheelDateField from '../wheel-picker/WheelDateField';

interface CustomRangePickerProps {
  /** Initial bounds (`Y-m-d`), already normalized. */
  from: string;
  to: string;
  onApply: (from: string, to: string) => void;
}

/**
 * Custom date-range editor (calendar-aware wheel date fields).
 *
 * Two input-style wheel date fields — start then end — whose calendars
 * follow the WordPress admin language (Jalali for `fa_*`). The user picks
 * two `Y-m-d` dates and the picker calls `onApply` with the normalized
 * bounds, so the REST layer and URL params keep the same format (see
 * `date-range/dateRange.ts`).
 *
 * Deliberately lazy-loaded (React.lazy in DateRangeFilter) so it stays
 * out of the initial bundle, mirroring the reference plugin.
 */
export default function CustomRangePicker({ from, to, onApply }: CustomRangePickerProps) {
  const [start, setStart] = useState<string | null>(from || null);
  const [end, setEnd] = useState<string | null>(to || null);

  const apply = () => {
    if (!start || !end) {
      return;
    }

    const bounds = normalizeBounds(start, end);
    onApply(bounds.from, bounds.to);
  };

  return (
    <Box sx={{ width: 288, maxWidth: '100%' }}>
      <Stack spacing={1.25}>
        <WheelDateField label={__('From', 'faracart')} value={start} onChange={setStart} />
        <WheelDateField label={__('To', 'faracart')} value={end} onChange={setEnd} />
      </Stack>

      <Divider sx={{ my: 1.5 }} />

      {/* Selection summary / prompt */}
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
        {start && end
          ? `${formatDateTime(start)} – ${formatDateTime(end)}`
          : __('Choose a start and end date.', 'faracart')}
      </Typography>

      <Button
        variant="contained"
        size="small"
        fullWidth
        onClick={apply}
        disabled={!start || !end}
      >
        {__('Apply range', 'faracart')}
      </Button>
    </Box>
  );
}
