<?php
declare(strict_types=1);

// JF Self Defense admin — per-class attendance register.
// Pick a date and a slot, tap each student, save. Saving is idempotent: the
// record is keyed on (date, slot, student_id) and each record remembers whether
// it already cost the student a session, so re-saving never double-deducts.

define('JFSD_ADMIN', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_ui.php';
admin_require_auth();

$user = admin_current_user() ?? '';

/**
 * One row of the register. Identical markup for someone on the active roster
 * and someone who has since paused or left, so a historical mark stays
 * correctable either way.
 *
 * @param array<string,string> $saved student_id => saved status
 */
function jfsd_reg_row(array $s, array $saved): void
{
    $sid     = (string) ($s['id'] ?? '');
    $current = $saved[$sid] ?? '';
    $left    = (int) ($s['sessions_remaining'] ?? 0);
    $isOut   = $left <= 0 && ($s['plan'] ?? '') !== 'corporate';
    ?>
  <div class="adm-reg-row<?= $current !== '' ? ' is-marked' : '' ?>">
    <div class="adm-reg-who">
      <div class="adm-reg-name"><?= jfsd_e((string) ($s['name'] ?? '')) ?></div>
      <div class="adm-reg-meta">
        <span><?= jfsd_e(JFSD_PLANS[$s['plan'] ?? '']['label'] ?? '—') ?></span>
        <span class="<?= $isOut ? 'is-zero' : '' ?>"><?= $left ?> left</span>
        <span class="adm-reg-unmarked"<?= $current !== '' ? ' hidden' : '' ?>>Not marked</span>
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
 * POST
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf();

    $date = jfsd_line((string) ($_POST['date'] ?? ''), 10);
    $slot = jfsd_line((string) ($_POST['slot'] ?? ''), 10);

    if (!jfsd_valid_date($date) || jfsd_slot($slot) === null) {
        jfsd_flash_set('error', 'That class could not be identified. Nothing was saved.');
        jfsd_redirect('/admin/attendance.php');
    }

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
        jfsd_flash_result(jfsd_save_attendance($date, $slot, $marks, $user));
    }
    jfsd_redirect('/admin/attendance.php?date=' . rawurlencode($date) . '&slot=' . rawurlencode($slot));
}

/* ---------------------------------------------------------------------------
 * GET — which class are we looking at?
 * ------------------------------------------------------------------------- */
$date = jfsd_line((string) ($_GET['date'] ?? ''), 10);
if (!jfsd_valid_date($date)) {
    $date = jfsd_today();
}

$slotsToday   = jfsd_slots_on_date($date);
$requestedKey = jfsd_line((string) ($_GET['slot'] ?? ''), 10);

if (jfsd_slot($requestedKey) !== null) {
    $slotKey = $requestedKey;
} elseif ($slotsToday) {
    $slotKey = (string) array_key_first($slotsToday);
} else {
    $slotKey = 'mon';
}
$slot        = jfsd_slot($slotKey);
$slotIsToday = isset($slotsToday[$slotKey]);

$students   = jfsd_read('students');
$roster     = jfsd_active_students($students);
$attendance = jfsd_read('attendance');

// Existing marks for this exact class.
$saved = [];
foreach ($attendance as $row) {
    if (($row['date'] ?? '') === $date && ($row['slot'] ?? '') === $slotKey) {
        $saved[(string) ($row['student_id'] ?? '')] = (string) ($row['status'] ?? '');
    }
}

// Counts for the summary. Marks belonging to students who have since left are
// still counted — they were in the room.
$byId   = jfsd_index_students($students);
$counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
foreach ($saved as $sid => $status) {
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}
$markedCount   = count($saved);
$unmarkedCount = 0;
foreach ($roster as $s) {
    if (!isset($saved[(string) ($s['id'] ?? '')])) {
        $unmarkedCount++;
    }
}

// Students on this register who have run out of sessions.
$outOfSessions = [];
foreach ($roster as $s) {
    if (($s['plan'] ?? '') === 'corporate') {
        continue;
    }
    if ((int) ($s['sessions_remaining'] ?? 0) <= 0) {
        $outOfSessions[] = $s;
    }
}

/* Anyone already marked for this exact class who is no longer on the active
   roster. They belong ON the register, with the same radios: a session charged
   to the wrong person has to stay correctable after that person is paused or
   marked as left, and leaving them off made the mistake permanent — the only
   way back was to reactivate them, fix it, and mark them left again. They do
   not appear on future registers, which is the behaviour that was wanted. */
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

jfsd_head('Attendance', 'attendance');
jfsd_page_title('Class register', 'Attendance');
?>

