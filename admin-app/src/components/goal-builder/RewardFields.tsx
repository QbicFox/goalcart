import { useEffect, useMemo, useState } from 'react';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import { searchCategories, searchCoupons, searchProducts } from '../../api/search';
import type { GoalInput, RewardMetaInput, RewardType, SearchCoupon } from '../../types';
import EntityAutocomplete from './EntityAutocomplete';

interface RewardFieldsProps {
  values: GoalInput;
  onValueChange: (patch: Partial<GoalInput>) => void;
}

const REWARD_OPTIONS: Array<{ value: RewardType; label: string }> = [
  { value: null, label: __('No reward', 'faracart') },
  { value: 'free_shipping', label: __('Free shipping', 'faracart') },
  { value: 'percent_discount', label: __('Percentage discount', 'faracart') },
  { value: 'fixed_discount', label: __('Fixed discount', 'faracart') },
  { value: 'free_gift', label: __('Free gift', 'faracart') },
  { value: 'coupon', label: __('Coupon', 'faracart') },
];

const STACKING_OPTIONS = [
  { value: 'none', label: __('No (exclusive)', 'faracart') },
  { value: 'stack', label: __('Yes (stack with other rewards)', 'faracart') },
];

const GIFT_MODE_OPTIONS = [
  { value: 'automatic', label: __('Add automatically', 'faracart') },
  { value: 'choose', label: __('Customer picks from a list', 'faracart') },
];

const COUPON_TYPE_OPTIONS = [
  { value: 'percent', label: __('Percentage off', 'faracart') },
  { value: 'fixed_cart', label: __('Fixed amount off', 'faracart') },
];

/** Reward-specific shape used by the coupon picker (code-based). */
interface CouponPick extends SearchCoupon {
  id: number;
}

/**
 * Goal Builder → Reward (Phase 9): dynamic reward configuration. The
 * fields shown depend on the reward type (free shipping, percentage/fixed
 * discount, free gift, coupon), and the configuration is flattened into
 * the goal's reward columns + `reward_meta` JSON exactly as the Reward
 * model reads it (Phase 5).
 */
