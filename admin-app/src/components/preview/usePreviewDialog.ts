import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { fetchPreview } from '../../api/preview';
import { fetchSettingsEnvelope } from '../../api/settings';
import { useTemplates } from '../../templates/useTemplates';
import type { PreviewSimulated } from '../../types';
import { PRESET_PERCENTS, defaultControls } from './types';
import type { PreviewControlsValue, PreviewPreset } from './types';

/** What a dialog needs to derive per target (goal or campaign). */
export interface PreviewDerivation {
  /** Initial template for the preview ('' = resolve the goal/campaign one). */
  templateDefault: string;
  /** Amount/quantity a state preset fraction should simulate. */
  targetsFor: (fraction: number) => { amount: number; quantity: number };
  /** The endpoint parameters for the current target. */
  paramsFor: (simulated: PreviewSimulated) => { goalId?: number; campaignId?: number };
}
interface UsePreviewDialogOptions<T extends { id: number }> {
  /** The goal/campaign being previewed (null = dialog closed). */
  target: T | null;
  /** Derive the preview behavior from the current target. */
  derive: (target: T) => PreviewDerivation;
}

/**
 * Shared Phase 15 preview state: control values (state presets, simulated
 * amount/quantity, reward state, device width, template), debounced
 * simulated values, the preview query (`POST /preview`) and the settings
 * query (appearance tokens). Used by the goal and campaign preview
 * dialogs so their behavior can never drift.
 */
export function usePreviewDialog<T extends { id: number }>({
  target,
  derive,
}: UsePreviewDialogOptions<T>) {
  const [controls, setControls] = useState<PreviewControlsValue>(() => defaultControls(''));
  const [debounced, setDebounced] = useState({ amount: 0, quantity: 0 });

  const wasOpenRef = useRef(false);
  const derivationRef = useRef<PreviewDerivation | null>(null);

  // Keep the latest derivation (recreated each render by inline closures).
  useEffect(() => {
    if (target) {
      derivationRef.current = derive(target);
    }
  }, [target, derive]);

  // Reset the controls every time the dialog opens (null → target), so a
  // reopened goal/campaign starts from a fresh 50% preview. While open,
  // re-renders never clobber the admin's adjustments.
  useEffect(() => {
    const open = target !== null;
    const justOpened = open && !wasOpenRef.current;
    wasOpenRef.current = open;

    if (!justOpened) {
      return;
    }

    const derivation = derivationRef.current;

    if (!derivation) {
      return;
    }

    const targets = derivation.targetsFor(PRESET_PERCENTS['50']);
    const next = { ...defaultControls(derivation.templateDefault), ...targets };

    setControls(next);
    setDebounced({ amount: next.amount, quantity: next.quantity });
  }, [target]);

  // Debounce amount/quantity so typing does not fire a preview per key.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebounced({ amount: controls.amount, quantity: controls.quantity });
    }, 300);

    return () => window.clearTimeout(timer);
  }, [controls.amount, controls.quantity]);

  const patch = (update: Partial<PreviewControlsValue>) =>
    setControls((current) => ({ ...current, ...update }));

  const applyPreset = (preset: PreviewPreset) => {
    const derivation = derivationRef.current;

    if (!target || !derivation) {
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
    queryKey: ['preview', target?.id, debounced.amount, debounced.quantity],
    queryFn: () => {
      const simulated = { amount: debounced.amount, quantity: debounced.quantity };
      const derivation = derivationRef.current;
      const params = derivation ? derivation.paramsFor(simulated) : {};

      return fetchPreview({ ...params, simulated });
    },
    enabled: target !== null,
    placeholderData: (previous) => previous,
  });	// The shared `['settings']` cache is the envelope shape `{ data, meta }`
	// (the same shape the Settings/Appearance pages use), so the dialogs
	// read the settings off `data` like everywhere else.
	const settingsQuery = useQuery({ queryKey: ['settings'], queryFn: fetchSettingsEnvelope });

	// The shared template registry (pluggable engine): the dialogs need
	// the registered templates + their global defaults for the template
	// override control and the forced-template preview settings.
	const templatesQuery = useTemplates();

	return { controls, patch, applyPreset, previewQuery, settingsQuery, templatesQuery };
}
