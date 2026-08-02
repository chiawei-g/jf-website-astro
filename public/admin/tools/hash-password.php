<?php
declare(strict_types=1);

/**
 * JF Self Defense admin — password hash generator.
 *
 * COMMAND LINE ONLY. Refuses to run over HTTP, and /admin/tools/ is 403'd by
 * admin/.htaccess as well.
 *
 * Usage:
 *   php tools/hash-password.php 'the new password'
 *   php tools/hash-password.php 'the new password' 'someone@example.com'
 *   php tools/hash-password.php            (prompts, does not echo the password)
 *
 * The email is optional and only affects the line this prints for you to paste.
 * Give it when generating a hash for anyone other than Jeffrey, so you do not
 * have to hand-edit the address afterwards and risk a typo in the one field
 * that has to match exactly at sign-in.
 *
 * Copy the printed line into admin/users.php, replacing the existing hash for
 * that email, then redeploy.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$password = $argv[1] ?? null;
// Lowercased because login lowercases the submitted address before lookup, so a
// capitalised key here could never be matched.
$email = strtolower(trim((string) ($argv[2] ?? 'Jeffrey.f@jfselfdefense.com')));

if ($password === null) {
    fwrite(STDERR, "New password: ");
    // Try to suppress echo so the password does not stay on screen or in
    // scrollback. Falls back to a visible prompt where stty is unavailable.
    $hidden = false;
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
        $hidden = true;
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($hidden) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }
}

if ($password === '') {
    fwrite(STDERR, "No password given. Nothing to do.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Refusing: use at least 12 characters. This is the only lock on the whole admin.\n");
    exit(1);
}
if (strlen($password) > 72) {
    // bcrypt silently ignores everything past 72 bytes. Better to say so.
    fwrite(STDERR, "Refusing: bcrypt ignores anything past 72 bytes, so a longer password is misleading.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
if (!is_string($hash) || $hash === '') {
    fwrite(STDERR, "password_hash() failed.\n");
    exit(1);
}

if ($email === '' || strpos($email, '@') === false) {
    fwrite(STDERR, "Refusing: '" . $email . "' is not an email address.\n");
    exit(1);
}

echo "\n";
echo "Paste this line into admin/users.php (keep the single quotes):\n\n";
echo "    '" . $email . "' => '" . $hash . "',\n\n";
echo "Then redeploy. The old password stops working immediately after deploy.\n";
echo "\nThe hash above is safe to send to someone. The password is not.\n";
