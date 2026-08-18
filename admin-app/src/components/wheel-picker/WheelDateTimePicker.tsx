import Stack from '@mui/material/Stack';
import { useMemo, useState } from 'react';

import { getBootData } from '../../boot';
import WheelDatePicker from './WheelDatePicker';
import WheelField from './WheelField';
import WheelTimePicker from './WheelTimePicker';

export interface WheelDateTimePickerProps {
  /** `Y-m-d H:i:s` / `Y-m-d`, or null when unset. */
  value: string | null;
  /** Fired with `Y-m-d H:i:s`, or null when cleared. */
  onChange: (value: string | null) => void;
  label?: string;
  helperText?: string;
  /** Overrides the default raw `Y-m-d H:i` value label. */
  valueLabel?: string;
}

/** Split a stored datetime into its `Y-m-d` date and `HH:mm` time parts. */
function splitValue(value: string | null): { date: string; time: string } {
  if (!value) {
    return { date: '', time: '12:00' };
  }

  const [datePart, timePart] = value.split(' ');

  return { date: datePart || '', time: (timePart ?? '').slice(0, 5) || '12:00' };
}

/**
 * Wheel date + time picker — a calendar-aware date row above a time row.
 * Used by the campaign / mission scheduling fields, whose API stores
 * `Y-m-d H:i:s` (or `Y-m-d`). The date row follows the admin language
 * (Jalali for `fa_*`), the time row is always a 24-hour clock.
 */
export default function WheelDateTimePicker({
  value,
  onChange,
  label,
  helperText,
  valueLabel,
}: WheelDateTimePickerProps) {
  const parts = useMemo(() => splitValue(value), [value]);
  const [time, setTime] = useState(parts.time);
  const [date, setDate] = useState(parts.date);

  // Follow external value changes (reset / clear elsewhere) via the
  // render-phase adjustment pattern — setState in an effect is flagged by
  // react-hooks/set-state-in-effect.
  const [prevParts, setPrevParts] = useState(parts);

  if (prevParts !== parts) {
    setPrevParts(parts);
    setTime(parts.time);
    setDate(parts.date);
  }

  // When no date has been picked yet, a time scroll anchors to the
  // site-local today (matches the rest of the app's date math).
  const today = useMemo(() => getBootData().currentDate, []);

  const pickTime = (next: string) => {
    setTime(next);
    onChange(`${date || parts.date || today} ${next}:00`);
  };

  const pickDate = (ymd: string) => {
    setDate(ymd);
    onChange(`${ymd} ${time}:00`);
  };

  const clear = () => {
    setDate('');
    setTime('12:00');
    onChange(null);
  };

  return (
    <WheelField
      label={label ?? ''}
      valueLabel={valueLabel ?? (value ? value.slice(0, 16) : undefined)}
      helperText={helperText}
      onClear={value ? clear : undefined}
    >
      <Stack spacing={0.75}>
        <WheelDatePicker value={parts.date || null} onChange={pickDate} />
        <WheelTimePicker value={time} onChange={pickTime} />
      </Stack>
    </WheelField>
  );
}
