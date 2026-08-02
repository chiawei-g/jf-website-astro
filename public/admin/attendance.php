<?php
declare(strict_types=1);

// JF Self Defense admin — the class calendar and the register.
//
// Three screens, one file:
//   calendar  no date in the URL. A month at a glance: which days have a class,
//             which registers are still to take, and how many turned up.
//   day       ?date=YYYY-MM-DD. The classes on one date, earliest first, plus a
//             way to put another one on that day.
//   register  ?date=...&class=ses_...  (a class that already has a record)
//             ?date=...&at=HH:MM       (one the weekly pattern only suggests)
//
// The second form of the register URL survives the class being written for the
// first time: once a record exists at that start time, the same link resolves to
// the record instead of the suggestion.

define('JFSD_ADMIN', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_ui.php';
admin_require_auth();

$user  = admin_current_user() ?? '';
$today = jfsd_today();

/* ---------------------------------------------------------------------------
 * Links. Every one of these is echoed straight into an href, so the separator
 * is &amp; rather than a bare ampersand.
 * ------------------------------------------------------------------------- */

function jfsd_month_href(string $ym): string
{
    return '/admin/attendance.php?m=' . rawurlencode($ym);
}

function jfsd_day_href(string $ymd): string
{
    return '/admin/attendance.php?date=' . rawurlencode($ymd);
}

function jfsd_class_href(array $class): string
{
    $url = jfsd_day_href((string) $class['date']);
    return ($class['stored'] ?? false)
        ? $url . '&amp;class=' . rawurlencode((string) $class['id'])
        : $url . '&amp;at=' . rawurlencode((string) $class['start']);
}

/* ---------------------------------------------------------------------------
 * What is on one date, and whether it still wants doing.
 * ------------------------------------------------------------------------- */

/**
 * @param array<string,array{marked:int,in:int}> $counts
 * @param string|null $floor earliest date worth nagging about — the day the
 *        first student joined. Before anyone was on the roster there was
 *        nothing to mark, so painting those days red would be noise.
 */
function jfsd_day_summary(array $sessions, array $counts, string $ymd, string $today, ?string $floor): array
{
    $classes  = jfsd_classes_on_date($sessions, $ymd);
    $attended = 0;
    $done     = 0;
    $open     = null;

    foreach ($classes as $class) {
        $id     = (string) $class['id'];
        $marked = ($class['stored'] && isset($counts[$id])) ? (int) $counts[$id]['marked'] : 0;
        if ($marked > 0) {
            $done++;
            $attended += (int) $counts[$id]['in'];
        } elseif ($open === null) {
            $open = $class;
        }
    }

    return [
        'classes'  => $classes,
        'total'    => count($classes),
        'done'     => $done,
        'attended' => $attended,
        'open'     => $open,
        'needs'    => $open !== null && $ymd <= $today && $floor !== null && $ymd >= $floor,
    ];
}

/** Plain sentence for a calendar cell, read out by screen readers and long-press. */
function jfsd_day_spoken(string $ymd, array $sum): string
{
    $when = jfsd_date_long($ymd);
    if ($sum['total'] === 0) {
        return $when . ', no class';
    }
    if ($sum['open'] === null) {
        return $when . ', register taken, ' . $sum['attended'] . ' in class';
    }
    $what = $sum['total'] === 1
        ? 'class at ' . jfsd_time_friendly((string) $sum['open']['start'])
        : $sum['total'] . ' classes, next one at ' . jfsd_time_friendly((string) $sum['open']['start']);
    return $when . ', ' . $what . ', register not taken';
}

/**
 * One row of the register. Identical markup for someone on the active roster and
 * someone who has since paused or left, so a historical mark stays correctable
 * either way.
 *
 * @param array<string,string> $saved student_id => saved status
 */
function jfsd_reg_row(array $s, array $saved): void
{
    $sid     = (string) ($s['id'] ?? '');
    $current = $saved[$sid] ?? '';
    $left    = (int) ($s['sessions_remaining'] ?? 0);
    $isLow   = $left <= 0 && ($s['plan'] ?? '') !== 'corporate';
    // Said in full rather than as a minus sign. "-9 left" is a puzzle; "9 more
    // than paid for" is a fact, and neither of them is a reason not to tap.
    $balance = $left > 0
        ? $left . ' left'
        : ($left === 0 ? 'none left' : abs($left) . ' more than paid for');
    ?>
  <div class="adm-reg-row<?= $current !== '' ? ' is-marked' : '' ?>">
    <div class="adm-reg-who">
      <div class="adm-reg-name"><?= jfsd_e((string) ($s['name'] ?? '')) ?></div>
      <div class="adm-reg-meta">
        <span class="adm-reg-left<?= $isLow ? ' is-low' : '' ?>"><?= jfsd_e($balance) ?></span>
        <span class="adm-reg-unmarked"<?= $current !== '' ? ' hidden' : '' ?>>not marked yet</span>
      </div>
    </div>
    <div class="adm-seg">
    <?php foreach (JFSD_ATTENDANCE_STATUSES as $key => $meta):
        $inputId = 'm-' . jfsd_e($sid) . '-' . jfsd_e($key);
        ?>
      <input type="radio"
             id="<?= $inputId ?>"
             name="mark[<?= jfsd_e($sid) ?>]"
             value="<?= jfsd_e($key) ?>"
             data-mark="<?= jfsd_e($key) ?>"
             <?= $current === $key ? 'checked' : '' ?>>
      <label for="<?= $inputId ?>"><?= jfsd_e($meta['label']) ?></label>
    <?php endforeach; ?>
    </div>
  </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * POST — every mutation redirects, so a refresh can never resubmit.
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf();

    $action   = (string) ($_POST['action'] ?? '');
    $postDate = jfsd_line((string) ($_POST['date'] ?? ''), 10);

    if (!jfsd_valid_date($postDate)) {
        jfsd_flash_set('error', 'That day could not be identified, so nothing was saved.');
        jfsd_redirect('/admin/attendance.php');
    }
    $dayUrl = '/admin/attendance.php?date=' . rawurlencode($postDate);

    if ($action === 'add_class') {
        // Studio wifi is slow and the button gets tapped twice. The register is
        // idempotent by design and needs no token; adding a class is not.
        if (!jfsd_nonce_spend()) {
            jfsd_flash_set('warn', 'That was the same class sent twice, so it was only added once.');
            jfsd_redirect($dayUrl);
        }
        jfsd_flash_result(jfsd_add_session(
            $postDate,
            jfsd_line((string) ($_POST['start'] ?? ''), 5),
            jfsd_line((string) ($_POST['end'] ?? ''), 5),
            jfsd_line((string) ($_POST['note'] ?? ''), 60),
            $user
        ));
        jfsd_redirect($dayUrl);
    }

    if ($action === 'save_register') {
        $postClass = jfsd_line((string) ($_POST['class'] ?? ''), 40);
        $postAt    = jfsd_line((string) ($_POST['at'] ?? ''), 5);
        $back      = $dayUrl . ($postClass !== ''
            ? '&class=' . rawurlencode($postClass)
            : '&at=' . rawurlencode($postAt));

        $rawMarks = $_POST['mark'] ?? [];
        $marks    = [];
        if (is_array($rawMarks)) {
            foreach ($rawMarks as $studentId => $status) {
                if (!is_string($studentId) || !is_string($status)) {
                    continue;
                }
                $id = jfsd_line($studentId, 40);
                if ($id !== '' && isset(JFSD_ATTENDANCE_STATUSES[$status])) {
                    $marks[$id] = $status;
                }
            }
        }

        if (!$marks) {
            jfsd_flash_set('warn', 'Nobody was marked, so nothing changed.');
        } else {
            jfsd_flash_result(jfsd_save_attendance($postClass, $postDate, $postAt, $marks, $user));
        }
        jfsd_redirect($back);
    }

    jfsd_redirect('/admin/attendance.php');
}

/* ---------------------------------------------------------------------------
 * GET — which of the three screens is this?
 * ------------------------------------------------------------------------- */
$sessions   = jfsd_read('sessions');
$students   = jfsd_read('students');
$attendance = jfsd_read('attendance');

$counts = jfsd_register_counts($attendance);
$roster = jfsd_active_students($students);

// The day the first person joined. Nothing before it is ever painted as unfinished.
$floor = null;
foreach ($students as $s) {
    $joined = (string) ($s['joined_date'] ?? '');
    if (jfsd_valid_date($joined) && ($floor === null || $joined < $floor)) {
        $floor = $joined;
    }
}

$dateParam  = jfsd_line((string) ($_GET['date'] ?? ''), 10);
$classParam = jfsd_line((string) ($_GET['class'] ?? ''), 40);
$atParam    = jfsd_line((string) ($_GET['at'] ?? ''), 5);

$date    = jfsd_valid_date($dateParam) ? $dateParam : '';
$class   = null;
$missing = false;

if ($date !== '' && ($classParam !== '' || $atParam !== '')) {
    foreach (jfsd_classes_on_date($sessions, $date) as $candidate) {
        $hit = $classParam !== ''
            ? ($candidate['stored'] && (string) $candidate['id'] === $classParam)
            : ((string) $candidate['start'] === $atParam);
        if ($hit) {
            $class = $candidate;
            break;
        }
    }
    $missing = $class === null;
}

$view = $class !== null ? 'register' : ($date !== '' ? 'day' : 'calendar');

// Which month the grid shows. Always follows the date being looked at.
$monthParam = jfsd_line((string) ($_GET['m'] ?? ''), 7);
if ($date !== '') {
    $month = substr($date, 0, 7);
} elseif (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam) === 1) {
    $month = $monthParam;
} else {
    $month = substr($today, 0, 7);
}

