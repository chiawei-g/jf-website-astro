<?php
declare(strict_types=1);

// JF Self Defense admin — the class calendar and who came.
//
// ONE page, and there is no second one. Tapping a date writes that day under
// the grid. A day with no class behaves exactly the same way: it says so at
// the bottom and offers to put one on.
//
// AND IT DOES NOT RELOAD. ?live=1 returns just the region that changes —
// rendered further down this same file, by this same code — so the browser
// swaps that region in and the page never blinks or jumps. There is no second
// template and no client-side copy of any of this; the server is still the
// only thing that decides what a day looks like.
//
// The links and forms underneath are ordinary links and forms and still work
// on their own. The swap is layered on top, and anything that goes wrong with
// it falls back to letting the browser do what it was always going to do.
//
// Attendance is a LIST OF WHO TURNED UP, built by typing a name:
//     on the list  =  came  =  one session off
//     not on it    =  did not come, and there is nothing to record
// There is no absent, no excused, no late, and nobody is on the list until he
// puts them there. Around fifty people are on the books and four or five are
// in any one class, so asking him to work down fifty rows was the wrong shape.
//
// Every add and every remove is its own POST and takes effect immediately, so
// there is no Save button to forget and no half-finished state to warn about.
// Adding is idempotent by (class, student), which is what makes that safe.

