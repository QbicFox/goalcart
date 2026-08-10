/** Device-width presets for the preview frame (Phase 15 Preview Controls). */
export type PreviewDevice = 'mobile' | 'tablet' | 'desktop';

/** How the reward chip should render in the preview. */
export type PreviewRewardState = 'auto' | 'locked' | 'unlocked';

/**
 * Simulated cart-state presets (Phase 15 Preview States): empty cart,
 * 25%, 50%, 75%, completed, plus manual `custom` values.
 */
export type PreviewPreset = 'empty' | '25' | '50' | '75' | '100' | 'custom';

/**
 * The full preview control state shared by the goal and campaign preview
 * dialogs.
 *
 * `template` may be `''` to mean "auto": render each goal with its own
 * resolved template + settings from the payload (the backend resolves
 * item override → scope default → legacy → fallback, identically to the
 * live frontend). Choosing a template id forces that variant with its
 * global default appearance.
 */
export interface PreviewControlsValue {
  preset: PreviewPreset;
  amount: number;
  quantity: number;
  rewardState: PreviewRewardState;
  deviceWidth: PreviewDevice;
  template: string;
}

/** Frame widths (px) for each device preset. */
export const DEVICE_WIDTHS: Record<PreviewDevice, number> = {
  mobile: 375,
  tablet: 768,
  desktop: 1280,
};

/** The fraction of the (top) goal target each state preset simulates. */
export const PRESET_PERCENTS: Record<Exclude<PreviewPreset, 'custom'>, number> = {
  empty: 0,
  '25': 0.25,
  '50': 0.5,
  '75': 0.75,
  '100': 1,
};

/**
 * Resolved storefront appearance tokens (mirrors the `--goalcart-*`
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

/** Default (fresh-open) control state for a goal/campaign preview. */
export function defaultControls(template: string): PreviewControlsValue {
  return {
    preset: '50',
    amount: 0,
    quantity: 0,
    rewardState: 'auto',
    deviceWidth: 'desktop',
    template,
  };
}
