<?php
/**
 * API: Import CSV Contacts
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireAuth();
validateCSRF($_POST['csrf_token'] ?? '');

$listId = (int)($_POST['list_id'] ?? 0);
$mappingsJson = $_POST['mappings'] ?? '{}';

if (!$listId) {
    jsonResponse(['success' => false, 'message' => 'Please select a contact list.'], 400);
}

// Check list exists
$list = dbFetchOne("SELECT id FROM contact_lists WHERE id = ?", [$listId]);
if (!$list) {
    jsonResponse(['success' => false, 'message' => 'Contact list not found.'], 404);
}

// Check file upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'No file uploaded or upload error.'], 400);
}

$file = $_FILES['file'];

// Validate file
if ($file['size'] > MAX_CSV_SIZE) {
    jsonResponse(['success' => false, 'message' => 'File too large. Maximum is ' . (MAX_CSV_SIZE / 1024 / 1024) . 'MB.'], 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'])) {
    jsonResponse(['success' => false, 'message' => 'Only CSV and TXT files are allowed.'], 400);
}

try {
    $mappings = json_decode($mappingsJson, true);
    if (!$mappings) {
        jsonResponse(['success' => false, 'message' => 'Invalid column mappings.'], 400);
    }
    
    // Find which columns are mapped to what
    $emailCol = -1;
    $nameCol = -1;
    $customCols = [];
    
    foreach ($mappings as $colIndex => $mapping) {
        $field = $mapping['field'];
        $header = $mapping['header'] ?? '';
        
        if ($field === 'email') $emailCol = (int)$colIndex;
        elseif ($field === 'name') $nameCol = (int)$colIndex;
        elseif ($field === 'custom') $customCols[(int)$colIndex] = $header;
    }
    
    if ($emailCol === -1) {
        jsonResponse(['success' => false, 'message' => 'You must map at least one column to "Email".'], 400);
    }
    
    // Parse CSV
    $csvData = parseCSV($file['tmp_name']);
    
    if (empty($csvData['rows'])) {
        jsonResponse(['success' => false, 'message' => 'CSV file is empty or has no data rows.'], 400);
    }
    
    $imported = 0;
    $skipped = 0;
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO contacts (list_id, email, name, custom_fields) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), custom_fields = VALUES(custom_fields)"
        );
        
        foreach ($csvData['rows'] as $row) {
            $email = trim($row[$emailCol] ?? '');
            
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }
            
            $name = ($nameCol >= 0 && isset($row[$nameCol])) ? trim($row[$nameCol]) : '';
            
            // Custom fields
            $customFields = [];
            foreach ($customCols as $colIdx => $header) {
                if (isset($row[$colIdx]) && trim($row[$colIdx]) !== '') {
                    $customFields[$header] = trim($row[$colIdx]);
                }
            }
            
            // Check for duplicate in same list
            $exists = dbFetchOne("SELECT id FROM contacts WHERE list_id = ? AND email = ?", [$listId, $email]);
            
            if ($exists) {
                // Update existing
                dbExecute("UPDATE contacts SET name = ?, custom_fields = ? WHERE id = ?", [
                    $name,
                    !empty($customFields) ? json_encode($customFields) : null,
                    $exists['id']
                ]);
            } else {
                // Insert new
                dbInsert("INSERT INTO contacts (list_id, email, name, custom_fields) VALUES (?, ?, ?, ?)", [
                    $listId,
                    $email,
                    $name,
                    !empty($customFields) ? json_encode($customFields) : null,
                ]);
            }
            
            $imported++;
        }
        
        $pdo->commit();
        
        // Update list count
        dbExecute("UPDATE contact_lists SET total_contacts = (SELECT COUNT(*) FROM contacts WHERE list_id = ?) WHERE id = ?", [$listId, $listId]);
        
        jsonResponse([
            'success' => true,
            'message' => "Imported {$imported} contacts. Skipped {$skipped} invalid entries.",
            'count' => $imported,
            'skipped' => $skipped,
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Import error: ' . $e->getMessage()], 500);
}