define('JFSD_ADMIN', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_ui.php';
admin_require_auth();

$user  = admin_current_user() ?? '';
$today = jfsd_today();

/**
 * Is this a request for just the region that changes, rather than the page?
 *
 * Set by the swap in the browser and by nothing else. It never appears in the
 * address bar: the URL pushed into history is always the plain one, so the
 * page can be reloaded, bookmarked or shared and behave normally.
 */
$live = ($_GET['live'] ?? $_POST['live'] ?? '') === '1';

/**
 * How many days back a class with nobody on it still asks for attention.
 *
 * Red has to be worth looking at. A class he can still fix from memory is
 * worth a flag; one from six weeks ago is a permanent stain on a calendar he
 * cannot do anything about, and a grid that is always red stops meaning
 * anything at all. A week is long enough to catch a Sunday he was away for.
 */
const JFSD_ATTENTION_DAYS = 7;

/* ---------------------------------------------------------------------------
 * Links. Every one of these is echoed into an href, so the separator is
 * &amp; rather than a bare ampersand.
 * ------------------------------------------------------------------------- */

/** A date on the grid. The fragment is the whole point: it never leaves. */
function jfsd_day_href(string $ymd): string
{
    return '/admin/attendance.php?date=' . rawurlencode($ymd) . '#day';
}

/**
 * A month step. The chosen day travels with it, so paging back and forth to
 * look at something never quietly changes what is written underneath.
 */
function jfsd_month_href(string $ym, string $date): string
{
    return '/admin/attendance.php?m=' . rawurlencode($ym) . '&amp;date=' . rawurlencode($date);
}

/* ---------------------------------------------------------------------------
 * What is on one date, and whether it wants looking at.
 * ------------------------------------------------------------------------- */

/**
 * @param array<string,int> $counts class id => how many came
 * @param string|null $floor earliest date worth flagging — the day the first
 *        student joined. Before anyone was on the books there was nobody to
 *        add, so painting those days red would be noise.
 */
function jfsd_day_summary(
    array $sessions,
    array $counts,
    string $ymd,
    string $today,
    ?string $floor,
    string $windowFrom
): array {
    $classes = jfsd_classes_on_date($sessions, $ymd);
    $came    = 0;
    $empty   = 0;
    $missed  = 0;
    $first   = null;

    /* The wall clock, not just the date.
     *
     * A class that has not finished yet cannot be missing anybody — nothing has
     * happened. Judging on the date alone painted tonight's 7pm class red from
     * midnight, so the calendar opened every class day already accusing him of
     * a class that was still hours away, and the count at the top said "2
     * classes with nobody on the list" when one of them had not started. */
    $nowHm = (new DateTimeImmutable('now', new DateTimeZone(jfsd_tz())))->format('H:i');

    foreach ($classes as $class) {
        $id = (string) $class['id'];
        $n  = ($class['stored'] && isset($counts[$id])) ? (int) $counts[$id] : 0;
        $came += $n;
        if ($n === 0) {
            $empty++;
            if ($first === null) {
                $first = $class;
            }
            // Over and done with, and still nobody on it.
            if ($ymd < $today || ($ymd === $today && (string) ($class['end'] ?? '') <= $nowHm)) {
                $missed++;
            }
        }
    }

    return [
        'classes' => $classes,
        'total'   => count($classes),
        'came'    => $came,
        'empty'   => $empty,
        'first'   => $first,
        /* Red means "this one wants you", so it has to be true when he looks.
           A class still to come is not a problem, it is a plan. */
        'needs'   => $missed > 0
            && $ymd >= $windowFrom
            && $floor !== null && $ymd >= $floor,
    ];
}

/** Plain sentence for a calendar cell, read out by screen readers. */
function jfsd_day_spoken(string $ymd, array $sum, bool $isToday): string
{
    $when = jfsd_date_long($ymd) . ($isToday ? ', today' : '');
    if ($sum['total'] === 0) {
        return $when . ', no class';
    }
    $came = (int) $sum['came'];
    if ($sum['empty'] === 0) {
        return $when . ', ' . $came . ($came === 1 ? ' person came' : ' people came');
    }
    $gap = $sum['empty'] === 1 ? 'nobody on the list' : $sum['empty'] . ' classes with nobody on the list';
    return $came > 0 ? $when . ', ' . $came . ' came, ' . $gap : $when . ', ' . $gap;
}

/* ---------------------------------------------------------------------------
 * POST — every change redirects, so a refresh can never resubmit.
 *
 * A change that WORKED goes back to the day itself, silently: the list on
 * screen is the confirmation, and a banner at the top of the page would be
 * out of sight below the fold anyway. A change that FAILED drops the fragment
 * so the page opens at the top, where the banner is.
 *
 * A live swap follows the redirect itself and asks for the region instead of
 * the page. Both destinations are the same two, just wrapped differently: it
 * never moved the page, so there is no top to send it back to, and it carries
 * its own banner where he is already looking.
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf();

    $action   = (string) ($_POST['action'] ?? '');
    $postDate = jfsd_line((string) ($_POST['date'] ?? ''), 10);

    if (!jfsd_valid_date($postDate)) {
        jfsd_flash_set('error', 'That day could not be identified, so nothing was changed.');
        jfsd_redirect('/admin/attendance.php' . ($live ? '?live=1' : ''));
    }

    $tail = $live ? '&live=1' : '';
    $top  = '/admin/attendance.php?date=' . rawurlencode($postDate) . $tail;
    $done = $live ? $top : $top . '#day';

    /** @param array{ok:bool,msg:string} $result */
    $settle = static function (array $result, string $type = 'error') use ($top, $done): void {
        if ($result['ok'] ?? false) {
            jfsd_redirect($done);
        }
        jfsd_flash_set($type, (string) ($result['msg'] ?? ''));
        jfsd_redirect($top);
    };

    if ($action === 'add') {
        // One name from the find field, or several from "same as last week".
        // Both go through the same idempotent call, so neither can double up.
        $raw = $_POST['student'] ?? '';
        $ids = [];
        foreach (is_array($raw) ? $raw : [$raw] as $one) {
            if (is_string($one)) {
                $id = jfsd_line($one, 40);
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }
        if (!$ids) {
            jfsd_flash_set('warn', 'No name was chosen, so nobody was added.');
            jfsd_redirect($top);
        }
        $settle(jfsd_add_attendees(
            jfsd_line((string) ($_POST['class'] ?? ''), 40),
            $postDate,
            jfsd_line((string) ($_POST['at'] ?? ''), 5),
            array_keys($ids),
            $user
        ));
    }

    if ($action === 'remove') {
        $settle(jfsd_remove_attendee(
            jfsd_line((string) ($_POST['class'] ?? ''), 40),
            jfsd_line((string) ($_POST['student'] ?? ''), 40)
        ));
    }

    if ($action === 'add_class') {
        // Studio wifi is slow and the button gets tapped twice. Adding and
        // removing people is idempotent by design and needs no token; putting
        // a new class on the calendar is not.
        if (!jfsd_nonce_spend()) {
            jfsd_flash_set('warn', 'That was the same class sent twice, so it was only added once.');
            jfsd_redirect($top);
        }
        $settle(jfsd_add_session(
            $postDate,
            jfsd_line((string) ($_POST['start'] ?? ''), 5),
            jfsd_line((string) ($_POST['end'] ?? ''), 5),
            jfsd_line((string) ($_POST['note'] ?? ''), 60),
            $user
        ));
    }

    jfsd_redirect($done);
}

/* ---------------------------------------------------------------------------
 * GET — one month above, one day below. Always both.
 * ------------------------------------------------------------------------- */
$sessions   = jfsd_read('sessions');
$students   = jfsd_read('students');
$attendance = jfsd_read('attendance');

$counts = jfsd_attendance_counts($attendance);
$roster = jfsd_active_students($students);
$byId   = jfsd_index_students($students);

// The day the first person joined. Nothing before it is ever painted red.
$floor = null;
foreach ($students as $s) {
    $joined = (string) ($s['joined_date'] ?? '');
    if (jfsd_valid_date($joined) && ($floor === null || $joined < $floor)) {
        $floor = $joined;
    }
}

$windowFrom = date('Y-m-d', (int) strtotime($today . ' -' . (JFSD_ATTENTION_DAYS - 1) . ' days'));

// The chosen day. No date in the URL means today, so the page opens on the one
// day he is nearly always here for.
$dateParam = jfsd_line((string) ($_GET['date'] ?? ''), 10);
$date      = jfsd_valid_date($dateParam) ? $dateParam : $today;

// The month on the grid follows the chosen day unless he has stepped away.
$monthParam = jfsd_line((string) ($_GET['m'] ?? ''), 7);
$month      = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam) === 1
    ? $monthParam
    : substr($date, 0, 7);

