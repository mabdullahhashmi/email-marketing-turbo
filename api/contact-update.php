<?php
/**
 * API: Inline update contact fields.
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
$listId = (int)($input['list_id'] ?? 0);
$email = trim($input['email'] ?? '');
$name = trim($input['name'] ?? '');
$city = trim($input['city'] ?? '');
$state = trim($input['state'] ?? '');
$badgeNumber = trim($input['badge_number'] ?? '');

if (!$id || !$listId) {
    jsonResponse(['success' => false, 'message' => 'Contact and list are required.'], 400);
}

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'A valid email address is required.'], 400);
}

$contact = dbFetchOne("SELECT * FROM contacts WHERE id = ? AND list_id = ?", [$id, $listId]);
if (!$contact) {
    jsonResponse(['success' => false, 'message' => 'Contact not found.'], 404);
}

$duplicate = dbFetchOne("SELECT id FROM contacts WHERE list_id = ? AND email = ? AND id <> ? LIMIT 1", [$listId, $email, $id]);
if ($duplicate) {
    jsonResponse(['success' => false, 'message' => 'Another contact in this list already uses that email.'], 409);
}

$customFields = $contact['custom_fields'] ? json_decode($contact['custom_fields'], true) : [];
if (!is_array($customFields)) {
    $customFields = [];
}

foreach (array_keys($customFields) as $key) {
    $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', (string)$key));
    if (in_array($normalized, ['city', 'state', 'badgenumber', 'badge'], true)) {
        unset($customFields[$key]);
    }
}

if ($city !== '') {
    $customFields['City'] = $city;
}
if ($state !== '') {
    $customFields['State'] = $state;
}
if ($badgeNumber !== '') {
    $customFields['Badge Number'] = $badgeNumber;
}

dbExecute(
    "UPDATE contacts SET email = ?, name = ?, custom_fields = ? WHERE id = ? AND list_id = ?",
    [$email, $name, $customFields ? json_encode($customFields) : null, $id, $listId]
);

jsonResponse([
    'success' => true,
    'message' => 'Contact updated.',
]);
