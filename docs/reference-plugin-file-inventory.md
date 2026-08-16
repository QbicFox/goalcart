# Reference Plugin — Complete File Inventory

> **Phase 0 / Task 0.2** — Generated for the FaraCart project.
> Reference plugin: `/home/qbicfox/public_html/woo-app/wp-content/plugins/wooinsights` (v0.1.0).
> Site context: WordPress 7.0.2, WooCommerce 11.0.0, PHP ≥ 7.4.

`node_modules/`, `vendor/` internals (Composer autoloader only), `admin-app/dist/` (build output) and `.git/` are
excluded from the detailed listing but noted where relevant.

---

## 1. Root files

| File | Purpose |
|---|---|
| `wooinsights.php` | Main plugin bootstrap. Plugin header (`Requires at least: 6.3`, `Requires PHP: 7.4`, `WC requires at least: 8.0`, `WC tested up to: 11.0`, Text Domain `wooinsights`, Domain Path `/languages`). Defines constants `WOOINSIGHTS_VERSION`, `WOOINSIGHTS_FILE`, `WOOINSIGHTS_PATH`, `WOOINSIGHTS_URL`, `WOOINSIGHTS_BASENAME`, `WOOINSIGHTS_DB_VERSION`. Loads `vendor/autoload.php` and boots `WooInsights\Plugin::instance()->boot()` at file scope. |
| `composer.json` | Composer config: `type: wordpress-plugin`, PSR-4 `WooInsights\ => includes/`, `autoload-dev` `WooInsights\Tests\ => tests/`, `optimize-autoloader`, `sort-packages`, requires PHP ≥ 7.4 only (no runtime packages). |
| `uninstall.php` | WordPress uninstall handler: loads the Composer autoloader via `__DIR__` (rename-safe), calls `WooInsights\Database\Installer::uninstall()` (drops all tables + options). |
| `README.md` | Setup/build/i18n documentation for the plugin. |
| `AGENT.md` | The plugin's own AI-agent execution roadmap (same pattern as FaraCart). |
| `.editorconfig` | Tabs for PHP, 2-space for YAML/JSON, UTF-8, LF, final newline, trim trailing whitespace. |
| `.gitignore` | Ignores `/vendor/`, `composer.lock`, `*.log`, `.DS_Store`, IDE dirs, `.phpunit.result.cache`, `coverage/`, `admin-app/node_modules/`, `admin-app/dist/`, `*.eslintcache`. |

## 2. `includes/` — PHP classes (namespace `WooInsights\`, 36 files)

### 2.1 Core

| File | Purpose |
|---|---|
| `Plugin.php` | Singleton bootstrap. Builds the DI container, resolves every service, registers activation/deactivation + migration hooks, `cron_schedules` filter, HPOS feature declaration (`before_woocommerce_init`), text-domain loading, registers every component's hooks through `HookManager`, then `run()`. Fires `wooinsights_loaded` action. |
| `Container.php` | Lightweight DI container: `set`/`singleton`/`factory`/`bind` + reflection autowiring via `make()`, `get()`, `has()`. |

### 2.2 `includes/Admin/`

| File | Purpose |
|---|---|
| `Admin.php` | Top-level admin menu (`wooinsights`, `dashicons-chart-area`, position 58, capability filter `wooinsights_admin_capability`), enqueues assets only on its page (`toplevel_page_wooinsights`), body-class management (`wooinsights-admin-page` / `wooinsights-fullscreen`), build-missing admin notice, React mount point `#wooinsights-admin` with explicit `dir` attribute. |
| `AssetLoader.php` | Enqueues the Vite-built React app: production reads `admin-app/dist/.vite/manifest.json` (hashed entry JS/CSS, filemtime cache-busting version), development auto-detects the Vite dev server (`localhost:5173`, https-first on SSL, overridable via `WOOINSIGHTS_DEV_SERVER_URL` constant). Localizes `window.wooinsights` boot data (nonce, restBase, restUrl, adminUrl, homeUrl, siteName, locale, isRtl, currency, currentDate, userId, user, caps, version, isPro, fullscreen) and wires `wp_set_script_translations()`. |

### 2.3 `includes/Database/`