$first = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone(jfsd_tz()));
if ($first === false) {
    $first = new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone(jfsd_tz()));
    $month = $first->format('Y-m');
}
$lead      = (int) $first->format('N') - 1;
$dayCount  = (int) $first->format('t');
$cellCount = (int) (ceil(($lead + $dayCount) / 7) * 7);

// Everything the month needs, worked out once.
$summaries = [];
$attention = 0;
$monthCame = 0;
for ($d = 1; $d <= $dayCount; $d++) {
    $ymd = $month . '-' . sprintf('%02d', $d);
    $sum = jfsd_day_summary($sessions, $counts, $ymd, $today, $floor, $windowFrom);
    $summaries[$ymd] = $sum;
    $monthCame += (int) $sum['came'];
    if ($sum['needs']) {
        $attention++;
    }
}

$day     = jfsd_day_summary($sessions, $counts, $date, $today, $floor, $windowFrom);
$isToday = $date === $today;

if (!$live) {
    jfsd_head('Attendance', 'attendance');
    jfsd_page_title('Attendance', 'Who came');
}
?>

<?php /* Everything a change can alter lives inside here, and this element is
         itself never replaced — so the delegated handlers bound to it survive
         every swap and nothing has to be rebound but the name fields. */ ?>
<div class="adm-live" id="att-live">

<?php
/* On a full load the banner is printed by jfsd_head, at the top of the page.
   A swap never redraws that far up, so the region carries its own — which is
   the better place for it anyway: beside the thing he just tapped, rather
   than above a calendar he has already scrolled past. */
if ($live):
    $flash = jfsd_flash_get();
    if ($flash !== null):
        $flashType = (string) ($flash['type'] ?? 'success');
        $flashCls  = in_array($flashType, ['success', 'warn', 'error'], true) ? $flashType : 'success';
        ?>
  <div class="adm-alert adm-alert-<?= jfsd_e($flashCls) ?>"><?= jfsd_e((string) ($flash['msg'] ?? '')) ?></div>
    <?php endif;
endif; ?>

<?php if (!$students): ?>
  <div class="adm-alert adm-alert-warn">
    <strong>There is nobody to add yet.</strong>
    <p>
      The calendar already knows the usual class times, but a name has to exist before it
      can go on a list. <a href="/admin/students.php?new=1">Add your first student</a>, then
      come back and tap the day.
    </p>
  </div>
<?php endif; ?>

<!-- ============ THE MONTH ============ -->
<div class="adm-cal-nav">
  <?php /* data-live marks a link the swap should handle. Anything without it
           navigates the ordinary way, which is what Students and Payments in
           the bar above need to keep doing. */ ?>
  <a class="adm-cal-step" data-live href="<?= jfsd_month_href($first->modify('-1 month')->format('Y-m'), $date) ?>"
     aria-label="Previous month" title="Previous month">&lsaquo;</a>
  <a class="adm-cal-step" data-live href="<?= jfsd_month_href($first->modify('+1 month')->format('Y-m'), $date) ?>"
     aria-label="Next month" title="Next month">&rsaquo;</a>
  <div class="adm-cal-heading">
    <h2 class="adm-cal-month"><?= jfsd_e(jfsd_month_label($month)) ?></h2>
  </div>
  <?php if (!$isToday || $month !== substr($today, 0, 7)): ?>
    <a class="adm-btn adm-btn-quiet" data-live href="/admin/attendance.php">Today</a>
  <?php endif; ?>
  <?php /* Its own line under the month and the arrows, not squeezed beside
           them: the sentence is long enough to wrap, and wrapping it under a
           52px button is how it ends up reading as two broken fragments. */ ?>
  <p class="adm-cal-count">
    <?php if (!$students): ?>
      Usual class times shown
    <?php elseif ($attention > 0): ?>
      <?= (int) $attention ?> class<?= $attention === 1 ? '' : 'es' ?> with nobody on the list
    <?php elseif ($monthCame > 0): ?>
      <?= (int) $monthCame ?> came to class this month
    <?php elseif ($month > substr($today, 0, 7)): ?>
      Still to come
    <?php else: ?>
      Nobody added this month
    <?php endif; ?>
  </p>
</div>

