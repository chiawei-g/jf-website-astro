import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Production: jfselfdefense.com (default — fail-safe if SITE_URL is unset).
// Staging:    lightskyblue-camel-545209.hostingersite.com
// Override via SITE_URL env at build time to build for staging instead.

const SITE_URL = process.env.SITE_URL || 'https://jfselfdefense.com';

export default defineConfig({
  site: SITE_URL,
  trailingSlash: 'ignore',
  output: 'static',
  integrations: [sitemap()],
  build: {
    inlineStylesheets: 'always',
  },
  server: {
    port: 4321,
    host: true,
  },
});
