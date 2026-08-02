<?php
declare(strict_types=1);

// JF Self Defense admin — paths and brand.
// INCLUDE ONLY. Never request this file over HTTP.
if (!defined('JFSD_ADMIN')) {
    http_response_code(404);
    exit;
}

/*
 * WHERE THE DATA LIVES
 * --------------------
 * The site is deployed with `scp -r dist/* .../public_html/`. That copy has no
 * exclude list and never deletes, so ANY writable file that also exists in the
 * repo gets overwritten on every deploy. Therefore nothing writable may live
 * under public_html/.
 *
 * All runtime state (students, attendance, payments, sessions, rate limits)
 * lives in the domain-private directory, which the deploy never touches:
 *
 *     /home/u778119288/domains/jfselfdefense.com/private/
 *
 * That directory must be created by hand over SSH before first use:
 *     mkdir -p /home/u778119288/domains/jfselfdefense.com/private
 *     chmod 700 /home/u778119288/domains/jfselfdefense.com/private
 *
 * See README.md in this folder. Override with the JFSD_PRIVATE_DIR environment
 * variable if the account id or domain ever changes, or for local testing.
 */
$privateDir = getenv('JFSD_PRIVATE_DIR');
if (!is_string($privateDir) || $privateDir === '') {
    $privateDir = '/home/u778119288/domains/jfselfdefense.com/private';
}
$privateDir = rtrim(str_replace('\\', '/', $privateDir), '/');

return [
    'brand'         => 'JF SELF DEFENSE',
    'domain'        => 'jfselfdefense.com',
    'public_url'    => 'https://jfselfdefense.com/',
    'timezone'      => 'Asia/Singapore',

    // Everything below is OUTSIDE the web root on purpose.
    'private_dir'   => $privateDir,
    'data_dir'      => $privateDir,
    'session_dir'   => $privateDir . '/.sessions',
    'ratelimit_dir' => $privateDir . '/.rate-limit',

    // Cache-buster for the public stylesheet. Matches V in
    // src/layouts/BaseLayout.astro. Bump both together if styles.css changes.
    'css_version'   => '27',
];