<div class="adm-cal-frame">
  <div class="adm-cal">
    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow): ?>
      <div class="adm-cal-dow"><?= jfsd_e($dow) ?></div>
    <?php endforeach; ?>

    <?php for ($cell = 0; $cell < $cellCount; $cell++):
        $dayNum = $cell - $lead + 1;
        if ($dayNum < 1 || $dayNum > $dayCount): ?>
          <div class="adm-cal-cell is-pad" aria-hidden="true"></div>
      <?php continue;
        endif;
        $ymd   = $month . '-' . sprintf('%02d', $dayNum);
        $sum   = $summaries[$ymd];
        $isNow = $ymd === $today;
        $isSel = $ymd === $date;

        /* Colour and glyph are independent, so the grid still reads with the
           red taken away: a display NUMBER is how many came, a mono TIME is a
           class nobody is on yet, and the date itself brightens through three
           steps as a day goes from quiet to worth looking at. */
        $state = 'is-none';
        if ($sum['total'] > 0) {
            if ($sum['needs']) {
                $state = 'is-todo';
            } elseif ($sum['came'] > 0) {
                $state = 'is-done';
            } else {
                $state = 'is-open';
            }
        }
        ?>
      <a class="adm-cal-cell <?= $state ?><?= $isNow ? ' is-today' : '' ?><?= $isSel ? ' is-picked' : '' ?>"
         data-live href="<?= jfsd_day_href($ymd) ?>"
         <?= $isSel ? 'aria-current="page"' : '' ?>
         aria-label="<?= jfsd_e(jfsd_day_spoken($ymd, $sum, $isNow)) ?>">
        <span class="adm-cal-date"><?= (int) $dayNum ?></span>
        <?php if ($sum['came'] > 0): ?>
          <span class="adm-cal-in"><?= (int) $sum['came'] ?></span>
        <?php elseif ($sum['first'] !== null): ?>
          <span class="adm-cal-time"><?= jfsd_e(jfsd_time_short((string) $sum['first']['start'])) ?></span>
        <?php endif; ?>
        <?php if ($sum['total'] > 1): ?>
          <span class="adm-cal-more"><?= (int) $sum['total'] ?> classes</span>
        <?php endif; ?>
      </a>
    <?php endfor; ?>
  </div>
</div>

<p class="adm-cal-legend">
  Tap any day to open it below. A number is how many came. Red is a class in the last few
  days with nobody on it yet.
</p>

<!-- ============ THE CHOSEN DAY — ALWAYS BELOW, NEVER ELSEWHERE ============ -->
<?php /* tabindex only so the browser has something to land on when it follows
         #day. The heading names the section rather than a fixed aria-label, so
         the two can never drift apart. */ ?>
