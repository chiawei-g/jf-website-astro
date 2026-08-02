<?php
declare(strict_types=1);

// JF Self Defense admin — dashboard.
// Answers the three questions this whole thing exists for:
//   who is coming, did they show up, and have they paid.

define('JFSD_ADMIN', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_ui.php';
admin_require_auth();

$user = admin_current_user() ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf();
    if ((string) ($_POST['action'] ?? '') === 'repair_balance') {
        jfsd_flash_result(jfsd_repair_balance(jfsd_line((string) ($_POST['id'] ?? ''), 40), $user));
    }
    jfsd_redirect('/admin/');
}

$students   = jfsd_read('students');
$payments   = jfsd_read('payments');
$attendance = jfsd_read('attendance');
$sessions   = jfsd_read('sessions');

$today       = jfsd_today();
$roster      = jfsd_active_students($students);
$attention   = jfsd_needs_attention($students);
$classCounts = jfsd_attendance_counts($attendance);
$classesToday = jfsd_classes_on_date($sessions, $today);

// This calendar month's takings, studio time. Voided rows and balance
// corrections are not money and must never appear in this figure.
$monthPrefix = substr($today, 0, 7);
$monthTotal  = 0.0;
$monthCount  = 0;
foreach (jfsd_live_payments($payments) as $p) {
    if (!jfsd_payment_is_money($p)) {
        continue;
    }
    if (str_starts_with((string) ($p['date_received'] ?? ''), $monthPrefix)) {
        $monthTotal += (float) ($p['amount_sgd'] ?? 0);
        $monthCount++;
    }
}

/* Does every stored balance still match the payments and attendance behind it?
   This should always be empty. It is surfaced because when it is not, the only
   alternative is Jeffrey discovering it by accident, months later, on one
   student. */
$drift = jfsd_reconcile($students, $payments, $attendance);

/* Attendance across the last 7 days, as a simple health signal. Being on a
   class list IS having come, so there is nothing to filter for. A row carries
   no date of its own — the class record owns that — so this joins through it. */
$weekAgo      = date('Y-m-d', (int) strtotime($today . ' -6 days'));
$sessionDates = jfsd_session_dates($sessions);
$weekCame     = 0;
foreach ($attendance as $row) {
    $d = $sessionDates[(string) ($row['session_id'] ?? '')] ?? '';
    if ($d >= $weekAgo && $d <= $today) {
        $weekCame++;
    }
}

jfsd_head('Dashboard', 'dashboard');
jfsd_page_title('Today', 'Dashboard');
?>

<?php if (!$students): ?>

<!-- ============ FIRST RUN ============ -->
<?php /* No button here on purpose. People are put on the roster by another
         route, and the owner does not want that door opened from the dashboard. */ ?>
<div class="adm-panel">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Nothing here yet</h2>
  </div>
  <div class="adm-panel-b">
    <p class="adm-today-line">Nobody is on the roster, so there is nothing to show.</p>
    <p class="adm-hint">
      Once people are on it, this page tells you who came to class this week and who has
      run out of sessions and needs chasing. The calendar under Attendance already knows
      the usual class times.
    </p>
  </div>
</div>

<?php else: ?>

<div class="adm-cards">
  <a class="adm-card<?= $attention ? ' is-alert' : '' ?>" href="/admin/payments.php">
    <span class="adm-card-label">Needs attention</span>
    <span class="adm-card-val"><?= count($attention) ?></span>
    <span class="adm-card-sub">
      <?= $attention
          ? 'Active student' . (count($attention) === 1 ? '' : 's') . ' with no sessions left.'
          : 'Everyone active has sessions left.' ?>
    </span>
  </a>

  <a class="adm-card" href="/admin/students.php">
    <span class="adm-card-label">Active students</span>
    <span class="adm-card-val"><?= count($roster) ?></span>
    <span class="adm-card-sub"><?= count($students) ?> on the roster in total, including paused and past.</span>
  </a>

  <a class="adm-card" href="/admin/attendance.php">
    <span class="adm-card-label">Came to class this week</span>
    <span class="adm-card-val"><?= (int) $weekCame ?></span>
    <span class="adm-card-sub">People on a class list since <?= jfsd_e(jfsd_date_friendly($weekAgo)) ?>.</span>
  </a>

  <a class="adm-card" href="/admin/payments.php">
    <span class="adm-card-label">Taken this month</span>
    <span class="adm-card-val"><?= jfsd_e(jfsd_money($monthTotal)) ?></span>
    <span class="adm-card-sub"><?= (int) $monthCount ?> payment<?= $monthCount === 1 ? '' : 's' ?> recorded in <?= jfsd_e(date('F', (int) strtotime($today))) ?>.</span>
  </a>
