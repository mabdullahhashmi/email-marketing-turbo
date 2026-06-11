<?php
/**
 * API: Save Campaign Template
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

$name = trim($input['name'] ?? '');
$subject = trim($input['subject'] ?? '');
$bodyHtml = $input['body_html'] ?? '';

if ($name === '') {
    jsonResponse(['success' => false, 'message' => 'Template name is required.'], 400);
}

if (trim(strip_tags($bodyHtml)) === '' && stripos($bodyHtml, '<img') === false && stripos($bodyHtml, '<table') === false) {
    jsonResponse(['success' => false, 'message' => 'Build an email body before saving a template.'], 400);
}

try {
    ensureCampaignTemplatesTable();

    $id = dbInsert(
        "INSERT INTO campaign_templates (name, subject, body_html) VALUES (?, ?, ?)",
        [$name, $subject, $bodyHtml]
    );

    jsonResponse([
        'success' => true,
        'message' => 'Template saved.',
        'template_id' => $id,
    ]);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