<form class="trial-form adm-filters" method="get" action="/admin/attendance.php" id="reg-filter">
  <div class="adm-field">
    <label>Date
      <input type="date" name="date" value="<?= jfsd_e($date) ?>">
    </label>
  </div>
  <div class="adm-field">
    <label>Class
      <select name="slot">
        <?php foreach (JFSD_SLOTS as $key => $s): ?>
          <option value="<?= jfsd_e($key) ?>" <?= $key === $slotKey ? 'selected' : '' ?>>
            <?= jfsd_e($s['label'] . ' — ' . $s['tag']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <!-- Kept as the no-JS fallback; with JS the form submits as soon as either
       control changes, so the register below can never silently disagree with
       the date and class shown above it. -->
  <button class="adm-btn" type="submit">Show</button>
</form>

<?php if (!$slotIsToday): ?>
  <div class="adm-alert adm-alert-warn adm-mt">
    <?= jfsd_e(jfsd_date_friendly($date)) ?> is a
    <?= jfsd_e((string) date('l', (int) strtotime($date . ' 12:00:00'))) ?>, and
    <?= jfsd_e((string) $slot['label']) ?> does not normally run then.
    You can still take this register — useful for a make-up or a one-off — but check the date first.
  </div>
<?php endif; ?>

<div class="adm-panel adm-mt">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title"><?= jfsd_e((string) $slot['label']) ?> &middot; <?= jfsd_e(jfsd_date_friendly($date)) ?></h2>
    <p class="adm-panel-note"><?= jfsd_e((string) $slot['tag']) ?> &middot; <?= count($roster) ?> on the active roster</p>
  </div>

  <?php if (!$roster && !$offRoster): ?>
    <div class="adm-empty">
      <strong>No active students.</strong>
      <a href="/admin/students.php?new=1">Add a student</a> first, then come back here.
    </div>
  <?php else: ?>
    <form method="post" action="/admin/attendance.php" id="reg-form">
      <?= admin_csrf_field() ?>
      <input type="hidden" name="date" value="<?= jfsd_e($date) ?>">
      <input type="hidden" name="slot" value="<?= jfsd_e($slotKey) ?>">

      <div class="adm-reg">
        <?php foreach ($roster as $s) { jfsd_reg_row($s, $saved); } ?>
      </div>

      <?php if ($offRoster): ?>
        <div class="adm-reg-group">
          <h3 class="adm-reg-group-h">No longer on the roster</h3>
          <p class="adm-reg-group-note">
            Paused or gone, but marked for this class. They are here so a mark can still be
            corrected. They will not appear on future registers.
          </p>
        </div>
        <div class="adm-reg is-off-roster">
          <?php foreach ($offRoster as $s) { jfsd_reg_row($s, $saved); } ?>
        </div>
      <?php endif; ?>

      <div class="adm-reg-bar">
        <div class="adm-tally" id="tally" aria-live="polite">
          <span>Present <b data-tally="present">0</b></span>
          <span>Late <b data-tally="late">0</b></span>
          <span>Absent <b data-tally="absent">0</b></span>
          <span>Excused <b data-tally="excused">0</b></span>
          <span>Not marked <b data-tally="unmarked"><?= (int) $unmarkedCount ?></b></span>
        </div>
        <div class="adm-actions">
          <button class="adm-btn adm-btn-quiet" type="button" id="all-present">Mark all present</button>
          <button class="adm-btn adm-btn-red" type="submit">Save register</button>
        </div>
      </div>
    </form>
  <?php endif; ?>
</div>

<div class="adm-cards">
  <div class="adm-card">
    <span class="adm-card-label">Saved for this class</span>
    <span class="adm-card-val"><?= (int) ($counts['present'] + $counts['late']) ?></span>
    <span class="adm-card-sub">
      <?= (int) $counts['present'] ?> present, <?= (int) $counts['late'] ?> late.
      <?= (int) $counts['absent'] ?> absent, <?= (int) $counts['excused'] ?> excused.
      <?= (int) $unmarkedCount ?> not yet marked.
    </span>
  </div>
  <div class="adm-card<?= $outOfSessions ? ' is-alert' : '' ?>">
    <span class="adm-card-label">Out of sessions</span>
    <span class="adm-card-val"><?= count($outOfSessions) ?></span>
    <span class="adm-card-sub">
      <?php if ($outOfSessions): ?>
        <?= jfsd_e(implode(', ', array_map(static fn(array $s): string => (string) ($s['name'] ?? ''), array_slice($outOfSessions, 0, 6)))) ?><?= count($outOfSessions) > 6 ? ' and ' . (count($outOfSessions) - 6) . ' more' : '' ?>.
        <a href="/admin/payments.php">Record a payment</a>
      <?php else: ?>
        Everyone on the active roster has sessions left.
      <?php endif; ?>
    </span>
  </div>
</div>

<script>
/* Live tally, per-row feedback, "mark all present", and an unsaved-marks guard.
   Everything here is convenience only — the numbers that matter are recomputed
   server-side after every save. */
(function () {
  var form   = document.getElementById('reg-form');
  var filter = document.getElementById('reg-filter');
  if (!form) { return; }

  var rows = form.querySelectorAll('.adm-reg-row');
  var outs = {};
  ['present', 'late', 'absent', 'excused', 'unmarked'].forEach(function (k) {
    outs[k] = form.querySelector('[data-tally="' + k + '"]');
  });

  /* Armed by the first change, disarmed on submit. Without this, tapping a nav
     link or changing the date threw away a half-marked register in silence. */
  var dirty  = false;
  var saving = false;

  function tally() {
    var n = { present: 0, late: 0, absent: 0, excused: 0, unmarked: 0 };
    rows.forEach(function (row) {
      var picked = row.querySelector('input[type="radio"]:checked');
      var note   = row.querySelector('.adm-reg-unmarked');
      if (picked) {
        n[picked.value] = (n[picked.value] || 0) + 1;
        row.classList.add('is-marked');
        if (note) { note.hidden = true; }
      } else {
        n.unmarked++;
        row.classList.remove('is-marked');
        if (note) { note.hidden = false; }
      }
    });
    Object.keys(outs).forEach(function (k) {
      if (outs[k]) { outs[k].textContent = String(n[k]); }
    });
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

  if (filter) {
    // Changing the date or the class reloads straight away, so the register can
    // never be Wednesday's while the picker says Saturday.
    filter.addEventListener('change', function () {
      if (dirty && !confirm('You have marked students but not saved. Change class anyway? Your marks will be lost.')) {
        return;
      }
      dirty = false;
      filter.submit();
    });
    filter.addEventListener('submit', function (e) {
      if (dirty && !confirm('You have marked students but not saved. Change class anyway? Your marks will be lost.')) {
        e.preventDefault();
        return;
      }
      dirty = false;
    });
  }

  tally();
})();
</script>

<?php jfsd_foot(); ?>
