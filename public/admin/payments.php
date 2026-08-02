<?php
declare(strict_types=1);

// JF Self Defense admin — manual payment recording.
// Bank transfer / PayNow / cash only. There is no payment gateway and none is
// wanted: Jeffrey sees the money arrive in his bank and types it in here.
// Recording a payment credits sessions to the student.

define('JFSD_ADMIN', true);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_ui.php';
admin_require_auth();

$user      = admin_current_user() ?? '';
$errors    = [];
$submitted = null;

/** Blank payment form. */
function jfsd_blank_payment(): array
{
    return [
        'student_id'       => '',
        'date_received'    => jfsd_today(),
        'amount_sgd'       => '',
        'method'           => 'bank_transfer',
        'reference'        => '',
        'covers'           => '8-pack',
        'sessions_granted' => '',
        'note'             => '',
    ];
}

/**
 * Parse a typed money value. Accepts "40", "S$40", "1,200.50", " 40.5 ".
 * Returns null when it is not a usable amount.
 */
function jfsd_parse_amount(string $raw): ?float
{
    $clean = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';
    if ($clean === '' || !preg_match('/^\d{1,7}(\.\d{1,2})?$/', $clean)) {
        return null;
    }
    return round((float) $clean, 2);
}

/** Validate and normalise a submitted payment. */
function jfsd_validate_payment(array $post, array $students, array &$errors): array
{
    $p = [
        'student_id'    => jfsd_line((string) ($post['student_id'] ?? ''), 40),
        'date_received' => jfsd_line((string) ($post['date_received'] ?? ''), 10),
        'amount_sgd'    => jfsd_line((string) ($post['amount_sgd'] ?? ''), 20),
        'method'        => jfsd_line((string) ($post['method'] ?? ''), 20),
        'reference'     => jfsd_line((string) ($post['reference'] ?? ''), 80),
        'covers'        => jfsd_line((string) ($post['covers'] ?? ''), 20),
        'note'          => jfsd_text((string) ($post['note'] ?? ''), 1000),
    ];
    $p['sessions_granted'] = jfsd_line((string) ($post['sessions_granted'] ?? ''), 6);

    if ($p['student_id'] === '' || jfsd_find_student($students, $p['student_id']) === null) {
        $errors['student_id'] = 'Pick a student.';
    }
    if ($p['date_received'] === '') {
        $p['date_received'] = jfsd_today();
    } elseif (!jfsd_valid_date($p['date_received'])) {
        $errors['date_received'] = 'Use a real date.';
    }

    $amount = jfsd_parse_amount($p['amount_sgd']);
    if ($amount === null) {
        $errors['amount_sgd'] = 'Enter the amount received, e.g. 280 or 280.50.';
    }
    if (!isset(JFSD_PAYMENT_METHODS[$p['method']])) {
        $errors['method'] = 'Pick how the money arrived.';
        $p['method'] = 'bank_transfer';
    }
    if (!isset(JFSD_PLANS[$p['covers']])) {
        $errors['covers'] = 'Pick what the payment covers.';
        $p['covers'] = '8-pack';
    }

    // Blank sessions means "the usual number for that plan".
    $granted = trim((string) $p['sessions_granted']);
    if ($granted === '') {
        $granted = (string) (JFSD_PLANS[$p['covers']]['sessions'] ?? 0);
    }
    if (!preg_match('/^\d{1,3}$/', $granted)) {
        $errors['sessions_granted'] = 'Sessions must be a whole number, 0 or more.';
        $granted = '0';
    }

    return [
        'form'    => $p,
        'amount'  => $amount ?? 0.0,
        'granted' => (int) $granted,
    ];
}