export default function RewardFields({ values, onValueChange }: RewardFieldsProps) {
  const type = values.reward_type ?? null;
  const meta = values.reward_meta ?? {};
  const stacking = meta.stacking ?? 'none';

  const patch = (data: Partial<GoalInput>) => onValueChange(data);
  const patchMeta = (metaPatch: Partial<RewardMetaInput>) =>
    patch({ reward_meta: { ...meta, ...metaPatch } });

  /** Switching reward types resets the type-specific config. */
  const changeType = (next: RewardType) => {
    const patchData: Partial<GoalInput> = {
      reward_type: next,
      reward_value: null,
      reward_max_value: null,
      reward_meta: {
        label: '',
        stacking: 'none',
      },
    };

    if (next === 'coupon') {
      patchData.reward_meta = {
        label: '',
        stacking: 'none',
        coupon_generate: false,
        coupon_discount_type: 'percent',
      };
    }

    if (next === 'free_gift') {
      patchData.reward_meta = {
        label: '',
        stacking: 'none',
        gift_add_mode: 'automatic',
      };
    }

    patch(patchData);
  };

  return (
    <Grid container spacing={2} sx={{ alignItems: 'flex-start' }}>
      <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
        <TextField
          select
          label={__('Reward type', 'faracart')}
          fullWidth
          value={type ?? ''}
          onChange={(event) => changeType((event.target.value || null) as RewardType)}
        >
          {REWARD_OPTIONS.map((option) => (
            <MenuItem key={option.value ?? 'none'} value={option.value ?? ''}>
              {option.label}
            </MenuItem>
          ))}
        </TextField>
      </Grid>

      {type && (
        <>
          {(type === 'percent_discount' || type === 'fixed_discount') && (
            <>
              <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                <TextField
                  label={
                    type === 'percent_discount'
                      ? __('Discount percentage', 'faracart')
                      : __('Discount amount', 'faracart')
                  }
                  type="number"
                  fullWidth
                  value={values.reward_value ?? ''}
                  placeholder={type === 'percent_discount' ? '10' : '100000'}
                  helperText={
                    type === 'percent_discount'
                      ? __('% off the eligible cart value', 'faracart')
                      : __('Fixed amount off', 'faracart')
                  }
                  onChange={(event) => patch({ reward_value: Number(event.target.value) || null })}
                />
              </Grid>

              {type === 'percent_discount' && (
                <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                  <TextField
                    label={__('Maximum discount', 'faracart')}
                    type="number"
                    fullWidth
                    value={values.reward_max_value ?? ''}
                    placeholder={__('Optional cap', 'faracart')}
                    helperText={__('Cap the discount at this amount.', 'faracart')}
                    onChange={(event) =>
                      patch({ reward_max_value: Number(event.target.value) || null })
                    }
                  />
                </Grid>
              )}

              <Grid size={12}>
                <Box
                  sx={{ display: 'grid', gap: 2, gridTemplateColumns: { sm: 'repeat(2, 1fr)' } }}
                >
                  <EntityAutocomplete
                    label={__('Eligible products (optional)', 'faracart')}
                    value={meta.eligible_products ?? []}
                    onChange={(eligible_products) => patchMeta({ eligible_products })}
                    search={searchProducts}
                    helperText={__('Leave empty to apply to the whole cart.', 'faracart')}
                  />
                  <EntityAutocomplete
                    label={__('Eligible categories (optional)', 'faracart')}
                    value={meta.eligible_categories ?? []}
                    onChange={(eligible_categories) => patchMeta({ eligible_categories })}
                    search={searchCategories}
                    helperText={__('Leave empty to apply to the whole cart.', 'faracart')}
                  />
                </Box>
              </Grid>

              <Grid size={12}>
                <EntityAutocomplete
                  label={__('Excluded products (optional)', 'faracart')}
                  value={meta.excluded_products ?? []}
                  onChange={(excluded_products) => patchMeta({ excluded_products })}
                  search={searchProducts}
                  helperText={__('The discount never applies to these products.', 'faracart')}
                />
              </Grid>
            </>
          )}

          {type === 'free_shipping' && (
            <Grid size={12}>
              <Typography variant="body2" color="text.secondary">
                {__(
                  'Makes every shipping rate free once the goal is reached. Zone/method-specific rules are configured in a later phase.',
                  'faracart'
                )}
              </Typography>
            </Grid>
          )}

          {type === 'free_gift' && (
            <>
              {!meta.gift_product_id &&
                (!meta.gift_products || meta.gift_products.length === 0) && (
                  <Grid size={12}>
                    <Alert severity="warning" variant="outlined">
                      {__(
                        'Select a gift product — without one the gift can never be added to the cart, so the reward stays unavailable on the storefront.',
                        'faracart'
                      )}
                    </Alert>
                  </Grid>
                )}
              {meta.gift_add_mode === 'choose' ? (
                <Grid size={12}>
                  <EntityAutocomplete
                    label={__('Gift products (customer picks one)', 'faracart')}
                    value={meta.gift_products ?? []}
                    onChange={(gift_products) => {
                      const first = gift_products[0] ?? 0;
                      patchMeta({ gift_products, gift_product_id: first });
                    }}
                    search={searchProducts}
                    multiple
                    helperText={__(
                      'The customer chooses one free gift from this list once the goal is reached. The first product is the storefront default.',
                      'faracart'
                    )}
                  />
                </Grid>
              ) : (
                <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                  <EntityAutocomplete
                    label={__('Gift product', 'faracart')}
                    value={meta.gift_product_id ? [meta.gift_product_id] : []}
                    onChange={(ids) => patchMeta({ gift_product_id: ids[0] ?? 0 })}
                    search={searchProducts}
                    multiple={false}
                    helperText={__('The product added to the cart as a gift.', 'faracart')}
                  />
                </Grid>
              )}
              <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
                <TextField
                  select
                  label={__('Gift mode', 'faracart')}
                  fullWidth
                  // A goal saved before the 'optional' mode was removed may
                  // still store it — render it as 'automatic' so the select
                  // never shows a blank value; saving overwrites the legacy
                  // mode with whatever the merchant picks. (The widening cast
                  // is deliberate: the DB can still hold the removed value.)
                  value={
                    (meta.gift_add_mode as string | undefined) === 'optional'
                      ? 'automatic'
                      : (meta.gift_add_mode ?? 'automatic')
                  }
                  onChange={(event) =>
                    patchMeta({
                      gift_add_mode: event.target.value as 'automatic' | 'choose',
                    })
                  }
                >
                  {GIFT_MODE_OPTIONS.map((option) => (
                    <MenuItem key={option.value} value={option.value}>
                      {option.label}
                    </MenuItem>
                  ))}
                </TextField>
              </Grid>
            </>
          )}

          {type === 'coupon' && (
            <CouponFields values={values} meta={meta} patchMeta={patchMeta} patch={patch} />
          )}

          <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
            <TextField
              label={__('Reward label', 'faracart')}
              fullWidth
              value={meta.label ?? ''}
              placeholder={__('e.g. Summer free shipping', 'faracart')}
              helperText={__('Shown to the customer (fee/coupon name).', 'faracart')}
              onChange={(event) => patchMeta({ label: event.target.value })}
            />
          </Grid>

          <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
            <TextField
              select
              label={__('Stack with other rewards?', 'faracart')}
              fullWidth
              value={stacking}
              onChange={(event) => patchMeta({ stacking: event.target.value as 'none' | 'stack' })}
              helperText={__('Exclusive rewards never combine.', 'faracart')}
            >
              {STACKING_OPTIONS.map((option) => (
                <MenuItem key={option.value} value={option.value}>
                  {option.label}
                </MenuItem>
              ))}
            </TextField>
          </Grid>
        </>
      )}
    </Grid>
  );
}

