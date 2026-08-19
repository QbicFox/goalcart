import { useEffect, useState } from 'react';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import { fetchSettingsEnvelope } from '../../api/settings';
import { searchCoupons, searchProducts, searchTags, searchZones } from '../../api/search';
import WheelDateTimeField from '../wheel-picker/WheelDateTimeField';
import WheelTimeField from '../wheel-picker/WheelTimeField';
import type { MissionInput } from '../../types';
import EntityAutocomplete from './EntityAutocomplete';

interface ConditionFieldsProps {
  values: MissionInput;
  onValueChange: (patch: Partial<MissionInput>) => void;
}

const WEEKDAYS = [
  { value: 1, label: __('Mon', 'faracart') },
  { value: 2, label: __('Tue', 'faracart') },
  { value: 3, label: __('Wed', 'faracart') },
  { value: 4, label: __('Thu', 'faracart') },
  { value: 5, label: __('Fri', 'faracart') },
  { value: 6, label: __('Sat', 'faracart') },
  { value: 7, label: __('Sun', 'faracart') },
];

/** Coupon pick option (code-based). */
interface CouponPick {
  code: string;
}

/**
 * Mission Builder → Conditions. The mission conditions
 * surface:
 *
 *  - excluded products (applies to every mission type)
 *  - schedule window + recurring day/time rules
 *  - customer conditions (roles, guest/logged-in state, first-order,
 *    VIP thresholds) — *  - shipping-zone conditions — *  - cart-state conditions (required coupons, minimum items) — *  - product tag conditions  — a tag-scoped mission also counts
 *    only products carrying the configured tags
 */
