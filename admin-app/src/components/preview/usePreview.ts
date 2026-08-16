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
import { PRESET_PERCENTS, defaultControls } from './types';
import type { PreviewControlsValue, PreviewPreset } from './types';

/** What a target needs to derive per goal/campaign preview. */
export interface PreviewDerivation {
  /** Initial template for the preview ('' = resolve the goal/campaign one). */
  templateDefault: string;
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

/** Everything a preview panel (dialog or builder column) needs. */
export interface PreviewState {
  controls: PreviewControlsValue;
  /** Apply a partial update to the control state. */
  patch: (update: Partial<PreviewControlsValue>) => void;
  /** Apply a state preset (computes amount/quantity from the target). */
  applyPreset: (preset: PreviewPreset) => void;
  previewQuery: UseQueryResult<PreviewPayload, Error>;
  settingsQuery: UseQueryResult<{ data: FaraCartSettings; meta: SettingsMeta }, Error>;
  templatesQuery: UseQueryResult<TemplatesPayload, Error>;
}

interface UsePreviewOptions<T> {
  /** The goal/campaign being previewed (its values change as the form edits). */
  target: T;
  /** Derive the preview behavior from the current target. */
  derive: (target: T) => PreviewDerivation;
}

/**
 * Shared Phase 15 preview state: control values (state presets, simulated
 * amount/quantity, reward state, device width, template), debounced
 * simulated values + target key, the preview query (`POST /preview`) and
 * the settings query (appearance tokens).
 *
 * Unlike the original dialog hook, the preview is always on — the builder
 * pages render it as a persistent column, so nothing resets on open. The
 * `payloadKey` derivation keeps the query key in sync with the (possibly
 * unsaved) form values, so the live preview always reflects the current
 * form state after a short debounce.
 */
export function usePreview<T>({ target, derive }: UsePreviewOptions<T>): PreviewState {
  const [controls, setControls] = useState<PreviewControlsValue>(() => defaultControls(''));
  const [debounced, setDebounced] = useState<{
    amount: number;
    quantity: number;
    payloadKey: string;
  }>({ amount: 0, quantity: 0, payloadKey: '' });

  const seededRef = useRef(false);
  const derivationRef = useRef<PreviewDerivation | null>(null);

  // Recompute the derivation each render (it is cheap — a JSON key plus
  // closures over the target), so render code can read the payloadKey
  // without touching the ref. The latest derivation also lives in a ref
  // for callbacks (presets, the query), which always fire with fresh
  // form state.
  const payloadKey = derive(target).payloadKey;

  useEffect(() => {
    derivationRef.current = derive(target);
  }, [target, derive]);

  // Seed the controls once from the initial target (50% preset), so the
  // preview opens at a sensible simulated state without ever clobbering
  // the admin's adjustments afterwards.
  useEffect(() => {
    if (seededRef.current) {
      return;
    }
    seededRef.current = true;

    const derivation = derivationRef.current;

    if (!derivation) {
      return;
    }

    const targets = derivation.targetsFor(PRESET_PERCENTS['50']);
    const next = { ...defaultControls(derivation.templateDefault), ...targets };

    setControls(next);
    setDebounced({
      amount: next.amount,
      quantity: next.quantity,
      payloadKey: derivation.payloadKey,
    });
  }, []);

  // Debounce amount/quantity AND the target key so typing in the form (or
  // the simulated values) does not fire a preview request per keystroke.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebounced({ amount: controls.amount, quantity: controls.quantity, payloadKey });
    }, 300);

    return () => window.clearTimeout(timer);
  }, [controls.amount, controls.quantity, payloadKey]);

  const patch = (update: Partial<PreviewControlsValue>) =>
    setControls((current) => ({ ...current, ...update }));

  const applyPreset = (preset: PreviewPreset) => {
    const derivation = derivationRef.current;

    if (!derivation) {
      return;
    }

    if (preset === 'custom') {
      setControls((current) => ({ ...current, preset }));
      return;
    }

    setControls((current) => ({
      ...current,
      preset,
      ...derivation.targetsFor(PRESET_PERCENTS[preset]),
    }));
  };

  const previewQuery = useQuery({
    queryKey: ['preview', debounced.payloadKey, debounced.amount, debounced.quantity],
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

  // The shared template registry (pluggable engine): the preview needs
  // the registered templates + their global defaults for the template
  // override control and the forced-template preview settings.
  const templatesQuery = useTemplates();

  return { controls, patch, applyPreset, previewQuery, settingsQuery, templatesQuery };
}
