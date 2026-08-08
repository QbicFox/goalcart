import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { __ } from '@wordpress/i18n';

import type { GoalInput, GoalType } from '../../types';
import { searchCategories, searchProducts } from '../../api/search';
import EntityAutocomplete from './EntityAutocomplete';

interface TargetFieldsProps {
  values: GoalInput;
  onValueChange: (patch: Partial<GoalInput>) => void;
}

/** Calculation bases offered per goal type (engine-validated enums). */
const MODE_OPTIONS: Record<string, Array<{ value: string; label: string }>> = {
  amount: [
    { value: 'subtotal', label: __('Subtotal (before discounts)', 'goalcart') },
    { value: 'discounted_subtotal', label: __('Discounted subtotal', 'goalcart') },
    { value: 'total', label: __('Cart total (incl. tax & shipping)', 'goalcart') },
  ],
  category: [
    { value: 'quantity', label: __('Quantity', 'goalcart') },
    { value: 'subtotal', label: __('Subtotal (before discounts)', 'goalcart') },
    { value: 'discounted_subtotal', label: __('Discounted subtotal', 'goalcart') },
    { value: 'total', label: __('Cart total (incl. tax & shipping)', 'goalcart') },
  ],
  product: [
    { value: 'quantity', label: __('Quantity', 'goalcart') },
    { value: 'subtotal', label: __('Subtotal (before discounts)', 'goalcart') },
    { value: 'discounted_subtotal', label: __('Discounted subtotal', 'goalcart') },
    { value: 'total', label: __('Cart total (incl. tax & shipping)', 'goalcart') },
  ],
};

/** The units a goal type measures, for the target field suffix. */
function targetSuffix(type: GoalType, mode: string): string {
  if (type === 'weight') {
    return __('(weight units)', 'goalcart');
  }
  if (type === 'quantity' || type === 'distinct_quantity' || mode === 'quantity') {
    return __('(items)', 'goalcart');
  }
  return __('(amount)', 'goalcart');
}

/**
 * Goal Builder → Target (Phase 9): dynamic target configuration for the
 * selected goal type. The fields change with the type so the admin never
 * sees irrelevant controls:
 *
 * - amount / quantity / distinct_quantity / weight: a single target
 * - category / product: target + calculation basis + a picker for the
 *   scoped categories/products
 * - composite: operator + children are edited in their own section
 */
export default function TargetFields({ values, onValueChange }: TargetFieldsProps) {
  const type = values.type;

  const modeOptions = MODE_OPTIONS[type] ?? [];

  const patch = (data: Partial<GoalInput>) => onValueChange(data);

  if (type === 'composite') {
    return null; // Operator + children live in the composite section.
  }

  const mode = values.calculation_mode;

  return (
    <Grid container spacing={2} alignItems="flex-start">
      <Grid item xs={12} sm={6} lg={4}>
        <TextField
          label={__('Target', 'goalcart')}
          type="number"
          fullWidth
          value={values.target === 0 ? '' : values.target}
          placeholder="0"
          helperText={__('The threshold shoppers need to reach', 'goalcart')}
          onChange={(event) => patch({ target: Number(event.target.value) || 0 })}
        />
      </Grid>

      {modeOptions.length > 0 && (
        <Grid item xs={12} sm={6} lg={4}>
          <TextField
            select
            label={__('Calculate against', 'goalcart')}
            fullWidth
            value={mode}
            onChange={(event) => patch({ calculation_mode: event.target.value })}
            helperText={targetSuffix(type, mode)}
          >
            {modeOptions.map((option) => (
              <MenuItem key={option.value} value={option.value}>
                {option.label}
              </MenuItem>
            ))}
          </TextField>
        </Grid>
      )}

      {type === 'category' && (
        <Grid item xs={12}>
          <EntityAutocomplete
            label={__('Categories', 'goalcart')}
            value={values.categories}
            onChange={(categories) => patch({ categories })}
            search={searchCategories}
            helperText={__(
              'Only products in these categories count toward the target.',
              'goalcart'
            )}
          />
        </Grid>
      )}

      {type === 'product' && (
        <Grid item xs={12}>
          <EntityAutocomplete
            label={__('Products', 'goalcart')}
            value={values.products}
            onChange={(products) => patch({ products })}
            search={searchProducts}
            helperText={__(
              'Only these products count toward the target. Variations are matched by their parent product.',
              'goalcart'
            )}
          />
        </Grid>
      )}
    </Grid>
  );
}
