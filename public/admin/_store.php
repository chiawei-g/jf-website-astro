<?php
declare(strict_types=1);

// JF Self Defense admin — domain constants and the flock-guarded JSON store.
// INCLUDE ONLY. Never request this file over HTTP.

if (!defined('JFSD_ADMIN')) {
    http_response_code(404);
    exit;
}

// A GA4 snapshot older than this is treated as absent rather than shown as
// current. Two sibling sites in this portfolio ran on a frozen snapshot for
// three weeks without anyone noticing, because a stale number looks exactly
// like a fresh one.
const JFSD_GA_STALE_DAYS = 3;

// The same guard for the Search Console snapshot, but a longer fuse, and the
// difference is deliberate rather than an oversight.
//
// GA4 is re-fetched whenever the site is deployed, so three days without a
// refresh really does mean something has broken. Search Console is a WEEKLY pull
// by design — the whole portfolio's plan (claude-shared/seo/GSC-setup-guide.md,
// Part E) is one /seo-pulse job a week, and Search Console itself only finalises
// figures two to three days after the fact, so there is nothing a daily pull
// would learn. Reusing the three-day figure here would mark a perfectly healthy
// weekly snapshot as stale four days out of every seven, and a warning that is
// wrong most of the time is a warning nobody reads.
//
// Eight days = one weekly cycle plus a day of slack. If the fetch is ever moved
// onto every deploy, tighten this to match; it is one number.
const JFSD_GSC_STALE_DAYS = 8;

/* ===========================================================================
 * THE WEEKLY PATTERN — A SUGGESTION, NEVER AN IDENTITY
 * ---------------------------------------------------------------------------
 * The studio normally runs four classes a week. This constant exists so the
 * calendar can show what OUGHT to be on a date nobody has touched yet. That is
 * ALL it does.
 *
 * The moment a register is saved, a real class record is written into
 * sessions.json carrying that day's actual start and end, and every screen
 * reads the record from then on. So editing the times below changes what is
 * suggested from today forward, and cannot reach back and re-time a register
 * that has already been taken. That is the entire point of the split: if
 * Wednesday ever moves from 7pm to 8pm, every past Wednesday must keep saying
 * 7pm, because 7pm is when those people were actually in the room.
 *
 * There is no marketing tag here on purpose. Which group a class is aimed at is
 * a public-site concern; internally people just turn up.
 *
 * 'dow' is PHP's date('N'): Monday = 1 ... Sunday = 7.
 * ========================================================================= */
const JFSD_TEMPLATE = [
    ['dow' => 1, 'day' => 'Monday',    'start' => '19:00', 'end' => '20:00'],
    ['dow' => 3, 'day' => 'Wednesday', 'start' => '19:00', 'end' => '20:00'],
    ['dow' => 6, 'day' => 'Saturday',  'start' => '09:00', 'end' => '10:00'],
    ['dow' => 7, 'day' => 'Sunday',    'start' => '09:30', 'end' => '10:30'],
];

/**
 * How a stored class came to exist.
 *   template — the weekly pattern suggested it and a register was saved on it
 *   adhoc    — somebody put it on the calendar by hand for that date only
 */
const JFSD_SESSION_SOURCES = ['template', 'adhoc'];

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

/* ===========================================================================
 * ATTENDANCE IS A LIST OF WHO TURNED UP
 * ---------------------------------------------------------------------------
 * There is no absent, no excused and no late, and there is no status field on
 * an attendance row at all.
 *
 *     on the list  =  came  =  one session off
 *     not on it    =  did not come, and there is nothing to record
 *
 * Absent and excused only mean something when attendance is expected in
 * advance. Nobody books anything here; people turn up. Late cost a control on
 * every row and moved nothing in the ledger. Around fifty people are on the
 * books and four or five are in any one class, so the list is built by typing
 * a name, not by working down everybody.
 *
 * The consequence for the ledger is the important one: a row exists ONLY
 * because somebody was added, so a row with no 'counted' flag on it must be
 * read as counted. See jfsd_row_counted().
 * ========================================================================= */

