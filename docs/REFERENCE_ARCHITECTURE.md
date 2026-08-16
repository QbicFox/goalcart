# WooInsights Reference Architecture Report

> **Phase 0 / Task 0.9** — Architectural source of truth for the **FaraCart for WooCommerce** plugin.
> Reference plugin: `/home/qbicfox/public_html/woo-app/wp-content/plugins/wooinsights` (v0.1.0).
> Inspected against: WordPress 7.0.2, WooCommerce 11.0.0, PHP ≥ 7.4, Node ≥ 18.
> This report derives **patterns** only — WooInsights business logic (search analytics) is NOT to be copied.

---

## 1. Directory Architecture

```
wooinsights/                      # Plugin root (slug = folder name)
├── wooinsights.php               # Main bootstrap file (plugin header + constants + boot)
├── uninstall.php                 # Uninstall handler (WP_UNINSTALL_PLUGIN guard)
├── composer.json                 # Composer + PSR-4 autoload mapping
├── README.md / AGENT.md          # Docs & AI roadmap
├── .editorconfig / .gitignore
├── includes/                     # All PHP classes, PSR-4 root (WooInsights\)
│   ├── Plugin.php                # Singleton bootstrap (top-level namespace)
│   ├── Container.php             # DI container (top-level namespace)
│   ├── Admin/                    # Admin menu + asset loading
│   ├── Database/                 # Schema + Installer (migrations)
│   ├── Data/                     # Services (cleanup, synonym manager)
│   ├── Hooks/                    # HookManager
│   ├── Notifications/            # Notification engine
│   ├── Reports/                  # Report services (domain logic)
│   ├── REST/                     # REST controllers (one per endpoint group)
│   ├── Settings/                 # Settings service
│   ├── Tracker/                  # Frontend tracking services
│   └── Utils/                    # Static helper classes
├── admin-app/                    # React admin SPA (Vite + TS + MUI)
│   ├── index.html / package.json / package-lock.json
│   ├── vite.config.ts / tsconfig.json / eslint.config.js
│   ├── .prettierrc.json / .prettierignore
│   ├── scripts/                  # i18n build/extract/verify scripts
│   ├── src/
│   │   ├── main.tsx / boot.ts / App.tsx / types.ts / global.d.ts / styles.css
│   │   ├── api/                  # API client + per-domain fetchers
│   │   ├── components/           # layout/, dashboard/, date-range/, reports/, trends/, shared
│   │   ├── date-range/           # global date-range context + math
│   │   ├── i18n/                 # wp.i18n re-exports
│   │   ├── lib/                  # format, jalali, wp-i18n shim
│   │   ├── providers/            # AppProviders, FullscreenProvider
│   │   ├── routes/               # one file per page (lazy-loaded)
│   │   └── theme/                # MUI theme factory
│   └── dist/                     # Build output (git-ignored) + .vite/manifest.json
├── assets/                       # Static assets
│   ├── css/  (admin-fullscreen.css)
│   ├── js/   (search-tracker.js)
│   └── img/
├── languages/                    # .pot / .po / .mo / JED .json
├── admin/  public/               # Empty placeholders (.gitkeep)
├── tests/                        # Empty placeholder (.gitkeep) — reserved namespace
└── vendor/                       # Composer autoloader only (git-ignored)
```

**Conventions:**
- One folder per functional area under `includes/`, mirroring the namespace (`WooInsights\Admin`, `WooInsights\REST`, …).
- One class per file; filename = class name (PSR-4).
- PHP in `includes/`, React in `admin-app/`, static frontend JS in `assets/js/`, static admin CSS in `assets/css/`.
- No legacy admin PHP (`admin/` empty) and no separate frontend PHP (`public/` empty) — everything is served from `includes/` services.

## 2. PHP Architecture

