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
 * Ensure campaign open tracking table exists.
 */
function ensureCampaignOpenTrackingTable() {
    try {
        dbExecute("
            CREATE TABLE IF NOT EXISTS `campaign_open_tracking` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `campaign_id` INT NOT NULL,
                `contact_id` INT NOT NULL,
                `queue_id` INT DEFAULT NULL,
                `tracking_token` VARCHAR(64) NOT NULL UNIQUE,
                `opened_at` DATETIME DEFAULT NULL,
                `open_count` INT NOT NULL DEFAULT 0,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` TEXT,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token (`tracking_token`),
                INDEX idx_campaign (`campaign_id`),
                INDEX idx_queue (`queue_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Ensure saved campaign templates table exists.
 */
function ensureCampaignTemplatesTable() {
    try {
        dbExecute("
            CREATE TABLE IF NOT EXISTS `campaign_templates` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `subject` VARCHAR(500) DEFAULT '',
                `body_html` LONGTEXT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (`name`),
                INDEX idx_created (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function mailpilotBuilderExtractState($html) {
    if (!preg_match('/<!--MAILPILOT_BUILDER\s+([A-Za-z0-9+\/=]+)-->/', (string)$html, $matches)) {
        return null;
    }

    $json = base64_decode($matches[1], true);
    if ($json === false) {
        return null;
    }

    $state = json_decode($json, true);
    if (!is_array($state)) {
        return null;
    }

    if (($state['settings']['mode'] ?? '') === 'rawHtml' && array_key_exists('rawHtml', $state)) {
        return $state;
    }

    return !empty($state['blocks']) && is_array($state['blocks']) ? $state : null;
}

function mailpilotBuilderEncodeState($state) {
    return base64_encode(json_encode($state, JSON_UNESCAPED_SLASHES));
}

function mailpilotBuilderEsc($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function mailpilotBuilderAttr($value) {
    return str_replace(["\r", "\n"], ' ', mailpilotBuilderEsc($value));
}

function mailpilotBuilderLines($value) {
    return nl2br(mailpilotBuilderEsc($value), false);
}

function mailpilotBuilderNum($value, $default = 0) {
    return is_numeric($value) ? (int)$value : (int)$default;
}

function mailpilotBuilderSetting($state, $key, $default = '') {
    return $state['settings'][$key] ?? $default;
}

function mailpilotBuilderFontStack($font) {
    $stacks = [
        'Poppins' => "'Poppins', 'Segoe UI', Arial, Helvetica, sans-serif",
        'Montserrat' => "'Montserrat', 'Segoe UI', Arial, Helvetica, sans-serif",
        'DM Sans' => "'DM Sans', 'Segoe UI', Arial, Helvetica, sans-serif",
        'Arial' => 'Arial, Helvetica, sans-serif',
        'Helvetica' => 'Helvetica, Arial, sans-serif',
        'Verdana' => 'Verdana, Geneva, sans-serif',
        'Trebuchet MS' => "'Trebuchet MS', Arial, sans-serif",
        'Georgia' => 'Georgia, serif',
        'Times New Roman' => "'Times New Roman', Times, serif",
    ];
    return $stacks[$font] ?? $stacks['Poppins'];
}

function mailpilotBuilderFontImport($font) {
    if ($font === 'Poppins') {
        return '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">';
    }
    if ($font === 'Montserrat') {
        return '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    }
    if ($font === 'DM Sans') {
        return '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    }
    return '';
}

function mailpilotBuilderResponsiveCss() {
    return '<style>
:root { color-scheme: light; supported-color-schemes: light; }
body, table, td, div, span, a { font-family: inherit; }
.mp-light-bg { background-color:#ffffff !important; background-image:linear-gradient(#ffffff,#ffffff) !important; color:#0b1d3a !important; }
.mp-soft-bg { background-color:#f7f9fc !important; background-image:linear-gradient(#f7f9fc,#f7f9fc) !important; color:#0b1d3a !important; }
.mp-cream-bg { background-color:#fff5eb !important; background-image:linear-gradient(#fff5eb,#fff5eb) !important; color:#0b1d3a !important; }
@media screen and (max-width:680px) {
    .mp-container { width:100% !important; max-width:100% !important; }
    .mp-stack { display:block !important; width:100% !important; max-width:100% !important; }
    .mp-hide-mobile { display:none !important; }
    .mp-mobile-edge { padding-left:0 !important; padding-right:0 !important; }
    .mp-pad-mobile { padding-left:22px !important; padding-right:22px !important; }
    .mp-mobile-pad { padding-left:22px !important; padding-right:22px !important; }
    .mp-center-mobile { text-align:center !important; }
    .mp-mobile-top { padding-top:20px !important; }
    .mp-mobile-gap { padding-top:12px !important; }
    .mp-process-border { border-right:none !important; border-bottom:1px solid #dde5f0 !important; padding-top:16px !important; padding-bottom:16px !important; }
    .mp-mobile-light { background-color:#ffffff !important; background-image:linear-gradient(#ffffff,#ffffff) !important; color:#0b1d3a !important; }
    .mp-mobile-soft { background-color:#f7f9fc !important; background-image:linear-gradient(#f7f9fc,#f7f9fc) !important; color:#0b1d3a !important; }
    .mp-mobile-center { text-align:center !important; }
    .mp-cta-wrap { padding-left:16px !important; padding-right:16px !important; }
    .mp-cta-cell { box-sizing:border-box !important; padding:22px 18px 10px 18px !important; text-align:center !important; width:100% !important; }
    .mp-cta-action { box-sizing:border-box !important; padding:12px 18px 22px 18px !important; text-align:center !important; width:100% !important; }
    .mp-cta-title { margin:0 auto !important; max-width:290px !important; text-align:center !important; overflow-wrap:break-word !important; }
    .mp-cta-copy { margin:0 auto !important; max-width:320px !important; text-align:center !important; overflow-wrap:break-word !important; }
    .mp-cta-button { box-sizing:border-box !important; max-width:100% !important; text-align:center !important; white-space:normal !important; }
}
</style>';
}

function mailpilotBuilderBaseCss($fontStack) {
    return '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><style>
body, table, td, div, span, p, a, strong { font-family:' . $fontStack . ' !important; }
body { background-color:#f7f9fc !important; }
img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
</style>';
}

function mailpilotBuilderBlockValue($block, $key, $default = '') {
    return $block[$key] ?? $default;
}

function mailpilotBuilderFaIcons() {
    return [
        'chart-line' => [
            'viewBox' => '0 0 512 512',
            'path' => 'M64 64c0-17.7-14.3-32-32-32S0 46.3 0 64V400c0 44.2 35.8 80 80 80H480c17.7 0 32-14.3 32-32s-14.3-32-32-32H80c-8.8 0-16-7.2-16-16V64zm406.6 86.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L320 210.7l-57.4-57.4c-12.5-12.5-32.8-12.5-45.3 0l-112 112c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L240 221.3l57.4 57.4c12.5 12.5 32.8 12.5 45.3 0l128-128z',
        ],
        'dollar-sign' => [
            'viewBox' => '0 0 320 512',
            'path' => 'M160 0c17.7 0 32 14.3 32 32V67.7c20.3 1.9 40 6.5 58.2 13.4c16.5 6.2 24.9 24.6 18.6 41.2s-24.6 24.9-41.2 18.6c-18.1-6.8-38.6-10.5-57.7-10.5c-36.3 0-65.9 14.7-65.9 32.8c0 16.6 21.5 27.2 75.6 41.7l3.4 .9c62.9 16.8 117 43.2 117 110.3c0 59.4-48.9 101.1-108 109.8V480c0 17.7-14.3 32-32 32s-32-14.3-32-32V426.4c-28.2-3.1-55.4-12.4-79.5-27.2c-15.1-9.2-19.8-28.9-10.6-44s28.9-19.8 44-10.6c24.5 15 52.4 22.9 80.4 22.9c41.8 0 74.8-16.6 74.8-43.6c0-20.6-14.3-33.1-78.2-50.1l-3.4-.9C86.2 257.2 40 232.1 40 164.2c0-55.7 45.9-93.2 88-102.8V32c0-17.7 14.3-32 32-32z',
        ],
        'calendar-check' => [
            'viewBox' => '0 0 448 512',
            'path' => 'M152 24c0-13.3-10.7-24-24-24s-24 10.7-24 24V64H64C28.7 64 0 92.7 0 128v16H448V128c0-35.3-28.7-64-64-64H344V24c0-13.3-10.7-24-24-24s-24 10.7-24 24V64H152V24zM448 192H0V448c0 35.3 28.7 64 64 64H384c35.3 0 64-28.7 64-64V192zM331.3 304.7l-112 112c-6.2 6.2-16.4 6.2-22.6 0l-56-56c-6.2-6.2-6.2-16.4 0-22.6s16.4-6.2 22.6 0L208 382.7l100.7-100.7c6.2-6.2 16.4-6.2 22.6 0s6.2 16.4 0 22.6z',
        ],
        'bullseye' => [
            'viewBox' => '0 0 512 512',
            'path' => 'M448 256A192 192 0 1 0 64 256a192 192 0 1 0 384 0zM0 256a256 256 0 1 1 512 0A256 256 0 1 1 0 256zm256 80a80 80 0 1 0 0-160 80 80 0 1 0 0 160zm0-224a144 144 0 1 1 0 288 144 144 0 1 1 0-288zm0 176a32 32 0 1 0 0-64 32 32 0 1 0 0 64z',
        ],
        'mobile-screen-button' => [
            'viewBox' => '0 0 384 512',
            'path' => 'M16 64C16 28.7 44.7 0 80 0H304c35.3 0 64 28.7 64 64V448c0 35.3-28.7 64-64 64H80c-35.3 0-64-28.7-64-64V64zM224 448a32 32 0 1 0-64 0a32 32 0 1 0 64 0zM304 64H80V384H304V64z',
        ],
        'shield-halved' => [
            'viewBox' => '0 0 512 512',
            'path' => 'M256 0c4.6 0 9.2 1 13.4 2.9L457.7 82.8c22 9.3 36.3 30.9 36.3 54.8c0 116.6-65.5 225.4-169.9 278.2l-54.7 27.7c-8.5 4.3-18.3 4.3-26.8 0l-54.7-27.7C83.5 363 18 254.2 18 137.6c0-23.9 14.3-45.5 36.3-54.8L242.6 2.9C246.8 1 251.4 0 256 0z',
        ],
        'bolt' => [
            'viewBox' => '0 0 448 512',
            'path' => 'M349.4 44.6c5.9-13.7 1.5-29.7-10.6-38.5s-28.7-8.8-39.7 1.3L31.4 255.4c-10.1 9.4-13.4 24-8.3 36.8s17 21.4 30.8 21.4H184.1L98.6 467.4c-7.3 13.1-3.1 29.6 10.2 38.2s30.8 7.7 43.2-2.6L416.6 224.6c9.5-10 12.2-24.6 6.8-37.2s-15.9-21-29.6-21H262.5L349.4 44.6z',
        ],
        'database' => [
            'viewBox' => '0 0 448 512',
            'path' => 'M448 80v48c0 44.2-100.3 80-224 80S0 172.2 0 128V80C0 35.8 100.3 0 224 0S448 35.8 448 80zM393.2 214.7c20.8-7.4 39.9-16.9 54.8-28.6V288c0 44.2-100.3 80-224 80S0 332.2 0 288V186.1c14.9 11.8 34 21.2 54.8 28.6C99.7 230.7 159.5 240 224 240s124.3-9.3 169.2-25.3zM0 346.1c14.9 11.8 34 21.2 54.8 28.6C99.7 390.7 159.5 400 224 400s124.3-9.3 169.2-25.3c20.8-7.4 39.9-16.9 54.8-28.6V432c0 44.2-100.3 80-224 80S0 476.2 0 432V346.1z',
        ],
        'pen-to-square' => [
            'viewBox' => '0 0 512 512',
            'path' => 'M471.6 21.7c-23.6-23.6-61.9-23.6-85.5 0L362.3 45.5l104 104l23.8-23.8c23.6-23.6 23.6-61.9 0-85.5L471.6 21.7zM21.7 386.1C7.8 400 0 418.9 0 438.6V480c0 17.7 14.3 32 32 32H73.4c19.7 0 38.6-7.8 52.5-21.7L444.7 171.5l-104-104L21.7 386.1z',
        ],
        'phone' => [
            'viewBox' => '0 0 512 512',
            'path' => 'M164.9 24.6c-7.7-18.6-28-28.5-46.6-20.8L39.4 36.7C21.7 44.1 9.7 60.7 8.1 79.8c-7.8 96.1 23.4 189.4 89.3 255.3s159.2 97.1 255.3 89.3c19.1-1.6 35.7-13.6 43.1-31.3l32.9-78.9c7.7-18.6-2.2-38.9-20.8-46.6l-86.7-36.1c-16.3-6.8-35.2-2.1-46.6 11.4l-37.1 43.6c-49.7-25.6-90.4-66.3-116-116l43.6-37.1c13.5-11.5 18.2-30.3 11.4-46.6L164.9 24.6z',
        ],
    ];
}

function mailpilotBuilderFaIconName($label) {
    $key = strtolower(trim((string)$label));
    $map = [
        '+' => 'chart-line',
        'more leads' => 'chart-line',
        'lead' => 'chart-line',
        'leads' => 'chart-line',
        'hero-more-leads' => 'hero-more-leads',
        'hero-lower-cost' => 'hero-lower-cost',
        'hero-more-booked' => 'hero-more-booked',
        'scorecard-cta-visibility' => 'scorecard-cta-visibility',
        'scorecard-mobile-quote' => 'scorecard-mobile-quote',
        'scorecard-trust-proof' => 'scorecard-trust-proof',
        'scorecard-calls' => 'scorecard-calls',
        'scorecard-quotes' => 'scorecard-quotes',
        'process-conversion-focused' => 'process-conversion-focused',
        'process-speed-optimized' => 'process-speed-optimized',
        'process-trust-built-in' => 'process-trust-built-in',
        'process-data-driven' => 'process-data-driven',
        '$' => 'dollar-sign',
        'cost' => 'dollar-sign',
        'lower cost' => 'dollar-sign',
        'b' => 'calendar-check',
        'booked' => 'calendar-check',
        'booking' => 'calendar-check',
        'calendar' => 'calendar-check',
        'c' => 'bullseye',
        'cta' => 'bullseye',
        'conversion' => 'bullseye',
        'clear ctas' => 'bullseye',
        'm' => 'mobile-screen-button',
        'mobile' => 'mobile-screen-button',
        'mobile-friendly' => 'mobile-screen-button',
        't' => 'shield-halved',
        'trust' => 'shield-halved',
        'trust proof' => 'shield-halved',
        's' => 'bolt',
        'speed' => 'bolt',
        'fast' => 'bolt',
        'f' => 'bolt',
        'd' => 'database',
        'data' => 'database',
        'q' => 'pen-to-square',
        'quote' => 'pen-to-square',
        'quotes' => 'pen-to-square',
        'p' => 'phone',
        'phone' => 'phone',
        'call' => 'phone',
        'calls' => 'phone',
    ];

    return $map[$key] ?? 'chart-line';
}

function mailpilotBuilderFaIconSvg($label, $color = 'currentColor', $size = 14, $display = 'inline-block') {
    $name = mailpilotBuilderFaIconName($label);
    $size = max(8, (int)$size);
    $style = $display === 'block'
        ? 'display:block;margin:0 auto;border:0;outline:0;text-decoration:none;line-height:1;'
        : 'display:inline-block;vertical-align:-2px;border:0;outline:0;text-decoration:none;line-height:1;';

    return '<img src="assets/uploads/mailpilot-icons/' . mailpilotBuilderAttr($name) . '.png" width="' . $size . '" height="' . $size . '" alt="" style="' . $style . 'width:' . $size . 'px;height:' . $size . 'px;" />';
}

function mailpilotBuilderIconBadge($label, $bg = '#fff5eb', $color = '#f47c20', $size = 42, $iconSize = 16, $radius = 14, $margin = '0 auto 10px auto') {
    $size = (int)$size;
    return '<table role="presentation" width="' . $size . '" height="' . $size . '" cellspacing="0" cellpadding="0" border="0" style="width:' . $size . 'px;height:' . $size . 'px;border-radius:' . (int)$radius . 'px;background:' . mailpilotBuilderAttr($bg) . ';background-image:linear-gradient(' . mailpilotBuilderAttr($bg) . ',' . mailpilotBuilderAttr($bg) . ');margin:' . mailpilotBuilderAttr($margin) . ';box-shadow:inset 0 1px 0 rgba(255,255,255,.9),0 8px 18px rgba(244,124,32,.12);border-collapse:separate;"><tr><td align="center" valign="middle" style="line-height:1;">' . mailpilotBuilderFaIconSvg($label, $color, $iconSize, 'block') . '</td></tr></table>';
}

function mailpilotBuilderPremiumBlockHtml($block, $state) {
    $type = $block['type'] ?? '';
    $fontStack = mailpilotBuilderFontStack(mailpilotBuilderSetting($state, 'font', 'Poppins'));
    $get = function($key, $default = '') use ($block) {
        return mailpilotBuilderBlockValue($block, $key, $default);
    };
    $esc = function($key, $default = '') use ($get) {
        return mailpilotBuilderEsc($get($key, $default));
    };
    $attr = function($key, $default = '') use ($get) {
        return mailpilotBuilderAttr($get($key, $default));
    };
    $lines = function($key, $default = '') use ($get) {
        return mailpilotBuilderLines($get($key, $default));
    };
    $iconSymbol = function($label, $color = 'currentColor', $size = 14) {
        return mailpilotBuilderFaIconSvg($label, $color, $size);
    };
    $iconHtml = function($label, $bg = '#fff5eb', $color = '#f47c20', $size = 42, $fontSize = 16, $radius = 14, $margin = '0 auto 10px auto') {
        return mailpilotBuilderIconBadge($label, $bg, $color, $size, $fontSize, $radius, $margin);
    };
    $pipeGraphic = function() use ($esc, $attr) {
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#102641;background-image:linear-gradient(135deg,rgba(255,255,255,.08),rgba(255,255,255,0));"><tr><td align="center" style="padding:24px 10px;"><table role="presentation" width="218" cellspacing="0" cellpadding="0" border="0" style="width:218px;max-width:100%;border-collapse:separate;"><tr><td width="86">&nbsp;</td><td align="center" colspan="2"><div style="height:20px;line-height:20px;border-radius:999px;background:#cfd7df;background-image:linear-gradient(#f1f5f9,#aeb8c1);box-shadow:0 5px 0 rgba(0,0,0,.18);font-size:1px;">&nbsp;</div></td></tr><tr><td width="112" align="center" valign="top" style="padding-top:8px;"><div style="width:104px;height:84px;border-radius:999px;background:#ffffff;color:#0b1d3a;text-align:center;padding-top:20px;box-shadow:0 20px 42px rgba(0,0,0,.22);"><div style="font-size:24px;color:' . $attr('accent', '#f47c20') . ';font-weight:700;line-height:1;">' . $esc('visualTop', 'FREE') . '</div><div style="font-size:12px;font-weight:700;padding-top:5px;line-height:1.25;">' . $esc('visualLine1', 'Landing Page') . '<br>' . $esc('visualLine2', 'Audit') . '</div></div></td><td width="106" align="center" valign="top"><div style="width:62px;height:74px;border-left:22px solid #b7c0c7;border-right:22px solid #b7c0c7;border-bottom:22px solid #b7c0c7;border-top:0;border-radius:0 0 58px 58px;line-height:1px;font-size:1px;">&nbsp;</div></td></tr></table></td></tr></table>';
    };
    $diceFace = function($pips, $rotate = 0) {
        $src = count($pips) === 4 ? 'dice-four.png' : 'dice-five.png';
        return '<img src="assets/uploads/mailpilot-icons/' . $src . '" width="142" height="142" alt="" style="display:block;width:142px;height:142px;border:0;outline:0;text-decoration:none;margin:0 auto;" />';
    };
    $sparkBars = function($color, $heights) {
        $bars = '';
        foreach ($heights as $height) {
            $bars .= '<td valign="bottom" align="center" style="padding:0 3px;"><div style="width:18px;height:' . (int)$height . 'px;background:' . mailpilotBuilderAttr($color) . ';border-radius:6px 6px 0 0;line-height:' . (int)$height . 'px;font-size:1px;">&nbsp;</div></td>';
        }

        return '<table role="presentation" width="100%" height="86" cellspacing="0" cellpadding="0" border="0" style="height:86px;border-left:2px solid rgba(255,255,255,.6);border-bottom:2px solid rgba(255,255,255,.28);"><tr>' . $bars . '</tr></table>';
    };

    if ($type === 'premiumPlumberHeader') {
        $padding = mailpilotBuilderNum($get('padding', 26));
        return '<tr><td class="mp-light-bg" bgcolor="' . $attr('bg', '#ffffff') . '" style="padding:' . $padding . 'px 28px 18px 28px;background:' . $attr('bg', '#ffffff') . ';background-image:linear-gradient(' . $attr('bg', '#ffffff') . ',' . $attr('bg', '#ffffff') . ');font-family:' . mailpilotBuilderAttr($fontStack) . ';">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                <td align="left" style="font-family:' . mailpilotBuilderAttr($fontStack) . ';"><div style="font-size:24px;font-weight:700;letter-spacing:0;color:' . $attr('color', '#0b1d3a') . ';line-height:.72;">' . $esc('brand', 'Abdullah') . '<span style="color:' . $attr('dotColor', '#f47c20') . ';">.</span><br><span style="font-size:7px;font-weight:700;letter-spacing:.28em;color:' . $attr('muted', '#8fa3bf') . ';text-transform:uppercase;">' . $esc('tagline', 'GROWTH EXPERT') . '</span></div></td>
                <td align="right" class="mp-hide-mobile" style="font-size:12px;color:' . $attr('muted', '#8fa3bf') . ';font-weight:700;">' . $esc('rightText') . '</td>
            </tr></table>
        </td></tr>';
    }

    if ($type === 'premiumPlumberHeroScore') {
        $padding = mailpilotBuilderNum($get('padding', 28));
        $accent = $attr('accent', '#f47c20');
        $muted = $attr('muted', '#8fa3bf');
        $stat = function($title, $text, $icon) use ($accent, $muted, $iconHtml) {
            return '<td width="33.33%" align="left" valign="top" style="padding-right:8px;">' . $iconHtml($icon, '#fff5eb', $accent, 50, 24, 15, '0 0 11px 0') . '<div style="font-size:12px;font-weight:700;color:#ffffff;">' . mailpilotBuilderEsc($title) . '</div><div style="font-size:10px;line-height:1.45;color:' . $muted . ';">' . mailpilotBuilderEsc($text) . '</div></td>';
        };
        $row = function($title, $text, $icon, $border = true) use ($accent, $iconHtml) {
            return '<tr><td style="padding:8px 0;' . ($border ? 'border-bottom:1px solid #dde5f0;' : '') . '"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td width="42">' . $iconHtml($icon, '#fff5eb', $accent, 34, 19, 10, '0') . '</td><td><div style="font-size:11px;font-weight:700;color:#0b1d3a;line-height:1.25;">' . mailpilotBuilderEsc($title) . '</div><div style="font-size:9px;color:#4a6080;line-height:1.35;">' . mailpilotBuilderEsc($text) . '</div></td></tr></table></td></tr>';
        };
        $cardImage = trim((string)$get('cardImageUrl', ''));
        $cardImageAttr = mailpilotBuilderAttr($cardImage);
        $cardBackgroundAttribute = $cardImage !== '' ? ' background="' . $cardImageAttr . '"' : '';
        $cardBackgroundStyle = $cardImage !== ''
            ? 'background-color:#0b1d3a;background-image:linear-gradient(90deg,rgba(11,29,58,.98) 0%,rgba(11,29,58,.90) 42%,rgba(11,29,58,.42) 100%),url(&quot;' . $cardImageAttr . '&quot;);background-repeat:no-repeat;background-position:center right;background-size:cover;'
            : 'background-color:#0b1d3a;background-image:linear-gradient(90deg,#0b1d3a,#132d52);';
        return '<tr><td style="background:' . $attr('bg', '#0b1d3a') . ';background-image:linear-gradient(140deg,' . $attr('bg', '#0b1d3a') . ' 0%,' . $attr('bg2', '#0f2a55') . ' 58%,#0e1e3e 100%);color:#ffffff;padding:' . $padding . 'px 28px 32px 28px;font-family:' . mailpilotBuilderAttr($fontStack) . ';">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                <td class="mp-stack" width="54%" valign="top" style="padding-right:20px;">
                    <span style="display:inline-block;padding:7px 13px;border-radius:999px;background:#fff5eb;color:' . $accent . ';font-size:10px;font-weight:700;line-height:1;letter-spacing:.12em;text-transform:uppercase;border:1px solid #fbd6bd;">' . $esc('pill') . '</span>
                    <div style="font-size:24px;line-height:1.10;font-weight:700;letter-spacing:-1.2px;margin:0;padding-top:22px;color:#ffffff;">' . $esc('title') . ' <span style="color:#f99148;">' . $esc('titleAccent') . '</span></div>
                    <div style="font-size:14px;line-height:1.72;margin:0;padding-top:18px;color:#dbeafe;">' . $lines('text') . '</div>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:30px;"><tr>' . $stat($get('stat1Title'), $get('stat1Text'), 'hero-more-leads') . $stat($get('stat2Title'), $get('stat2Text'), 'hero-lower-cost') . $stat($get('stat3Title'), $get('stat3Text'), 'hero-more-booked') . '</tr></table>
                    <div style="padding-top:24px;"><a href="' . $attr('heroButtonUrl', 'https://calendly.com/mu-abdullahhashmi/30min?utm_source=mailpilot&utm_medium=email&utm_campaign=plumber_premium_leak_audit') . '" style="display:inline-block;background:' . $accent . ';color:#ffffff;text-decoration:none;padding:13px 17px;border-radius:8px;font-size:10px;line-height:1.3;font-weight:700;margin:0 7px 7px 0;">' . $esc('heroButtonText', 'Book Free Audit') . ' &#8594;</a><a href="' . $attr('heroSecondaryButtonUrl', 'https://abdullahhashmi.com/plumbers-growth-expert/?utm_source=mailpilot&utm_medium=email&utm_campaign=plumber_premium_how_it_works') . '" style="display:inline-block;background:#ffffff;color:#0b1d3a;text-decoration:none;padding:12px 16px;border-radius:8px;border:1px solid #dbeafe;font-size:10px;line-height:1.3;font-weight:700;margin:0 0 7px 0;">' . $esc('heroSecondaryButtonText', 'See How It Works') . '</a></div>
                    <div style="margin-top:19px;height:1px;background:rgba(255,255,255,.14);line-height:1px;font-size:1px;">&nbsp;</div>
                    <div style="padding-top:14px;color:' . $muted . ';font-size:12px;line-height:1.55;"><span style="width:7px;height:7px;display:inline-block;border-radius:50%;background:#fb923c;vertical-align:middle;margin-right:8px;"></span>' . $esc('note') . '</div>
                </td>
                <td class="mp-stack mp-mobile-top" width="46%" valign="top">
                    <div class="mp-light-bg" style="background:#ffffff;background-image:linear-gradient(#ffffff,#ffffff);border:1px solid #dde5f0;border-radius:18px;overflow:hidden;box-shadow:0 20px 55px rgba(8,24,50,.14);">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"' . $cardBackgroundAttribute . ' style="' . $cardBackgroundStyle . '"><tr><td valign="top" style="padding:17px 20px 22px 20px;height:154px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td><span style="display:inline-block;background:rgba(244,124,32,.16);color:#fb923c;border:1px solid rgba(251,146,60,.32);padding:6px 9px;border-radius:999px;font-size:8px;letter-spacing:.11em;font-weight:700;text-transform:uppercase;">' . $esc('cardPill') . '</span></td><td align="right" style="font-size:10px;color:#dbeafe;font-weight:700;text-shadow:0 1px 2px rgba(0,0,0,.45);">' . $esc('cardMeta') . '</td></tr></table>
                            <div style="font-size:20px;line-height:1.14;margin:20px 0 0 0;font-weight:700;letter-spacing:-.7px;color:#ffffff;max-width:165px;text-shadow:0 1px 3px rgba(0,0,0,.5);">' . $lines('cardTitle') . '</div>
                            <div style="font-size:11px;line-height:1.48;margin:10px 0 0 0;color:#dbeafe;max-width:175px;text-shadow:0 1px 3px rgba(0,0,0,.5);">' . $lines('cardText') . '</div>
                        </td></tr></table>
                        <div class="mp-light-bg" style="padding:18px 18px 14px 18px;background:#ffffff;background-image:linear-gradient(#ffffff,#ffffff);"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td width="38%" align="center" valign="middle" style="padding-right:12px;"><div style="width:98px;height:98px;border-radius:50%;background:' . $accent . ';padding:7px;box-shadow:0 18px 40px rgba(244,124,32,.18);"><div style="width:84px;height:84px;border-radius:50%;background:#ffffff;background-image:linear-gradient(#ffffff,#ffffff);text-align:center;padding-top:22px;"><div style="font-size:24px;line-height:1;font-weight:700;letter-spacing:-1px;color:#0b1d3a;">' . $esc('score') . '</div><div style="font-size:11px;font-weight:700;color:#4a6080;">/ 100</div></div></div><div style="font-size:8px;color:#4a6080;font-weight:700;padding-top:8px;letter-spacing:.08em;text-transform:uppercase;line-height:1.3;">' . $esc('scoreLabel') . '</div></td><td width="62%" valign="middle"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $row($get('check1Title'), $get('check1Text'), 'scorecard-cta-visibility') . $row($get('check2Title'), $get('check2Text'), 'scorecard-mobile-quote') . $row($get('check3Title'), $get('check3Text'), 'scorecard-trust-proof', false) . '</table></td></tr></table></div>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#0d2347;color:#ffffff;"><tr><td align="center" style="padding:11px 8px;border-right:1px solid rgba(255,255,255,.12);font-size:10px;font-weight:700;color:#ffffff;"><span style="color:' . $accent . ';font-size:18px;">' . $iconSymbol('scorecard-calls', $accent, 18) . '</span><br><span style="font-size:8px;color:' . $muted . ';font-weight:700;">' . $esc('bottom1') . '</span></td><td align="center" style="padding:11px 8px;border-right:1px solid rgba(255,255,255,.12);font-size:10px;font-weight:700;color:#ffffff;"><span style="color:' . $accent . ';font-size:18px;">' . $iconSymbol('scorecard-quotes', $accent, 18) . '</span><br><span style="font-size:8px;color:' . $muted . ';font-weight:700;">' . $esc('bottom2') . '</span></td><td align="center" style="padding:11px 8px;font-size:10px;font-weight:700;color:#ffffff;"><span style="color:' . $accent . ';font-size:18px;">' . $iconSymbol('hero-more-booked', $accent, 18) . '</span><br><span style="font-size:8px;color:' . $muted . ';font-weight:700;">' . $esc('bottom3') . '</span></td></tr></table>
                    </div>
                </td>
            </tr></table>
        </td></tr>';
    }

    if ($type === 'premiumPlumberFindings') {
        $padding = mailpilotBuilderNum($get('padding', 32));
        $accent = $attr('accent', '#f47c20');
        $item = function($text, $label, $last = false) use ($accent) {
            return '<tr><td style="background:rgba(255,255,255,.065);border-radius:12px;padding:15px 16px;color:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td width="56"><div style="width:44px;height:44px;border-radius:50%;background:#fff5eb;color:' . $accent . ';line-height:44px;text-align:center;font-size:15px;font-weight:700;">' . mailpilotBuilderEsc($label) . '</div></td><td style="font-size:13px;line-height:1.45;color:#ffffff;font-weight:700;">' . mailpilotBuilderEsc($text) . '</td></tr></table></td></tr>' . ($last ? '' : '<tr><td height="10" style="font-size:1px;line-height:10px;">&nbsp;</td></tr>');
        };
        return '<tr><td style="padding:' . $padding . 'px 28px;background:' . $attr('bg', '#0b1d3a') . ';background-image:linear-gradient(145deg,' . $attr('bg', '#0b1d3a') . ' 0%,' . $attr('bg2', '#0f2a55') . ' 100%);border-radius:0 0 8px 8px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td class="mp-stack" width="36%" valign="middle" style="padding-right:22px;"><div style="font-size:10px;color:#fb923c;font-weight:700;letter-spacing:.16em;text-transform:uppercase;">' . $esc('eyebrow') . '</div><div style="font-size:15px;line-height:1.18;font-weight:700;letter-spacing:-.45px;color:#ffffff;padding-top:12px;">' . $lines('title') . '</div><div style="width:66px;height:2px;background:' . $accent . ';margin:15px 0 18px 0;line-height:1px;font-size:1px;">&nbsp;</div><div style="font-size:12px;line-height:1.55;color:#dde5f0;">' . $lines('text') . '</div></td><td class="mp-stack mp-mobile-top" width="64%" valign="middle"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $item($get('item1'), '1') . $item($get('item2'), '2') . $item($get('item3'), '3', true) . '</table></td></tr></table></td></tr>';
    }

    if ($type === 'premiumPlumberProcess') {
        $padding = mailpilotBuilderNum($get('padding', 32));
        $accent = $attr('accent', '#f47c20');
        $card = function($title, $text, $icon, $last = false) use ($accent, $iconHtml) {
            return '<td class="mp-stack ' . ($last ? '' : 'mp-process-border') . '" width="25%" align="center" valign="top" style="padding:0 11px;' . ($last ? '' : 'border-right:1px solid #dde5f0;') . '">' . $iconHtml($icon, '#fff5eb', $accent, 48, 21, 15) . '<div style="font-size:11px;font-weight:700;color:#1e3048;">' . mailpilotBuilderEsc($title) . '</div><div style="font-size:10px;line-height:1.45;color:#4a6080;padding-top:5px;">' . mailpilotBuilderLines($text) . '</div></td>';
        };
        return '<tr><td style="padding:' . $padding . 'px 28px;background:#ffffff;text-align:center;"><div style="font-size:10px;color:#fb923c;font-weight:700;letter-spacing:.18em;text-transform:uppercase;">' . $esc('eyebrow') . '</div><div style="font-size:15px;line-height:1.18;font-weight:700;letter-spacing:-.45px;color:#1e3048;padding-top:9px;">' . $esc('title') . '</div><div style="font-size:14px;line-height:1.72;color:#4a6080;padding:14px 28px 26px 28px;">' . $lines('text') . '</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>' . $card($get('item1Title'), $get('item1Text'), 'process-conversion-focused') . $card($get('item2Title'), $get('item2Text'), 'process-speed-optimized') . $card($get('item3Title'), $get('item3Text'), 'process-trust-built-in') . $card($get('item4Title'), $get('item4Text'), 'process-data-driven', true) . '</tr></table></td></tr>';
    }

    if ($type === 'premiumPlumberIncludes') {
        $padding = mailpilotBuilderNum($get('padding', 28));
        $accent = $attr('accent', '#f47c20');
        $item = function($text, $icon, $last = false) use ($accent, $iconSymbol) {
            return '<td width="25%" align="center" valign="top" style="' . ($last ? '' : 'border-right:1px solid #dde5f0;') . 'padding:0 7px;"><div style="font-size:28px;line-height:1;color:' . $accent . ';font-weight:700;">' . $iconSymbol($icon, $accent, 28) . '</div><div style="font-size:10px;line-height:1.45;font-weight:700;color:#0b1d3a;padding-top:8px;">' . mailpilotBuilderLines($text) . '</div></td>';
        };
        return '<tr><td style="padding:0 28px ' . $padding . 'px 28px;background:#ffffff;"><div style="border:1px solid #dde5f0;border-radius:14px;padding:20px 12px;background:#ffffff;box-shadow:0 10px 34px rgba(9,17,22,.04);text-align:center;"><div style="font-size:16px;line-height:1.35;font-weight:700;color:#1e3048;padding-bottom:17px;">' . $esc('title') . '</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>' . $item($get('item1'), '+') . $item($get('item2'), 'M') . $item($get('item3'), 'F') . $item($get('item4'), 'C', true) . '</tr></table></div></td></tr>';
    }

    if ($type === 'premiumPlumberFinalCta') {
        $padding = mailpilotBuilderNum($get('padding', 28));
        $accent = $attr('accent', '#f47c20');
        return '<tr><td class="mp-cta-wrap" style="padding:0 28px ' . $padding . 'px 28px;background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:' . $attr('bg', '#fff5eb') . ';border-radius:18px;border:1px solid ' . $attr('border', '#fbd6bd') . ';border-collapse:separate;table-layout:fixed;"><tr><td class="mp-stack mp-cta-cell" width="62%" align="left" valign="middle" style="box-sizing:border-box;padding:24px 22px;"><div class="mp-cta-title" style="font-size:15px;line-height:1.28;font-weight:700;letter-spacing:0;color:#1e3048;">' . $esc('title') . '</div><div class="mp-cta-copy" style="font-size:12px;line-height:1.55;color:#4a6080;padding-top:8px;">' . $lines('text') . '</div></td><td class="mp-stack mp-center-mobile mp-cta-action" width="38%" align="right" valign="middle" style="box-sizing:border-box;padding:24px 22px;"><a class="mp-cta-button" href="' . $attr('buttonUrl', '#') . '" style="display:inline-block;background:' . $accent . ';color:#ffffff;text-decoration:none;padding:15px 18px;border-radius:8px;font-size:10px;line-height:1.35;font-weight:700;letter-spacing:0;box-shadow:0 12px 30px rgba(244,124,32,.24);white-space:normal;">' . $esc('buttonText') . ' &#8594;</a><div style="font-size:10px;line-height:1.45;color:#4a6080;padding-top:8px;text-align:center;">' . $esc('note') . '</div></td></tr></table></td></tr>';
    }

    if ($type === 'premiumPlumberFooter') {
        $padding = mailpilotBuilderNum($get('padding', 26));
        $accent = $attr('accent', '#f47c20');
        return '<tr><td style="padding:' . $padding . 'px 28px 20px 28px;background:' . $attr('bg', '#f7f9fc') . ';border-top:1px solid #dde5f0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td class="mp-stack" width="48%" valign="top" style="padding-right:18px;"><div style="font-size:23px;font-weight:700;letter-spacing:0;color:#0b1d3a;line-height:.72;">' . $esc('brand', 'Abdullah') . '<span style="color:' . $accent . ';">.</span><br><span style="font-size:7px;font-weight:700;letter-spacing:.28em;color:#8fa3bf;text-transform:uppercase;">' . $esc('tagline', 'GROWTH EXPERT') . '</span></div><div style="font-size:12px;line-height:1.55;color:' . $attr('muted', '#4a6080') . ';padding-top:14px;">' . $lines('text') . '</div></td><td class="mp-hide-mobile" width="4%" style="border-left:1px solid #dde5f0;font-size:1px;line-height:1px;">&nbsp;</td><td class="mp-stack mp-mobile-top" width="48%" valign="top" style="padding-left:24px;"><div style="font-size:16px;line-height:1.35;font-weight:700;color:#1e3048;">' . $esc('title') . '</div><table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:12px;"><tr><td style="font-size:18px;padding:4px 10px 4px 0;color:' . $accent . ';font-weight:700;">' . $iconSymbol('P', $accent, 18) . '</td><td style="font-size:12px;line-height:1.55;color:#0d2347;">' . $esc('phone') . '</td></tr></table></td></tr></table><div style="font-size:10px;line-height:1.45;color:' . $attr('muted', '#4a6080') . ';text-align:center;padding-top:24px;margin:0;">' . $lines('note') . '</div></td></tr>';
    }

    if ($type === 'premiumFunnel') {
        return '';
    }

    if ($type === 'premiumLeakHero') {
        $padding = mailpilotBuilderNum($get('padding', 0));
        $accent = $attr('accent', '#f47c20');
        $buttonBg = $attr('buttonBg', '#f47c20');
        return '<tr><td style="padding:' . $padding . 'px 28px 28px 28px;background:#ffffff;background-image:linear-gradient(#ffffff,#ffffff);font-family:' . mailpilotBuilderAttr($fontStack) . ';"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $attr('bg', '#0b1d3a') . ';border-radius:18px;border-collapse:separate;overflow:hidden;color:#ffffff;"><tr><td align="center" valign="middle" style="padding:42px 34px;text-align:center;"><div style="font-size:23px;line-height:1.18;font-weight:700;letter-spacing:-.45px;color:#ffffff;">' . $esc('titleLine1') . '<br>' . $esc('titleLine2') . ' <span style="color:' . $accent . ';">' . $esc('titleAccent') . '</span></div><div style="font-size:16px;line-height:1.72;color:#dde5f0;padding-top:18px;max-width:430px;margin:0 auto;">' . $lines('text') . '</div><div style="padding-top:24px;"><a href="' . $attr('buttonUrl', '#') . '" style="display:inline-block;background:' . $buttonBg . ';color:#ffffff;text-decoration:none;padding:15px 24px;border-radius:8px;font-size:12px;font-weight:700;letter-spacing:.01em;box-shadow:0 12px 30px rgba(244,124,32,.24);">' . $esc('buttonText') . ' &#8594;</a></div></td></tr></table></td></tr>';
    }

    if ($type === 'premiumImpactDice') {
        $padding = mailpilotBuilderNum($get('padding', 18));
        $accent = $attr('accent', '#f47c20');
        $buttonBg = $attr('buttonBg', '#0a1f3d');
        return '<tr><td class="mp-light-bg" style="padding:' . $padding . 'px 28px;background:#ffffff;background-image:linear-gradient(#ffffff,#ffffff);font-family:' . mailpilotBuilderAttr($fontStack) . ';"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="mp-soft-bg" style="border:1px solid #d6e1ee;border-radius:20px;border-collapse:separate;overflow:hidden;background-color:#f7fbff;background-image:linear-gradient(#f7fbff,#f7fbff);"><tr><td align="center" style="padding:44px 42px 26px 42px;"><div style="font-size:34px;line-height:1.14;font-weight:700;color:#20334f;margin-bottom:18px;"><span style="font-style:italic;">' . $esc('smallWord', 'Small') . '</span> ' . $esc('smallTail', 'moves.') . '<br><span style="font-style:italic;color:' . $accent . ';">' . $esc('bigWord', 'Big') . '</span>' . $esc('bigTail', ' impact.') . '</div><div style="font-size:17px;line-height:1.7;color:#4d6485;margin:0 auto 28px auto;max-width:470px;">' . $lines('text') . '</div><a href="' . $attr('buttonUrl', '#') . '" style="display:inline-block;background:' . $buttonBg . ';color:#ffffff;text-decoration:none;padding:16px 24px;border-radius:8px;font-size:15px;line-height:1;font-weight:700;">' . $esc('buttonText', 'Start Today') . '</a><table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:24px auto 0 auto;"><tr><td style="padding:0 0 0 0;">' . $diceFace([1, 3, 5, 7, 9], -9) . '</td><td style="padding:0 0 0 4px;">' . $diceFace([1, 3, 7, 9], 7) . '</td></tr></table></td></tr></table></td></tr>';
    }

    if ($type === 'premiumCompare') {
        $padding = mailpilotBuilderNum($get('padding', 18));
        $accent = $attr('accent', '#ff8a32');
        return '<tr><td style="padding:' . $padding . 'px 28px 26px 28px;background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $attr('bg', '#0a1f3d') . ';border-radius:24px;border-collapse:separate;overflow:hidden;"><tr><td align="center" style="padding:32px 28px 10px 28px;font-size:38px;line-height:1.05;font-weight:700;color:#ffffff;">' . $esc('title', 'Convert or Leak..?') . '</td></tr><tr><td style="padding:10px 28px 30px 28px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;"><tr><td class="mp-stack mp-mobile-edge" width="50%" valign="top" style="padding:0 12px 0 0;"><div style="background:#294360;border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:24px 22px;color:#ffffff;"><div style="font-size:19px;font-weight:700;color:' . $accent . ';margin-bottom:22px;">' . $esc('leftLabel') . '</div><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px;height:58px;border:10px solid ' . $accent . ';border-radius:50%;text-align:center;line-height:58px;font-size:20px;font-weight:700;">' . $esc('leftPercent') . '</div></td><td><div style="font-size:18px;line-height:1.18;font-weight:700;">' . $esc('leftTitle') . '</div><div style="font-size:14px;line-height:1.45;color:#cbd7e8;">' . $esc('leftText') . '</div></td></tr></table><div style="margin-top:24px;">' . $sparkBars($accent, [16, 28, 34, 39, 55, 48, 61, 66, 78]) . '</div></div></td><td class="mp-stack mp-mobile-edge mp-mobile-gap" width="50%" valign="top" style="padding:0 0 0 12px;"><div style="background:#294360;border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:24px 22px;color:#ffffff;"><div style="font-size:19px;font-weight:700;color:#d5deeb;margin-bottom:22px;">' . $esc('rightLabel') . '</div><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px;height:58px;border:10px solid #9aa8ba;border-radius:50%;text-align:center;line-height:58px;font-size:20px;font-weight:700;">' . $esc('rightPercent') . '</div></td><td><div style="font-size:18px;line-height:1.18;font-weight:700;">' . $esc('rightTitle') . '</div><div style="font-size:14px;line-height:1.45;color:#cbd7e8;">' . $esc('rightText') . '</div></td></tr></table><div style="margin-top:24px;">' . $sparkBars('#ffffff', [76, 61, 56, 47, 38, 31, 23, 18, 10]) . '</div></div></td></tr></table></td></tr></table></td></tr>';
    }

    return null;
}

function mailpilotBuilderBlockHtml($block, $state) {
    $type = $block['type'] ?? '';
    $accent = mailpilotBuilderSetting($state, 'accent', '#2563eb');

    $premiumBlockHtml = mailpilotBuilderPremiumBlockHtml($block, $state);
    if ($premiumBlockHtml !== null) {
        return $premiumBlockHtml;
    }

    if ($type === 'brandHeader') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 18);
        return '<tr><td style="background:' . mailpilotBuilderAttr($block['bg'] ?? '#0f172a') . '; color:' . mailpilotBuilderAttr($block['color'] ?? '#ffffff') . '; padding:' . $padding . 'px 28px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;"><tr>
                <td align="left" style="font-size:17px; font-weight:bold; color:' . mailpilotBuilderAttr($block['color'] ?? '#ffffff') . ';">' . mailpilotBuilderEsc($block['brand'] ?? '') . '</td>
                <td align="right" style="font-size:13px; color:' . mailpilotBuilderAttr($block['color'] ?? '#ffffff') . ';">' . mailpilotBuilderEsc($block['label'] ?? '') . '</td>
            </tr></table>
        </td></tr>';
    }

    if ($type === 'hero') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 46);
        $align = $block['align'] ?? 'left';
        $image = !empty($block['imageUrl']) ? '<img src="' . mailpilotBuilderAttr($block['imageUrl']) . '" alt="" width="572" style="display:block; width:100%; max-width:572px; height:auto; border:0; margin:0 auto 22px;">' : '';
        $primary = !empty($block['buttonText']) ? '<a href="' . mailpilotBuilderAttr($block['buttonUrl'] ?? '#') . '" style="display:inline-block; background:' . mailpilotBuilderAttr($accent) . '; color:#ffffff; text-decoration:none; padding:13px 22px; border-radius:4px; font-weight:bold;">' . mailpilotBuilderEsc($block['buttonText']) . '</a>' : '';
        $secondary = !empty($block['secondaryButtonText']) ? '<a href="' . mailpilotBuilderAttr($block['secondaryButtonUrl'] ?? '#') . '" style="display:inline-block; background:' . mailpilotBuilderAttr($block['secondaryButtonBg'] ?? '#ffffff') . '; color:' . mailpilotBuilderAttr($block['secondaryButtonColor'] ?? '#111827') . '; text-decoration:none; padding:12px 20px; border-radius:4px; font-weight:bold; margin:4px;">' . mailpilotBuilderEsc($block['secondaryButtonText']) . '</a>' : '';
        return '<tr><td align="' . mailpilotBuilderAttr($align) . '" style="background:' . mailpilotBuilderAttr($block['bg'] ?? '#0f172a') . '; color:' . mailpilotBuilderAttr($block['textColor'] ?? '#ffffff') . '; padding:' . $padding . 'px 34px; text-align:' . mailpilotBuilderAttr($align) . ';">
            ' . $image . '
            <div style="font-size:12px; font-weight:bold; letter-spacing:1px; color:' . mailpilotBuilderAttr($accent) . '; margin-bottom:10px;">' . mailpilotBuilderEsc($block['eyebrow'] ?? '') . '</div>
            <div style="font-size:34px; line-height:1.15; font-weight:bold; margin-bottom:14px;">' . mailpilotBuilderEsc($block['title'] ?? '') . '</div>
            <div style="font-size:16px; line-height:1.65; margin-bottom:22px;">' . mailpilotBuilderLines($block['subtitle'] ?? '') . '</div>
            ' . $primary . $secondary . '
        </td></tr>';
    }

    if ($type === 'text') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 30);
        $align = $block['align'] ?? 'left';
        $fontSize = mailpilotBuilderNum($block['fontSize'] ?? 16, 16);
        return '<tr><td align="' . mailpilotBuilderAttr($align) . '" style="padding:' . $padding . 'px 34px; text-align:' . mailpilotBuilderAttr($align) . '; color:' . mailpilotBuilderAttr($block['color'] ?? '#0f172a') . '; font-size:' . $fontSize . 'px; line-height:1.7;">' . mailpilotBuilderLines($block['content'] ?? '') . '</td></tr>';
    }

    if ($type === 'auditGrid') {
        $card = function($icon, $title, $text) use ($block) {
            return '<td width="50%" valign="top" style="padding:7px;"><div style="border:1px solid ' . mailpilotBuilderAttr($block['border'] ?? '#dbe3ef') . '; background:' . mailpilotBuilderAttr($block['cardBg'] ?? '#f8fafc') . '; border-radius:10px; padding:18px;">
                ' . mailpilotBuilderIconBadge($icon, $block['iconBg'] ?? '#eff6ff', $block['iconColor'] ?? '#2563eb', 44, 19, 12, '0 0 14px 0') . '
                <div style="font-weight:bold; color:#0f172a; margin-bottom:8px;">' . mailpilotBuilderEsc($title) . '</div>
                <div style="font-size:13px; color:#475569; line-height:1.55;">' . mailpilotBuilderLines($text) . '</div>
            </div></td>';
        };
        $padding = mailpilotBuilderNum($block['padding'] ?? 26);
        return '<tr><td style="padding:' . $padding . 'px 27px; background:' . mailpilotBuilderAttr($block['bg'] ?? '#ffffff') . ';">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                <tr>' . $card($block['item1Icon'] ?? '', $block['item1Title'] ?? '', $block['item1Text'] ?? '') . $card($block['item2Icon'] ?? '', $block['item2Title'] ?? '', $block['item2Text'] ?? '') . '</tr>
                <tr>' . $card($block['item3Icon'] ?? '', $block['item3Title'] ?? '', $block['item3Text'] ?? '') . $card($block['item4Icon'] ?? '', $block['item4Title'] ?? '', $block['item4Text'] ?? '') . '</tr>
            </table>
        </td></tr>';
    }

    if ($type === 'checklistPanel') {
        $items = '';
        foreach (['item1', 'item2', 'item3', 'item4'] as $key) {
            if (!empty($block[$key])) {
                $items .= '<tr><td style="border-top:1px solid ' . mailpilotBuilderAttr($block['lineColor'] ?? '#26364f') . '; padding:12px 0; font-size:14px; color:' . mailpilotBuilderAttr($block['color'] ?? '#ffffff') . ';"><span style="color:' . mailpilotBuilderAttr($block['accent'] ?? $accent) . '; font-weight:bold;">&#10003;</span> ' . mailpilotBuilderEsc($block[$key]) . '</td></tr>';
            }
        }
        $padding = mailpilotBuilderNum($block['padding'] ?? 28);
        return '<tr><td style="padding:' . $padding . 'px 30px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . mailpilotBuilderAttr($block['bg'] ?? '#0f172a') . '; color:' . mailpilotBuilderAttr($block['color'] ?? '#ffffff') . '; border-radius:12px; border-collapse:separate;">
                <tr><td style="padding:24px 24px 0 24px; font-size:21px; line-height:1.3; font-weight:bold; color:' . mailpilotBuilderAttr($block['color'] ?? '#ffffff') . ';">' . mailpilotBuilderEsc($block['title'] ?? '') . '</td></tr>
                <tr><td style="padding:12px 24px 16px 24px; font-size:14px; line-height:1.7; color:' . mailpilotBuilderAttr($block['color'] ?? '#ffffff') . ';">' . mailpilotBuilderLines($block['intro'] ?? '') . '</td></tr>
                <tr><td style="padding:0 24px 18px 24px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $items . '</table></td></tr>
            </table>
        </td></tr>';
    }

    if ($type === 'metricBars') {
        $metric = function($label, $before, $after, $note) use ($block) {
            return '<td width="33.33%" align="center" valign="top" style="padding:0 8px;">
                <div style="font-size:11px; color:' . mailpilotBuilderAttr($block['muted'] ?? '#a8b5c7') . '; font-weight:bold; letter-spacing:1px; text-transform:uppercase; margin-bottom:12px;">' . mailpilotBuilderEsc($label) . '</div>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="height:116px;"><tr>
                    <td valign="bottom" style="padding:0 5px;"><div style="width:34px; height:38px; background:#64748b; border-radius:5px 5px 0 0; color:#ffffff; font-size:11px; font-weight:bold; padding-top:5px;">' . mailpilotBuilderEsc($before) . '</div></td>
                    <td valign="bottom" style="padding:0 5px;"><div style="width:40px; height:92px; background:' . mailpilotBuilderAttr($block['accent'] ?? '#f97316') . '; border-radius:5px 5px 0 0; color:#ffffff; font-size:11px; font-weight:bold; padding-top:5px;">' . mailpilotBuilderEsc($after) . '</div></td>
                </tr></table>
                <div style="display:inline-block; border:1px solid rgba(251,146,60,.45); background:rgba(251,146,60,.14); color:' . mailpilotBuilderAttr($block['accent'] ?? '#f97316') . '; border-radius:20px; padding:7px 10px; font-size:12px; font-weight:bold;">' . mailpilotBuilderEsc($note) . '</div>
            </td>';
        };
        $padding = mailpilotBuilderNum($block['padding'] ?? 32);
        return '<tr><td style="padding:' . $padding . 'px 30px; background:' . mailpilotBuilderAttr($block['bg'] ?? '#17345a') . '; color:' . mailpilotBuilderAttr($block['color'] ?? '#ffffff') . ';">
            <div style="font-size:22px; font-weight:bold; margin-bottom:8px; color:' . mailpilotBuilderAttr($block['color'] ?? '#ffffff') . ';">' . mailpilotBuilderEsc($block['title'] ?? '') . '</div>
            <div style="font-size:13px; color:' . mailpilotBuilderAttr($block['muted'] ?? '#a8b5c7') . '; margin-bottom:24px;">' . mailpilotBuilderEsc($block['subtitle'] ?? '') . '</div>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;"><tr>
                ' . $metric($block['metric1Label'] ?? '', $block['metric1Before'] ?? '', $block['metric1After'] ?? '', $block['metric1Note'] ?? '') . '
                ' . $metric($block['metric2Label'] ?? '', $block['metric2Before'] ?? '', $block['metric2After'] ?? '', $block['metric2Note'] ?? '') . '
                ' . $metric($block['metric3Label'] ?? '', $block['metric3Before'] ?? '', $block['metric3After'] ?? '', $block['metric3Note'] ?? '') . '
            </tr></table>
        </td></tr>';
    }

    if ($type === 'browserAudit') {
        $issues = '';
        foreach (['issue1', 'issue2', 'issue3', 'issue4'] as $key) {
            if (!empty($block[$key])) {
                $issues .= '<div style="background:' . mailpilotBuilderAttr($block['warningBg'] ?? '#fef2f2') . '; color:' . mailpilotBuilderAttr($block['warningColor'] ?? '#dc2626') . '; border:1px solid #fecaca; border-radius:8px; padding:13px; font-size:14px; margin-bottom:10px;">&times; ' . mailpilotBuilderEsc($block[$key]) . '</div>';
            }
        }
        $padding = mailpilotBuilderNum($block['padding'] ?? 28);
        return '<tr><td style="padding:' . $padding . 'px 30px; background:' . mailpilotBuilderAttr($block['bg'] ?? '#ffffff') . ';">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse; margin-bottom:12px;"><tr>
                <td align="left" style="font-size:12px; letter-spacing:2px; text-transform:uppercase; font-weight:bold; color:#8aa0c8;">' . mailpilotBuilderEsc($block['label'] ?? '') . '</td>
                <td align="right"><span style="background:#ef4444; color:#ffffff; border-radius:7px; padding:9px 12px; font-weight:bold;">' . mailpilotBuilderEsc($block['score'] ?? '') . '</span></td>
            </tr></table>
            <div style="border:1px solid #dbe3ef; background:' . mailpilotBuilderAttr($block['chromeBg'] ?? '#e5eaf1') . '; border-radius:10px; padding:14px; margin-bottom:16px;">
                <div style="font-size:12px; color:#64748b; background:#ffffff; display:inline-block; padding:5px 14px; border-radius:4px; margin-bottom:12px;">' . mailpilotBuilderEsc($block['domain'] ?? '') . '</div>
                <div style="height:86px; background:#d5dce5; border-radius:8px; margin-bottom:12px;"></div>
                <div style="height:8px; background:#d5dce5; border-radius:8px; margin-bottom:8px; width:96%;"></div>
                <div style="height:8px; background:#d5dce5; border-radius:8px; margin-bottom:8px; width:74%;"></div>
                <div style="height:8px; background:#d5dce5; border-radius:8px; width:56%;"></div>
            </div>' . $issues . '
        </td></tr>';
    }

    if ($type === 'ctaPanel') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 30);
        $secondary = !empty($block['secondaryButtonText']) ? '<a href="' . mailpilotBuilderAttr($block['secondaryButtonUrl'] ?? '#') . '" style="display:inline-block; background:' . mailpilotBuilderAttr($block['secondaryButtonBg'] ?? '#ffffff') . '; color:' . mailpilotBuilderAttr($block['secondaryButtonColor'] ?? '#0f172a') . '; border:1px solid ' . mailpilotBuilderAttr($block['border'] ?? '#bfdbfe') . '; text-decoration:none; padding:12px 20px; border-radius:7px; font-weight:bold; margin:4px;">' . mailpilotBuilderEsc($block['secondaryButtonText']) . '</a>' : '';
        return '<tr><td align="center" style="padding:' . $padding . 'px 30px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . mailpilotBuilderAttr($block['bg'] ?? '#eff6ff') . '; border:1px solid ' . mailpilotBuilderAttr($block['border'] ?? '#bfdbfe') . '; border-radius:12px; border-collapse:separate;">
                <tr><td align="center" style="padding:28px; color:' . mailpilotBuilderAttr($block['color'] ?? '#0f172a') . ';">
                    <div style="font-size:24px; line-height:1.2; font-weight:bold; margin-bottom:12px;">' . mailpilotBuilderEsc($block['title'] ?? '') . '</div>
                    <div style="font-size:14px; line-height:1.7; margin-bottom:20px;">' . mailpilotBuilderLines($block['text'] ?? '') . '</div>
                    <a href="' . mailpilotBuilderAttr($block['buttonUrl'] ?? '#') . '" style="display:inline-block; background:' . mailpilotBuilderAttr($block['buttonBg'] ?? $accent) . '; color:#ffffff; text-decoration:none; padding:13px 22px; border-radius:7px; font-weight:bold; margin:4px;">' . mailpilotBuilderEsc($block['buttonText'] ?? '') . '</a>
                    ' . $secondary . '
                </td></tr>
            </table>
        </td></tr>';
    }

    if ($type === 'premiumLeakHero') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 24);
        $bg = $block['bg'] ?? '#0a1f3d';
        $accentColor = $block['accent'] ?? '#ff7a1a';
        $buttonBg = $block['buttonBg'] ?? $accentColor;
        $textColor = $block['textColor'] ?? '#ffffff';
        return '<tr><td style="padding:' . $padding . 'px 28px 18px 28px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . mailpilotBuilderAttr($bg) . '; border-radius:24px; border-collapse:separate; overflow:hidden;">
                <tr>
                    <td class="mp-stack mp-pad-mobile" width="52%" valign="middle" style="padding:34px 12px 34px 28px; color:' . mailpilotBuilderAttr($textColor) . ';">
                        <div style="font-size:25px; line-height:1.08; font-weight:700; color:' . mailpilotBuilderAttr($textColor) . '; margin-bottom:22px;">' . mailpilotBuilderEsc($block['titleLine1'] ?? '') . '<br>' . mailpilotBuilderEsc($block['titleLine2'] ?? '') . ' <span style="color:' . mailpilotBuilderAttr($accentColor) . ';">' . mailpilotBuilderEsc($block['titleAccent'] ?? '') . '</span></div>
                        <div style="font-size:17px; line-height:1.65; color:' . mailpilotBuilderAttr($block['muted'] ?? '#d9e6f8') . '; margin-bottom:26px;">' . mailpilotBuilderLines($block['text'] ?? '') . '</div>
                        <a href="' . mailpilotBuilderAttr($block['buttonUrl'] ?? '#') . '" style="display:inline-block; background:' . mailpilotBuilderAttr($buttonBg) . '; color:#ffffff; text-decoration:none; padding:16px 24px; border-radius:8px; font-size:15px; line-height:1; font-weight:700;">' . mailpilotBuilderEsc($block['buttonText'] ?? '') . ' &#8594;</a>
                    </td>
                    <td class="mp-stack mp-pad-mobile mp-mobile-top" width="48%" valign="middle" style="padding:24px 28px 24px 0;">
                        <div style="position:relative; min-height:178px; background:#102641; overflow:hidden;">
                            <div style="position:absolute; right:20px; top:46px; width:160px; height:17px; border-radius:20px; background:#cfd7df; box-shadow:0 3px 0 rgba(0,0,0,.35) inset;"></div>
                            <div style="position:absolute; right:53px; top:60px; width:70px; height:88px; border:26px solid #b8c3cc; border-top:0; border-radius:0 0 62px 62px;"></div>
                            <div style="position:absolute; left:28px; top:60px; width:118px; height:118px; border-radius:70px; background:#ffffff; text-align:center;">
                                <div style="padding-top:27px; color:' . mailpilotBuilderAttr($accentColor) . '; font-size:26px; line-height:1; font-weight:700;">' . mailpilotBuilderEsc($block['visualTop'] ?? 'FREE') . '</div>
                                <div style="margin-top:6px; color:#0a1f3d; font-size:15px; line-height:1.25; font-weight:700;">' . mailpilotBuilderEsc($block['visualLine1'] ?? 'Landing Page') . '<br>' . mailpilotBuilderEsc($block['visualLine2'] ?? 'Audit') . '</div>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
        </td></tr>';
    }

    if ($type === 'premiumFunnel') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 18);
        $bg = $block['bg'] ?? '#071b34';
        $accentColor = $block['accent'] ?? '#ff7a1a';
        $blue = $block['blue'] ?? '#3367ff';
        return '<tr><td style="padding:' . $padding . 'px 28px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . mailpilotBuilderAttr($bg) . '; border-radius:24px; border-collapse:separate; overflow:hidden;">
                <tr>
                    <td class="mp-stack mp-pad-mobile" width="32%" valign="middle" style="padding:38px 12px 38px 28px;">
                        <div style="font-size:23px; line-height:1.14; font-weight:700; color:#ffffff; margin-bottom:24px;">' . mailpilotBuilderEsc($block['titleLine1'] ?? '') . '<br><span style="color:' . mailpilotBuilderAttr($accentColor) . ';">' . mailpilotBuilderEsc($block['titleAccent'] ?? '') . '</span></div>
                        <div style="font-size:19px; line-height:1.65; color:#9fb1ca;">' . mailpilotBuilderLines($block['text'] ?? '') . '</div>
                    </td>
                    <td class="mp-stack mp-pad-mobile mp-mobile-top" width="35%" valign="middle" align="center" style="padding:28px 6px;">
                        <div style="position:relative; width:220px; max-width:100%; height:178px; margin:0 auto;">
                            <div style="position:absolute; left:25px; top:20px; width:170px; height:118px; background:' . mailpilotBuilderAttr($blue) . '; clip-path:polygon(0 0,100% 0,68% 100%,32% 100%);"></div>
                            <div style="position:absolute; left:82px; top:118px; width:56px; height:58px; border-radius:0 0 20px 20px; background:#123b96;"></div>
                            <div style="position:absolute; left:17px; top:13px; transform:rotate(-6deg); background:#ffffff; color:#0f172a; border-radius:5px; padding:10px 17px; font-size:14px; font-weight:700;">' . mailpilotBuilderEsc($block['labelOne'] ?? 'Traffic') . '</div>
                            <div style="position:absolute; right:0; top:14px; transform:rotate(5deg); background:#ffffff; color:#0f172a; border-radius:5px; padding:10px 17px; font-size:14px; font-weight:700;">' . mailpilotBuilderEsc($block['labelTwo'] ?? 'CTAs') . '</div>
                            <div style="position:absolute; left:78px; top:70px; transform:rotate(-3deg); background:#ffffff; color:#0f172a; border-radius:5px; padding:10px 18px; font-size:14px; font-weight:700;">' . mailpilotBuilderEsc($block['labelThree'] ?? 'Follow-up') . '</div>
                        </div>
                    </td>
                    <td class="mp-stack mp-pad-mobile mp-mobile-top" width="33%" valign="middle" style="padding:34px 28px 34px 12px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                            <tr><td style="padding:0 0 12px 0; border-bottom:1px solid rgba(255,255,255,.12);"><div style="font-size:13px; color:#ffffff; font-weight:700;">' . mailpilotBuilderEsc($block['step1Title'] ?? '') . '</div><div style="font-size:12px; color:#9fb1ca;">' . mailpilotBuilderEsc($block['step1Text'] ?? '') . '</div></td></tr>
                            <tr><td style="padding:12px 0; border-bottom:1px solid rgba(255,255,255,.12);"><div style="font-size:13px; color:#ffffff; font-weight:700;">' . mailpilotBuilderEsc($block['step2Title'] ?? '') . '</div><div style="font-size:12px; color:#9fb1ca;">' . mailpilotBuilderEsc($block['step2Text'] ?? '') . '</div></td></tr>
                            <tr><td style="padding:12px 0; border-bottom:1px solid rgba(255,255,255,.12);"><div style="font-size:13px; color:#ffffff; font-weight:700;">' . mailpilotBuilderEsc($block['step3Title'] ?? '') . '</div><div style="font-size:12px; color:#9fb1ca;">' . mailpilotBuilderEsc($block['step3Text'] ?? '') . '</div></td></tr>
                            <tr><td style="padding:12px 0 0 0;"><div style="font-size:13px; color:#ffffff; font-weight:700;">' . mailpilotBuilderEsc($block['step4Title'] ?? '') . '</div><div style="font-size:12px; color:#9fb1ca;">' . mailpilotBuilderEsc($block['step4Text'] ?? '') . '</div></td></tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td></tr>';
    }

    if ($type === 'premiumImpactDice') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 18);
        $accentColor = $block['accent'] ?? '#ff7a1a';
        $buttonBg = $block['buttonBg'] ?? '#0a1f3d';
        return '<tr><td style="padding:' . $padding . 'px 28px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #d6e1ee; border-radius:20px; border-collapse:separate; overflow:hidden; background-color:#f7fbff; background-image:linear-gradient(#d9e5f2 1px, transparent 1px), linear-gradient(90deg, #d9e5f2 1px, transparent 1px); background-size:32px 32px;">
                <tr><td align="center" style="padding:44px 42px 20px 42px;">
                    <div style="font-size:34px; line-height:1.14; font-weight:700; color:#20334f; margin-bottom:18px;"><span style="font-style:italic;">' . mailpilotBuilderEsc($block['smallWord'] ?? 'Small') . '</span> ' . mailpilotBuilderEsc($block['smallTail'] ?? 'moves.') . '<br><span style="font-style:italic; color:' . mailpilotBuilderAttr($accentColor) . ';">' . mailpilotBuilderEsc($block['bigWord'] ?? 'Big') . '</span>' . mailpilotBuilderEsc($block['bigTail'] ?? ' impact.') . '</div>
                    <div style="font-size:17px; line-height:1.7; color:#4d6485; margin:0 auto 28px auto; max-width:470px;">' . mailpilotBuilderLines($block['text'] ?? '') . '</div>
                    <a href="' . mailpilotBuilderAttr($block['buttonUrl'] ?? '#') . '" style="display:inline-block; background:' . mailpilotBuilderAttr($buttonBg) . '; color:#ffffff; text-decoration:none; padding:16px 24px; border-radius:8px; font-size:15px; line-height:1; font-weight:700;">' . mailpilotBuilderEsc($block['buttonText'] ?? '') . '</a>
                    <div style="position:relative; height:168px; max-width:270px; margin:26px auto 0 auto;">
                        <div style="position:absolute; left:20px; top:25px; width:116px; height:116px; border-radius:18px; background:#ffffff; box-shadow:0 18px 32px rgba(31,55,84,.15); transform:rotate(-11deg);">
                            <span style="position:absolute; left:28px; top:31px; width:17px; height:17px; background:#111111; border-radius:50%;"></span>
                            <span style="position:absolute; right:28px; top:20px; width:17px; height:17px; background:#111111; border-radius:50%;"></span>
                            <span style="position:absolute; left:56px; top:59px; width:17px; height:17px; background:#111111; border-radius:50%;"></span>
                            <span style="position:absolute; left:31px; bottom:23px; width:17px; height:17px; background:#111111; border-radius:50%;"></span>
                            <span style="position:absolute; right:21px; bottom:30px; width:17px; height:17px; background:#111111; border-radius:50%;"></span>
                        </div>
                        <div style="position:absolute; right:22px; top:28px; width:116px; height:116px; border-radius:18px; background:#ffffff; box-shadow:0 18px 32px rgba(31,55,84,.15); transform:rotate(7deg);">
                            <span style="position:absolute; left:28px; top:26px; width:17px; height:17px; background:#111111; border-radius:50%;"></span>
                            <span style="position:absolute; right:25px; top:40px; width:17px; height:17px; background:#111111; border-radius:50%;"></span>
                            <span style="position:absolute; left:23px; bottom:33px; width:17px; height:17px; background:#111111; border-radius:50%;"></span>
                            <span style="position:absolute; right:34px; bottom:24px; width:17px; height:17px; background:#111111; border-radius:50%;"></span>
                        </div>
                    </div>
                </td></tr>
            </table>
        </td></tr>';
    }

    if ($type === 'premiumCompare') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 18);
        $bg = $block['bg'] ?? '#0a1f3d';
        $accentColor = $block['accent'] ?? '#ff8a32';
        return '<tr><td style="padding:' . $padding . 'px 28px 26px 28px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . mailpilotBuilderAttr($bg) . '; border-radius:24px; border-collapse:separate; overflow:hidden;">
                <tr><td align="center" style="padding:32px 28px 10px 28px; font-size:38px; line-height:1.05; font-weight:700; color:#ffffff;">' . mailpilotBuilderEsc($block['title'] ?? '') . '</td></tr>
                <tr><td style="padding:10px 28px 30px 28px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;">
                        <tr>
                            <td class="mp-stack mp-mobile-edge" width="50%" valign="top" style="padding:0 12px 0 0;">
                                <div style="background:#294360; border:1px solid rgba(255,255,255,.12); border-radius:18px; padding:24px 22px; color:#ffffff;">
                                    <div style="font-size:19px; font-weight:700; color:' . mailpilotBuilderAttr($accentColor) . '; margin-bottom:22px;">' . mailpilotBuilderEsc($block['leftLabel'] ?? '') . '</div>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px; height:58px; border:10px solid ' . mailpilotBuilderAttr($accentColor) . '; border-radius:50%; text-align:center; line-height:58px; font-size:20px; font-weight:700;">' . mailpilotBuilderEsc($block['leftPercent'] ?? '') . '</div></td><td><div style="font-size:18px; line-height:1.18; font-weight:700;">' . mailpilotBuilderEsc($block['leftTitle'] ?? '') . '</div><div style="font-size:14px; line-height:1.45; color:#cbd7e8;">' . mailpilotBuilderEsc($block['leftText'] ?? '') . '</div></td></tr></table>
                                    <div style="position:relative; height:86px; margin-top:24px; border-left:2px solid rgba(255,255,255,.6); border-bottom:2px solid rgba(255,255,255,.25);">
                                        <div style="position:absolute; left:0; bottom:12px; width:58px; height:4px; background:' . mailpilotBuilderAttr($accentColor) . '; transform:rotate(-25deg); transform-origin:left center;"></div>
                                        <div style="position:absolute; left:52px; bottom:32px; width:72px; height:4px; background:' . mailpilotBuilderAttr($accentColor) . '; transform:rotate(-6deg); transform-origin:left center;"></div>
                                        <div style="position:absolute; left:118px; bottom:40px; width:54px; height:4px; background:' . mailpilotBuilderAttr($accentColor) . '; transform:rotate(-18deg); transform-origin:left center;"></div>
                                        <div style="position:absolute; left:165px; bottom:57px; width:76px; height:4px; background:' . mailpilotBuilderAttr($accentColor) . '; transform:rotate(-7deg); transform-origin:left center;"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="mp-stack mp-mobile-edge mp-mobile-gap" width="50%" valign="top" style="padding:0 0 0 12px;">
                                <div style="background:#294360; border:1px solid rgba(255,255,255,.12); border-radius:18px; padding:24px 22px; color:#ffffff;">
                                    <div style="font-size:19px; font-weight:700; color:#d5deeb; margin-bottom:22px;">' . mailpilotBuilderEsc($block['rightLabel'] ?? '') . '</div>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px; height:58px; border:10px solid #9aa8ba; border-radius:50%; text-align:center; line-height:58px; font-size:20px; font-weight:700;">' . mailpilotBuilderEsc($block['rightPercent'] ?? '') . '</div></td><td><div style="font-size:18px; line-height:1.18; font-weight:700;">' . mailpilotBuilderEsc($block['rightTitle'] ?? '') . '</div><div style="font-size:14px; line-height:1.45; color:#cbd7e8;">' . mailpilotBuilderEsc($block['rightText'] ?? '') . '</div></td></tr></table>
                                    <div style="position:relative; height:86px; margin-top:24px; border-left:2px solid rgba(255,255,255,.6); border-bottom:2px solid rgba(255,255,255,.55);">
                                        <div style="position:absolute; left:0; bottom:68px; width:52px; height:4px; background:#ffffff; transform:rotate(25deg); transform-origin:left center;"></div>
                                        <div style="position:absolute; left:46px; bottom:48px; width:70px; height:4px; background:#ffffff; transform:rotate(6deg); transform-origin:left center;"></div>
                                        <div style="position:absolute; left:111px; bottom:39px; width:57px; height:4px; background:#ffffff; transform:rotate(18deg); transform-origin:left center;"></div>
                                        <div style="position:absolute; left:160px; bottom:23px; width:78px; height:4px; background:#ffffff; transform:rotate(7deg); transform-origin:left center;"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </td></tr>
            </table>
        </td></tr>';
    }

    if ($type === 'signature') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 24);
        return '<tr><td style="padding:' . $padding . 'px 30px; background:' . mailpilotBuilderAttr($block['bg'] ?? '#ffffff') . '; color:' . mailpilotBuilderAttr($block['color'] ?? '#0f172a') . ';">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-top:1px solid #e2e8f0; padding-top:20px; border-collapse:collapse;"><tr>
                <td width="52" valign="top"><div style="width:42px; height:42px; border-radius:50%; background:#0f172a; color:#ffffff; text-align:center; line-height:42px; font-weight:bold;">' . mailpilotBuilderEsc($block['avatarText'] ?? '') . '</div></td>
                <td valign="top">
                    <div style="font-weight:bold; color:' . mailpilotBuilderAttr($block['color'] ?? '#0f172a') . ';">' . mailpilotBuilderEsc($block['name'] ?? '') . '</div>
                    <div style="font-size:13px; color:' . mailpilotBuilderAttr($block['muted'] ?? '#64748b') . ';">' . mailpilotBuilderEsc($block['title'] ?? '') . '</div>
                    <div style="font-size:13px; color:' . mailpilotBuilderAttr($accent) . ';">' . mailpilotBuilderEsc($block['website'] ?? '') . '</div>
                </td>
            </tr></table>
            <div style="font-size:11px; line-height:1.6; color:' . mailpilotBuilderAttr($block['muted'] ?? '#64748b') . '; text-align:center; margin-top:22px;">' . mailpilotBuilderLines($block['note'] ?? '') . '</div>
        </td></tr>';
    }

    if ($type === 'html') {
        $padding = mailpilotBuilderNum($block['padding'] ?? 0);
        return '<tr><td style="padding:' . $padding . 'px 34px;">' . ($block['html'] ?? '') . '</td></tr>';
    }

    return '';
}

