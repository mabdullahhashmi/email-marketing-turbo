<?php
/**
 * API: Pause / Resume Campaign
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
$action = $input['action'] ?? '';

if (!$id) {
    jsonResponse(['success' => false, 'message' => 'Campaign ID required.'], 400);
}

$campaign = dbFetchOne("SELECT * FROM campaigns WHERE id = ?", [$id]);
if (!$campaign) {
    jsonResponse(['success' => false, 'message' => 'Campaign not found.'], 404);
}

try {
    if ($action === 'pause') {
        if (!in_array($campaign['status'], ['sending', 'scheduled'])) {
            jsonResponse(['success' => false, 'message' => 'Campaign cannot be paused (status: ' . $campaign['status'] . ')'], 400);
        }
        
        dbExecute("UPDATE campaigns SET status = 'paused', updated_at = NOW() WHERE id = ?", [$id]);
        jsonResponse(['success' => true, 'message' => 'Campaign paused.']);
        
    } elseif ($action === 'resume') {
        if ($campaign['status'] !== 'paused') {
            jsonResponse(['success' => false, 'message' => 'Campaign is not paused.'], 400);
        }
        
        // Check if there are pending emails
        $pendingCount = getCount('email_queue', 'campaign_id = ? AND status = ?', [$id, 'pending']);
        
        if ($pendingCount > 0) {
            dbExecute("UPDATE campaigns SET status = 'sending', updated_at = NOW() WHERE id = ?", [$id]);
            jsonResponse(['success' => true, 'message' => "Campaign resumed. {$pendingCount} emails pending."]);
        } else {
            dbExecute("UPDATE campaigns SET status = 'completed', updated_at = NOW() WHERE id = ?", [$id]);
            jsonResponse(['success' => true, 'message' => 'No pending emails. Campaign marked as completed.']);
        }
    } else {
        jsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
