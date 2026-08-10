import Box from '@mui/material/Box';
import FormControl from '@mui/material/FormControl';
import InputLabel from '@mui/material/InputLabel';
import MenuItem from '@mui/material/MenuItem';
import Select from '@mui/material/Select';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import type { PreviewControlsValue, PreviewPreset } from './types';

interface PreviewControlsProps {
  value: PreviewControlsValue;
  /** Apply a partial update to the control state. */
  onPatch: (patch: Partial<PreviewControlsValue>) => void;
  /** Apply a state preset (the parent computes amount/quantity). */
  onApplyPreset: (preset: PreviewPreset) => void;
  /**
   * The registered templates for the previewed scope (pluggable engine) —
   * the template override dropdown is built from this list, so new
   * templates automatically appear here.
   */
  templates: Array<{ id: string; label: string }>;
}

const PRESETS: Array<{ value: PreviewPreset; label: string }> = [
  { value: 'empty', label: __('Empty cart', 'goalcart') },
  { value: '25', label: '25%' },
  { value: '50', label: '50%' },
  { value: '75', label: '75%' },
  { value: '100', label: __('Complete', 'goalcart') },
];

const DEVICES: Array<{ value: PreviewControlsValue['deviceWidth']; label: string }> = [
  { value: 'mobile', label: __('Mobile', 'goalcart') },
  { value: 'tablet', label: __('Tablet', 'goalcart') },
  { value: 'desktop', label: __('Desktop', 'goalcart') },
];

/**
 * Phase 15 Preview Controls: simulated cart state presets (empty cart /
 * 25% / 50% / 75% / completed), simulated cart amount and quantity,
 * simulated reward state, device width and template variant. Shared by
 * the goal and campaign preview dialogs.
 */
export default function PreviewControls({
  value,
  onPatch,
  onApplyPreset,
  templates,
}: PreviewControlsProps) {
  return (
    <Stack spacing={2.5}>
      {/* Preview states */}
      <Box>
        <Typography variant="body2" color="text.secondary" gutterBottom>
          {__('Preview state', 'goalcart')}
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
          aria-label={__('Preview state', 'goalcart')}
        >
          {PRESETS.map((preset) => (
            <ToggleButton key={preset.value} value={preset.value} sx={{ flex: 1 }}>
              {preset.label}
            </ToggleButton>
          ))}
        </ToggleButtonGroup>
      </Box>

      {/* Simulated cart amount */}
      <TextField
        label={__('Simulated cart amount', 'goalcart')}
        type="number"
        size="small"
        fullWidth
        value={Number.isFinite(value.amount) ? value.amount : 0}
        onChange={(event) => onPatch({ preset: 'custom', amount: Number(event.target.value) || 0 })}
        helperText={__('Drives money-based goals (subtotal / total).', 'goalcart')}
      />

      {/* Simulated quantity */}
      <TextField
        label={__('Simulated quantity', 'goalcart')}
        type="number"
        size="small"
        fullWidth
        value={Number.isFinite(value.quantity) ? value.quantity : 0}
        onChange={(event) =>
          onPatch({ preset: 'custom', quantity: Number(event.target.value) || 0 })
        }
        helperText={__('Drives quantity, distinct-quantity and weight goals.', 'goalcart')}
      />

      {/* Simulated reward */}
      <Box>
        <Typography variant="body2" color="text.secondary" gutterBottom>
          {__('Simulated reward', 'goalcart')}
        </Typography>
        <ToggleButtonGroup
          size="small"
          exclusive
          fullWidth
          value={value.rewardState}
          onChange={(_event, next) => {
            if (next) {
              onPatch({ rewardState: next as PreviewControlsValue['rewardState'] });
            }
          }}
          aria-label={__('Simulated reward', 'goalcart')}
        >
          <ToggleButton value="auto" sx={{ flex: 1 }}>
            {__('Auto', 'goalcart')}
          </ToggleButton>
          <ToggleButton value="locked" sx={{ flex: 1 }}>
            {__('Locked', 'goalcart')}
          </ToggleButton>
          <ToggleButton value="unlocked" sx={{ flex: 1 }}>
            {__('Unlocked', 'goalcart')}
          </ToggleButton>
        </ToggleButtonGroup>
      </Box>

      {/* Device width */}
      <Box>
        <Typography variant="body2" color="text.secondary" gutterBottom>
          {__('Device width', 'goalcart')}
        </Typography>
        <ToggleButtonGroup
          size="small"
          exclusive
          fullWidth
          value={value.deviceWidth}
          onChange={(_event, next) => {
            if (next) {
              onPatch({ deviceWidth: next as PreviewControlsValue['deviceWidth'] });
            }
          }}
          aria-label={__('Device width', 'goalcart')}
        >
          {DEVICES.map((device) => (
            <ToggleButton key={device.value} value={device.value} sx={{ flex: 1 }}>
              {device.label}
            </ToggleButton>
          ))}
        </ToggleButtonGroup>
      </Box>	      {/* Template override (pluggable template engine) */}
      <FormControl size="small" fullWidth>
        <InputLabel id="preview-template-label">{__('Template', 'goalcart')}</InputLabel>
        <Select
          labelId="preview-template-label"
          label={__('Template', 'goalcart')}
          value={value.template}
          onChange={(event) => onPatch({ template: String(event.target.value) })}
        >
          <MenuItem value="">
            <em>{__('Auto (goal/campaign)', 'goalcart')}</em>
          </MenuItem>
          {templates.map((template) => (
            <MenuItem key={template.id} value={template.id}>
              {template.label}
            </MenuItem>
          ))}
        </Select>
      </FormControl>
    </Stack>
  );
}