const JFSD_PAYMENT_METHODS = [
    'bank_transfer' => 'Bank transfer',
    'paynow'        => 'PayNow',
    'cash'          => 'Cash',
];

/** The four JSON files. Nothing outside this list can be read or written. */
const JFSD_FILES = ['students', 'attendance', 'payments', 'sessions'];

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
 * Make sure the data directory exists and the four JSON files are seeded.
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

    // Seed the four files on first use so every later read is a plain array.
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

/** PHP's date('N') for an ISO date: Monday = 1 ... Sunday = 7. 0 if unparseable. */
function jfsd_dow(string $ymd): int
{
    $ts = strtotime($ymd . ' 12:00:00');
    return $ts === false ? 0 : (int) date('N', $ts);
}

/** What the weekly pattern suggests for one date. Usually one, sometimes none. */
function jfsd_template_on_date(string $ymd): array
{
    $dow = jfsd_dow($ymd);
    $out = [];
    foreach (JFSD_TEMPLATE as $entry) {
        if ((int) $entry['dow'] === $dow) {
            $out[] = $entry;
        }
    }
    usort($out, static fn(array $a, array $b): int => strcmp((string) $a['start'], (string) $b['start']));
    return $out;
}

/** Stored class records for one date, earliest first. */
function jfsd_sessions_on_date(array $sessions, string $ymd): array
{
    $out = [];
    foreach ($sessions as $s) {
        if ((string) ($s['date'] ?? '') === $ymd) {
            $out[] = $s;
        }
    }
    usort($out, static fn(array $a, array $b): int =>
        [(string) ($a['start'] ?? ''), (string) ($a['id'] ?? '')]
        <=> [(string) ($b['start'] ?? ''), (string) ($b['id'] ?? '')]);
    return $out;
}

function jfsd_find_session(array $sessions, string $id): ?array
{
    if ($id === '') {
        return null;
    }
    foreach ($sessions as $s) {
        if ((string) ($s['id'] ?? '') === $id) {
            return $s;
        }
    }
    return null;
}

/**
 * Everything that belongs on the calendar for one date: the classes that have
 * actually been stored, plus whatever the weekly pattern still suggests on top
 * of them. Earliest first.
 *
 * Each entry is normalised so no screen has to care which kind it is:
 *   id      the stored id, or '' when the pattern is only suggesting it
 *   stored  true once there is a real record behind it
 *
 * Matching a suggestion to a stored class is deliberately two-pass:
 *
 *   1. same start time  — the ordinary case.
 *   2. failing that, any leftover stored class that itself came from the
 *      pattern. This is what stops a time change putting a phantom class on
 *      history: move Wednesday to 8pm and every past Wednesday already saved at
 *      7pm would otherwise sprout a second, never-taken 8pm class.
 */
