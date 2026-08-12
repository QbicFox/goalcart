import { lazy, Suspense, type ReactNode } from 'react';
import { createHashRouter, Navigate, RouterProvider } from 'react-router-dom';
import Box from '@mui/material/Box';
import Skeleton from '@mui/material/Skeleton';

import AdminLayout from './components/layout/AdminLayout';
import { DateRangeProvider } from './date-range/DateRangeContext';
import Dashboard from './routes/Dashboard';
import Goals from './routes/Goals';
import NotFound from './routes/NotFound';

// Secondary routes are lazy-loaded so their chunks are only fetched when
// actually opened — the dashboard bundle stays small (roadmap
// code-splitting guidance, mirroring the reference plugin).
const Campaigns = lazy(() => import('./routes/Campaigns'));
const Analytics = lazy(() => import('./routes/Analytics'));
const Appearance = lazy(() => import('./routes/Appearance'));
const Settings = lazy(() => import('./routes/Settings'));
const GoalBuilder = lazy(() => import('./routes/GoalBuilder'));
const CampaignBuilder = lazy(() => import('./routes/CampaignBuilder'));
// Phase 33.6 (React Admin) — the Revenue Optimization section.
const RevenueOverview = lazy(() => import('./routes/RevenueOverview'));
const GoalPerformance = lazy(() => import('./routes/GoalPerformance'));
const AttributionDashboard = lazy(() => import('./routes/AttributionDashboard'));
const Recommendations = lazy(() => import('./routes/Recommendations'));
const UpsellAnalytics = lazy(() => import('./routes/UpsellAnalytics'));

/** Skeleton shown while a lazy route loads. */
function RouteFallback() {
  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
      <Skeleton variant="rounded" height={96} />
      <Skeleton variant="rounded" height={420} />
    </Box>
  );
}

function lazyRoute(element: ReactNode) {
  return <Suspense fallback={<RouteFallback />}>{element}</Suspense>;
}

/**
 * Hash-based data router (Phase 8: React Admin Foundation).
 *
 * Uses `createHashRouter` (a data router) rather than the declarative
 * `<HashRouter>` so data-router hooks (e.g. `useBlocker` for unsaved
 * changes, as the reference Settings page uses) are available later. The
 * URL shape is unchanged — still `#/route` inside the single admin page.
 *
 * `DateRangeProvider` (Phase 17) sits inside the router — it syncs the
 * analytics date range with the URL hash params via `useSearchParams` —
 * and above the layout so routed pages can share the selection.
 *
 * Mirrors the reference plugin (WooInsights App router).
 */
const router = createHashRouter([
  {
    element: (
      <DateRangeProvider>
        <AdminLayout />
      </DateRangeProvider>
    ),
    children: [
      { index: true, element: <Navigate to="/dashboard" replace /> },
      { path: '/dashboard', element: <Dashboard /> },
      { path: '/goals', element: <Goals /> },
      { path: '/goals/new', element: lazyRoute(<GoalBuilder />) },
      { path: '/goals/:id/edit', element: lazyRoute(<GoalBuilder />) },
      { path: '/campaigns', element: lazyRoute(<Campaigns />) },
      { path: '/campaigns/new', element: lazyRoute(<CampaignBuilder />) },
      { path: '/campaigns/:id/edit', element: lazyRoute(<CampaignBuilder />) },
      { path: '/analytics', element: lazyRoute(<Analytics />) },
      { path: '/revenue', element: lazyRoute(<RevenueOverview />) },
      { path: '/revenue/goals', element: lazyRoute(<GoalPerformance />) },
      { path: '/revenue/attribution', element: lazyRoute(<AttributionDashboard />) },
      // UPSELL_REFACTOR §36: Optimization is the canonical home of the two
      // engines — Goal Optimization decides the Goal, Upsell Performance
      // measures product recommendations. The pre-refactor routes stay
      // alive as redirects so bookmarked URLs never break (§37/§53).
      { path: '/optimization/goals', element: lazyRoute(<Recommendations />) },
      { path: '/optimization/upsells', element: lazyRoute(<UpsellAnalytics />) },
      { path: '/revenue/recommendations', element: <Navigate to="/optimization/goals" replace /> },
      { path: '/revenue/upsells', element: <Navigate to="/optimization/upsells" replace /> },
      { path: '/appearance', element: lazyRoute(<Appearance />) },
      { path: '/settings', element: lazyRoute(<Settings />) },
      { path: '*', element: <NotFound /> },
    ],
  },
]);

export default function App() {
  return <RouterProvider router={router} />;
}