### 2.1 Bootstrap
- **Single main file** with the plugin header and six constants guarded by `defined()`:
  `WOOINSIGHTS_VERSION`, `WOOINSIGHTS_FILE`, `WOOINSIGHTS_PATH`, `WOOINSIGHTS_URL`, `WOOINSIGHTS_BASENAME`, `WOOINSIGHTS_DB_VERSION`.
- Composer autoloader loaded if present; then `WooInsights\Plugin::instance()->boot()` runs **at file scope** (not on `plugins_loaded`) so `register_activation_hook()` is always registered in time.
- Namespace: `WooInsights\` → `includes/` (PSR-4, `composer.json`).

### 2.2 Boot flow (`Plugin::boot()`)
1. `register_activation_hook` / `register_deactivation_hook` → `Installer`.
2. `plugins_loaded` + `admin_init` → `Installer::maybe_upgrade` (migrations run on every load, cheap version compare).
3. `init` → `load_textdomain()` (`load_plugin_textdomain`).
4. `cron_schedules` filter → custom weekly interval.
5. `before_woocommerce_init` → HPOS feature declaration via `FeaturesUtil::declare_compatibility('custom_order_tables', …)`.
6. `HookManager::register()` for every core service, then `hooks()->run()` to apply all hooks in one pass.
7. `do_action('wooinsights_loaded', $plugin)`.

### 2.3 Dependency injection (`Container`)
- Lightweight hand-rolled container: `set($id, $factory, $shared)`, `singleton()`, `factory()`, `bind()`, reflection autowiring in `make()`.
- All services are registered as **singletons** in `Plugin::register_services()` with closures receiving the container (`function (Container $c) { return new Foo($c->get(Bar::class)); }`).
- Constructor injection only; no service-locator usage in components.

### 2.4 Hook management (`HookManager`)
- Every component implements `register( HookManager $hooks )` and calls `$hooks->add_action(...)` / `add_filter(...)`.
- Registration is **buffered** and applied in one `run()` call — single place where all WordPress hooks are declared (the `Plugin` class).
- No service touches `add_action()` directly outside `HookManager`.

### 2.5 Layering
- **REST layer**: `REST/*Controller` classes (one per resource), thin, extend `BaseController`, delegate to services.
- **Service layer**: `Tracker`, `Reports/*`, `Data/*`, `Notifications/Notifier`, `Settings` — hold business logic.
- **Database layer**: `Database/Schema` (definitions) + `Database/Installer` (migrations); services query `$wpdb` directly with prepared statements (no ORM, no repository classes, no models/DTOs/value objects).
- **Utils**: static helper classes (`Helpers`, `Formatting`, `Jalali`).
- No controllers/services/repositories separation beyond this; reports are stateless query services.

### 2.6 Settings
- Single service (`Settings`) persisted in one WP option (`wooinsights_settings`), defaults + `wp_parse_args` merge, in-memory cache, read via `get($key, $default)`.
- REST `SettingsController` saves only known keys, each validated by schema + per-key sanitizer.

### 2.7 Frontend tracking (WooCommerce integration patterns)
- Native hooks: `pre_get_posts`, `found_posts`, `shutdown`, `wp_redirect`, `wp`, `wp_enqueue_scripts`, `wp_footer`.
- WooCommerce hooks: `woocommerce_add_to_cart`, `woocommerce_thankyou`, `before_woocommerce_init`.
- Public AJAX: `wp_ajax_`/`wp_ajax_nopriv_` with `check_ajax_referer`; frontend config printed inline (`window.wooinsights`) with nonce.
- Public REST: nonce-verified `permission_callback` instead of capability check.
- Cookie sessions: HttpOnly, SameSite=Lax, sliding expiry, server-side staleness.

### 2.8 Cron jobs (6 events, all scheduled by `Installer`)
- `wooinsights_daily_report` (daily 02:00 site-local), `wooinsights_cleanup` (daily 03:00), `wooinsights_clean_sessions` (daily), `wooinsights_notify_daily` (daily 08:00), `wooinsights_notify_weekly` (weekly Mon 09:00), `wooinsights_out_of_stock_notify` (weekly Mon 07:00).
- Custom `wooinsights_weekly` interval registered persistently (so rescheduling works from wp-cron.php).
- Times resolved in `wp_timezone()`; `wp_next_scheduled()` guards; deactivation clears events but **preserves data**; uninstall drops tables.

### 2.9 Activation / deactivation / uninstall
- **Activate**: create tables (dbDelta) + set DB version option + schedule crons.
- **Deactivate**: clear scheduled hooks only.
- **Uninstall** (`uninstall.php`): load autoloader via `__DIR__` (rename-safe), `Installer::uninstall()` drops all plugin tables + options.

## 3. React Architecture

### 3.1 Entry & boot
- `main.tsx` mounts on `#wooinsights-admin` (PHP renders this mount point with an explicit `dir` attribute and a loading placeholder).
- `boot.ts` exposes typed `getBootData()` reading `window.wooinsights` (localized by `AssetLoader::boot_data()`): nonce, REST base, user, caps, locale, isRtl, currency, version, fullscreen.

### 3.2 Routing
- **Hash-based data router** (`createHashRouter` from react-router-dom 6) — `#/route?preset=…`.
- Layout route wraps `DateRangeProvider` + `AdminLayout`; all report pages are children; index redirects to `/dashboard`; `*` → `NotFound`.
- **All non-dashboard routes are lazy-loaded** (`React.lazy` + `Suspense` with a skeleton fallback) so DataGrid/Recharts/form chunks load on demand.

### 3.3 State management
- **Server state**: TanStack Query 5 (`QueryClientProvider`; default `retry: 1`, `staleTime: 60s`, `refetchOnWindowFocus: false`).
- **Local state**: React hooks; `useServerGridData()` centralizes page/perPage/sort state and keying by global date range.
- **Global UI state via React Context**: `DateRangeContext` (single range shared by all pages, persisted to URL hash params + localStorage, exposes previous-period comparison), `FullscreenContext`.
- **Forms**: react-hook-form with `Controller` components; `useBlocker` for unsaved-changes guard.
- No Redux/Zustand — context + query is the entire state system.

### 3.4 API client
- Single `apiFetch<T>(path, options, unwrap)` helper: prepends `boot.restBase`, sends `X-WP-Nonce` + `Content-Type: application/json` when a body exists, `credentials: 'include'`, parses JSON defensively, throws typed `ApiError(status, message, code, data)`, unwraps the `{ data, meta, pagination }` envelope (configurable).
- Per-domain modules (`api/reports.ts`, `api/settings.ts`, `api/data.ts`, `api/synonyms.ts`, `api/notifications.ts`, `api/reportPages.ts`) expose typed fetchers using `apiFetch`.

### 3.5 UI components & conventions
- MUI 6 component library + `@mui/icons-material`, MUI X `DataGrid` for tables, `recharts` for charts, `@mui/x-date-pickers` + dayjs for date inputs.
- Layout: `AdminLayout` — in-flow header (below the WP admin bar), responsive sidebar (permanent Drawer ≥ md, temporary drawer < md), accordion nav groups with persisted expand state, pinned footer with collapse-all/version chip, `ErrorBoundary` around `<Outlet/>`.
- Shared building blocks: `ServerDataGrid` (server-side table with loading/error/empty states), `EmptyState`, `ReportPageHeader`, `CsvExportButton`, `KpiCard`, `DateRangeFilter`/`CustomRangePicker`, `TrendChart`, `FunnelBars`, `ScoreGauge`.
- One file per route in `routes/`; route pages are composition of shared components + `useQuery` fetchers.
- All UI strings via `__()` from `@wordpress/i18n`.

### 3.6 Theme & RTL
- `createAppTheme()`: WP admin palette (primary `#2271b1`, background `#f0f0f1`, ink `#1d2327`, divider `#dcdcde`, success/warning/error from WP), system font stack, `disableElevation`, `direction` from `boot.isRtl`.
- **RTL three-part setup**: `dir="rtl"` set by PHP on the mount point; `theme.direction`; Emotion cache with `@mui/stylis-plugin-rtl` + `prefixer`.
- Emotion cache key `'wooinsights'` isolates styles from other admin plugins; **no CssBaseline** (resets are scoped under `#wooinsights-admin` in `styles.css` to avoid leaking into WP admin).

### 3.7 i18n (React)
- `@wordpress/i18n` aliased to `src/lib/wp-i18n.ts` — a shim delegating to the `wp.i18n` global (WP core script).
- Translations loaded by `wp_set_script_translations()` → JED JSON → `wp.i18n.setLocaleData()`.
- POT extracted by scripts; Persian (`fa_IR`) shipped; JED filename is handle-based (`wooinsights-fa_IR-wooinsights-admin.json`, WP 6.5+).

## 4. Build Architecture

| Concern | Decision |
|---|---|
| Bundler | **Vite 5** (`@vitejs/plugin-react`), single-page admin app |
| Entry | `admin-app/src/main.tsx` (rollupOptions input) |
| Output | `admin-app/dist/`, `emptyOutDir`, `manifest: true` → `.vite/manifest.json`, `sourcemap: false` |
| Base | `base: './'` — relative asset URLs so the plugin works in subdirectory installs |
| TypeScript | strict; `tsc --noEmit` runs as part of `npm run build` (`tsc --noEmit && vite build`) |
| Lint | ESLint 9 flat config (`eslint.config.js`): TS recommended + react-hooks + react-refresh + jsx-a11y |
| Format | Prettier (`semi`, `singleQuote`, `printWidth: 100`, `trailingComma: es5`) |
| Package manager | npm (lockfile committed); Node ≥ 18 |
| Commands | `npm run dev` (Vite dev server, `localhost:5173`, `strictPort`), `build`, `typecheck`, `lint`, `format`, `i18n:*` |
| Aliases | `@` → `src/`; `@wordpress/i18n` → `src/lib/wp-i18n.ts` shim |
| Dependencies | React 18, MUI 6, TanStack Query 5, react-router-dom 6, react-hook-form, recharts, MUI X, dayjs — all **bundled** (no `wp.element` dependency) to avoid plugin conflicts |

### WordPress enqueue integration (`AssetLoader`)
- **Production**: read manifest → enqueue hashed entry JS with `filemtime` version; enqueue entry CSS; force `type="module"` via `wp_script_attributes` filter; dependencies `['wp-i18n']`; `in_footer`.
- **Development (HMR)**: when `wp_get_environment_type()` is `local`/`development` and the dev server is reachable (https-first on SSL; overridable via `WOOINSIGHTS_DEV_SERVER_URL`), enqueue `@vite/client` + `src/main.tsx` from the dev server.
- **Boot data**: `wp_localize_script` (`window.wooinsights`) on the app handle; `wp_set_script_translations` for React i18n.
- **Cache busting**: filemtime of the built entry file, falling back to `WOOINSIGHTS_VERSION`.

## 5. API Architecture

### 5.1 General
- **Namespace**: `wooinsights/v1` (single namespace for all endpoints).
- **Endpoints** (31 paths / 35 route registrations — `/settings`, `/synonyms`, `/synonyms/{id}` and `/notifications/rules` are registered once per HTTP method): grouped by controller:
  - `ReportController`: `/reports/overview`, `/reports/regenerate`, `/reports/top-searches`, `/reports/zero-results`, `/reports/zero-results/create-product`, `/reports/out-of-stock-demand`, `/reports/funnel`, `/reports/keywords`, `/reports/trends`, `/reports/opportunities`, `/reports/lost-revenue`, `/reports/quality`, `/reports/typo-suggestions`, `/reports/typo-suggestions/review`, `/reports/categories`, `/reports/brands`, `/reports/segments`, `/reports/devices`
  - `DataController`: `/data/status`, `/data/cleanup`, `/data/export`
  - `SettingsController`: `/settings` (GET/POST)
  - `SynonymController`: `/synonyms`, `/synonyms/{id}`, `/synonyms/import`, `/synonyms/suggestions`, `/synonyms/export`
  - `NotificationController`: `/notifications/rules`, `/notifications/test`
  - `SystemController`: `/system`
  - `ClickController`: `/track/click` (public)
- **HTTP methods**: `WP_REST_Server::READABLE` (GET), `CREATABLE` (POST), etc. per route; reads are GET, mutations are POST (no PUT/DELETE in the reference).
- **Response envelope**: `{ data, meta?, pagination? }` where `pagination = { page, per_page, total, total_pages }` (via `BaseController::success()/paginated()`).
- **Errors**: `WP_Error` with machine-readable codes prefixed `wooinsights_*` (`wooinsights_forbidden`, `wooinsights_invalid_nonce`, `wooinsights_rate_limited`, `wooinsights_settings_empty`, …) + HTTP status.
- **Permissions**: admin endpoints use `get_permission_callback()` = capability check (`manage_options`, filterable via `wooinsights_rest_capability`) **+ per-user rate limiting** (fixed window, transients, default 60 req/min). Public endpoints override with their own nonce verification.
- **Validation**: every route declares `args` with REST schemas (`type`, `default`, `enum`, `minimum/maximum`, `validate_callback`, `sanitize_callback`); shared report args (`date_from`, `date_to`, `compare`, `page`, `per_page`, `orderby`, `order`) from `BaseController::shared_args()`.
- **Dates**: validated `Y-m-d`; date range resolved to site-timezone `Y-m-d 00:00:00` → `23:59:59`; default range = last 30 days.
- **Caching**: `with_cache()` transient cache for GET/HEAD report responses, keyed by sorted params + user id, 5-minute TTL, never caches `WP_Error`; settings-save and cleanup flush `_transient_wooinsights_rest_*`.

### 5.2 Frontend API wrapper
- `apiFetch()` in `api/client.ts` (see §3.4) is the single wrapper: nonce auth, envelope unwrapping, typed errors.

### 5.3 Authentication model
- **Admin React app**: cookie-based REST auth via `X-WP-Nonce` (nonce `wp_rest` from boot data) + capability checks server-side.
- **Public frontend REST** (`/track/click`): plugin-specific nonce (`wp_create_nonce('wooinsights_track_click')`) printed in `window.wooinsights`, verified in `permission_callback`.
- **Public frontend AJAX** (`wooinsights_track_search`): `check_ajax_referer`.

## 6. Database Architecture

- **Engine**: MySQL via `$wpdb`; InnoDB; `$wpdb->get_charset_collate()`.
- **Table naming**: `{$wpdb->prefix}wooinsights_<name>` (`Schema::TABLE_PREFIX = 'wooinsights_'`).
- **8 tables** (all `bigint(20) unsigned AUTO_INCREMENT` PKs, `datetime` columns, `decimal(19,4)` for money):
  `search_logs`, `search_clicks`, `search_add_to_carts`, `search_conversions`, `daily_reports`, `typo_corrections`, `synonyms`, `settings` (option-style key/value).
- **Schema style**: dbDelta-friendly `CREATE TABLE` in `Schema::create_statements()`; single statement per table; `ENGINE=InnoDB {$collate}`.
- **Indexes**: `PRIMARY KEY` + targeted `KEY` indexes on FK columns and query columns (`created_at`, `session_id`, `search_term`, `product_id`, `report_date`, …); `UNIQUE KEY` where natural (`report_date`, `search_term+correction`).
- **Foreign keys**: defined in `Schema::foreign_keys()`; **not** created by dbDelta — `Installer::maybe_add_foreign_keys()` adds them via `ALTER TABLE` after checking `INFORMATION_SCHEMA.TABLE_CONSTRAINTS` (idempotent, failures logged but non-fatal).
- **Migrations**: version-driven; `WOOINSIGHTS_DB_VERSION` constant vs `wooinsights_db_version` option; `Installer::maybe_upgrade()` runs on `plugins_loaded` + `admin_init`; upgrade = recreate via dbDelta + refresh crons. No SQL migration files.
- **Timestamps**: `datetime` in the **site timezone** via `current_time('Y-m-d H:i:s')` (all time math done in the same frame); no `updated_at` everywhere — only where used.
- **JSON/serialization**: avoided; structured columns preferred; `longtext` only where needed (`synonyms.terms`).
- **Money**: `decimal(19,4)`.
- **Options** (non-table storage): settings (`wooinsights_settings`), DB version, converted-session markers, cleanup last-run, per-user rate-limit/cache transients.
- **Upgrade strategy**: idempotent re-creation + guarded FK adds + cron re-scheduling; uninstall drops everything.

## 7. Testing Architecture

- **Status: not yet implemented.** `tests/` contains only `.gitkeep`; no PHPUnit config, no test files, no JS test framework (no vitest/jest) in the reference plugin.
- `composer.json` reserves `autoload-dev` PSR-4 namespace `WooInsights\Tests\ => tests/`.
- `.gitignore` anticipates `.phpunit.result.cache` and `coverage/`.
- **Implication for FaraCart**: testing conventions must be established in Phase 2 (choose PHPUnit + WP test framework and Vitest/RTL as the project's own convention, since the reference has none).

## 8. Coding Conventions

### 8.1 PHP
- **Namespaces**: `WooInsights\` root; sub-namespace = folder (`Admin`, `Database`, `REST`, `Reports`, `Settings`, `Tracker`, `Utils`, `Data`, `Hooks`, `Notifications`).
- **File naming**: one class per file, filename = class name (PSR-4).
- **Class naming**: `StudlyCase`, final where appropriate (`final class Plugin`).
- **Method/property naming**: `snake_case` (`register_menu`, `maybe_upgrade`, `$this->container`).
- **Constants**: `UPPER_SNAKE_CASE` class constants (`MENU_SLUG`, `NAMESPACE`, `DB_VERSION_OPTION`); plugin constants prefixed with plugin slug (`WOOINSIGHTS_VERSION`).
- **Hooks naming**: `wooinsights_<verb>_<noun>` (`wooinsights_loaded`, `wooinsights_tracking_enabled`, `wooinsights_admin_boot_data`, `wooinsights_rest_capability`).
- **Docblocks**: every class and method has a PHPDoc block; `@package WooInsights` on every file; `@param`, `@return`, `@var` with types; inline comments explain "why" (reference-plugin task IDs).
- **File guard**: `defined( 'ABSPATH' ) || exit;` in every PHP file.
- **Indentation**: tabs (`.editorconfig`); alignment spaces inside arrays around `=>`.
- **Strictness**: scalar type hints + return types on new code (PHP 7.4 compatible syntax); no PHP 8-only syntax.
- **i18n**: every user-facing string via `__()`/`_n()` with text domain `wooinsights`; translators comments via `/* translators: … */`.
- **Error handling**: services return `0`/`false`/empty on failure (no exceptions except DI resolution); REST layer converts to `WP_Error`; `error_log` only for non-fatal infra issues with `// phpcs:ignore` annotations.
- **WordPress coding standards**: phpcs-style annotations (`// phpcs:ignore WordPress.DB…`) on intentional direct DB calls.

