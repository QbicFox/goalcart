import CloseIcon from '@mui/icons-material/Close';
import EventIcon from '@mui/icons-material/Event';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import { __ } from '@wordpress/i18n';
import { useState } from 'react';

import { formatDateTime, nowDateTime } from '../../lib/calendar';
import WheelDateTimePicker from './WheelDateTimePicker';

export interface WheelDateTimeFieldProps {
  /** `Y-m-d H:i:s` / `Y-m-d`, or null when unset. */
  value: string | null;
  /** Fired with `Y-m-d H:i:s`, or null when cleared. */
  onChange: (value: string | null) => void;
  label?: string;
  helperText?: string;
}

/**
 * Input-style wheel date + time picker.
 *
 * A read-only text field shows the value formatted in the admin language
 * (Jalali for `fa_*`), and stays empty until one is chosen. Clicking the
 * field opens the wheel pickers in a modal dialog — positioned at the
 * current date and time when unset — where the wheels edit a draft that
 * is committed on Apply.
 */
export default function WheelDateTimeField({ value, onChange, label, helperText }: WheelDateTimeFieldProps) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<string | null>(null);

  const display = value ? formatDateTime(value) : '';

  const openModal = () => {
    // Start the wheels at the current value, or at the current date and
    // time when unset.
    setDraft(value ?? nowDateTime());
    setOpen(true);
  };

  const apply = () => {
    onChange(draft);
    setOpen(false);
  };

  const clear = () => onChange(null);

  return (
    <>
      <TextField
        label={label}
        fullWidth
        size="small"
        value={display}
        helperText={helperText}
        onClick={openModal}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openModal();
          }
        }}
        slotProps={{
          input: {
            readOnly: true,
            sx: { cursor: 'pointer' },
            endAdornment: (
              <InputAdornment position="end">
                <Stack direction="row" sx={{ alignItems: 'center', gap: 0.25 }}>
                  <Tooltip title={__('Clear', 'faracart')}>
                    <span>
                      <IconButton
                        size="small"
                        onClick={(event) => {
                          event.stopPropagation();
                          clear();
                        }}
                        disabled={!value}
                        aria-label={__('Clear', 'faracart')}
                        sx={{ p: 0.25 }}
                      >
                        <CloseIcon sx={{ fontSize: 16 }} />
                      </IconButton>
                    </span>
                  </Tooltip>
                  <EventIcon sx={{ fontSize: 18, color: 'text.secondary' }} />
                </Stack>
              </InputAdornment>
            ),
          },
        }}
      />

      <Dialog open={open} onClose={() => setOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>{label ?? __('Date and time', 'faracart')}</DialogTitle>
        <DialogContent>
          <WheelDateTimePicker
            value={draft}
            onChange={setDraft}
            valueLabel={draft ? formatDateTime(draft) : undefined}
          />
        </DialogContent>
        <DialogActions>
          <Button size="small" onClick={() => setOpen(false)}>
            {__('Cancel', 'faracart')}
          </Button>
          <Button size="small" variant="contained" onClick={apply}>
            {__('Apply', 'faracart')}
          </Button>
        </DialogActions>
      </Dialog>
    </>
  );
}
