<?php
/**
 * Aafina Technologies — Biptap enquiry handler
 *
 * 1. Validates the enquiry form on digital-banking.html
 * 2. Emails a notification to Aafina
 * 3. Saves a backup copy (CSV) outside the public web folder
 * 4. Returns the Biptap referral link so the visitor can continue there
 */

// ---------- Settings ----------
const NOTIFY_TO     = 'admin@skilligen.com';                     // where notifications go
const MAIL_FROM     = 'Aafina Website <noreply@aafinatech.com>'; // sender shown on notifications
const REFERRAL_URL  = 'https://buildabank.io/?ref=UQ9TgyEBAvPP'; // Aafina's Biptap referral link
const MIN_SECONDS   = 3;                                         // bots submit faster than this

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

// Only accept posts from our own site
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = strtolower($_SERVER['HTTP_HOST'] ?? '');
if ($origin !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '')) !== $host) {
    respond(403, ['ok' => false, 'error' => 'Forbidden']);
}

function field(string $key, int $max = 200): string {
    $v = trim((string)($_POST[$key] ?? ''));
    $v = str_replace(["\r", "\0"], '', $v);
    return mb_substr($v, 0, $max);
}
function one_line(string $v): string {
    return trim(preg_replace('/[\n\t]+/', ' ', $v));
}

// ---------- Spam checks ----------
if (field('website') !== '') {                  // honeypot: real people never fill this
    respond(200, ['ok' => true, 'redirect' => REFERRAL_URL]);
}
$started = (int)field('started', 20);
if ($started > 0 && (time() - intdiv($started, 1000)) < MIN_SECONDS) {
    respond(200, ['ok' => true, 'redirect' => REFERRAL_URL]);
}

// ---------- Collect & validate ----------
$data = [
    'Enquiring as'   => one_line(field('as', 20)),
    'Name'           => one_line(field('name', 120)),
    'Company'        => one_line(field('company', 160)),
    'Email'          => one_line(field('email', 200)),
    'Phone'          => one_line(field('phone', 40)),
    'Country'        => one_line(field('country', 80)),
    'Business type'  => one_line(field('type', 80)),
    'Interested in'  => one_line(field('interests', 300)),
    'Launch option'  => one_line(field('option', 60)),
    'Message'        => field('message', 3000),
];

if ($data['Enquiring as'] === 'Individual') {      // business-only fields don't apply
    $data['Company'] = $data['Business type'] = $data['Launch option'] = '';
}

$errors = [];
if ($data['Name'] === '')                                    $errors[] = 'name';
if (!filter_var($data['Email'], FILTER_VALIDATE_EMAIL))      $errors[] = 'email';
if ($data['Country'] === '')                                 $errors[] = 'country';
if (!in_array($data['Enquiring as'], ['Business', 'Individual'], true)) $errors[] = 'as';
if ($data['Enquiring as'] === 'Business' && $data['Company'] === '')  $errors[] = 'company';
if (field('consent', 5) !== 'yes')                           $errors[] = 'consent';
if ($errors) {
    respond(422, ['ok' => false, 'error' => 'Please complete the required fields.', 'fields' => $errors]);
}

$when = gmdate('Y-m-d H:i:s') . ' UTC';
$ip   = $_SERVER['REMOTE_ADDR'] ?? '';
$page = one_line(field('page', 200));

// ---------- 1. Email notification ----------
$who     = $data['Enquiring as'] === 'Business' ? $data['Company'] : $data['Name'];
$subject = 'New Biptap enquiry (' . $data['Enquiring as'] . '): ' . $who;
$lines   = ["A new enquiry was submitted on aafinatech.com.", "The visitor was then sent to Aafina's Biptap referral link.", ""];
foreach ($data as $k => $v) {
    if ($v !== '') $lines[] = str_pad($k . ':', 16) . $v;
}
$lines[] = '';
$lines[] = 'Submitted: ' . $when;
$lines[] = 'Page: ' . $page;
$lines[] = 'Referral link: ' . REFERRAL_URL;
$body = implode("\n", $lines);

$replyTo = filter_var($data['Email'], FILTER_VALIDATE_EMAIL) ? $data['Email'] : NOTIFY_TO;
$headers = implode("\r\n", [
    'From: ' . MAIL_FROM,
    'Reply-To: ' . $replyTo,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: Aafina-Website',
]);
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$mailed = @mail(NOTIFY_TO, $encodedSubject, $body, $headers, '-fnoreply@aafinatech.com');

// ---------- 2. Backup copy (outside public_html) ----------
$saved = false;
$dir = dirname(__DIR__) . '/aafina-enquiries';
if (is_dir($dir) || @mkdir($dir, 0750, true)) {
    $file  = $dir . '/biptap-enquiries.csv';
    $isNew = !file_exists($file);
    if ($fh = @fopen($file, 'a')) {
        if (flock($fh, LOCK_EX)) {
            if ($isNew) fputcsv($fh, array_merge(['Submitted'], array_keys($data), ['IP', 'Emailed']));
            $row = array_map(fn($v) => preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, array_values($data));
            fputcsv($fh, array_merge([$when], $row, [$ip, $mailed ? 'yes' : 'no']));
            flock($fh, LOCK_UN);
            $saved = true;
        }
        fclose($fh);
    }
}

if (!$mailed && !$saved) {
    respond(500, ['ok' => false, 'error' => 'We could not record your enquiry. Please email ' . NOTIFY_TO . '.', 'redirect' => REFERRAL_URL]);
}

// ---------- 3. Continue to Biptap ----------
respond(200, ['ok' => true, 'redirect' => REFERRAL_URL]);
