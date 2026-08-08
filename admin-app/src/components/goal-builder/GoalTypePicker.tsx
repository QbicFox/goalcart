import Box from '@mui/material/Box';
import ButtonBase from '@mui/material/ButtonBase';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';

import type { GoalType } from '../../types';
import { GOAL_TYPES } from './goalTypes';

interface GoalTypePickerProps {
  value: GoalType;
  onChange: (type: GoalType) => void;
}

/**
 * Goal type selector (Phase 9: Goal Builder → Goal Type). Renders one
 * tappable card per engine-supported type so the choice is visual.
 */
export default function GoalTypePicker({ value, onChange }: GoalTypePickerProps) {
  return (
    <Box
      sx={{
        display: 'grid',
        gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(4, 1fr)' },
        gap: 1.5,
      }}
    >
      {GOAL_TYPES.map((type) => {
        const selected = type.value === value;

        return (
          <ButtonBase
            key={type.value}
            component={Paper}
            onClick={() => onChange(type.value)}
            aria-pressed={selected}
            sx={{
              p: 2,
              textAlign: 'start',
              cursor: 'pointer',
              border: '2px solid',
              borderColor: selected ? 'primary.main' : 'divider',
              bgcolor: selected ? 'action.selected' : 'background.paper',
              transition: 'border-color 150ms ease, box-shadow 150ms ease',
              '&:hover': {
                borderColor: selected ? 'primary.dark' : 'text.disabled',
                boxShadow: 1,
              },
              '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main' },
            }}
          >
            <Stack spacing={1}>
              <Box sx={{ color: selected ? 'primary.main' : 'text.secondary' }}>{type.icon}</Box>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {type.label}
              </Typography>
              <Typography variant="caption" color="text.secondary" component="div">
                {type.description}
              </Typography>
            </Stack>
          </ButtonBase>
        );
      })}
    </Box>
  );
}
