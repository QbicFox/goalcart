import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import RestartAltIcon from '@mui/icons-material/RestartAlt';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import type { DisplaySettingsInput, GoalInput } from '../../types';
import SchemaForm from '../../templates/SchemaForm';
import { templateById, useTemplates } from '../../templates/useTemplates';

interface DisplayFieldsProps {
  values: GoalInput;
  onValueChange: (patch: Partial<GoalInput>) => void;
}

/**
 * Goal Builder → Display (Phase 9 + pluggable template engine).
 *
 * Stores the customer-facing copy and the goal's own template choice in
 * the `display_settings` JSON consumed by the storefront and the template
 * engine: `template_id` + `template_settings` (validated server-side
 * against the template's schema). Leaving the template on "Default"
 * falls back to the Goal scope default from Settings/Appearance; the
 * settings form is generated generically from the template's schema, so
 * a new template automatically gets a working per-goal settings UI.
 */
export default function DisplayFields({ values, onValueChange }: DisplayFieldsProps) {
  const display = (values.display_settings ?? {}) as DisplaySettingsInput;
  const { data: templates } = useTemplates();

  const patch = (data: Partial<GoalInput>) => onValueChange(data);
  const patchDisplay = (patchData: Partial<DisplaySettingsInput>) =>
    patch({ display_settings: { ...display, ...patchData } });

  const templateId = display.template_id ?? '';
  const definition = templateById(templates, 'goal', templateId);
  const templateSettings = display.template_settings ?? definition?.settings ?? {};

  const chooseTemplate = (next: string) => {
    if (!next) {
      // Reset to the scope default: drop the per-goal override entirely.
      const rest = { ...display };
      delete rest.template_id;
      delete rest.template_settings;
      patch({ display_settings: rest });
      return;
    }

    const nextDefinition = templateById(templates, 'goal', next);
    const defaults = nextDefinition?.settings ?? {};

    patchDisplay({
      template_id: next,
      template_settings: { ...defaults },
    });
  };

  return (
    <Grid container spacing={2} sx={{ alignItems: 'flex-start' }}>
      <Grid size={{ xs: 12, sm: 6 }}>
        <TextField
          label={__('Title', 'faracart')}
          fullWidth
          value={display.title ?? ''}
          placeholder={__('Free shipping unlocked', 'faracart')}
          onChange={(event) => patchDisplay({ title: event.target.value })}
        />
      </Grid>

      <Grid size={{ xs: 12, sm: 6 }}>
        <TextField
          select
          label={__('Template', 'faracart')}
          fullWidth
          value={templateId}
          onChange={(event) => chooseTemplate(event.target.value)}
        >
          <MenuItem value="">
            <em>{__('Default (goal templates)', 'faracart')}</em>
          </MenuItem>
          {(templates?.goal ?? []).map((template) => (
            <MenuItem key={template.id} value={template.id}>
              {template.label}
            </MenuItem>
          ))}
        </TextField>
      </Grid>

      {definition && (
        <Grid size={12}>
          <Box
            sx={{
              p: 2,
              border: '1px dashed',
              borderColor: 'divider',
              borderRadius: 2,
              bgcolor: 'background.default',
            }}
          >
            <Box
              sx={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 1,
                mb: 2,
              }}
            >
              <Box>
                <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
                  {definition.label}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {definition.description}
                </Typography>
              </Box>
              <Button
                size="small"
                startIcon={<RestartAltIcon />}
                onClick={() => chooseTemplate('')}
              >
                {__('Use global default', 'faracart')}
              </Button>
            </Box>
            <SchemaForm
              schema={definition.schema}
              value={templateSettings}
              onChange={(next) => patchDisplay({ template_settings: next })}
            />
          </Box>
        </Grid>
      )}

      <Grid size={12}>
        <TextField
          label={__('Message (in progress)', 'faracart')}
          fullWidth
          multiline
          minRows={2}
          value={display.message ?? ''}
          placeholder={__('Only {remaining} left to unlock {reward}!', 'faracart')}
          helperText={__(
            'Supports {current}, {target}, {remaining}, {percentage}, {quantity}, {reward} and {goal_name}.',
            'faracart'
          )}
          onChange={(event) => patchDisplay({ message: event.target.value })}
        />
      </Grid>

      <Grid size={12}>
        <TextField
          label={__('Completed message', 'faracart')}
          fullWidth
          multiline
          minRows={2}
          value={display.completed_message ?? ''}
          placeholder={__('Goal reached — your reward is unlocked!', 'faracart')}
          onChange={(event) => patchDisplay({ completed_message: event.target.value })}
        />
      </Grid>

      <Grid size={{ xs: 12, sm: 6 }}>
        <TextField
          label={__('Icon', 'faracart')}
          fullWidth
          value={display.icon ?? ''}
          placeholder={__('e.g. 🎁 or a dashicon name', 'faracart')}
          helperText={__('Optional icon shown next to the progress bar.', 'faracart')}
          onChange={(event) => patchDisplay({ icon: event.target.value })}
        />
      </Grid>
    </Grid>
  );
}
