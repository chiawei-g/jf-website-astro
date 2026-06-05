import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Production: jfselfdefense.com (when DNS cuts over from Exabytes).
// Staging:    lightskyblue-camel-545209.hostingersite.com
// Override via SITE_URL env at build time so CI can deploy to either.

const SITE_URL = process.env.SITE_URL || 'https://lightskyblue-camel-545209.hostingersite.com';

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
