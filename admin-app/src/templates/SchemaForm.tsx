import Box from '@mui/material/Box';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Slider from '@mui/material/Slider';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useMemo } from 'react';

import type { TemplateField, TemplateSettingsValue } from '../types';
import ColorField from './ColorField';

interface SchemaFormProps {
  schema: TemplateField[];
  value: TemplateSettingsValue;
  onChange: (next: TemplateSettingsValue) => void;
}

/**
 * The generic, schema-driven settings form (pluggable template engine).
 *
 * Field type → input component mapping, generated from the template's
 * schema served by `GET /goalcart/v1/templates`:
 *
 *   color   → hex picker + text field
 *   bool    → switch
 *   number  → slider (bounded ranges) or numeric text field
 *   select  → dropdown (options from the schema)
 *   css     → monospace multiline (server-sanitized on save)
 *   textarea→ multiline
 *   text    → text field
 *
 * A new template therefore automatically gets a working settings UI —
 * no per-template form code anywhere.
 */
export default function SchemaForm({ schema, value, onChange }: SchemaFormProps) {
  const groups = useMemo(() => {
    const map = new Map<string | null, TemplateField[]>();

    for (const field of schema) {
      const group = field.group || null;
      const bucket = map.get(group) ?? [];
      bucket.push(field);
      map.set(group, bucket);
    }

    return [...map.entries()];
  }, [schema]);

  const patch = (key: string, next: string | number | boolean) =>
    onChange({ ...value, [key]: next });

  return (
    <Stack spacing={2.5}>
      {groups.map(([group, fields]) => (
        <Box key={group ?? '__root'}>
          {group && (
            <Typography
              variant="overline"
              color="text.secondary"
              sx={{ display: 'block', mb: 1, letterSpacing: '0.08em' }}
            >
              {group}
            </Typography>
          )}
          <Stack spacing={2}>
            {fields.map((field) => (
              <SchemaField
                key={field.key}
                field={field}
                value={value[field.key] ?? field.default}
                onChange={(next) => patch(field.key, next)}
              />
            ))}
          </Stack>
        </Box>
      ))}
    </Stack>
  );
}

function SchemaField({
  field,
  value,
  onChange,
}: {
  field: TemplateField;
  value: string | number | boolean;
  onChange: (next: string | number | boolean) => void;
}) {
  const help = field.help;

  switch (field.type) {
    case 'color':
      return (
        <ColorField
          label={field.label}
          value={String(value)}
          onChange={onChange}
          helperText={help}
        />
      );

    case 'bool':
      return (
        <FormControlLabel
          control={
            <Switch checked={Boolean(value)} onChange={(event) => onChange(event.target.checked)} />
          }
          label={
            <Box>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {field.label}
              </Typography>
              {help && (
                <Typography variant="caption" color="text.secondary">
                  {help}
                </Typography>
              )}
            </Box>
          }
        />
      );

    case 'number': {
      const min = field.min ?? 0;
      const max = field.max ?? 100;
      const slidable = field.min !== undefined && field.max !== undefined && max - min <= 100;

      if (slidable) {
        return (
          <Box>
            <Typography variant="body2" color="text.secondary" gutterBottom>
              {field.label}
            </Typography>
            <Slider
              value={Number(value)}
              min={min}
              max={max}
              onChange={(_event, next) => onChange(Array.isArray(next) ? next[0] : next)}
              valueLabelDisplay="auto"
            />
          </Box>
        );
      }

      return (
        <TextField
          label={field.label}
          type="number"
          size="small"
          fullWidth
          value={Number(value)}
          helperText={help}
          slotProps={{ htmlInput: { min, max } }}
          onChange={(event) => onChange(Number(event.target.value) || 0)}
        />
      );
    }

    case 'select':
      return (
        <TextField
          select
          label={field.label}
          size="small"
          fullWidth
          value={String(value)}
          helperText={help}
          onChange={(event) => onChange(event.target.value)}
        >
          {Object.entries(field.options ?? {}).map(([optionValue, optionLabel]) => (
            <MenuItem key={optionValue} value={optionValue}>
              {optionLabel}
            </MenuItem>
          ))}
        </TextField>
      );

    case 'css':
      return (
        <TextField
          label={field.label}
          size="small"
          fullWidth
          multiline
          minRows={4}
          value={String(value)}
          helperText={help}
          sx={{ fontFamily: 'ui-monospace, monospace' }}
          slotProps={{ input: { sx: { fontFamily: 'ui-monospace, monospace' } } }}
          onChange={(event) => onChange(event.target.value)}
        />
      );

    case 'textarea':
      return (
        <TextField
          label={field.label}
          size="small"
          fullWidth
          multiline
          minRows={2}
          value={String(value)}
          helperText={help}
          onChange={(event) => onChange(event.target.value)}
        />
      );

    default:
      return (
        <TextField
          label={field.label}
          size="small"
          fullWidth
          value={String(value)}
          helperText={help}
          onChange={(event) => onChange(event.target.value)}
        />
      );
  }
}