</div>

<!-- ============ TODAY'S CLASSES ============ -->
<?php /* One link out of this panel, not one per class. Attendance is a single
         page now: it opens on today with the classes already written out at the
         bottom, so a second button per row would land in the same place. */
$todayGap = false;
foreach ($classesToday as $c) {
    $cid = (string) $c['id'];
    if (!$c['stored'] || (int) ($classCounts[$cid] ?? 0) === 0) {
        $todayGap = true;
    }
}
?>
<div class="adm-panel">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Today &middot; <?= jfsd_e(jfsd_date_friendly($today)) ?></h2>
    <p class="adm-panel-note">Times are Singapore time.</p>
  </div>
  <div class="adm-panel-b<?= $classesToday ? ' is-flush' : '' ?>">
    <?php if (!$classesToday): ?>
      <div class="adm-empty">
        <strong>No class today.</strong>
        Classes normally run on Monday and Wednesday evenings, and Saturday and Sunday
        mornings. <a href="/admin/attendance.php">Open the calendar</a> to see the week.
      </div>
    <?php else: ?>
      <?php foreach ($classesToday as $c):
          $cid  = (string) $c['id'];
          $came = ($c['stored'] && isset($classCounts[$cid])) ? (int) $classCounts[$cid] : 0;
          ?>
        <div class="adm-today">
          <div class="adm-today-when">
            <span class="adm-today-time"><?= jfsd_e(jfsd_time_short((string) $c['start'])) ?></span>
            <span class="adm-today-range"><?= jfsd_e(jfsd_time_range((string) $c['start'], (string) $c['end'])) ?></span>
          </div>
          <div class="adm-today-body">
            <p class="adm-today-line">
              <?php if ($came > 0): ?>
                <b><?= (int) $came ?></b> <?= $came === 1 ? 'person' : 'people' ?> on the list so far.
              <?php else: ?>
                Nobody on the list yet.
              <?php endif; ?>
            </p>
            <p class="adm-hint">
              <?php if ((string) $c['label'] !== ''): ?>
                <?= jfsd_e((string) $c['label']) ?>.
              <?php endif; ?>
              Type a name to put somebody on the list. Only the people who came go on it.
            </p>
          </div>
        </div>
      <?php endforeach; ?>
      <p class="adm-panel-more">
        <a href="/admin/attendance.php#day"><?= $todayGap ? 'Open today and add who came' : 'Open today' ?></a>
      </p>
    <?php endif; ?>
  </div>
</div>

<!-- ============ BALANCES THAT DO NOT ADD UP ============ -->
<?php if ($drift): ?>
  <div class="adm-panel">
    <div class="adm-panel-h">
      <h2 class="adm-panel-title">Balances that do not add up</h2>
      <p class="adm-panel-note">
        The sessions on the roster disagree with the payments and the class lists behind
        them for these people. Nothing is lost: the payments and the class lists are what
        actually happened, and the button puts the roster back in line with them.
      </p>
    </div>
    <div class="adm-panel-b is-flush">
      <p class="adm-swipe">Swipe the table sideways for the Fix button.</p>
      <div class="adm-scroll">
        <table class="adm-table">
          <thead>
            <tr><th>Name</th><th class="is-num">Roster says</th><th class="is-num">Should be</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($drift as $row):
                $s   = $row['student'];
                $sid = (string) ($s['id'] ?? '');
                ?>
              <tr>
                <td class="is-name"><a href="/admin/students.php?edit=<?= rawurlencode($sid) ?>"><?= jfsd_e((string) ($s['name'] ?? '')) ?></a></td>
                <td class="is-num"><?= (int) $row['stored'] ?></td>
                <td class="is-num"><span class="adm-pill adm-pill-alert"><?= (int) $row['expected'] ?></span></td>
                <td class="is-num">
                  <form class="adm-inline-form" method="post" action="/admin/"
                        onsubmit="return confirm(<?= jfsd_e((string) json_encode(
                            'Set ' . (string) ($s['name'] ?? 'this student') . ' to ' . (int) $row['expected']
                            . ' session(s), which is what the payments and attendance add up to?',
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        )) ?>);">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="repair_balance">
                    <input type="hidden" name="id" value="<?= jfsd_e($sid) ?>">
                    <button class="adm-btn adm-btn-quiet" type="submit">Set to <?= (int) $row['expected'] ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ============ NEEDS ATTENTION ============ -->
