import { useMemo, useState } from 'react';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import { __ } from '@wordpress/i18n';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';

import { getBootData } from '../../boot';
import { formatDay, normalizeBounds, toYmd } from '../../date-range/dateRange';

interface CustomRangePickerProps {
  /** Initial bounds (`Y-m-d`), already normalized. */
  from: string;
  to: string;
  onApply: (from: string, to: string) => void;
}

/** Weekday header — Sunday first (matches JS getDay()). */
const WEEKDAY_LABELS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

/** Localized "Month Year" caption for the current view. */
function monthLabel(year: number, month: number): string {
  const boot = getBootData();

  try {
    return new Intl.DateTimeFormat(boot.locale.replace('_', '-'), {
      month: 'long',
      year: 'numeric',
    }).format(new Date(year, month - 1, 1));
  } catch {
    return `${year}-${String(month).padStart(2, '0')}`;
  }
}

/**
 * Custom date-range editor (Gregorian month grid).
 *
 * A self-contained month-grid picker (two linked selections — start then
 * end) with hover-previewed range highlighting, navigable by month. The
 * user picks two `Y-m-d` dates and the picker calls `onApply` with the
 * normalized bounds, so the REST layer and URL params keep the same
 * format (see `date-range/dateRange.ts`).
 *
 * Deliberately lazy-loaded (React.lazy in DateRangeFilter) so it stays
 * out of the initial bundle, mirroring the reference plugin.
 */
export default function CustomRangePicker({ from, to, onApply }: CustomRangePickerProps) {
  const initialFrom = useMemo(() => (from ? new Date(`${from}T12:00:00`) : null), [from]);
  const initialTo = useMemo(() => (to ? new Date(`${to}T12:00:00`) : null), [to]);

  // "Today" is the site's local date from boot data (matches the
  // DateRangeContext presets) — not the admin's browser timezone, which
  // could differ by a day in multi-timezone setups.
  const today = getBootData().currentDate;

  const [start, setStart] = useState<string | null>(initialFrom ? toYmd(initialFrom) : null);
  const [end, setEnd] = useState<string | null>(initialTo ? toYmd(initialTo) : null);
  const [hover, setHover] = useState<string | null>(null);

  // The month grid being displayed — starts on the selected start date,
  // otherwise the current month.
  const [view, setView] = useState(() => {
    const base = initialFrom ?? new Date(`${today}T12:00:00`);

    return { year: base.getFullYear(), month: base.getMonth() + 1 };
  });

  const selectDay = (ymd: string) => {
    if (!start || (start && end)) {
      // Fresh selection: pick the start, clear the end.
      setStart(ymd);
      setEnd(null);
    } else {
      setEnd(ymd);
    }
  };

  const apply = () => {
    if (!start || !end) {
      return;
    }

    const bounds = normalizeBounds(start, end);
    onApply(bounds.from, bounds.to);
  };

  const goMonth = (delta: number) => {
    setView((current) => {
      let month = current.month + delta;
      let year = current.year;

      if (month < 1) {
        month = 12;
        year -= 1;
      } else if (month > 12) {
        month = 1;
        year += 1;
      }

      return { year, month };
    });
  };

  const daysInMonth = new Date(view.year, view.month, 0).getDate();
  const leadingBlanks = new Date(view.year, view.month - 1, 1).getDay();

  /** Keys strictly inside the selected (or hover-previewed) range. */
  const inRangeKeys = useMemo(() => {
    if (!start) {
      return new Set<string>();
    }

    const hi = end ?? hover ?? start;
    const lo = start <= hi ? start : hi;
    const hiSorted = start <= hi ? hi : start;

    const keys = new Set<string>();
    const cursor = new Date(`${lo}T12:00:00`);
    const last = new Date(`${hiSorted}T12:00:00`);

    while (cursor <= last) {
      keys.add(toYmd(cursor));
      cursor.setDate(cursor.getDate() + 1);
    }

    return keys;
  }, [start, end, hover]);

  const cells: Array<number | null> = [
    ...Array<null>(leadingBlanks).fill(null),
    ...Array.from({ length: daysInMonth }, (_, index) => index + 1),
  ];

  return (
    <Box sx={{ width: 288, maxWidth: '100%' }}>
      {/* Month navigation */}
      <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
        <IconButton
          size="small"
          onClick={() => goMonth(-1)}
          aria-label={__('Previous month', 'faracart')}
        >
          <ChevronLeftIcon fontSize="small" />
        </IconButton>
        <Typography variant="subtitle2" sx={{ fontWeight: 600 }}>
          {monthLabel(view.year, view.month)}
        </Typography>
        <IconButton
          size="small"
          onClick={() => goMonth(1)}
          aria-label={__('Next month', 'faracart')}
        >
          <ChevronRightIcon fontSize="small" />
        </IconButton>
      </Stack>

      {/* Weekday header */}
      <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', mb: 0.5 }}>
        {WEEKDAY_LABELS.map((weekday) => (
          <Typography
            key={weekday}
            variant="caption"
            align="center"
            color="text.secondary"
            sx={{ fontSize: 11 }}
          >
            {weekday}
          </Typography>
        ))}
      </Box>

      {/* Day grid */}
      <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 0.5 }}>
        {cells.map((day, index) => {
          if (day === null) {
            return <Box key={`blank-${index}`} />;
          }

          const ymd = toYmd(new Date(view.year, view.month - 1, day, 12));
          const isStart = ymd === start;
          const isEnd = ymd === end;
          const selected = isStart || isEnd;
          const inRange = inRangeKeys.has(ymd) && !selected;
          const isToday = ymd === today;

          return (
            <Button
              key={ymd}
              size="small"
              disableRipple
              onMouseEnter={() => setHover(ymd)}
              onMouseLeave={() => setHover(null)}
              onClick={() => selectDay(ymd)}
              aria-label={formatDay(ymd)}
              sx={{
                minWidth: 0,
                px: 0,
                py: 0.75,
                borderRadius: 1.5,
                fontSize: 12,
                fontWeight: selected ? 700 : 400,
                color: selected ? 'primary.contrastText' : 'text.primary',
                bgcolor: selected ? 'primary.main' : inRange ? 'primary.light' : 'transparent',
                ...(isToday && !selected
                  ? {
                      boxShadow: 'inset 0 0 0 1.5px',
                      color: 'primary.main',
                    }
                  : {}),
                '&:hover': {
                  bgcolor: selected ? 'primary.main' : 'action.hover',
                },
                ...(inRange ? { color: 'primary.dark' } : {}),
              }}
            >
              {day}
            </Button>
          );
        })}
      </Box>

      {/* Selection summary / prompt */}
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1.5 }}>
        {start && end
          ? `${formatDay(start)} – ${formatDay(end)}`
          : __('Choose a start and end date.', 'faracart')}
      </Typography>

      <Button
        variant="contained"
        size="small"
        fullWidth
        onClick={apply}
        disabled={!start || !end}
        sx={{ mt: 1 }}
      >
        {__('Apply range', 'faracart')}
      </Button>
    </Box>
  );
}
