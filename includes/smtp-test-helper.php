<?php
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

function smtpTestRun($account, $toEmail = null, $subject = null, $body = null) {
    $startedAt = microtime(true);

    $host = $account['smtp_host'];
    $port = (int) $account['smtp_port'];
    $encryption = $account['smtp_encryption'];
    $username = $account['smtp_username'];
    $password = decryptString($account['smtp_password']);
    $fromName = $account['from_name'];
    $fromEmail = $account['from_email'];
    $recipient = $toEmail ?: $fromEmail;

    $subject = $subject ?: 'Test Email from ' . APP_NAME;
    $body = $body ?: '<h2>Test Email</h2><p>This is a test email sent from ' . APP_NAME . '.</p><p>If you received this, your SMTP configuration is working correctly! ✅</p>';

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = $encryption === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $port;
    // Use a conservative per-connection timeout to keep bulk runs responsive.
    $mail->Timeout = 8;

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($recipient);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $body;
    $mail->AltBody = strip_tags($body);
    $mail->CharSet = 'UTF-8';

    $mail->send();

    return [
        'success' => true,
        'message' => 'Email sent successfully to ' . $recipient,
        'to_email' => $recipient,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ];
}

function smtpTestStoreRuntimeStatus($accountId, $status, $message) {
    try {
        dbExecute(
            "UPDATE smtp_accounts SET last_test_status = ?, last_test_message = ?, last_tested_at = NOW() WHERE id = ?",
            [$status, $message, $accountId]
        );
        return true;
    } catch (Exception $e) {
        // Log the failure to help diagnose missing DB permissions or other issues.
        try {
            $logDir = __DIR__ . '/../storage/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
            $logFile = $logDir . '/smtp-test-persist.log';
            @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Initial update failed for account {$accountId}: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        } catch (Exception $_) {
            // swallow logging errors
        }

        // If update failed because the columns don't exist, attempt to add them now and retry once.
        $msg = $e->getMessage();
        if (stripos($msg, 'Unknown column') !== false || stripos($msg, "doesn't exist") !== false || stripos($msg, 'column') !== false) {
            try {
                dbExecute(
                    "ALTER TABLE `smtp_accounts` \
                        ADD COLUMN `last_test_status` ENUM('untested','passed','failed') NOT NULL DEFAULT 'untested', \
                        ADD COLUMN `last_test_message` TEXT DEFAULT NULL, \
                        ADD COLUMN `last_tested_at` DATETIME DEFAULT NULL"
                );

                // Retry the update once.
                dbExecute(
                    "UPDATE smtp_accounts SET last_test_status = ?, last_test_message = ?, last_tested_at = NOW() WHERE id = ?",
                    [$status, $message, $accountId]
                );
                return true;
            } catch (Exception $e2) {
                // If the alter/update fails, log and give up gracefully.
                try {
                    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Auto-migration failed for account {$accountId}: " . $e2->getMessage() . PHP_EOL, FILE_APPEND);
                } catch (Exception $_) {}
                return false;
            }
        }

        return false;
    }
}