jfsd_head('Attendance', 'attendance');

/* ===========================================================================
 * SCREEN 1 — THE CALENDAR
 * ========================================================================= */
if ($view === 'calendar'):

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
    $stillToDo = 0;
    for ($d = 1; $d <= $dayCount; $d++) {
        $ymd = $month . '-' . sprintf('%02d', $d);
        $sum = jfsd_day_summary($sessions, $counts, $ymd, $today, $floor);
        $summaries[$ymd] = $sum;
        if ($sum['needs']) {
            $stillToDo++;
        }
    }

    $todaySum   = jfsd_day_summary($sessions, $counts, $today, $today, $floor);
    $isThisMonth = $month === substr($today, 0, 7);

    // The next date with a class on it, for the days there is nothing on.
    $nextDate = '';
    for ($i = 1; $i <= 14; $i++) {
        $look = date('Y-m-d', (int) strtotime($today . ' +' . $i . ' days'));
        if (jfsd_classes_on_date($sessions, $look) !== []) {
            $nextDate = $look;
            break;
        }
    }

    jfsd_page_title('Attendance', 'Calendar');
    ?>

  <?php if (!$students): ?>
    <div class="adm-panel">
      <div class="adm-panel-h">
        <h2 class="adm-panel-title">Nothing to mark yet</h2>
      </div>
      <div class="adm-panel-b">
        <p class="adm-today-line">Nobody is on the roster, so there is no register to take.</p>
        <p class="adm-hint">
          The calendar below already knows the usual class times. Once there are students,
          tap a day to open the class and mark who turned up, and the day starts showing
          the number who came.
        </p>
      </div>
    </div>
  <?php endif; ?>

  <div class="adm-cal-nav">
    <a class="adm-cal-step" href="<?= jfsd_month_href($first->modify('-1 month')->format('Y-m')) ?>"
       aria-label="Previous month" title="Previous month">&lsaquo;</a>
    <a class="adm-cal-step" href="<?= jfsd_month_href($first->modify('+1 month')->format('Y-m')) ?>"
       aria-label="Next month" title="Next month">&rsaquo;</a>
    <div class="adm-cal-heading">
      <h2 class="adm-cal-month"><?= jfsd_e(jfsd_month_label($month)) ?></h2>
      <p class="adm-cal-count">
        <?php if (!$students): ?>
          Usual class times shown
        <?php elseif ($stillToDo > 0): ?>
          <?= (int) $stillToDo ?> register<?= $stillToDo === 1 ? '' : 's' ?> still to take
        <?php else: ?>
          Every class marked
        <?php endif; ?>
      </p>
    </div>
    <?php if (!$isThisMonth): ?>
      <a class="adm-btn adm-btn-quiet" href="/admin/attendance.php">This month</a>
    <?php endif; ?>
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
          $ymd     = $month . '-' . sprintf('%02d', $dayNum);
          $sum     = $summaries[$ymd];
          $isToday = $ymd === $today;
          $state   = 'is-none';
          if ($sum['total'] > 0) {
              $state = $sum['open'] === null ? 'is-done' : ($sum['needs'] ? 'is-todo' : 'is-ahead');
          }
          ?>
        <a class="adm-cal-cell <?= $state ?><?= $isToday ? ' is-today' : '' ?>"
           href="<?= jfsd_day_href($ymd) ?>"
           aria-label="<?= jfsd_e(jfsd_day_spoken($ymd, $sum)) ?>">
          <span class="adm-cal-date"><?= (int) $dayNum ?></span>
          <?php if ($sum['total'] === 0): ?>
          <?php elseif ($sum['open'] === null): ?>
            <span class="adm-cal-in"><?= (int) $sum['attended'] ?></span>
          <?php else: ?>
            <span class="adm-cal-time"><?= jfsd_e(jfsd_time_short((string) $sum['open']['start'])) ?></span>
          <?php endif; ?>
          <?php if ($sum['total'] > 1): ?>
            <span class="adm-cal-more"><?= (int) $sum['total'] ?> classes</span>
          <?php endif; ?>
        </a>
      <?php endfor; ?>
    </div>
  </div>

  <p class="adm-cal-legend">
    A number is how many were in class that day. Red is a register still to take.
  </p>

  <div class="adm-now">
    <p class="adm-now-when">Today, <?= jfsd_e(jfsd_date_long($today)) ?></p>
    <?php if ($todaySum['total'] === 0): ?>
      <p class="adm-now-line">
        No class today.
        <?= $nextDate !== '' ? 'The next one is ' . jfsd_e(jfsd_date_long($nextDate)) . '.' : '' ?>
      </p>
      <?php if ($nextDate !== ''): ?>
        <a class="adm-btn adm-now-btn" href="<?= jfsd_day_href($nextDate) ?>">Open that day</a>
      <?php endif; ?>
    <?php else: ?>
      <?php foreach ($todaySum['classes'] as $c):
          $id     = (string) $c['id'];
          $marked = ($c['stored'] && isset($counts[$id])) ? (int) $counts[$id]['marked'] : 0;
          ?>
        <div class="adm-now-class">
          <p class="adm-now-line">
            <b><?= jfsd_e(jfsd_time_friendly((string) $c['start'])) ?></b>
            <?= $c['label'] !== '' ? jfsd_e($c['label']) . '. ' : '' ?>
            <?php if ($marked > 0): ?>
              Register taken, <?= (int) $counts[$id]['in'] ?> in class.
            <?php else: ?>
              Register not taken.
            <?php endif; ?>
          </p>
          <a class="adm-btn <?= $marked > 0 ? '' : 'adm-btn-red ' ?>adm-now-btn" href="<?= jfsd_class_href($c) ?>">
            <?= $marked > 0 ? 'Open the register' : 'Take the register' ?>
          </a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php
