<?php
/**
 * Cron Job: Scan IMAP inboxes for delayed bounce notifications.
 *
 * Usage (cron): run every 15 minutes: php /path/to/cron/scan-bounces.php secret=YOUR_CRON_SECRET
 * Usage (web):  https://yourdomain.com/email-tool/cron/scan-bounces.php?secret=YOUR_CRON_SECRET
 */

set_time_limit(180);
ignore_user_abort(true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bounce-helper.php';

if (php_sapi_name() !== 'cli') {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== CRON_SECRET) {
        http_response_code(403);
        die('Unauthorized');
    }
}

$logFile = __DIR__ . '/../logs/bounce-scan.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function bounceScanLog($message) {
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}

$limit = (int)($_GET['limit'] ?? 120);
$limit = max(10, min(300, $limit));

bounceScanLog('=== Bounce scan started ===');
$summary = bounceScanConfiguredMailboxes($limit);
bounceScanLog("Accounts: {$summary['accounts']}; DSNs: {$summary['scanned']}; recorded: {$summary['recorded']}; suppressed: {$summary['suppressed']}");
foreach ($summary['errors'] as $error) {
    bounceScanLog('Error: ' . $error);
}
bounceScanLog("=== Bounce scan finished ===\n");

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'summary' => $summary]);
}