<div class="adm-panel">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Needs attention</h2>
    <p class="adm-panel-note">Active students at zero or below. <a href="/admin/payments.php">Record a payment</a></p>
  </div>
  <div class="adm-panel-b is-flush">
    <?php if (!$attention): ?>
      <div class="adm-empty">
        <strong>Nothing to chase.</strong>
        Every active student has sessions left.
      </div>
    <?php else: ?>
      <p class="adm-swipe">Swipe the table sideways for contact details and “Record payment”.</p>
      <div class="adm-scroll">
        <table class="adm-table">
          <thead>
            <tr><th>Name</th><th class="is-num">Sessions left</th><th>Plan</th><th>Contact</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($attention, 0, 8) as $s):
                $sid = (string) ($s['id'] ?? '');
                ?>
              <tr>
                <td class="is-name"><a href="/admin/students.php?edit=<?= rawurlencode($sid) ?>"><?= jfsd_e((string) ($s['name'] ?? '')) ?></a></td>
                <td class="is-num"><span class="adm-pill adm-pill-alert"><?= (int) ($s['sessions_remaining'] ?? 0) ?></span></td>
                <td><?= jfsd_e(JFSD_PLANS[$s['plan'] ?? '']['label'] ?? '—') ?></td>
                <td><?= jfsd_e((string) ($s['phone'] ?? ($s['email'] ?? ''))) ?></td>
                <td class="is-num"><a href="/admin/payments.php?student=<?= rawurlencode($sid) ?>">Record payment</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($attention) > 8): ?>
        <?php /* NOT .adm-demo-note — that amber marker means "these numbers are
                 invented" and belongs only inside .adm-demo. This is a real list. */ ?>
        <p class="adm-panel-more"><a href="/admin/payments.php">See all <?= count($attention) ?></a></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php endif; /* $students */ ?>

<?php
/* ===========================================================================
 * ANALYTICS AND SEARCH — PLACEHOLDERS ONLY
 * ---------------------------------------------------------------------------
 * GA4 and Google Search Console are NOT provisioned for jfselfdefense.com yet.
 * Every number below is invented. The shells exist so real data can be dropped
 * in later without redesigning the page: replace the arrays with the API
 * response, delete JFSD_ANALYTICS_ARE_FAKE, and the badges disappear.
 *
 * DO NOT soften the badges until the data is genuinely live.
 * ========================================================================= */
/* GA4 went live on this site 2026-07-27 (property 547369215, G-L650FNHQTS), so
 * traffic and pages now read from a real snapshot. Search Console is NOT
 * verified for jfselfdefense.com yet, so the queries panel is still invented
 * and still badged. One flag per panel, because "analytics" stopped being a
 * single thing the moment one half became real. */
$gaSnapshot = jfsd_ga_snapshot();
$trafficIsFake = ($gaSnapshot === null);
$pagesAreFake  = ($gaSnapshot === null);
const JFSD_QUERIES_ARE_FAKE = true;   // until GSC is verified

if ($gaSnapshot !== null) {
    $w = $gaSnapshot['windows']['d30']['metrics'] ?? [];
    $secs = (int) round((float) ($w['averageSessionDuration']['v'] ?? 0));
    $demoTraffic = [
        'visitors'  => number_format((int) ($w['totalUsers']['v'] ?? 0)),
        'sessions'  => number_format((int) ($w['sessions']['v'] ?? 0)),
        'avg_time'  => sprintf('%dm %02ds', intdiv($secs, 60), $secs % 60),
        'enquiries' => number_format((int) ($w['screenPageViews']['v'] ?? 0)),
    ];
    $rows  = $gaSnapshot['topPages'] ?? [];
    $total = array_sum(array_map(static fn($r) => (int) ($r['views'] ?? 0), $rows));
    $demoPages = [];
    foreach (array_slice($rows, 0, 6) as $r) {
        $views = (int) ($r['views'] ?? 0);
        $demoPages[] = [
            (string) ($r['path'] ?? '/'),
            number_format($views),
            $total > 0 ? round($views / $total * 100) . '%' : '—',
        ];
    }
    if ($demoPages === []) {
        $demoPages = [['No page views recorded yet', '0', '—']];
    }
} else {
    // No snapshot: say so plainly. Do NOT show invented numbers dressed as real.
    $demoTraffic = [
        'visitors'  => '—',
        'sessions'  => '—',
        'avg_time'  => '—',
        'enquiries' => '—',
    ];
    $demoPages = [['Analytics snapshot not available', '—', '—']];
}
$demoQueries = [
    ['self defense singapore',            '2,400', '6.2'],
    ['womens self defence class',         '880',   '4.1'],
    ['krav maga singapore',               '1,300', '11.8'],
    ['self defense class near me',        '590',   '8.4'],
    ['family self defence singapore',     '210',   '3.0'],
];
$demoBadge = '<span class="adm-demo-pill">Demo data — not connected</span>';
?>

