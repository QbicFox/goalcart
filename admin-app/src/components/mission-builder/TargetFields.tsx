import { useEffect, useState } from 'react';
import Autocomplete from '@mui/material/Autocomplete';
import CircularProgress from '@mui/material/CircularProgress';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import { searchAttributes, searchCategories, searchProducts, searchTags } from '../../api/search';
import type { MissionInput, MissionType, SearchAttribute } from '../../types';
import EntityAutocomplete from './EntityAutocomplete';

interface TargetFieldsProps {
  values: MissionInput;
  onValueChange: (patch: Partial<MissionInput>) => void;
}

/** Calculation bases offered per mission type (engine-validated enums). */
const MODE_OPTIONS: Record<string, Array<{ value: string; label: string }>> = {
  amount: [
    { value: 'subtotal', label: __('Subtotal (before discounts)', 'faracart') },
    { value: 'discounted_subtotal', label: __('Discounted subtotal', 'faracart') },
    { value: 'total', label: __('Cart total (incl. tax & shipping)', 'faracart') },
  ],
  category: [
    { value: 'quantity', label: __('Quantity', 'faracart') },
    { value: 'subtotal', label: __('Subtotal (before discounts)', 'faracart') },
    { value: 'discounted_subtotal', label: __('Discounted subtotal', 'faracart') },
    { value: 'total', label: __('Cart total (incl. tax & shipping)', 'faracart') },
  ],
  product: [
    { value: 'quantity', label: __('Quantity', 'faracart') },
    { value: 'subtotal', label: __('Subtotal (before discounts)', 'faracart') },
    { value: 'discounted_subtotal', label: __('Discounted subtotal', 'faracart') },
    { value: 'total', label: __('Cart total (incl. tax & shipping)', 'faracart') },
  ],
  // tag/attribute conditions: the category family.
  tag: [
    { value: 'quantity', label: __('Quantity', 'faracart') },
    { value: 'subtotal', label: __('Subtotal (before discounts)', 'faracart') },
    { value: 'discounted_subtotal', label: __('Discounted subtotal', 'faracart') },
    { value: 'total', label: __('Cart total (incl. tax & shipping)', 'faracart') },
  ],
  attribute: [
    { value: 'quantity', label: __('Quantity', 'faracart') },
    { value: 'subtotal', label: __('Subtotal (before discounts)', 'faracart') },
    { value: 'discounted_subtotal', label: __('Discounted subtotal', 'faracart') },
    { value: 'total', label: __('Cart total (incl. tax & shipping)', 'faracart') },
  ],
};

/** The units a mission type measures, for the target field suffix. */
function targetSuffix(type: MissionType, mode: string): string {
  if (type === 'weight') {
    return __('(weight units)', 'faracart');
  }
  if (type === 'quantity' || type === 'distinct_quantity' || mode === 'quantity') {
    return __('(items)', 'faracart');
  }
  return __('(amount)', 'faracart');
}

/**
 * A concrete, plain-Persian example under the target field (§9) — a small
 * mental model of "how far along is the cart?", adapted to the target's
 * unit (money, items or weight).
 */
function targetExample(type: MissionType, mode: string): string {
  if (type === 'weight') {
    return __(
      'Example: if the target is 10 kg, a cart weighing 7 kg is 70% of the way there.',
      'faracart'
    );
  }
  if (type === 'quantity' || type === 'distinct_quantity' || mode === 'quantity') {
    return __(
      'Example: if the target is 10 items, a cart with 7 items is 70% of the way there.',
      'faracart'
    );
  }
  return __(
    'Example: if the target is 2,000,000, a cart of 1,500,000 is 75% of the way there.',
    'faracart'
  );
}

/**
 * Mission Builder → Target: dynamic target configuration for the
 * selected mission type. The fields change with the type so the admin never
 * sees irrelevant controls:
 *
 * - amount / quantity / distinct_quantity / weight: a single target
 * - category / product / tag / attribute: target + calculation
 *   basis + a picker for the scoped entities (adds tags and
 *   attribute taxonomies)
 * - composite: operator + children are edited in their own section
 */
