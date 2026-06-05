#!/usr/bin/env node
// One-off: convert the 4 static HTML pages from Mockup-v1/ into Astro pages.
// Strips <!DOCTYPE>, head, nav, nav-dropdown, footer and the trailing
// <script src=site.js> (BaseLayout owns all of those). Fixes relative links.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(__dirname, '..', '..');
const SRC = path.join(REPO, 'Mockup-v1');
const DST = path.resolve(__dirname, '..', 'src', 'pages');

const pages = [
  {
    src: 'index.html',
    dst: 'index.astro',
    title: 'JF Self Defense — Singapore',
    description: 'Practical self defense in Singapore — taught by Jeffrey Fleury, ICPS. Real situational decisions for women, families and professionals. No belts. No forms.',
    activeNav: 'home',
    bodyLabel: '01 Home',
  },
  {
    src: 'about.html',
    dst: 'about.astro',
    title: 'About Jeffrey',
    description: 'Three decades in law enforcement, security and protective services. The operator who built JF Self Defense and why he refuses to teach martial arts.',
    activeNav: 'about',
    bodyLabel: '02 About Jeffrey',
  },
  {
    src: 'trainers.html',
    dst: 'trainers.astro',
    title: 'Trainers',
    description: 'Three coaches, forty years of operator memory between them. Meet Jeffrey, Ryan and Chia Wei — the team behind every JF class.',
    activeNav: 'trainers',
    bodyLabel: '03 Trainers',
  },
  {
    src: 'programmes.html',
    dst: 'programmes.astro',
    title: 'Programmes',
    description: 'Group, womens, personalised 1-on-1, corporate workshops, kids and security personnel. Practical self defense, rebuilt for the life you actually live.',
    activeNav: 'programmes',
    bodyLabel: '04 Programmes',
  },
];

const PATH_FIXES = [
  [/href="index\.html"/g, 'href="/"'],
  [/href="about\.html"/g, 'href="/about/"'],
  [/href="trainers\.html"/g, 'href="/trainers/"'],
  [/href="programmes\.html"/g, 'href="/programmes/"'],
  [/href="articles\.html"/g, 'href="/articles/"'],
  [/src="assets\//g, 'src="/assets/'],
  [/href="assets\//g, 'href="/assets/'],
  // The Astro JSX-like parser is strict about unescaped `<` in body text. Most
  // < are inside tags so safe; we don't have raw `<` in copy except inside the
  // pricing micro-copy that already uses & or proper tags.
];

function extractBody(html) {
  // Find end of nav-dropdown: the closing </div> that pairs with
  // <div class="nav-dropdown" id="nav-dropdown" ...>. It's the second </div>
  // right after </div></a></div></div> following nav-dropdown-foot.
  // Simpler heuristic: find the </div> immediately before the first <!-- comment
  // that's followed by a <section> (i.e., the start of real page body).
  const navDropdownIdx = html.indexOf('<div class="nav-dropdown"');
  if (navDropdownIdx === -1) throw new Error('No nav-dropdown block found');
  // Find the closing </div> for nav-dropdown by counting depth from that opener.
  let depth = 1;
  let i = navDropdownIdx + '<div'.length;
  while (i < html.length && depth > 0) {
    const nextOpen = html.indexOf('<div', i);
    const nextClose = html.indexOf('</div>', i);
    if (nextClose === -1) throw new Error('Unbalanced div in nav-dropdown');
    if (nextOpen !== -1 && nextOpen < nextClose) {
      depth++;
      i = nextOpen + '<div'.length;
    } else {
      depth--;
      i = nextClose + '</div>'.length;
    }
  }
  // i is now just past the closing </div> of nav-dropdown.
  const bodyStart = i;

  // End is right before <footer class="foot">
  const footerIdx = html.indexOf('<footer class="foot"');
  if (footerIdx === -1) throw new Error('No footer found');

  let body = html.substring(bodyStart, footerIdx);
  for (const [from, to] of PATH_FIXES) body = body.replace(from, to);
  return body.trim();
}

let count = 0;
for (const p of pages) {
  const html = fs.readFileSync(path.join(SRC, p.src), 'utf8');
  const body = extractBody(html);
  const astro = `---
import BaseLayout from '../layouts/BaseLayout.astro';
---
<BaseLayout
  title="${p.title}"
  description="${p.description}"
  activeNav="${p.activeNav}"
  bodyLabel="${p.bodyLabel}">

${body}

</BaseLayout>
`;
  fs.writeFileSync(path.join(DST, p.dst), astro);
  console.log(`✓ ${p.dst} (${(astro.length / 1024).toFixed(1)} KB)`);
  count++;
}
console.log(`\nDone. ${count} pages migrated.`);
