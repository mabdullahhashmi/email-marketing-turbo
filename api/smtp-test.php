<?php
/**
 * API: Test SMTP Connection / Send Test Email
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
        $host = $account['smtp_host'];
        $port = (int)$account['smtp_port'];
        $encryption = $account['smtp_encryption'];
        $username = $account['smtp_username'];
        $password = decryptString($account['smtp_password']);
        $fromName = $account['from_name'];
        $fromEmail = $account['from_email'];
        $toEmail = $account['from_email']; // Send to self
    } else {
        // Use provided SMTP details
        $smtpId = (int)($input['smtp_account_id'] ?? 0);
        if ($smtpId) {
            $account = dbFetchOne("SELECT * FROM smtp_accounts WHERE id = ?", [$smtpId]);
            if (!$account) {
                jsonResponse(['success' => false, 'message' => 'SMTP account not found'], 404);
            }
            $host = $account['smtp_host'];
            $port = (int)$account['smtp_port'];
            $encryption = $account['smtp_encryption'];
            $username = $account['smtp_username'];
            $password = decryptString($account['smtp_password']);
            $fromName = $account['from_name'];
            $fromEmail = $account['from_email'];
        } else {
            jsonResponse(['success' => false, 'message' => 'No SMTP account specified'], 400);
        }
        $toEmail = $input['to_email'] ?? $fromEmail;
    }
    
    $subject = $input['subject'] ?? 'Test Email from ' . APP_NAME;
    $body = $input['body_html'] ?? '<h2>Test Email</h2><p>This is a test email sent from ' . APP_NAME . '.</p><p>If you received this, your SMTP configuration is working correctly! ✅</p>';
    
    $mail = new PHPMailer(true);
    
    // SMTP Configuration
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = $encryption === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $port;
    $mail->Timeout = 15;
    
    // Email
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $body;
    $mail->AltBody = strip_tags($body);
    $mail->CharSet = 'UTF-8';
    
    $mail->send();
    
    jsonResponse(['success' => true, 'message' => 'Email sent successfully to ' . $toEmail]);
    
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'SMTP Error: ' . $e->getMessage()]);
} catch (\Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
