import Box from '@mui/material/Box';
import Grid from '@mui/material/Grid';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import { searchProducts } from '../../api/search';
import type { GoalInput } from '../../types';
import EntityAutocomplete from './EntityAutocomplete';

interface ConditionFieldsProps {
  values: GoalInput;
  onValueChange: (patch: Partial<GoalInput>) => void;
}

/**
 * Goal Builder → Conditions (Phase 9). The goal conditions supported by
 * the Phase 3/4 data model: excluded products (applies to every goal
 * type) and the schedule window. Role/customer-state/cart-state
 * conditions are roadmap deferrals (Phase 32) — they need schema fields
 * the Goal model does not have yet.
 */
export default function ConditionFields({ values, onValueChange }: ConditionFieldsProps) {
  const patch = (data: Partial<GoalInput>) => onValueChange(data);

  return (
    <Grid container spacing={2} alignItems="flex-start">
      <Grid item xs={12}>
        <EntityAutocomplete
          label={__('Excluded products', 'goalcart')}
          value={values.excluded_products}
          onChange={(excluded_products) => patch({ excluded_products })}
          search={searchProducts}
          helperText={__(
            'These products never count toward the goal, even when they would otherwise qualify.',
            'goalcart'
          )}
        />
      </Grid>

      <Grid item xs={12}>
        <Typography variant="subtitle2" gutterBottom>
          {__('Schedule', 'goalcart')}
        </Typography>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { sm: 'repeat(2, 1fr)' } }}>
          <TextFieldDate
            label={__('Starts at', 'goalcart')}
            value={values.starts_at}
            onChange={(starts_at) => patch({ starts_at })}
          />
          <TextFieldDate
            label={__('Ends at', 'goalcart')}
            value={values.ends_at}
            onChange={(ends_at) => patch({ ends_at })}
          />
        </Box>
        <Typography variant="caption" color="text.secondary">
          {__(
            'Leave both empty to run the goal at all times. Local site time is used.',
            'goalcart'
          )}
        </Typography>
      </Grid>
    </Grid>
  );
}

interface TextFieldDateProps {
  label: string;
  value: string | null;
  onChange: (value: string | null) => void;
}

/** datetime-local input mapping to/from the API's 'Y-m-d H:i:s'. */
function TextFieldDate({ label, value, onChange }: TextFieldDateProps) {
  // datetime-local expects '2026-08-07T14:30'; the API stores
  // '2026-08-07 14:30:00'.
  const inputValue = value ? value.replace(' ', 'T').slice(0, 16) : '';

  return (
    <TextField
      label={label}
      type="datetime-local"
      fullWidth
      size="small"
      value={inputValue}
      onChange={(event) => {
        const raw = event.target.value;

        if ('' === raw) {
          onChange(null);
          return;
        }

        // '2026-08-07T14:30' → '2026-08-07 14:30:00'.
        onChange(raw.replace('T', ' ') + ':00');
      }}
      slotProps={{ inputLabel: { shrink: true } }}
    />
  );
}
