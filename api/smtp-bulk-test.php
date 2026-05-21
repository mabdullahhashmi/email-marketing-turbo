<?php
/**
 * API: Bulk Test SMTP Connections
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/smtp-test-helper.php';

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

$ids = array_values(array_unique(array_filter(array_map('intval', $input['ids'] ?? []), fn($id) => $id > 0)));
if (!$ids) {
    jsonResponse(['success' => false, 'message' => 'Please select at least one SMTP account.'], 400);
}

$results = [];
$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($ids as $id) {
    $account = dbFetchOne("SELECT * FROM smtp_accounts WHERE id = ?", [$id]);
    if (!$account) {
        $results[] = [
            'id' => $id,
            'status' => 'skipped',
            'success' => false,
            'message' => 'Account not found',
            'runtime_status_saved' => false,
        ];
        $skipped++;
        continue;
    }

    if (empty($account['smtp_host']) || empty($account['smtp_port']) || empty($account['smtp_username']) || empty($account['smtp_password']) || empty($account['from_email'])) {
        $message = 'Missing SMTP settings';
        $runtimeSaved = smtpTestStoreRuntimeStatus($id, 'failed', $message);
        $results[] = [
            'id' => $id,
            'label' => $account['label'],
            'from_email' => $account['from_email'],
            'status' => 'skipped',
            'success' => false,
            'message' => $message,
            'runtime_status_saved' => $runtimeSaved,
        ];
        $skipped++;
        continue;
    }

    try {
        $result = smtpTestRun($account, $account['from_email']);
        $runtimeSaved = smtpTestStoreRuntimeStatus($id, 'passed', $result['message']);
        $results[] = [
            'id' => $id,
            'label' => $account['label'],
            'from_email' => $account['from_email'],
            'status' => 'passed',
            'success' => true,
            'message' => $result['message'],
            'duration_ms' => $result['duration_ms'],
            'runtime_status_saved' => $runtimeSaved,
        ];
        $passed++;
    } catch (Exception $e) {
        $message = $e->getMessage();
        $runtimeSaved = smtpTestStoreRuntimeStatus($id, 'failed', $message);
        $results[] = [
            'id' => $id,
            'label' => $account['label'],
            'from_email' => $account['from_email'],
            'status' => 'failed',
            'success' => false,
            'message' => $message,
            'runtime_status_saved' => $runtimeSaved,
        ];
        $failed++;
    }
}

jsonResponse([
    'success' => true,
    'message' => 'Bulk SMTP test completed.',
    'passed' => $passed,
    'failed' => $failed,
    'skipped' => $skipped,
    'results' => $results,
]);