| File | Purpose |
|---|---|
| `Schema.php` | Central table definitions: `TABLE_PREFIX = 'wooinsights_'`, `table()` (prefixed name), `tables()` (8 tables), `create_statements()` (dbDelta `CREATE TABLE`), `foreign_keys()` (FK definitions applied via `ALTER TABLE`). |
| `Installer.php` | Activation/deactivation/uninstall + version-driven migrations. `activate()` creates tables, sets `wooinsights_db_version`, schedules crons. `deactivate()` clears scheduled events (data preserved). `maybe_upgrade()` on `plugins_loaded` + `admin_init` compares `wooinsights_db_version` option. Schedules daily report (02:00), cleanup (03:00), notify crons; registers `wooinsights_weekly` interval. FK creation is idempotent via `INFORMATION_SCHEMA` checks. |

### 2.4 `includes/Hooks/`

| File | Purpose |
|---|---|
| `HookManager.php` | Buffered hook registration. Components implement `register( HookManager $hooks )`; the manager applies everything in one `run()` call. `add_action`, `add_filter`, `register($component)`. |

### 2.5 `includes/Settings/`

| File | Purpose |
|---|---|
| `Settings.php` | Settings service: single option `wooinsights_settings`, defaults merged with stored values (`wp_parse_args`), in-memory cache, `all()/get()/set()/set_many()/save()/reset()/defaults()`. Registers no hooks itself. |

### 2.6 `includes/Tracker/`

| File | Purpose |
|---|---|
| `Tracker.php` | Frontend search tracking core. `pre_get_posts`/`found_posts`/`shutdown` native search capture; `wp_redirect` single-result click capture; referer-based product-page search recovery (`wp`); AJAX search tracking (`wp_ajax_(nopriv_)wooinsights_track_search`); `woocommerce_add_to_cart` funnel tracking; `woocommerce_thankyou` conversion attribution (24h window, idempotent); frontend config print (`window.wooinsights`) in `wp_footer`; `assets/js/search-tracker.js` enqueue; `is_tracking_allowed()` with settings + `wooinsights_tracking_enabled` filter. |
| `Session.php` | Cookie-based anonymous sessions: `wooinsights_session` cookie (32-char hex, HttpOnly, SameSite=Lax, sliding expiry), server-side staleness check, converted-session markers (`wooinsights_converted_sessions` option) with daily cleanup. |

### 2.7 `includes/REST/`

| File | Purpose |
|---|---|
| `BaseController.php` | Abstract REST foundation: namespace `wooinsights/v1`, capability `manage_options` (+`wooinsights_rest_capability` filter), shared arg schema (`date_from`, `date_to`, `compare`, `page`, `per_page`, `orderby`, `order`), `success()/paginated()/error()` envelope helpers, date validation, date-range resolution (defaults last 30 days), fixed-window per-user rate limiting (transients), transient response cache (`with_cache()`). |
| `ClickController.php` | Public `POST /track/click` — nonce-guarded (own tracking nonce instead of capability), resolves clicked URL to a published product server-side, attributes to the session's search log. |
| `DataController.php` | `GET /data/status`, `POST /data/cleanup`, `POST /data/export` — DB size indicator, retention purge, full-table export. |
| `NotificationController.php` | Notification rule CRUD + `POST /notifications/test`. |
| `ReportController.php` | All report endpoints (see API architecture): overview, regenerate, top-searches, zero-results (+create-product), out-of-stock-demand, funnel, keywords, trends, opportunities, lost-revenue, quality, typo-suggestions (+review), categories, brands, segments, devices. |
| `SettingsController.php` | `GET/POST /settings` — validated partial save against known keys, per-key sanitizer, roles list in meta, flushes REST caches on save. |
| `SynonymController.php` | `GET /synonyms`, `POST /synonyms/import`, `GET /synonyms/suggestions`, `POST /synonyms/export`. |
| `SystemController.php` | `GET /system` — system status/health info. |

### 2.8 `includes/Reports/` (12 report services)

