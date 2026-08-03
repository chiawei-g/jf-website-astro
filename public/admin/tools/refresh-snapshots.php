<?php
declare(strict_types=1);

/**
 * JF Self Defense — nightly analytics refresh.
 *
 * COMMAND LINE ONLY. Refuses to run over HTTP, and /admin/tools/ is 403'd by
 * admin/.htaccess as well.
 *
 * WHY THIS EXISTS IN PHP WHEN THE REAL FETCHERS ARE NODE
 * -----------------------------------------------------
 * The dashboard's traffic and search panels read two snapshot files. Those
 * files are written by site/scripts/fetch-ga-snapshot.mjs and
 * fetch-gsc-snapshot.mjs, which are the source of truth and stay that way.
 *
 * But this box has no Node — only PHP 8.3 and curl — and it is the only
 * machine that is always on. Chia's PC is not, and GitHub Actions would mean
 * putting a Google private key into a PUBLIC repository's secrets and then
 * fighting Hostinger's habit of blocking runner IPs. So the scheduled refresh
 * runs here, in the language the box actually has.
 *
 * IT MERGES, IT DOES NOT REPLACE
 * ------------------------------
 * The Node fetchers write a much richer snapshot than this admin reads —
 * 7/30/90-day windows, events, conversions, acquisition, audience, trend. This
 * script only knows how to fetch the parts the dashboard actually renders:
 *
 *     GA4   windows.d30.metrics, topPages
 *     GSC   topQueries, totals, range
 *
 * So it loads the existing file, updates those keys, and writes the whole thing
 * back. Anything it does not understand survives untouched. That way running
 * this nightly can never quietly demote a full snapshot to a partial one, and
 * whoever adds a panel later does not discover the cron has been deleting the
 * data behind it.
 *
 * IT REFUSES TO WRITE RUBBISH
 * ---------------------------
 * Same rule as the Node scripts, for the same reason: the PRESENCE of these
 * files is what tells the dashboard the connection is real. A file full of
 * zeros is worse than yesterday's file plus a staleness warning, because the
 * first is a lie and the second is true. Each source is judged on its own — a
 * bad GA4 day does not stop Search Console updating, and vice versa.
 *
 * SETUP
 *   1. Put the service-account JSON key at:
 *        <private_dir>/seo-reader.json      (chmod 600)
 *      The same key must be a Viewer on the GA4 property AND registered on the
 *      Search Console property. Neither happens by itself.
 *   2. Hostinger hPanel -> Cron Jobs, once a day:
 *        /usr/bin/php /home/u778119288/domains/jfselfdefense.com/public_html/admin/tools/refresh-snapshots.php
 *
 * Exits 0 if at least one source refreshed, 1 if both failed — so a cron that
 * mails on failure says something only when something is actually wrong.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('JFSD_ADMIN', true);
require_once __DIR__ . '/../_store.php';

const JFSD_GA_PROPERTY = '547369215';
const JFSD_GSC_SITE    = 'https://jfselfdefense.com/';

/** Both scopes on one token. Read-only on both sides; this never writes to Google. */
const JFSD_SCOPES = 'https://www.googleapis.com/auth/analytics.readonly '
                  . 'https://www.googleapis.com/auth/webmasters.readonly';

function out(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}

function err(string $s): void
{
    fwrite(STDERR, $s . "\n");
}

/** base64url, which is base64 with three substitutions and no padding. */
function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * POST JSON and return the decoded body, or null with the reason in $why.
 *
 * curl rather than file_get_contents because shared hosting frequently has
 * allow_url_fopen off, and a hard failure at 3am on a setting nobody
 * remembers changing is a bad way to find out.
 */
function post_json(string $url, string $body, array $headers, ?string &$why): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        $why = 'network: ' . $cerr;
        return null;
    }
    if ($code < 200 || $code >= 300) {
        $why = 'HTTP ' . $code . ': ' . substr((string) $res, 0, 300);
        return null;
    }
    $decoded = json_decode((string) $res, true);
    if (!is_array($decoded)) {
        $why = 'response was not JSON';
        return null;
    }
    return $decoded;
}

/** Sign a JWT with the service account key and trade it for an access token. */
function access_token(array $creds, ?string &$why): ?string
{
    $now    = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim  = [
        'iss'   => $creds['client_email'] ?? '',
        'scope' => JFSD_SCOPES,
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        // 55 minutes. Google caps assertions at an hour and clocks drift.
        'exp'   => $now + 3300,
    ];

    $unsigned = b64url(json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . b64url(json_encode($claim, JSON_UNESCAPED_SLASHES));

    $sig = '';
    if (!openssl_sign($unsigned, $sig, (string) ($creds['private_key'] ?? ''), OPENSSL_ALGO_SHA256)) {
        $why = 'could not sign the assertion — is private_key intact in the key file?';
        return null;
    }

    $res = post_json(
        'https://oauth2.googleapis.com/token',
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . b64url($sig),
        ]),
        ['Content-Type: application/x-www-form-urlencoded'],
        $why
    );

    $token = is_array($res) ? (string) ($res['access_token'] ?? '') : '';
    if ($token === '') {
        $why = $why ?? 'token response carried no access_token';
        return null;
    }
    return $token;
}

