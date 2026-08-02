<?php
declare(strict_types=1);

// JF Self Defense admin — domain constants and the flock-guarded JSON store.
// INCLUDE ONLY. Never request this file over HTTP.

// A GA4 snapshot older than this is treated as absent rather than shown as
// current. Two sibling sites in this portfolio ran on a frozen snapshot for
// three weeks without anyone noticing, because a stale number looks exactly
// like a fresh one.
const JFSD_GA_STALE_DAYS = 3;
if (!defined('JFSD_ADMIN')) {
    http_response_code(404);
    exit;
}

/* ===========================================================================
 * THE WEEKLY SCHEDULE — SINGLE SOURCE OF TRUTH
 * ---------------------------------------------------------------------------
 * The studio runs four fixed slots a week. These were previously restated in
 * six different places across the public site; this admin defines them once and
 * every screen reads from here. If a slot ever changes, change it HERE.
 *
 * 'key' matches the radio values already used on /programmes (mon|wed|sat|sun)
 * so attendance records stay comparable with anything the public site records.
 * 'dow' is PHP's date('N'): Monday = 1 ... Sunday = 7.
 * ========================================================================= */
const JFSD_SLOTS = [
    'mon' => ['key' => 'mon', 'dow' => 1, 'day' => 'Monday',    'start' => '19:00', 'end' => '20:00', 'label' => 'Monday 7-8pm',        'tag' => 'All adults'],
    'wed' => ['key' => 'wed', 'dow' => 3, 'day' => 'Wednesday', 'start' => '19:00', 'end' => '20:00', 'label' => 'Wednesday 7-8pm',     'tag' => "Women's night"],
    'sat' => ['key' => 'sat', 'dow' => 6, 'day' => 'Saturday',  'start' => '09:00', 'end' => '10:00', 'label' => 'Saturday 9-10am',     'tag' => 'All adults'],
    'sun' => ['key' => 'sun', 'dow' => 7, 'day' => 'Sunday',    'start' => '09:30', 'end' => '10:30', 'label' => 'Sunday 9.30-10.30am', 'tag' => 'Family'],
];

/** Membership plans. 'sessions' is the usual number of classes a purchase buys. */
const JFSD_PLANS = [
    'trial'     => ['label' => 'Trial class', 'sessions' => 1],
    'drop-in'   => ['label' => 'Drop-in',     'sessions' => 1],
    '4-pack'    => ['label' => '4-pack',      'sessions' => 4],
    '8-pack'    => ['label' => '8-pack',      'sessions' => 8],
    '12-pack'   => ['label' => '12-pack',     'sessions' => 12],
    '1-on-1'    => ['label' => '1-on-1',      'sessions' => 1],
    'corporate' => ['label' => 'Corporate',   'sessions' => 0],
];

const JFSD_STUDENT_STATUSES = [
    'active' => 'Active',
    'paused' => 'Paused',
    'left'   => 'Left',
];

/** Attendance marks. 'counts' = does this deduct a session from the student. */
const JFSD_ATTENDANCE_STATUSES = [
    'present'  => ['label' => 'Present',  'counts' => true],
    'late'     => ['label' => 'Late',     'counts' => true],
    'absent'   => ['label' => 'Absent',   'counts' => false],
    'excused'  => ['label' => 'Excused',  'counts' => false],
];

const JFSD_PAYMENT_METHODS = [
    'bank_transfer' => 'Bank transfer',
    'paynow'        => 'PayNow',
    'cash'          => 'Cash',
];

/** The three JSON files. Nothing outside this list can be read or written. */
const JFSD_FILES = ['students', 'attendance', 'payments'];

/**
 * Pseudo-mark meaning "take this row off the register entirely".
 * Not a member of JFSD_ATTENDANCE_STATUSES on purpose — it is an instruction,
 * not a state a student can be in. Clearing reverses whatever the row cost.
 */
const JFSD_MARK_CLEAR = 'none';

/**
 * 'covers' value used by balance corrections written into payments.json.
 * Not a plan, so it is never offered on the payment form.
 */
const JFSD_COVERS_ADJUSTMENT = 'adjustment';

/* ===========================================================================
 * Store plumbing
 * ========================================================================= */

function jfsd_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function jfsd_data_dir(): string
{
    return (string) (jfsd_config()['data_dir'] ?? '');
}

function jfsd_path(string $name): string
{
    if (!in_array($name, JFSD_FILES, true)) {
        // Programming error, not user input — but never build a path from it.
        return '';
    }
    return jfsd_data_dir() . '/' . $name . '.json';
}

/** Who the operator should call when the server side is broken. */
function jfsd_support_contact(): string
{
    $c = trim((string) (jfsd_config()['support_contact'] ?? ''));
    return $c !== '' ? $c : 'whoever set this admin up for you';
}

/** Tiny keyed store for the two halves of the setup message. */
function jfsd_store_state(string $key, ?string $set = null): string
{
    static $state = [];
    if ($set !== null) {
        $state[$key] = $set;
    }
    return (string) ($state[$key] ?? '');
}