function mailpilotBuilderGenerateHtml($state) {
    $settings = $state['settings'] ?? [];
    if (($settings['mode'] ?? '') === 'rawHtml') {
        return (string)($state['rawHtml'] ?? '');
    }

    $font = $settings['font'] ?? 'Poppins';
    $fontStack = mailpilotBuilderFontStack($font);
    $bg = $settings['bg'] ?? '#f4f7fb';
    $contentBg = $settings['contentBg'] ?? '#ffffff';
    $width = mailpilotBuilderNum($settings['width'] ?? 640, 640);
    $rows = '';
    foreach (($state['blocks'] ?? []) as $block) {
        if (is_array($block)) {
            $rows .= mailpilotBuilderBlockHtml($block, $state);
        }
    }

    return '<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' . mailpilotBuilderFontImport($font) . mailpilotBuilderBaseCss($fontStack) . mailpilotBuilderResponsiveCss() . '</head>
<body style="margin:0; padding:0; background:' . mailpilotBuilderAttr($bg) . '; background-image:linear-gradient(' . mailpilotBuilderAttr($bg) . ',' . mailpilotBuilderAttr($bg) . '); font-family:' . mailpilotBuilderAttr($fontStack) . ';">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="' . mailpilotBuilderAttr($bg) . '" style="width:100%; background:' . mailpilotBuilderAttr($bg) . '; background-image:linear-gradient(' . mailpilotBuilderAttr($bg) . ',' . mailpilotBuilderAttr($bg) . '); border-collapse:collapse;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" class="mp-container" width="' . $width . '" cellspacing="0" cellpadding="0" border="0" bgcolor="' . mailpilotBuilderAttr($contentBg) . '" style="width:100%; max-width:' . $width . 'px; background:' . mailpilotBuilderAttr($contentBg) . '; background-image:linear-gradient(' . mailpilotBuilderAttr($contentBg) . ',' . mailpilotBuilderAttr($contentBg) . '); border-collapse:collapse; font-family:' . mailpilotBuilderAttr($fontStack) . ';">
' . $rows . '
</table>
</td></tr>
</table>
</body>
</html>';
}

