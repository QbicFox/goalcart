import { WheelPicker, WheelPickerWrapper, type WheelPickerOption } from '@ncdai/react-wheel-picker';
import Box from '@mui/material/Box';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from 'react';

import WheelField from './WheelField';

export interface WheelTimePickerProps {
  /** `HH:mm`, or '' when unset. */
  value: string;
  /** Fired with `HH:mm` whenever a wheel settles on a time. */
  onChange: (time: string) => void;
  label?: string;
  valueLabel?: string;
  helperText?: string;
  /** Show a clear affordance (renders instead of valueLabel). */
  onClear?: () => void;
}

// Options in the wheel ring (rows-per-side = visibleCount / 4); 12 keeps
// the wheel ~111px tall — smaller values collapse the cylinder.
const VISIBLE_COUNT = 12;
const ITEM_HEIGHT = 30;

/** Column headers above the wheels, in column order. */
const COLUMN_LABELS = [__('Hour', 'faracart'), __('Minute', 'faracart')];

const pad = (n: number) => String(n).padStart(2, '0');

/**
 * Wheel time picker — hour / minute wheels built on
 * `@ncdai/react-wheel-picker`. 24-hour clock, values are `HH:mm`.
 */
export default function WheelTimePicker({ value, onChange, label, valueLabel, helperText, onClear }: WheelTimePickerProps) {
  const initial = useMemo(() => {
    const match = /^(\d{2}):(\d{2})$/.exec(value);

    return match ? { hour: Number(match[1]), minute: Number(match[2]) } : { hour: 12, minute: 0 };
  }, [value]);

  const [hour, setHour] = useState(initial.hour);
  const [minute, setMinute] = useState(initial.minute);

  // Follow external value changes via the render-phase adjustment pattern
  // (setState in an effect is flagged by react-hooks/set-state-in-effect).
  const [prevInitial, setPrevInitial] = useState(initial);

  if (prevInitial !== initial) {
    setPrevInitial(initial);
    setHour(initial.hour);
    setMinute(initial.minute);
  }

  const emit = (h: number, m: number) => onChange(`${pad(h)}:${pad(m)}`);

  const hourOptions = useMemo<WheelPickerOption<number>[]>(
    () => Array.from({ length: 24 }, (_, h) => ({ value: h, label: pad(h) })),
    []
  );

  const minuteOptions = useMemo<WheelPickerOption<number>[]>(
    () => Array.from({ length: 60 }, (_, m) => ({ value: m, label: pad(m) })),
    []
  );

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
      <WheelPickerWrapper className="faracart-wheel-time">
        <WheelPicker
          options={hourOptions}
          value={hour}
          onValueChange={(h) => {
            setHour(h);
            emit(h, minute);
          }}
          infinite
          visibleCount={VISIBLE_COUNT}
          optionItemHeight={ITEM_HEIGHT}
        />
        <WheelPicker
          options={minuteOptions}
          value={minute}
          onValueChange={(m) => {
            setMinute(m);
            emit(hour, m);
          }}
          infinite
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
    <WheelField label={label} valueLabel={valueLabel} helperText={helperText} onClear={onClear}>
      {wheels}
    </WheelField>
  );
}
