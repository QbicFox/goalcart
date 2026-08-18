import { WheelPicker, WheelPickerWrapper, type WheelPickerOption } from '@ncdai/react-wheel-picker';
import Box from '@mui/material/Box';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from 'react';

import { getBootData } from '../../boot';
import { toYmd } from '../../date-range/dateRange';
import {
  JALALI_MONTH_NAMES,
  daysInGregorianMonth,
  daysInJalaliMonth,
  gregorianMonthName,
  isJalaliLocale,
  jalaliToDate,
  toJalali,
  type CalendarDate,
} from '../../lib/calendar';
import WheelField from './WheelField';

export interface WheelDatePickerProps {
  /** Gregorian `Y-m-d`, or null before the first selection. */
  value: string | null;
  /** Fired with a Gregorian `Y-m-d` whenever a wheel settles on a date. */
  onChange: (ymd: string) => void;
  label?: string;
  valueLabel?: string;
}

/** Column headers above the wheels, in column order. */
const COLUMN_LABELS = [__('Year', 'faracart'), __('Month', 'faracart'), __('Day', 'faracart')];

/** Years offered on each side of the current year. */
const YEAR_SPAN = 20;

/**
 * Options in the wheel ring (rows-per-side = visibleCount / 4, so it
 * must be a multiple of 4). 12 keeps the wheel ~111px tall — smaller
 * values collapse the cylinder (4 computes to an 8px wheel).
 */
const VISIBLE_COUNT = 12;
const ITEM_HEIGHT = 30;

/**
 * Wheel date picker — year / month / day wheels built on
 * `@ncdai/react-wheel-picker`.
 *
 * The calendar follows the WordPress admin language: `fa_*` locales use
 * the Jalali calendar, everything else stays Gregorian. The value is
 * always a Gregorian `Y-m-d` string (the app-wide interchange format) —
 * the wheels only change how that instant is presented and edited.
 */
export default function WheelDatePicker({ value, onChange, label, valueLabel }: WheelDatePickerProps) {
  const jalali = useMemo(() => isJalaliLocale(), []);

  const today = useMemo(() => new Date(`${getBootData().currentDate}T12:00:00`), []);
  const todayParts = useMemo<CalendarDate>(
    () =>
      jalali
        ? toJalali(today)
        : { year: today.getFullYear(), month: today.getMonth() + 1, day: today.getDate() },
    [jalali, today]
  );

  /** Active-calendar coordinates of the current value (fallback: today). */
  const current = useMemo<CalendarDate>(() => {
    if (!value) {
      return todayParts;
    }

    const date = new Date(`${value}T12:00:00`);

    return jalali
      ? toJalali(date)
      : { year: date.getFullYear(), month: date.getMonth() + 1, day: date.getDate() };
  }, [jalali, todayParts, value]);

  const [year, setYear] = useState(current.year);
  const [month, setMonth] = useState(current.month);
  const [day, setDay] = useState(current.day);

  // Follow external value changes (reset, preset switch, clear) via the
  // render-phase adjustment pattern — setState in an effect is flagged by
  // react-hooks/set-state-in-effect.
  const [prevCurrent, setPrevCurrent] = useState(current);

  if (prevCurrent !== current) {
    setPrevCurrent(current);
    setYear(current.year);
    setMonth(current.month);
    setDay(current.day);
  }

  const daysFor = (y: number, m: number) =>
    jalali ? daysInJalaliMonth(y, m) : daysInGregorianMonth(y, m);

  const days = daysFor(year, month);

  /** Convert active-calendar coordinates to the Gregorian Y-m-d and emit. */
  const emit = (y: number, m: number, d: number) => {
    const date = jalali ? jalaliToDate(y, m, d) : new Date(y, m - 1, d, 12);
    onChange(toYmd(date));
  };

  const changeYear = (y: number) => {
    const d = Math.min(day, daysFor(y, month));
    setYear(y);
    setDay(d);
    emit(y, month, d);
  };

  const changeMonth = (m: number) => {
    const d = Math.min(day, daysFor(year, m));
    setMonth(m);
    setDay(d);
    emit(year, m, d);
  };

  const changeDay = (d: number) => {
    setDay(d);
    emit(year, month, d);
  };

  const yearOptions = useMemo<WheelPickerOption<number>[]>(() => {
    const min = Math.min(todayParts.year - YEAR_SPAN, current.year);
    const max = Math.max(todayParts.year + YEAR_SPAN, current.year);
    const options: WheelPickerOption<number>[] = [];

    for (let y = min; y <= max; y += 1) {
      options.push({ value: y, label: String(y) });
    }

    return options;
  }, [todayParts.year, current.year]);

  const monthOptions = useMemo<WheelPickerOption<number>[]>(() => {
    const options: WheelPickerOption<number>[] = [];

    for (let m = 1; m <= 12; m += 1) {
      options.push({
        value: m,
        label: jalali ? JALALI_MONTH_NAMES[m - 1] : gregorianMonthName(m),
      });
    }

    return options;
  }, [jalali]);

  const dayOptions = useMemo<WheelPickerOption<number>[]>(() => {
    const options: WheelPickerOption<number>[] = [];

    for (let d = 1; d <= days; d += 1) {
      options.push({ value: d, label: String(d) });
    }

    return options;
  }, [days]);

  // Guard against malformed external values whose day exceeds the
  // displayed month (the wheel must always contain its controlled value).
  const safeDay = Math.min(day, days);

  const wheels = (
    <Box>
      <Stack direction="row" sx={{ mb: 0.25 }}>
        {COLUMN_LABELS.map((column) => (
          <Typography
            key={column}
            variant="caption"
            color="text.secondary"
            sx={{ flex: 1, textAlign: 'center', fontSize: 11, fontWeight: 600 }}
          >
            {column}
          </Typography>
        ))}
      </Stack>
      <WheelPickerWrapper className="faracart-wheel-date">
        <WheelPicker
          options={yearOptions}
          value={year}
          onValueChange={changeYear}
          infinite
          visibleCount={VISIBLE_COUNT}
          optionItemHeight={ITEM_HEIGHT}
        />
        <WheelPicker
          options={monthOptions}
          value={month}
          onValueChange={changeMonth}
          infinite
          visibleCount={VISIBLE_COUNT}
          optionItemHeight={ITEM_HEIGHT}
        />
        <WheelPicker
          options={dayOptions}
          value={safeDay}
          onValueChange={changeDay}
          visibleCount={VISIBLE_COUNT}
          optionItemHeight={ITEM_HEIGHT}
        />
      </WheelPickerWrapper>
    </Box>
  );

  if (!label) {
    return wheels;
  }

  return (
    <WheelField label={label} valueLabel={valueLabel}>
      {wheels}
    </WheelField>
  );
}
