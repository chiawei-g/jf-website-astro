import { createClient } from '@sanity/client';

const projectId = import.meta.env.SANITY_PROJECT_ID || process.env.SANITY_PROJECT_ID || 'pe441y01';
const dataset   = import.meta.env.SANITY_DATASET   || process.env.SANITY_DATASET   || 'production';
// Guard against malformed tokens (the build dies with "Invalid character in
// header content [authorization]" if the env var has a stray newline). Only
// pass through values that look like a real Sanity token.
const rawToken  = (import.meta.env.SANITY_TOKEN || process.env.SANITY_TOKEN || '').toString().trim();
const token     = /^sk[A-Za-z0-9_-]{50,}$/.test(rawToken) ? rawToken : undefined;

if (!projectId) {
  throw new Error('SANITY_PROJECT_ID is not set. Check site/.env');
}

export const client = createClient({
  projectId,
  dataset,
  apiVersion: '2025-04-01',
  useCdn: false,
  token,
  perspective: 'published',
});

// Category is a reference document — dereference into convenience fields so
// callers keep working with plain strings.
const CATEGORY_PROJECTION = `
    "category": category->title,
    "categorySlug": category->slug.current,
    "categoryNav": category->navLabel,`;

export async function getAllArticles() {
  return client.fetch(`*[_type == "article"] | order(publishedAt desc) {
    _id,
    title,
    "slug": slug.current,${CATEGORY_PROJECTION}
    excerpt,
    publishedAt,
    readTime,
    featured,
    "coverImageUrl": coverImage.asset->url + "?auto=format&q=80",
    metaDescription
  }`);
}

export async function getArticleBySlug(slug: string) {
  return client.fetch(`*[_type == "article" && slug.current == $slug][0] {
    _id,
    title,
    "slug": slug.current,${CATEGORY_PROJECTION}
    excerpt,
    publishedAt,
    readTime,
    featured,
    "coverImageUrl": coverImage.asset->url + "?auto=format&q=80",
    body[]{
      ...,
      _type == "image" => {
        ...,
        "url": asset->url + "?auto=format&q=80"
      }
    },
    metaDescription
  }`, { slug });
}

export async function getArticleCategories() {
  return client.fetch(`*[_type == "category"] | order(order asc) {
    "id": slug.current,
    "value": title,
    title,
    navLabel,
    description,
    order
  }`);
}
