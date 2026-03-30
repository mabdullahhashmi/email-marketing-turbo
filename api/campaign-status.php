<?php
/**
 * API: Get Campaign Status (for polling)
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

requireAuth();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    jsonResponse(['success' => false, 'message' => 'Campaign ID required.'], 400);
}

$campaign = dbFetchOne("SELECT id, status, total_emails, sent_count, failed_count FROM campaigns WHERE id = ?", [$id]);

if (!$campaign) {
    jsonResponse(['success' => false, 'message' => 'Campaign not found.'], 404);
}

$pendingCount = getCount('email_queue', 'campaign_id = ? AND status = ?', [$id, 'pending']);
$clickCount = getCount('click_tracking', 'campaign_id = ? AND clicked_at IS NOT NULL', [$id]);

jsonResponse([
    'success' => true,
    'data' => [
        'status' => $campaign['status'],
        'total_emails' => (int)$campaign['total_emails'],
        'sent_count' => (int)$campaign['sent_count'],
        'failed_count' => (int)$campaign['failed_count'],
        'pending_count' => $pendingCount,
        'click_count' => $clickCount,
    ]
]);
