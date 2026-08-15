/* eslint-disable react-refresh/only-export-components -- a context module
   conventionally exports both the provider component and the hook that
   consumes it (see React docs on sharing state between components). */
import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';

import { getBootData } from '../boot';
import { comparisonRange, isFixedPreset, isYmd, normalizeBounds, presetRange } from './dateRange';
import type { DateRange, FixedRangePreset } from './types';

/** localStorage key holding the persisted range JSON. */
const STORAGE_KEY = 'goalcart:dateRange';

/** Fallback selection when nothing is persisted and no URL params exist. */
const DEFAULT_PRESET: FixedRangePreset = 'last30';

export interface ComparisonRange {
  from: string;
  to: string;
}

interface DateRangeContextValue {
  /** The resolved range — every dashboard query keys on from/to. */
  range: DateRange;
  /** Equal-length period immediately before `range` (vs previous period). */
  comparison: ComparisonRange;
  setPreset: (preset: FixedRangePreset) => void;
  setCustomRange: (from: string, to: string) => void;
}

const DateRangeContext = createContext<DateRangeContextValue | null>(null);

/** Deserialize a persisted/URL range, validating shapes and bounds. */
function parseRange(value: unknown): DateRange | null {
  if (typeof value !== 'object' || value === null) {
    return null;
  }

  const record = value as Record<string, unknown>;
  const preset = record.preset;

  if (typeof preset !== 'string' || (!isFixedPreset(preset) && preset !== 'custom')) {
    return null;
  }

  if (preset === 'custom') {
    if (!isYmd(record.from) || !isYmd(record.to)) {
      return null;
    }

    return { preset: 'custom', ...normalizeBounds(record.from, record.to) };
  }

  // Fixed presets are recomputed against today, so persisted from/to are
  // not trusted — the preset always wins (keeps stale persisted dates
  // from pinning a range forever).
  const today = getBootData().currentDate;

  return { preset, ...presetRange(preset, today) };
}

/**
 * Rebuild the initial range: URL params win (deep links), then
 * localStorage, then the default preset.
 */
function initialRange(params: URLSearchParams): DateRange {
  const today = getBootData().currentDate;

  // A fixed preset deep link (`?preset=last7`) — persisted as-is for
  // non-custom ranges, so it round-trips exactly.
  const preset = params.get('preset');

  if (preset && isFixedPreset(preset)) {
    return { preset, ...presetRange(preset, today) };
  }

  // A custom deep link (`?from=...&to=...`).
  if (params.get('from') && params.get('to')) {
    const fromUrl = parseRange({
      preset: 'custom',
      from: params.get('from'),
      to: params.get('to'),
    });

    if (fromUrl) {
      return fromUrl;
    }
  }

  if (typeof window !== 'undefined') {
    try {
      const stored = window.localStorage.getItem(STORAGE_KEY);

      if (stored) {
        const fromStorage = parseRange(JSON.parse(stored));

        if (fromStorage) {
          return fromStorage;
        }
      }
    } catch {
      // Corrupt storage — fall through to the default.
    }
  }

  return { preset: DEFAULT_PRESET, ...presetRange(DEFAULT_PRESET, today) };
}

/**
 * Global date-range provider (Phase 17).
 *
 * Holds the one date-range selection used by the analytics dashboard,
 * persists it to both the URL hash params (shareable deep links) and
 * localStorage (survives reloads), and exposes the equal-length previous
 * period so the filter can render "vs previous period" context.
 *
 * Mirrors the reference plugin's DateRangeProvider.
 */
export function DateRangeProvider({ children }: { children: ReactNode }) {
  const [params, setParams] = useSearchParams();

  const [range, setRange] = useState<DateRange>(() => initialRange(params));

  const persist = useCallback(
    (next: DateRange) => {
      setRange(next);

      // Mirror the selection into the URL hash params (replace so the
      // history stack stays clean) and localStorage.
      const nextParams = new URLSearchParams(params);
      nextParams.set('preset', next.preset);

      if (next.preset === 'custom') {
        nextParams.set('from', next.from);
        nextParams.set('to', next.to);
      } else {
        nextParams.delete('from');
        nextParams.delete('to');
      }

      setParams(nextParams, { replace: true });

      try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
      } catch {
        // Private mode / quota — persistence is best-effort.
      }
    },
    [params, setParams]
  );

  const setPreset = useCallback(
    (preset: FixedRangePreset) => {
      const today = getBootData().currentDate;
      persist({ preset, ...presetRange(preset, today) });
    },
    [persist]
  );

  const setCustomRange = useCallback(
    (from: string, to: string) => {
      persist({ preset: 'custom', ...normalizeBounds(from, to) });
    },
    [persist]
  );

  const value = useMemo<DateRangeContextValue>(
    () => ({
      range,
      comparison: comparisonRange(range),
      setPreset,
      setCustomRange,
    }),
    [range, setPreset, setCustomRange]
  );

  return <DateRangeContext.Provider value={value}>{children}</DateRangeContext.Provider>;
}

/**
 * Access the global date range. Throws when used outside the provider —
 * pages must render under DateRangeProvider (wired in App.tsx, inside
 * the data router, so it can sync the URL hash params).
 */
export function useDateRange(): DateRangeContextValue {
  const context = useContext(DateRangeContext);

  if (!context) {
    throw new Error('useDateRange must be used within a DateRangeProvider.');
  }

  return context;
}
