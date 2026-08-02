// scripts/fetch-ga-snapshot.mjs
// -----------------------------------------------------------------------------
// Build-time GA4 snapshot for the admin Performance / Pages / Conversions /
// Audience number-cards (the DHP "Data snapshot" pattern).
//
// Authenticates to the GA4 Data API, then writes
//   src/data/ga-snapshot.json
// which contains aggregate numbers ONLY (safe to commit + deploy).
//
// DUAL-MODE auth (auto-detected from the credential file's "type"):
//   • "authorized_user"  → OAuth (your own Google login, via gcloud ADC).
//                          Used now, because Google currently has a bug that
//                          blocks adding new service accounts to GA4.
//   • "service_account"  → signed JWT (the service-account key). Switch to this
//                          later, once Google fixes the bug — no code change,
//                          just point GA_KEY_PATH at the key file.
//
// No npm dependencies: JWT is signed with Node's built-in crypto.
//
// Credential path resolution (first match wins):
//   1. $GA_KEY_PATH                      (explicit override)
//   2. $GOOGLE_APPLICATION_CREDENTIALS   (standard ADC env)
//   3. gcloud Application Default Credentials well-known path
//
// GA_PROPERTY_ID  defaults to the jfselfdefense.com property (547369215).
// GA_QUOTA_PROJECT defaults to the credential's quota_project_id, else
//                  "jacyoga-calendar-booking" (only used for OAuth mode).
// -----------------------------------------------------------------------------

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { createSign } from 'node:crypto';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROPERTY_ID = process.env.GA_PROPERTY_ID || '547369215';
const OUT_PATH = resolve(__dirname, '../public/admin/data/ga-snapshot.json');

function adcWellKnownPath() {
  if (process.env.APPDATA) return join(process.env.APPDATA, 'gcloud', 'application_default_credentials.json');
  return join(homedir(), '.config', 'gcloud', 'application_default_credentials.json');
}

const CREDS_PATH =
  process.env.GA_KEY_PATH || process.env.GOOGLE_APPLICATION_CREDENTIALS || adcWellKnownPath();

if (!existsSync(CREDS_PATH)) {
  console.error(`ERROR: no credential file at:\n  ${CREDS_PATH}`);
  console.error('Set GA_KEY_PATH, or run: gcloud auth application-default login --scopes=...analytics.readonly');
  process.exit(1);
}

const creds = JSON.parse(readFileSync(CREDS_PATH, 'utf8'));
const AUTH_MODE = creds.type === 'service_account' ? 'service_account' : 'authorized_user';
const QUOTA_PROJECT =
  process.env.GA_QUOTA_PROJECT || creds.quota_project_id || 'jacyoga-calendar-booking';

