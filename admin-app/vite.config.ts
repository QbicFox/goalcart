import { fileURLToPath, URL } from 'node:url';

import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/**
 * Vite configuration for the Goal Cart admin app.
 *
 * - `build.manifest` writes `.vite/manifest.json` so the PHP asset loader
 *   (`includes/Admin/AssetLoader.php`) can enqueue the hashed entry assets.
 * - `@wordpress/i18n` is aliased to a tiny shim that delegates to the
 *   `wp.i18n` global shipped by WordPress core — translations loaded via
 *   `wp_set_script_translations()` land straight in `wp.i18n.setLocaleData`.
 * - React, MUI, TanStack Query and React Router are bundled into the app
 *   (no `wp.element` dependency) to avoid conflicts with other admin plugins.
 *
 * Phase 23 (Performance → minimize bundle size): `manualChunks` splits the
 * heavy, rarely-changing vendors into their own cacheable chunks so the
 * entry and the route chunks stay small and browser caches survive app
 * releases. The reference plugin (WooInsights) ships the same minimal
 * config without manualChunks — a documented deviation (AGENT.md rule 15)
 * driven by the Phase 23 roadmap requirement to minimize bundle size; the
 * routing/base/manifest architecture is unchanged, only chunk grouping is
 * added. Module-groups use the id-prefix function form so Rollup resolves
 * transitive vendor deps into their owning group automatically.
 */
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '@wordpress/i18n': fileURLToPath(new URL('./src/lib/wp-i18n.ts', import.meta.url)),
    },
  },
  // Relative base: the plugin can live at any URL (WordPress installs in
  // subdirectories, e.g. /woo-app/wp-content/plugins/goalcart/), so asset
  // URLs must resolve against the chunk's own location via import.meta.url
  // rather than the site root.
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    rollupOptions: {
      input: 'src/main.tsx',
      // Phase 23: vendor chunk groups. Module ids are absolute paths from
      // node_modules; the prefix function assigns each to a stable group so
      // React, MUI, TanStack, recharts and the date pickers each live in a
      // separate cacheable chunk instead of one ~600 kB main bundle.
      output: {
        manualChunks(id: string) {
          if (id.includes('node_modules')) {
            // Order matters: react-router must win over react, the date
            // pickers over mui, and Emotion over the broad react match.
            if (id.includes('react-router')) return 'router';
            if (id.includes('@tanstack')) return 'query';
            if (id.includes('recharts')) return 'charts';
            if (id.includes('@mui/x-date-pickers') || id.includes('dayjs')) return 'pickers';
            if (id.includes('@emotion')) return 'mui'; // MUI's styling engine.
            if (id.includes('@mui')) return 'mui';
            if (id.includes('react') || id.includes('scheduler') || id.includes('use-sync-external-store')) return 'react';
            return 'vendor';
          }
          return undefined;
        },
      },
    },
  },
  server: {
    host: 'localhost',
    port: 5173,
    strictPort: true,
  },
});