<div class="adm-panel<?= $trafficIsFake ? ' adm-demo' : '' ?>">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Website traffic — last 30 days</h2>
    <?php if ($trafficIsFake): ?><?= $demoBadge ?><?php endif; ?>
  </div>
  <div class="adm-panel-b">
    <div class="adm-cards adm-cards-flush">
      <div class="adm-card">
        <span class="adm-card-label">Visitors</span>
        <span class="adm-card-val"><?= jfsd_e($demoTraffic['visitors']) ?></span>
        <span class="adm-card-sub">People, not visits.</span>
      </div>
      <div class="adm-card">
        <span class="adm-card-label">Sessions</span>
        <span class="adm-card-val"><?= jfsd_e($demoTraffic['sessions']) ?></span>
        <span class="adm-card-sub">Separate visits.</span>
      </div>
      <div class="adm-card">
        <span class="adm-card-label">Avg. time on site</span>
        <span class="adm-card-val"><?= jfsd_e($demoTraffic['avg_time']) ?></span>
        <span class="adm-card-sub">Per visit.</span>
      </div>
      <div class="adm-card">
        <span class="adm-card-label">Pages viewed</span>
        <span class="adm-card-val"><?= jfsd_e($demoTraffic['enquiries']) ?></span>
        <span class="adm-card-sub">Across all visits.</span>
      </div>
    </div>
  </div>
  <?php if ($trafficIsFake): ?>
    <p class="adm-demo-note">
      <strong>No figures available.</strong>
      The analytics snapshot is missing or has not refreshed in a few days, so
      nothing is shown rather than something out of date.
    </p>
  <?php else: ?>
    <p class="adm-panel-foot">
      From Google Analytics, last 30 days. Updated <?= jfsd_e(jfsd_ga_updated_label()) ?>.
    </p>
  <?php endif; ?>
</div>

<div class="adm-panel<?= $pagesAreFake ? ' adm-demo' : '' ?>">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Top pages</h2>
    <?php if ($pagesAreFake): ?><?= $demoBadge ?><?php endif; ?>
  </div>
  <div class="adm-panel-b is-flush">
    <div class="adm-scroll">
      <table class="adm-table">
        <thead><tr><th>Page</th><th class="is-num">Views</th><th class="is-num">Share</th></tr></thead>
        <tbody>
          <?php foreach ($demoPages as $row): ?>
            <tr>
              <td class="is-name"><?= jfsd_e($row[0]) ?></td>
              <td class="is-num"><?= jfsd_e($row[1]) ?></td>
              <td class="is-num"><?= jfsd_e($row[2]) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($pagesAreFake): ?>
    <p class="adm-demo-note">
      <strong>No page data available.</strong>
      The analytics snapshot is missing or stale.
    </p>
  <?php else: ?>
    <p class="adm-panel-foot">Most-viewed pages, last 30 days.</p>
  <?php endif; ?>
</div>

<div class="adm-panel adm-demo">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Search queries</h2>
    <?= $demoBadge ?>
  </div>
  <div class="adm-panel-b is-flush">
    <div class="adm-scroll">
      <table class="adm-table">
        <thead><tr><th>Query</th><th class="is-num">Impressions</th><th class="is-num">Avg. position</th></tr></thead>
        <tbody>
          <?php foreach ($demoQueries as $row): ?>
            <tr>
              <td class="is-name"><?= jfsd_e($row[0]) ?></td>
              <td class="is-num"><?= jfsd_e($row[1]) ?></td>
              <td class="is-num"><?= jfsd_e($row[2]) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <p class="adm-demo-note">
    <strong>These are not your real search rankings.</strong>
    Google Search Console has not been connected to this site, so no query data exists.
    Nobody has searched these terms and found you — the rows are illustrative only.
  </p>
</div>

<?php jfsd_foot(); ?>