const b64url = (input) =>
  Buffer.from(input).toString('base64').replace(/=/g, '').replace(/\+/g, '-').replace(/\//g, '_');

async function tokenFromServiceAccount() {
  const now = Math.floor(Date.now() / 1000);
  const header = { alg: 'RS256', typ: 'JWT' };
  const claim = {
    iss: creds.client_email,
    scope: 'https://www.googleapis.com/auth/analytics.readonly',
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

async function runReport(token, body) {
  const headers = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };
  // OAuth (user) credentials must bill the Data API call to a quota project.
  if (AUTH_MODE === 'authorized_user') headers['X-Goog-User-Project'] = QUOTA_PROJECT;

  const res = await fetch(
    `https://analyticsdata.googleapis.com/v1beta/properties/${PROPERTY_ID}:runReport`,
    { method: 'POST', headers, body: JSON.stringify(body) }
  );
  if (!res.ok) throw new Error(`runReport failed: ${res.status} ${await res.text()}`);
  return res.json();
}

const num = (v) => (v == null ? 0 : Number(v));

async function safe(label, fn, fallback) {
  try {
    return await fn();
  } catch (e) {
    console.warn(`! skipped "${label}": ${e.message}`);
    return fallback;
  }
}

async function main() {
  console.log(`auth mode: ${AUTH_MODE} (${creds.client_email || creds.client_id || 'user'})`);
  const token = await getAccessToken();

  // ---- Metric windows: 7 / 30 / 90 days, each vs the prior equal period (DHP-style) ----
  const CURRENT = { startDate: '30daysAgo', endDate: 'yesterday' }; // arrays below use a 30d window
  const WINDOW_METRICS = ['sessions', 'totalUsers', 'screenPageViews', 'averageSessionDuration', 'engagementRate'];

  async function windowTotals(label, cur, prev) {
    const report = await safe(label, () =>
      runReport(token, {
        dateRanges: [{ ...cur, name: 'cur' }, { ...prev, name: 'prev' }],
        metrics: WINDOW_METRICS.map((name) => ({ name })),
      }), null);
    const out = {};
    if (report?.rows?.length) {
      const dimIdx = (report.dimensionHeaders || []).findIndex((h) => h.name === 'dateRange');
      const byRange = {};
      for (const row of report.rows) {
        const rn = dimIdx >= 0 ? row.dimensionValues[dimIdx].value : 'cur';
        byRange[rn] = row.metricValues.map((m) => num(m.value));
      }
      const c = byRange['cur'] || byRange['date_range_0'] || [];
      const p = byRange['prev'] || byRange['date_range_1'] || [];
      WINDOW_METRICS.forEach((name, i) => {
        const v = c[i] ?? 0, pv = p[i] ?? 0;
        out[name] = { v, prev: pv, delta: pv ? (v - pv) / pv : null };
      });
    }
    return out;
  }

  const windows = {
    d7:  { label: 'Last 7 days',  metrics: await windowTotals('window:7d',  { startDate: '7daysAgo',  endDate: 'yesterday' }, { startDate: '14daysAgo',  endDate: '8daysAgo' }) },
    d30: { label: 'Last 30 days', metrics: await windowTotals('window:30d', { startDate: '30daysAgo', endDate: 'yesterday' }, { startDate: '60daysAgo',  endDate: '31daysAgo' }) },
    d90: { label: 'Last 90 days', metrics: await windowTotals('window:90d', { startDate: '90daysAgo', endDate: 'yesterday' }, { startDate: '180daysAgo', endDate: '91daysAgo' }) },
  };

  // ---- Pages: top by views ----
  const pagesReport = await safe(
    'pages',
    () =>
      runReport(token, {
        dateRanges: [CURRENT],
        dimensions: [{ name: 'pagePath' }],
        metrics: [{ name: 'screenPageViews' }],
        orderBys: [{ desc: true, metric: { metricName: 'screenPageViews' } }],
        limit: 12,
      }),
    null
  );
  const topPages = (pagesReport?.rows || []).map((r) => ({
    path: r.dimensionValues[0].value,
    views: num(r.metricValues[0].value),
  }));

  // ---- Events: top by count ----
  const eventsReport = await safe(
    'events',
    () =>
      runReport(token, {
        dateRanges: [CURRENT],
        dimensions: [{ name: 'eventName' }],
        metrics: [{ name: 'eventCount' }],
        orderBys: [{ desc: true, metric: { metricName: 'eventCount' } }],
        limit: 12,
      }),
    null
  );
  const events = (eventsReport?.rows || []).map((r) => ({
    name: r.dimensionValues[0].value,
    count: num(r.metricValues[0].value),
  }));

  // ---- Conversions (GA4 key events) ----
  const convReport = await safe(
    'conversions',
    () =>
      runReport(token, {
        dateRanges: [CURRENT],
        dimensions: [{ name: 'eventName' }],
        metrics: [{ name: 'keyEvents' }],
        orderBys: [{ desc: true, metric: { metricName: 'keyEvents' } }],
        limit: 12,
      }),
    null
  );
  const conversions = (convReport?.rows || [])
    .map((r) => ({ name: r.dimensionValues[0].value, count: num(r.metricValues[0].value) }))
    .filter((c) => c.count > 0);

  // ---- Audience: country / device / new-vs-returning ----
  const countryReport = await safe(
    'country',
    () =>
      runReport(token, {
        dateRanges: [CURRENT],
        dimensions: [{ name: 'country' }],
        metrics: [{ name: 'totalUsers' }],
        orderBys: [{ desc: true, metric: { metricName: 'totalUsers' } }],
        limit: 8,
      }),
    null
  );
  const country = (countryReport?.rows || []).map((r) => ({
    name: r.dimensionValues[0].value,
    users: num(r.metricValues[0].value),
  }));

  const deviceReport = await safe(
    'device',
    () =>
      runReport(token, {
        dateRanges: [CURRENT],
        dimensions: [{ name: 'deviceCategory' }],
        metrics: [{ name: 'totalUsers' }],
        orderBys: [{ desc: true, metric: { metricName: 'totalUsers' } }],
      }),
    null
  );
  const device = (deviceReport?.rows || []).map((r) => ({
    name: r.dimensionValues[0].value,
    users: num(r.metricValues[0].value),
  }));

  const nvrReport = await safe(
    'newVsReturning',
    () =>
      runReport(token, {
        dateRanges: [CURRENT],
        dimensions: [{ name: 'newVsReturning' }],
        metrics: [{ name: 'activeUsers' }],
      }),
    null
  );
  const newVsReturning = (nvrReport?.rows || []).map((r) => ({
    name: r.dimensionValues[0].value || '(unknown)',
    users: num(r.metricValues[0].value),
  }));

  // ---- Acquisition: top source / medium by sessions ----
  const acqReport = await safe(
    'acquisition',
    () =>
      runReport(token, {
        dateRanges: [CURRENT],
        dimensions: [{ name: 'sessionSource' }, { name: 'sessionMedium' }],
        metrics: [{ name: 'sessions' }, { name: 'totalUsers' }],
        orderBys: [{ desc: true, metric: { metricName: 'sessions' } }],
        limit: 10,
      }),
    null
  );
  const acquisition = (acqReport?.rows || []).map((r) => ({
    source: r.dimensionValues[0].value,
    medium: r.dimensionValues[1].value,
    sessions: num(r.metricValues[0].value),
    users: num(r.metricValues[1].value),
  }));

  // ---- Trend: sessions by day (28d) for a sparkline ----
  const trendReport = await safe(
    'trend',
    () =>
      runReport(token, {
        dateRanges: [CURRENT],
        dimensions: [{ name: 'date' }],
        metrics: [{ name: 'sessions' }],
        orderBys: [{ dimension: { dimensionName: 'date' } }],
      }),
    null
  );
  const trend = (trendReport?.rows || []).map((r) => ({
    date: r.dimensionValues[0].value, // YYYYMMDD
    value: num(r.metricValues[0].value),
  }));

  const snapshot = {
    _note:
      'Build-time GA4 fallback, refreshed each CI deploy and baked into dist. ' +
      'A stale generatedAt here does NOT mean the dhp-ga4-reader credential died — ' +
      'the committed copy only updates when someone runs `npm run snapshot` locally; ' +
      'live data updates per successful deploy. If in doubt, run this script (it prints ' +
      'the auth mode) or check pinkaling.dev/pitch/. See playbook §9 ledger 2026-07-26.',
    generatedAt: new Date().toISOString(),
    propertyId: PROPERTY_ID,
    windows,
    topPages,
    events,
    conversions,
    acquisition,
    audience: { country, device, newVsReturning },
    trend,
  };

  mkdirSync(dirname(OUT_PATH), { recursive: true });
  writeFileSync(OUT_PATH, JSON.stringify(snapshot, null, 2));
  console.log(`✓ wrote ${OUT_PATH}`);
  console.log(
    `  sessions(30d): ${windows.d30.metrics.sessions?.v ?? 'n/a'}` +
      `, users: ${windows.d30.metrics.totalUsers?.v ?? 'n/a'}` +
      `, pages: ${topPages.length}, acq: ${acquisition.length}, conversions: ${conversions.length}`
  );
}

main().catch((e) => {
  console.error('FATAL:', e.message);
  process.exit(1);
});
