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

    /* NO css_version HERE ANY MORE.
     *
     * It used to hold a cache-buster for /styles.css that had to be kept in
     * step by hand with V in src/layouts/BaseLayout.astro. It was not, and could
     * not reasonably be expected to be: nothing failed when they diverged, so
     * nothing said so. The public site went to 29, the admin stayed on 27, and
     * every browser holding a cached styles.css under ?v=27 rendered the admin
     * as unstyled HTML while the public site looked perfectly fine.
     *
     * _ui.php now takes the key from filemtime() on the file itself, the way it
     * always did for admin.css. Two numbers that must match is a bug waiting for
     * a quiet week; one number derived from the thing it describes is not. */
];
