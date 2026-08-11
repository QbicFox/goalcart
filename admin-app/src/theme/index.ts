import { createTheme } from '@mui/material/styles';
import type { Theme } from '@mui/material/styles';

import { getBootData } from '../boot';

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
 * Colors are pulled from the WP admin palette (admin blue #2271b1,
 * canvas #f0f0f1, ink #1d2327) so the dashboard blends in natively, and
 * the direction flips to RTL when the site locale is RTL.
 *
 * Mirrors the reference plugin (WooInsights\admin-app theme).
 */
export function createAppTheme(): Theme {
  const boot = getBootData();

  return createTheme({
    direction: boot.isRtl ? 'rtl' : 'ltr',
    palette: {
      mode: 'light',
      primary: {
        main: '#2271b1',
        light: '#72aee6',
        dark: '#135e96',
        contrastText: '#ffffff',
      },
      secondary: {
        main: '#7e8993',
        light: '#a7aaad',
        dark: '#50575e',
        contrastText: '#ffffff',
      },
      background: {
        default: '#f0f0f1',
        paper: '#ffffff',
      },
      text: {
        primary: '#1d2327',
        secondary: '#50575e',
        disabled: '#a7aaad',
      },
      divider: '#dcdcde',
      success: { main: '#00a32a' },
      warning: { main: '#dba617' },
      error: { main: '#d63638' },
      info: { main: '#2271b1' },
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
