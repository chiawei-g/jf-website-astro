<?php
declare(strict_types=1);

// JF Self Defense admin — escaping, formatting, flash messages, page chrome.
// INCLUDE ONLY. Never request this file over HTTP.
if (!defined('JFSD_ADMIN')) {
    http_response_code(404);
    exit;
}

/* ===========================================================================
 * Escaping and input normalisation
 * Every stored value is treated as hostile. Escape at the point of output.
 * ========================================================================= */

/** Escape for HTML. Use on EVERY value that reaches the page. */
function jfsd_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Single-line field: strip CR/LF and control characters, collapse, trim, cap. */
function jfsd_line(?string $s, int $max = 200): string
{
    $s = (string) $s;
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    $s = trim($s);
    return jfsd_cut($s, $max);
}

/** Length cap that stays UTF-8 safe with or without mbstring. */
function jfsd_cut(string $s, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    if (strlen($s) <= $max) {
        return $s;
    }
    // Byte-cut, then walk back until the result is valid UTF-8 again.
    $cut = substr($s, 0, $max);
    while ($cut !== '' && preg_match('//u', $cut) !== 1) {
        $cut = substr($cut, 0, -1);
    }
    return $cut;
}

/** Multi-line field: allow newlines, drop CR and other control chars, cap. */
function jfsd_text(?string $s, int $max = 4000): string
{
    $s = (string) $s;
    $s = str_replace("\r\n", "\n", $s);
    $s = str_replace("\r", "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $s) ?? '';
    return jfsd_cut(trim($s), $max);
}

/** Money for display only. Stored as a plain number. */
function jfsd_money(float $amount): string
{
    return 'S$' . number_format($amount, 2);
}

/** ISO date -> "Sat 26 Jul 2026". Falls back to the raw value if unparseable. */
function jfsd_date_friendly(?string $ymd): string
{
    $ymd = (string) $ymd;
    if ($ymd === '') {
        return '—';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone(jfsd_tz()));
    return $d === false ? $ymd : $d->format('D j M Y');
}

/** ISO timestamp -> "26 Jul 2026, 7:12pm". */
function jfsd_datetime_friendly(?string $iso): string
{
    $iso = (string) $iso;
    if ($iso === '') {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($iso);
    } catch (Exception $e) {
        return $iso;
    }
    return $d->setTimezone(new DateTimeZone(jfsd_tz()))->format('j M Y, g:ia');
}

function jfsd_tz(): string
{
    return (string) (jfsd_config()['timezone'] ?? 'Asia/Singapore');
}

/** Strict ISO date validation — rejects 2026-02-31 and anything malformed. */
function jfsd_valid_date(string $ymd): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    return checkdate($m, $d, $y) && $y >= 2000 && $y <= 2100;
}

/* ===========================================================================
 * Post/Redirect/Get + session flash
 * Every mutation redirects, so a refresh can never resubmit.
 * ========================================================================= */

function jfsd_flash_set(string $type, string $msg): void
{
    admin_start_session();
    $_SESSION['jfsd_flash'] = ['type' => $type, 'msg' => $msg];
}

/** Read-and-clear, so a flash shows exactly once. */
function jfsd_flash_get(): ?array
{
    admin_start_session();
    if (empty($_SESSION['jfsd_flash']) || !is_array($_SESSION['jfsd_flash'])) {
        return null;
    }
    $f = $_SESSION['jfsd_flash'];
    unset($_SESSION['jfsd_flash']);
    return $f;
}

/** Turn a mutation result into a flash. */
function jfsd_flash_result(array $result): void
{
    jfsd_flash_set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['msg'] ?? ''));
}

/* ===========================================================================
 * One-shot form nonces
 *
 * Post/Redirect/Get stops a REFRESH resubmitting. It does nothing about the
 * same request being sent twice: studio wifi is slow, the page sits there, the
 * button gets tapped again, and the payment is recorded twice under two
 * different ids with the sessions credited twice over.
 *
 * Each at-risk form carries a single-use token. PHP's session file lock
 * serialises two requests from the same browser, so the second one always finds
 * the token already spent.
 * ========================================================================= */

const JFSD_NONCE_KEEP = 12; // concurrent open forms to tolerate

function jfsd_nonce_field(): string
{
    admin_start_session();
    $live = $_SESSION['jfsd_nonces'] ?? [];
    if (!is_array($live)) {
        $live = [];
    }
    $nonce  = bin2hex(random_bytes(16));
    $live[] = $nonce;
    if (count($live) > JFSD_NONCE_KEEP) {
        $live = array_slice($live, -JFSD_NONCE_KEEP);
    }
    $_SESSION['jfsd_nonces'] = array_values($live);
    return '<input type="hidden" name="_once" value="' . jfsd_e($nonce) . '">';
}

/** Spend the submitted token. False means this is a repeat of a submission. */
function jfsd_nonce_spend(): bool
{
    admin_start_session();
    $sent = (string) ($_POST['_once'] ?? '');
    $live = $_SESSION['jfsd_nonces'] ?? [];
    if ($sent === '' || !is_array($live)) {
        return false;
    }
    $at = array_search($sent, $live, true);
    if ($at === false) {
        return false;
    }
    unset($live[$at]);
    $_SESSION['jfsd_nonces'] = array_values($live);
    return true;
}

/**
 * Redirect within the admin. $path is always a literal from our own code, but
 * CR/LF is stripped anyway so nothing can ever split the header.
 */
function jfsd_redirect(string $path): void
{
    $path = str_replace(["\r", "\n", "\0"], '', $path);
    if (!str_starts_with($path, '/admin/')) {
        $path = '/admin/';
    }
    header('Location: ' . $path);
    exit;
}