export default function TargetFields({ values, onValueChange }: TargetFieldsProps) {
  const type = values.type;

  const modeOptions = MODE_OPTIONS[type] ?? [];

  const patch = (data: Partial<MissionInput>) => onValueChange(data);

  if (type === 'composite') {
    return null; // Operator + children live in the composite section.
  }

  const mode = values.calculation_mode;

  return (
    <Grid container spacing={2} sx={{ alignItems: 'flex-start' }}>
      <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
        <TextField
          label={__('Target', 'faracart')}
          type="number"
          fullWidth
          value={values.target === 0 ? '' : values.target}
          placeholder="0"
          helperText={__('The threshold shoppers need to reach', 'faracart')}
          onChange={(event) => patch({ target: Number(event.target.value) || 0 })}
        />
      </Grid>

      {modeOptions.length > 0 && (
        <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
          <TextField
            select
            label={__('Calculate against', 'faracart')}
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
        <Grid size={12}>
          <EntityAutocomplete
            label={__('Categories', 'faracart')}
            value={values.categories}
            onChange={(categories) => patch({ categories })}
            search={searchCategories}
            helperText={__(
              'Only products in these categories count toward the target.',
              'faracart'
            )}
          />
        </Grid>
      )}

      {type === 'product' && (
        <Grid size={12}>
          <EntityAutocomplete
            label={__('Products', 'faracart')}
            value={values.products}
            onChange={(products) => patch({ products })}
            search={searchProducts}
            helperText={__(
              'Only these products count toward the target. Variations are matched by their parent product.',
              'faracart'
            )}
          />
        </Grid>
      )}

      {type === 'tag' && (
        <Grid size={12}>
          <EntityAutocomplete
            label={__('Tags', 'faracart')}
            value={values.tags}
            onChange={(tags) => patch({ tags })}
            search={searchTags}
            helperText={__(
              'Only products carrying any of these tags count toward the target.',
              'faracart'
            )}
          />
        </Grid>
      )}

      {type === 'attribute' && (
        <Grid size={12}>
          <TaxonomyAutocomplete
            label={__('Attributes', 'faracart')}
            value={values.attributes}
            multiple
            onChange={(attributes) => patch({ attributes })}
            helperText={__(
              'Products carrying any of these attribute taxonomies (e.g. pa_color) count toward the target.',
              'faracart'
            )}
          />
        </Grid>
      )}

      <Grid size={12}>
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
          {targetExample(type, mode)}
        </Typography>
      </Grid>
    </Grid>
  );
}

interface TaxonomyAutocompleteProps {
  label: string;
  value: string[];
  multiple?: boolean;
  onChange: (value: string[]) => void;
  helperText?: string;
}

/**
 * A debounced picker for global attribute taxonomies. Works on
 * taxonomy slugs (strings), backed by `/search/attributes`.
 */
function TaxonomyAutocomplete({
  label,
  value,
  multiple = true,
  onChange,
  helperText,
}: TaxonomyAutocompleteProps) {
  const [input, setInput] = useState('');
  const [options, setOptions] = useState<SearchAttribute[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!open) {
      return;
    }

    let alive = true;

    // Loading is toggled inside the debounce callback (not synchronously
    // in the effect body) per react-hooks/set-state-in-effect.
    const timer = window.setTimeout(() => {
      setLoading(true);

      searchAttributes({ q: input, per_page: 50 })
        .then((items) => {
          if (alive) {
            setOptions(items);
          }
        })
        .catch(() => undefined)
        .finally(() => {
          if (alive) {
            setLoading(false);
          }
        });
    }, 300);

    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [input, open]);

  // Keep saved taxonomies visible as chips even when the attribute was
  // deleted (label falls back to the raw slug).
  const selected = value.map((slug) => ({
    taxonomy: slug,
    name: options.find((option) => option.taxonomy === slug)?.name ?? slug,
  }));

  return (
    <Autocomplete<{ taxonomy: string; name: string }, boolean, false, false>
      multiple={multiple}
      open={open}
      onOpen={() => setOpen(true)}
      onClose={() => {
        setOpen(false);
        setInput('');
      }}
      value={multiple ? selected : (selected[0] ?? null)}
      options={options}
      loading={loading}
      filterOptions={(options) => options}
      isOptionEqualToValue={(option, value) => option.taxonomy === value.taxonomy}
      getOptionLabel={(option) => option.name}
      onInputChange={(_event, value) => setInput(value)}
      onChange={(_event, value) => {
        if (multiple) {
          onChange((value as Array<{ taxonomy: string }>).map((option) => option.taxonomy));
        } else {
          onChange(value ? [(value as { taxonomy: string }).taxonomy] : []);
        }
      }}
      renderInput={(params) => (
        <TextField
          {...params}
          label={label}
          placeholder={__('Search attributes…', 'faracart')}
          helperText={helperText}
          slotProps={{
            ...params.slotProps,
            input: {
              ...params.slotProps.input,
              endAdornment: (
                <>
                  {loading ? <CircularProgress size={18} /> : null}
                  {params.slotProps.input.endAdornment}
                </>
              ),
            },
          }}
        />
      )}
    />
  );
}
