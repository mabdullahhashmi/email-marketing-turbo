<?php
/**
 * API: Save SMTP Account
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['success' => false, 'message' => 'Invalid request data'], 400);
}

validateCSRF($input['csrf_token'] ?? '');

$id = (int)($input['id'] ?? 0);
$label = trim($input['label'] ?? '');
$host = trim($input['smtp_host'] ?? '');
$port = (int)($input['smtp_port'] ?? 465);
$encryption = $input['smtp_encryption'] ?? 'ssl';
$username = trim($input['smtp_username'] ?? '');
$password = trim($input['smtp_password'] ?? '');
$fromName = trim($input['from_name'] ?? '');
$fromEmail = trim($input['from_email'] ?? '');
$dailyLimit = (int)($input['daily_limit'] ?? 0);

// Validate
if (!$label || !$host || !$port || !$username || !$fromName || !$fromEmail) {
    jsonResponse(['success' => false, 'message' => 'All required fields must be filled in.'], 400);
}

if (!in_array($encryption, ['ssl', 'tls'])) {
    $encryption = 'ssl';
}

try {
    if ($id > 0) {
        // Update existing
        $updateFields = [
            'label' => $label,
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_encryption' => $encryption,
            'smtp_username' => $username,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'daily_limit' => $dailyLimit,
        ];
        
        $sql = "UPDATE smtp_accounts SET label = ?, smtp_host = ?, smtp_port = ?, smtp_encryption = ?, smtp_username = ?, from_name = ?, from_email = ?, daily_limit = ?";
        $params = array_values($updateFields);
        
        // Only update password if provided
        if ($password) {
            $sql .= ", smtp_password = ?";
            $params[] = encryptString($password);
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        dbExecute($sql, $params);
        jsonResponse(['success' => true, 'message' => 'SMTP account updated successfully.']);
    } else {
        // Create new
        if (!$password) {
            jsonResponse(['success' => false, 'message' => 'Password is required for new accounts.'], 400);
        }
        
        $newId = dbInsert(
            "INSERT INTO smtp_accounts (label, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, from_name, from_email, daily_limit) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$label, $host, $port, $encryption, $username, encryptString($password), $fromName, $fromEmail, $dailyLimit]
        );
        
        jsonResponse(['success' => true, 'message' => 'SMTP account created successfully.', 'id' => $newId]);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error saving account: ' . $e->getMessage()], 500);
}
