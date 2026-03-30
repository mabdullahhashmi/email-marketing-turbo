<?php
/**
 * Helper Functions
 */

/**
 * Encrypt a string using AES-256-CBC
 */
function encryptString($plaintext) {
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt a string
 */
function decryptString($ciphertext) {
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $data = base64_decode($ciphertext);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

/**
 * Replace shortcodes in a template string
 * 
 * Supported: {{name}}, {{email}}, {{first_name}}, {{list_name}}, 
 *            {{unsubscribe_link}}, {{custom.FIELD}}
 */
function replaceShortcodes($template, $contact, $extraData = []) {
    $name = $contact['name'] ?? '';
    $email = $contact['email'] ?? '';
    $customFields = [];
    
    if (!empty($contact['custom_fields'])) {
        $customFields = is_string($contact['custom_fields']) 
            ? json_decode($contact['custom_fields'], true) ?? []
            : $contact['custom_fields'];
    }
    
    // Extract first name
    $firstName = explode(' ', trim($name))[0];
    $lastName = '';
    $nameParts = explode(' ', trim($name));
    if (count($nameParts) > 1) {
        $lastName = end($nameParts);
    }
    
    // Standard replacements
    $replacements = [
        '{{name}}' => $name,
        '{{email}}' => $email,
        '{{first_name}}' => $firstName,
        '{{last_name}}' => $lastName,
        '{{list_name}}' => $extraData['list_name'] ?? '',
        '{{unsubscribe_link}}' => $extraData['unsubscribe_link'] ?? '#',
        '{{date}}' => date('F j, Y'),
        '{{year}}' => date('Y'),
    ];
    
    $result = str_replace(array_keys($replacements), array_values($replacements), $template);
    
    // Custom field replacements: {{custom.company}}, {{custom.city}}, etc.
    $result = preg_replace_callback('/\{\{custom\.(\w+)\}\}/', function($matches) use ($customFields) {
        $field = $matches[1];
        return $customFields[$field] ?? '';
    }, $result);
    
    return $result;
}

/**
 * Generate a random tracking token
 */
function generateTrackingToken() {
    return bin2hex(random_bytes(16));
}

/**
 * Process click tracking: replace URLs in HTML with tracking redirects
 */
function processClickTracking($html, $campaignId, $contactId, $queueId) {
    $appUrl = APP_URL ?: getAppUrl();
    
    // Find all href URLs in anchor tags
    $pattern = '/(<a\b[^>]*href=["\'])([^"\']+)(["\'][^>]*>)/i';
    
    $html = preg_replace_callback($pattern, function($matches) use ($campaignId, $contactId, $queueId, $appUrl) {
        $url = $matches[2];
        
        // Skip mailto:, tel:, #, javascript:, and unsubscribe links
        if (preg_match('/^(mailto:|tel:|#|javascript:|cid:)/i', $url)) {
            return $matches[0];
        }
        
        // Skip already-tracked URLs
        if (strpos($url, 'track/click.php') !== false) {
            return $matches[0];
        }
        
        // Create tracking record
        $token = generateTrackingToken();
        dbInsert(
            "INSERT INTO click_tracking (campaign_id, contact_id, queue_id, original_url, tracking_token) 
             VALUES (?, ?, ?, ?, ?)",
            [$campaignId, $contactId, $queueId, $url, $token]
        );
        
        $trackingUrl = $appUrl . '/track/click.php?t=' . $token;
        return $matches[1] . $trackingUrl . $matches[3];
    }, $html);
    
    return $html;
}

/**
 * Scan HTML for uploaded images and prepare CID mapping
 * Returns array of ['path' => filepath, 'cid' => content_id, 'name' => filename]
 */
function getEmbeddedImages($html) {
    $images = [];
    $uploadDir = UPLOAD_DIR;
    
    // Match img src pointing to assets/uploads/
    preg_match_all('/src=["\'](?:\.\.\/|\.\/)?(?:assets\/uploads\/)([^"\']+)["\']/i', $html, $matches);
    
    if (!empty($matches[1])) {
        foreach ($matches[1] as $filename) {
            $filepath = $uploadDir . $filename;
            if (file_exists($filepath)) {
                $cid = 'img_' . md5($filename) . '_' . pathinfo($filename, PATHINFO_FILENAME);
                $images[] = [
                    'path' => $filepath,
                    'cid' => $cid,
                    'name' => $filename,
                    'original_src_pattern' => $filename,
                ];
            }
        }
    }
    
    return $images;
}

/**
 * Replace image src URLs with CID references in HTML
 */
function replaceImagesWithCID($html, $images) {
    foreach ($images as $img) {
        // Replace various forms of the image path with cid: reference
        $patterns = [
            'assets/uploads/' . $img['original_src_pattern'],
            '../assets/uploads/' . $img['original_src_pattern'],
            './assets/uploads/' . $img['original_src_pattern'],
        ];
        foreach ($patterns as $pattern) {
            $html = str_replace($pattern, 'cid:' . $img['cid'], $html);
        }
    }
    return $html;
}

/**
 * Try to auto-detect the app URL
 */
function getAppUrl() {
    if (defined('APP_URL') && APP_URL !== '') {
        return APP_URL;
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = getBasePath();
    return $protocol . '://' . $host . $basePath;
}

/**
 * Format a datetime string to readable format
 */
function formatDateTime($datetime) {
    if (!$datetime) return '—';
    return date('M j, Y g:i A', strtotime($datetime));
}

/**
 * Time ago helper
 */
function timeAgo($datetime) {
    if (!$datetime) return '—';
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 0) {
        // Future
        $diff = abs($diff);
        if ($diff < 60) return 'in ' . $diff . 's';
        if ($diff < 3600) return 'in ' . floor($diff / 60) . 'm';
        if ($diff < 86400) return 'in ' . floor($diff / 3600) . 'h';
        return 'in ' . floor($diff / 86400) . 'd';
    }
    
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return formatDateTime($datetime);
}

/**
 * Sanitize output for HTML
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Generate a clean flash message
 */
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Get campaign status badge HTML
 */
function statusBadge($status) {
    $classes = [
        'draft' => 'badge-draft',
        'scheduled' => 'badge-scheduled',
        'sending' => 'badge-sending',
        'completed' => 'badge-completed',
        'paused' => 'badge-paused',
        'pending' => 'badge-scheduled',
        'sent' => 'badge-completed',
        'failed' => 'badge-failed',
    ];
    $class = $classes[$status] ?? 'badge-draft';
    return '<span class="badge ' . $class . '">' . ucfirst(e($status)) . '</span>';
}

/**
 * Safe redirect
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Return JSON response
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get the count from a table
 */
function getCount($table, $where = '', $params = []) {
    $sql = "SELECT COUNT(*) FROM `{$table}`";
    if ($where) {
        $sql .= " WHERE {$where}";
    }
    return (int) dbFetchValue($sql, $params);
}

/**
 * Parse CSV file and return headers + rows
 */
function parseCSV($filepath, $limit = 0) {
    $headers = [];
    $rows = [];
    
    if (($handle = fopen($filepath, 'r')) !== false) {
        // Detect delimiter
        $firstLine = fgets($handle);
        rewind($handle);
        
        $delimiter = ',';
        if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        }
        
        $lineNum = 0;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($lineNum === 0) {
                $headers = array_map('trim', $data);
            } else {
                if ($limit > 0 && $lineNum > $limit) break;
                $rows[] = $data;
            }
            $lineNum++;
        }
        fclose($handle);
    }
    
    return ['headers' => $headers, 'rows' => $rows];
}
