import CloseIcon from '@mui/icons-material/Close';
import Box from '@mui/material/Box';
import FormHelperText from '@mui/material/FormHelperText';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

interface WheelFieldProps {
  label: string;
  /** The currently selected value, shown on the right of the label. */
  valueLabel?: string;
  helperText?: string;
  /** Show a clear affordance (renders instead of valueLabel). */
  onClear?: () => void;
  children: ReactNode;
}

/**
 * The framed container shared by the wheel pickers — a labeled, outlined
 * box that reads like an MUI form control while hosting the wheel rows.
 */
export default function WheelField({ label, valueLabel, helperText, onClear, children }: WheelFieldProps) {
  return (
    <Box>
      <Stack
        direction="row"
        sx={{ alignItems: 'center', justifyContent: 'space-between', minHeight: 22, mb: 0.25 }}
      >
        <Typography variant="caption" color="text.secondary" component="span" sx={{ fontWeight: 600 }}>
          {label}
        </Typography>
        <Stack direction="row" sx={{ alignItems: 'center', gap: 0.5 }}>
          {valueLabel ? (
            <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.primary' }}>
              {valueLabel}
            </Typography>
          ) : null}
          {onClear ? (
            <Tooltip title={__('Clear', 'faracart')}>
              <IconButton size="small" onClick={onClear} aria-label={__('Clear', 'faracart')} sx={{ p: 0.25 }}>
                <CloseIcon sx={{ fontSize: 14 }} />
              </IconButton>
            </Tooltip>
          ) : null}
        </Stack>
      </Stack>
      <Box
        className="faracart-wheel"
        sx={{
          border: '1px solid',
          borderColor: 'divider',
          borderRadius: 1,
          bgcolor: 'background.paper',
          px: 1,
          py: 0.5,
        }}
      >
        {children}
      </Box>
      {helperText ? (
        <FormHelperText sx={{ mt: 0.5, mx: 0 }}>{helperText}</FormHelperText>
      ) : null}
    </Box>
  );
}
