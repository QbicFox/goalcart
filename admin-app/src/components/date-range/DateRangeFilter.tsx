import { lazy, Suspense, useState } from 'react';
import CalendarMonthIcon from '@mui/icons-material/CalendarMonth';
import CheckIcon from '@mui/icons-material/Check';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import { __, sprintf } from '@wordpress/i18n';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Divider from '@mui/material/Divider';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import MenuItem from '@mui/material/MenuItem';
import MenuList from '@mui/material/MenuList';
import Popover from '@mui/material/Popover';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';

import { useDateRange } from '../../date-range/DateRangeContext';
import { FIXED_PRESETS, formatDay, formatRangeLabel, presetLabel } from '../../date-range/dateRange';
import type { FixedRangePreset } from '../../date-range/types';

// The custom range grid is only fetched when the user opens "Custom
// range" — keeps it out of the initial bundle.
const CustomRangePicker = lazy(() => import('./CustomRangePicker'));

/**
 * Date-range filter, shown in the analytics filter toolbar.
 * Presets (Today / Yesterday / Last 7 / Last 30) apply instantly;
 * "Custom" opens a lazy-loaded month-grid picker. The selection is
 * shared via DateRangeContext and persists to URL + storage.
 *
 * Mirrors the reference plugin's DateRangeFilter.
 */
export default function DateRangeFilter() {
  const { range, comparison, setPreset, setCustomRange } = useDateRange();

  const [anchorEl, setAnchorEl] = useState<HTMLElement | null>(null);
  const [showCustom, setShowCustom] = useState(false);

  const open = Boolean(anchorEl);

  const close = () => {
    setAnchorEl(null);
    setShowCustom(false);
  };

  const handlePreset = (preset: FixedRangePreset) => {
    setPreset(preset);
    close();
  };

  const handleCustom = (from: string, to: string) => {
    setCustomRange(from, to);
    close();
  };

  return (
    <>
      <Button
        size="small"
        color="inherit"
        variant="outlined"
        startIcon={<CalendarMonthIcon fontSize="small" />}
        endIcon={<ExpandMoreIcon fontSize="small" />}
        onClick={(event) => setAnchorEl(event.currentTarget)}
        aria-haspopup="true"
        aria-expanded={open}
        aria-label={__('Date range filter', 'faracart')}
        sx={{ textTransform: 'none', borderColor: 'divider', color: 'text.primary', height: 40 }}
      >
        {formatRangeLabel(range)}
      </Button>

      <Popover
        open={open}
        anchorEl={anchorEl}
        onClose={close}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
        transformOrigin={{ vertical: 'top', horizontal: 'right' }}
        slotProps={{ paper: { sx: { width: 320, p: 1.5 } } }}
      >
        <Typography variant="subtitle2" sx={{ px: 1, mb: 0.5 }}>
          {__('Date range', 'faracart')}
        </Typography>

        <MenuList dense disablePadding>
          {FIXED_PRESETS.map((preset) => {
            const active = range.preset === preset;

            return (
              <MenuItem key={preset} selected={active} onClick={() => handlePreset(preset)}>
                {active ? (
                  <ListItemIcon sx={{ minWidth: 28 }}>
                    <CheckIcon fontSize="small" color="primary" />
                  </ListItemIcon>
                ) : (
                  <Box sx={{ minWidth: 28 }} />
                )}
                <ListItemText>{presetLabel(preset)}</ListItemText>
              </MenuItem>
            );
          })}

          <Divider sx={{ my: 1 }} />

          <MenuItem
            selected={range.preset === 'custom'}
            onClick={() => setShowCustom((current) => !current)}
          >
            <Box sx={{ minWidth: 28 }}>
              {range.preset === 'custom' && <CheckIcon fontSize="small" color="primary" />}
            </Box>
            <ListItemText>{__('Custom range…', 'faracart')}</ListItemText>
          </MenuItem>
        </MenuList>

        {showCustom && (
          <Box sx={{ mt: 1.5 }}>
            <Suspense fallback={<Skeleton variant="rounded" height={120} />}>
              <CustomRangePicker from={range.from} to={range.to} onApply={handleCustom} />
            </Suspense>
          </Box>
        )}

        <Typography
          variant="caption"
          color="text.secondary"
          sx={{ display: 'block', mt: 1.5, px: 1 }}
        >
          {sprintf(
            __('Compared with previous period (%1$s – %2$s).', 'faracart'),
            formatDay(comparison.from),
            formatDay(comparison.to)
          )}
        </Typography>
      </Popover>
    </>
  );
}