/* ---------------------------------------------------------------------------
 * POST
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf();
    $action   = (string) ($_POST['action'] ?? '');
    $students = jfsd_read('students');

    if ($action === 'record') {
        $checked   = jfsd_validate_payment($_POST, $students, $errors);
        $submitted = $checked['form'];

        if (!$errors) {
            // Slow wifi, nothing visibly happens, the button gets tapped again.
            // Both POSTs would otherwise be accepted and serialised by the write
            // lock, crediting the sessions and banking the money twice.
            if (!jfsd_nonce_spend()) {
                jfsd_flash_set('warn', 'That payment had already been recorded. Nothing was added a second time.');
                jfsd_redirect('/admin/payments.php?student=' . rawurlencode($checked['form']['student_id']));
            }

            $payment = [
                'id'               => jfsd_id('pay'),
                'student_id'       => $checked['form']['student_id'],
                'date_received'    => $checked['form']['date_received'],
                'amount_sgd'       => $checked['amount'],
                'method'           => $checked['form']['method'],
                'reference'        => $checked['form']['reference'],
                'covers'           => $checked['form']['covers'],
                'sessions_granted' => $checked['granted'],
                'note'             => $checked['form']['note'],
                'recorded_at'      => jfsd_now_iso(),
                'recorded_by'      => $user,
            ];
            jfsd_flash_result(jfsd_record_payment($payment));
            jfsd_redirect('/admin/payments.php?student=' . rawurlencode($payment['student_id']));
        }
        // Falls through and re-renders the form with the values intact.

    } elseif ($action === 'void') {
        $id      = jfsd_line((string) ($_POST['id'] ?? ''), 40);
        $backTo  = jfsd_line((string) ($_POST['back_to'] ?? ''), 40);
        jfsd_flash_result(jfsd_void_payment($id, $user));
        jfsd_redirect('/admin/payments.php' . ($backTo !== '' ? '?student=' . rawurlencode($backTo) : ''));

    } else {
        jfsd_redirect('/admin/payments.php');
    }
}

/* ---------------------------------------------------------------------------
 * GET
 * ------------------------------------------------------------------------- */
$students = jfsd_read('students');
$payments = jfsd_read('payments');
$byId     = jfsd_index_students($students);
$attention = jfsd_needs_attention($students);

$form = $submitted ?? jfsd_blank_payment();

$focusId      = jfsd_line((string) ($_GET['student'] ?? ''), 40);
$focusStudent = $focusId !== '' ? jfsd_find_student($students, $focusId) : null;
if ($focusStudent !== null && $submitted === null) {
    $form['student_id'] = $focusId;
    $form['covers']     = (string) ($focusStudent['plan'] ?? '8-pack');
    if (!isset(JFSD_PLANS[$form['covers']])) {
        $form['covers'] = '8-pack';
    }
}

// Newest first.
$history = $payments;
usort($history, static function (array $a, array $b): int {
    return [(string) ($b['date_received'] ?? ''), (string) ($b['recorded_at'] ?? '')]
       <=> [(string) ($a['date_received'] ?? ''), (string) ($a['recorded_at'] ?? '')];
});
if ($focusStudent !== null) {
    $history = array_values(array_filter(
        $history,
        static fn(array $p): bool => ($p['student_id'] ?? '') === $focusId
    ));
}
$historyShown = $focusStudent !== null ? $history : array_slice($history, 0, 40);

// Money totals count LIVE payments only, and balance corrections are not money.
$focusTotal = 0.0;
$focusCount = 0;
foreach (jfsd_live_payments($history) as $p) {
    if (!jfsd_payment_is_money($p)) {
        continue;
    }
    $focusTotal += (float) ($p['amount_sgd'] ?? 0);
    $focusCount++;
}

