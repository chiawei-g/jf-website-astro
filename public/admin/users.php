<?php
declare(strict_types=1);

// JF Self Defense admin — credential loader.
// INCLUDE ONLY. Never request this file over HTTP.
if (!defined('JFSD_ADMIN')) {
    http_response_code(404);
    exit;
}

/* ============================================================================
 * NO CREDENTIALS LIVE IN THIS FILE, DELIBERATELY.
 * ============================================================================
 *
 * chiawei-g/jf-website-astro is a PUBLIC repository. A bcrypt hash committed
 * here would be world-readable and permanently in git history, open to offline
 * brute force for as long as the password is in use. Cost-12 is slow, but a
 * published hash is still an unnecessary head start.
 *
 * So the real email => hash map lives on the server only, outside the web root
 * and outside the deploy tree, at:
 *
 *     /home/u778119288/domains/jfselfdefense.com/private/admin-users.php
 *
 * This mirrors the pattern already in use for Jacyoga's Stripe keys
 * (domains/jacyoga.com/stripe-private/config.php): secrets on the box, never
 * in the repo, never in a build artefact.
 *
 * That file returns the same shape this one used to:
 *
 *     <?php return [
 *         'someone@example.com' => '$2y$12$...',
 *     ];
 *
 * TO ADD OR ROTATE SOMEONE
 *   1. php tools/hash-password.php 'the new password' 'their@email.com'
 *   2. SSH in and edit the private file above, pasting the printed line.
 *   3. No redeploy needed. It is read on each request, so the change is live
 *      immediately — which also means a typo locks everyone out immediately.
 *      Keep a session open in another tab while editing.
 *
 * Rules that still apply:
 *   - Email keys MUST be lowercase. Login lowercases the submitted address
 *     before lookup, so a capitalised key can never be matched.
 *   - Single-quote the hash. Bcrypt hashes contain '$' characters that PHP
 *     would interpolate inside double quotes.
 *   - Removing someone is deleting their line. There is no disable flag.
 *
 * IF THE FILE IS MISSING the admin returns an empty map, every sign-in fails
 * closed, and login.php explains what to create. It does not fall back to any
 * built-in account — an admin that ships its own key is what this whole
 * arrangement exists to avoid.
 * ========================================================================== */

$config = require __DIR__ . '/config.php';
$credentialsFile = rtrim((string) $config['private_dir'], '/') . '/admin-users.php';

if (!is_file($credentialsFile) || !is_readable($credentialsFile)) {
    // Fail closed, and leave a breadcrumb for whoever is looking at the logs.
    error_log('[jfsd-admin] credentials file missing or unreadable: ' . $credentialsFile);
    return [];
}

$users = require $credentialsFile;

if (!is_array($users)) {
    error_log('[jfsd-admin] credentials file did not return an array: ' . $credentialsFile);
    return [];
}

// Normalise keys so a stray capital or trailing space in the private file
// cannot produce an account that exists but can never be signed into.
$normalised = [];
foreach ($users as $email => $hash) {
    if (!is_string($email) || !is_string($hash) || $hash === '') {
        continue;
    }
    $normalised[strtolower(trim($email))] = $hash;
}

return $normalised;
