import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import { loadArticleLastmodMap } from './scripts/sitemap-lastmod.mjs';

// Production: jfselfdefense.com (default — fail-safe if SITE_URL is unset).
// Staging:    lightskyblue-camel-545209.hostingersite.com
// Override via SITE_URL env at build time to build for staging instead.

const SITE_URL = process.env.SITE_URL || 'https://jfselfdefense.com';

// /articles/<slug>/ -> Sanity _updatedAt, resolved once at config load.
// Fails soft to {} so a Sanity hiccup never breaks a deploy.
const lastmodByPath = await loadArticleLastmodMap();

const toPath = (loc) => {
  try {
    let p = new URL(loc).pathname;
    if (!p.endsWith('/')) p += '/';
    return p;
  } catch {
    return loc;
  }
};

export default defineConfig({
  site: SITE_URL,
  trailingSlash: 'ignore',
  output: 'static',
  integrations: [
    sitemap({
      serialize: (item) => {
        const lm = lastmodByPath[toPath(item.url)];
        if (lm) item.lastmod = lm;
        return item;
      },
    }),
  ],
  build: {
    inlineStylesheets: 'always',
  },
  server: {
    port: 4321,
    host: true,
  },
});
