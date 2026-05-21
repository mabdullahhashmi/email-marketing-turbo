<?php
/**
 * API: Bulk Import SMTP Accounts from CSV
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireAuth();
validateCSRF($_POST['csrf_token'] ?? '');

$mappingsJson = $_POST['mappings'] ?? '{}';

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'No file uploaded or upload error.'], 400);
}

$file = $_FILES['file'];
if ($file['size'] > MAX_CSV_SIZE) {
    jsonResponse(['success' => false, 'message' => 'File too large. Maximum is ' . (MAX_CSV_SIZE / 1024 / 1024) . 'MB.'], 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'])) {
    jsonResponse(['success' => false, 'message' => 'Only CSV and TXT files are allowed.'], 400);
}

function normalizeSmtpBool($value) {
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on', 'seed'], true) ? 1 : 0;
}

try {
    $mappings = json_decode($mappingsJson, true);
    if (!$mappings) {
        jsonResponse(['success' => false, 'message' => 'Invalid column mappings.'], 400);
    }

    $csvData = parseCSV($file['tmp_name']);
    if (empty($csvData['headers']) || empty($csvData['rows'])) {
        jsonResponse(['success' => false, 'message' => 'CSV file is empty or has no data rows.'], 400);
    }

    $mappedColumns = [];
    foreach ($mappings as $colIndex => $mapping) {
        $field = $mapping['field'] ?? 'skip';
        if ($field !== 'skip') {
            $mappedColumns[(int) $colIndex] = $field;
        }
    }

    if (!in_array('smtp_host', $mappedColumns, true) || !in_array('smtp_username', $mappedColumns, true)) {
        jsonResponse(['success' => false, 'message' => 'Please map at least SMTP Host and SMTP Username columns.'], 400);
    }

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    $pdo = getDB();
    $pdo->beginTransaction();

    try {
        foreach ($csvData['rows'] as $rowNumber => $row) {
            $rowData = [];
            foreach ($mappings as $colIndex => $mapping) {
                $field = $mapping['field'] ?? 'skip';
                if ($field === 'skip') {
                    continue;
                }
                $rowData[$field] = trim($row[(int) $colIndex] ?? '');
            }

            $host = $rowData['smtp_host'] ?? '';
            $username = $rowData['smtp_username'] ?? '';
            $password = $rowData['smtp_password'] ?? '';

            if (!$host || !$username) {
                $skipped++;
                $errors[] = 'Row ' . ($rowNumber + 2) . ': SMTP host and username are required.';
                continue;
            }

            $label = $rowData['label'] ?? '';
            $fromEmail = $rowData['from_email'] ?? '';
            $fromName = $rowData['from_name'] ?? '';

            if (!$fromEmail) {
                $fromEmail = $label ?: $username;
            }
            if (!$label) {
                $label = $fromEmail ?: $username;
            }
            if (!$fromName) {
                $fromName = $label;
            }

            if (!$password) {
                $skipped++;
                $errors[] = 'Row ' . ($rowNumber + 2) . ': Password is required.';
                continue;
            }

            $port = (int) ($rowData['smtp_port'] ?? 465);
            if (!$port) {
                $port = 465;
            }
            $encryption = strtolower(trim($rowData['smtp_encryption'] ?? 'ssl'));
            if (!in_array($encryption, ['ssl', 'tls'], true)) {
                $encryption = 'ssl';
            }

            $imapHost = trim($rowData['imap_host'] ?? '');
            $imapPort = (int) ($rowData['imap_port'] ?? 993);
            if (!$imapPort) {
                $imapPort = 993;
            }
            $imapEncryption = strtolower(trim($rowData['imap_encryption'] ?? 'ssl'));
            if (!in_array($imapEncryption, ['ssl', 'tls', ''], true)) {
                $imapEncryption = 'ssl';
            }
            $imapUsername = trim($rowData['imap_username'] ?? '');
            $imapPassword = trim($rowData['imap_password'] ?? '');
            $isSeedAccount = normalizeSmtpBool($rowData['is_seed_account'] ?? 0);
            $dailyLimit = isset($rowData['daily_limit']) && trim((string) $rowData['daily_limit']) !== ''
                ? (int) $rowData['daily_limit']
                : 100;

            $newImapPassword = $imapPassword ? encryptString($imapPassword) : null;
            dbInsert(
                "INSERT INTO smtp_accounts (label, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, from_name, from_email, daily_limit, imap_host, imap_port, imap_encryption, imap_username, imap_password, is_seed_account, warmup_status, warmup_current_day, warmup_target_daily, sent_today, last_reset_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $label,
                    $host,
                    $port,
                    $encryption,
                    $username,
                    encryptString($password),
                    $fromName,
                    $fromEmail,
                    $dailyLimit,
                    $imapHost ?: null,
                    $imapPort,
                    $imapEncryption,
                    $imapUsername ?: null,
                    $newImapPassword,
                    $isSeedAccount,
                    $imapHost ? 'active' : 'idle',
                    $imapHost ? 1 : 0,
                    $imapHost ? 2 : 0,
                    0,
                    $imapHost ? date('Y-m-d') : null,
                ]
            );
            $created++;
        }

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'message' => 'Bulk SMTP import completed.',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Import error: ' . $e->getMessage()], 500);
}
