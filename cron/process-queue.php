<?php
/**
 * Cron Job: Process Email Queue
 * 
 * This script should be run every minute via cron job.
 * It picks pending emails from the queue and sends them via SMTP.
 * 
 * Usage (cron): * * * * * php /path/to/cron/process-queue.php secret=YOUR_CRON_SECRET
 * Usage (web):  https://yourdomain.com/email-tool/cron/process-queue.php?secret=YOUR_CRON_SECRET
 */

// Set execution time limit
set_time_limit(120);
ignore_user_abort(true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Verify cron secret
$secret = '';
if (php_sapi_name() === 'cli') {
    // CLI mode: parse from argv
    foreach ($argv ?? [] as $arg) {
        if (strpos($arg, 'secret=') === 0) {
            $secret = substr($arg, 7);
        }
    }
} else {
    // Web mode: parse from GET
    $secret = $_GET['secret'] ?? '';
}

if ($secret !== CRON_SECRET) {
    http_response_code(403);
    die('Unauthorized');
}

$logFile = __DIR__ . '/../logs/cron.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function cronLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

cronLog("=== Cron started ===");

// Reset daily counters if needed
$today = date('Y-m-d');
dbExecute("UPDATE smtp_accounts SET sent_today = 0 WHERE last_reset_date IS NULL OR last_reset_date < ?", [$today]);
dbExecute("UPDATE smtp_accounts SET last_reset_date = ? WHERE last_reset_date IS NULL OR last_reset_date < ?", [$today, $today]);

// Update campaign statuses: scheduled → sending (if scheduled_at has passed)
dbExecute("UPDATE campaigns SET status = 'sending' WHERE status = 'scheduled' AND scheduled_at <= NOW()");

// Get pending emails that are due
$pendingEmails = dbFetchAll("
    SELECT eq.*, c.status as campaign_status
    FROM email_queue eq
    JOIN campaigns c ON eq.campaign_id = c.id
    WHERE eq.status = 'pending' 
      AND eq.scheduled_at <= NOW()
      AND c.status IN ('sending', 'scheduled')
      AND eq.attempts < ?
    ORDER BY eq.scheduled_at ASC
    LIMIT ?
", [MAX_RETRY_ATTEMPTS, QUEUE_BATCH_SIZE]);

if (empty($pendingEmails)) {
    cronLog("No pending emails to process.");
    
    // Check for campaigns that should be marked complete
    $sendingCampaigns = dbFetchAll("SELECT id FROM campaigns WHERE status = 'sending'");
    foreach ($sendingCampaigns as $sc) {
        $remaining = getCount('email_queue', 'campaign_id = ? AND status = ?', [$sc['id'], 'pending']);
        if ($remaining === 0) {
            dbExecute("UPDATE campaigns SET status = 'completed', updated_at = NOW() WHERE id = ?", [$sc['id']]);
            cronLog("Campaign #{$sc['id']} marked as completed.");
        }
    }
    
    cronLog("=== Cron finished ===\n");
    exit;
}

cronLog("Found " . count($pendingEmails) . " emails to process.");

// Group by SMTP account for connection reuse
$smtpConnections = [];

foreach ($pendingEmails as $email) {
    $smtpId = $email['smtp_account_id'];
    
    try {
        // Mark as sending
        dbExecute("UPDATE email_queue SET status = 'sending', attempts = attempts + 1 WHERE id = ?", [$email['id']]);
        
        // Load SMTP account
        if (!isset($smtpConnections[$smtpId])) {
            $account = dbFetchOne("SELECT * FROM smtp_accounts WHERE id = ? AND is_active = 1", [$smtpId]);
            if (!$account) {
                throw new \Exception("SMTP account #{$smtpId} not found or disabled");
            }
            
            // Check daily limit
            if ($account['daily_limit'] > 0 && $account['sent_today'] >= $account['daily_limit']) {
                cronLog("SMTP #{$smtpId} daily limit reached ({$account['sent_today']}/{$account['daily_limit']}). Skipping.");
                dbExecute("UPDATE email_queue SET status = 'pending' WHERE id = ?", [$email['id']]);
                continue;
            }
            
            $smtpConnections[$smtpId] = $account;
        }
        
        $account = $smtpConnections[$smtpId];
        
        // Re-check daily limit
        if ($account['daily_limit'] > 0 && $account['sent_today'] >= $account['daily_limit']) {
            dbExecute("UPDATE email_queue SET status = 'pending' WHERE id = ?", [$email['id']]);
            continue;
        }
        
        // Create PHPMailer instance
        $mail = new PHPMailer(true);
        
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = $account['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $account['smtp_username'];
        $mail->Password = decryptString($account['smtp_password']);
        $mail->SMTPSecure = $account['smtp_encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = (int)$account['smtp_port'];
        $mail->Timeout = 30;
        $mail->CharSet = 'UTF-8';
        
        // From
        $mail->setFrom($account['from_email'], $account['from_name']);
        
        // To
        $mail->addAddress($email['to_email'], $email['to_name']);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $email['subject'];
        
        $bodyHtml = $email['body_html'];
        
        // Process click tracking - replace URLs with tracking redirects
        $bodyHtml = processClickTracking($bodyHtml, $email['campaign_id'], $email['contact_id'], $email['id']);
        
        // Embed images as CID inline attachments
        $images = getEmbeddedImages($bodyHtml);
        foreach ($images as $img) {
            $mail->addEmbeddedImage($img['path'], $img['cid'], $img['name']);
        }
        $bodyHtml = replaceImagesWithCID($bodyHtml, $images);
        
        $mail->Body = $bodyHtml;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml));
        
        // Send
        $mail->send();
        
        // Success!
        dbExecute("UPDATE email_queue SET status = 'sent', sent_at = NOW(), error_message = NULL WHERE id = ?", [$email['id']]);
        
        // Update campaign sent count
        dbExecute("UPDATE campaigns SET sent_count = sent_count + 1, updated_at = NOW() WHERE id = ?", [$email['campaign_id']]);
        
        // Update SMTP daily counter
        dbExecute("UPDATE smtp_accounts SET sent_today = sent_today + 1 WHERE id = ?", [$smtpId]);
        $smtpConnections[$smtpId]['sent_today']++;
        
        cronLog("✓ Sent to {$email['to_email']} (Queue #{$email['id']}, Campaign #{$email['campaign_id']})");
        
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        cronLog("✕ Failed {$email['to_email']}: {$errorMsg}");
        
        // Check if max retries reached
        $attempts = (int) dbFetchValue("SELECT attempts FROM email_queue WHERE id = ?", [$email['id']]);
        
        if ($attempts >= MAX_RETRY_ATTEMPTS) {
            dbExecute("UPDATE email_queue SET status = 'failed', error_message = ? WHERE id = ?", [$errorMsg, $email['id']]);
            dbExecute("UPDATE campaigns SET failed_count = failed_count + 1, updated_at = NOW() WHERE id = ?", [$email['campaign_id']]);
            cronLog("  → Max retries reached. Marked as failed.");
        } else {
            dbExecute("UPDATE email_queue SET status = 'pending', error_message = ? WHERE id = ?", [$errorMsg, $email['id']]);
            cronLog("  → Will retry (attempt {$attempts}/" . MAX_RETRY_ATTEMPTS . ")");
        }
    } catch (\Exception $e) {
        $errorMsg = $e->getMessage();
        cronLog("✕ General error for {$email['to_email']}: {$errorMsg}");
        dbExecute("UPDATE email_queue SET status = 'pending', error_message = ? WHERE id = ?", [$errorMsg, $email['id']]);
    }
}

// Check for completed campaigns
$sendingCampaigns = dbFetchAll("SELECT id FROM campaigns WHERE status = 'sending'");
foreach ($sendingCampaigns as $sc) {
    $remaining = getCount('email_queue', 'campaign_id = ? AND status IN (?, ?)', [$sc['id'], 'pending', 'sending']);
    if ($remaining === 0) {
        dbExecute("UPDATE campaigns SET status = 'completed', updated_at = NOW() WHERE id = ?", [$sc['id']]);
        cronLog("Campaign #{$sc['id']} completed!");
    }
}

cronLog("=== Cron finished ===\n");

// Output for CLI/web
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'processed' => count($pendingEmails)]);
}
