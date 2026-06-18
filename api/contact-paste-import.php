<?php
/**
 * API: Import pasted contact table rows.
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

$listId = (int)($input['list_id'] ?? 0);
$rows = $input['rows'] ?? [];

if (!$listId) {
    jsonResponse(['success' => false, 'message' => 'Contact list is required.'], 400);
}

$list = dbFetchOne("SELECT id FROM contact_lists WHERE id = ?", [$listId]);
if (!$list) {
    jsonResponse(['success' => false, 'message' => 'Contact list not found.'], 404);
}

if (!is_array($rows) || empty($rows)) {
    jsonResponse(['success' => false, 'message' => 'Paste at least one contact row.'], 400);
}

if (count($rows) > 5000) {
    jsonResponse(['success' => false, 'message' => 'Paste import is limited to 5,000 rows at a time.'], 400);
}

$inserted = 0;
$updated = 0;
$skipped = 0;

$pdo = getDB();
$pdo->beginTransaction();

try {
    foreach ($rows as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }

        $name = trim($row['name'] ?? '');
        $email = trim($row['email'] ?? '');
        $city = trim($row['city'] ?? '');
        $state = trim($row['state'] ?? '');
        $badgeNumber = trim($row['badge_number'] ?? '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            continue;
        }

        $customFields = [];
        if ($city !== '') {
            $customFields['City'] = $city;
        }
        if ($state !== '') {
            $customFields['State'] = $state;
        }
        if ($badgeNumber !== '') {
            $customFields['Badge Number'] = $badgeNumber;
        }

        $existing = dbFetchOne("SELECT id, custom_fields FROM contacts WHERE list_id = ? AND email = ? LIMIT 1", [$listId, $email]);
        if ($existing) {
            $existingCustom = $existing['custom_fields'] ? json_decode($existing['custom_fields'], true) : [];
            if (!is_array($existingCustom)) {
                $existingCustom = [];
            }
            foreach (array_keys($existingCustom) as $key) {
                $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', (string)$key));
                if (in_array($normalized, ['city', 'state', 'badgenumber', 'badge'], true)) {
                    unset($existingCustom[$key]);
                }
            }
            $mergedCustom = array_merge($existingCustom, $customFields);
            dbExecute(
                "UPDATE contacts SET name = ?, custom_fields = ? WHERE id = ?",
                [$name, $mergedCustom ? json_encode($mergedCustom) : null, $existing['id']]
            );
            $updated++;
        } else {
            dbInsert(
                "INSERT INTO contacts (list_id, email, name, custom_fields) VALUES (?, ?, ?, ?)",
                [$listId, $email, $name, $customFields ? json_encode($customFields) : null]
            );
            $inserted++;
        }
    }

    dbExecute("UPDATE contact_lists SET total_contacts = (SELECT COUNT(*) FROM contacts WHERE list_id = ?) WHERE id = ?", [$listId, $listId]);
    $pdo->commit();

    jsonResponse([
        'success' => true,
        'message' => "Imported {$inserted}, updated {$updated}, skipped {$skipped}.",
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'message' => 'Paste import failed: ' . $e->getMessage()], 500);
}