### 8.2 TypeScript / React
- **File naming**: `PascalCase.tsx` for components/routes, `camelCase.ts` for utilities; component files default-export a named component.
- **Types**: `types.ts` holds all shared interfaces; `interface` for objects; explicit `| null | undefined` unions; no `any`.
- **Import ordering**: node/react first, then third-party (alphabetical within groups), then `@wordpress/i18n`, then local `../` imports — enforced manually (no import-order plugin), MUI icon imports first in `AdminLayout`.
- **Function components** with hooks; no class components except `ErrorBoundary`.
- **Hooks**: `useQuery`/`useMutation` from TanStack; custom hooks named `useXxx`; context hooks throw when used outside provider.
- **Styling**: MUI `sx` prop (theme-aware, no inline style strings); `sx` callbacks receive `theme`; shared CSS only in `styles.css` scoped to the mount point.
- **i18n**: `__('string', 'wooinsights')` in every user-facing string.
- **Strict TS**: `noUnusedLocals`, `noUnusedParameters`, `strict: true` — unused imports/vars fail `tsc`.
- **Formatting**: Prettier defaults per `.prettierrc.json`.

## 9. Asset Conventions

- **Admin app bundle**: `admin-app/dist/` built by Vite; enqueued only on the plugin's own admin screen (`toplevel_page_wooinsights`); version = entry file `filemtime`; `type="module"` enforced.
- **Boot data**: single `wp_localize_script` object (`window.wooinsights`) — never multiple inline scripts scattered around.
- **Frontend JS**: hand-written vanilla JS in `assets/js/` (no build step), enqueued with `WOOINSIGHTS_VERSION` + `in_footer`, guarded to never throw; config passed via a single inline script tag (`window.wooinsights`) printed early in `wp_footer`.
- **Admin CSS**: `assets/css/admin-fullscreen.css` enqueued on the plugin screen.
- **i18n assets**: `languages/*.pot|.po|.mo|*.json`; `Domain Path: /languages` in the header.
- **Hashing**: production JS/CSS filenames hashed by Vite; version cache-busting via filemtime.
- **RTL**: `dir` attribute set on the mount point by PHP; flip handled client-side (theme + stylis).

