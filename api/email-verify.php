<?php
/**
 * API: Verify a single email or a bulk list of emails.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email-verifier-helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
}

validateCSRF($input['csrf_token'] ?? '');
emailVerifierEnsureTable();

$smtpCheck = !empty($input['smtp_check']);
$emails = [];

if (!empty($input['email'])) {
    $emails[] = emailVerifierNormalize($input['email']);
}

if (!empty($input['emails']) && is_array($input['emails'])) {
    foreach ($input['emails'] as $email) {
        $emails[] = emailVerifierNormalize($email);
    }
}

if (!empty($input['email_text'])) {
    $emails = array_merge($emails, emailVerifierExtractEmails($input['email_text']));
}

$emails = array_values(array_unique(array_filter($emails)));
if (empty($emails)) {
    jsonResponse(['success' => false, 'message' => 'No emails found to verify.'], 400);
}

$maxEmails = $smtpCheck ? 100 : 500;
if (count($emails) > $maxEmails) {
    jsonResponse([
        'success' => false,
        'message' => "Too many emails. Limit is {$maxEmails} per request" . ($smtpCheck ? ' when SMTP probe is enabled.' : '.'),
    ], 400);
}

$results = [];
$summary = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'risky' => 0, 'unknown' => 0];
$probeSender = emailVerifierDefaultProbeSender();

foreach ($emails as $email) {
    $result = emailVerifierVerify($email, [
        'smtp_check' => $smtpCheck,
        'probe_sender' => $probeSender,
        'timeout' => 6,
    ]);
    emailVerifierSaveResult($result);

    $summary['total']++;
    if (isset($summary[$result['status']])) {
        $summary[$result['status']]++;
    }

    $results[] = $result;
}

jsonResponse([
    'success' => true,
    'mode' => count($results) === 1 ? 'single' : 'bulk',
    'smtp_check' => $smtpCheck,
    'summary' => $summary,
    'results' => $results,
]);
