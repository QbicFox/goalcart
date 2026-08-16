import { useEffect, useRef, useState } from 'react';
import Autocomplete from '@mui/material/Autocomplete';
import CircularProgress from '@mui/material/CircularProgress';
import TextField from '@mui/material/TextField';
import { __ } from '@wordpress/i18n';

import type { SearchParams } from '../../api/search';

export interface EntityOption {
  id: number;
  label: string;
  subtitle?: string;
}

interface EntityAutocompleteProps {
  /** Field label. */
  label: string;
  /** Selected entity ids (multi-select keeps an array, single keeps 0/1). */
  value: number[];
  /** Emits the new id selection. */
  onChange: (ids: number[]) => void;
  /** Server-side search: receives q + optional ids (preload). */
  search: (params: SearchParams) => Promise<Array<{ id: number; name: string }>>;
  multiple?: boolean;
  placeholder?: string;
  helperText?: string;
  disabled?: boolean;
}

/**
 * Debounced async picker backed by a FaraCart search endpoint
 * (`/search/products`, `/search/categories`, `/search/coupons`).
 *
 * - typing searches server-side (debounced, Phase 23: server-side search)
 * - passing `value` ids that are not loaded yet triggers an `ids`-scoped
 *   preload so saved selections render as labeled chips immediately
 * - `multiple` picks many ids, otherwise exactly one
 */
export default function EntityAutocomplete({
  label,
  value,
  onChange,
  search,
  multiple = true,
  placeholder,
  helperText,
  disabled,
}: EntityAutocompleteProps) {
  const [input, setInput] = useState('');
  const [options, setOptions] = useState<EntityOption[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [resolved, setResolved] = useState<Record<number, EntityOption>>({});
  const resolvedRef = useRef<Record<number, EntityOption>>({});
  const timer = useRef<number | undefined>(undefined);
  const requested = useRef(0);

  // Preload ids the user has already saved (e.g. when editing a goal) so
  // the chips show names, not bare ids. Runs once per new id set (the id
  // set is stable across renders, so `value` identity changes do not
  // re-trigger it).
  const valueKey = value.join(',');
  useEffect(() => {
    const missingIds = valueKey
      .split(',')
      .filter(Boolean)
      .map(Number)
      .filter((id) => !resolvedRef.current[id]);

    if (missingIds.length === 0) {
      return;
    }

    const requestId = ++requested.current;

    search({ ids: missingIds })
      .then((items) => {
        if (requestId !== requested.current) {
          return;
        }
        setResolved((prev) => {
          const next = { ...prev };
          resolvedRef.current = next;
          items.forEach((item) => {
            next[item.id] = { id: item.id, label: item.name };
          });
          resolvedRef.current = next;
          return next;
        });
      })
      .catch(() => undefined);
  }, [valueKey, search]);

  // Debounced server-side search while the dropdown is open.
  useEffect(() => {
    if (!open) {
      return;
    }

    const requestId = ++requested.current;
    window.clearTimeout(timer.current);

    // Loading is toggled inside the debounce callback (not synchronously
    // in the effect body) per react-hooks/set-state-in-effect.
    timer.current = window.setTimeout(() => {
      if (requestId !== requested.current) {
        return;
      }

      setLoading(true);
      search({ q: input, per_page: 20 })
        .then((items) => {
          if (requestId !== requested.current) {
            return;
          }
          setOptions(items.map((item) => ({ id: item.id, label: item.name })));
        })
        .catch(() => undefined)
        .finally(() => {
          if (requestId === requested.current) {
            setLoading(false);
          }
        });
    }, 300);

    return () => window.clearTimeout(timer.current);
  }, [input, open, search]);

  const selected = value.map((id) => resolved[id] ?? { id, label: `#${id}` }).filter(Boolean);

  return (
    <Autocomplete<EntityOption, boolean, false, false>
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
      disabled={disabled}
      filterOptions={(options) => options}
      isOptionEqualToValue={(option, value) => option.id === value.id}
      getOptionLabel={(option) => option.label}
      onInputChange={(_event, value) => setInput(value)}
      onChange={(_event, value) => {
        if (multiple) {
          onChange((value as EntityOption[]).map((option) => option.id));
        } else {
          onChange(value ? [(value as EntityOption).id] : []);
        }
      }}
      renderInput={(params) => (
        <TextField
          {...params}
          label={label}
          placeholder={
            placeholder ??
            (multiple ? __('Search and select…', 'goalcart') : __('Search…', 'goalcart'))
          }
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
      renderOption={(props, option) => (
        <li {...props} key={option.id}>
          {option.label}
        </li>
      )}
    />
  );
}