## 10. Security Conventions

| Layer | Convention |
|---|---|
| PHP | `ABSPATH` guard on every file; `sanitize_text_field`/`esc_url_raw`/`absint`/`sanitize_key` on all input (after `wp_unslash`); `esc_html`/`esc_attr`/`wp_kses_post` on output |
| SQL | Prepared statements everywhere (`$wpdb->prepare`); table/column names only from the fixed `Schema::tables()` list (never user input); FK/DDL guarded by existence checks |
| REST | Capability checks via `permission_callback` (`manage_options`), per-user rate limiting, REST `args` schema validation + sanitize callbacks, nonce verification on public endpoints |
| AJAX | `check_ajax_referer` before any action |
| Frontend | Nonce printed once server-side; XHR sends nonce header; server resolves product IDs from URLs (does not trust client IDs); session IDs regex-validated (`^[a-f0-9]{32}$`) |
| Privacy | IPs hashed with HMAC-SHA256 + `wp_salt` (opt-in), guest/member exclusions, no PII beyond what's needed, retention purge |
| Capabilities | Menu/API capability filterable (`wooinsights_admin_capability`, `wooinsights_rest_capability`) |
| Cookies | HttpOnly, SameSite=Lax, Secure on SSL |

## 11. Reusable Patterns (must adopt for FaraCart)