/* ===========================================================================
 * SCREEN 2 — ONE DAY
 * ========================================================================= */
elseif ($view === 'day'):

    $sum      = jfsd_day_summary($sessions, $counts, $date, $today, $floor);
    $backHtml = '<a class="adm-btn" href="' . jfsd_month_href($month) . '">Back to the calendar</a>';
    jfsd_page_title('Attendance', jfsd_date_long($date), $backHtml);
    ?>

  <?php if ($missing): ?>
    <div class="adm-alert adm-alert-warn">
      That class is not on the calendar any more. Here is everything on this day instead.
    </div>
  <?php endif; ?>

  <div class="adm-panel">
    <div class="adm-panel-h">
      <h2 class="adm-panel-title">Classes on this day</h2>
      <p class="adm-panel-note"><?= jfsd_e(jfsd_date_friendly($date)) ?> &middot; Singapore time</p>
    </div>
    <div class="adm-panel-b is-flush">
      <?php if ($sum['total'] === 0): ?>
        <div class="adm-empty">
          <strong>No class on this day.</strong>
          Classes normally run on Monday and Wednesday evenings, and Saturday and Sunday
          mornings. If one ran here anyway, put it on the calendar below.
        </div>
      <?php else: ?>
        <div class="adm-day-list">
          <?php foreach ($sum['classes'] as $c):
              $id     = (string) $c['id'];
              $marked = ($c['stored'] && isset($counts[$id])) ? (int) $counts[$id]['marked'] : 0;
              $needs  = $marked === 0 && $date <= $today && $floor !== null && $date >= $floor;
              ?>
            <a class="adm-day-row<?= $needs ? ' is-todo' : '' ?>" href="<?= jfsd_class_href($c) ?>">
              <span class="adm-day-time"><?= jfsd_e(jfsd_time_short((string) $c['start'])) ?></span>
              <span class="adm-day-body">
                <span class="adm-day-state">
                  <?php if ($marked > 0): ?>
                    Register taken &middot; <b><?= (int) $counts[$id]['in'] ?></b> in class
                  <?php else: ?>
                    Register not taken
                  <?php endif; ?>
                </span>
                <span class="adm-day-when">
                  <?= jfsd_e(jfsd_time_range((string) $c['start'], (string) $c['end'])) ?><?php
                    if ($c['label'] !== '') { echo ' &middot; ' . jfsd_e((string) $c['label']); } ?>
                </span>
              </span>
              <span class="adm-day-go"><?= $marked > 0 ? 'Open' : 'Take it' ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <details class="adm-add">
        <summary>Add a class at another time</summary>
        <div class="adm-add-b">
          <p class="adm-hint adm-mb">
            For a time the venue moved, or a one-off somebody asked for. It only affects
            this day.
          </p>
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
  </div>

