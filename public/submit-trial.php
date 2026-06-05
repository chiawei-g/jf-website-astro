<?php
// JF Self Defense trial enquiry handler.
// Receives JSON or form-encoded POST from site.js and emails the form data.
// TEST MODE: routing to chiawei.g@dustinhill.com.sg.
// SWITCH BEFORE GO-LIVE: set $TO = 'Jeffrey.f@jfselfdefense.com'.

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Allow same-origin and lightskyblue-camel-545209.hostingersite.com previews.
$allowedOrigins = [
    'https://lightskyblue-camel-545209.hostingersite.com',
    'https://www.jfselfdefense.com',
    'https://jfselfdefense.com',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse either JSON body or classic form-encoded payload.
$raw = file_get_contents('php://input') ?: '';
$input = [];
if ($raw && str_starts_with(trim($raw), '{')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if (!$input) {
    $input = $_POST;
}

$get = static function (string $k) use ($input): string {
    $v = $input[$k] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return trim($v);
};

$name      = $get('name');
$email     = $get('email');
$phone     = $get('phone');
$programme = $get('programme');
$when      = $get('when');
$message   = $get('message');
$honeypot  = $get('_hp');

// Honeypot — silently accept bot submissions so they think they worked.
if ($honeypot !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

// Required-field validation.
$errors = [];
if ($name === '')                                   { $errors[] = 'name'; }
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'email'; }
if ($phone === '')                                  { $errors[] = 'phone'; }

if ($errors) {
    http_response_code(400);
    echo json_encode(['error' => 'Required fields missing', 'fields' => $errors]);
    exit;
}

// Strip line-injection attempts from header-targeted fields.
$nameClean  = preg_replace('/[\r\n]+/', ' ', $name) ?? '';
$emailClean = preg_replace('/[\r\n]+/', '', $email) ?? '';
$progClean  = preg_replace('/[\r\n]+/', ' ', $programme) ?? '';

$TO      = 'chiawei.g@dustinhill.com.sg';
$subject = '[JF Self Defense] Trial booking — ' . ($progClean !== '' ? $progClean : 'general');

$bodyLines = [
    'New trial booking enquiry — JF Self Defense',
    str_repeat('=', 44),
    '',
    'Name:       ' . $nameClean,
    'Email:      ' . $emailClean,
    'Phone:      ' . $phone,
    'Programme:  ' . ($progClean !== '' ? $progClean : '(not selected)'),
];
if ($when !== '') {
    $bodyLines[] = 'Best times: ' . $when;
}
if ($message !== '') {
    $bodyLines[] = '';
    $bodyLines[] = 'Notes:';
    $bodyLines[] = $message;
}
$bodyLines[] = '';
$bodyLines[] = str_repeat('-', 44);
$bodyLines[] = 'Submitted: ' . gmdate('Y-m-d H:i:s') . ' UTC';
$bodyLines[] = 'Source IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$bodyLines[] = 'Source URL: ' . ($_SERVER['HTTP_REFERER'] ?? 'direct');
$bodyLines[] = '';
$bodyLines[] = 'Reply directly to this email to respond to ' . $nameClean . '.';

$body = implode("\r\n", $bodyLines);

$host = $_SERVER['HTTP_HOST'] ?? 'jfselfdefense.com';
$fromAddress = 'noreply@' . $host;

$headers = [
    'From: JF Self Defense <' . $fromAddress . '>',
    'Reply-To: ' . $nameClean . ' <' . $emailClean . '>',
    'X-Mailer: JF-Self-Defense-Site',
    'Content-Type: text/plain; charset=UTF-8',
    'MIME-Version: 1.0',
];

$sent = @mail($TO, $subject, $body, implode("\r\n", $headers), '-f' . $fromAddress);

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Mail send failed',
        'hint'  => 'PHP mail() returned false — check Hostinger mail logs',
    ]);
}
