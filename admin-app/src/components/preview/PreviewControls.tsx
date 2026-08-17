import Box from '@mui/material/Box';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import type { PreviewControlsValue, PreviewPreset } from './types';

interface PreviewControlsProps {
  value: PreviewControlsValue;
  /** Apply a preview-state preset (the parent computes amount/quantity). */
  onApplyPreset: (preset: PreviewPreset) => void;
}

const PRESETS: Array<{ value: PreviewPreset; label: string }> = [
  { value: 'empty', label: __('Empty cart', 'faracart') },
  { value: '25', label: '25%' },
  { value: '50', label: '50%' },
  { value: '75', label: '75%' },
  { value: '100', label: __('Complete', 'faracart') },
];

/**
 * Preview Settings: the remaining preview-level control. The simulated
 * amount/quantity, reward state, device width and template override were
 * removed — the preview renders the mission/campaign's own configuration
 * (selected template or the global default) at the column width, and
 * only the progress state (empty cart → completed) is previewable here.
 * Each preset derives its simulated amount/quantity from the current
 * form target, so no raw simulation values are exposed to the admin.
 */
export default function PreviewControls({ value, onApplyPreset }: PreviewControlsProps) {
  return (
    <Box>
      <Typography variant="body2" color="text.secondary" gutterBottom>
        {__('Preview state', 'faracart')}
      </Typography>
      <ToggleButtonGroup
        size="small"
        exclusive
        fullWidth
        value={value.preset}
        onChange={(_event, next) => {
          if (next) {
            onApplyPreset(next as PreviewPreset);
          }
        }}
        aria-label={__('Preview state', 'faracart')}
      >
        {PRESETS.map((preset) => (
          <ToggleButton key={preset.value} value={preset.value} sx={{ flex: 1 }}>
            {preset.label}
          </ToggleButton>
        ))}
      </ToggleButtonGroup>
    </Box>
  );
}
