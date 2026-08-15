/**
 * Global date-range model (Phase 17, mirroring the reference plugin's
 * date-range module).
 *
 * The analytics page consumes a DateRange through the DateRangeContext
 * provider, so filtering on the page refetches the dashboard query at
 * once (the query key embeds from/to).
 */

/** Preset keys offered by the date-range filter. */
export type RangePreset =
  | 'today'
  | 'yesterday'
  | 'last7'
  | 'last30'
  | 'last90'
  | 'this_month'
  | 'previous_month'
  | 'custom';

/**
 * A resolved date-range selection.
 *
 * `preset` records which preset produced the range (so the filter can
 * highlight it), and `from`/`to` are concrete `Y-m-d` bounds that every
 * API call uses. For the `custom` preset the bounds come straight from
 * the date-range picker.
 */
export interface DateRange {
  preset: RangePreset;
  from: string; // Y-m-d
  to: string; // Y-m-d
}

/** Presets that map to a fixed, recomputable window (excludes `custom`). */
export type FixedRangePreset = Exclude<RangePreset, 'custom'>;