/**
 * Make sure the data directory exists and the three JSON files are seeded.
 * Runs once per request. NEVER throws — a missing directory must degrade to a
 * readable on-screen notice, not a white page.
 *
 * The message is deliberately split in two. jfsd_store_problem() is written for
 * Jeffrey and says what he can actually do (phone someone). The SSH / mkdir /
 * chmod text lives in jfsd_store_problem_detail() and is shown behind a
 * "Technical details" disclosure, because it is addressed to a developer.
 */
function jfsd_store_check(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $fail = static function (string $plain, string $detail): void {
        jfsd_store_state('problem', $plain);
        jfsd_store_state('detail', $detail);
    };
    $cantSave = 'The admin cannot save anything right now, and nothing you type will be kept. '
        . 'Nothing already saved is lost — this is a setup problem on the server. '
        . 'Please contact ';

    $dir = jfsd_data_dir();
    if ($dir === '') {
        $fail(
            $cantSave . jfsd_support_contact() . '.',
            'No data directory is configured. Set the JFSD_PRIVATE_DIR environment variable, '
                . 'or correct the fallback path in admin/config.php.'
        );
        return;
    }

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_dir($dir)) {
        $fail(
            $cantSave . jfsd_support_contact() . '.',
            'The data directory does not exist and could not be created automatically: ' . $dir
                . ' — over SSH run: mkdir -p "' . $dir . '" && chmod 700 "' . $dir . '"'
        );
        return;
    }
    if (!is_writable($dir)) {
        $fail(
            $cantSave . jfsd_support_contact() . '.',
            'The data directory exists but PHP cannot write to it: ' . $dir
                . ' — over SSH run: chmod 700 "' . $dir . '" and confirm it is owned by the web account.'
        );
        return;
    }

    // Seed the three files on first use so every later read is a plain array.
    foreach (JFSD_FILES as $name) {
        $path = jfsd_path($name);
        if ($path !== '' && !is_file($path)) {
            $fp = @fopen($path, 'c');
            if ($fp !== false) {
                if (flock($fp, LOCK_EX)) {
                    if (filesize($path) === 0) {
                        ftruncate($fp, 0);
                        rewind($fp);
                        fwrite($fp, "[]\n");
                        fflush($fp);
                    }
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
                @chmod($path, 0600);
            }
        }
    }
}

/** Operator-facing setup problem, or null when the store is usable. */
function jfsd_store_problem(): ?string
{
    jfsd_store_check();
    $p = jfsd_store_state('problem');
    return $p === '' ? null : $p;
}

/** The developer half of the same message. Empty when there is no problem. */
function jfsd_store_problem_detail(): string
{
    jfsd_store_check();
    return jfsd_store_state('detail');
}

/**
 * Hard data-integrity flag, latched for the rest of the request.
 *
 * Set when a JSON file exists on disk but cannot be parsed. This is NOT the
 * same as "no rows yet", and the difference matters enormously: treating a
 * corrupt file as an empty one lets the next save overwrite the whole roster
 * with a single row. Once this is set, every write refuses and every page
 * renders a stop banner.
 */
function jfsd_data_fault(?string $set = null): ?string
{
    static $fault = null;
    if ($set !== null && $fault === null) {
        $fault = $set;
    }
    return $fault;
}

/**
 * Read a JSON array file under a shared lock.
 *
 * Returns [] ONLY for "there are genuinely no rows". A file that exists but
 * cannot be opened, read or decoded latches jfsd_data_fault() instead, which
 * stops every subsequent write for this request. The old version returned []
 * for both cases, so a truncated students.json read as "No students yet" and
 * the next save wiped the roster for good.
 */
function jfsd_read(string $name): array
{
    if (jfsd_store_problem() !== null) {
        return [];
    }
    $path = jfsd_path($name);
    if ($path === '') {
        return [];
    }
    if (!is_file($path)) {
        // Seeding in jfsd_store_check() creates all three, so a missing file
        // here means first run on a brand new directory. Genuinely empty.
        return [];
    }
    $fp = @fopen($path, 'r');
    if ($fp === false) {
        jfsd_data_fault($name . '.json exists but could not be opened for reading.');
        return [];
    }
    $raw    = '';
    $locked = false;
    if (flock($fp, LOCK_SH)) {
        $locked = true;
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false) {
                $locked = false; // short read — do not trust what we have
                break;
            }
            $raw .= $chunk;
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    if (!$locked) {
        jfsd_data_fault($name . '.json could not be read completely.');
        return [];
    }
    if (trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jfsd_data_fault(
            $name . '.json is on disk but is not readable JSON (' . json_last_error_msg() . '). '
            . 'It has NOT been treated as empty.'
        );
        return [];
    }
    return array_values(array_filter($decoded, 'is_array'));
}

/** Exclusive-lock depth, shared by jfsd_transaction() and the write path. */
function jfsd_transaction_depth(?int $set = null): int
{
    static $depth = 0;
    if ($set !== null) {
        $depth = $set;
    }
    return $depth;
}

