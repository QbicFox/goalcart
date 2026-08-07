import { __ } from '@wordpress/i18n';

import { getBootData } from './boot';

/**
 * Minimal admin shell placeholder (Phase 2 foundation).
 *
 * Phase 8 (React Admin Foundation) replaces this with the full shell —
 * AdminLayout, hash router, AppProviders (MUI theme + RTL + TanStack
 * Query), navigation and routed pages — following the reference plugin's
 * React architecture.
 */
export default function App() {
  const boot = getBootData();

  return (
    <div className="goalcart-admin-placeholder">
      <h1>{__('Goal Cart', 'goalcart')}</h1>
      <p>{__('The Goal Cart admin dashboard is built in Phase 8.', 'goalcart')}</p>
      <p className="goalcart-admin-meta">
        {boot.siteName} — v{boot.version}
      </p>
    </div>
  );
}
