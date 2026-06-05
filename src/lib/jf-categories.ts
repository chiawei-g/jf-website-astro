// Category list for /articles/ surfaces. Loads from Sanity at build time so
// adding a Category in Studio adds a filter tab + /articles/category/<slug>/
// route on the next build. No code edit needed.

import { getArticleCategories } from './sanity';

export interface JfCategoryDef {
  /** URL slug, e.g. "awareness". */
  id: string;
  /** Canonical title (matches the dereferenced `category` on an article). */
  value: string;
  /** Tab + heading label, e.g. "On Awareness". */
  label: string;
  /** Optional intro line for the category page. */
  description?: string;
}

let _cache: JfCategoryDef[] | null = null;

export async function loadJfCategories(): Promise<JfCategoryDef[]> {
  if (_cache) return _cache;
  const docs = (await getArticleCategories()) || [];
  _cache = docs.map((d: any) => {
    const label = d.navLabel || d.title;
    return {
      id: d.id,
      value: d.value || d.title,
      label,
      description: d.description || undefined,
    };
  });
  return _cache;
}

export async function loadJfCategoryLabel(): Promise<Record<string, string>> {
  const cats = await loadJfCategories();
  return Object.fromEntries(cats.map((c) => [c.value, c.label]));
}