/**
 * Stage one file: encode, write to a temp file beside the target, flush it to
 * disk, then verify the byte count AND read it back. Returns the temp path, or
 * null if anything at all went wrong.
 *
 * Nothing here touches the live file, so a failure at any point leaves the
 * existing data exactly as it was. That is the whole point: the previous
 * implementation truncated the live file first and discovered the disk was full
 * afterwards, having already destroyed the only copy.
 */
function jfsd_stage(string $name, array $rows): ?string
{
    $path = jfsd_path($name);
    if ($path === '') {
        return null;
    }
    $json = json_encode(
        array_values($rows),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) {
        return null;
    }
    $json .= "\n";
    $len = strlen($json);

    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $fp  = @fopen($tmp, 'wb');
    if ($fp === false) {
        return null;
    }
    $written = fwrite($fp, $json);
    $flushed = fflush($fp);
    // fsync() is PHP 8.1+. The rest of this admin targets 8.0, so guard it.
    if (function_exists('fsync')) {
        @fsync($fp);
    }
    fclose($fp);

    clearstatcache(true, $tmp);
    if ($written === false || $written !== $len || $flushed === false || @filesize($tmp) !== $len) {
        @unlink($tmp);
        return null;
    }
    // Read it back. This is the ledger; a byte count alone is not proof.
    $back = @file_get_contents($tmp);
    if ($back !== $json) {
        @unlink($tmp);
        return null;
    }
    @chmod($tmp, 0600);
    return $tmp;
}

/** Throw away staged temp files. Safe to call with an empty or partial set. */
function jfsd_discard(array $staged): void
{
    foreach ($staged as $tmp) {
        if (is_string($tmp) && $tmp !== '') {
            @unlink($tmp);
        }
    }
}

/**
 * Rename every staged file onto its target.
 *
 * Nothing is renamed until every stage has already succeeded and been verified,
 * so the only remaining failure window is between two renames — microseconds,
 * and recoverable from the .bak copy taken immediately before.
 *
 * @param array<string,string> $staged name => temp path
 */
function jfsd_commit(array $staged): bool
{
    foreach ($staged as $name => $tmp) {
        if (jfsd_path($name) === '' || !is_file($tmp)) {
            jfsd_discard($staged);
            return false;
        }
    }
    // One generation back, always, before anything is replaced.
    foreach ($staged as $name => $tmp) {
        $path = jfsd_path($name);
        if (is_file($path) && @copy($path, $path . '.bak')) {
            @chmod($path . '.bak', 0600);
        }
    }

    $done = [];
    foreach ($staged as $name => $tmp) {
        $path = jfsd_path($name);
        if (!@rename($tmp, $path)) {
            // Put back whatever we already replaced, from the copies above.
            foreach ($done as $n) {
                $p = jfsd_path($n);
                if (is_file($p . '.bak')) {
                    @copy($p . '.bak', $p);
                }
            }
            jfsd_discard($staged);
            return false;
        }
        @chmod($path, 0600);
        $done[] = $name;
        unset($staged[$name]);
    }
    return true;
}

/**
 * Write several files as one unit: stage and verify them ALL, then rename them
 * all. Either every file lands or none does.
 *
 * This is what attendance and payments need. Writing attendance.json and then
 * discovering students.json cannot be written used to leave the register saved,
 * every row stamped counted:true, and the balances never deducted — a state no
 * amount of re-saving could repair, because the counted flag made the delta a
 * no-op forever.
 *
 * @param array<string,array> $sets name => rows
 */
function jfsd_write_all(array $sets): bool
{
    if (jfsd_store_problem() !== null || jfsd_data_fault() !== null) {
        return false;
    }
    $run = static function () use ($sets): bool {
        $staged = [];
        foreach ($sets as $name => $rows) {
            $tmp = jfsd_stage((string) $name, $rows);
            if ($tmp === null) {
                jfsd_discard($staged);
                return false;
            }
            $staged[(string) $name] = $tmp;
        }
        return jfsd_commit($staged);
    };

    if (jfsd_transaction_depth() > 0) {
        return $run();
    }
    return jfsd_transaction($run) === true;
}

/** Write one JSON array file. Thin wrapper over the all-or-nothing path. */
function jfsd_write(string $name, array $rows): bool
{
    return jfsd_write_all([$name => $rows]);
}

/**
 * Run a read-modify-write under one exclusive lock held across ALL files.
 * Attendance and payments both touch students.json as well as their own file,
 * so per-file locking would still let two admins clobber each other.
 * Returns the callback's value, or false if the lock could not be taken.
 * Re-entrant: nested calls reuse the outer lock.
 *
 * @param callable():mixed $fn
 * @return mixed
 */
function jfsd_transaction(callable $fn)
{
    if (jfsd_transaction_depth() > 0) {
        jfsd_transaction_depth(jfsd_transaction_depth() + 1);
        try {
            return $fn();
        } finally {
            jfsd_transaction_depth(jfsd_transaction_depth() - 1);
        }
    }

    if (jfsd_store_problem() !== null) {
        return false;
    }
    $lockPath = jfsd_data_dir() . '/.write.lock';
    $fp = @fopen($lockPath, 'c');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    jfsd_transaction_depth(1);
    try {
        return $fn();
    } finally {
        jfsd_transaction_depth(0);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** Collision-resistant, sortable-ish id. */
function jfsd_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(7));
}

