# jf-website-astro

Astro-everything build for JF Self Defense (jfselfdefense.com). Parent
marketing pages + Sanity-backed `/articles/` section + booking form in a
single project, deployed in one shot to Hostinger.

## Stack
- **Astro** static build
- **Sanity** CMS (project `pe441y01`) — Article + Category document types
- **@astrojs/sitemap** — unified `sitemap-index.xml`
- **GitHub Actions** → SCP to Hostinger on Sanity publish

## Local dev

```bash
npm install
npm run dev
# → http://localhost:4321
```

Set `SANITY_TOKEN` in `.env` (viewer token) so the build can fetch articles.
See `.env` for the canonical shape.

## Build

```bash
npm run build
# → dist/
```

## Deploy

**Auto:** push to `main`, OR publish in Sanity Studio. CI runs
`.github/workflows/deploy.yml`.

**Manual:** `pwsh ./deploy.ps1` from Windows. Requires the
`Hostinger-site-deploy` SSH key at `~/.ssh/`.

## Staging vs production

- Staging URL: `https://lightskyblue-camel-545209.hostingersite.com/`
  (Hostinger-issued staging subdomain, current deploy target)
- Production URL: `https://jfselfdefense.com/` (DNS pending Exabytes unlock)

Override the canonical URL at build time with `SITE_URL=...` env. The
GitHub Action will pick it up from a `SITE_URL` repository secret once it
exists.

## Sanity Studio

`https://chiawei-studio.sanity.studio/jf` — shared Studio, `jf` workspace.
Schema lives in the sibling `dhp-blog-astro` repo (multi-brand Studio).
