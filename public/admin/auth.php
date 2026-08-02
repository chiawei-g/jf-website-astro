<?php
declare(strict_types=1);

// JF Self Defense admin — session auth, CSRF, and redirect guards.
// Ported from the CGull admin stack with its known weaknesses fixed:
//   * session + rate-limit storage moved OUTSIDE the web root
//   * idle timeout actually enforced (CGull wrote last_active and never read it)
//   * CSRF tokens on every state-changing POST (CGull had none)
//   * return-path guard is an allow-list, not a loose prefix match
//   * include files refuse to run when requested directly
//
// INCLUDE ONLY. Never request this file over HTTP.
if (!defined('JFSD_ADMIN')) {
    http_response_code(404);
    exit;
}

const ADMIN_SESSION_LIFETIME = 2592000; // 30 days — absolute cookie lifetime
const ADMIN_IDLE_TIMEOUT     = 604800;  // 7 days — enforced sliding idle window
const ADMIN_LOGIN_PATH       = '/admin/login.php';
const ADMIN_HOME_PATH        = '/admin/';
const ADMIN_COOKIE_PATH      = '/admin/';
const ADMIN_SESSION_NAME     = 'JFSDADMIN';

/* ---------------------------------------------------------------------------
 * THE SHIPPED PLACEHOLDER CREDENTIAL
 *
 * This admin was published with a working password that is documented in a
 * public repo. Both halves of it are below on purpose: the hash so the exact
 * shipped line is recognised, and the word so that re-hashing the same word
 * with a fresh salt is recognised too.
 *
 * Neither is a secret and neither is usable — admin_password_is_placeholder()
 * is consulted before any sign-in is accepted, and a match is refused outright.
 * Replace the hash in users.php (php tools/hash-password.php '...') and the
 * admin starts working again.
 * ------------------------------------------------------------------------- */
const ADMIN_PLACEHOLDER_PASSWORD = 'ChangeMe-JF-2026';
const ADMIN_PLACEHOLDER_HASH     = '$2y$12$KUR0Yed4qwhV/wwOgpPEuOvltNdQuEBpPt1nz590pRbyGeZHto0CC';

/** Is this stored hash the credential the world already has? */
function admin_password_is_placeholder(string $hash): bool
{
    if ($hash === '') {
        return false;
    }
    if (hash_equals(ADMIN_PLACEHOLDER_HASH, $hash)) {
        return true;
    }
    return password_verify(ADMIN_PLACEHOLDER_PASSWORD, $hash);
}

/**
 * Every account still sitting on the shipped placeholder.
 *
 * @param array<string,mixed> $users
 * @return string[] email addresses
 */
function admin_placeholder_accounts(array $users): array
{
    $out = [];
    foreach ($users as $email => $hash) {
        if (is_string($hash) && admin_password_is_placeholder($hash)) {
            $out[] = (string) $email;
        }
    }
    return $out;
}

/**
 * Cookies carry the Secure flag unless a developer explicitly opts out.
 *
 * This used to be conditional on the current request already being HTTPS, which
 * gets it exactly backwards: the one request that matters is a downgraded first
 * navigation, and that is the one that was handed a readable cookie. Production
 * is HTTPS and .htaccess redirects to it. JFSD_ALLOW_INSECURE_COOKIES=1 exists
 * only for a plain-HTTP local checkout and must never be set on the live host.
 */
function admin_cookies_must_be_secure(): bool
{
    $optOut = getenv('JFSD_ALLOW_INSECURE_COOKIES');
    return !(is_string($optOut) && $optOut === '1');
}

/**
 * Does this request already carry an admin session cookie?
 *
 * Guarding on this before session_start() is what stops an unauthenticated
 * request creating a session file. Every anonymous hit used to write one, and
 * they were kept for thirty days: a plain request loop could exhaust the
 * account's inode quota, which takes the public site and every deploy down with
 * it — and stops the admin saving students.json at the same time.
 */
function admin_has_session_cookie(): bool
{
    $v = $_COOKIE[ADMIN_SESSION_NAME] ?? null;
    return is_string($v) && $v !== '';
}

/** True when a session is running, or can be resumed from a cookie. */
function admin_session_available(): bool
{
    return session_status() === PHP_SESSION_ACTIVE || admin_has_session_cookie();
}

/**
 * Pages a post-login redirect is allowed to land on. Anything not on this list
 * falls back to the dashboard, which closes the open-redirect hole without
 * relying on a prefix regex.
 */
const ADMIN_RETURN_WHITELIST = [
    '/admin/',
    '/admin/index.php',
    '/admin/students.php',
    '/admin/attendance.php',
    '/admin/payments.php',
];