interface CouponFieldsProps {
  values: GoalInput;
  meta: RewardMetaInput;
  patchMeta: (patch: Partial<RewardMetaInput>) => void;
  /** Patches top-level goal reward columns (value/cap). */
  patch: (patch: Partial<GoalInput>) => void;
}

/**
 * Coupon reward configuration: use an existing coupon code (searchable)
 * or generate a deterministic coupon from rules (Phase 5 CouponApplicator).
 */
function CouponFields({ values, meta, patchMeta, patch }: CouponFieldsProps) {
  const generate = meta.coupon_generate ?? false;
  const [query, setQuery] = useState('');
  const [options, setOptions] = useState<CouponPick[]>([]);

  // Debounced coupon search (server-side, capped at 50).
  useEffect(() => {
    const timer = window.setTimeout(() => {
      searchCoupons({ q: query, per_page: 20 }).then((items) =>
        setOptions(items.map((item) => ({ ...item })))
      );
    }, 300);

    return () => window.clearTimeout(timer);
  }, [query]);

  const selectedCoupon = useMemo(
    () => options.find((option) => option.code === meta.coupon_code) ?? null,
    [options, meta.coupon_code]
  );

  return (
    <>
      <Grid size={12}>
        <FormControlLabel
          control={
            <Switch
              checked={generate}
              onChange={(event) => patchMeta({ coupon_generate: event.target.checked })}
            />
          }
          label={
            <Box>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {__('Generate a coupon from these rules', 'faracart')}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {__('A deterministic coupon is created per customer.', 'faracart')}
              </Typography>
            </Box>
          }
        />
      </Grid>

      {generate ? (
        <>
          <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
            <TextField
              select
              label={__('Coupon discount type', 'faracart')}
              fullWidth
              value={meta.coupon_discount_type ?? 'percent'}
              onChange={(event) =>
                patchMeta({ coupon_discount_type: event.target.value as 'percent' | 'fixed_cart' })
              }
            >
              {COUPON_TYPE_OPTIONS.map((option) => (
                <MenuItem key={option.value} value={option.value}>
                  {option.label}
                </MenuItem>
              ))}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
            <TextField
              label={__('Coupon value', 'faracart')}
              type="number"
              fullWidth
              value={values.reward_value ?? ''}
              placeholder="10"
              onChange={(event) => patch({ reward_value: Number(event.target.value) || null })}
            />
          </Grid>
          <Grid size={{ xs: 12, sm: 6, lg: 4 }}>
            <TextField
              label={__('Coupon cap (optional)', 'faracart')}
              type="number"
              fullWidth
              value={values.reward_max_value ?? ''}
              placeholder={__('Optional cap', 'faracart')}
              onChange={(event) => patch({ reward_max_value: Number(event.target.value) || null })}
            />
          </Grid>
        </>
      ) : (
        <Grid size={{ xs: 12, sm: 6, lg: 6 }}>
          <Autocomplete<CouponPick, false, false, true>
            freeSolo
            options={options}
            value={selectedCoupon ?? null}
            inputValue={meta.coupon_code ?? ''}
            getOptionLabel={(option) => (typeof option === 'string' ? option : option.code)}
            isOptionEqualToValue={(option, value) =>
              typeof value === 'object' && option.code === value.code
            }
            onInputChange={(_event, value) => {
              setQuery(value);
              patchMeta({ coupon_code: value });
            }}
            onChange={(_event, value) => {
              if (value && typeof value !== 'string') {
                patchMeta({ coupon_code: value.code });
                patch({
                  reward_value: value.amount ?? null,
                  reward_max_value: null,
                });
              }
            }}
            renderInput={(params) => (
              <TextField
                {...params}
                label={__('Existing coupon code', 'faracart')}
                helperText={__('Type or pick an existing coupon.', 'faracart')}
              />
            )}
          />
        </Grid>
      )}
    </>
  );
}
