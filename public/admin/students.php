<?php
declare(strict_types=1);

// JF Self Defense admin — student roster.
// List / search / add / edit / soft-delete. A person's record is never removed.

define('JFSD_ADMIN', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_ui.php';
admin_require_auth();

$user      = admin_current_user() ?? '';
$errors    = [];
$submitted = null; // the validated-but-rejected form, for re-rendering
$formOrig  = null; // the balance this form was rendered with, for re-rendering

/** Blank form. Also the shape every student record has on disk. */
function jfsd_blank_student(): array
{
    return [
        'id'                 => '',
        'name'               => '',
        'email'              => '',
        'phone'              => '',
        'joined_date'        => jfsd_today(),
        'plan'               => 'trial',
        'sessions_remaining' => 0,
        'notes'              => '',
        'status'             => 'active',
    ];
}

/**
 * Validate and normalise one submitted student. Everything is treated as
 * hostile: single-line fields have control characters stripped, the plan and
 * status must be members of the known sets, and the session count is clamped.
 *
 * @param array<string,string> $errors filled by reference
 */
function jfsd_validate_student(array $post, array &$errors): array
{
    $s = [
        'id'          => jfsd_line((string) ($post['id'] ?? ''), 40),
        'name'        => jfsd_line((string) ($post['name'] ?? ''), 120),
        'email'       => strtolower(jfsd_line((string) ($post['email'] ?? ''), 190)),
        'phone'       => jfsd_line((string) ($post['phone'] ?? ''), 40),
        'joined_date' => jfsd_line((string) ($post['joined_date'] ?? ''), 10),
        'plan'        => jfsd_line((string) ($post['plan'] ?? ''), 20),
        'notes'       => jfsd_text((string) ($post['notes'] ?? ''), 2000),
        'status'      => jfsd_line((string) ($post['status'] ?? ''), 20),
    ];

    if ($s['name'] === '') {
        $errors['name'] = 'A name is required.';
    }
    if ($s['email'] !== '' && !filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'That does not look like an email address. Leave it blank if you do not have one.';
    }
    if ($s['joined_date'] === '') {
        $s['joined_date'] = jfsd_today();
    } elseif (!jfsd_valid_date($s['joined_date'])) {
        $errors['joined_date'] = 'Use a real date.';
    }
    if (!isset(JFSD_PLANS[$s['plan']])) {
        $errors['plan'] = 'Pick a plan.';
        $s['plan'] = 'trial';
    }
    if (!isset(JFSD_STUDENT_STATUSES[$s['status']])) {
        $errors['status'] = 'Pick a status.';
        $s['status'] = 'active';
    }

    $raw = trim((string) ($post['sessions_remaining'] ?? '0'));
    if ($raw === '') {
        $raw = '0';
    }
    if (!preg_match('/^-?\d{1,4}$/', $raw)) {
        $errors['sessions_remaining'] = 'Sessions must be a whole number.';
        $s['sessions_remaining'] = 0;
    } else {
        $s['sessions_remaining'] = max(-999, min(999, (int) $raw));
    }

    return $s;
}

/* ---------------------------------------------------------------------------
 * POST — every mutation redirects afterwards, so refresh cannot resubmit.
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $student   = jfsd_validate_student($_POST, $errors);
        $submitted = $student;
        $isNew     = ($student['id'] === '');

        /* What the balance field was showing when this page was drawn. The save
           refuses if the stored figure has moved since — otherwise adding
           somebody to a class in one tab and fixing a typo in another silently
           hands the session back, and the counted flag means it can never be
           re-charged. */
        $rawOrig = trim((string) ($_POST['sessions_remaining_orig'] ?? ''));
        if (preg_match('/^-?\d{1,7}$/', $rawOrig) === 1) {
            $formOrig = (int) $rawOrig;
        }

        // Untouched field: hand back exactly what was rendered, never a
        // re-parsed or re-clamped version of it.
        if ($formOrig !== null && trim((string) ($_POST['sessions_remaining'] ?? '')) === $rawOrig) {
            $student['sessions_remaining']   = $formOrig;
            $submitted['sessions_remaining'] = $formOrig;
        }

        if (!$errors) {
            if (!jfsd_nonce_spend()) {
                jfsd_flash_set('warn', $isNew
                    ? 'That was the same new student sent twice, so nothing was added a second time.'
                    : 'That change had already been saved. Nothing was saved twice.');
                jfsd_redirect('/admin/students.php');
            }

            $now = jfsd_now_iso();
            if ($isNew) {
                $student['id']         = jfsd_id('stu');
                $student['created_at'] = $now;
            }
            $student['updated_at'] = $now;

            jfsd_flash_result(jfsd_save_student($student, $isNew, $formOrig, $user));
            jfsd_redirect('/admin/students.php');
        }
        // Falls through to re-render the form with the submitted values intact.

    } elseif ($action === 'status') {
        $id     = jfsd_line((string) ($_POST['id'] ?? ''), 40);
        $status = jfsd_line((string) ($_POST['status'] ?? ''), 20);
        jfsd_flash_result(jfsd_set_student_status($id, $status));
        jfsd_redirect('/admin/students.php');

    } else {
        jfsd_redirect('/admin/students.php');
    }
}