/* ---------------------------------------------------------------------------
 * GA4 — the 30-day window and the top pages, and nothing else.
 * ------------------------------------------------------------------------- */

/**
 * @return array{metrics:array<string,array{v:int|float}>,topPages:array<int,array{path:string,views:int}>}|null
 */
function fetch_ga(string $token, ?string &$why): ?array
{
    $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . JFSD_GA_PROPERTY . ':runReport';
    $hdr = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];

    $names = ['sessions', 'totalUsers', 'screenPageViews', 'averageSessionDuration', 'engagementRate'];

    $totals = post_json($url, json_encode([
        'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'yesterday']],
        'metrics'    => array_map(static fn(string $n): array => ['name' => $n], $names),
    ]), $hdr, $why);
    if ($totals === null) {
        return null;
    }

    $row = $totals['rows'][0]['metricValues'] ?? null;
    if (!is_array($row)) {
        $why = 'the 30-day window came back with no rows';
        return null;
    }

    $metrics = [];
    foreach ($names as $i => $n) {
        $raw = $row[$i]['value'] ?? null;
        if ($raw === null) {
            continue;
        }
        // Keep the {v: ...} envelope the dashboard and the Node script both use.
        $metrics[$n] = ['v' => (float) $raw == (int) (float) $raw ? (int) (float) $raw : (float) $raw];
    }
    if (!isset($metrics['sessions']) && !isset($metrics['totalUsers'])) {
        $why = 'neither sessions nor users came back — refusing to call that a refresh';
        return null;
    }

    $pagesWhy = null;
    $pages = post_json($url, json_encode([
        'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'yesterday']],
        'dimensions' => [['name' => 'pagePath']],
        'metrics'    => [['name' => 'screenPageViews']],
        'orderBys'   => [['desc' => true, 'metric' => ['metricName' => 'screenPageViews']]],
        'limit'      => 10,
    ]), $hdr, $pagesWhy);

    $topPages = [];
    foreach (($pages['rows'] ?? []) as $r) {
        $topPages[] = [
            'path'  => (string) ($r['dimensionValues'][0]['value'] ?? ''),
            'views' => (int) ($r['metricValues'][0]['value'] ?? 0),
        ];
    }

    // Top pages failing on its own is survivable — the window is the panel that
    // matters, and an empty list reads as "no pages" which is at least honest.
    if ($pagesWhy !== null) {
        err('  note: top pages failed (' . $pagesWhy . '), keeping the previous list');
        $topPages = [];
    }

    return ['metrics' => $metrics, 'topPages' => $topPages];
}

/* ---------------------------------------------------------------------------
 * Search Console — the query list behind the "Search queries" panel.
 * ------------------------------------------------------------------------- */

/**
 * @return array{topQueries:array<int,array<string,mixed>>,totals:array<string,mixed>,range:array<string,string>}|null
 */
function fetch_gsc(string $token, ?string &$why): ?array
{
    // Search Console finalises data on a lag, so asking for "yesterday" mostly
    // returns nothing. Three days back is the shortest window that is reliably
    // populated, and it matches what the Node fetcher asks for.
    $end   = date('Y-m-d', strtotime('-3 days'));
    $start = date('Y-m-d', strtotime('-31 days'));

    $res = post_json(
        'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode(JFSD_GSC_SITE) . '/searchAnalytics/query',
        json_encode([
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => ['query'],
            'rowLimit'   => 25,
            'dataState'  => 'final',
        ]),
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        $why
    );
    if ($res === null) {
        return null;
    }

    $rows = [];
    $clicks = 0;
    $impressions = 0;
    $posWeighted = 0.0;
    foreach (($res['rows'] ?? []) as $r) {
        $imp = (int) ($r['impressions'] ?? 0);
        $rows[] = [
            'query'       => (string) ($r['keys'][0] ?? ''),
            'clicks'      => (int) ($r['clicks'] ?? 0),
            'impressions' => $imp,
            'ctr'         => (float) ($r['ctr'] ?? 0),
            'position'    => round((float) ($r['position'] ?? 0), 1),
        ];
        $clicks      += (int) ($r['clicks'] ?? 0);
        $impressions += $imp;
        $posWeighted += ((float) ($r['position'] ?? 0)) * $imp;
    }

    /* An EMPTY list is a legitimate, successful answer — it means Google has
       nothing to report for the window. That is why this returns a result
       rather than an error: the dashboard has a state for "connected, nothing
       yet" and it should be able to reach it. */
    return [
        'topQueries' => $rows,
        'totals'     => [
            'clicks'      => $clicks,
            'impressions' => $impressions,
            'position'    => $impressions > 0 ? round($posWeighted / $impressions, 1) : 0,
        ],
        'range' => ['startDate' => $start, 'endDate' => $end],
    ];
}

