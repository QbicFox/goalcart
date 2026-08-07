/**
 * Drop-in shim for the `@wordpress/i18n` package.
 *
 * WordPress core ships the i18n implementation on the global `wp.i18n`
 * (registered as the `wp-i18n` script). Aliasing `@wordpress/i18n` to this
 * module means the app can `import { __ } from '@wordpress/i18n'` while
 * delegating to the core implementation — which is exactly where the
 * locale JSON produced by `wp_set_script_translations()` lands via
 * `wp.i18n.setLocaleData()`.
 */

export interface WpI18n {
  __: (text: string, domain?: string) => string;
  _x: (text: string, context: string, domain?: string) => string;
  _n: (single: string, plural: string, number: number, domain?: string) => string;
  _nx: (
    single: string,
    plural: string,
    number: number,
    context: string,
    domain?: string
  ) => string;
  sprintf: (format: string, ...args: Array<string | number>) => string;
  setLocaleData: (data: unknown, domain?: string) => void;
  isRTL: () => boolean;
}

declare const wp: { i18n: WpI18n } | undefined;

const i18n: WpI18n =
  typeof wp !== 'undefined' && wp.i18n
    ? wp.i18n
    : {
        // Identity fallback so the app also typechecks/runs outside WP
        // (e.g. `vite dev` standalone) without a core script.
        __: (text) => text,
        _x: (text) => text,
        _n: (single, plural, number) => (number === 1 ? single : plural),
        _nx: (single, plural, number) => (number === 1 ? single : plural),
        sprintf: (format, ...args) =>
          format.replace(/%[sd]/g, () => String(args.shift() ?? '')),
        setLocaleData: () => undefined,
        isRTL: () => false,
      };

export const __ = i18n.__;
export const _x = i18n._x;
export const _n = i18n._n;
export const _nx = i18n._nx;
export const sprintf = i18n.sprintf;
export const setLocaleData = i18n.setLocaleData;
export const isRTL = i18n.isRTL;
