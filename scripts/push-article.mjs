#!/usr/bin/env node
// Publish ONE JF Self Defense article to Sanity from a draft JSON.
//
// Written 2026-07-29 to replace the one-off push-week-articles-c1-b1.mjs pattern:
// that script hardcoded two specific articles and their Replicate URLs, so every
// new article meant a new copy of it. This one takes any draft JSON matching the
// portfolio draft contract (claude-shared/seo/drafts/DRAFT-CONTRACT.md).
//
// Usage:
//   node scripts/push-article.mjs --input scripts/articles/<slug>.json [--publish] [--dry-run]
//
// Without --publish the doc is created as drafts.<slug> for review in Studio.
//
// Required fields in the input JSON:
//   slug, title, excerpt, metaDescription, publishedAt, readTime, body
//   coverImagePath, coverAlt                     (relative to repo root or absolute)
//   inlineImagePath, inlineAlt                   (optional; needs an image-placeholder block)
//   categoryRef  e.g. "cat-legal"                OR
//   category     {id,title,navLabel,slug,description,order} to create it if missing
//
// The body is Portable Text. A { "_type": "image-placeholder" } block is replaced
// with the real inline image on push (same convention as the Qanvero/Jacyoga
// pushers). comparisonTable blocks pass through untouched — the schema block and
// the renderer both landed 2026-07-28 (dhp-blog-astro c05aef6 / jf 47045ca).

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const PROJECT_ID = 'pe441y01';
const DATASET = 'production';
const API = '2025-04-01';

const repoRoot = path.resolve(import.meta.dirname, '..');
const envText = fs.readFileSync(path.join(repoRoot, '.env'), 'utf8');
const TOKEN = envText.match(/^SANITY_WRITE_TOKEN=(.+)$/m)?.[1]?.trim();
if (!TOKEN) {
  console.error('SANITY_WRITE_TOKEN missing from .env');
  process.exit(1);
}

const args = process.argv.slice(2);
const inputPath = args[args.indexOf('--input') + 1];
const publish = args.includes('--publish');
const dryRun = args.includes('--dry-run');
if (!inputPath || args.indexOf('--input') === -1) {
  console.error('Usage: node scripts/push-article.mjs --input <path.json> [--publish] [--dry-run]');
  process.exit(2);
}

const post = JSON.parse(fs.readFileSync(path.resolve(inputPath), 'utf8'));

const required = ['slug', 'title', 'excerpt', 'metaDescription', 'publishedAt', 'readTime', 'coverImagePath', 'coverAlt', 'body'];
for (const f of required) {
  if (!post[f]) throw new Error(`Missing required field: ${f}`);
}
if (post.excerpt.length > 200) throw new Error(`excerpt is ${post.excerpt.length} chars (max 200)`);
if (!post.categoryRef && !post.category) throw new Error('Need categoryRef or category');

const hasPlaceholder = post.body.some((b) => b._type === 'image-placeholder');
if (post.inlineImagePath && !hasPlaceholder) {
  throw new Error('inlineImagePath given but body has no image-placeholder block — the image would upload and never appear.');
}

const abs = (p) => (path.isAbsolute(p) ? p : path.join(repoRoot, p));
for (const p of [post.coverImagePath, post.inlineImagePath].filter(Boolean)) {
  if (!fs.existsSync(abs(p))) throw new Error(`Image not found: ${abs(p)}`);
}

const key = () => crypto.randomBytes(6).toString('hex');

async function sanity(pathname, init) {
  const res = await fetch(`https://${PROJECT_ID}.api.sanity.io/v${API}${pathname}`, {
    ...init,
    headers: { Authorization: `Bearer ${TOKEN}`, ...(init?.headers || {}) },
  });
  const txt = await res.text();
  if (!res.ok) throw new Error(`${pathname} -> ${res.status}: ${txt.slice(0, 400)}`);
  return JSON.parse(txt);
}

async function uploadImage(filePath) {
  const buf = fs.readFileSync(filePath);
  const filename = path.basename(filePath);
  const out = await sanity(
    `/assets/images/${DATASET}?filename=${encodeURIComponent(filename)}`,
    { method: 'POST', headers: { 'Content-Type': 'image/webp' }, body: buf },
  );
  return out.document._id;
}

