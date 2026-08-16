/** How the reward chip should render in the preview. */
export type PreviewRewardState = 'auto' | 'locked' | 'unlocked';

/**
 * Simulated cart-state presets (Phase 15 Preview States): empty cart,
 * 25%, 50%, 75%, completed. The preset fraction drives the simulated
 * amount/quantity internally — the admin never edits raw simulation
 * values; the preview consumes the current form state instead.
 */
export type PreviewPreset = 'empty' | '25' | '50' | '75' | '100';

/**
 * The preview control state shared by the goal and campaign preview
 * panels. Only the preview-state preset remains user-selectable — the
 * simulated amount/quantity, reward state, device width and template
 * override are gone: the preview renders the form's own configuration
 * (the selected template, or the global default) at the available width.
 */
export interface PreviewControlsValue {
  preset: PreviewPreset;
}

/** The fraction of the (top) goal target each state preset simulates. */
export const PRESET_PERCENTS: Record<PreviewPreset, number> = {
  empty: 0,
  '25': 0.25,
  '50': 0.5,
  '75': 0.75,
  '100': 1,
};

/**
 * Resolved storefront appearance tokens (mirrors the `--faracart-*`
 * custom properties in assets/css/frontend.css, sourced from the Phase 12
 * `frontend_*` settings).
 */
export interface PreviewTokens {
  accent: string;
  bg: string;
  border: string;
  text: string;
  radius: number;
  barHeight: number;
}

/** Default appearance tokens (keep in sync with Settings::defaults()). */
export const DEFAULT_PREVIEW_TOKENS: PreviewTokens = {
  accent: '#2271b1',
  bg: '#ffffff',
  border: '#dcdcde',
  text: '#1d2327',
  radius: 10,
  barHeight: 10,
};

/** Build preview tokens from the persisted settings (defaults fill gaps). */
export function tokensFromSettings(
  settings:
    | Partial<{
        frontend_accent: string;
        frontend_bg: string;
        frontend_border: string;
        frontend_text: string;
        frontend_radius: number;
        frontend_bar_height: number;
      }>
    | undefined
): PreviewTokens {
  return {
    accent: settings?.frontend_accent || DEFAULT_PREVIEW_TOKENS.accent,
    bg: settings?.frontend_bg || DEFAULT_PREVIEW_TOKENS.bg,
    border: settings?.frontend_border || DEFAULT_PREVIEW_TOKENS.border,
    text: settings?.frontend_text || DEFAULT_PREVIEW_TOKENS.text,
    radius: settings?.frontend_radius ?? DEFAULT_PREVIEW_TOKENS.radius,
    barHeight: settings?.frontend_bar_height ?? DEFAULT_PREVIEW_TOKENS.barHeight,
  };
}