| File | Purpose |
|---|---|
| `DailyReportGenerator.php` | Pre-computes daily report rows via cron (`wooinsights_daily_report`), range generation, per-day aggregation, `latest_report`, `missing_days`, generation stamp. |
| `OutOfStockReport.php` | Out-of-stock demand report (product stock × search demand). |
| `FunnelReport.php` | Search → click → add-to-cart → purchase funnel. |
| `TrendReport.php` | Volume-over-time series, growth vs previous period, trending/declining keywords, seasonality (weekday/monthly/peak days). |
| `OpportunityReport.php` | Sales opportunities (missing product / out-of-stock / low-conversion), scoring 0-100. |
| `LostRevenueReport.php` | Estimated lost revenue (zero-result terms priced with category/store AOV + out-of-stock). |
| `QualityReport.php` | Keyword quality scoring (CTR, conversion, exit rate, time-to-click), bands. |
| `TypoReport.php` | Typo-correction suggestions (pattern/behavior/similarity sources), review statuses. |
| `CategoryReport.php` | Smart category demand report + under-searched suggestions. |
| `BrandReport.php` | Brand analytics extracted from search terms. |
| `SegmentReport.php` | Visitor segments (guest/member/new/returning) behavior stats. |
| `DeviceReport.php` | Device analytics + responsive-UX recommendations. |

### 2.9 `includes/Data/`

| File | Purpose |
|---|---|
| `Cleanup.php` | Data management service: daily retention purge cron (`wooinsights_cleanup`), `purge()`, `status()` (SHOW TABLE STATUS per-table size), `export_all()`, flushes REST transients. |
| `SynonymManager.php` | Synonym group storage, starter Persian defaults, similarity detection, import, search-query expansion. |

### 2.10 `includes/Notifications/`

| File | Purpose |
|---|---|
| `Notifier.php` | Notification engine: rule-driven alert emails (daily summary, zero-result spike, opportunities, low conversion) on daily/weekly crons, per-rule recipients, `last_sent_at`. |

### 2.11 `includes/Utils/`

| File | Purpose |
|---|---|
| `Helpers.php` | Static utilities: `is_woocommerce_active()`, `is_published_product()`, `is_ajax()`, `sanitize_search_term()`, `hash_ip()` (HMAC-SHA256 with `wp_salt`), `current_datetime()`, `is_mobile()`, `detect_device_type()`. |
| `Formatting.php` | `number()` (number_format_i18n), `currency()` (wc_price when available), `percent()`, `date()` (date_i18n), `duration()` (_n pluralization). |
| `Jalali.php` | Persian (Jalali) calendar conversions used by the admin UI. |

## 3. `assets/` — static frontend/admin assets

| File | Purpose |
|---|---|
| `css/admin-fullscreen.css` | Admin-only CSS: drops WP admin chrome (`#wpbody` padding, admin bar/menu/notices) when the `wooinsights-fullscreen` body class is present; keeps the build notice visible. |
| `js/search-tracker.js` | Vanilla JS (no deps) search-result click tracker for the storefront: delegated click listener on `ul.products, ul.woocommerce-loop, [data-wooinsights-results]`, position detection, sendBeacon→XHR fallback, debounce/in-flight dedupe, must-never-throw contract. |
| `img/.gitkeep`, `css/.gitkeep`, `js/.gitkeep` | Empty placeholder dirs. |

## 4. `languages/` — i18n

| File | Purpose |
|---|---|
| `wooinsights.pot` | English source template (generated by `npm run makepot` / `i18n:extract`). |
| `wooinsights-fa_IR.po` / `.mo` | Persian source + compiled MO for PHP (`load_plugin_textdomain`). |
| `wooinsights-fa_IR-wooinsights-admin.json` | JED JSON for the React app, consumed via `wp_set_script_translations()` → `wp.i18n.setLocaleData()` (handle-based naming, WP 6.5+). |

## 5. `admin-app/` — React admin application (Vite + TypeScript + MUI)

### 5.1 Config & build

