/**
 * Build-time map of /articles/<slug>/ -> Sanity _updatedAt, for sitemap <lastmod>.
 *
 * scope: JF only. Read once at config load, so it costs a single GROQ query per
 *        build and nothing at request time.
 *
 * WHY (2026-08-05). The portfolio health audit found JF emitting no <lastmod> on
 * any sitemap entry and no dateModified in its Article schema. A sitemap without
 * lastmod gives a crawler no reason to recrawl a page that has genuinely changed,
 * which matters most on the one brand already inside the top-20 pool.
 *
 * Fails soft: any error returns {} and the sitemap simply builds without lastmod,
 * exactly as it did before. A broken analytics nicety must never break a deploy.
 */

const PROJECT_ID = process.env.SANITY_PROJECT_ID || 'pe441y01';
const DATASET = process.env.SANITY_DATASET || 'production';

export async function loadArticleLastmodMap() {
  try {
    const query = encodeURIComponent(
      '*[_type == "article" && defined(slug.current)]{"slug": slug.current, _updatedAt}',
    );
    const url = `https://${PROJECT_ID}.api.sanity.io/v2025-04-01/data/query/${DATASET}?query=${query}`;
    const headers = process.env.SANITY_TOKEN
      ? { Authorization: `Bearer ${process.env.SANITY_TOKEN}` }
      : undefined;
    const res = await fetch(url, { headers });
    if (!res.ok) {
      console.warn(`[sitemap-lastmod] Sanity returned ${res.status}; building without lastmod.`);
      return {};
    }
    const { result = [] } = await res.json();
    const map = {};
    for (const r of result) {
      if (r?.slug && r?._updatedAt) map[`/articles/${r.slug}/`] = r._updatedAt;
    }
    console.log(`[sitemap-lastmod] resolved ${Object.keys(map).length} article lastmod dates.`);
    return map;
  } catch (err) {
    console.warn(`[sitemap-lastmod] ${err?.message || err}; building without lastmod.`);
    return {};
  }
}
