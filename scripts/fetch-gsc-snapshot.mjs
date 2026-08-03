// scripts/fetch-gsc-snapshot.mjs
// -----------------------------------------------------------------------------
// Build-time Google Search Console snapshot for the admin "Search queries" panel.
//
// Sibling of fetch-ga-snapshot.mjs and deliberately shaped like it: same
// dependency-free JWT signing with node:crypto, same credential resolution
// order, same "write one JSON file under public/admin/data/ and print what you
// wrote" discipline. If you are changing one of these two scripts, read the
// other first — they are meant to stay recognisably the same script.
//
// Writes:
//   public/admin/data/gsc-snapshot.json
//
// -----------------------------------------------------------------------------
// THE ONE INVARIANT THAT MATTERS
// -----------------------------------------------------------------------------
// This script writes the snapshot file ONLY after Search Console has answered a
// query successfully. Any failure — missing key, unverified property, network,
// 4xx, 5xx — exits non-zero and writes NOTHING, leaving whatever was there
// before untouched.
//
// The dashboard depends on that. It reads "file is present" as "Search Console
// is genuinely connected" and "file is present with zero rows" as "connected,
// Google just has nothing to say yet" — which is a completely different message
// to a studio owner than "not connected". If this script ever wrote a stub file
// on failure, the panel would start claiming a connection that does not exist,
// which is the single worst outcome for this feature. So: no partial writes, no
// "empty snapshot so the page has something to read", no catch-all that turns an
// error into an empty result set.
//
// -----------------------------------------------------------------------------
// AUTH — dual-mode, auto-detected from the credential file's "type"
// -----------------------------------------------------------------------------
//   • "service_account"  → signed JWT (the seo-reader key). The intended
//                          long-term identity. Requires the C7 owner-verify
//                          workaround below, because the Search Console "Add
//                          user" UI rejects service-account addresses outright
//                          (confirmed Google bug, open since Apr 2026).
//   • "authorized_user"  → OAuth refresh token from `gcloud auth
//                          application-default login`. This is here for the same
//                          reason it is in fetch-ga-snapshot.mjs: Google's own
//                          UI blocks the clean service-account path, and a human
//                          account that already owns the property can read it
//                          today with no new identity to provision.
//
// Credential path resolution (first match wins):
//   1. $GSC_SA_KEY_FILE                  (same name gsc-verify-register.mjs uses,
//                                         so one export drives both scripts)
//   2. $GSC_KEY_PATH                     (explicit override, GA_KEY_PATH's twin)
//   3. $GOOGLE_APPLICATION_CREDENTIALS   (standard ADC env)
//   4. gcloud Application Default Credentials well-known path
//
// -----------------------------------------------------------------------------
// BEFORE THIS CAN WORK AT ALL
// -----------------------------------------------------------------------------
// Search Console access is NOT granted through project IAM. The identity has to
// be a verified OWNER of the property. For the service-account path that means
// running, once, per property:
//
//   node claude-shared/seo/gsc-verify-register.mjs token    jfselfdefense.com
//   ...add the printed TXT record at the domain apex, wait for DNS...
//   node claude-shared/seo/gsc-verify-register.mjs verify   jfselfdefense.com
//   node claude-shared/seo/gsc-verify-register.mjs register jfselfdefense.com
//   node claude-shared/seo/gsc-verify-register.mjs list      # want: siteOwner
//
// Full procedure and the reasoning: claude-shared/seo/GSC-setup-guide.md Part C.
// The JF-specific path through it: public/admin/README.md §9.
// -----------------------------------------------------------------------------

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { createSign } from 'node:crypto';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT_PATH = resolve(__dirname, '../public/admin/data/gsc-snapshot.json');

/* The property to read.
 *
 * Default is the DOMAIN property (`sc-domain:`), which is what GSC-setup-guide
 * Part D recommends pointing the dashboard at: one property covering http,
 * https, www, non-www and every subdomain, verified once by a DNS TXT record.
 * jfselfdefense.com currently has only a URL-prefix property, which silently
 * misses every www/http variant — the guide lists adding the Domain property as
 * JF's upgrade path, and this script is written for the destination, not the
 * waypoint.
 *
 * To read the existing URL-prefix property instead, pass the full origin
 * including the trailing slash:
 *   GSC_SITE_URL='https://jfselfdefense.com/' node scripts/fetch-gsc-snapshot.mjs
 * That property has been accruing since 2026-07-27 so it holds history the new
 * Domain property will not, but the service account has to be verified against
 * it by META tag rather than DNS TXT (guide Part C7 / the --site flag on
 * gsc-verify-register.mjs), which means shipping a tag in the site <head>.
 */