| File | Purpose |
|---|---|
| `package.json` | npm package `wooinsights-admin` (v0.1.0, private, Node ≥ 18, `"type": "module"`). Scripts: `dev`, `build` (`tsc --noEmit && vite build`), `preview`, `typecheck`, `lint`, `lint:fix`, `format`, `format:check`, `makepot`, `i18n:extract`, `i18n:build`, `i18n:verify`, `i18n:all`. Dependencies: React 18, MUI 6 (+icons, x-data-grid, x-date-pickers, stylis RTL plugin), TanStack Query 5, react-router-dom 6, react-hook-form, recharts, dayjs, emotion. Dev: Vite 5, TypeScript 5.7, ESLint 9 (flat), typescript-eslint, prettier. |
| `package-lock.json` | Lockfile. |
| `vite.config.ts` | Vite config: `base: './'` (subdirectory-safe relative asset URLs), `build.outDir: dist`, `emptyOutDir`, `manifest: true`, `sourcemap: false`, rollup input `src/main.tsx`, `@` alias → `src/`, `@wordpress/i18n` alias → `src/lib/wp-i18n.ts` shim, dev server `localhost:5173` strictPort. |
| `tsconfig.json` | TS strict mode, `target ES2020`, `moduleResolution: bundler`, `jsx: react-jsx`, `noUnusedLocals/Parameters`, `noEmit`, `types: [vite/client, node]`, `@/*` and `@wordpress/i18n` path aliases. |
| `eslint.config.js` | ESLint 9 flat config: TS recommended + react-hooks + react-refresh + jsx-a11y, ignores `dist`/`node_modules`. |
| `.prettierrc.json` | `semi: true`, `singleQuote: true`, `printWidth: 100`, `trailingComma: es5`. |
| `.prettierignore` | Prettier ignores. |
| `index.html` | Dev entry HTML with `#wooinsights-admin` mount + `/src/main.tsx` module script. |

### 5.2 i18n scripts

| File | Purpose |
|---|---|
| `scripts/extract-i18n.mjs` | Extracts translatable strings from the app into the POT. |
| `scripts/build-i18n.mjs` | Builds the JED JSON locale files; fails on missing translations or dropped `%s` placeholders. |
| `scripts/verify-mo.php` | Cross-checks compiled MO against PO using WordPress's own pomo reader. |
| `scripts/translations-fa_IR.json` | Curated Persian translation source for the admin app. |

### 5.3 `src/` — application source

| File | Purpose |
|---|---|
| `main.tsx` | React entry: `createRoot` on `#wooinsights-admin`, renders `<StrictMode><AppProviders><App/></AppProviders></StrictMode>`, imports `./i18n` and `./styles.css`. |
| `boot.ts` | `getBootData()` — typed accessor for `window.wooinsights` boot data (cached). |
| `App.tsx` | `createHashRouter` data router (hash-based, `#/route`), `DateRangeProvider` + `AdminLayout` wrapper, lazy-loaded routes with `Suspense` skeleton fallback, index redirect to `/dashboard`, catch-all `NotFound`. |
| `types.ts` | All shared TypeScript interfaces: boot data, API envelope, settings, report row/meta types per endpoint. |
| `global.d.ts` | `window.wooinsights` global declaration. |
| `vite-env.d.ts` | Vite client types reference. |
| `styles.css` | Scoped CSS for `#wooinsights-admin` (box-sizing, font, loading placeholder). Explicitly no CssBaseline — resets are scoped to the mount point. |
| `api/client.ts` | `apiFetch<T>()` — fetch helper: `X-WP-Nonce` header from boot data, `credentials: include`, JSON parsing, `ApiError` with status/code/data, unwraps the `{ data, meta, pagination }` envelope. |
| `api/reports.ts` | Report endpoints: `fetchOverview()`, `regenerateReports()`. |
| `api/reportPages.ts` | Paginated report fetchers + `PaginatedEnvelope`. |
| `api/settings.ts` | `fetchSettingsEnvelope()`, `saveSettings()`. |
| `api/data.ts` | Data management endpoints (status, cleanup, export). |
| `api/synonyms.ts` | Synonym endpoints. |
| `api/notifications.ts` | Notification rules endpoints. |
| `components/layout/AdminLayout.tsx` | App shell: header (AppBar, date-range filter, store link, user menu), responsive sidebar (permanent desktop Drawer / temporary mobile Drawer), accordion nav groups with persisted expand state, pinned footer (collapse-all + version chip), `ErrorBoundary`-wrapped `<Outlet/>`, full-screen vs embedded mode. `DRAWER_WIDTH = 260`. |
| `components/layout/navigation.ts` | `NAV_SECTIONS` (grouped nav: Overview / Search Reports / Insights / Administration) + `NAV_ITEMS` flat list; MUI icon per item. |
| `components/dashboard/KpiCard.tsx`, `SearchesChart.tsx`, `useDashboardData.ts` | Dashboard KPI cards, search chart, data hook. |
| `components/date-range/CustomRangePicker.tsx`, `DateRangeFilter.tsx` | Date-range filter UI (presets + custom). |
| `components/reports/ServerDataGrid.tsx` | Reusable server-side table wrapping MUI X DataGrid with loading/error/empty states, server pagination + sorting. |
| `components/reports/useServerGridData.ts` | Server-grid state hook: page/perPage/sort state, TanStack Query keyed on date range + paging, resets to page 1 on range change. |
| `components/reports/ReportPageHeader.tsx`, `CsvExportButton.tsx` (+`useCsvExport.ts`), `FunnelBars.tsx`, `ScoreGauge.tsx` | Shared report page chrome and visualization helpers. |
| `components/trends/TrendChart.tsx` | Trend chart component. |
| `components/EmptyState.tsx` | Reusable empty-state. |
| `components/ErrorBoundary.tsx` | Class error boundary with "Try again" reset. |
| `components/DailyReportsCard.tsx` | Daily reports overview card. |
| `date-range/DateRangeContext.tsx` | Global date-range provider (presets + custom, URL hash params sync, localStorage persistence, previous-period comparison). |
| `date-range/dateRange.ts`, `types.ts` | Range math (`presetRange`, `normalizeBounds`, `comparisonRange`, `isYmd`) and types. |
| `i18n/index.ts` | Re-exports `__`, `_n`, `_nx`, `_x`, `isRTL`, `setLocaleData`, `sprintf` from the `@wordpress/i18n` shim. |
| `lib/format.ts` | Frontend formatters: number, percent, currency (Intl), Jalali date/datetime, truncate, bucket labels, bytes. |
| `lib/jalali.ts` | Jalali calendar formatting (Persian digits + month names). |
| `lib/wp-i18n.ts` | Drop-in `@wordpress/i18n` shim delegating to the `wp.i18n` global. |
| `providers/AppProviders.tsx` | Global providers: Emotion cache (key `wooinsights`, RTL stylis flip), MUI ThemeProvider (WP palette), TanStack Query client (retry 1, staleTime 60s, no refetch on window focus), `FullscreenProvider`. No CssBaseline. |
| `providers/FullscreenProvider.tsx` | Owns the `wooinsights-fullscreen` body class live (initialized from boot data, no flash). |
| `theme/index.ts` | `createAppTheme()` — MUI theme with WP admin palette (`#2271b1` primary, `#f0f0f1` background, `#1d2327` text), direction from boot `isRtl`, system font stack, `disableElevation` buttons. |
| `routes/*.tsx` (20) | Dashboard, TopSearches, ZeroResults, OutOfStockDemand, Funnel, Keywords, Trends, Opportunities, LostRevenue, SearchQuality, TypoCorrections, Synonyms, CategoryInsights, BrandAnalytics, Segments, Devices, Notifications, Settings, DataManagement, NotFound. |

