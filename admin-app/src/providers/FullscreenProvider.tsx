/* eslint-disable react-refresh/only-export-components -- a context module
   conventionally exports both the provider component and the hook that
   consumes it (see the reference's FullscreenProvider for the pattern). */

import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { getBootData } from '../boot';

interface FullscreenContextValue {
  fullscreen: boolean;
  setFullscreen: (value: boolean) => void;
}

const FullscreenContext = createContext<FullscreenContextValue | null>(null);

/** body class the full-screen CSS (assets/css/admin-fullscreen.css) keys on. */
const FULLSCREEN_CLASS = 'goalcart-fullscreen';

/**
 * Full-screen dashboard mode (Settings → General toggle).
 *
 * When enabled, the app hides the WordPress admin chrome and owns the
 * whole viewport (fixed header + sidebar, scrollable content). The WP
 * admin chrome visibility is controlled by the `goalcart-fullscreen`
 * body class — PHP adds it on first load (Admin::admin_body_class) so
 * there is no flash, and this provider owns it from then on so saving
 * the Settings toggle switches modes instantly without a page reload.
 *
 * Mirrors the reference plugin (WooInsights\FullscreenProvider).
 */
export function FullscreenProvider({ children }: { children: ReactNode }) {
  // Initialized from boot data so the first render matches the persisted
  // setting (no layout flash while the settings query loads).
  const [fullscreen, setFullscreen] = useState(() => getBootData().fullscreen);

  useEffect(() => {
    document.body.classList.toggle(FULLSCREEN_CLASS, fullscreen);

    // Restore the previous state on unmount (the SPA never unmounts the
    // provider, but this keeps the body class from lingering on other
    // admin pages if it ever does).
    return () => {
      document.body.classList.toggle(FULLSCREEN_CLASS, false);
    };
  }, [fullscreen]);

  const value = useMemo(() => ({ fullscreen, setFullscreen }), [fullscreen]);

  return <FullscreenContext.Provider value={value}>{children}</FullscreenContext.Provider>;
}

/** Access the current dashboard display mode. */
export function useFullscreen(): FullscreenContextValue {
  const context = useContext(FullscreenContext);

  if (!context) {
    throw new Error('useFullscreen must be used within a FullscreenProvider.');
  }

  return context;
}
