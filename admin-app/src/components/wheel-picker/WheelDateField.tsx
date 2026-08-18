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

import { formatDateTime } from '../../lib/calendar';
import WheelDatePicker from './WheelDatePicker';
import WheelField from './WheelField';

export interface WheelDateFieldProps {
  /** Gregorian `Y-m-d`, or null when unset. */
  value: string | null;
  /** Fired with a Gregorian `Y-m-d`, or null when cleared. */
  onChange: (value: string | null) => void;
  label?: string;
  helperText?: string;
}

/**
 * Input-style wheel date picker.
 *
 * A read-only text field shows the chosen date formatted in the admin
 * language (Jalali for `fa_*`) and stays empty until one is picked.
 * Clicking the field opens the wheel pickers in a modal dialog; the
 * wheels edit a draft that is committed on Apply.
 */
export default function WheelDateField({ value, onChange, label, helperText }: WheelDateFieldProps) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<string | null>(value);

  const display = value ? formatDateTime(value) : '';

  const openModal = () => {
    setDraft(value);
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
        <DialogTitle>{label ?? __('Date', 'faracart')}</DialogTitle>
        <DialogContent>
          {/* Framed like the other wheel modals so the highlight band +
              muted rows (scoped to .faracart-wheel) apply. */}
          <WheelField label="" valueLabel={draft ? formatDateTime(draft) : undefined}>
            <WheelDatePicker value={draft} onChange={setDraft} />
          </WheelField>
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
