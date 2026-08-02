<?php
declare(strict_types=1);

define('JFSD_ADMIN', true);
require_once __DIR__ . '/auth.php';

/* Local helpers — login.php deliberately does not pull in the rest of the app,
   so a broken data folder can never lock the operator out of signing in. */
function jfsd_login_line(string $s): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/', '', $s) ?? '';
    return substr(trim($s), 0, 190);
}
function jfsd_redirect_login(string $path): void
{
    header('Location: ' . str_replace(["\r", "\n"], '', $path));
    exit;
}
function jfsd_login_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$config = require __DIR__ . '/config.php';
$users  = require __DIR__ . '/users.php';
$error  = '';
$notice = '';
$locked = false;

// Sends a Set-Cookie header, so it has to happen before a byte of output.
admin_login_csrf_seed();

/* Accounts still using the password this admin shipped with — the one written
   down in a public repo. Signing in with it is refused outright below; this
   drives the banner that says so, in words Jeffrey can act on. */
$placeholderAccounts = admin_placeholder_accounts($users);

if (isset($_GET['timeout'])) {
    $notice = 'You were signed out after a week of inactivity. Please sign in again.';
}

/* ---------------------------------------------------------------------------
 * Rate limiting
 *
 * Per IP: a hard block at 5 failures per 15 minutes. One address is throttled
 * and nobody else is affected, so this one is safe to enforce bluntly.
 *
 * Per email: a PROGRESSIVE DELAY, never a block. Blocking on the submitted
 * address alone meant any stranger, from any IP, could lock the studio's only
 * operator out of his own admin — five wrong passwords, then one more every few
 * minutes, forever. There is no self-service reset and no unlock link; clearing
 * it means SSH'ing into the host, which is precisely what a non-technical
 * instructor standing in front of a waiting class cannot do. So a correct
 * password must always get through. Brute force just pays for the privilege.
 *
 * Counters live outside the web root and are written under a lock.
 * ------------------------------------------------------------------------- */
$rateDir = (string) ($config['ratelimit_dir'] ?? '');
if ($rateDir !== '' && !is_dir($rateDir)) {
    @mkdir($rateDir, 0700, true);
}
$rateOk = $rateDir !== '' && is_dir($rateDir) && is_writable($rateDir);

const JFSD_RATE_MAX       = 5;
const JFSD_RATE_WINDOW    = 900; // 15 minutes
const JFSD_RATE_DELAY_MAX = 5;   // seconds — cap on the per-email slow-down

/** Read one counter file. */
function jfsd_rate_read(string $file): array
{
    $out = ['count' => 0, 'first' => 0];
    if (!is_file($file)) {
        return $out;
    }
    $fp = @fopen($file, 'r');
    if ($fp === false) {
        return $out;
    }
    $raw = '';
    if (flock($fp, LOCK_SH)) {
        $raw = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $out['count'] = (int) ($decoded['count'] ?? 0);
        $out['first'] = (int) ($decoded['first'] ?? 0);
    }
    return $out;
}

/** Increment one counter under an exclusive lock, so parallel tries can't race. */
function jfsd_rate_bump(string $file, int $now): void
{
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    if (flock($fp, LOCK_EX)) {
        $raw     = (string) stream_get_contents($fp);
        $decoded = json_decode($raw, true);
        $count   = is_array($decoded) ? (int) ($decoded['count'] ?? 0) : 0;
        $first   = is_array($decoded) ? (int) ($decoded['first'] ?? 0) : 0;
        if ($count === 0 || ($now - $first) >= JFSD_RATE_WINDOW) {
            $count = 0;
            $first = $now;
        }
        $count++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) json_encode(['count' => $count, 'first' => $first]));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    @chmod($file, 0600);
}

/** Delete counter files older than the window, so the folder cannot grow forever. */
function jfsd_rate_prune(string $dir, int $now): void
{
    // Cheap probabilistic sweep — roughly 1 request in 20 pays for it.
    if (random_int(1, 20) !== 1) {
        return;
    }
    $files = @glob($dir . '/*.json');
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $f) {
        $mt = @filemtime($f);
        if ($mt !== false && ($now - $mt) > (JFSD_RATE_WINDOW * 4)) {
            @unlink($f);
        }
    }
}

/**
 * Same sweep for abandoned session files.
 *
 * PHP's own collector only runs from inside session_start(), and auth.php now
 * deliberately avoids calling that for anyone without a cookie — so without
 * this, nothing ever tidies .sessions on a quiet site.
 */
function jfsd_session_prune(string $dir, int $now): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    if (random_int(1, 20) !== 1) {
        return;
    }
    $files = @glob($dir . '/sess_*');
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $f) {
        $mt = @filemtime($f);
        if ($mt !== false && ($now - $mt) > ADMIN_IDLE_TIMEOUT) {
            @unlink($f);
        }
    }
}

$now       = time();
$clientIp  = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$postEmail = strtolower(jfsd_login_line((string) ($_POST['email'] ?? '')));

$ipFile    = $rateOk ? $rateDir . '/ip-' . hash('sha256', $clientIp) . '.json' : '';
$emailFile = ($rateOk && $postEmail !== '') ? $rateDir . '/em-' . hash('sha256', $postEmail) . '.json' : '';

