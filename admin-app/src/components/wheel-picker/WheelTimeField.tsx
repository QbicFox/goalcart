import AccessTimeIcon from '@mui/icons-material/AccessTime';
import CloseIcon from '@mui/icons-material/Close';
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

import WheelField from './WheelField';
import WheelTimePicker from './WheelTimePicker';

export interface WheelTimeFieldProps {
  /** `HH:mm`, or '' when unset. */
  value: string;
  /** Fired with `HH:mm`, or '' when cleared. */
  onChange: (value: string) => void;
  label?: string;
  helperText?: string;
}

/**
 * Input-style wheel time picker.
 *
 * A read-only text field shows the chosen `HH:mm` (24-hour clock) and
 * stays empty until one is picked. Clicking the field opens the wheel
 * pickers in a modal dialog; the wheels edit a draft that is committed
 * on Apply.
 */
export default function WheelTimeField({ value, onChange, label, helperText }: WheelTimeFieldProps) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState(value);

  const openModal = () => {
    setDraft(value);
    setOpen(true);
  };

  const apply = () => {
    onChange(draft);
    setOpen(false);
  };

  const clear = () => onChange('');

  return (
    <>
      <TextField
        label={label}
        fullWidth
        size="small"
        value={value}
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
                  <AccessTimeIcon sx={{ fontSize: 18, color: 'text.secondary' }} />
                </Stack>
              </InputAdornment>
            ),
          },
        }}
      />

      <Dialog open={open} onClose={() => setOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>{label ?? __('Time', 'faracart')}</DialogTitle>
        <DialogContent>
          {/* Framed exactly like the datetime picker's modal so the
              highlight band + muted rows (scoped to .faracart-wheel)
              apply — a bare picker renders without them. */}
          <WheelField label="" valueLabel={draft || undefined}>
            <WheelTimePicker value={draft} onChange={setDraft} />
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
