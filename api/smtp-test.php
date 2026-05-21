<?php
/**
 * API: Test SMTP Connection / Send Test Email
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

try {
    // If account ID provided, load from database
    if (!empty($input['id'])) {
        $account = dbFetchOne("SELECT * FROM smtp_accounts WHERE id = ?", [(int)$input['id']]);
        if (!$account) {
            jsonResponse(['success' => false, 'message' => 'Account not found'], 404);
        }
    } else {
        // Use provided SMTP details
        $smtpId = (int)($input['smtp_account_id'] ?? 0);
        if ($smtpId) {
            $account = dbFetchOne("SELECT * FROM smtp_accounts WHERE id = ?", [$smtpId]);
            if (!$account) {
                jsonResponse(['success' => false, 'message' => 'SMTP account not found'], 404);
            }
        } else {
            jsonResponse(['success' => false, 'message' => 'No SMTP account specified'], 400);
        }
    }

    $subject = $input['subject'] ?? 'Test Email from ' . APP_NAME;
    $body = $input['body_html'] ?? '<h2>Test Email</h2><p>This is a test email sent from ' . APP_NAME . '.</p><p>If you received this, your SMTP configuration is working correctly! ✅</p>';
    $toEmail = $input['to_email'] ?? $account['from_email'];

    $result = smtpTestRun($account, $toEmail, $subject, $body);
    $runtimeSaved = smtpTestStoreRuntimeStatus($account['id'], 'passed', $result['message']);

    jsonResponse([
        'success' => true,
        'message' => $result['message'],
        'to_email' => $result['to_email'],
        'duration_ms' => $result['duration_ms'],
        'runtime_status' => 'passed',
        'runtime_status_saved' => $runtimeSaved,
        'tested_at' => date('Y-m-d H:i:s'),
    ]);

} catch (Exception $e) {
    if (!empty($account['id'])) {
        $runtimeSaved = smtpTestStoreRuntimeStatus($account['id'], 'failed', $e->getMessage());
    }
    jsonResponse(['success' => false, 'message' => 'SMTP Error: ' . $e->getMessage(), 'runtime_status_saved' => $runtimeSaved ?? false]);
} catch (\Exception $e) {
    if (!empty($account['id'])) {
        $runtimeSaved = smtpTestStoreRuntimeStatus($account['id'], 'failed', $e->getMessage());
    }
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'runtime_status_saved' => $runtimeSaved ?? false]);
}
