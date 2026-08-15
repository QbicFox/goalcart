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
 * MUI theme tuned to a modern, restrained SaaS dashboard that still sits
 * naturally inside the WordPress admin.
 *
 * The palette keeps WordPress's admin blue as the brand primary (so the
 * dashboard reads as a first-class WP screen) but rounds it out with full
 * semantic success/warning/error ramps and a neutral ink/canvas scale, so
 * color communicates meaning rather than decoration (UICHANGES.md §24).
 * Spacing/radius/typography are centralized here so no page hard-codes
 * its own values (§38).
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
      success: {
        main: '#00a32a',
        light: '#dff0e4',
        dark: '#007017',
        contrastText: '#ffffff',
      },
      warning: {
        main: '#996800',
        light: '#fdf3dd',
        dark: '#7d5a00',
        contrastText: '#ffffff',
      },
      error: {
        main: '#d63638',
        light: '#fbeaea',
        dark: '#b32d2e',
        contrastText: '#ffffff',
      },
      info: {
        main: '#2271b1',
        light: '#e8f0f7',
        dark: '#135e96',
        contrastText: '#ffffff',
      },
    },
    shape: { borderRadius: 8 },
    typography: {
      fontFamily: ADMIN_FONT,
      h4: { fontWeight: 600, fontSize: '1.75rem', lineHeight: 1.3 },
      h5: { fontWeight: 600, fontSize: '1.35rem', lineHeight: 1.35 },
      h6: { fontWeight: 600, fontSize: '1.05rem', lineHeight: 1.4 },
      subtitle1: { fontWeight: 600 },
      subtitle2: { fontWeight: 600 },
      body2: { lineHeight: 1.5 },
      caption: { lineHeight: 1.45 },
    },
    components: {
      MuiButton: {
        defaultProps: { disableElevation: true },
        styleOverrides: {
          root: { textTransform: 'none', fontWeight: 500 },
        },
      },
      MuiPaper: {
        styleOverrides: {
          root: { backgroundImage: 'none' },
        },
      },
      // Lightweight modern cards: a subtle border carries the surface;
      // shadows are reserved for elevation (dialogs/drawers), never decoration.
      MuiCard: {
        styleOverrides: {
          root: { boxShadow: 'none' },
        },
      },
      MuiCardContent: {
        styleOverrides: {
          root: ({ theme }) => ({
            padding: theme.spacing(2),
            '&:last-child': { paddingBottom: theme.spacing(2) },
          }),
        },
      },
      // Modern tables: comfortable row spacing, sticky headers, a quiet
      // header background and a restrained hover state (§28).
      MuiTableHead: {
        styleOverrides: {
          root: ({ theme }) => ({
            '& .MuiTableCell-head': {
              backgroundColor: theme.palette.grey[50],
              color: theme.palette.text.secondary,
              fontWeight: 600,
              whiteSpace: 'nowrap',
            },
          }),
        },
      },
      MuiTableCell: {
        styleOverrides: {
          root: ({ theme }) => ({
            borderColor: theme.palette.divider,
            paddingTop: theme.spacing(1.25),
            paddingBottom: theme.spacing(1.25),
          }),
        },
      },
      MuiTooltip: {
        styleOverrides: {
          tooltip: { fontSize: 12 },
        },
      },
      MuiChip: {
        styleOverrides: {
          root: { fontWeight: 500 },
        },
      },
    },
  });
}
