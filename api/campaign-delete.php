<?php
/**
 * API: Delete Campaign
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
    jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
}

validateCSRF($input['csrf_token'] ?? '');

$id = (int)($input['id'] ?? 0);

if (!$id) {
    jsonResponse(['success' => false, 'message' => 'Campaign ID required.'], 400);
}

try {
    // Delete campaign (cascades to email_queue)
    $deleted = dbExecute("DELETE FROM campaigns WHERE id = ?", [$id]);
    
    // Also clean up click tracking
    dbExecute("DELETE FROM click_tracking WHERE campaign_id = ?", [$id]);
    
    if ($deleted) {
        jsonResponse(['success' => true, 'message' => 'Campaign deleted.']);
    } else {
        jsonResponse(['success' => false, 'message' => 'Campaign not found.'], 404);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
