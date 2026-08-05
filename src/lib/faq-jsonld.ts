/**
 * FAQPage JSON-LD extracted from an article's Portable Text body.
 *
 * scope: JF article pages only. Ported from the Qanvero implementation
 *        (qanvero-articles-astro/src/lib/jsonld.js), which has been running
 *        against a 33-article corpus since 2026-07-05.
 *
 * WHY THIS EXISTS (2026-08-05). The portfolio health audit found JF emitting
 * ZERO FAQPage across all 7 articles, despite every one of them carrying a
 * "Common questions" section. JF is the only brand inside the top-20 result
 * pool (average position 11.6), so it is the single page set where a richer
 * result and a cleaner extraction surface pays off soonest.
 *
 * THE STRUCTURE IT RELIES ON — matched exactly, or nothing is emitted:
 *   H2 with the text "Common questions"
 *   then repeating: H3 (the question) -> the FIRST `normal` block (the answer)
 *   section ends at the next H2, or at the end of the body.
 *
 * Only the FIRST normal block after each H3 is taken. Trailing paragraphs after
 * the last question are closing prose or a call to action, and hoovering them
 * into the final answer would push promotional copy into structured data, which
 * is precisely what suppresses citation.
 */

type Block = Record<string, any>;

const isStyle = (b: Block | undefined, style: string) =>
  !!b && b._type === 'block' && b.style === style;

const blockText = (b: Block | undefined) =>
  ((b?.children as Block[]) || [])
    .map((c) => c?.text || '')
    .join('')
    .trim();

export interface QaPair {
  question: string;
  answer: string;
}

export function extractFaq(body: Block[] | undefined): QaPair[] {
  if (!Array.isArray(body)) return [];

  const start = body.findIndex(
    (b) => isStyle(b, 'h2') && blockText(b).toLowerCase() === 'common questions',
  );
  if (start < 0) return [];

  const pairs: QaPair[] = [];
  let current: QaPair | null = null;

  for (let i = start + 1; i < body.length; i++) {
    const block = body[i];
    if (isStyle(block, 'h2')) break; // next major heading closes the section

    if (isStyle(block, 'h3')) {
      if (current?.question && current.answer) pairs.push(current);
      current = { question: blockText(block), answer: '' };
      continue;
    }

    if (current && !current.answer && isStyle(block, 'normal')) {
      current.answer = blockText(block);
    }
    // Any other block type (image, table, blockquote) is ignored and never
    // extends an answer.
  }

  if (current?.question && current.answer) pairs.push(current);
  return pairs.filter((p) => p.question && p.answer);
}

/** FAQPage node, or null when the article has no question section. */
export function buildFaqJsonLd(body: Block[] | undefined) {
  const pairs = extractFaq(body);
  if (!pairs.length) return null;
  return {
    '@type': 'FAQPage',
    mainEntity: pairs.map((p) => ({
      '@type': 'Question',
      name: p.question,
      acceptedAnswer: { '@type': 'Answer', text: p.answer },
    })),
  };
}