### 5.4 Build output

| Path | Purpose |
|---|---|
| `admin-app/dist/` | Production bundle (hashed JS/CSS chunks, code-split per route; git-ignored). |
| `admin-app/dist/.vite/manifest.json` | Vite manifest consumed by `AssetLoader::enqueue_production()`. |
| `admin-app/node_modules/` | npm dependencies (git-ignored). |

## 6. Other top-level dirs

| Path | Purpose |
|---|---|
| `tests/` | **Empty** — only `.gitkeep`. No PHPUnit config, no test files yet. `composer.json` reserves `WooInsights\Tests\` namespace for future use. |
| `vendor/` | Composer autoloader only (`autoload.php`, `composer/`); no runtime packages. |
| `admin/` | Empty placeholder (`.gitkeep`); no legacy admin PHP. |
| `public/` | Empty placeholder (`.gitkeep`); no separate frontend PHP. |
| `docs/user/` | User-facing docs (non-technical). |
| `.git/` | VCS history (reference only; must never be copied). |

## 7. Inventory summary

| Category | Count |
|---|---|
| PHP files (`includes/`) | 36 |
| PHP files (root) | 2 (`wooinsights.php`, `uninstall.php`; `admin-app/scripts/verify-mo.php` lives with the React app scripts) |
| React/TS/TSX source files (`admin-app/src/`) | 61 (62 files incl. `styles.css`) |
| Config files (root + admin-app) | 11 |
| Static assets | 2 (`assets/css/admin-fullscreen.css`, `assets/js/search-tracker.js`; i18n files are listed under `languages/`) |
| REST endpoints | 31 paths / 35 route registrations (namespace `wooinsights/v1`) |
| Database tables | 8 |
| WP-Cron events | 6 |