function jfsd_classes_on_date(array $sessions, string $ymd): array
{
    $stored  = jfsd_sessions_on_date($sessions, $ymd);
    $pattern = jfsd_template_on_date($ymd);

    $taken   = [];  // stored index => already answers a suggestion
    $suggest = [];  // pattern index => still unanswered
    foreach ($pattern as $i => $entry) {
        $suggest[$i] = true;
    }

    foreach ($pattern as $i => $entry) {
        foreach ($stored as $j => $s) {
            if (isset($taken[$j]) || (string) ($s['start'] ?? '') !== (string) $entry['start']) {
                continue;
            }
            $taken[$j] = true;
            unset($suggest[$i]);
            break;
        }
    }
    foreach ($pattern as $i => $entry) {
        if (!isset($suggest[$i])) {
            continue;
        }
        foreach ($stored as $j => $s) {
            if (isset($taken[$j]) || (string) ($s['source'] ?? '') !== 'template') {
                continue;
            }
            $taken[$j] = true;
            unset($suggest[$i]);
            break;
        }
    }

    $out = [];
    foreach ($stored as $s) {
        $out[] = [
            'id'     => (string) ($s['id'] ?? ''),
            'date'   => $ymd,
            'start'  => (string) ($s['start'] ?? ''),
            'end'    => (string) ($s['end'] ?? ''),
            'label'  => (string) ($s['label'] ?? ''),
            'source' => (string) ($s['source'] ?? 'adhoc'),
            'stored' => true,
        ];
    }
    foreach ($pattern as $i => $entry) {
        if (!isset($suggest[$i])) {
            continue;
        }
        $out[] = [
            'id'     => '',
            'date'   => $ymd,
            'start'  => (string) $entry['start'],
            'end'    => (string) $entry['end'],
            'label'  => '',
            'source' => 'template',
            'stored' => false,
        ];
    }
    usort($out, static fn(array $a, array $b): int =>
        [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);
    return $out;
}

/**
 * How many people are on each class's list.
 *
 * A pair is counted once however many rows carry it, so a duplicate that got
 * onto disk some other way cannot inflate a headcount on screen.
 *
 * @return array<string,int> class id => how many came
 */
function jfsd_attendance_counts(array $attendance): array
{
    $out  = [];
    $seen = [];
    foreach ($attendance as $row) {
        $sid = (string) ($row['session_id'] ?? '');
        $stu = (string) ($row['student_id'] ?? '');
        if ($sid === '' || $stu === '' || isset($seen[$sid . '|' . $stu])) {
            continue;
        }
        $seen[$sid . '|' . $stu] = true;
        $out[$sid] = ($out[$sid] ?? 0) + 1;
    }
    return $out;
}

/**
 * Who is on one class's list, first added first, duplicates collapsed.
 *
 * @return string[] student ids
 */
function jfsd_attendees(array $attendance, string $sessionId): array
{
    if ($sessionId === '') {
        return [];
    }
    $out = [];
    foreach ($attendance as $row) {
        if ((string) ($row['session_id'] ?? '') !== $sessionId) {
            continue;
        }
        $stu = (string) ($row['student_id'] ?? '');
        if ($stu !== '' && !isset($out[$stu])) {
            $out[$stu] = true;
        }
    }
    return array_keys($out);
}

/**
 * The equivalent class one week earlier, which is what "same as last week"
 * copies from. Null when there was nothing to copy.
 *
 * Matched on start time first: that is the ordinary case and the one that
 * cannot be wrong. Failing that, if the earlier day carried exactly one class,
 * that one is used — so a week the class started half an hour late still
 * offers its list. Anything looser would quietly pull the wrong people in.
 */
function jfsd_previous_week_class(array $sessions, string $ymd, string $start): ?array
{
    $ts = strtotime($ymd . ' 12:00:00 -7 days');
    if ($ts === false) {
        return null;
    }
    $onDay = jfsd_sessions_on_date($sessions, date('Y-m-d', $ts));
    foreach ($onDay as $s) {
        if ((string) ($s['start'] ?? '') === $start) {
            return $s;
        }
    }
    return count($onDay) === 1 ? $onDay[0] : null;
}

/**
 * class id => the date it ran on.
 *
 * Attendance rows carry no date of their own on purpose: the class record owns
 * the date, so there is exactly one place a date can be read from and nothing
 * to drift. Anything that wants to count marks per day joins through this.
 *
 * @return array<string,string>
 */
function jfsd_session_dates(array $sessions): array
{
    $out = [];
    foreach ($sessions as $s) {
        $id = (string) ($s['id'] ?? '');
        if ($id !== '') {
            $out[$id] = (string) ($s['date'] ?? '');
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
 * Rows written by this admin always carry an explicit 'counted' flag. A row
 * that has lost it — a hand-repaired file, or a restore from a .bak taken
 * mid-write — must NOT fall back to false. False is the direction that lets
 * the student be charged a SECOND time, and it is also simply wrong under this
 * model: a row exists only because somebody was added to a class, and adding
 * is the thing that deducts.
 */
function jfsd_row_counted(array $row): bool
{
    if (array_key_exists('counted', $row)) {
        return (bool) $row['counted'];
    }
    return true;
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
 * Put an extra class on the calendar for one date: a time the venue forced, or a
 * one-off somebody asked for. Unlike a class the weekly pattern suggests, this
 * one is a real record from the moment it is added, before any register exists.
 */
function jfsd_add_session(string $date, string $start, string $end, string $label, string $user): array
{
    if (!jfsd_valid_date($date)) {
        return ['ok' => false, 'msg' => 'That is not a real date, so nothing was added.'];
    }
    if (!jfsd_valid_time($start) || !jfsd_valid_time($end)) {
        return ['ok' => false, 'msg' => 'Please give both a start time and a finish time. Nothing was added.'];
    }
    if ($end <= $start) {
        return ['ok' => false, 'msg' => 'The finish time has to be later than the start time. Nothing was added.'];
    }

    $result = jfsd_transaction(static function () use ($date, $start, $end, $label, $user): array {
        $sessions = jfsd_read('sessions');
        foreach (jfsd_sessions_on_date($sessions, $date) as $s) {
            if ((string) ($s['start'] ?? '') === $start) {
                return [
                    'ok'  => false,
                    'msg' => 'There is already a class at that time on that day, so nothing was added.',
                ];
            }
        }
        $sessions[] = [
            'id'         => jfsd_id('ses'),
            'date'       => $date,
            'start'      => $start,
            'end'        => $end,
            'label'      => $label,
            'source'     => 'adhoc',
            'created_at' => jfsd_now_iso(),
            'created_by' => $user,
        ];
        if (!jfsd_write('sessions', $sessions)) {
            return ['ok' => false, 'msg' => 'Nothing was saved, so please try again. The class was not added.'];
        }
        return [
            'ok'  => true,
            'msg' => 'Added a class at ' . jfsd_time_friendly($start) . ' on ' . jfsd_date_friendly($date) . '.',
        ];
    });

    if (!is_array($result)) {
        return ['ok' => false, 'msg' => 'The data files are busy or unavailable. Nothing was saved, so please try again.'];
    }
    return $result;
}

/**
 * Put one or more people on a class's list. Each of them loses one session.
 *
 * IDEMPOTENT by (class, student). Somebody already on the list is skipped
 * whole: no second row, no second deduction. That is what makes it safe to
 * have no Save button. A double tap on slow studio wifi, a refresh, or "same
 * as last week" run twice all land on the same list and the same balances.
 *
 * $sessionId identifies a class that already has a record. When it is empty
 * the class is one the weekly pattern only suggests and nobody has been added
 * to it yet: $date + $start say which suggestion, the times are read from the
 * pattern AS IT STANDS NOW, and a real record is written into sessions.json in
 * the same commit as the names. From that moment the record is the truth, and
 * a later edit to the pattern cannot re-time it.
 *
 * @param string[] $studentIds
 * @return array{ok:bool,msg:string,added:int}
 */
function jfsd_add_attendees(string $sessionId, string $date, string $start, array $studentIds, string $user): array
{
    $result = jfsd_transaction(static function () use ($sessionId, $date, $start, $studentIds, $user): array {
        $sessions   = jfsd_read('sessions');
        $attendance = jfsd_read('attendance');
        $students   = jfsd_read('students');
        $byId       = jfsd_index_students($students);

        $now        = jfsd_now_iso();
        $newSession = null;

        if ($sessionId === '') {
            $entry = null;
            foreach (jfsd_template_on_date($date) as $e) {
                if ((string) $e['start'] === $start) {
                    $entry = $e;
                    break;
                }
            }
            if ($entry === null) {
                return ['ok' => false, 'msg' => 'That class is not on the calendar, so nobody was added.', 'added' => 0];
            }
            // Two phones, one class: the other one may have written the record
            // seconds ago. Re-use it rather than creating a second class at the
            // same time, which would split the list in half.
            foreach (jfsd_sessions_on_date($sessions, $date) as $s) {
                if ((string) ($s['start'] ?? '') === $start) {
                    $sessionId = (string) ($s['id'] ?? '');
                    break;
                }
            }
            if ($sessionId === '') {
                $newSession = [
                    'id'         => jfsd_id('ses'),
                    'date'       => $date,
                    'start'      => (string) $entry['start'],
                    'end'        => (string) $entry['end'],
                    'label'      => '',
                    'source'     => 'template',
                    'created_at' => $now,
                    'created_by' => $user,
                ];
                $sessions[] = $newSession;
                $sessionId  = (string) $newSession['id'];
            }
        } elseif (jfsd_find_session($sessions, $sessionId) === null) {
            return ['ok' => false, 'msg' => 'That class is not on the calendar, so nobody was added.', 'added' => 0];
        }

        // Who is on this class already. Read once, then kept up to date as we
        // go, so the same id twice in one submission still only adds once.
        $already = [];
        foreach ($attendance as $row) {
            if ((string) ($row['session_id'] ?? '') === $sessionId) {
                $already[(string) ($row['student_id'] ?? '')] = true;
            }
        }

        $names = [];
        foreach ($studentIds as $studentId) {
            $studentId = (string) $studentId;
            if ($studentId === '' || isset($already[$studentId]) || !isset($byId[$studentId])) {
                continue;
            }
            $already[$studentId] = true;
            $attendance[] = [
                'id'         => jfsd_id('att'),
                'session_id' => $sessionId,
                'student_id' => $studentId,
                'counted'    => true,
                'marked_at'  => $now,
                'marked_by'  => $user,
            ];
            // The ONLY place a session moves when somebody is added. A balance
            // at or below zero is not a reason to stop: it goes negative, the
            // person turns up on the list that needs chasing, and nothing here
            // ever blocks.
            $byId[$studentId]['sessions_remaining'] = (int) ($byId[$studentId]['sessions_remaining'] ?? 0) - 1;
            $byId[$studentId]['updated_at']         = $now;
            $names[] = (string) ($byId[$studentId]['name'] ?? 'Somebody');
        }

        if (!$names) {
            // Everybody asked for was already there. Nothing is written at all,
            // not even the class record: an empty record says no more than the
            // weekly pattern already says.
            return ['ok' => true, 'msg' => 'Already on the list, so nothing changed.', 'added' => 0];
        }

        // Rebuild students in original order with the updated rows.
        $updatedStudents = [];
        foreach ($students as $s) {
            $id = (string) ($s['id'] ?? '');
            $updatedStudents[] = $byId[$id] ?? $s;
        }

        // ONE atomic write. Committing attendance first and students second used
        // to leave every row stamped counted:true with no session deducted, a
        // state no amount of re-saving could repair, because the counted flag
        // made the delta a permanent no-op.
        //
        // sessions.json goes FIRST in the set for the same reason at one remove:
        // a class record with nobody against it yet is harmless, while names
        // pointing at a class that was never written are orphans.
        $sets = [];
        if ($newSession !== null) {
            $sets['sessions'] = $sessions;
        }
        $sets['attendance'] = $attendance;
        $sets['students']   = $updatedStudents;

        if (!jfsd_write_all($sets)) {
            return [
                'ok'  => false,
                'msg' => 'Nothing was saved, so please try again. The list and the session balances '
                    . 'are both exactly as they were.',
                'added' => 0,
            ];
        }

        $n = count($names);
        return [
            'ok'    => true,
            'msg'   => jfsd_join_names($names) . ($n === 1 ? ' is' : ' are') . ' on the list. '
                . $n . ' session' . ($n === 1 ? '' : 's') . ' off.',
            'added' => $n,
        ];
    });

    if (!is_array($result)) {
        return [
            'ok'    => false,
            'msg'   => 'The data files are busy or unavailable. Nothing was saved, so please try again.',
            'added' => 0,
        ];
    }
    return $result;
}

/**
 * Take one person off a class's list and give the session back.
 *
 * Idempotent the other way round: somebody who is not on the list is not an
 * error, because the only way to ask for that is to tap twice. Every row that
 * charged gives its session back, so a duplicate that got onto disk some other
 * way is repaired by the same tap rather than stranding a session.
 *
 * @return array{ok:bool,msg:string,removed:int}
 */
function jfsd_remove_attendee(string $sessionId, string $studentId): array
{
    if ($sessionId === '' || $studentId === '') {
        return ['ok' => false, 'msg' => 'That person could not be identified, so nothing changed.', 'removed' => 0];
    }

    $result = jfsd_transaction(static function () use ($sessionId, $studentId): array {
        $attendance = jfsd_read('attendance');
        $students   = jfsd_read('students');
        $byId       = jfsd_index_students($students);
        $now        = jfsd_now_iso();

        $kept     = [];
        $giveBack = 0;
        foreach ($attendance as $row) {
            if ((string) ($row['session_id'] ?? '') === $sessionId
                && (string) ($row['student_id'] ?? '') === $studentId) {
                if (jfsd_row_counted($row)) {
                    $giveBack++;
                }
                continue;
            }
            $kept[] = $row;
        }

        $removed = count($attendance) - count($kept);
        if ($removed === 0) {
            return ['ok' => true, 'msg' => 'Already off the list, so nothing changed.', 'removed' => 0];
        }

        $name = 'That person';
        if (isset($byId[$studentId])) {
            $name = (string) ($byId[$studentId]['name'] ?? $name);
            if ($giveBack > 0) {
                $byId[$studentId]['sessions_remaining'] = (int) ($byId[$studentId]['sessions_remaining'] ?? 0) + $giveBack;
                $byId[$studentId]['updated_at']         = $now;
            }
        }

        $updatedStudents = [];
        foreach ($students as $s) {
            $id = (string) ($s['id'] ?? '');
            $updatedStudents[] = $byId[$id] ?? $s;
        }

        // One atomic write, for the same reason as adding: the row and the
        // balance are one fact and must never land separately.
        if (!jfsd_write_all(['attendance' => $kept, 'students' => $updatedStudents])) {
            return [
                'ok'  => false,
                'msg' => 'Nothing was saved, so please try again. The list and the session balances '
                    . 'are both exactly as they were.',
                'removed' => 0,
            ];
        }

        return [
            'ok'  => true,
            'msg' => $name . ' came off the list'
                . ($giveBack > 0
                    ? ' and got ' . $giveBack . ' session' . ($giveBack === 1 ? '' : 's') . ' back.'
                    : '.'),
            'removed' => $removed,
        ];
    });

    if (!is_array($result)) {
        return [
            'ok'      => false,
            'msg'     => 'The data files are busy or unavailable. Nothing was saved, so please try again.',
            'removed' => 0,
        ];
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

/**
 * Read the Search Console snapshot written by scripts/fetch-gsc-snapshot.mjs.
 *
 * Same home and same protection as the GA4 snapshot: admin/data/gsc-snapshot.json,
 * inside the deploy tree so it ships with the build, denied over HTTP by the
 * *.json rule in admin/.htaccess, read off disk by PHP.
 *
 * DELIBERATELY DIFFERENT FROM jfsd_ga_snapshot() IN ONE WAY: this does not
 * return null when the snapshot is stale. The GA reader collapses "missing" and
 * "stale" into one null because both mean "show nothing", and for traffic
 * figures that is the whole answer. Search queries have a third possibility that
 * matters more than either: the file can be present, fresh, and legitimately
 * empty, because a Search Console property has NO BACKFILL — it counts searches
 * only from the day it was verified, and finalises them two to three days late.
 *
 * So a brand-new connection is genuinely empty for a few days, and telling the
 * studio owner "not connected" or "no data available" during that window would
 * be a lie that sends him chasing a fault that does not exist. Distinguishing
 * the four states is the point of this function; jfsd_gsc_state() names them.
 *
 * Returns null only when the snapshot is missing, unreadable or malformed.
 * A returned array carries '_ageDays' and '_stale'.
 *
 * @return array<string,mixed>|null
 */
function jfsd_gsc_snapshot(): ?array
{
    static $cached = false;
    static $value = null;
    if ($cached) {
        return $value;
    }
    $cached = true;

    $path = __DIR__ . '/data/gsc-snapshot.json';
    if (!is_file($path) || !is_readable($path)) {
        return $value = null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $value = null;
    }
    $data = json_decode($raw, true);

    /* 'topQueries' must be present AND an array. An empty array is a valid,
     * meaningful answer here, so this checks the key exists rather than checking
     * it is non-empty — is_array([]) is true and that is exactly the case this
     * whole function exists to preserve. */
    if (!is_array($data) || !isset($data['topQueries']) || !is_array($data['topQueries'])) {
        return $value = null;
    }

    $generated = isset($data['generatedAt']) ? strtotime((string) $data['generatedAt']) : false;
    if ($generated === false) {
        return $value = null;
    }

    $data['_ageDays'] = (time() - $generated) / 86400;
    $data['_stale']   = $data['_ageDays'] > JFSD_GSC_STALE_DAYS;
    return $value = $data;
}

/**
 * Which of the four things the Search queries panel is currently looking at.
 *
 *   'absent' — no snapshot. Search Console is not connected to this site.
 *              This is the state until somebody does the one-off Google-side
 *              setup; see README section 9.
 *   'stale'  — a snapshot exists but has stopped being refreshed. Its numbers
 *              are NOT shown: a stale figure looks identical to a fresh one,
 *              which is how a frozen snapshot sat unnoticed on two sibling
 *              sites for three weeks.
 *   'empty'  — connected, fetched successfully, and Google returned nothing.
 *              NOT AN ERROR. Normal for the first days of a new property, and
 *              normal forever for a site nobody has searched for yet.
 *   'live'   — connected, fresh, with rows.
 *
 * The 'absent' vs 'empty' distinction is the whole reason the fetcher refuses to
 * write a file on failure. File present == a real API call succeeded. If that
 * ever stops being true, this function starts lying.
 */
function jfsd_gsc_state(): string
{
    $snap = jfsd_gsc_snapshot();
    if ($snap === null) {
        return 'absent';
    }
    if (!empty($snap['_stale'])) {
        return 'stale';
    }
    return ($snap['topQueries'] ?? []) === [] ? 'empty' : 'live';
}

/** Human-readable age of the Search Console snapshot, for the panel footnote. */
function jfsd_gsc_updated_label(): string
{
    $snap = jfsd_gsc_snapshot();
    if ($snap === null) {
        return 'never';
    }
    $age = (float) ($snap['_ageDays'] ?? 0);
    if ($age < 1)  { return 'today'; }
    if ($age < 2)  { return 'yesterday'; }
    return (int) floor($age) . ' days ago';
}

/**
 * The window the snapshot actually covers, as "26 Jun – 23 Jul".
 *
 * Read from the file rather than hard-coded in the panel, because the fetcher
 * ends its window three days back to stay inside Search Console's finalisation
 * lag. A panel headline saying "last 28 days" while the data quietly stops on
 * Tuesday is the kind of small untruth that costs an afternoon later.
 */
function jfsd_gsc_range_label(): string
{
    $snap = jfsd_gsc_snapshot();
    $from = (string) ($snap['range']['startDate'] ?? '');
    $to   = (string) ($snap['range']['endDate'] ?? '');
    if ($from === '' || $to === '') {
        return '';
    }
    /* Timezone read the long way rather than through jfsd_tz(), which lives in
     * _ui.php. Everything else in this file does the same: _store.php is
     * included by pages that do not always pull in _ui.php, and a helper that
     * only works on some of them is a fatal waiting for the one page nobody
     * tested. See jfsd_now_iso() / jfsd_today() directly above. */
    $tz = new DateTimeZone((string) (jfsd_config()['timezone'] ?? 'Asia/Singapore'));
    $fmt = static function (string $ymd) use ($tz): string {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, $tz);
        return $d === false ? $ymd : $d->format('j M');
    };
    return $fmt($from) . ' – ' . $fmt($to);
}