export default function ConditionFields({ values, onValueChange }: ConditionFieldsProps) {
  const patch = (data: Partial<MissionInput>) => onValueChange(data);
  const [roles, setRoles] = useState<Array<{ slug: string; name: string }>>([]);

  // The editable role list rides on the settings GET meta.
  useEffect(() => {
    let alive = true;

    fetchSettingsEnvelope()
      .then((envelope) => {
        const roles = envelope.meta?.roles;

        if (!alive || !roles || typeof roles !== 'object') {
          return;
        }

        setRoles(
          Object.entries(roles as Record<string, string>).map(([slug, name]) => ({
            slug,
            name,
          }))
        );
      })
      .catch(() => undefined);

    return () => {
      alive = false;
    };
  }, []);

  const selectedRoles = values.customer_roles
    .map((slug) => roles.find((role) => role.slug === slug) ?? { slug, name: slug })
    .filter(Boolean);

  const toggleDay = (day: number) => {
    const days = values.schedule_days ?? [];
    patch({
      schedule_days: days.includes(day) ? days.filter((d) => d !== day) : [...days, day],
    });
  };

  return (
    <Stack spacing={3}>
      {/* Excluded products (existing) */}
      <Grid container spacing={2}>
        <Grid size={12}>
          <EntityAutocomplete
            label={__('Excluded products', 'faracart')}
            value={values.excluded_products}
            onChange={(excluded_products) => patch({ excluded_products })}
            search={searchProducts}
            helperText={__(
              'These products never count toward the mission, even when they would otherwise qualify.',
              'faracart'
            )}
          />
        </Grid>
      </Grid>

      {/* Customer conditions */}
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          {__('Customers', 'faracart')}
        </Typography>
        <Stack spacing={2}>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}>
              <Autocomplete<{ slug: string; name: string }, true, false, false>
                multiple
                options={roles}
                value={selectedRoles}
                getOptionLabel={(option) => option.name}
                isOptionEqualToValue={(option, value) => option.slug === value.slug}
                onChange={(_event, value) =>
                  patch({ customer_roles: value.map((role) => role.slug) })
                }
                renderInput={(params) => (
                  <TextField
                    {...params}
                    label={__('Allowed customer roles', 'faracart')}
                    helperText={__(
                      'Leave empty to allow every role. Guests never match a role restriction.',
                      'faracart'
                    )}
                  />
                )}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <Box>
                <FormControlLabel
                  control={
                    <Checkbox
                      checked={(values.customer_state ?? []).includes('guest')}
                      onChange={(event) => {
                        const current = values.customer_state ?? [];
                        patch({
                          customer_state: event.target.checked
                            ? [...current, 'guest']
                            : current.filter((state) => state !== 'guest'),
                        });
                      }}
                    />
                  }
                  label={__('Guests (not logged in)', 'faracart')}
                />
                <FormControlLabel
                  control={
                    <Checkbox
                      checked={(values.customer_state ?? []).includes('logged_in')}
                      onChange={(event) => {
                        const current = values.customer_state ?? [];
                        patch({
                          customer_state: event.target.checked
                            ? [...current, 'logged_in']
                            : current.filter((state) => state !== 'logged_in'),
                        });
                      }}
                    />
                  }
                  label={__('Logged-in customers', 'faracart')}
                />
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                  {__('Leave both unchecked to allow everyone.', 'faracart')}
                </Typography>
              </Box>
            </Grid>
          </Grid>

          <FormControlLabel
            control={
              <Switch
                checked={Boolean(values.first_order)}
                onChange={(event) => patch({ first_order: event.target.checked })}
              />
            }
            label={
              <Box>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {__('First order only', 'faracart')}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {__(
                    'Only shoppers with no completed orders see this mission. Guests always qualify.',
                    'faracart'
                  )}
                </Typography>
              </Box>
            }
          />

          <FormControlLabel
            control={
              <Switch
                checked={Boolean(values.vip)}
                onChange={(event) => patch({ vip: event.target.checked })}
              />
            }
            label={
              <Box>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {__('VIP customers only', 'faracart')}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {__(
                    'Logged-in customers whose lifetime spend and order count meet the thresholds below.',
                    'faracart'
                  )}
                </Typography>
              </Box>
            }
          />

          {values.vip && (
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                <TextField
                  label={__('Minimum lifetime spend', 'faracart')}
                  type="number"
                  fullWidth
                  value={values.vip_min_spend === 0 ? '' : values.vip_min_spend}
                  placeholder="0"
                  helperText={__('Total paid across all orders.', 'faracart')}
                  onChange={(event) => patch({ vip_min_spend: Number(event.target.value) || 0 })}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                <TextField
                  label={__('Minimum completed orders', 'faracart')}
                  type="number"
                  fullWidth
                  value={values.vip_min_orders === 0 ? '' : values.vip_min_orders}
                  placeholder="0"
                  helperText={__('Processing + completed orders.', 'faracart')}
                  onChange={(event) => patch({ vip_min_orders: Number(event.target.value) || 0 })}
                />
              </Grid>
            </Grid>
          )}
        </Stack>
      </Box>

      {/* Shipping zones */}
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          {__('Shipping destination', 'faracart')}
        </Typography>
        <EntityAutocomplete
          label={__('Allowed shipping zones', 'faracart')}
          value={values.shipping_zones}
          onChange={(shipping_zones) => patch({ shipping_zones })}
          search={searchZones}
          helperText={__(
            'The mission only applies when the cart ships to one of these zones. Leave empty for every zone.',
            'faracart'
          )}
        />
      </Box>

      {/* Cart state */}
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          {__('Cart state', 'faracart')}
        </Typography>
        <Stack spacing={2}>
          <TextField
            label={__('Minimum items in cart', 'faracart')}
            type="number"
            fullWidth
            sx={{ maxWidth: 260 }}
            value={values.cart_min_items === 0 ? '' : values.cart_min_items}
            placeholder="0"
            helperText={__('The cart must contain at least this many items.', 'faracart')}
            onChange={(event) => patch({ cart_min_items: Number(event.target.value) || 0 })}
          />
          <CouponPicker
            value={values.cart_coupons}
            onChange={(cart_coupons) => patch({ cart_coupons })}
          />
        </Stack>
      </Box>

      {/* Product tags condition */}
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          {__('Product tags', 'faracart')}
        </Typography>
        <EntityAutocomplete
          label={__('Required product tags', 'faracart')}
          value={values.tags}
          onChange={(tags) => patch({ tags })}
          search={searchTags}
          helperText={__(
            'Only products carrying any of these tags count toward the mission. Leave empty to count every product.',
            'faracart'
          )}
        />
      </Box>

      {/* Schedule */}
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          {__('Schedule', 'faracart')}
        </Typography>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 6 }}>
            <WheelDateTimeField
              label={__('Starts at', 'faracart')}
              value={values.starts_at}
              onChange={(starts_at) => patch({ starts_at })}
            />
          </Grid>
          <Grid size={{ xs: 12, sm: 6 }}>
            <WheelDateTimeField
              label={__('Ends at', 'faracart')}
              value={values.ends_at}
              onChange={(ends_at) => patch({ ends_at })}
            />
          </Grid>
        </Grid>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
          {__(
            'Leave both empty to run the mission at all times. Local site time is used.',
            'faracart'
          )}
        </Typography>

        {/* recurring day/time window */}
        <Stack spacing={1.5} sx={{ mt: 2 }}>
          <Box>
            <Typography variant="caption" color="text.secondary" component="div" gutterBottom>
              {__('Repeat on days (optional)', 'faracart')}
            </Typography>
            <Stack direction="row" spacing={0.75} useFlexGap sx={{ flexWrap: 'wrap' }}>
              {WEEKDAYS.map((day) => {
                const selected = (values.schedule_days ?? []).includes(day.value);
                return (
                  <Chip
                    key={day.value}
                    label={day.label}
                    size="small"
                    color={selected ? 'primary' : 'default'}
                    variant={selected ? 'filled' : 'outlined'}
                    onClick={() => toggleDay(day.value)}
                  />
                );
              })}
            </Stack>
          </Box>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}>
              <WheelTimeField
                label={__('Day window starts', 'faracart')}
                value={values.schedule_start_time}
                onChange={(next) => patch({ schedule_start_time: next })}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <WheelTimeField
                label={__('Day window ends', 'faracart')}
                value={values.schedule_end_time}
                onChange={(next) => patch({ schedule_end_time: next })}
              />
            </Grid>
          </Grid>
          <Typography variant="caption" color="text.secondary">
            {__(
              'A window that crosses midnight (e.g. 22:00–06:00) counts the hours after start and before end.',
              'faracart'
            )}
          </Typography>
        </Stack>
      </Box>
    </Stack>
  );
}

