<?php
/**
 * API: List Saved Campaign Templates
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireAuth();
ensureCampaignTemplatesTable();

try {
    $templates = dbFetchAll("
        SELECT id, name, subject, body_html, created_at, updated_at
        FROM campaign_templates
        ORDER BY updated_at DESC, created_at DESC
    ");

    jsonResponse([
        'success' => true,
        'templates' => $templates,
    ]);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
