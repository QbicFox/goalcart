import { useMemo, type ReactNode } from 'react';
import createCache from '@emotion/cache';
import { CacheProvider } from '@emotion/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import rtlPlugin from '@mui/stylis-plugin-rtl';
import { StyledEngineProvider, ThemeProvider } from '@mui/material/styles';
import { prefixer } from 'stylis';

import { getBootData } from '../boot';
import { createAppTheme } from '../theme';
import { SnackbarProvider } from '../components/notifications/SnackbarProvider';
import { FullscreenProvider } from './FullscreenProvider';

interface AppProvidersProps {
  children: ReactNode;
}

/**
 * Wraps the app in every global provider:
 * - MUI theme (WP-admin palette, RTL-aware)
 * - A dedicated Emotion cache with a unique 'goalcart' key (so our
 *   styles never collide with other admin plugins) that is RTL-flipped
 *   when the WordPress locale is RTL, mirroring the whole dashboard
 * - TanStack Query client
 * - Full-screen dashboard mode provider
 * - Shared Snackbar notifications provider
 *
 * Routing lives in App.tsx (a `createHashRouter` data router wrapping
 * AdminLayout), so only non-router providers live here.
 *
 * RTL (three-part setup, per the MUI guide):
 *   1. `dir="rtl"` on the root element — WordPress already renders
 *      `<html dir="rtl">` for RTL locales; the PHP mount point also
 *      sets `dir` explicitly for robustness.
 *   2. `theme.direction: 'rtl'` — handled in `createAppTheme()`.
 *   3. A flipped Emotion cache — handled here via the stylis RTL plugin.
 *
 * Note: no CssBaseline — its `* { box-sizing }` and `body` resets would
 * leak outside the mount point into the whole WP admin. Scoped resets
 * live in styles.css under `#goalcart-admin` instead.
 *
 * Mirrors the reference plugin (WooInsights\AppProviders).
 */
export default function AppProviders({ children }: AppProvidersProps) {
  const queryClient = useMemo(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            retry: 1,
            staleTime: 60_000,
            refetchOnWindowFocus: false,
          },
        },
      }),
    []
  );

  // The RTL flip uses the stylis plugin from the MUI guide: it swaps
  // left/right in the generated CSS (margins, padding, borders, floats,
  // text-align, ...), so the entire dashboard — custom sx styles and MUI
  // components alike — mirrors for RTL sites. LTR sites keep the default
  // plugin chain (prefixer only).
  const cache = useMemo(() => {
    const isRtl = getBootData().isRtl;

    return createCache({
      key: 'goalcart',
      stylisPlugins: isRtl ? [prefixer, rtlPlugin] : undefined,
    });
  }, []);

  const theme = useMemo(() => createAppTheme(), []);

  return (
    <StyledEngineProvider injectFirst>
      <CacheProvider value={cache}>
        <ThemeProvider theme={theme}>
          <QueryClientProvider client={queryClient}>
            <FullscreenProvider>
              <SnackbarProvider>{children}</SnackbarProvider>
            </FullscreenProvider>
          </QueryClientProvider>
        </ThemeProvider>
      </CacheProvider>
    </StyledEngineProvider>
  );
}