/* ---------------------------------------------------------------------------
 * Which screen are we on?
 * ------------------------------------------------------------------------- */
$students = jfsd_read('students');

$showForm = false;
$form     = jfsd_blank_student();

if ($errors && $submitted !== null) {
    // Re-render with what was typed, so nothing is lost on a validation error.
    $showForm = true;
    $form     = $submitted;
} elseif (isset($_GET['new'])) {
    $showForm = true;
    $formOrig = null;
} elseif (isset($_GET['edit'])) {
    $existing = jfsd_find_student($students, jfsd_line((string) $_GET['edit'], 40));
    if ($existing !== null) {
        $showForm = true;
        $form     = array_merge(jfsd_blank_student(), $existing);
        $formOrig = (int) ($existing['sessions_remaining'] ?? 0);
    } else {
        jfsd_flash_set('error', 'That student was not found.');
        jfsd_redirect('/admin/students.php');
    }
}

/* ---------------------------------------------------------------------------
 * List filtering
 * ------------------------------------------------------------------------- */
$q     = jfsd_line((string) ($_GET['q'] ?? ''), 60);
$scope = ($_GET['scope'] ?? 'active') === 'all' ? 'all' : 'active';

/** Prefix match on name, email or phone. Also matches any word in the name,
 *  so "wei" finds "Chia Wei". Digits-only queries ignore phone formatting. */
function jfsd_student_matches(array $s, string $q): bool
{
    if ($q === '') {
        return true;
    }
    $needle = strtolower($q);

    $name = strtolower((string) ($s['name'] ?? ''));
    foreach (preg_split('/\s+/', $name) ?: [] as $word) {
        if ($word !== '' && str_starts_with($word, $needle)) {
            return true;
        }
    }
    if (str_starts_with(strtolower((string) ($s['email'] ?? '')), $needle)) {
        return true;
    }

    $phone       = preg_replace('/\D+/', '', (string) ($s['phone'] ?? '')) ?? '';
    $needleDigits = preg_replace('/\D+/', '', $needle) ?? '';
    if ($needleDigits !== '' && $phone !== '' && str_starts_with($phone, $needleDigits)) {
        return true;
    }
    return false;
}

$rows = [];
foreach ($students as $s) {
    if ($scope === 'active' && ($s['status'] ?? '') !== 'active') {
        continue;
    }
    if (!jfsd_student_matches($s, $q)) {
        continue;
    }
    $rows[] = $s;
}
usort($rows, static function (array $a, array $b): int {
    // Active first, then by name.
    $rank = static fn(array $s): int => ($s['status'] ?? '') === 'active' ? 0 : (($s['status'] ?? '') === 'paused' ? 1 : 2);
    return [$rank($a), strtolower((string) ($a['name'] ?? ''))] <=> [$rank($b), strtolower((string) ($b['name'] ?? ''))];
});

$activeCount = count(jfsd_active_students($students));

jfsd_head('Students', 'students');

/* The eyebrow names the section and the heading says what the screen is, which
 * is how Attendance already reads: "Attendance / Who came". It used to say
 * "Roster", a word nobody in the studio says out loud and one this admin does
 * not use anywhere a person can see it.
 *
 * The LIST screen deliberately carries no action up here any more. At 375px
 * "Add a student" was a 134px button wedged into the top right corner beside
 * the H1 — the most expensive corner on a phone to reach, close enough to the
 * heading to read as part of it, and the reason the top of this page looked
 * like two things fighting over one line. It has moved down into the toolbar
 * with the other controls.
 *
 * The FORM screen keeps its top-right action, because "Back to the list" is an
 * escape, and the far corner is exactly where an escape belongs. */
