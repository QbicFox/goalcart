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
    },
  },
  server: {
    host: 'localhost',
    port: 5173,
    strictPort: true,
  },
});