<section class="adm-panel adm-day" id="day" tabindex="-1" aria-labelledby="day-title">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title" id="day-title"><?= $isToday ? 'Today' : jfsd_e(jfsd_date_long($date)) ?></h2>
    <p class="adm-panel-note"><?= jfsd_e(jfsd_date_friendly($date)) ?> &middot; Singapore time</p>
  </div>

  <div class="adm-panel-b is-flush">
    <?php /* A day with no class says nothing about itself. The panel heading
             above already names the date, the grid above that already showed
             the day was blank, and a paragraph explaining which evenings
             classes usually run on is three sentences telling him something he
             is the one who decided. All that is left to offer is the way to put
             a class on, so that is all there is. */ ?>
    <?php if ($day['total'] !== 0): ?>
      <?php foreach ($day['classes'] as $class):
          $cid   = (string) $class['id'];
          $start = (string) $class['start'];

          // Who is on this class, in name order.
          $onList = [];
          foreach (jfsd_attendees($attendance, $class['stored'] ? $cid : '') as $sid) {
              $onList[] = ['id' => $sid, 'student' => $byId[$sid] ?? null];
          }
          usort($onList, static fn(array $a, array $b): int => strcasecmp(
              (string) ($a['student']['name'] ?? "\u{FFFF}"),
              (string) ($b['student']['name'] ?? "\u{FFFF}")
          ));

          $onIds = [];
          foreach ($onList as $row) {
              $onIds[(string) $row['id']] = true;
          }

          // Same as last week: the equivalent class seven days back, minus
          // anyone already here, minus anyone who has since stopped coming.
          $repeat = [];
          $repeatOn = 0;
          $prev = jfsd_previous_week_class($sessions, $date, $start);
          if ($prev !== null) {
              foreach (jfsd_attendees($attendance, (string) ($prev['id'] ?? '')) as $sid) {
                  $who = $byId[$sid] ?? null;
                  if ($who === null || ($who['status'] ?? '') !== 'active') {
                      continue;
                  }
                  if (isset($onIds[$sid])) {
                      $repeatOn++;
                      continue;
                  }
                  $repeat[] = $who;
              }
              usort($repeat, static fn(array $a, array $b): int =>
                  strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
          }

          // Who is left to add.
          $addable = [];
          foreach ($roster as $s) {
              if (!isset($onIds[(string) ($s['id'] ?? '')])) {
                  $addable[] = $s;
              }
          }

          $fieldId = 'c' . substr(hash('crc32b', $cid . '|' . $start), 0, 6);
          ?>
        <article class="adm-cls">
          <div class="adm-cls-h">
            <h3 class="adm-cls-when"><?= jfsd_e(jfsd_class_when($date, $start, (string) $class['end'])) ?></h3>
            <p class="adm-cls-came">
              <?php if ($onList): ?>
                <b><?= count($onList) ?></b> came
              <?php else: ?>
                Nobody on the list yet
              <?php endif; ?>
            </p>
            <?php if ((string) $class['label'] !== ''): ?>
              <p class="adm-cls-note"><?= jfsd_e((string) $class['label']) ?></p>
            <?php endif; ?>
          </div>

          <?php if ($onList): ?>
            <ul class="adm-came">
              <?php foreach ($onList as $row):
                  $sid  = (string) $row['id'];
                  $who  = $row['student'];
                  $name = $who !== null ? (string) ($who['name'] ?? '') : '';
                  $left = (int) ($who['sessions_remaining'] ?? 0);
                  $low  = $who !== null && $left <= 0 && ($who['plan'] ?? '') !== 'corporate';
                  ?>
                <li class="adm-came-row">
                  <span class="adm-came-who">
                    <span class="adm-came-name"><?= $name !== '' ? jfsd_e($name) : 'Somebody no longer in your students' ?></span>
                    <?php if ($who !== null && ($who['plan'] ?? '') !== 'corporate'): ?>
                      <span class="adm-came-left<?= $low ? ' is-low' : '' ?>"><?= jfsd_e(jfsd_sessions_left_phrase($left)) ?></span>
                    <?php endif; ?>
                  </span>
                  <form method="post" action="/admin/attendance.php">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="date" value="<?= jfsd_e($date) ?>">
                    <input type="hidden" name="class" value="<?= jfsd_e($cid) ?>">
                    <input type="hidden" name="student" value="<?= jfsd_e($sid) ?>">
                    <button class="adm-btn adm-btn-quiet" type="submit">Remove</button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($repeat): ?>
            <form class="adm-repeat" method="post" action="/admin/attendance.php">
              <?= admin_csrf_field() ?>
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="date" value="<?= jfsd_e($date) ?>">
              <input type="hidden" name="class" value="<?= jfsd_e($cid) ?>">
              <input type="hidden" name="at" value="<?= jfsd_e($start) ?>">
              <?php foreach ($repeat as $s): ?>
                <input type="hidden" name="student[]" value="<?= jfsd_e((string) ($s['id'] ?? '')) ?>">
              <?php endforeach; ?>
              <p class="adm-repeat-who">
                Adds <?= jfsd_e(jfsd_join_names(array_map(
                    static fn(array $s): string => (string) ($s['name'] ?? ''),
                    $repeat
                ))) ?>.
                <?php if ($repeatOn > 0): ?>
                  <span class="adm-repeat-sub">
                    The other <?= $repeatOn === 1 ? 'one is' : (int) $repeatOn . ' are' ?> already here.
                  </span>
                <?php endif; ?>
              </p>
              <button class="adm-btn <?= $onList ? '' : 'adm-btn-red ' ?>adm-repeat-go" type="submit">
                Same as last week
              </button>
            </form>
          <?php endif; ?>

          <?php if (!$roster): ?>
            <p class="adm-cls-none">
              Nobody is active in your students, so there is no name to add.
              <a href="/admin/students.php">Open Students</a>
            </p>
          <?php elseif (!$addable): ?>
            <p class="adm-cls-none">Everybody is already on this list.</p>
          <?php else: ?>
            <?php /* The START TIME rides on data-add so that after a swap the
                     cursor can go back to the field it left, ready for the next
                     name, instead of to the top of a rebuilt page.

                     The time and not the class id, because a class that only
                     exists in the template has no id until somebody is put on
                     it — so keying on the id would lose the cursor on exactly
                     the add that starts the day, which is the common one. The
                     time is the same before and after it is stored. */ ?>
            <form class="adm-add" method="post" action="/admin/attendance.php"
                  data-add="<?= jfsd_e($start) ?>">
              <?= admin_csrf_field() ?>
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="date" value="<?= jfsd_e($date) ?>">
              <input type="hidden" name="class" value="<?= jfsd_e($cid) ?>">
              <input type="hidden" name="at" value="<?= jfsd_e($start) ?>">

              <?php /* Shown only once scripting is confirmed working. */ ?>
              <div class="adm-add-find" data-add-find hidden>
                <label class="adm-add-label" for="find-<?= jfsd_e($fieldId) ?>">Add someone who came</label>
                <input class="adm-add-in" id="find-<?= jfsd_e($fieldId) ?>" type="search"
                       placeholder="Type a few letters of a name"
                       autocomplete="off" autocapitalize="words" spellcheck="false"
                       enterkeyhint="done" data-add-in>
              </div>

              <?php /* The plain version. Works with no scripting at all, and it
                       is the same control the find field drives, so there is one
                       source of truth for who can be added. */ ?>
              <div class="adm-add-pick" data-add-pick>
                <label class="adm-add-label" for="pick-<?= jfsd_e($fieldId) ?>">Add someone who came</label>
                <div class="adm-add-row">
                  <select id="pick-<?= jfsd_e($fieldId) ?>" name="student" required data-add-sel>
                    <option value="">Choose a name</option>
                    <?php foreach ($addable as $s):
                        $sLeft = (int) ($s['sessions_remaining'] ?? 0);
                        $sLow  = $sLeft <= 0 && ($s['plan'] ?? '') !== 'corporate';
                        $sBal  = ($s['plan'] ?? '') === 'corporate' ? '' : jfsd_sessions_left_phrase($sLeft);
                        ?>
                      <option value="<?= jfsd_e((string) ($s['id'] ?? '')) ?>"
                              data-name="<?= jfsd_e((string) ($s['name'] ?? '')) ?>"
                              data-search="<?= jfsd_e(strtolower((string) ($s['name'] ?? ''))) ?>"
                              data-left="<?= jfsd_e($sBal) ?>"
                              data-low="<?= $sLow ? '1' : '0' ?>">
                        <?= jfsd_e((string) ($s['name'] ?? '')) ?><?= $sBal !== '' ? ' (' . jfsd_e($sBal) . ')' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button class="adm-btn" type="submit">Add</button>
                </div>
              </div>

              <div class="adm-hits" data-add-hits hidden></div>
              <p class="adm-add-state" data-add-state aria-live="polite" hidden></p>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php /* "another time" only means something when there is already a class
             to be another one than. On a blank day it is the only thing on
             offer, so it just says what it does. */ ?>
    <details class="adm-add-class"<?= $day['total'] === 0 ? ' open' : '' ?>>
      <summary><?= $day['total'] === 0 ? 'Add a class' : 'Add a class at another time' ?></summary>
      <div class="adm-add-class-b">
        <?php /* The panel heading already says which day this is, twice. */ ?>
        <p class="adm-hint adm-mb">For a one-off, or a time the venue moved.</p>
        <form class="trial-form" method="post" action="/admin/attendance.php">
          <?= admin_csrf_field() ?>
          <?= jfsd_nonce_field() ?>
          <input type="hidden" name="action" value="add_class">
          <input type="hidden" name="date" value="<?= jfsd_e($date) ?>">
          <div class="adm-form-grid">
            <label>Starts at
              <input type="time" name="start" required>
            </label>
            <label>Finishes at
              <input type="time" name="end" required>
            </label>
          </div>
          <label>Note <span class="adm-opt">(optional)</span>
            <input type="text" name="note" maxlength="60" autocomplete="off" placeholder="Makeup class">
          </label>
          <div class="adm-actions">
            <button class="adm-btn adm-btn-red" type="submit">Add this class</button>
          </div>
        </form>
      </div>
    </details>
  </div>
</section>

</div><!-- /.adm-live -->

<?php
/* A swap asked for the region and has now had it. Everything past this point
   is the page around it, which is already on screen and must not be sent
   twice — least of all the script, whose listeners are still bound. */
if ($live) {
    return;
}
?>

<script>
/* One page that genuinely stays one page.
 *
 * Tapping a date, stepping a month, adding somebody, taking them off — each of
 * these asks the server for the region above and swaps it in, so the page does
 * not blink, does not lose its place, and does not throw him back to the top.
 *
 * The server is still the only thing that decides what a day looks like. ?live=1
 * returns the same markup, from the same PHP, and nothing here knows what a
 * class or a balance is. There is no second copy to drift.
 *
 * Everything underneath is a real link and a real form. This is layered over
 * them, and every failure path — no fetch, bad response, network gone, wrong
 * markup back — hands control to the browser to do what it would have done
 * anyway. The worst case is the page reloading, which is where we started. */
(function () {
  'use strict';

  var LIVE = document.getElementById('att-live');
  if (!LIVE) { return; }

  var MAX_HITS = 8;

  function text(tag, cls, value) {
    var el = document.createElement(tag);
    if (cls) { el.className = cls; }
    el.textContent = value;
    return el;
  }

  /* =========================================================================
   * THE TYPE-AND-TAP NAME FIELD
   *
   * The <select> stays in the page and stays the thing that gets submitted, so
   * there is exactly one list of who can be added and the no-scripting path is
   * the same form rather than a second implementation. With scripting off, the
   * picker and its Add button are simply what you get.
   * ====================================================================== */
  function enhanceAdd(form) {
    var find  = form.querySelector('[data-add-find]');
    var input = form.querySelector('[data-add-in]');
    var pick  = form.querySelector('[data-add-pick]');
    var sel   = form.querySelector('[data-add-sel]');
    var hits  = form.querySelector('[data-add-hits]');
    var state = form.querySelector('[data-add-state]');
    if (!find || !input || !pick || !sel || !hits || !state) { return; }

    var options = Array.prototype.slice.call(sel.options).filter(function (o) { return o.value; });
    if (!options.length) { return; }

    /* Swap the controls only now that everything needed is present. The select
       keeps its name and its value; it is just no longer the thing he touches.
       'required' comes off because a hidden required control blocks submit. */
    find.hidden = false;
    pick.hidden = true;
    sel.required = false;

    var sent = false;

    function matching(query) {
      var q = query.trim().toLowerCase();
      if (q === '') { return []; }
      return options.filter(function (o) {
        var words = (o.getAttribute('data-search') || '').split(' ');
        for (var i = 0; i < words.length; i++) {
          if (words[i].indexOf(q) === 0) { return true; }
        }
        return false;
      });
    }

    function choose(option) {
      if (sent) { return; }
      sent = true;
      var name = option.getAttribute('data-name') || 'that person';
      sel.value = option.value;
      input.value = name;
      input.disabled = true;
      hits.hidden = true;
      state.hidden = false;
      state.textContent = 'Adding ' + name + '.';
      /* Straight to send() rather than form.submit(): a scripted submit()
         fires no submit event, so going through the DOM here would slip past
         the delegated handler and reload the page — the exact thing this is
         all for. send() falls back to a real submit if it cannot proceed. */
      send(form);
    }

    function draw() {
      var found = matching(input.value);
      hits.textContent = '';

      if (input.value.trim() === '') {
        hits.hidden = true;
        return;
      }
      hits.hidden = false;

      if (!found.length) {
        var none = text('p', 'adm-hits-none', 'Nobody by that name. ');
        var link = document.createElement('a');
        link.href = '/admin/students.php?new=1';
        link.textContent = 'Add them to your students first';
        none.appendChild(link);
        none.appendChild(document.createTextNode('.'));
        hits.appendChild(none);
        return;
      }

      found.slice(0, MAX_HITS).forEach(function (option) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'adm-hit';
        button.appendChild(text('span', 'adm-hit-name', option.getAttribute('data-name') || ''));
        var left = option.getAttribute('data-left') || '';
        if (left) {
          button.appendChild(text(
            'span',
            'adm-hit-left' + (option.getAttribute('data-low') === '1' ? ' is-low' : ''),
            left
          ));
        }
        button.addEventListener('click', function () { choose(option); });
        hits.appendChild(button);
      });

      if (found.length > MAX_HITS) {
        hits.appendChild(text(
          'p',
          'adm-hits-more',
          MAX_HITS + ' of ' + found.length + ' names. Type another letter.'
        ));
      }
    }

    input.addEventListener('input', draw);
    input.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') { return; }
      // Never let Enter submit the picker's empty value.
      e.preventDefault();
      var found = matching(input.value);
      if (found.length) { choose(found[0]); }
    });
  }

  /* Bind the parts of a freshly swapped region that need their own listeners.
     The clicks and submits are delegated to LIVE, which is never replaced, so
     this is only ever the name fields. */
  function enhance(root) {
    Array.prototype.forEach.call(root.querySelectorAll('[data-add]'), enhanceAdd);
  }

  /* =========================================================================
   * THE SWAP
   * ====================================================================== */

  /* Without all four, every link and form stays exactly as the server sent it
     and the browser navigates. The name field still works — that part needs
     nothing but the DOM — so this degrades to the page we had yesterday. */
  var CAN_SWAP = !!(window.fetch && window.DOMParser && window.history && history.pushState);

  var busy = false;

  /** The address bar version of a URL: whatever we asked, minus our own flag. */
  function plain(href) {
    var u = new URL(href, location.href);
    u.searchParams.delete('live');
    u.hash = '';
    return u.pathname + u.search;
  }

  /** The version we ask the server for. */
  function region(href) {
    var u = new URL(href, location.href);
    u.searchParams.set('live', '1');
    u.hash = '';
    return u.href;
  }

  function start() {
    busy = true;
    LIVE.setAttribute('aria-busy', 'true');
    LIVE.classList.add('is-swapping');
  }

  function stop() {
    busy = false;
    LIVE.removeAttribute('aria-busy');
    LIVE.classList.remove('is-swapping');
  }

  /* Bring the day into view only when it is not already there.
   *
   * On his phone the calendar fills the screen and the day sits under it, so a
   * tap that changed nothing visible would read as a tap that did nothing.
   * On a laptop both are on screen at once and moving the page would be the
   * rude thing to do. Hence: look first, and glide rather than jump. */
  function reveal() {
    var day = document.getElementById('day');
    if (!day) { return; }
    var box = day.getBoundingClientRect();
    var vh  = window.innerHeight || document.documentElement.clientHeight;
    if (box.top >= 0 && box.top <= vh * 0.7) { return; }
    var slow = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    day.scrollIntoView({ behavior: slow ? 'auto' : 'smooth', block: 'start' });
  }

  /**
   * Put the new region on screen.
   * @param html   the response body — a full document containing our region
   * @param url    what the address bar should say afterwards
   * @param toTime start time of the class whose name field should get the
   *               cursor back, if any
   * @param mode   'push' for a day he chose, 'replace' for a change to the day
   *               he is already on, 'none' when the browser has already moved
   */
  function paint(html, url, toTime, mode) {
    var next = new DOMParser().parseFromString(html, 'text/html').getElementById('att-live');
    if (!next) { throw new Error('no region in response'); }

    LIVE.innerHTML = next.innerHTML;
    enhance(LIVE);

    /* Adding somebody is not a place he navigated to, so it replaces rather
       than pushes. Otherwise four names added to a Sunday would be four Backs
       that all look like nothing happening. */
    if (mode === 'push') { history.pushState({ live: true }, '', url); }
    else if (mode === 'replace') { history.replaceState({ live: true }, '', url); }

    /* Back to the field he was typing in, so the second name is just typing.
       Only when he was already there — never steal focus on a date tap, which
       on a phone would open the keyboard over the list he asked to see. */
    if (toTime) {
      var key = window.CSS && CSS.escape ? CSS.escape(toTime) : toTime;
      var back = LIVE.querySelector('[data-add="' + key + '"] [data-add-in]');
      if (back && back.offsetParent !== null) {
        try { back.focus({ preventScroll: true }); } catch (e) { back.focus(); }
      }
    }
    reveal();
  }

  /** Follow a link without leaving the page. */
  function go(href, mode) {
    if (busy) { return; }
    start();
    fetch(region(href), { credentials: 'same-origin', headers: { 'X-Live': '1' } })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.text();
      })
      .then(function (html) {
        paint(html, plain(href), '', mode);
        stop();
      })
      .catch(function () {
        // Signed out, offline, or something we did not expect. Let the browser
        // go there properly — it will land on the login page if that is why.
        location.href = href;
      });
  }

  /** Send a form without leaving the page. */
  function send(form) {
    if (!CAN_SWAP) { form.submit(); return; }
    if (busy) { return; }
    var data;
    try {
      data = new FormData(form);
    } catch (e) {
      form.submit();
      return;
    }
    data.set('live', '1');
    var toTime = form.getAttribute('data-add') || '';

    /* getAttribute, NOT form.action.
     *
     * Every one of these forms carries <input name="action">, and a named
     * control shadows the form property of the same name — so form.action
     * hands back that input element, which stringifies into a URL like
     * /admin/[object HTMLInputElement]. The attribute is the actual markup and
     * cannot be shadowed. Same reasoning for method below. */
    var action = form.getAttribute('action') || location.pathname;

    start();
    fetch(action, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: { 'X-Live': '1' }
    })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.text().then(function (html) { return { html: html, url: res.url }; });
      })
      .then(function (out) {
        paint(out.html, plain(out.url), toTime, 'replace');
        stop();
      })
      .catch(function () {
        // The change may or may not have gone through, and guessing would be
        // worse than showing him. A plain submit is safe: adding and removing
        // are idempotent, and the class form is guarded by its own token.
        stop();
        form.submit();
      });
  }

  /* ---- what gets intercepted ---------------------------------------------
     Delegated to LIVE, which no swap ever replaces, so these are bound once
     and go on working for markup that does not exist yet. */

  LIVE.addEventListener('click', function (e) {
    if (!CAN_SWAP) { return; }
    // A modified click is a deliberate new tab. Leave it alone.
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    var link = e.target.closest && e.target.closest('a[data-live]');
    if (!link || !LIVE.contains(link)) { return; }
    e.preventDefault();
    go(link.href, 'push');
  });

  LIVE.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') { return; }
    if ((form.getAttribute('method') || '').toLowerCase() !== 'post') { return; }

    /* Acknowledge the tap within the same frame, so a slow round trip on
       studio wifi does not look like nothing happened. Safe on the plain path
       too: the browser has already taken the submission by the time this runs
       and every one of these actions can be repeated without harm. */
    var button = form.querySelector('button[type="submit"]');
    if (button) { setTimeout(function () { button.disabled = true; }, 0); }

    if (!CAN_SWAP) { return; }
    e.preventDefault();
    send(form);
  });

  /* Back and forward move between days, because each one was pushed. The
     browser has already changed the URL by the time this fires, so the region
     is fetched to match it and nothing is written to history. */
  window.addEventListener('popstate', function () {
    go(location.href, 'none');
  });

  if (CAN_SWAP) {
    // Give the first entry a state object, and drop any leftover #day so the
    // address bar matches every URL the swap will push after it.
    history.replaceState({ live: true }, '', location.pathname + location.search);
  }
  enhance(LIVE);
})();
</script>

<?php jfsd_foot(); ?>