function mailpilotBuilderStateToHtml($state) {
    return '<!--MAILPILOT_BUILDER ' . mailpilotBuilderEncodeState($state) . "-->\n" . mailpilotBuilderGenerateHtml($state);
}

function mailpilotRenderBuilderHtml($html, $force = false) {
    $state = mailpilotBuilderExtractState($html);
    if (!$state) {
        return $html;
    }

    if (($state['settings']['mode'] ?? '') === 'rawHtml') {
        return mailpilotBuilderGenerateHtml($state);
    }

    // Builder state is the source of truth. Regenerate so saved templates and
    // scheduled campaigns receive renderer fixes instead of stale embedded HTML.
    return mailpilotBuilderStateToHtml($state);
}

/**
 * Ensure campaigns can remember the selected contact batch.
 */
function ensureCampaignBatchColumn() {
    try {
        $exists = dbFetchValue("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'campaigns'
              AND COLUMN_NAME = 'contact_batch'
        ");

        if (!$exists) {
            dbExecute("ALTER TABLE `campaigns` ADD COLUMN `contact_batch` VARCHAR(100) DEFAULT NULL AFTER `contact_list_id`");
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function deleteCampaignsByIds($ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (empty($ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    ensureCampaignOpenTrackingTable();
    dbExecute("DELETE FROM click_tracking WHERE campaign_id IN ({$placeholders})", $ids);
    dbExecute("DELETE FROM campaign_open_tracking WHERE campaign_id IN ({$placeholders})", $ids);
    dbExecute("DELETE FROM email_queue WHERE campaign_id IN ({$placeholders})", $ids);

    return dbExecute("DELETE FROM campaigns WHERE id IN ({$placeholders})", $ids);
}

/**
 * Read the batch value from imported CSV custom fields.
 * Accepts common headers like batch_number, batch, Batch Number, batch no, and badge_number.
 */
function normalizeContactFieldKey($key) {
    return preg_replace('/[^a-z0-9]+/', '', strtolower((string)$key));
}

function isContactBatchFieldKey($key) {
    return in_array(normalizeContactFieldKey($key), [
        'batch',
        'batchnumber',
        'batchno',
        'batchnum',
        'badge',
        'badgenumber',
        'badgeno',
        'badgenum',
        'contactbatch',
        'contactbadge',
    ], true);
}

function decodeContactCustomFields($customFields) {
    if (is_array($customFields)) {
        return $customFields;
    }

    if (!is_string($customFields) || trim($customFields) === '') {
        return [];
    }

    $decoded = json_decode($customFields, true);
    if (is_string($decoded)) {
        $decoded = json_decode($decoded, true);
    }

    return is_array($decoded) ? $decoded : [];
}

function findContactBatchValue($fields) {
    if (!is_array($fields)) {
        return '';
    }

    foreach ($fields as $key => $value) {
        if (!isContactBatchFieldKey($key)) {
            continue;
        }

        if (!is_array($value)) {
            return trim((string)$value);
        }

        $nestedValues = collectContactScalarValues($value);
        if (!empty($nestedValues)) {
            return trim((string)$nestedValues[0]);
        }
    }

    foreach ($fields as $value) {
        if (!is_array($value)) {
            continue;
        }

        if (isset($value['name'], $value['value']) && isContactBatchFieldKey($value['name'])) {
            return trim((string)$value['value']);
        }

        if (isset($value['label'], $value['value']) && isContactBatchFieldKey($value['label'])) {
            return trim((string)$value['value']);
        }

        $nestedValue = findContactBatchValue($value);
        if ($nestedValue !== '') {
            return $nestedValue;
        }
    }

    return '';
}

function collectContactScalarValues($value) {
    $values = [];

    if (is_array($value)) {
        foreach ($value as $nestedValue) {
            $values = array_merge($values, collectContactScalarValues($nestedValue));
        }
        return $values;
    }

    if (is_scalar($value)) {
        $trimmed = trim((string)$value);
        if ($trimmed !== '') {
            $values[] = $trimmed;
        }
    }

    return $values;
}

function findContactBatchValueByValue($fields) {
    foreach (collectContactScalarValues($fields) as $value) {
        if (preg_match('/^batch[0-9]+$/', normalizeContactBatchValue($value))) {
            return $value;
        }
    }

    return '';
}

/**
 * Read the batch value from imported CSV custom fields or contact columns.
 * Accepts common headers like batch_number, batch, Batch Number, batch no, and badge_number.
 */
function getContactBatchValue($contact) {
    $value = findContactBatchValue($contact);
    if ($value !== '') {
        return $value;
    }

    if (array_key_exists('custom_fields', $contact)) {
        $customFields = decodeContactCustomFields($contact['custom_fields']);
        $value = findContactBatchValue($customFields);
        if ($value !== '') {
            return $value;
        }

        return findContactBatchValueByValue($customFields);
    }

    return '';
}

function summarizeContactCustomFieldKeys($contacts, $limit = 12) {
    $keys = [];

    foreach ($contacts as $contact) {
        foreach (decodeContactCustomFields($contact['custom_fields'] ?? null) as $key => $value) {
            if (is_int($key) && is_array($value)) {
                foreach (['name', 'label', 'key'] as $labelKey) {
                    if (!empty($value[$labelKey])) {
                        $keys[(string)$value[$labelKey]] = true;
                    }
                }
                continue;
            }

            $keys[(string)$key] = true;
        }

        foreach ($contact as $key => $value) {
            if (is_scalar($value) && isContactBatchFieldKey($key)) {
                $keys[(string)$key] = true;
            }
        }
    }

    $keys = array_keys($keys);
    natcasesort($keys);
    return array_slice(array_values($keys), 0, $limit);
}

/**
 * Normalize badge/batch labels for matching imported contacts to campaigns.
 * Examples that should match: "Batch 1", "batch1", "Batch 01", "1".
 */
function normalizeContactBatchValue($value) {
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], ' ', $value);
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value);
    $compact = preg_replace('/[^a-z0-9]+/', '', $value);

    if (preg_match('/^(batch|badge)0*([0-9]+)$/', $compact, $matches)) {
        return 'batch' . (int)$matches[2];
    }

    if (preg_match('/^0*([0-9]+)$/', $compact, $matches)) {
        return 'batch' . (int)$matches[1];
    }

    return $compact;
}

function contactBatchMatches($contact, $expectedBatch) {
    $expected = normalizeContactBatchValue($expectedBatch);
    if ($expected === '') {
        return true;
    }

    $batchValue = getContactBatchValue($contact);
    if ($batchValue !== '' && normalizeContactBatchValue($batchValue) === $expected) {
        return true;
    }

    foreach (collectContactScalarValues(decodeContactCustomFields($contact['custom_fields'] ?? null)) as $value) {
        if (normalizeContactBatchValue($value) === $expected) {
            return true;
        }
    }

    return false;
}

function summarizeContactBatchValues($contacts, $limit = 12) {
    $counts = [];
    foreach ($contacts as $contact) {
        $value = getContactBatchValue($contact);
        if ($value === '') {
            continue;
        }
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    uksort($counts, 'strnatcasecmp');
    $summary = [];
    foreach ($counts as $value => $count) {
        $summary[] = $value . ' (' . $count . ')';
        if (count($summary) >= $limit) {
            break;
        }
    }

    return $summary;
}

/**
 * Add a 1x1 campaign open tracking pixel to the final email HTML.
 */
function processOpenTracking($html, $campaignId, $contactId, $queueId) {
    ensureCampaignOpenTrackingTable();

    $existingToken = dbFetchValue(
        "SELECT tracking_token FROM campaign_open_tracking WHERE queue_id = ? LIMIT 1",
        [$queueId]
    );

    $token = $existingToken ?: generateTrackingToken();
    if (!$existingToken) {
        dbInsert(
            "INSERT INTO campaign_open_tracking (campaign_id, contact_id, queue_id, tracking_token)
             VALUES (?, ?, ?, ?)",
            [$campaignId, $contactId, $queueId, $token]
        );
    }

    $appUrl = APP_URL ?: getAppUrl();
    $pixelUrl = $appUrl . '/track/open.php?t=' . urlencode($token) . '&cb=' . urlencode((string) $queueId);
    $pixel = '<img src="' . e($pixelUrl) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;max-width:1px;max-height:1px;opacity:0;overflow:hidden;border:0;margin:0;padding:0;" />';

    if (stripos($html, 'track/open.php?t=') !== false) {
        return $html;
    }

    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $pixel . '</body>', $html, 1);
    }

    return $html . $pixel;
}

/**
 * Scan HTML for uploaded images and prepare CID mapping
 * Returns array of ['path' => filepath, 'cid' => content_id, 'name' => filename]
 */
function getEmbeddedImages($html) {
    $images = [];
    $uploadDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR;
    $uploadRoot = realpath($uploadDir);
    $seen = [];
    
    // Match img src pointing anywhere under assets/uploads/.
    preg_match_all('/src=["\']([^"\']*assets\/uploads\/([^"\']+))["\']/i', $html, $matches);
    
    if (!empty($matches[2])) {
        foreach ($matches[2] as $index => $filename) {
            $filename = rawurldecode(html_entity_decode($filename, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $filename = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filename);
            $filepath = realpath($uploadDir . $filename);

            if (
                $filepath === false
                || $uploadRoot === false
                || strncmp($filepath, $uploadRoot . DIRECTORY_SEPARATOR, strlen($uploadRoot . DIRECTORY_SEPARATOR)) !== 0
                || !is_file($filepath)
            ) {
                continue;
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($filepath, strlen($uploadRoot) + 1));
            if (isset($seen[$relativePath])) {
                continue;
            }

            $seen[$relativePath] = true;
            $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'gif' => 'image/gif',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
            ];
            $images[] = [
                'path' => $filepath,
                'cid' => 'mailpilot_' . md5($relativePath),
                'name' => basename($filepath),
                'type' => $mimeTypes[$extension] ?? 'application/octet-stream',
                'original_src' => $matches[1][$index],
                'original_src_pattern' => $relativePath,
            ];
        }
    }
    
    return $images;
}

/**
 * Replace image src URLs with CID references in HTML
 */
function replaceImagesWithCID($html, $images) {
    foreach ($images as $img) {
        if (!empty($img['original_src'])) {
            $html = str_replace($img['original_src'], 'cid:' . $img['cid'], $html);
        }

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
 * Attach local builder images and return HTML containing CID references.
 */
function embedImagesForMailer($mail, $html) {
    $images = getEmbeddedImages($html);
    foreach ($images as $img) {
        $added = $mail->addEmbeddedImage(
            $img['path'],
            $img['cid'],
            $img['name'],
            'base64',
            $img['type'],
            'inline'
        );
        if (!$added) {
            throw new RuntimeException('Could not embed email image: ' . $img['name']);
        }
    }

    return replaceImagesWithCID($html, $images);
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
