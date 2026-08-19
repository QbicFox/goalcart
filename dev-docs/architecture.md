# FaraCart — Architecture

## Overview

FaraCart is a WooCommerce plugin that increases Average Order Value by showing cart missions, progress bars, rewards, milestones, and smart product suggestions. It is built following the WooInsights reference plugin's architectural conventions.

## Directory Structure

```
ravis-faracart/
├── ravis-faracart.php          # Main bootstrap (plugin header + constants + boot)
├── uninstall.php               # Uninstall handler
├── composer.json               # PSR-4 autoload
├── includes/                   # All PHP classes (FaraCart\ namespace)
│   ├── Plugin.php              # Singleton bootstrap
│   ├── Container.php           # DI container
│   ├── Compatibility.php       # WP/WC/PHP version gates
│   ├── Admin/                  # Admin menu + asset loading
│   ├── Analytics/              # Revenue attribution, recommendations, upsells
│   ├── Campaigns/              # Campaign CRUD + repository
│   ├── Cart/                   # CartContext + CartIntegration
│   ├── Database/               # Schema + Installer (migrations)
│   ├── Frontend/               # ProgressUI (storefront widgets)
│   ├── Hooks/                  # HookManager
│   ├── Missions/               # MissionEngine, evaluators, ConflictResolver
│   ├── Recommendations/        # ProductRecommendationEngine (suggestions + upsells)
│   ├── Rewards/                # RewardEngine, applicators, safety
│   ├── REST/                   # REST controllers
│   ├── Settings/               # Settings service
│   ├── Suggestions/            # SuggestionEngine (Phase 14)
│   ├── Templates/              # Pluggable template engine (TemplateRegistry, TemplateEngine)
│   └── Utils/                  # Static helpers
├── admin-app/                  # React admin SPA (Vite + TS + MUI)
│   ├── src/
│   │   ├── main.tsx / boot.ts / App.tsx / types.ts
│   │   ├── api/                # API client + per-domain fetchers
│   │   ├── components/         # layout/, mission-builder/, preview/, shared
│   │   ├── routes/             # One file per page (lazy-loaded)
│   │   ├── templates/          # Template renderers + SchemaForm
│   │   ├── lib/                # format, wp-i18n shim
│   │   ├── providers/          # AppProviders, FullscreenProvider
│   │   └── theme/              # MUI theme factory
│   └── dist/                   # Build output
├── assets/
│   ├── js/frontend.js          # Storefront widget library (vanilla JS)
│   └── css/frontend.css        # Widget styles
├── languages/                  # .pot / .po / .mo / JED .json
├── tests/                      # PHP test suites
└── docs/                       # Internal developer docs
```

## PHP Architecture

### Bootstrap

1. `ravis-faracart.php` defines constants (`FARACART_VERSION`, `FARACART_FILE`, `FARACART_PATH`, `FARACART_URL`, `FARACART_BASENAME`, `FARACART_DB_VERSION`).
2. Loads Composer autoloader.
3. Calls `FaraCart\Plugin::instance()->boot()` at file scope.
4. `Plugin::boot()` registers activation/deactivation hooks, schedules migrations, loads textdomain, declares HPOS compatibility, and runs `HookManager::register()` + `run()`.

### Dependency Injection

Lightweight hand-rolled container (`Container`): `set()`, `singleton()`, `factory()`, `make()` with reflection autowiring. All services registered as singletons in `Plugin::register_services()`.

### Hook Management

Every component implements `register(HookManager $hooks)` and calls `$hooks->add_action()` / `add_filter()`. Registration is buffered and applied in one `run()` call.

### Layering

| Layer | Classes |
|---|---|
| REST | `REST/*Controller` — thin, extend `BaseController`, delegate to services |
| Service | `Analytics/*`, `Recommendations/*`, `Missions/*Engine`, `Rewards/*Engine` |
| Repository | `CampaignRepository`, `MissionRepository`, `RevenueRepository` |
| Database | `Schema` (definitions) + `Installer` (migrations) |
| Utils | Static helpers (`Formatting`, `Helpers`) |

### Settings

Single `faracart_settings` option, defaults + `wp_parse_args` merge, in-memory cache, `get($key, $default)`. REST saves only known keys with per-key sanitization.

## React Architecture

### Entry & Boot

- `main.tsx` mounts on `#faracart-admin`.
- `boot.ts` reads `window.faracart` (nonce, REST base, user, locale, isRtl, currency).
- Hash-based router (`createHashRouter`), all non-dashboard routes lazy-loaded.

### State Management

- **Server state:** TanStack Query 5 (retry 1, 60s staleTime).
- **Local state:** React hooks.
- **Global UI:** React Context (`DateRangeContext`, `FullscreenContext`).
- **Forms:** react-hook-form with `Controller`.
- No Redux/Zustand.

### API Client

Single `apiFetch<T>(path, options, unwrap)`: prepends REST base, sends `X-WP-Nonce`, parses JSON, throws typed `ApiError`, unwraps `{ data, meta, pagination }` envelope.

### Theme & RTL

- WP admin palette (primary `#2271b1`).
- `dir="rtl"` on mount point, MUI theme direction, Emotion cache with `@mui/stylis-plugin-rtl`.
- No CssBaseline — scoped resets in `styles.css` under `#faracart-admin`.

## Build Architecture

| Concern | Decision |
|---|---|
| Bundler | Vite 5 (`@vitejs/plugin-react`) |
| Entry | `admin-app/src/main.tsx` |
| Output | `admin-app/dist/`, manifest + sourcemap |
| TypeScript | strict; `tsc --noEmit` in build |
| ESLint | flat config: TS recommended + react-hooks + react-refresh |
| Prettier | `semi`, `singleQuote`, `printWidth: 100` |
| Package manager | npm (lockfile committed) |
| Aliases | `@` → `src/`; `@wordpress/i18n` → `src/lib/wp-i18n.ts` |

### WordPress Enqueue

`AssetLoader` reads the Vite manifest, enqueues hashed entry JS with `filemtime` version, forces `type="module"`, depends on `['wp-i18n']`. Dev mode detects the Vite dev server for HMR.

## Database Architecture

- MySQL via `$wpdb`, InnoDB, `$wpdb->get_charset_collate()`.
- Table naming: `{$wpdb->prefix}faracart_<name>`.
- 9 tables (see `docs/database.md`).
- dbDelta-friendly `CREATE TABLE`, foreign keys via idempotent `ALTER TABLE`.
- Version-driven migrations: `FARACART_DB_VERSION` vs `faracart_db_version` option.
- No foreign keys into WooCommerce tables (HPOS-safe).

## Security

- `ABSPATH` guard on every PHP file.
- Prepared statements everywhere.
- REST: capability checks (`manage_options`, filterable), arg-schema validation, rate limiting.
- Public endpoints: plugin-specific nonce verification.
- React: never renders untrusted HTML, `X-WP-Nonce` on API calls.
- Cookies: HttpOnly, SameSite=Lax.
