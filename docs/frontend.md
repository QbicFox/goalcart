# Goal Cart React Admin App (Phase 8)

The admin dashboard is a React + TypeScript SPA (Vite + MUI) mounted
inside the WordPress admin at `#goalcart-admin` (see
`includes/Admin/Admin.php`). Phase 8 builds the complete admin shell —
providers, routing, layout, shared components and the six admin pages —
following the reference plugin's React architecture exactly.

```text
admin-app/src/
├── main.tsx                     entry: createRoot + AppProviders + App
├── App.tsx                      createHashRouter (data router) + lazy routes
├── boot.ts                      getBootData() — window.goalcart
├── types.ts                     boot data + Goal / Settings / envelope types
├── theme/index.ts               WP-admin MUI theme (RTL-aware)
├── providers/
│   ├── AppProviders.tsx         theme + Emotion cache + TanStack Query +
│   │                            Fullscreen + Snackbar providers
│   ├── FullscreenProvider.tsx   owns the goalcart-fullscreen body class
│   └── (SnackbarProvider lives in components/notifications/)
├── api/
│   ├── client.ts                apiFetch: X-WP-Nonce + envelope unwrap
│   ├── goals.ts                 typed goal CRUD + duplicate
│   ├── campaigns.ts             typed campaign CRUD + duplicate
│   ├── search.ts                typed /search/{products,categories,coupons}
│   └── settings.ts              typed GET/POST /settings
├── components/
│   ├── layout/                  AdminLayout (header + sidebar + main) + navigation
│   ├── notifications/           SnackbarProvider + useSnackbar()
│   ├── ConfirmDialog.tsx        reusable destructive-action dialog
│   ├── EmptyState.tsx           no-data panel
│   ├── ErrorBoundary.tsx        render-error fallback with retry
│   ├── GoalPreviewDialog.tsx    lightweight goal preview (simulated progress)
│   ├── CampaignPreviewDialog.tsx  lightweight milestone-ladder preview
│   ├── goal-builder/            Phase 9 builder sections
│   │   ├── SectionCard.tsx      titled section wrapper
│   │   ├── EntityAutocomplete.tsx  debounced async search picker
│   │   ├── GoalTypePicker.tsx   goal type selector cards
│   │   ├── goalTypes.tsx        shared GOAL_TYPES definitions
│   │   ├── TargetFields.tsx     dynamic target by goal type
│   │   ├── CompositeChildrenEditor.tsx  AND/OR child goals
│   │   ├── RewardFields.tsx     dynamic reward configuration
│   │   ├── ConditionFields.tsx  excluded products + schedule
│   │   └── DisplayFields.tsx    message/template/icon
│   └── PageContainer.tsx        shared page header + content wrapper
└── routes/
    ├── Dashboard.tsx            live goal summary (REST-backed)
    ├── Goals.tsx                full goal CRUD list (Phase 9)
    ├── GoalBuilder.tsx          goal create/edit builder (Phase 9)
    ├── Campaigns.tsx            full campaign CRUD list (Phase 10)
    ├── CampaignBuilder.tsx      campaign builder: basics, schedule, priority,
    │                            milestone ordering (Phase 10)
    ├── Analytics.tsx            container (Phase 16–17)
    ├── Appearance.tsx           container (Phase 12)
    ├── Settings.tsx             functional react-hook-form settings page
    └── NotFound.tsx             404
```

## Providers

`AppProviders` wraps the app in:

- **MUI theme** — `createAppTheme()` (WP-admin palette: blue #2271b1,
  canvas #f0f0f1, ink #1d2327; `direction` flips for RTL locales).
- **Dedicated Emotion cache** — key `goalcart`, RTL-flipped via the
  stylis RTL plugin when the site locale is RTL, so styles never collide
  with other admin plugins and the whole dashboard mirrors for RTL sites.
  No `CssBaseline` (its global resets would leak into the WP admin);
  scoped resets live in `styles.css` under `#goalcart-admin`.
- **TanStack Query** — retry 1, 60s staleTime, no refetch on window
  focus.
- **FullscreenProvider** — initialized from boot data (no layout flash),
  owns the `goalcart-fullscreen` body class that
  `assets/css/admin-fullscreen.css` keys on.
- **SnackbarProvider** — `useSnackbar().notify(message, severity)` from
  any page or mutation.

## Routing

`createHashRouter` (a data router, so `useBlocker`-style hooks are
available later) inside the single admin page — URL shape `#/route`.
The root redirects to `#/dashboard`; secondary routes are lazy-loaded
with a skeleton fallback (code splitting keeps the dashboard bundle
small); unknown routes render `NotFound`.

## Layout

`AdminLayout` mirrors the reference: a white header (title, view-store
link, user menu) in normal document flow below the WP admin bar, a
responsive sidebar with collapsible nav groups (persisted in
localStorage) that collapses to a temporary drawer on mobile, a pinned
footer (collapse-all + version chip), and the routed content area wrapped
in an `ErrorBoundary`. In full-screen mode the shell owns the viewport
and only the content area scrolls.

## API client

`apiFetch(path, options, unwrap)` sends `X-WP-Nonce` on every request
(Phase 2 nonce strategy), parses the Phase 7 `{ data, meta, pagination }`
envelope, throws typed `ApiError`s (network errors included), and
optionally returns the full envelope when pagination metadata is needed.
Typed per-resource modules (`api/goals.ts`, `api/settings.ts`) consume
it.

## Shared components

- `PageContainer` — consistent title + description + actions header.
- `ConfirmDialog` — MUI dialog for destructive actions (busy state,
  not dismissible while busy).
- `EmptyState` / `ErrorBoundary` — no-data and render-error panels.
- `SnackbarProvider` — shared notifications (the reference renders a
  local Snackbar per page; the shared provider is the Goal Cart
  foundation variant).

## Pages

| Page | Phase 8 status | Full implementation |
|---|---|---|
| Dashboard | live summary: goal counts, currency, version | 16–17 (analytics) |
| Goals | full goal CRUD list (search/filter/pagination, edit, duplicate, enable/disable, delete, preview) | 9 (Goal Management UI) |
| GoalBuilder | — | 9 (Goal Builder, `/goals/new` + `/goals/:id/edit`) |
| Campaigns | full campaign CRUD list (milestones, status, priority, schedule, edit, duplicate, enable/disable, delete, preview) | 10 (Campaign Builder) |
| CampaignBuilder | — | 10 (Campaign Builder, `/campaigns/new` + `/campaigns/:id/edit`) |
| Analytics | container | 16–17 |
| Appearance | container | 12 (Progress Templates) |
| Settings | functional: enabled + fullscreen toggles (react-hook-form) | 18 (full surface) |

## Build & verification

- `npm run typecheck` / `npm run lint` / `npm run build` (Vite manifest
  consumed by `AssetLoader`), Prettier-formatted.
- Browser smoke test: the built bundle is rendered headlessly with boot
  data injected; every route renders its expected content and no console
  errors are produced.
- RTL is covered by the three-part MUI setup (dir attribute, theme
  direction, flipped Emotion cache); i18n delegates to `wp.i18n` via the
  `@wordpress/i18n` shim.
