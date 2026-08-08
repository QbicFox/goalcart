import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { __ } from '@wordpress/i18n';

import type { DisplaySettingsInput, GoalInput } from '../../types';

interface DisplayFieldsProps {
  values: GoalInput;
  onValueChange: (patch: Partial<GoalInput>) => void;
}

const TEMPLATE_OPTIONS = [
  { value: 'basic', label: __('Basic progress bar', 'goalcart') },
  { value: 'percentage', label: __('Percentage', 'goalcart') },
  { value: 'milestone', label: __('Milestones', 'goalcart') },
  { value: 'card', label: __('Card', 'goalcart') },
];

/**
 * Goal Builder → Display (Phase 9). Stores the customer-facing copy and
 * template choice in the `display_settings` JSON consumed by the Phase 11
 * frontend widgets and the Phase 13 message template engine. Placeholders
 * like {current}, {remaining} and {reward} are supported at render time.
 */
export default function DisplayFields({ values, onValueChange }: DisplayFieldsProps) {
  const display = (values.display_settings ?? {}) as DisplaySettingsInput;

  const patch = (data: Partial<GoalInput>) => onValueChange(data);
  const patchDisplay = (patchData: Partial<DisplaySettingsInput>) =>
    patch({ display_settings: { ...display, ...patchData } });

  return (
    <Grid container spacing={2} alignItems="flex-start">
      <Grid item xs={12} sm={6}>
        <TextField
          label={__('Title', 'goalcart')}
          fullWidth
          value={display.title ?? ''}
          placeholder={__('Free shipping unlocked', 'goalcart')}
          onChange={(event) => patchDisplay({ title: event.target.value })}
        />
      </Grid>

      <Grid item xs={12} sm={6}>
        <TextField
          select
          label={__('Template', 'goalcart')}
          fullWidth
          value={display.template ?? 'basic'}
          onChange={(event) => patchDisplay({ template: event.target.value })}
        >
          {TEMPLATE_OPTIONS.map((option) => (
            <MenuItem key={option.value} value={option.value}>
              {option.label}
            </MenuItem>
          ))}
        </TextField>
      </Grid>

      <Grid item xs={12}>
        <TextField
          label={__('Message (in progress)', 'goalcart')}
          fullWidth
          multiline
          minRows={2}
          value={display.message ?? ''}
          placeholder={__('Only {remaining} left to unlock {reward}!', 'goalcart')}
          helperText={__(
            'Supports {current}, {target}, {remaining}, {percentage}, {quantity}, {reward} and {goal_name}.',
            'goalcart'
          )}
          onChange={(event) => patchDisplay({ message: event.target.value })}
        />
      </Grid>

      <Grid item xs={12}>
        <TextField
          label={__('Completed message', 'goalcart')}
          fullWidth
          multiline
          minRows={2}
          value={display.completed_message ?? ''}
          placeholder={__('Goal reached — your reward is unlocked!', 'goalcart')}
          onChange={(event) => patchDisplay({ completed_message: event.target.value })}
        />
      </Grid>

      <Grid item xs={12} sm={6}>
        <TextField
          label={__('Icon', 'goalcart')}
          fullWidth
          value={display.icon ?? ''}
          placeholder={__('e.g. 🎁 or a dashicon name', 'goalcart')}
          helperText={__('Optional icon shown next to the progress bar.', 'goalcart')}
          onChange={(event) => patchDisplay({ icon: event.target.value })}
        />
      </Grid>
    </Grid>
  );
}