const SITE_URL = process.env.GSC_SITE_URL || 'sc-domain:jfselfdefense.com';

/* Window. 28 days ending at the last day Google will have finalised.
 *
 * LAG_DAYS exists because `dataState: "final"` trades freshness for stability —
 * Search Console keeps revising the most recent two to three days, so asking for
 * them yields either nothing or numbers that change under you tomorrow. Ending
 * the window three days back means every figure in the snapshot is one Google
 * will still agree with next week. Guide C5, "Data has a ~2-3 day lag".
 */
const WINDOW_DAYS = 28;
const LAG_DAYS = 3;

/* `final` is the guide's verified default. `all` includes the fresh, still-
 * moving days; it is exposed only so somebody debugging "is anything arriving at
 * all?" on a newly connected property can look, not because the dashboard should
 * run on it. */
const DATA_STATE = process.env.GSC_DATA_STATE || 'final';

/* Row cap per request, per guide C6: the API allows 1..25000, defaults to 1000.
 * Asking for the maximum keeps a small site to exactly one request. */
const ROW_LIMIT = 25000;

/* How many queries the dashboard keeps. The panel shows fewer; the surplus is
 * here so the panel can change its mind without a re-fetch. */
const TOP_N = 12;

function adcWellKnownPath() {
  if (process.env.APPDATA) return join(process.env.APPDATA, 'gcloud', 'application_default_credentials.json');
  return join(homedir(), '.config', 'gcloud', 'application_default_credentials.json');
}

const CREDS_PATH =
  process.env.GSC_SA_KEY_FILE ||
  process.env.GSC_KEY_PATH ||
  process.env.GOOGLE_APPLICATION_CREDENTIALS ||
  adcWellKnownPath();

/* Fail here, loudly, with the whole picture.
 *
 * This is the NORMAL state of this script until somebody does the Google-side
 * work, so this message is not an edge case — it is the thing a human is most
 * likely to read out of this file, and it is the only place that can tell them
 * what to do next. Hence the length. */
if (!existsSync(CREDS_PATH)) {
  console.error(`ERROR: no credential file at:\n  ${CREDS_PATH}\n`);
  console.error('Search Console cannot be read without one. Nothing was written, and');
  console.error('the admin will keep saying "not connected", which is the truth.\n');
  console.error('Point one of these at a credential file (first match wins):');
  console.error('  GSC_SA_KEY_FILE                 the seo-reader service-account JSON key');
  console.error('  GSC_KEY_PATH                    explicit override');
  console.error('  GOOGLE_APPLICATION_CREDENTIALS  standard ADC env var');
  console.error('  ...or run: gcloud auth application-default login \\');
  console.error('               --scopes=https://www.googleapis.com/auth/webmasters.readonly\n');
  console.error('As of the last check the seo-reader service account had NOT been created');
  console.error('yet (claude-shared/seo/brands.json marks it PLANNED). Creating it, and');
  console.error('making it an owner of the property, is a one-off job:');
  console.error('  claude-shared/seo/GSC-setup-guide.md   Part C1-C4 then C7');
  console.error('  site/public/admin/README.md            section 9 (the JF-specific path)');
  process.exit(1);
}

const creds = JSON.parse(readFileSync(CREDS_PATH, 'utf8'));
const AUTH_MODE = creds.type === 'service_account' ? 'service_account' : 'authorized_user';

/* Only used in OAuth mode. Defaults to dhp-site-analytics because that is the
 * project GSC-setup-guide C2 has you enable the Search Console API on; a quota
 * project without that API enabled produces a 403 that reads like a permissions
 * problem and is not one. */
const QUOTA_PROJECT =
  process.env.GSC_QUOTA_PROJECT || creds.quota_project_id || 'dhp-site-analytics';

/* Read-only is all the dashboard needs. gsc-verify-register.mjs asks for the
 * read-write `webmasters` scope because sites.add writes; this script never
 * writes anything on Google's side and should not be able to. */
const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

