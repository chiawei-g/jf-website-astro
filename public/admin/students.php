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
           refuses if the stored figure has moved since — otherwise marking the
           register in one tab and fixing a typo in another silently hands the
           session back, and the counted flag means it can never be re-charged. */
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

$action = $showForm
    ? '<a class="adm-btn" href="/admin/students.php">Back to the list</a>'
    : '<a class="adm-btn adm-btn-red" href="/admin/students.php?new=1">Add a student</a>';
jfsd_page_title('Roster', $showForm ? (($form['id'] ?? '') !== '' ? 'Edit student' : 'Add a student') : 'Students', $action);
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
          Marks <?= jfsd_e((string) $form['name']) ?> as gone and hides them from the class register.
          Nothing is deleted — their attendance and payment history stays exactly where it is,
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

  <form class="trial-form adm-filters" method="get" action="/admin/students.php">
    <div class="adm-field">
      <label>Search
        <input type="search" name="q" placeholder="Name, email or phone" value="<?= jfsd_e($q) ?>">
      </label>
    </div>
    <input type="hidden" name="scope" value="<?= jfsd_e($scope) ?>">
    <button class="adm-btn" type="submit">Search</button>
    <?php if ($q !== ''): ?>
      <a class="adm-btn adm-btn-quiet" href="/admin/students.php?scope=<?= jfsd_e($scope) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <div class="adm-chips adm-chips-row">
    <a class="adm-chip<?= $scope === 'active' ? ' is-active' : '' ?>"
       href="/admin/students.php?scope=active<?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>">Active (<?= (int) $activeCount ?>)</a>
    <a class="adm-chip<?= $scope === 'all' ? ' is-active' : '' ?>"
       href="/admin/students.php?scope=all<?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>">Everyone (<?= count($students) ?>)</a>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-h">
      <h2 class="adm-panel-title"><?= count($rows) ?> shown</h2>
      <?php if ($q !== ''): ?>
        <p class="adm-panel-note">Matching &ldquo;<?= jfsd_e($q) ?>&rdquo;</p>
      <?php endif; ?>
    </div>
    <div class="adm-panel-b is-flush">
      <?php if (!$rows): ?>
        <div class="adm-empty">
          <strong><?= $students ? 'Nothing matches.' : 'No students yet.' ?></strong>
          <?php if ($students): ?>
            Try a shorter search, or switch to Everyone.
          <?php else: ?>
            <p class="adm-empty-cta">
              <a class="adm-btn adm-btn-red" href="/admin/students.php?new=1">Add your first student</a>
            </p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="adm-scroll">
          <table class="adm-table">
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
                  $isLow  = $left <= 0 && ($s['status'] ?? '') === 'active' && ($s['plan'] ?? '') !== 'corporate';
                  $status = (string) ($s['status'] ?? 'active');
                  ?>
                <tr>
                  <td class="is-name">
                    <a href="/admin/students.php?edit=<?= rawurlencode((string) ($s['id'] ?? '')) ?>"><?= jfsd_e((string) ($s['name'] ?? '')) ?></a>
                    <?php if (($s['email'] ?? '') !== '' || ($s['phone'] ?? '') !== ''): ?>
                      <span class="adm-sub"><?= jfsd_e(trim((string) ($s['email'] ?? '') . '  ' . (string) ($s['phone'] ?? ''))) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><?= jfsd_e(JFSD_PLANS[$s['plan'] ?? '']['label'] ?? (string) ($s['plan'] ?? '—')) ?></td>
                  <td class="is-num">
                    <?php if ($isLow): ?>
                      <span class="adm-pill adm-pill-alert"><?= $left ?></span>
                    <?php else: ?>
                      <?= $left ?>
                    <?php endif; ?>
                  </td>
                  <td><span class="adm-pill adm-pill-<?= jfsd_e($status) ?>"><?= jfsd_e(JFSD_STUDENT_STATUSES[$status] ?? $status) ?></span></td>
                  <td><?= jfsd_e(jfsd_date_friendly((string) ($s['joined_date'] ?? ''))) ?></td>
                  <td class="is-num">
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