/* ===========================================================================
 * Page chrome
 * ========================================================================= */

const JFSD_NAV = [
    ['href' => '/admin/',               'key' => 'dashboard',  'label' => 'Dashboard'],
    ['href' => '/admin/attendance.php', 'key' => 'attendance', 'label' => 'Attendance'],
    ['href' => '/admin/students.php',   'key' => 'students',   'label' => 'Students'],
    ['href' => '/admin/payments.php',   'key' => 'payments',   'label' => 'Payments'],
];

function jfsd_head(string $title, string $activeKey): void
{
    $config  = jfsd_config();
    $cssV    = (string) ($config['css_version'] ?? '1');
    $adminV  = (string) (@filemtime(__DIR__ . '/admin.css') ?: '1');
    $user    = admin_current_user() ?? '';
    ?>
<!DOCTYPE html>
<html lang="en" data-bg="charcoal" data-headline="humanist">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?= jfsd_e($title) ?> — JF Admin</title>
<meta name="theme-color" content="#0E0E10">
<link rel="icon" type="image/png" href="/assets/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css?v=<?= jfsd_e($cssV) ?>">
<link rel="stylesheet" href="/admin/admin.css?v=<?= jfsd_e($adminV) ?>">
</head>
<body class="adm">
<header class="adm-bar">
  <div class="adm-bar-in">
    <a class="adm-brand" href="/admin/">
      <span class="adm-brand-1">JF</span><span class="adm-brand-2">ADMIN</span>
    </a>
    <nav class="adm-nav">
      <?php foreach (JFSD_NAV as $item): ?>
        <a class="adm-nav-link<?= $item['key'] === $activeKey ? ' is-active' : '' ?>"
           href="<?= jfsd_e($item['href']) ?>"><?= jfsd_e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="adm-who">
      <span class="adm-who-email"><?= jfsd_e($user) ?></span>
      <a class="adm-signout" href="/admin/logout.php">Sign out</a>
    </div>
  </div>
</header>
<main class="adm-main">
    <?php
    $problem = jfsd_store_problem();
    if ($problem !== null):
        $detail = jfsd_store_problem_detail(); ?>
  <div class="adm-alert adm-alert-error">
    <strong>The data folder is not ready, so nothing can be saved.</strong>
    <p><?= jfsd_e($problem) ?></p>
        <?php if ($detail !== ''): ?>
    <details class="adm-detail">
      <summary>Technical details (for whoever set this up)</summary>
      <p><?= jfsd_e($detail) ?></p>
    </details>
        <?php endif; ?>
  </div>
    <?php endif; ?>
    <?php
    /* A JSON file exists but could not be read or decoded. The store has already
       refused every write; the danger left is the SCREEN, which would otherwise
       show "No students yet" and "Register not taken yet" — numbers that look
       like facts and are not. Say the word "damaged" and draw nothing else. */
    $fault = jfsd_data_fault();
    if ($fault !== null): ?>
  <div class="adm-alert adm-alert-error">
    <strong>Your saved records could not be read, so this page is not showing your real figures.</strong>
    <p>
      Nothing has been lost and nothing has been changed — but please do not add students,
      take a register or record a payment until this is fixed, because none of it will save.
    </p>
    <p>Please contact <?= jfsd_e(jfsd_support_contact()) ?>.</p>
    <details class="adm-detail">
      <summary>Technical details (for whoever set this up)</summary>
      <p><?= jfsd_e($fault) ?> Restore the matching <code>.json.bak</code> from the private directory.</p>
    </details>
  </div>
        <?php
        jfsd_foot();
        exit;
    endif; ?>
    <?php
    /* The admin shipped with a published password. If it is still in place,
       sign-in is refused (see login.php) — but anyone who did get in through a
       second account needs to know the front door is standing open. */
    $placeholders = admin_placeholder_accounts((array) (require __DIR__ . '/users.php'));
    if ($placeholders): ?>
  <div class="adm-alert adm-alert-error">
    <strong>The password for this admin is still the setup password.</strong>
    <p>
      It is written down publicly, so it is not protecting anything. Please ask the person
      who built this to change it before you enter any more student details.
    </p>
    <p class="adm-alert-sub">Affects: <?= jfsd_e(implode(', ', $placeholders)) ?></p>
  </div>
    <?php endif; ?>
    <?php
    $flash = jfsd_flash_get();
    if ($flash !== null):
        $type = (string) ($flash['type'] ?? 'success');
        $cls  = in_array($type, ['success', 'warn', 'error'], true) ? $type : 'success';
        ?>
  <div class="adm-alert adm-alert-<?= jfsd_e($cls) ?>"><?= jfsd_e((string) ($flash['msg'] ?? '')) ?></div>
    <?php endif;
}

function jfsd_foot(): void
{
    ?>
</main>
<footer class="adm-foot">
  <span>JF Self Defense — staff area</span>
  <span class="adm-foot-sep">/</span>
  <a href="https://jfselfdefense.com/" target="_blank" rel="noopener">View public site</a>
</footer>
</body>
</html>
    <?php
}

/** Page title block: eyebrow + heading + optional right-hand action slot. */
function jfsd_page_title(string $eyebrow, string $title, string $actionHtml = ''): void
{
    ?>
<div class="adm-head">
  <div>
    <div class="eyebrow"><span class="dot"></span><?= jfsd_e($eyebrow) ?></div>
    <h1 class="display h3 adm-h1"><?= jfsd_e($title) ?></h1>
  </div>
    <?php if ($actionHtml !== ''): ?>
  <div class="adm-head-actions"><?= $actionHtml /* trusted literal markup from our own pages */ ?></div>
    <?php endif; ?>
</div>
    <?php
}