const b64url = (input) =>
  Buffer.from(input).toString('base64').replace(/=/g, '').replace(/\+/g, '-').replace(/\//g, '_');

async function tokenFromServiceAccount() {
  const now = Math.floor(Date.now() / 1000);
  const header = { alg: 'RS256', typ: 'JWT' };
  const claim = {
    iss: creds.client_email,
    scope: SCOPE,
    aud: creds.token_uri || 'https://oauth2.googleapis.com/token',
    iat: now,
    exp: now + 3600,
  };
  const unsigned = `${b64url(JSON.stringify(header))}.${b64url(JSON.stringify(claim))}`;
  const signer = createSign('RSA-SHA256');
  signer.update(unsigned);
  signer.end();
  const signature = signer
    .sign(creds.private_key)
    .toString('base64')
    .replace(/=/g, '')
    .replace(/\+/g, '-')
    .replace(/\//g, '_');
  const jwt = `${unsigned}.${signature}`;

  const res = await fetch(creds.token_uri || 'https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
      assertion: jwt,
    }),
  });
  if (!res.ok) throw new Error(`token exchange failed: ${res.status} ${await res.text()}`);
  return (await res.json()).access_token;
}

async function tokenFromAuthorizedUser() {
  const res = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      client_id: creds.client_id,
      client_secret: creds.client_secret,
      refresh_token: creds.refresh_token,
      grant_type: 'refresh_token',
    }),
  });
  if (!res.ok) throw new Error(`refresh-token exchange failed: ${res.status} ${await res.text()}`);
  return (await res.json()).access_token;
}

const getAccessToken = () =>
  AUTH_MODE === 'service_account' ? tokenFromServiceAccount() : tokenFromAuthorizedUser();

/* Search Console reports in Pacific Time (guide C5). Asking for a window
 * computed from a Singapore clock would ask for a day Google has not started
 * yet, which is a silent way to lose a day off the end of every pull. */
function pacificToday() {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Los_Angeles',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date());
}

const shiftDays = (iso, delta) => {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d) + delta * 86400000).toISOString().slice(0, 10);
};

/**
 * One page of searchanalytics.query.
 *
 * Request shape is verbatim GSC-setup-guide Part C5 (and its Node form in C8):
 * the endpoint is still webmasters/v3 in 2026, {siteUrl} is URL-encoded so a
 * Domain property travels as sc-domain%3Ajfselfdefense.com, and the body carries
 * startDate / endDate / dimensions / type / rowLimit / startRow / dataState.
 * Do not "tidy" any of these away — the shape was verified against Google's own
 * reference on 2026-06-28 and the guide is the record of that.
 */
async function queryPage(token, startDate, endDate, startRow) {
  const headers = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };
  // OAuth (user) credentials must bill the call to a quota project; a service
  // account bills itself and must NOT send this header.
  if (AUTH_MODE === 'authorized_user') headers['X-Goog-User-Project'] = QUOTA_PROJECT;

  const res = await fetch(
    `https://www.googleapis.com/webmasters/v3/sites/${encodeURIComponent(SITE_URL)}/searchAnalytics/query`,
    {
      method: 'POST',
      headers,
      body: JSON.stringify({
        startDate,
        endDate,
        dimensions: ['query', 'page', 'date'],
        type: 'web',
        rowLimit: ROW_LIMIT,
        startRow,
        dataState: DATA_STATE,
      }),
    }
  );

  if (!res.ok) {
    const body = await res.text();
    /* Translate the two failures that actually happen into the sentence that
     * tells you what to do, because Google's own wording for both is a generic
     * permissions message that sends people back to the broken "Add user" UI. */
    if (res.status === 403) {
      throw new Error(
        `Search Console refused this identity (403) for ${SITE_URL}.\n` +
          `  ${AUTH_MODE === 'service_account' ? creds.client_email : 'the OAuth user'} is not a verified OWNER of that property.\n` +
          `  This is expected until the one-off owner-verification is done — it CANNOT be\n` +
          `  fixed in the Search Console web UI, whose "Add user" box rejects service-account\n` +
          `  addresses (confirmed Google bug). Run gsc-verify-register.mjs: token -> verify ->\n` +
          `  register -> list. See GSC-setup-guide.md Part C7.\n` +
          `  API said: ${body}`
      );
    }
    if (res.status === 404) {
      throw new Error(
        `Search Console has no property "${SITE_URL}" for this identity (404).\n` +
          `  Either the property string is wrong, or ownership was verified but sites.add was\n` +
          `  never run — verification alone does not make the property visible to the API.\n` +
          `  Run: node gsc-verify-register.mjs register <domain>, then: list.\n` +
          `  A Domain property must read sc-domain:example.com; a URL-prefix property must be\n` +
          `  the full origin WITH its trailing slash, e.g. https://example.com/.\n` +
          `  API said: ${body}`
      );
    }
    throw new Error(`searchanalytics.query failed: ${res.status} ${body}`);
  }
  return res.json();
}

