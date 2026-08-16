# FaraCart for WooCommerce

FaraCart increases Average Order Value (AOV) by showing WooCommerce shoppers cart goals, progress
bars, rewards, milestones, and smart product suggestions that close the gap to the next reward.

> **Note:** This plugin is under active development following the roadmap in `AGENT.md`.

## Requirements

- WordPress 6.3+ (for `type="module"` script enqueueing)
- WooCommerce 8.0+
- PHP 7.4+

## Development Setup

```bash
composer install        # Generate the PSR-4 autoloader (GoalCart\ => includes/)
```

## Admin App (React)

The admin dashboard is a React SPA built with Vite + TypeScript + MUI, located in `admin-app/`
(the full shell is built in Phase 8; the foundation phase ships the build stack + mount point).

```bash
cd admin-app
npm install
npm run dev        # start the Vite dev server (HMR) — see below
npm run build      # production build → admin-app/dist/
npm run lint       # ESLint
npm run format     # Prettier
npm run typecheck  # TypeScript (no emit)
```

**Development workflow (HMR inside WP admin):**

1. Start the Vite dev server: `cd admin-app && npm run dev` (default `http://localhost:5173`).
2. Load the **FaraCart** admin page while WordPress runs with an environment type of `local` or
   `development` — the plugin auto-detects the dev server and enqueues the app straight from it.
3. If your dev server is not on `localhost:5173`, define the `GOALCART_DEV_SERVER_URL` constant.

**Production:** `npm run build` emits a hashed bundle plus a Vite manifest
(`admin-app/dist/.vite/manifest.json`) that the PHP asset loader (`includes/Admin/AssetLoader.php`)
reads to enqueue the correct files.

**Boot data:** the PHP side localizes `window.goalcart` (REST nonce, API base, current user, caps,
locale) so the app can authenticate REST calls via the `X-WP-Nonce` header.

## Directory Structure

```
ravis-faracart/
├── ravis-faracart.php    # Main plugin file (bootstrap)
├── composer.json         # Composer config + PSR-4 autoloader
├── includes/             # Core plugin classes (GoalCart\ namespace)
│   ├── Plugin.php        # Singleton bootstrap + DI wiring
│   ├── Container.php     # Dependency injection container
│   ├── Compatibility.php # WP/PHP/WC environment gate
│   ├── Admin/            # Admin menu + asset loading (React mount)
│   ├── Database/         # Schema + Installer (activation/migrations)
│   ├── Hooks/            # HookManager
│   └── Settings/         # Settings service
├── admin-app/            # React admin SPA (Vite + TS + MUI)
├── assets/css/           # Admin CSS
├── languages/            # Translations (POT/PO/MO/JED)
├── tests/                # Tests (Phase 24)
└── docs/                 # Roadmap, architecture, product spec
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