1. **Bootstrap**: single main file → constants → Composer autoload → `Plugin::instance()->boot()` at file scope.
2. **DI container + HookManager**: constructor-injected singleton services; components declare hooks via `register(HookManager)`; one `run()` applies all.
3. **Schema + Installer**: central `Schema` (table list, create statements, FK definitions) + `Installer` (activate/deactivate/maybe_upgrade/uninstall, cron scheduling, version-driven migrations, dbDelta + idempotent FK ALTERs).
4. **Settings service**: one option + defaults + in-memory cache; validated partial saves via REST schema + per-key sanitizer.
5. **REST BaseController**: namespace constant, `{data, meta, pagination}` envelope, shared arg schema, date-range resolution, rate limiting, transient cache, `wooinsights_*` error codes.
6. **Admin + AssetLoader**: menu registration, screen-scoped enqueue, Vite manifest consumption, dev-server HMR detection, `window.<slug>` boot data, `wp_set_script_translations`.
7. **React shell**: `main.tsx` mount → `boot.ts` → hash data router with lazy routes → `AdminLayout` → per-domain `api/` modules over `apiFetch` → TanStack Query + context → MUI theme with WP palette + RTL.
8. **Frontend config pattern**: single inline `window.<slug>` object (nonce, endpoints, session) printed early, consumed by vanilla JS with a must-never-throw contract.
9. **Cron pattern**: timezone-aware scheduling helpers (`wp_timezone`, `DateTimeImmutable`), `wp_next_scheduled` guards, custom intervals registered persistently, deactivation clears events only.
10. **WC integration**: `before_woocommerce_init` HPOS declaration; `wc_*` functions guarded by `function_exists`; site-timezone `current_time()` datetimes everywhere.
11. **i18n pipeline**: PHP text domain + POT + MO; React via `wp.i18n` shim + JED JSON + extraction/build/verify scripts; RTL support end-to-end.
12. **Utils pattern**: static helper classes (`Helpers`, `Formatting`) for shared sanitization/formatting; `number_format_i18n`, `wc_price`, `date_i18n`.
13. **Pluggable template engine (FaraCart pattern, extends the registry
    convention)**: a `Template` contract — stable id, translated
    label/description, scope (goal | campaign | both), a settings
    `schema` (field type, default, validation, label, group) and a
    `version` — plus a lazy, filterable `TemplateRegistry` (the same
    class-map convention as the goal-evaluator / reward-applicator
    registries, filterable via `faracart_template_classes`) and a
    resolver (`TemplateEngine`) with a documented resolution order
    (item override → scope default → legacy → fallback) and
    schema-driven settings sanitization. The backend is the source of
    truth for registered templates + schemas (REST); the React app maps
    template ids to renderer components (`templates/registry.tsx`) and
    builds the settings form generically from the schema
    (`templates/SchemaForm.tsx`). Adding a new template touches only the
    registry — never the Settings UI, builders, REST layer or preview.