async function main() {
  console.log(`credential: ${CREDS_PATH}`);
  console.log(`auth mode:  ${AUTH_MODE} (${creds.client_email || creds.client_id || 'user'})`);
  console.log(`property:   ${SITE_URL}`);

  const endDate = shiftDays(pacificToday(), -LAG_DAYS);
  const startDate = shiftDays(endDate, -(WINDOW_DAYS - 1));
  console.log(`window:     ${startDate} .. ${endDate} (dataState: ${DATA_STATE})`);

  const token = await getAccessToken();

  /* Pagination per guide C6: keep asking until a page comes back short. A page
   * that comes back exactly full is not proof there is more, but asking once
   * more is cheap and getting it wrong silently truncates the data. */
  const rows = [];
  let startRow = 0;
  for (;;) {
    const data = await queryPage(token, startDate, endDate, startRow);
    const batch = data.rows ?? [];
    rows.push(...batch);
    if (batch.length < ROW_LIMIT) break;
    startRow += ROW_LIMIT;
  }

  /* Fold query x page x date down to one line per query.
   *
   * `position` from the API is already an impression-weighted average within its
   * own row, so weighting each row's position by its impressions and dividing by
   * total impressions reproduces the query's true average exactly. Averaging the
   * positions unweighted would let a query that appeared once on page nine drag
   * down a query that appeared four hundred times at the top. */
  const byQuery = new Map();
  for (const r of rows) {
    const q = r.keys?.[0] ?? '';
    const cur = byQuery.get(q) || { query: q, clicks: 0, impressions: 0, posWeighted: 0 };
    const impressions = Number(r.impressions ?? 0);
    cur.clicks += Number(r.clicks ?? 0);
    cur.impressions += impressions;
    cur.posWeighted += Number(r.position ?? 0) * impressions;
    byQuery.set(q, cur);
  }

  const topQueries = [...byQuery.values()]
    .sort((a, b) => b.impressions - a.impressions || b.clicks - a.clicks)
    .slice(0, TOP_N)
    .map((q) => ({
      query: q.query,
      clicks: q.clicks,
      impressions: q.impressions,
      ctr: q.impressions ? q.clicks / q.impressions : 0,
      position: q.impressions ? Number((q.posWeighted / q.impressions).toFixed(1)) : null,
    }));

  const totalClicks = [...byQuery.values()].reduce((n, q) => n + q.clicks, 0);
  const totalImpressions = [...byQuery.values()].reduce((n, q) => n + q.impressions, 0);
  const totalPosWeighted = [...byQuery.values()].reduce((n, q) => n + q.posWeighted, 0);

  const snapshot = {
    _note:
      'Search Console snapshot for the admin "Search queries" panel, written by ' +
      'scripts/fetch-gsc-snapshot.mjs. The PRESENCE of this file is what tells the ' +
      'dashboard that Search Console is genuinely connected, so it is only ever ' +
      'written after a successful searchanalytics.query — never as a placeholder. ' +
      'topQueries: [] therefore means "connected, Google has nothing to report ' +
      'yet", which is a normal state for the first few days of a new property and ' +
      'is NOT an error. Aggregate figures only; no visitor is identifiable here. ' +
      'Numbers can read slightly below Search Console\'s own UI because Google ' +
      'drops rare queries for privacy, and drops more of them at this query+page+' +
      'date granularity than at query alone (guide C6).',
    generatedAt: new Date().toISOString(),
    siteUrl: SITE_URL,
    range: { startDate, endDate },
    dataState: DATA_STATE,
    totals: {
      clicks: totalClicks,
      impressions: totalImpressions,
      position: totalImpressions ? Number((totalPosWeighted / totalImpressions).toFixed(1)) : null,
    },
    queryCount: byQuery.size,
    rowCount: rows.length,
    topQueries,
  };

  mkdirSync(dirname(OUT_PATH), { recursive: true });
  writeFileSync(OUT_PATH, JSON.stringify(snapshot, null, 2));
  console.log(`✓ wrote ${OUT_PATH}`);
  console.log(
    `  rows: ${rows.length}, distinct queries: ${byQuery.size}, ` +
      `impressions: ${totalImpressions}, clicks: ${totalClicks}`
  );
  if (rows.length === 0) {
    console.log(
      '  Zero rows is not a failure. A property only counts searches from the day it\n' +
        '  was verified, has no backfill, and finalises figures ~3 days late — so a\n' +
        '  freshly connected property is legitimately empty for a few days. The admin\n' +
        '  panel now says exactly that instead of pretending to be broken.'
    );
  }
}

main().catch((e) => {
  console.error('FATAL:', e.message);
  console.error('\nNothing was written. The existing snapshot, if any, is untouched, and the');
  console.error('admin panel keeps showing whatever it honestly knows.');
  process.exit(1);
});