// Students available in the picker: active and paused. Someone who has left is
// not going to be paying, and keeping them out stops mis-selection.
$pickable = array_values(array_filter(
    $students,
    static fn(array $s): bool => in_array($s['status'] ?? '', ['active', 'paused'], true)
));
usort($pickable, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

jfsd_head('Payments', 'payments');
jfsd_page_title('Money in', 'Payments', '<a class="adm-btn" href="/admin/students.php">Roster</a>');
?>

<!-- ============ THE LIST THIS SOFTWARE EXISTS FOR ============ -->
<div class="adm-panel">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Needs attention</h2>
    <p class="adm-panel-note">Active students with no sessions left. Chase these before class.</p>
  </div>
  <div class="adm-panel-b is-flush">
    <?php if (!$attention): ?>
      <div class="adm-empty">
        <strong>Everyone is paid up.</strong>
        No active student is out of sessions.
      </div>
    <?php else: ?>
      <p class="adm-swipe">Swipe the table sideways for contact details and “Record payment”.</p>
      <div class="adm-scroll">
        <table class="adm-table">
          <thead>
            <tr>
              <th>Name</th>
              <th class="is-num">Sessions left</th>
              <th>Plan</th>
              <th>Contact</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($attention as $s):
                $sid = (string) ($s['id'] ?? '');
                ?>
              <tr>
                <td class="is-name"><a href="/admin/students.php?edit=<?= rawurlencode($sid) ?>"><?= jfsd_e((string) ($s['name'] ?? '')) ?></a></td>
                <td class="is-num"><span class="adm-pill adm-pill-alert"><?= (int) ($s['sessions_remaining'] ?? 0) ?></span></td>
                <td><?= jfsd_e(JFSD_PLANS[$s['plan'] ?? '']['label'] ?? '—') ?></td>
                <td>
                  <?= jfsd_e((string) ($s['phone'] ?? '')) ?>
                  <?php if (($s['email'] ?? '') !== ''): ?><span class="adm-sub"><?= jfsd_e((string) $s['email']) ?></span><?php endif; ?>
                </td>
                <td class="is-num"><a href="/admin/payments.php?student=<?= rawurlencode($sid) ?>">Record payment</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============ RECORD A PAYMENT ============ -->
<?php if ($errors): ?>
  <div class="adm-alert adm-alert-error">
    <strong>Nothing was recorded.</strong>
    <p><?= jfsd_e(implode(' ', $errors)) ?></p>
  </div>
<?php endif; ?>

<div class="adm-panel">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">Record a payment</h2>
    <p class="adm-panel-note">Adds the sessions to that student straight away.</p>
  </div>
  <div class="adm-panel-b">
    <?php if (!$pickable): ?>
      <div class="adm-empty">
        <strong>No students yet.</strong>
        <a href="/admin/students.php?new=1">Add a student</a> before recording a payment.
      </div>
    <?php else: ?>
      <form class="trial-form adm-form" method="post" action="/admin/payments.php" id="pay-form"
            onsubmit="var b=this.querySelector('button[type=submit]'); if(b){setTimeout(function(){b.disabled=true;},0);}">
        <?= admin_csrf_field() ?>
        <?= jfsd_nonce_field() ?>
        <input type="hidden" name="action" value="record">

        <label>Student
          <select name="student_id" required>
            <option value="">— pick a student —</option>
            <?php foreach ($pickable as $s):
                $sid = (string) ($s['id'] ?? '');
                ?>
              <option value="<?= jfsd_e($sid) ?>" <?= $form['student_id'] === $sid ? 'selected' : '' ?>>
                <?= jfsd_e((string) ($s['name'] ?? '')) ?>
                (<?= (int) ($s['sessions_remaining'] ?? 0) ?> left<?= ($s['status'] ?? '') === 'paused' ? ', paused' : '' ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <div class="adm-form-grid">
          <label>Date received
            <input type="date" name="date_received" value="<?= jfsd_e((string) $form['date_received']) ?>">
          </label>
          <label>Amount (SGD)
            <input type="text" name="amount_sgd" inputmode="decimal" placeholder="280"
                   value="<?= jfsd_e((string) $form['amount_sgd']) ?>" required>
          </label>
          <label>How it arrived
            <select name="method">
              <?php foreach (JFSD_PAYMENT_METHODS as $key => $label): ?>
                <option value="<?= jfsd_e($key) ?>" <?= $form['method'] === $key ? 'selected' : '' ?>><?= jfsd_e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="adm-form-grid">
          <label>Covers
            <select name="covers" id="covers-select">
              <?php foreach (JFSD_PLANS as $key => $plan): ?>
                <option value="<?= jfsd_e($key) ?>" data-sessions="<?= (int) $plan['sessions'] ?>"
                        <?= $form['covers'] === $key ? 'selected' : '' ?>><?= jfsd_e($plan['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Sessions to add
            <input type="number" name="sessions_granted" id="sessions-input" step="1" min="0" max="999"
                   inputmode="numeric" value="<?= jfsd_e((string) $form['sessions_granted']) ?>"
                   placeholder="<?= (int) (JFSD_PLANS[$form['covers']]['sessions'] ?? 0) ?>">
          </label>
          <label>Reference <span class="adm-opt">(optional)</span>
            <input type="text" name="reference" maxlength="80" autocomplete="off"
                   placeholder="Bank ref / PayNow ref"
                   value="<?= jfsd_e((string) $form['reference']) ?>">
          </label>
        </div>

        <label>Note <span class="adm-opt">(optional)</span>
          <textarea name="note" maxlength="1000" rows="2"><?= jfsd_e((string) $form['note']) ?></textarea>
        </label>

        <p class="adm-hint">
          Leave &ldquo;sessions to add&rdquo; blank and the usual number for that plan is used.
        </p>

        <div class="adm-actions">
          <button class="adm-btn adm-btn-red" type="submit">Record payment</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- ============ HISTORY ============ -->
<div class="adm-panel">
  <div class="adm-panel-h">
    <h2 class="adm-panel-title">
      <?= $focusStudent !== null
          ? jfsd_e((string) ($focusStudent['name'] ?? '')) . ' — payment history'
          : 'Recent payments' ?>
    </h2>
    <p class="adm-panel-note">
      <?php if ($focusStudent !== null): ?>
        <?= (int) $focusCount ?> payment<?= $focusCount === 1 ? '' : 's' ?>,
        <?= jfsd_e(jfsd_money($focusTotal)) ?> total &middot;
        <a href="/admin/payments.php">Show everyone</a>
      <?php else: ?>
        Newest first, most recent 40. Open a student to see their full history.
      <?php endif; ?>
      Voided rows are struck through and are not in the total.
    </p>
  </div>
  <div class="adm-panel-b is-flush">
    <?php if (!$historyShown): ?>
      <div class="adm-empty">
        <strong>No payments recorded<?= $focusStudent !== null ? ' for this student' : '' ?> yet.</strong>
        They will appear here as you enter them.
      </div>
    <?php else: ?>
      <p class="adm-swipe">Swipe the table sideways for method, sessions, reference and Void.</p>
      <div class="adm-scroll">
        <table class="adm-table">
          <thead>
            <tr>
              <th>Received</th>
              <?php if ($focusStudent === null): ?><th>Student</th><?php endif; ?>
              <th class="is-num">Amount</th>
              <th>Method</th>
              <th>Covers</th>
              <th class="is-num">Sessions</th>
              <th>Reference</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historyShown as $p):
                $pid      = (string) ($p['id'] ?? '');
                $student  = $byId[$p['student_id'] ?? ''] ?? null;
                $isVoid   = jfsd_payment_is_void($p);
                $isMoney  = jfsd_payment_is_money($p);
                $sessions = (int) ($p['sessions_granted'] ?? 0);
                ?>
              <tr class="<?= $isVoid ? 'is-void' : '' ?>">
                <td>
                  <?= jfsd_e(jfsd_date_friendly((string) ($p['date_received'] ?? ''))) ?>
                  <span class="adm-sub">entered <?= jfsd_e(jfsd_datetime_friendly((string) ($p['recorded_at'] ?? ''))) ?></span>
                </td>
                <?php if ($focusStudent === null): ?>
                  <td class="is-name">
                    <?php if ($student !== null): ?>
                      <a href="/admin/payments.php?student=<?= rawurlencode((string) ($student['id'] ?? '')) ?>"><?= jfsd_e((string) ($student['name'] ?? '')) ?></a>
                    <?php else: ?>
                      <span class="adm-sub">(student removed)</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <td class="is-num"><?= $isMoney ? jfsd_e(jfsd_money((float) ($p['amount_sgd'] ?? 0))) : '—' ?></td>
                <?php
                $methodKey   = (string) ($p['method'] ?? '');
                $methodLabel = JFSD_PAYMENT_METHODS[$methodKey] ?? $methodKey;
                ?>
                <td><?= jfsd_e($methodLabel === '' ? '—' : $methodLabel) ?></td>
                <td><?= jfsd_e(jfsd_covers_label((string) ($p['covers'] ?? ''))) ?></td>
                <td class="is-num"><?= jfsd_e(sprintf('%+d', $sessions)) ?></td>
                <td>
                  <?= jfsd_e((string) ($p['reference'] ?? '')) ?>
                  <?php if (($p['note'] ?? '') !== ''): ?><span class="adm-sub"><?= jfsd_e((string) $p['note']) ?></span><?php endif; ?>
                </td>
                <td class="is-num">
                  <?php if ($isVoid): ?>
                    <span class="adm-pill">Voided</span>
                    <span class="adm-sub"><?= jfsd_e(jfsd_datetime_friendly((string) ($p['voided_at'] ?? ''))) ?></span>
                  <?php elseif (!$isMoney): ?>
                    <span class="adm-sub">Balance correction</span>
                  <?php else: ?>
                    <form class="adm-inline-form" method="post" action="/admin/payments.php"
                          onsubmit="return confirm(<?= jfsd_e((string) json_encode(
                              'Void this payment? The record is kept and stays visible, struck through, but it stops counting'
                              . ($sessions !== 0 ? ' and ' . abs($sessions) . ' session(s) come back off the balance.' : '.'),
                              JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                          )) ?>);">
                      <?= admin_csrf_field() ?>
                      <input type="hidden" name="action" value="void">
                      <input type="hidden" name="id" value="<?= jfsd_e($pid) ?>">
                      <input type="hidden" name="back_to" value="<?= jfsd_e($focusId) ?>">
                      <button class="adm-btn adm-btn-quiet adm-btn-danger" type="submit">Void</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
/* Convenience only: when the plan changes, suggest the usual session count.
   The server applies the same default when the field is left blank. */
(function () {
  var covers = document.getElementById('covers-select');
  var input  = document.getElementById('sessions-input');
  if (!covers || !input) { return; }
  covers.addEventListener('change', function () {
    var opt = covers.options[covers.selectedIndex];
    var n   = opt ? opt.getAttribute('data-sessions') : null;
    if (n !== null) {
      input.placeholder = n;
      if (input.value === '') { return; }
      input.value = n;
    }
  });
})();
</script>

<?php jfsd_foot(); ?>
