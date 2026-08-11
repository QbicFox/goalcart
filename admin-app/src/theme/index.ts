import { createTheme } from '@mui/material/styles';
import type { Theme } from '@mui/material/styles';

import { getBootData } from '../boot';
import type { AdminTheme } from '../types';

/** System font stack used by the WP admin (wp-admin.css). */
export const ADMIN_FONT = [
  '-apple-system',
  'BlinkMacSystemFont',
  '"Segoe UI"',
  'Roboto',
  'Oxygen-Sans',
  'Ubuntu',
  'Cantarell',
  '"Helvetica Neue"',
  'sans-serif',
].join(',');

/**
 * MUI theme tuned to the WordPress admin look & feel.
 *
 * Light mode uses the WP admin palette (admin blue #2271b1, canvas
 * #f0f0f1, ink #1d2327) so the dashboard blends in natively. Dark mode
 * mirrors the WP admin dark color scheme (base #1d2327, elevated cards
 * #2c3338, highlight #72aee6) so the whole dashboard — navigation,
 * cards, tables, charts — is comfortable in low light. The direction
 * flips to RTL when the site locale is RTL.
 *
 * The active mode comes from the ThemeModeProvider (Settings → General →
 * Dashboard theme), which is initialized from the persisted setting so
 * the first render matches without a flash.
 *
 * Mirrors the reference plugin (WooInsights\admin-app theme).
 */
export function createAppTheme(mode: AdminTheme = 'light'): Theme {
  const boot = getBootData();
  const dark = mode === 'dark';

  return createTheme({
    direction: boot.isRtl ? 'rtl' : 'ltr',
    palette: {
      mode,
      primary: {
        main: dark ? '#72aee6' : '#2271b1',
        light: dark ? '#9ec2e6' : '#72aee6',
        dark: dark ? '#2271b1' : '#135e96',
        contrastText: dark ? '#1d2327' : '#ffffff',
      },
      secondary: {
        main: dark ? '#a7aaad' : '#7e8993',
        light: dark ? '#c3c4c7' : '#a7aaad',
        dark: dark ? '#787c82' : '#50575e',
        contrastText: dark ? '#1d2327' : '#ffffff',
      },
      background: {
        default: dark ? '#1d2327' : '#f0f0f1',
        paper: dark ? '#2c3338' : '#ffffff',
      },
      text: {
        primary: dark ? '#f0f0f1' : '#1d2327',
        secondary: dark ? '#c3c4c7' : '#50575e',
        disabled: dark ? '#787c82' : '#a7aaad',
      },
      divider: dark ? '#3c434a' : '#dcdcde',
      success: { main: dark ? '#00ba37' : '#00a32a' },
      warning: { main: dark ? '#f0b429' : '#dba617' },
      error: { main: dark ? '#f86368' : '#d63638' },
      info: { main: dark ? '#72aee6' : '#2271b1' },
    },
    shape: { borderRadius: 4 },
    typography: {
      fontFamily: ADMIN_FONT,
      h4: { fontWeight: 600 },
      h5: { fontWeight: 600 },
      h6: { fontWeight: 600 },
    },
    components: {
      MuiButton: {
        defaultProps: { disableElevation: true },
      },
      MuiPaper: {
        styleOverrides: {
          root: { backgroundImage: 'none' },
        },
      },
    },
  });
}
