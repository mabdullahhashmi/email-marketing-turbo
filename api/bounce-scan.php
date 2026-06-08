<?php
/**
 * API: Scan configured IMAP inboxes for delayed bounce notifications.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bounce-helper.php';

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

$limit = (int)($input['limit'] ?? 80);
$limit = max(10, min(300, $limit));
$accountId = (int)($input['smtp_account_id'] ?? 0);

try {
    $summary = bounceScanConfiguredMailboxes($limit, $accountId);
    $message = "Scanned {$summary['accounts']} account(s), found {$summary['scanned']} bounce notice(s), recorded {$summary['recorded']} new bounce(s).";

    jsonResponse([
        'success' => true,
        'message' => $message,
        'summary' => $summary,
    ]);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