/**
 * True when the request arrived over HTTPS (or a proxy says it did).
 *
 * NOT for deciding the Secure cookie flag — see admin_cookies_must_be_secure().
 * Gating Secure on the current scheme is what left a downgraded first
 * navigation holding a readable session cookie.
 */
function admin_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $proto === 'https';
}

function admin_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config  = require __DIR__ . '/config.php';
    $sessDir = (string) ($config['session_dir'] ?? '');

    // Session files hold live credentials, so they belong outside public_html.
    // If the private directory is missing we do NOT fall back to a web-reachable
    // folder — we let PHP use its system default (usually /tmp) instead.
    if ($sessDir !== '') {
        if (!is_dir($sessDir)) {
            @mkdir($sessDir, 0700, true);
        }
        if (is_dir($sessDir) && is_writable($sessDir)) {
            ini_set('session.save_path', $sessDir);
        }
    }

    // Collect abandoned session files on the idle window, not the 30-day cookie
    // lifetime. A session that has not been touched in 7 days is already dead to
    // admin_require_auth(), so keeping its file for another 23 days buys nothing
    // and lets junk accumulate under a shared-hosting quota.
    ini_set('session.gc_maxlifetime',   (string) ADMIN_IDLE_TIMEOUT);
    ini_set('session.cookie_lifetime',  (string) ADMIN_SESSION_LIFETIME);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode',  '1');

    session_name(ADMIN_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => ADMIN_SESSION_LIFETIME,
        'path'     => ADMIN_COOKIE_PATH,
        'domain'   => '',
        'secure'   => admin_cookies_must_be_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/**
 * Guard for every protected page. Two lines at the top of each:
 *   define('JFSD_ADMIN', true); require_once __DIR__ . '/auth.php';
 *   admin_require_auth();
 */
function admin_require_auth(): void
{
    // No cookie means nobody is signed in and there is nothing to read. Bounce
    // without calling session_start(), so hammering a protected URL cannot
    // litter the private directory with session files.
    if (!admin_session_available()) {
        $return = admin_safe_return((string) ($_SERVER['REQUEST_URI'] ?? ADMIN_HOME_PATH));
        header('Location: ' . ADMIN_LOGIN_PATH . '?return=' . urlencode($return));
        exit;
    }

    admin_start_session();

    // Enforce the idle window. CGull recorded last_active but never checked it,
    // so a stolen session stayed valid for the full 30 days.
    if (!empty($_SESSION['user'])) {
        $last = (int) ($_SESSION['last_active'] ?? 0);
        if ($last > 0 && (time() - $last) > ADMIN_IDLE_TIMEOUT) {
            admin_logout();
            header('Location: ' . ADMIN_LOGIN_PATH . '?timeout=1');
            exit;
        }
    }

    if (empty($_SESSION['user'])) {
        $return = admin_safe_return((string) ($_SERVER['REQUEST_URI'] ?? ADMIN_HOME_PATH));
        header('Location: ' . ADMIN_LOGIN_PATH . '?return=' . urlencode($return));
        exit;
    }

    $_SESSION['last_active'] = time();
}

/**
 * Reduce any candidate return path to one of the known admin pages.
 * Query strings are dropped — nothing here needs them preserved, and dropping
 * them removes the whole class of smuggled-parameter problems.
 */
function admin_safe_return(string $candidate): string
{
    $path = parse_url($candidate, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return ADMIN_HOME_PATH;
    }
    $path = rawurldecode($path);
    if (str_contains($path, "\r") || str_contains($path, "\n") || str_contains($path, '..')) {
        return ADMIN_HOME_PATH;
    }
    return in_array($path, ADMIN_RETURN_WHITELIST, true) ? $path : ADMIN_HOME_PATH;
}

function admin_current_user(): ?string
{
    // Never start a session just to discover nobody is signed in.
    if (!admin_session_available()) {
        return null;
    }
    admin_start_session();
    $u = $_SESSION['user'] ?? null;
    return is_string($u) && $u !== '' ? $u : null;
}

function admin_login_user(string $email): void
{
    admin_start_session();
    session_regenerate_id(true);
    $_SESSION['user']        = $email;
    $_SESSION['login_time']  = time();
    $_SESSION['last_active'] = time();
    unset($_SESSION['csrf']); // fresh token for the new session id
}

function admin_logout(): void
{
    admin_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?? ADMIN_COOKIE_PATH,
            'domain'   => $p['domain'] ?? '',
            'secure'   => (bool) ($p['secure'] ?? false),
            'httponly' => (bool) ($p['httponly'] ?? true),
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

/* ---------------------------------------------------------------------------
 * CSRF
 * SameSite=Strict is a browser behaviour, not a server-side control. Every
 * state-changing POST in this admin also carries a per-session token.
 * ------------------------------------------------------------------------- */

function admin_csrf_token(): string
{
    admin_start_session();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input to drop inside every <form method="post">. */
function admin_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="'
        . htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function admin_csrf_valid(): bool
{
    if (!admin_session_available()) {
        return false;
    }
    admin_start_session();
    $sent = (string) ($_POST['_csrf'] ?? '');
    $have = (string) ($_SESSION['csrf'] ?? '');
    return $have !== '' && $sent !== '' && hash_equals($have, $sent);
}

/**
 * Stop on a bad token, but stop in a way a sixty-something instructor standing
 * in the studio can act on. This fires on the ordinary case of leaving a
 * register or a payment form open past the session and then tapping Save, so
 * "Security check failed." on a bare white page is the wrong answer — it reads
 * as "the website is broken" rather than "sign in again".
 */
function admin_require_csrf(): void
{
    if (admin_csrf_valid()) {
        return;
    }
    http_response_code(400);

    if (function_exists('jfsd_head') && function_exists('jfsd_foot')) {
        jfsd_head('Sign in again', '');
        ?>
  <div class="adm-alert adm-alert-warn">
    <strong>You were signed out while this page was open.</strong>
    <p>Nothing was saved and nothing was changed. Sign in again and you can re-enter it.</p>
    <p><a class="adm-btn adm-btn-red adm-mt" href="/admin/login.php">Sign in again</a></p>
  </div>
        <?php
        jfsd_foot();
        exit;
    }

    header('Content-Type: text/plain; charset=UTF-8');
    echo "You were signed out while this page was open.\n\n"
        . "Nothing was saved and nothing was changed.\n"
        . "Go to /admin/login.php, sign in again, and re-enter it.\n";
    exit;
}

/* ---------------------------------------------------------------------------
 * Login-form CSRF — signed double-submit cookie, no session
 *
 * The sign-in form still needs a token, but minting one from $_SESSION meant
 * every anonymous GET of the login page wrote a session file. Instead: a random
 * value in a cookie, and an HMAC of that value in the form. A cross-site POST
 * cannot read the cookie, and SameSite=Strict stops the browser sending it, so
 * the check fails exactly where it should. Nothing is stored server-side.
 * ------------------------------------------------------------------------- */

const ADMIN_LOGIN_CSRF_COOKIE = 'JFSDLOGIN';

/**
 * HMAC key for the login token. Derived from the password hashes, which are
 * already this deployment's secret and are stable across requests without any
 * writable storage. An HMAC does not leak its key.
 */
function admin_login_csrf_key(): string
{
    static $key = null;
    if ($key === null) {
        $users = require __DIR__ . '/users.php';
        $key   = hash_hmac(
            'sha256',
            'jfsd-login-csrf-v1',
            implode('|', array_map('strval', array_values((array) $users))),
            true
        );
    }
    return $key;
}

/**
 * Make sure the login cookie exists and return its value.
 * MUST be called before any output — it may send a Set-Cookie header.
 */
function admin_login_csrf_seed(): string
{
    $have = $_COOKIE[ADMIN_LOGIN_CSRF_COOKIE] ?? null;
    if (is_string($have) && preg_match('/^[a-f0-9]{32}$/', $have) === 1) {
        return $have;
    }
    $value = bin2hex(random_bytes(16));
    setcookie(ADMIN_LOGIN_CSRF_COOKIE, $value, [
        'expires'  => 0, // browser session only
        'path'     => ADMIN_COOKIE_PATH,
        'domain'   => '',
        'secure'   => admin_cookies_must_be_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    $_COOKIE[ADMIN_LOGIN_CSRF_COOKIE] = $value;
    return $value;
}

/** Hidden input for the sign-in form. */
function admin_login_csrf_field(): string
{
    $value = admin_login_csrf_seed();
    $token = $value . '.' . hash_hmac('sha256', $value, admin_login_csrf_key());
    return '<input type="hidden" name="_csrf" value="'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function admin_login_csrf_valid(): bool
{
    $cookie = $_COOKIE[ADMIN_LOGIN_CSRF_COOKIE] ?? null;
    if (!is_string($cookie) || preg_match('/^[a-f0-9]{32}$/', $cookie) !== 1) {
        return false;
    }
    $parts = explode('.', (string) ($_POST['_csrf'] ?? ''), 2);
    if (count($parts) !== 2) {
        return false;
    }
    [$value, $mac] = $parts;
    if (!hash_equals($cookie, $value)) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $value, admin_login_csrf_key()), $mac);
}
