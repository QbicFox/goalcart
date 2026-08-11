/* eslint-disable react-refresh/only-export-components -- a context module
   conventionally exports both the provider component and the hook that
   consumes it (see the reference's FullscreenProvider for the pattern). */

import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { getBootData } from '../boot';
import type { AdminTheme } from '../types';

interface ThemeModeContextValue {
  mode: AdminTheme;
  setMode: (value: AdminTheme) => void;
}

const ThemeModeContext = createContext<ThemeModeContextValue | null>(null);

/** body class the dark-theme CSS keys on (mirrors `goalcart-fullscreen`). */
const DARK_CLASS = 'goalcart-dark';

/**
 * Dashboard theme mode (Settings → General → Dashboard theme).
 *
 * When dark, the app swaps the MUI palette (createAppTheme('dark')) and
 * marks the page with the `goalcart-dark` body class so the scoped CSS
 * (styles.css) paints the mount point dark too. PHP adds the class on
 * first load (Admin::admin_body_class) so there is no flash, and this
 * provider owns it from then on — saving the Settings toggle switches
 * the whole dashboard instantly without a page reload.
 *
 * Mirrors the reference plugin (WooInsights\\FullscreenProvider).
 */
export function ThemeModeProvider({ children }: { children: ReactNode }) {
  // Initialized from boot data so the first render matches the persisted
  // setting (no layout flash while the settings query loads).
  const [mode, setMode] = useState<AdminTheme>(() =>
    getBootData().adminTheme === 'dark' ? 'dark' : 'light'
  );

  useEffect(() => {
    document.body.classList.toggle(DARK_CLASS, mode === 'dark');

    // Restore the previous state on unmount (the SPA never unmounts the
    // provider, but this keeps the body class from lingering on other
    // admin pages if it ever does).
    return () => {
      document.body.classList.toggle(DARK_CLASS, false);
    };
  }, [mode]);

  const value = useMemo(() => ({ mode, setMode }), [mode]);

  return <ThemeModeContext.Provider value={value}>{children}</ThemeModeContext.Provider>;
}

/** Access the current dashboard theme mode and its setter. */
export function useThemeMode(): ThemeModeContextValue {
  const context = useContext(ThemeModeContext);

  if (!context) {
    throw new Error('useThemeMode must be used within a ThemeModeProvider.');
  }

  return context;
}
