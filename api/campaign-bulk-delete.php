<?php
/**
 * API: Bulk delete campaigns.
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

$ids = $input['ids'] ?? [];
if (!is_array($ids)) {
    jsonResponse(['success' => false, 'message' => 'Campaign IDs must be an array.'], 400);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
if (empty($ids)) {
    jsonResponse(['success' => false, 'message' => 'Select at least one campaign to delete.'], 400);
}

if (count($ids) > 500) {
    jsonResponse(['success' => false, 'message' => 'Bulk delete is limited to 500 campaigns at a time.'], 400);
}

try {
    $deleted = deleteCampaignsByIds($ids);

    jsonResponse([
        'success' => $deleted > 0,
        'message' => $deleted > 0
            ? "Deleted {$deleted} campaign(s)."
            : 'No matching campaigns were found.',
        'deleted' => $deleted,
    ], $deleted > 0 ? 200 : 404);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
