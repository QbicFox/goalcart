/* eslint-disable react-refresh/only-export-components -- a context module
   conventionally exports both the provider component and the hooks that
   consume / register through it (see the reference's FullscreenProvider
   for the pattern). */

import {
  createContext,
  useContext,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type DependencyList,
  type ReactNode,
} from 'react';

interface ActionBarContextValue {
  /** The current bottom-bar content — null when no page has registered any. */
  actions: ReactNode;
  /** Register the bottom-bar content, or clear it with null. */
  setActions: (actions: ReactNode) => void;
}

const ActionBarContext = createContext<ActionBarContextValue | null>(null);

/**
 * Sticky bottom action bar (admin UX).
 *
 * The bar itself lives once in the app shell (AdminLayout renders
 * `ActionBar`); pages that own a save / reset / cancel action surface
 * register their buttons through `useStickyBarActions`, and the bar
 * renders them pinned to the bottom of the dashboard while it is present.
 *
 * The context deliberately keeps the registered content (not just a
 * setter) so the shell's bar re-renders exactly when the actions change —
 * registering pages never need to consume this context themselves.
 */
export function ActionBarProvider({ children }: { children: ReactNode }) {
  const [actions, setActions] = useState<ReactNode>(null);

  const value = useMemo(() => ({ actions, setActions }), [actions]);

  return <ActionBarContext.Provider value={value}>{children}</ActionBarContext.Provider>;
}

/** Access the action bar context (used by the shell's ActionBar). */
export function useActionBar(): ActionBarContextValue {
  const ctx = useContext(ActionBarContext);

  if (!ctx) {
    throw new Error('useActionBar must be used within an ActionBarProvider');
  }

  return ctx;
}

/**
 * Page-side registration for the sticky bottom action bar.
 *
 * Pages call this once, before any conditional return, with a render
 * callback that builds their save / reset / cancel buttons.
 *
 * `deps` must list every value the buttons read — both the *visible*
 * output (pending flags, disabled states) and any mutable form state the
 * click handlers capture (e.g. the builder's `values`, the appearance
 * `drafts`). The registered node is re-evaluated only when a dep
 * changes, so a handler that reads stale data would silently persist it;
 * the deps are what keep the click closures fresh (this mirrors the
 * `useCallback` / `useMemo` dep discipline used across the app).
 */
export function useStickyBarActions(deps: DependencyList, render: () => ReactNode) {
  const { setActions } = useActionBar();
  const renderRef = useRef(render);

  // Keep the latest render callback reachable from the registration
  // effect below without listing it in the deps — a fresh closure is
  // created on every page render, so listing it would re-register (and
  // re-render the bar) endlessly. A layout effect runs after every
  // render and before the passive effect that actually registers.
  useLayoutEffect(() => {
    renderRef.current = render;
  });

  useEffect(() => {
    setActions(renderRef.current());

    return () => setActions(null);
    // Re-register only when the visible output can change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [setActions, ...deps]);
}
