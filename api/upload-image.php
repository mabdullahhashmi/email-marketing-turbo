<?php
/**
 * API: Upload Image for TinyMCE Editor
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

// Check file
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'File too large (PHP limit)',
        UPLOAD_ERR_FORM_SIZE => 'File too large (form limit)',
        UPLOAD_ERR_PARTIAL => 'File partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
    ];
    $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    jsonResponse(['success' => false, 'message' => $errors[$errorCode] ?? 'Upload error'], 400);
}

$file = $_FILES['file'];

// Validate size
if ($file['size'] > MAX_IMAGE_SIZE) {
    jsonResponse(['success' => false, 'message' => 'Image too large. Maximum is ' . (MAX_IMAGE_SIZE / 1024 / 1024) . 'MB.'], 400);
}

// Validate MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
    jsonResponse(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, GIF, WebP.'], 400);
}

// Generate unique filename
$ext = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
][$mimeType] ?? 'jpg';

$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$destination = UPLOAD_DIR . $filename;

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Move file
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    jsonResponse(['success' => false, 'message' => 'Failed to save uploaded file.'], 500);
}

// Generate CID for email embedding
$cid = 'img_' . bin2hex(random_bytes(8));

// Store record
$campaignId = !empty($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : null;

try {
    dbInsert(
        "INSERT INTO campaign_images (campaign_id, filename, original_name, mime_type, file_size, cid) VALUES (?, ?, ?, ?, ?, ?)",
        [$campaignId, $filename, $file['name'], $mimeType, $file['size'], $cid]
    );
} catch (Exception $e) {
    // Log but don't fail - image is still usable
    error_log('Failed to save image record: ' . $e->getMessage());
}

// Return URL for TinyMCE
// Use relative path so it works from the editor context
$basePath = getBasePath();
$imageUrl = $basePath . '/assets/uploads/' . $filename;

jsonResponse([
    'success' => true,
    'location' => $imageUrl,
    'filename' => $filename,
    'cid' => $cid,
]);
