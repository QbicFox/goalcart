import { useEffect, useRef, useState } from 'react';
import { useQuery, type UseQueryResult } from '@tanstack/react-query';

import { fetchPreview, type PreviewRequest } from '../../api/preview';
import { fetchSettingsEnvelope } from '../../api/settings';
import { useTemplates } from '../../templates/useTemplates';
import type {
  FaraCartSettings,
  PreviewPayload,
  PreviewSimulated,
  SettingsMeta,
  TemplatesPayload,
} from '../../types';
import { PRESET_PERCENTS } from './types';
import type { PreviewControlsValue, PreviewPreset } from './types';

/** What a target needs to derive per mission/campaign preview. */
export interface PreviewDerivation {
  /** Amount/quantity a state preset fraction should simulate. */
  targetsFor: (fraction: number) => { amount: number; quantity: number };
  /** The endpoint parameters for the current target (ids and/or form payloads). */
  paramsFor: (simulated: PreviewSimulated) => Omit<PreviewRequest, 'simulated'>;
  /**
   * Stable key identifying the preview target — a saved id, or a
   * serialization of the unsaved form fields that drive the preview. It
   * is part of the query key, so form edits refetch the preview.
   */
  payloadKey: string;
}

/** Everything a preview panel (builder column) needs. */
export interface PreviewState {
  controls: PreviewControlsValue;
  /** Apply a preview-state preset (computes amount/quantity from the target). */
  applyPreset: (preset: PreviewPreset) => void;
  previewQuery: UseQueryResult<PreviewPayload, Error>;
  settingsQuery: UseQueryResult<{ data: FaraCartSettings; meta: SettingsMeta }, Error>;
  templatesQuery: UseQueryResult<TemplatesPayload, Error>;
}

interface UsePreviewOptions<T> {
  /** The mission/campaign being previewed (its values change as the form edits). */
  target: T;
  /** Derive the preview behavior from the current target. */
  derive: (target: T) => PreviewDerivation;
}

/**
 * Shared preview state: the preview-state preset (empty cart →
 * completed), debounced simulated values derived from the preset + the
 * current form target, the preview query (`POST /preview`) and the
 * settings query (appearance tokens).
 *
 * The builder pages render the preview as a persistent column, so nothing
 * resets on open. The `payloadKey` derivation keeps the query key in sync
 * with the (possibly unsaved) form values, so the live preview always
 * reflects the current form state after a short debounce — no save
 * required. The simulated amount/quantity are derived internally from the
 * preset fraction and the form's own target (never editable), and the
 * preview renders the template the backend resolved for the form (item
 * override → scope default → fallback), identical to the storefront.
 */
export function usePreview<T>({ target, derive }: UsePreviewOptions<T>): PreviewState {
  // The only user-facing preview control: which progress state to show.
  const [preset, setPreset] = useState<PreviewPreset>('50');
  const [debounced, setDebounced] = useState<{
    amount: number;
    quantity: number;
    payloadKey: string;
  }>({ amount: 0, quantity: 0, payloadKey: '' });

  const derivationRef = useRef<PreviewDerivation | null>(null);

  // Recompute the derivation each render (it is cheap — a JSON key plus
  // closures over the target), so render code can read the payloadKey
  // without touching the ref. The latest derivation also lives in a ref
  // for callbacks (the query), which always fire with fresh form state.
  const payloadKey = derive(target).payloadKey;

  useEffect(() => {
    derivationRef.current = derive(target);
  }, [target, derive]);

  // Derive the simulated amount/quantity from the preset fraction applied
  // to the current form target, and debounce them AND the target key so
  // typing in the form (or switching the preset) does not fire a preview
  // request per change.
  useEffect(() => {
    const derivation = derivationRef.current;

    if (!derivation) {
      return;
    }

    const { amount, quantity } = derivation.targetsFor(PRESET_PERCENTS[preset]);

    const timer = window.setTimeout(() => {
      setDebounced({ amount, quantity, payloadKey });
    }, 300);

    return () => window.clearTimeout(timer);
  }, [preset, payloadKey]);

  const applyPreset = (next: PreviewPreset) => setPreset(next);

  // The shared template registry (pluggable engine): the preview needs
  // the registered templates for the resolved-template label.
  const templatesQuery = useTemplates();

  const previewQuery = useQuery({
    // The scope-default template ids ride on the key: when the global /
    // default template changes (Appearance page), the preview refetches
    // so a mission or campaign without its own template reflects the new
    // default — never a stale one.
    queryKey: [
      'preview',
      debounced.payloadKey,
      debounced.amount,
      debounced.quantity,
      templatesQuery.data?.defaults?.mission ?? '',
      templatesQuery.data?.defaults?.campaign ?? '',
    ],
    queryFn: () => {
      const simulated = { amount: debounced.amount, quantity: debounced.quantity };
      const derivation = derivationRef.current;
      const params = derivation ? derivation.paramsFor(simulated) : {};

      return fetchPreview({ ...params, simulated });
    },
    enabled: debounced.payloadKey !== '',
    placeholderData: (previous) => previous,
  });

  // The shared `['settings']` cache is the envelope shape `{ data, meta }`
  // (the same shape the Settings/Appearance pages use), so the preview
  // reads the settings off `data` like everywhere else.
  const settingsQuery = useQuery({ queryKey: ['settings'], queryFn: fetchSettingsEnvelope });

  return { controls: { preset }, applyPreset, previewQuery, settingsQuery, templatesQuery };
}