<?php
/* ===========================================================================
 * SCREEN 3 — THE REGISTER
 * ========================================================================= */
else:

    $classId = (string) $class['id'];

    // Marks already saved for this exact class.
    $saved = [];
    if ($classId !== '') {
        foreach ($attendance as $row) {
            if ((string) ($row['session_id'] ?? '') === $classId) {
                $saved[(string) ($row['student_id'] ?? '')] = (string) ($row['status'] ?? '');
            }
        }
    }

    $byId = jfsd_index_students($students);

    // Students on this register who have run out. Named here so the count under
    // the heading is a fact rather than a warning, and so nothing on this screen
    // ever suggests they cannot be marked in.
    $lowCount = 0;
    foreach ($roster as $s) {
        if (($s['plan'] ?? '') !== 'corporate' && (int) ($s['sessions_remaining'] ?? 0) <= 0) {
            $lowCount++;
        }
    }

    /* Anyone already marked for this exact class who is no longer on the active
       roster. They belong ON the register, with the same buttons: a session
       charged to the wrong person has to stay correctable after that person is
       paused or marked as left, and leaving them off made the mistake permanent.
       They do not appear on future registers, which is the behaviour wanted. */
    $rosterIds = [];
    foreach ($roster as $s) {
        $rosterIds[(string) ($s['id'] ?? '')] = true;
    }
    $offRoster = [];
    foreach ($saved as $sid => $status) {
        if (!isset($rosterIds[$sid]) && isset($byId[$sid])) {
            $offRoster[] = $byId[$sid];
        }
    }
    usort($offRoster, static fn(array $a, array $b): int =>
        strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

    $rowCount = count($roster) + count($offRoster);
    $backHtml = '<a class="adm-btn" href="' . jfsd_day_href($date) . '">Back to the day</a>';
    jfsd_page_title(jfsd_date_friendly($date), jfsd_time_friendly((string) $class['start']) . ' class', $backHtml);
    ?>

  <div class="adm-panel">
    <div class="adm-panel-h">
      <h2 class="adm-panel-title">Who turned up</h2>
      <p class="adm-panel-note">
        <?= jfsd_e(jfsd_time_range((string) $class['start'], (string) $class['end'])) ?><?php
          if ((string) $class['label'] !== '') { echo ' &middot; ' . jfsd_e((string) $class['label']); } ?>
      </p>
    </div>

    <?php if (!$roster && !$offRoster): ?>
      <div class="adm-empty">
        <strong>Nobody is on the roster yet.</strong>
        This is where the class list appears, one name per row, with a tap for present,
        late, absent or excused beside each of them. Add the people who come to class and
        they will be waiting here.
      </div>
    <?php else: ?>
      <form method="post" action="/admin/attendance.php" id="reg-form">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="save_register">
        <input type="hidden" name="date" value="<?= jfsd_e($date) ?>">
        <input type="hidden" name="class" value="<?= jfsd_e($classId) ?>">
        <input type="hidden" name="at" value="<?= jfsd_e((string) $class['start']) ?>">

        <div class="adm-reg-top">
          <div class="adm-reg-top-text">
            <p class="adm-reg-top-line"><?= (int) count($roster) ?> on the roster. Tap each name.</p>
            <?php if ($lowCount > 0): ?>
              <p class="adm-reg-top-note">
                <?= (int) $lowCount ?> of them <?= $lowCount === 1 ? 'has' : 'have' ?> no sessions left.
                Mark them present as normal, and top them up when it suits.
              </p>
            <?php endif; ?>
          </div>
          <button class="adm-btn adm-btn-quiet" type="button" id="all-present">Mark everyone present</button>
        </div>

        <div class="adm-reg">
          <?php foreach ($roster as $s) { jfsd_reg_row($s, $saved); } ?>
        </div>

        <?php if ($offRoster): ?>
          <div class="adm-reg-group">
            <h3 class="adm-reg-group-h">No longer on the roster</h3>
            <p class="adm-reg-group-note">
              Paused or gone, but marked for this class. They are here so a mark can still
              be corrected. They will not appear on future registers.
            </p>
          </div>
          <div class="adm-reg is-off-roster">
            <?php foreach ($offRoster as $s) { jfsd_reg_row($s, $saved); } ?>
          </div>
        <?php endif; ?>

        <div class="adm-reg-bar">
          <div class="adm-reg-tally" aria-live="polite">
            <p class="adm-reg-in"><b data-tally="in">0</b> in class</p>
            <p class="adm-reg-progress">
              <span data-tally="marked">0</span> of <?= (int) $rowCount ?> marked
            </p>
          </div>
          <button class="adm-btn adm-btn-red adm-btn-save" type="submit">Save the register</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

<script>
/* Live tally, per-row feedback, "mark everyone present", and the unsaved-marks
   guard. Everything here is convenience only — the numbers that matter are
   recomputed server-side after every save. */
(function () {
  var form = document.getElementById('reg-form');
  if (!form) { return; }

  var rows = form.querySelectorAll('.adm-reg-row');
  var outs = {};
  ['in', 'marked'].forEach(function (k) {
    outs[k] = form.querySelector('[data-tally="' + k + '"]');
  });

  /* Armed by the first change, disarmed on submit. Without this, tapping a nav
     link or the back button threw away a half-marked register in silence. */
  var dirty  = false;
  var saving = false;

  function tally() {
    var inRoom = 0;
    var marked = 0;
    rows.forEach(function (row) {
      var picked = row.querySelector('input[type="radio"]:checked');
      var note   = row.querySelector('.adm-reg-unmarked');
      if (picked) {
        marked++;
        if (picked.value === 'present' || picked.value === 'late') { inRoom++; }
        row.classList.add('is-marked');
        if (note) { note.hidden = true; }
      } else {
        row.classList.remove('is-marked');
        if (note) { note.hidden = false; }
      }
    });
    if (outs['in'])     { outs['in'].textContent     = String(inRoom); }
    if (outs.marked)    { outs.marked.textContent    = String(marked); }
  }

  form.addEventListener('change', function () { dirty = true; tally(); });
  form.addEventListener('submit', function () { saving = true; });

  var allBtn = document.getElementById('all-present');
  if (allBtn) {
    allBtn.addEventListener('click', function () {
      rows.forEach(function (row) {
        var p = row.querySelector('input[data-mark="present"]');
        if (p) { p.checked = true; }
      });
      dirty = true;
      tally();
    });
  }

  window.addEventListener('beforeunload', function (e) {
    if (dirty && !saving) { e.preventDefault(); e.returnValue = ''; return ''; }
  });

  tally();
})();
</script>

<?php endif; ?>

<?php jfsd_foot(); ?>