interface CouponPickerProps {
  value: string[];
  onChange: (codes: string[]) => void;
}

/** Free-solo coupon-code picker backed by `/search/coupons`. */
function CouponPicker({ value, onChange }: CouponPickerProps) {
  const [input, setInput] = useState('');
  const [options, setOptions] = useState<CouponPick[]>([]);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      searchCoupons({ q: input, per_page: 20 })
        .then((items) => setOptions(items.map((item) => ({ code: item.code }))))
        .catch(() => undefined);
    }, 300);

    return () => window.clearTimeout(timer);
  }, [input]);

  const selected = value.map((code) => ({ code }));

  return (
    <Autocomplete<CouponPick, true, false, true>
      multiple
      freeSolo
      options={options}
      value={selected}
      getOptionLabel={(option) => (typeof option === 'string' ? option : option.code)}
      isOptionEqualToValue={(option, value) =>
        typeof value === 'object' && option.code === value.code
      }
      onInputChange={(_event, next) => setInput(next)}
      onChange={(_event, next) =>
        onChange(next.map((option) => (typeof option === 'string' ? option : option.code)))
      }
      renderInput={(params) => (
        <TextField
          {...params}
          label={__('Required coupons in cart', 'faracart')}
          helperText={__(
            'At least one of these coupon codes must be applied for the mission to count.',
            'faracart'
          )}
        />
      )}
    />
  );
}