$emailStrikes = 0;

if ($rateOk) {
    jfsd_rate_prune($rateDir, $now);
    jfsd_session_prune((string) ($config['session_dir'] ?? ''), $now);

    // Per IP — the hard block. Not remotely abusable against the operator.
    $c = jfsd_rate_read($ipFile);
    if ($c['count'] >= JFSD_RATE_MAX && ($now - $c['first']) < JFSD_RATE_WINDOW) {
        $locked = true;
        $error  = 'Too many failed attempts from this device. Try again in 15 minutes.';
    }

    // Per email — counted, but only ever used to slow the next attempt down.
    if ($emailFile !== '') {
        $e = jfsd_rate_read($emailFile);
        if (($now - $e['first']) < JFSD_RATE_WINDOW) {
            $emailStrikes = $e['count'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    // CSRF on login too: it stops an attacker forcing a victim's browser into
    // a session the attacker controls. Signed double-submit cookie, so loading
    // this page does not cost a session file.
    if (!admin_login_csrf_valid()) {
        $error = 'That form expired. Please try again.';
    } else {
        // This address has failed recently: make the attempt expensive, but
        // never refuse it. A correct password always gets through.
        if ($emailStrikes >= JFSD_RATE_MAX) {
            sleep(min(JFSD_RATE_DELAY_MAX, 2 ** min(10, $emailStrikes - JFSD_RATE_MAX + 1)));
        }

        $password = (string) ($_POST['password'] ?? '');
        $stored   = ($postEmail !== '' && isset($users[$postEmail]) && is_string($users[$postEmail]))
            ? (string) $users[$postEmail]
            : '';
        $matched  = $stored !== '' && password_verify($password, $stored);

        if ($matched && admin_password_is_placeholder($stored)) {
            // The password is right, and that is the whole problem: it is the
            // one published in the repo. A lock the world has the key to does
            // not get to open just because someone turned it.
            $error = 'This admin is still on its setup password, which is written down publicly, '
                . 'so signing in is switched off until that is changed.';

        } elseif ($matched) {
            admin_login_user($postEmail);
            if ($ipFile !== '')    { @unlink($ipFile); }
            if ($emailFile !== '') { @unlink($emailFile); }
            jfsd_redirect_login(admin_safe_return((string) ($_GET['return'] ?? ADMIN_HOME_PATH)));

        } else {
            if ($rateOk) {
                if ($ipFile !== '')    { jfsd_rate_bump($ipFile, $now); }
                if ($emailFile !== '') { jfsd_rate_bump($emailFile, $now); }
            }
            // Identical message for "no such user" and "wrong password", plus a
            // random pause so the bcrypt cost does not leak which one it was.
            usleep(random_int(150000, 350000));
            $error = 'Invalid credentials.';
        }
    }
}

// Already signed in? Skip the form. (Checked after the POST branch so a
// successful sign-in exits above with its own return destination.)
if (admin_current_user()) {
    jfsd_redirect_login(ADMIN_HOME_PATH);
}

$cssV   = (string) ($config['css_version'] ?? '27');
$adminV = (string) (@filemtime(__DIR__ . '/admin.css') ?: '1');
?>
<!DOCTYPE html>
<html lang="en" data-bg="charcoal" data-headline="humanist">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Sign in — JF Admin</title>
<meta name="theme-color" content="#0E0E10">
<link rel="icon" type="image/png" href="/assets/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css?v=<?= jfsd_login_e($cssV) ?>">
<link rel="stylesheet" href="/admin/admin.css?v=<?= jfsd_login_e($adminV) ?>">
</head>
<body class="adm adm-login-body">
  <form class="adm-login trial-form" method="POST" autocomplete="on">
    <?= admin_login_csrf_field() ?>
    <div class="eyebrow"><span class="dot"></span>JF Self Defense</div>
    <h1 class="display h3 adm-login-h1">Staff sign in</h1>

    <?php if ($placeholderAccounts): ?>
      <div class="adm-alert adm-alert-error">
        <strong>This admin is not ready to be used yet.</strong>
        <p>
          It is still on the password it was built with, and that password is written down
          in a place anyone can read. Until it is changed, signing in is switched off — so
          that nobody else can sign in either.
        </p>
        <p>Please ask whoever set this up to change it. Nothing needs doing at your end.</p>
        <p class="adm-alert-sub">
          Affects: <?= jfsd_login_e(implode(', ', $placeholderAccounts)) ?>
        </p>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="adm-alert adm-alert-error"><?= jfsd_login_e($error) ?></div>
    <?php elseif ($notice !== ''): ?>
      <div class="adm-alert adm-alert-warn"><?= jfsd_login_e($notice) ?></div>
    <?php endif; ?>

    <label>Email
      <input type="email" name="email" required autocomplete="username" inputmode="email"
             value="<?= jfsd_login_e($postEmail) ?>" <?= $locked ? 'disabled' : 'autofocus' ?>>
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password" <?= $locked ? 'disabled' : '' ?>>
    </label>

    <button class="btn btn-red adm-login-btn" type="submit" <?= $locked ? 'disabled' : '' ?>>Sign in</button>
    <p class="adm-login-note">Restricted access. Authorised staff only.</p>
  </form>
</body>
</html>
