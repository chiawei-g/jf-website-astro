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

$today      = jfsd_today();
$roster     = jfsd_active_students($students);
$attention  = jfsd_needs_attention($students);
$slotsToday = jfsd_slots_on_date($today);

// Registers already saved for today, per slot.
$todayMarks = [];
foreach ($attendance as $row) {
    if (($row['date'] ?? '') === $today) {
        $todayMarks[(string) ($row['slot'] ?? '')][(string) ($row['student_id'] ?? '')] = (string) ($row['status'] ?? '');
    }
}

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

// Attendance across the last 7 days, as a simple health signal.
$weekAgo   = date('Y-m-d', (int) strtotime($today . ' -6 days'));
$weekPresent = 0;
foreach ($attendance as $row) {
    $d = (string) ($row['date'] ?? '');
    if ($d >= $weekAgo && $d <= $today && in_array($row['status'] ?? '', ['present', 'late'], true)) {
        $weekPresent++;
    }
}

jfsd_head('Dashboard', 'dashboard');
jfsd_page_title('Today', 'Dashboard');
?>

<?php if (!$students): ?>

<!-- ============ FIRST RUN ============ -->
<div class="adm-panel">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Nothing set up yet</h2>
  </div>
  <div class="adm-panel-b">
    <p class="adm-today-line">Start by adding the people who come to class.</p>
    <p class="adm-hint adm-mb">
      Once they are on the roster you can take a register and record payments, and this page
      starts telling you who has run out of sessions and needs chasing.
    </p>
    <a class="adm-btn adm-btn-red adm-btn-wide" href="/admin/students.php?new=1">Add your first student</a>
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
    <span class="adm-card-label">Attended this week</span>
    <span class="adm-card-val"><?= (int) $weekPresent ?></span>
    <span class="adm-card-sub">Marks of present or late since <?= jfsd_e(jfsd_date_friendly($weekAgo)) ?>.</span>
  </a>

  <a class="adm-card" href="/admin/payments.php">
    <span class="adm-card-label">Taken this month</span>
    <span class="adm-card-val"><?= jfsd_e(jfsd_money($monthTotal)) ?></span>
    <span class="adm-card-sub"><?= (int) $monthCount ?> payment<?= $monthCount === 1 ? '' : 's' ?> recorded in <?= jfsd_e(date('F', (int) strtotime($today))) ?>.</span>
  </a>
</div>

<!-- ============ TODAY'S CLASSES ============ -->
<div class="adm-panel">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Today &middot; <?= jfsd_e(jfsd_date_friendly($today)) ?></h2>
    <p class="adm-panel-note">Times are Singapore time.</p>
  </div>
  <div class="adm-panel-b<?= $slotsToday ? ' is-flush' : '' ?>">
    <?php if (!$slotsToday): ?>
      <div class="adm-empty">
        <strong>No class today.</strong>
        The studio runs Monday and Wednesday evenings, and Saturday and Sunday mornings.
      </div>
    <?php else: ?>
      <?php foreach ($slotsToday as $key => $slot):
          $marks   = $todayMarks[$key] ?? [];
          $present = 0;
          foreach ($marks as $status) {
              if (in_array($status, ['present', 'late'], true)) {
                  $present++;
              }
          }
          ?>
        <div class="adm-today">
          <div class="adm-today-when">
            <span class="adm-today-time"><?= jfsd_e($slot['start']) ?>–<?= jfsd_e($slot['end']) ?></span>
            <span class="adm-today-tag"><?= jfsd_e($slot['tag']) ?></span>
          </div>
          <div class="adm-today-body">
            <p class="adm-today-line">
              <?php if ($marks): ?>
                Register taken — <b><?= (int) $present ?></b> in class,
                <?= count($marks) ?> of <?= count($roster) ?> marked.
              <?php else: ?>
                Register not taken yet. <b><?= count($roster) ?></b> active student<?= count($roster) === 1 ? '' : 's' ?> expected.
              <?php endif; ?>
            </p>
            <p class="adm-hint">
              There is no per-class booking — everyone on the active roster is listed on the register,
              and you mark who actually turned up.
            </p>
          </div>
          <div class="adm-today-cta">
            <a class="adm-btn adm-btn-red" href="/admin/attendance.php?date=<?= rawurlencode($today) ?>&amp;slot=<?= rawurlencode((string) $key) ?>">
              <?= $marks ? 'Open register' : 'Take register' ?>
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ============ BALANCES THAT DO NOT ADD UP ============ -->
<?php if ($drift): ?>
  <div class="adm-panel">
    <div class="adm-panel-h">
      <h2 class="adm-panel-title">Balances that do not add up</h2>
      <p class="adm-panel-note">
        The sessions on the roster disagree with the payments and attendance recorded for
        these people. Nothing is lost — the payments and the register are the record, and
        the button puts the roster back in line with them.
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
const JFSD_ANALYTICS_ARE_FAKE = true;

$demoTraffic = [
    'visitors'  => '1,284',
    'sessions'  => '1,902',
    'avg_time'  => '1m 47s',
    'enquiries' => '23',
];
$demoPages = [
    ['/',                      '612', '48%'],
    ['/programmes',            '284', '22%'],
    ['/about',                 '141', '11%'],
    ['/articles',              '112', '9%'],
    ['/contact',               '68',  '5%'],
];
$demoQueries = [
    ['self defense singapore',            '2,400', '6.2'],
    ['womens self defence class',         '880',   '4.1'],
    ['krav maga singapore',               '1,300', '11.8'],
    ['self defense class near me',        '590',   '8.4'],
    ['family self defence singapore',     '210',   '3.0'],
];
$demoBadge = '<span class="adm-demo-pill">Demo data — not connected</span>';
?>

<div class="adm-panel adm-demo">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Website traffic — last 28 days</h2>
    <?= $demoBadge ?>
  </div>
  <div class="adm-panel-b">
    <div class="adm-cards adm-cards-flush">
      <div class="adm-card">
        <span class="adm-card-label">Visitors</span>
        <span class="adm-card-val"><?= jfsd_e($demoTraffic['visitors']) ?></span>
        <span class="adm-card-sub">Made-up number.</span>
      </div>
      <div class="adm-card">
        <span class="adm-card-label">Sessions</span>
        <span class="adm-card-val"><?= jfsd_e($demoTraffic['sessions']) ?></span>
        <span class="adm-card-sub">Made-up number.</span>
      </div>
      <div class="adm-card">
        <span class="adm-card-label">Avg. time on site</span>
        <span class="adm-card-val"><?= jfsd_e($demoTraffic['avg_time']) ?></span>
        <span class="adm-card-sub">Made-up number.</span>
      </div>
      <div class="adm-card">
        <span class="adm-card-label">Trial enquiries</span>
        <span class="adm-card-val"><?= jfsd_e($demoTraffic['enquiries']) ?></span>
        <span class="adm-card-sub">Made-up number.</span>
      </div>
    </div>
  </div>
  <p class="adm-demo-note">
    <strong>These are not your real figures.</strong>
    Google Analytics 4 is not set up for jfselfdefense.com yet, so there is nothing to read.
    The layout is here so the real numbers can be plugged in later.
  </p>
</div>

<div class="adm-panel adm-demo">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Top pages</h2>
    <?= $demoBadge ?>
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
  <p class="adm-demo-note">
    <strong>These are not your real pages.</strong>
    Invented rows, standing in for a GA4 report that does not exist yet.
  </p>
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