/* ===========================================================================
 * Lookups
 * ========================================================================= */

function jfsd_slot(string $key): ?array
{
    return JFSD_SLOTS[$key] ?? null;
}

/** The slots that fall on a given ISO date (usually one, sometimes none). */
function jfsd_slots_on_date(string $ymd): array
{
    $ts = strtotime($ymd . ' 12:00:00');
    if ($ts === false) {
        return [];
    }
    $dow = (int) date('N', $ts);
    $out = [];
    foreach (JFSD_SLOTS as $key => $slot) {
        if ($slot['dow'] === $dow) {
            $out[$key] = $slot;
        }
    }
    return $out;
}

function jfsd_find_student(array $students, string $id): ?array
{
    foreach ($students as $s) {
        if (($s['id'] ?? '') === $id) {
            return $s;
        }
    }
    return null;
}

/** id => student, for cheap joins on the attendance and payment screens. */
function jfsd_index_students(array $students): array
{
    $out = [];
    foreach ($students as $s) {
        $id = (string) ($s['id'] ?? '');
        if ($id !== '') {
            $out[$id] = $s;
        }
    }
    return $out;
}

/** Active students, sorted by name. The default working set on every screen. */
function jfsd_active_students(array $students): array
{
    $out = array_values(array_filter($students, static fn(array $s): bool => ($s['status'] ?? '') === 'active'));
    usort($out, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $out;
}

/**
 * The list this whole admin exists for: active students who have run out.
 * Corporate students are excluded — they are billed per engagement, not per
 * session, so a zero balance is normal and would be noise here.
 */
function jfsd_needs_attention(array $students): array
{
    $out = [];
    foreach (jfsd_active_students($students) as $s) {
        if (($s['plan'] ?? '') === 'corporate') {
            continue;
        }
        if ((int) ($s['sessions_remaining'] ?? 0) <= 0) {
            $out[] = $s;
        }
    }
    usort($out, static fn(array $a, array $b): int =>
        (int) ($a['sessions_remaining'] ?? 0) <=> (int) ($b['sessions_remaining'] ?? 0));
    return $out;
}

/**
 * A voided payment is kept forever, struck through, and excluded from money
 * totals and from session credits. Nothing in this admin deletes a money row.
 */
function jfsd_payment_is_void(array $p): bool
{
    return trim((string) ($p['voided_at'] ?? '')) !== '';
}

/** Live payments only — what the money totals and the ledger are built from. */
function jfsd_live_payments(array $payments): array
{
    return array_values(array_filter($payments, static fn(array $p): bool => !jfsd_payment_is_void($p)));
}

/**
 * True for a real payment, false for a balance adjustment.
 *
 * Adjustment rows live in payments.json so the ledger identity holds, but they
 * carry no money and must never be counted in "taken this month" or in a
 * student's payment total.
 */
function jfsd_payment_is_money(array $p): bool
{
    return (string) ($p['covers'] ?? '') !== JFSD_COVERS_ADJUSTMENT;
}

/** Display label for a payment's 'covers' value, including adjustment rows. */
function jfsd_covers_label(string $covers): string
{
    if (isset(JFSD_PLANS[$covers])) {
        return (string) JFSD_PLANS[$covers]['label'];
    }
    if ($covers === JFSD_COVERS_ADJUSTMENT) {
        return 'Balance correction';
    }
    return $covers === '' ? '—' : $covers;
}

/**
 * A balance movement that is not a payment: an opening balance typed when a
 * student is created, or a manual correction typed on the student form.
 *
 * These are written into payments.json for one reason: so that
 *     Σ sessions_granted (live rows) − count(counted attendance rows)
 * remains the authority on every balance. Without them jfsd_expected_sessions()
 * reports a phantom drift for every student who was ever given an opening
 * balance, and jfsd_reconcile() cannot be trusted or switched on.
 */
function jfsd_adjustment_row(string $studentId, int $sessions, string $note, string $user): array
{
    return [
        'id'               => jfsd_id('adj'),
        'student_id'       => $studentId,
        'date_received'    => jfsd_today(),
        'amount_sgd'       => 0,
        'method'           => '',
        'reference'        => '',
        'covers'           => JFSD_COVERS_ADJUSTMENT,
        'sessions_granted' => $sessions,
        'note'             => $note,
        'recorded_at'      => jfsd_now_iso(),
        'recorded_by'      => $user,
    ];
}

/**
 * Has this attendance row already charged the student a session?
 *
 * Rows written by this admin always carry an explicit 'counted' flag. A row that
 * has lost it — a hand-repaired file, or a restore from a .bak taken mid-write —
 * must NOT fall back to false: false is the direction that charges the student a
 * SECOND session the next time the register is saved. Infer it from the stored
 * status instead, which is what the operator can actually see on screen.
 */
function jfsd_row_counted(array $row): bool
{
    if (array_key_exists('counted', $row)) {
        return (bool) $row['counted'];
    }
    $status = (string) ($row['status'] ?? '');
    return (bool) (JFSD_ATTENDANCE_STATUSES[$status]['counts'] ?? false);
}

/**
 * What each student's balance SHOULD be, straight from the ledger:
 *
 *     Σ payments.sessions_granted (excluding voided)
 *   − count(attendance rows with counted = true)
 *
 * Manual corrections are written into payments.json as adjustment rows, and
 * students created with an opening balance get one too, so this stays the
 * authority rather than a second opinion. Anywhere it disagrees with the stored
 * sessions_remaining, something has drifted and the roster says so.
 *
 * @return array<string,int> student_id => expected balance
 */
function jfsd_expected_sessions(array $payments, array $attendance): array
{
    $out = [];
    foreach ($payments as $p) {
        if (jfsd_payment_is_void($p)) {
            continue;
        }
        $sid = (string) ($p['student_id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $out[$sid] = ($out[$sid] ?? 0) + (int) ($p['sessions_granted'] ?? 0);
    }
    foreach ($attendance as $row) {
        if (!jfsd_row_counted($row)) {
            continue;
        }
        $sid = (string) ($row['student_id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $out[$sid] = ($out[$sid] ?? 0) - 1;
    }
    return $out;
}

/** Students whose stored balance disagrees with the ledger. */
function jfsd_reconcile(array $students, array $payments, array $attendance): array
{
    $expected = jfsd_expected_sessions($payments, $attendance);
    $out      = [];
    foreach ($students as $s) {
        $sid = (string) ($s['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $exp   = (int) ($expected[$sid] ?? 0);
        $store = (int) ($s['sessions_remaining'] ?? 0);
        if ($exp !== $store) {
            $out[] = ['student' => $s, 'expected' => $exp, 'stored' => $store];
        }
    }
    return $out;
}

/* ===========================================================================
 * Mutations — every one of these runs inside a single lock.
 * All return ['ok' => bool, 'msg' => string].
 * ========================================================================= */

/**
 * Save one class register. Idempotent by (date, slot, student_id): re-saving
 * the same marks changes nothing. A record carries its own 'counted' flag, so
 * flipping present -> absent gives the session back and flipping it again does
 * not take a second one.
 *
 * @param array<string,string> $marks student_id => status
 */
function jfsd_save_attendance(string $date, string $slotKey, array $marks, string $user): array
{
    $result = jfsd_transaction(static function () use ($date, $slotKey, $marks, $user): array {
        $attendance = jfsd_read('attendance');
        $students   = jfsd_read('students');
        $byId       = jfsd_index_students($students);

        $now        = jfsd_now_iso();
        $deducted   = 0;
        $restored   = 0;
        $dupRemoved = 0;

        // Index this class's rows by student, collapsing any duplicates on
        // (date, slot, student_id) as we go. Keeping only the first means the
        // 'counted' flag we later flip is the only one that exists; a duplicate
        // that had already charged a session gives it back on the way out, so
        // the ledger identity survives the cleanup.
        $kept     = [];
        $existing = [];
        foreach ($attendance as $row) {
            if (($row['date'] ?? '') !== $date || ($row['slot'] ?? '') !== $slotKey) {
                $kept[] = $row;
                continue;
            }
            $sid = (string) ($row['student_id'] ?? '');
            if ($sid !== '' && isset($existing[$sid])) {
                if (jfsd_row_counted($row) && isset($byId[$sid])) {
                    $byId[$sid]['sessions_remaining'] = (int) ($byId[$sid]['sessions_remaining'] ?? 0) + 1;
                    $byId[$sid]['updated_at']         = $now;
                }
                $dupRemoved++;
                continue;
            }
            $kept[] = $row;
            if ($sid !== '') {
                $existing[$sid] = count($kept) - 1;
            }
        }
        $attendance = $kept;

        foreach ($marks as $studentId => $status) {
            $studentId = (string) $studentId;
            $status    = (string) $status;
            if (!isset($byId[$studentId]) || !isset(JFSD_ATTENDANCE_STATUSES[$status])) {
                continue;
            }
            $shouldCount = (bool) JFSD_ATTENDANCE_STATUSES[$status]['counts'];

            if (isset($existing[$studentId])) {
                $idx        = $existing[$studentId];
                $wasCounted = jfsd_row_counted($attendance[$idx]);
                $attendance[$idx]['status']    = $status;
                $attendance[$idx]['marked_at'] = $now;
                $attendance[$idx]['marked_by'] = $user;
                $attendance[$idx]['counted']   = $shouldCount;
            } else {
                $wasCounted   = false;
                $attendance[] = [
                    'id'         => jfsd_id('att'),
                    'date'       => $date,
                    'slot'       => $slotKey,
                    'student_id' => $studentId,
                    'status'     => $status,
                    'counted'    => $shouldCount,
                    'marked_at'  => $now,
                    'marked_by'  => $user,
                ];
            }

            // The delta is the ONLY place sessions move for attendance.
            if ($shouldCount && !$wasCounted) {
                $byId[$studentId]['sessions_remaining'] = (int) ($byId[$studentId]['sessions_remaining'] ?? 0) - 1;
                $byId[$studentId]['updated_at']         = $now;
                $deducted++;
            } elseif (!$shouldCount && $wasCounted) {
                $byId[$studentId]['sessions_remaining'] = (int) ($byId[$studentId]['sessions_remaining'] ?? 0) + 1;
                $byId[$studentId]['updated_at']         = $now;
                $restored++;
            }
        }

        // Rebuild students in original order with the updated rows.
        $updatedStudents = [];
        foreach ($students as $s) {
            $id = (string) ($s['id'] ?? '');
            $updatedStudents[] = $byId[$id] ?? $s;
        }

        // ONE atomic write. Committing attendance first and students second used
        // to leave every row stamped counted:true with no session deducted — a
        // state no amount of re-saving could repair, because the counted flag
        // made the delta a permanent no-op.
        if (!jfsd_write_all(['attendance' => $attendance, 'students' => $updatedStudents])) {
            return [
                'ok'  => false,
                'msg' => 'Nothing was saved — please try again. The register and the session balances '
                    . 'are both exactly as they were.',
            ];
        }

        $bits = [];
        if ($deducted > 0)   { $bits[] = $deducted . ' session' . ($deducted === 1 ? '' : 's') . ' deducted'; }
        if ($restored > 0)   { $bits[] = $restored . ' session' . ($restored === 1 ? '' : 's') . ' given back'; }
        if ($dupRemoved > 0) { $bits[] = $dupRemoved . ' duplicate row' . ($dupRemoved === 1 ? '' : 's') . ' tidied up'; }
        $suffix = $bits ? ' (' . implode(', ', $bits) . ').' : ' (no change to session balances).';

        return ['ok' => true, 'msg' => 'Register saved' . $suffix];
    });

    if (!is_array($result)) {
        return ['ok' => false, 'msg' => 'The data files are busy or unavailable. Nothing was saved — please try again.'];
    }
    return $result;
}

/** Record a payment and credit the sessions it bought. */
function jfsd_record_payment(array $payment): array
{
    $result = jfsd_transaction(static function () use ($payment): array {
        $students = jfsd_read('students');
        $idx      = null;
        foreach ($students as $i => $s) {
            if (($s['id'] ?? '') === $payment['student_id']) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return ['ok' => false, 'msg' => 'That student no longer exists. Nothing was recorded.'];
        }

        $payments   = jfsd_read('payments');
        $payments[] = $payment;

        $granted = (int) $payment['sessions_granted'];
        $students[$idx]['sessions_remaining'] = (int) ($students[$idx]['sessions_remaining'] ?? 0) + $granted;
        $students[$idx]['updated_at']         = jfsd_now_iso();

        // One atomic write — banking the money row without crediting the
        // sessions would make re-entering the payment double-book the money.
        if (!jfsd_write_all(['payments' => $payments, 'students' => $students])) {
            return ['ok' => false, 'msg' => 'Nothing was saved — please try again. The payment was not recorded.'];
        }

        $name = (string) ($students[$idx]['name'] ?? 'student');
        return [
            'ok'  => true,
            'msg' => 'Payment recorded for ' . $name . '. '
                . ($granted > 0
                    ? $granted . ' session' . ($granted === 1 ? '' : 's') . ' added — balance is now '
                        . (int) $students[$idx]['sessions_remaining'] . '.'
                    : 'No sessions were added.'),
        ];
    });

    if (!is_array($result)) {
        return ['ok' => false, 'msg' => 'The data files are busy or unavailable. Nothing was saved — please try again.'];
    }
    return $result;
}

/**
 * Void a mis-keyed payment: mark the row voided and take its sessions back off.
 *
 * The row is KEPT. A record of money a student actually handed over is not
 * something a mis-tap on a phone gets to destroy — it is struck through, left
 * out of every total, and still visible. Voiding twice is refused, because a
 * double-tap would otherwise subtract the sessions twice.
 */
function jfsd_void_payment(string $paymentId, string $user): array
{
    $result = jfsd_transaction(static function () use ($paymentId, $user): array {
        $payments = jfsd_read('payments');
        $found    = null;
        foreach ($payments as $i => $p) {
            if (($p['id'] ?? '') === $paymentId) {
                $found = $i;
                break;
            }
        }
        if ($found === null) {
            return ['ok' => false, 'msg' => 'That payment was not found.'];
        }
        if (jfsd_payment_is_void($payments[$found])) {
            return ['ok' => false, 'msg' => 'That one was already voided. Nothing was changed.'];
        }

        $payment = $payments[$found];
        $payments[$found]['voided_at'] = jfsd_now_iso();
        $payments[$found]['voided_by'] = $user;

        $students = jfsd_read('students');
        $name     = 'that student';
        foreach ($students as $i => $s) {
            if (($s['id'] ?? '') === ($payment['student_id'] ?? '')) {
                $students[$i]['sessions_remaining'] = (int) ($s['sessions_remaining'] ?? 0)
                    - (int) ($payment['sessions_granted'] ?? 0);
                $students[$i]['updated_at'] = jfsd_now_iso();
                $name = (string) ($s['name'] ?? $name);
                break;
            }
        }

        if (!jfsd_write_all(['payments' => $payments, 'students' => $students])) {
            return ['ok' => false, 'msg' => 'Nothing was saved — please try again. The payment was not voided.'];
        }

        $took = (int) ($payment['sessions_granted'] ?? 0);
        return [
            'ok'  => true,
            'msg' => 'Payment voided. It stays in the history, struck through, but no longer counts'
                . ($took !== 0
                    ? ', and ' . abs($took) . ' session' . (abs($took) === 1 ? '' : 's') . ' came back off ' . $name . '.'
                    : '.'),
        ];
    });

    if (!is_array($result)) {
        return ['ok' => false, 'msg' => 'The data files are busy or unavailable. Nothing was saved — please try again.'];
    }
    return $result;
}

/**
 * Create or update one student record. $student must already be validated.
 *
 * The edit form does NOT own the balance by value. It renders the figure it was
 * given plus a hidden copy of it, and this function compares that copy against
 * what is on disk right now:
 *
 *   - unchanged, and the operator did not retype it -> the balance is left alone
 *   - changed underneath him (he marked the register in another tab while this
 *     form sat open) -> the whole save is REFUSED, because writing the stale
 *     figure would silently un-deduct a session that the register already
 *     charged, and the counted flag makes that impossible to re-apply
 *   - the operator genuinely retyped it -> the DELTA is applied and a matching
 *     adjustment row goes into payments.json, so the ledger still explains it
 *
 * Fields are merged, never replaced, so a key this form does not know about
 * (or a record hand-repaired without one) survives instead of being defaulted.
 *
 * @param int|null $balanceOrig the balance the form was rendered with, or null
 *                              when the submission carried no copy at all
 */
function jfsd_save_student(array $student, bool $isNew, ?int $balanceOrig, string $user): array
{
    $result = jfsd_transaction(static function () use ($student, $isNew, $balanceOrig, $user): array {
        $students = jfsd_read('students');
        $payments = null; // read and rewritten only when an adjustment is needed
        $now      = jfsd_now_iso();
        $wanted   = (int) ($student['sessions_remaining'] ?? 0);

        if ($isNew) {
            $students[] = $student;
            if ($wanted !== 0) {
                $payments   = jfsd_read('payments');
                $payments[] = jfsd_adjustment_row((string) $student['id'], $wanted, 'Opening balance', $user);
            }
        } else {
            $idx = null;
            foreach ($students as $i => $s) {
                if (($s['id'] ?? '') === $student['id']) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                return ['ok' => false, 'msg' => 'That student was not found. Nothing was changed.'];
            }

            $stored = (int) ($students[$idx]['sessions_remaining'] ?? 0);

            if ($balanceOrig === null) {
                // No copy came back with the form. Never guess — leave the
                // stored balance exactly where it is and save the rest.
                $student['sessions_remaining'] = $stored;
            } elseif ($balanceOrig !== $stored) {
                return [
                    'ok'  => false,
                    'msg' => 'Nothing was saved. ' . (string) ($students[$idx]['name'] ?? 'This student')
                        . ' now has ' . $stored . ' session' . ($stored === 1 ? '' : 's')
                        . ' left, not ' . $balanceOrig . ' — that changed while this page was open. '
                        . 'Open the record again and make your change on the current figure.',
                ];
            } elseif ($wanted !== $stored) {
                $payments   = jfsd_read('payments');
                $payments[] = jfsd_adjustment_row(
                    (string) $student['id'],
                    $wanted - $stored,
                    'Manual correction by ' . $user,
                    $user
                );
            }

            // Merge, do not replace.
            $students[$idx] = array_merge($students[$idx], $student);
            if (($students[$idx]['created_at'] ?? '') === '') {
                $students[$idx]['created_at'] = $now;
            }
        }

        $sets = ['students' => $students];
        if ($payments !== null) {
            $sets['payments'] = $payments;
        }
        if (!jfsd_write_all($sets)) {
            return ['ok' => false, 'msg' => 'Could not save the roster. Nothing was changed.'];
        }
        return [
            'ok'  => true,
            'msg' => ($isNew ? 'Added ' : 'Updated ') . $student['name'] . '.',
        ];
    });

    if (!is_array($result)) {
        return ['ok' => false, 'msg' => 'The data files are busy or unavailable. Nothing was saved — please try again.'];
    }
    return $result;
}

/**
 * Soft delete. A person's record is never removed — status goes to 'left' so
 * their attendance and payment history stays readable.
 */
function jfsd_set_student_status(string $id, string $status): array
{
    if (!isset(JFSD_STUDENT_STATUSES[$status])) {
        return ['ok' => false, 'msg' => 'Unknown status.'];
    }
    $result = jfsd_transaction(static function () use ($id, $status): array {
        $students = jfsd_read('students');
        $name     = '';
        $hit      = false;
        foreach ($students as $i => $s) {
            if (($s['id'] ?? '') === $id) {
                $students[$i]['status']     = $status;
                $students[$i]['updated_at'] = jfsd_now_iso();
                $name = (string) ($s['name'] ?? '');
                $hit  = true;
                break;
            }
        }
        if (!$hit) {
            return ['ok' => false, 'msg' => 'That student was not found.'];
        }
        if (!jfsd_write('students', $students)) {
            return ['ok' => false, 'msg' => 'Could not save the roster. Nothing was changed.'];
        }
        return ['ok' => true, 'msg' => $name . ' is now marked ' . JFSD_STUDENT_STATUSES[$status] . '.'];
    });

    if (!is_array($result)) {
        return ['ok' => false, 'msg' => 'The data files are busy or unavailable. Nothing was saved — please try again.'];
    }
    return $result;
}

/**
 * Reconcile repair: set one student's stored balance to the ledger figure.
 *
 * The expected figure is recomputed inside the lock rather than trusted from the
 * form, so a stale dashboard cannot write a stale number.
 */
function jfsd_repair_balance(string $id, string $user): array
{
    $result = jfsd_transaction(static function () use ($id, $user): array {
        $students   = jfsd_read('students');
        $payments   = jfsd_read('payments');
        $attendance = jfsd_read('attendance');
        $expected   = jfsd_expected_sessions($payments, $attendance);

        $idx = null;
        foreach ($students as $i => $s) {
            if (($s['id'] ?? '') === $id) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return ['ok' => false, 'msg' => 'That student was not found.'];
        }

        $name   = (string) ($students[$idx]['name'] ?? 'That student');
        $exp    = (int) ($expected[$id] ?? 0);
        $stored = (int) ($students[$idx]['sessions_remaining'] ?? 0);
        if ($exp === $stored) {
            return ['ok' => true, 'msg' => $name . ' already matches the record of payments and attendance. Nothing was changed.'];
        }

        $students[$idx]['sessions_remaining'] = $exp;
        $students[$idx]['updated_at']         = jfsd_now_iso();
        if (!jfsd_write_all(['students' => $students])) {
            return ['ok' => false, 'msg' => 'Could not save the roster. Nothing was changed.'];
        }
        return [
            'ok'  => true,
            'msg' => $name . ' now shows ' . $exp . ' session' . ($exp === 1 ? '' : 's')
                . ' left, which is what the payments and attendance add up to (was ' . $stored . ').',
        ];
    });

    if (!is_array($result)) {
        return ['ok' => false, 'msg' => 'The data files are busy or unavailable. Nothing was saved — please try again.'];
    }
    return $result;
}

/** ISO 8601 timestamp in the studio's timezone. Storage format everywhere. */
function jfsd_now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone((string) (jfsd_config()['timezone'] ?? 'Asia/Singapore'))))
        ->format('c');
}

/** Today in the studio's timezone, ISO date. */
function jfsd_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone((string) (jfsd_config()['timezone'] ?? 'Asia/Singapore'))))
        ->format('Y-m-d');
}

/**
 * Read the GA4 snapshot written by scripts/fetch-ga-snapshot.mjs.
 *
 * Lives at admin/data/ga-snapshot.json — inside the deploy tree so it ships
 * with the build, but admin/.htaccess denies *.json so it is disk-readable by
 * PHP and 403 over HTTP.
 *
 * Returns null if the file is missing, unreadable, malformed, or older than
 * JFSD_GA_STALE_DAYS. Null means the caller shows "not available" rather than
 * presenting stale figures as current — the failure mode that let a frozen
 * snapshot sit unnoticed on two sibling sites for three weeks.
 *
 * @return array<string,mixed>|null
 */
function jfsd_ga_snapshot(): ?array
{
    static $cached = false;
    static $value = null;
    if ($cached) {
        return $value;
    }
    $cached = true;

    $path = __DIR__ . '/data/ga-snapshot.json';
    if (!is_file($path) || !is_readable($path)) {
        return $value = null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $value = null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['windows']) || !is_array($data['windows'])) {
        return $value = null;
    }

    $generated = isset($data['generatedAt']) ? strtotime((string) $data['generatedAt']) : false;
    if ($generated === false) {
        return $value = null;
    }
    $ageDays = (time() - $generated) / 86400;
    if ($ageDays > JFSD_GA_STALE_DAYS) {
        return $value = null;
    }

    $data['_ageDays'] = $ageDays;
    return $value = $data;
}

/** Human-readable age of the GA4 snapshot, for the dashboard footnote. */
function jfsd_ga_updated_label(): string
{
    $snap = jfsd_ga_snapshot();
    if ($snap === null) {
        return 'never';
    }
    $age = (float) ($snap['_ageDays'] ?? 0);
    if ($age < 1)  { return 'today'; }
    if ($age < 2)  { return 'yesterday'; }
    return (int) floor($age) . ' days ago';
}