if ($showForm) {
    jfsd_page_title(
        'Students',
        ($form['id'] ?? '') !== '' ? 'Edit student' : 'Add a student',
        '<a class="adm-btn" href="/admin/students.php">Back to the list</a>'
    );
} else {
    jfsd_page_title('Students', 'Who trains here');
}
?>

<?php if ($showForm): ?>
  <?php if ($errors): ?>
    <div class="adm-alert adm-alert-error">
      <strong>Nothing was saved.</strong>
      <p><?= jfsd_e(implode(' ', $errors)) ?></p>
    </div>
  <?php endif; ?>

  <div class="adm-panel">
    <div class="adm-panel-h">
      <h2 class="adm-panel-title"><?= ($form['id'] ?? '') !== '' ? 'Student details' : 'New student' ?></h2>
      <?php if (($form['id'] ?? '') !== ''): ?>
        <p class="adm-panel-note">Added <?= jfsd_e(jfsd_date_friendly((string) ($form['joined_date'] ?? ''))) ?></p>
      <?php endif; ?>
    </div>
    <div class="adm-panel-b">
      <form class="trial-form adm-form" method="post" action="/admin/students.php"
            onsubmit="var b=this.querySelector('button[type=submit]'); if(b){setTimeout(function(){b.disabled=true;},0);}">
        <?= admin_csrf_field() ?>
        <?= jfsd_nonce_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= jfsd_e((string) $form['id']) ?>">
        <input type="hidden" name="sessions_remaining_orig" value="<?= jfsd_e((string) ($formOrig ?? $form['sessions_remaining'])) ?>">

        <label>Full name
          <input type="text" name="name" required maxlength="120" autocomplete="off"
                 value="<?= jfsd_e((string) $form['name']) ?>">
        </label>

        <div class="adm-form-grid">
          <label>Email <span class="adm-opt">(optional)</span>
            <input type="email" name="email" maxlength="190" autocomplete="off"
                   value="<?= jfsd_e((string) $form['email']) ?>">
          </label>
          <label>Phone <span class="adm-opt">(optional)</span>
            <input type="text" name="phone" maxlength="40" inputmode="tel" autocomplete="off"
                   value="<?= jfsd_e((string) $form['phone']) ?>">
          </label>
        </div>

        <div class="adm-form-grid">
          <label>Joined
            <input type="date" name="joined_date" value="<?= jfsd_e((string) $form['joined_date']) ?>">
          </label>
          <label>Plan
            <select name="plan">
              <?php foreach (JFSD_PLANS as $key => $plan): ?>
                <option value="<?= jfsd_e($key) ?>" <?= $form['plan'] === $key ? 'selected' : '' ?>>
                  <?= jfsd_e($plan['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Status
            <select name="status">
              <?php foreach (JFSD_STUDENT_STATUSES as $key => $label): ?>
                <option value="<?= jfsd_e($key) ?>" <?= $form['status'] === $key ? 'selected' : '' ?>>
                  <?= jfsd_e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label>Sessions remaining
          <input type="number" name="sessions_remaining" step="1" min="-999" max="999" inputmode="numeric"
                 value="<?= jfsd_e((string) $form['sessions_remaining']) ?>">
        </label>
        <p class="adm-hint">
          Normally you do not type this. Recording a payment adds sessions, and marking
          someone present takes one off. Only edit it here to correct a mistake — the
          correction is written into the payment history so the figure always adds up.
          If the number changes while this page is open, saving is refused rather than
          quietly putting the old figure back.
        </p>

        <label>Notes
          <textarea name="notes" maxlength="2000" rows="4"><?= jfsd_e((string) $form['notes']) ?></textarea>
        </label>

        <div class="adm-actions">
          <button class="adm-btn adm-btn-red" type="submit">Save</button>
          <a class="adm-btn" href="/admin/students.php">Cancel</a>
        </div>
      </form>
    </div>
  </div>

  <?php if (($form['id'] ?? '') !== '' && ($form['status'] ?? '') !== 'left'): ?>
    <div class="adm-panel">
      <div class="adm-panel-h">
        <h2 class="adm-panel-title">Left the studio</h2>
      </div>
      <div class="adm-panel-b">
        <p class="adm-hint adm-mb">
          Marks <?= jfsd_e((string) $form['name']) ?> as gone, so their name stops coming up
          when you add somebody to a class. Nothing is deleted: their attendance and payment
          history stays exactly where it is, they stay on any class list they are already on,
          and you can set them back to Active at any time.
        </p>
        <form class="adm-inline-form" method="post" action="/admin/students.php"
              onsubmit="return confirm(<?= jfsd_e((string) json_encode('Mark ' . (string) $form['name'] . ' as having left?', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>);">
          <?= admin_csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="id" value="<?= jfsd_e((string) $form['id']) ?>">
          <input type="hidden" name="status" value="left">
          <button class="adm-btn adm-btn-danger" type="submit">Mark as left</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

<?php else: ?>

  <?php
  /* ONE control group where there used to be four separate things.
   *
   * The old markup put this form's classes on the same element as the public
   * site's .trial-form, which lays its children out in a COLUMN. Both of the
   * .adm-filters declarations underneath it had been written for a ROW, so they
   * were being applied down the wrong axis: align-items:flex-end stopped being
   * vertical alignment and became "shove everything to the right edge", and
   * flex:1 1 240px stopped being a width and became a 240px HEIGHT — which is
   * where the 194px hole between the search box and its own Search button came
   * from. Nothing was going to fix that from inside .adm-filters, so the class
   * pair is gone entirely and this block owns its own layout.
   *
   * But a straightened-out row would still have been four objects at four
   * different left edges: type a name, press Search, choose Active or Everyone,
   * add somebody. Three of those are the same question — WHICH of these people
   * am I looking at — so they now share one frame, hairline-separated, at one
   * full-page width. The fourth is the only thing on the screen that creates
   * something, so it sits on its own above it.
   *
   * The whole group is hidden when there is nobody on the books: there is
   * nothing to search and nothing to filter, and the empty panel below says the
   * one thing there is to do. */
  ?>
  <?php if ($students): ?>
    <div class="adm-tools">
      <a class="adm-btn adm-tools-add" href="/admin/students.php?new=1">Add a student</a>

      <div class="adm-find">
        <?php
        /* Still a plain GET with one field, so this works with scripting off and
           the result is a URL he could bookmark. The Search button stays even
           though Enter would submit: he is not a keyboard person, the field is
           reached by tapping and left by tapping somewhere else, and a form that
           only responds to a key he never presses is a form that looks broken.
           It sits ON THE SAME LINE as the field now, which is the fix — it was
           194px below it and reading as an unrelated button. */
        ?>
        <form class="adm-find-b" method="get" action="/admin/students.php">
          <label class="adm-find-label" for="adm-find-q">Find a student</label>
          <div class="adm-find-row">
            <input class="adm-find-in" id="adm-find-q" type="search" name="q" maxlength="60"
                   value="<?= jfsd_e($q) ?>" placeholder="Type a name"
                   autocomplete="off" autocapitalize="words" spellcheck="false"
                   enterkeyhint="search">
            <input type="hidden" name="scope" value="<?= jfsd_e($scope) ?>">
            <button class="adm-btn" type="submit">Search</button>
          </div>
        </form>

        <?php /* What the search did, and the way out of it, inside the same frame
                 as the field that caused it — rather than as a heading over a
                 panel further down, where it read as a fact about the list
                 instead of as something he had just done. Only exists while
                 there is a query. */ ?>
        <?php if ($q !== ''): ?>
          <p class="adm-find-state">
            <span><?= count($rows) ?> matching &ldquo;<?= jfsd_e($q) ?>&rdquo;</span>
            <a class="adm-find-clear" href="/admin/students.php?scope=<?= jfsd_e($scope) ?>">Clear</a>
          </p>
        <?php endif; ?>

        <?php /* Two halves of one control rather than two links that happen to be
                 near each other. Equal widths so neither reads as the default,
                 and each half is a 160px target on his phone instead of a 110px
                 pill. The counts are the size of each SCOPE, which is why they do
                 not move when a search narrows the list. */ ?>
        <div class="adm-scope">
          <a class="adm-chip<?= $scope === 'active' ? ' is-active' : '' ?>"
             <?= $scope === 'active' ? 'aria-current="true" ' : '' ?>href="/admin/students.php?scope=active<?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>">Active (<?= (int) $activeCount ?>)</a>
          <a class="adm-chip<?= $scope === 'all' ? ' is-active' : '' ?>"
             <?= $scope === 'all' ? 'aria-current="true" ' : '' ?>href="/admin/students.php?scope=all<?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>">Everyone (<?= count($students) ?>)</a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php /* No panel heading on this screen. It used to say "9 shown" directly
           under a chip that said "Active (9)", which is the same fact twice, and
           on a phone that stutter cost a whole 55px strip above the only thing
           anybody came here to read. The frame above names the scope and says
           what a search found; the panel is the list. */ ?>
  <div class="adm-panel">
    <div class="adm-panel-b is-flush">
      <?php if (!$rows): ?>
        <div class="adm-empty">
          <?php if (!$students): ?>
            <strong>No students yet.</strong>
            Everyone you teach lives here: their plan, how many sessions they have left,
            and how to reach them.
            <p class="adm-empty-cta">
              <a class="adm-btn adm-btn-red" href="/admin/students.php?new=1">Add your first student</a>
            </p>
          <?php elseif ($q !== ''): ?>
            <strong>Nothing matches.</strong>
            Try fewer letters<?= $scope === 'active' ? ', or tap Everyone to include people who have paused or left' : '' ?>.
          <?php else: ?>
            <strong>Nobody is active right now.</strong>
            Tap Everyone to see people who have paused or left.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <?php
        /* ONE table, ONE loop. Above 760px it is the six-column table it always
         * was. Below 760px the same cells are laid out as a list of people —
         * see .adm-people in admin.css — because 665px of table inside a 320px
         * box is not a table, it is a sideways scroller that nobody discovers,
         * with the one number on the row that matters parked off the right edge.
         *
         * The cell classes below exist so that reflow has something to aim at.
         * Nothing is rendered twice and there is no phone-only markup to drift
         * out of step with the desktop one.
         *
         * .is-usual on a Status cell means "this person is Active", which is the
         * ordinary case and therefore worth nothing on a phone: in the Active
         * scope every row would carry the same pill, and in Everyone the useful
         * signal is the rows that DON'T say Active. The full column stays on the
         * desktop table, where a column costs nothing. */
        ?>
        <div class="adm-scroll">
          <table class="adm-table adm-people">
            <thead>
              <tr>
                <th>Name</th>
                <th>Plan</th>
                <th class="is-num">Sessions left</th>
                <th>Status</th>
                <th>Joined</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $s):
                  $left   = (int) ($s['sessions_remaining'] ?? 0);
                  $isCorp = ($s['plan'] ?? '') === 'corporate';
                  $isLow  = $left <= 0 && ($s['status'] ?? '') === 'active' && !$isCorp;
                  $status = (string) ($s['status'] ?? 'active');
                  ?>
                <tr>
                  <td class="is-name">
                    <a href="/admin/students.php?edit=<?= rawurlencode((string) ($s['id'] ?? '')) ?>"><?= jfsd_e((string) ($s['name'] ?? '')) ?></a>
                    <?php if (($s['email'] ?? '') !== '' || ($s['phone'] ?? '') !== ''): ?>
                      <span class="adm-sub"><?= jfsd_e(trim((string) ($s['email'] ?? '') . '  ' . (string) ($s['phone'] ?? ''))) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="is-plan"><?= jfsd_e(JFSD_PLANS[$s['plan'] ?? '']['label'] ?? (string) ($s['plan'] ?? '—')) ?></td>
                  <?php
                  /* A bare "4" under a "Sessions left" header reads fine. With the
                     header gone on a phone it is a number with no noun, so the
                     cell carries both and the layout picks one. The words are
                     jfsd_sessions_left_phrase(), the same sentence the attendance
                     page says, so a balance is worded one way in this admin.
                     A corporate student does not buy sessions, so the sentence is
                     simply not written: the phone would otherwise say "none left"
                     beside somebody whose company pays a retainer, which reads as
                     a person to chase. The desktop column still prints the stored
                     figure, exactly as it did before. */
                  ?>
                  <td class="is-num is-bal">
                    <?php if ($isLow): ?>
                      <span class="adm-pill adm-pill-alert"><span class="adm-bal-n"><?= $left ?></span><span class="adm-bal-say"><?= jfsd_e(jfsd_sessions_left_phrase($left)) ?></span></span>
                    <?php else: ?>
                      <span class="adm-bal-n"><?= $left ?></span>
                      <?php if (!$isCorp): ?><span class="adm-bal-say"><?= jfsd_e(jfsd_sessions_left_phrase($left)) ?></span><?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td class="is-status<?= $status === 'active' ? ' is-usual' : '' ?>"><span class="adm-pill adm-pill-<?= jfsd_e($status) ?>"><?= jfsd_e(JFSD_STUDENT_STATUSES[$status] ?? $status) ?></span></td>
                  <td class="is-joined"><?= jfsd_e(jfsd_date_friendly((string) ($s['joined_date'] ?? ''))) ?></td>
                  <td class="is-num is-pay">
                    <a href="/admin/payments.php?student=<?= rawurlencode((string) ($s['id'] ?? '')) ?>">Payments</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

<?php jfsd_foot(); ?>