// ── Category: reuse if present, create if the caller supplied a definition ──
let categoryRef = post.categoryRef;
const catDef = post.category;
if (catDef) {
  const found = await sanity(
    `/data/query/${DATASET}?query=${encodeURIComponent(`*[_type=="category" && _id=="${catDef.id}"][0]._id`)}`,
  );
  if (found.result) {
    console.log(`  category ${catDef.id} already exists, reusing`);
  } else if (dryRun) {
    console.log(`  DRY RUN: would create category ${catDef.id} ("${catDef.title}", order ${catDef.order})`);
  } else {
    await sanity(`/data/mutate/${DATASET}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        mutations: [{
          createIfNotExists: {
            _id: catDef.id,
            _type: 'category',
            title: catDef.title,
            navLabel: catDef.navLabel,
            description: catDef.description,
            order: catDef.order,
            slug: { _type: 'slug', current: catDef.slug },
          },
        }],
      }),
    });
    console.log(`  created category ${catDef.id} ("${catDef.title}", order ${catDef.order})`);
  }
  categoryRef = catDef.id;
}

// ── Images ──
let coverId = null;
let inlineId = null;
if (dryRun) {
  console.log(`  DRY RUN: would upload ${path.basename(post.coverImagePath)}`);
  if (post.inlineImagePath) console.log(`  DRY RUN: would upload ${path.basename(post.inlineImagePath)}`);
} else {
  console.log('  uploading cover image...');
  coverId = await uploadImage(abs(post.coverImagePath));
  console.log(`    asset: ${coverId}`);
  if (post.inlineImagePath) {
    console.log('  uploading inline image...');
    inlineId = await uploadImage(abs(post.inlineImagePath));
    console.log(`    asset: ${inlineId}`);
  }
}

// ── Body: stamp _keys, swap the placeholder for the real image ──
const body = [];
for (const blk of post.body) {
  if (blk._type === 'image-placeholder') {
    if (!inlineId && !dryRun) continue;
    body.push({
      _type: 'image',
      _key: key(),
      asset: { _type: 'reference', _ref: inlineId || 'DRYRUN' },
      alt: post.inlineAlt || '',
    });
    continue;
  }
  const copy = JSON.parse(JSON.stringify(blk));
  copy._key = copy._key || key();
  if (copy._type === 'block') {
    copy.children = (copy.children || []).map((c) => ({ ...c, _key: c._key || key() }));
    copy.markDefs = copy.markDefs || [];
  }
  if (copy._type === 'comparisonTable') {
    copy.rows = (copy.rows || []).map((r) => ({ ...r, _key: r._key || key(), _type: 'row' }));
  }
  body.push(copy);
}

const docId = publish ? post.slug : `drafts.${post.slug}`;
const doc = {
  _id: docId,
  _type: 'article',
  title: post.title,
  slug: { _type: 'slug', current: post.slug },
  category: { _type: 'reference', _ref: categoryRef },
  excerpt: post.excerpt,
  metaDescription: post.metaDescription,
  publishedAt: post.publishedAt,
  readTime: post.readTime,
  featured: post.featured ?? false,
  coverImage: coverId
    ? { _type: 'image', asset: { _type: 'reference', _ref: coverId }, alt: post.coverAlt }
    : undefined,
  body,
};

if (dryRun) {
  console.log(`\n[${post.slug}] DRY RUN — validation passed.`);
  console.log(`  state:    ${publish ? 'PUBLISHED' : 'DRAFT'}`);
  console.log(`  _id:      ${docId}`);
  console.log(`  category: ${categoryRef}`);
  console.log(`  blocks:   ${body.length} (tables: ${body.filter((b) => b._type === 'comparisonTable').length}, images: ${body.filter((b) => b._type === 'image').length})`);
  console.log(`  excerpt:  ${post.excerpt.length} chars`);
  console.log(`  meta:     ${post.metaDescription.length} chars`);
  process.exit(0);
}

// If publishing, drop any orphan draft so Studio does not show a stale twin.
const mutations = [{ createOrReplace: doc }];
if (publish) mutations.push({ delete: { id: `drafts.${post.slug}` } });

await sanity(`/data/mutate/${DATASET}`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ mutations }),
});

console.log(`\n[${post.slug}] ${publish ? 'PUBLISHED' : 'saved as DRAFT'} (_id=${docId}, ${body.length} blocks)`);
console.log(JSON.stringify({
  id: docId,
  slug: post.slug,
  category: categoryRef,
  blocks: body.length,
  liveUrl: `https://jfselfdefense.com/articles/${post.slug}/`,
}, null, 2));
