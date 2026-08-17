import AddIcon from '@mui/icons-material/Add';
import CloseIcon from '@mui/icons-material/Close';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Grid from '@mui/material/Grid';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import { searchCategories, searchProducts } from '../../api/search';
import type { MissionChildInput, MissionType } from '../../types';
import EntityAutocomplete from './EntityAutocomplete';
import { MISSION_TYPES } from './missionTypes';

interface CompositeChildrenEditorProps {
  children: MissionChildInput[];
  onChange: (children: MissionChildInput[]) => void;
}

const CHILD_MODE_OPTIONS: Array<{ value: string; label: string }> = [
  { value: 'quantity', label: __('Quantity', 'faracart') },
  { value: 'subtotal', label: __('Subtotal (before discounts)', 'faracart') },
  { value: 'discounted_subtotal', label: __('Discounted subtotal', 'faracart') },
  { value: 'total', label: __('Cart total (incl. tax & shipping)', 'faracart') },
];

/** A child can be any simple type; composite children are not nested here. */
const CHILD_TYPES = MISSION_TYPES.filter((type) => type.value !== 'composite');

function emptyChild(): MissionChildInput {
  return {
    type: 'amount',
    target: 0,
    calculation_mode: 'subtotal',
    categories: [],
    products: [],
  };
}

/**
 * Mission Builder → Target → Composite children (Phase 9). A composite mission
 * is an AND/OR combination of child missions; this editor maintains the
 * ordered list of child configs (Mission::from_array() payloads) the engine
 * evaluates through the same registry as top-level missions (Phase 4).
 */
export default function CompositeChildrenEditor({
  children,
  onChange,
}: CompositeChildrenEditorProps) {
  const updateChild = (index: number, patch: Partial<MissionChildInput>) => {
    onChange(children.map((child, i) => (i === index ? { ...child, ...patch } : child)));
  };

  const addChild = () => onChange([...children, emptyChild()]);
  const removeChild = (index: number) => onChange(children.filter((_child, i) => i !== index));

  return (
    <Box>
      {children.length === 0 && (
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
          {__(
            'No child missions yet. Add at least one condition to build the composite mission.',
            'faracart'
          )}
        </Typography>
      )}

      {children.map((child, index) => {
        const childType = child.type as MissionType;

        return (
          <Paper key={index} variant="outlined" sx={{ p: 2, mb: 1.5, bgcolor: 'action.hover' }}>
            <Grid container spacing={2} sx={{ alignItems: 'flex-start' }}>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <TextField
                  select
                  label={__('Child type', 'faracart')}
                  fullWidth
                  size="small"
                  value={childType}
                  onChange={(event) =>
                    updateChild(index, {
                      type: event.target.value as MissionType,
                      calculation_mode: event.target.value === 'product' ? 'quantity' : 'subtotal',
                    })
                  }
                >
                  {CHILD_TYPES.map((type) => (
                    <MenuItem key={type.value} value={type.value}>
                      {type.label}
                    </MenuItem>
                  ))}
                </TextField>
              </Grid>

              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <TextField
                  label={__('Target', 'faracart')}
                  type="number"
                  fullWidth
                  size="small"
                  value={child.target === 0 ? '' : child.target}
                  placeholder="0"
                  onChange={(event) =>
                    updateChild(index, { target: Number(event.target.value) || 0 })
                  }
                />
              </Grid>

              {(childType === 'amount' || childType === 'category' || childType === 'product') && (
                <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                  <TextField
                    select
                    label={__('Calculate against', 'faracart')}
                    fullWidth
                    size="small"
                    value={child.calculation_mode}
                    onChange={(event) =>
                      updateChild(index, { calculation_mode: event.target.value })
                    }
                  >
                    {CHILD_MODE_OPTIONS.map((option) => (
                      <MenuItem key={option.value} value={option.value}>
                        {option.label}
                      </MenuItem>
                    ))}
                  </TextField>
                </Grid>
              )}

              <Grid size={{ xs: 12, sm: 'auto' }}>
                <Tooltip title={__('Remove condition', 'faracart')}>
                  <span>
                    <IconButton
                      size="small"
                      color="error"
                      aria-label={__('Remove condition', 'faracart')}
                      onClick={() => removeChild(index)}
                    >
                      <CloseIcon fontSize="small" />
                    </IconButton>
                  </span>
                </Tooltip>
              </Grid>

              {childType === 'category' && (
                <Grid size={12}>
                  <EntityAutocomplete
                    label={__('Categories', 'faracart')}
                    value={child.categories}
                    onChange={(categories) => updateChild(index, { categories })}
                    search={searchCategories}
                  />
                </Grid>
              )}

              {childType === 'product' && (
                <Grid size={12}>
                  <EntityAutocomplete
                    label={__('Products', 'faracart')}
                    value={child.products}
                    onChange={(products) => updateChild(index, { products })}
                    search={searchProducts}
                  />
                </Grid>
              )}
            </Grid>
          </Paper>
        );
      })}

      <Button startIcon={<AddIcon />} onClick={addChild} size="small">
        {__('Add condition', 'faracart')}
      </Button>
    </Box>
  );
}