## 12. Patterns That MUST NOT Be Copied

1. **WooInsights business logic**: search tracking, session cookies, funnel/opportunity/quality/typo/synonym/brand/segment/device analytics, notification rules — FaraCart implements its own domain (goals, rewards, campaigns, cart progress).
2. **Table schemas**: the 8 analytics tables are WooInsights-specific; FaraCart defines its own schema following the same *style* (prefix, dbDelta, indexes, FK pattern).
3. **Settings keys**: `wooinsights_settings` option contents are search-analytics settings; FaraCart has its own settings model.
4. **The `admin/` and `public/` empty-placeholder dirs** (`.gitkeep`) — legacy from the reference's scaffolding; FaraCart may omit them.
5. **`docs/user/`** structure unless FaraCart ships user docs.
6. **The reference's own `AGENT.md` roadmap content** (tasks/IDs referenced in comments like "Task 2.4") — FaraCart's roadmap is its own.
7. **Icons/menu position/copy**: `dashicons-chart-area` at position 58 and all UI copy are WooInsights-specific choices.
8. **"No tests" status**: WooInsights has no test suite; FaraCart must establish its own testing strategy (Phase 24) rather than mirroring the absence.

---

## Acceptance Criteria Checklist (Phase 0)

- [x] Reference plugin inspected (`/home/qbicfox/public_html/woo-app/wp-content/plugins/wooinsights` exists, readable, version 0.1.0).
- [x] Architecture documented (this report + `docs/reference-plugin-file-inventory.md`).
- [x] No major structural assumption remains undocumented (bootstrap, DI, hooks, DB, REST, React, build, i18n, security, cron, WC integration covered).
- [x] New plugin architecture can be derived from this report (Phase 2 can scaffold FaraCart following §1–§11).
- [x] Reference plugin NOT modified.