/* ---------------------------------------------------------------------------
 * Merge into whatever is already on disk, and write atomically.
 * ------------------------------------------------------------------------- */

/**
 * @param array<string,mixed> $updates keys to change
 * @param array<int,string>   $replace top-level keys to set WHOLESALE rather
 *        than merge. Everything list-shaped belongs here: array_replace_recursive
 *        merges arrays index by index, so refreshing a 5-row query list over a
 *        stored 25-row one would keep rows 6-25 from last time and present them
 *        as current. Deleted rows have to actually disappear.
 */
function merge_write(string $path, array $updates, ?string &$why, array $replace = []): bool
{
    $existing = [];
    if (is_file($path)) {
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }

    $merged = array_replace_recursive($existing, $updates);

    // Force the list-shaped keys back to exactly what was just fetched.
    foreach ($replace as $key) {
        if (array_key_exists($key, $updates)) {
            $merged[$key] = $updates[$key];
        }
    }
    // Same reasoning one level down: the 30-day metrics are the whole panel, and
    // a metric that stopped being returned should stop being displayed.
    if (isset($updates['windows']['d30']['metrics'])) {
        $merged['windows']['d30']['metrics'] = $updates['windows']['d30']['metrics'];
    }

    // Same directory so rename() stays on one filesystem and is therefore atomic;
    // a reader can never catch a half-written snapshot.
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
        $why = 'could not write ' . $tmp;
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        $why = 'could not move the new file into place';
        return false;
    }
    return true;
}

/* ---------------------------------------------------------------------------
 * Run.
 * ------------------------------------------------------------------------- */

$config  = jfsd_config();
$keyFile = rtrim((string) ($config['private_dir'] ?? ''), '/') . '/seo-reader.json';

if (!is_file($keyFile) || !is_readable($keyFile)) {
    err('FATAL: no service-account key at ' . $keyFile);
    err('       Put the seo-reader JSON key there and chmod it 600.');
    exit(1);
}
$creds = json_decode((string) file_get_contents($keyFile), true);
if (!is_array($creds) || ($creds['private_key'] ?? '') === '') {
    err('FATAL: ' . $keyFile . ' is not a usable service-account key.');
    exit(1);
}

out('identity: ' . (string) ($creds['client_email'] ?? 'unknown'));

$why   = null;
$token = access_token($creds, $why);
if ($token === null) {
    err('FATAL: could not get an access token — ' . (string) $why);
    exit(1);
}

$dataDir = __DIR__ . '/../data';
$stamp   = gmdate('Y-m-d\TH:i:s\Z');
$ok      = 0;

// ---- GA4 ----
$why = null;
$ga  = fetch_ga($token, $why);
if ($ga === null) {
    err('! GA4 not refreshed: ' . (string) $why);
    err('  The previous traffic snapshot has been left exactly as it was.');
} else {
    $updates = [
        'generatedAt' => $stamp,
        'propertyId'  => JFSD_GA_PROPERTY,
        'windows'     => ['d30' => ['label' => 'Last 30 days', 'metrics' => $ga['metrics']]],
    ];
    // Only overwrite topPages when we actually have some; an empty list here
    // means the second request failed, not that the site had no visitors.
    if ($ga['topPages']) {
        $updates['topPages'] = $ga['topPages'];
    }
    $w = null;
    if (merge_write($dataDir . '/ga-snapshot.json', $updates, $w, ['topPages'])) {
        out('✓ GA4: users ' . (string) ($ga['metrics']['totalUsers']['v'] ?? '?')
            . ', sessions ' . (string) ($ga['metrics']['sessions']['v'] ?? '?')
            . ', pages ' . count($ga['topPages']));
        $ok++;
    } else {
        err('! GA4 fetched but could not be written: ' . (string) $w);
    }
}

// ---- Search Console ----
$why = null;
$gsc = fetch_gsc($token, $why);
if ($gsc === null) {
    err('! Search Console not refreshed: ' . (string) $why);
    err('  The previous query snapshot has been left exactly as it was.');
} else {
    $w = null;
    if (merge_write($dataDir . '/gsc-snapshot.json', [
        'generatedAt' => $stamp,
        'siteUrl'     => JFSD_GSC_SITE,
        'dataState'   => 'final',
        'range'       => $gsc['range'],
        'totals'      => $gsc['totals'],
        'queryCount'  => count($gsc['topQueries']),
        'rowCount'    => count($gsc['topQueries']),
        'topQueries'  => $gsc['topQueries'],
    ], $w, ['topQueries'])) {
        out('✓ Search Console: ' . count($gsc['topQueries']) . ' queries, '
            . (string) $gsc['totals']['impressions'] . ' impressions, '
            . (string) $gsc['totals']['clicks'] . ' clicks');
        $ok++;
    } else {
        err('! Search Console fetched but could not be written: ' . (string) $w);
    }
}

if ($ok === 0) {
    err('Nothing refreshed. Both snapshots left untouched.');
    exit(1);
}
out('done (' . $ok . ' of 2 refreshed)');
exit(0);
