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
        'Poppins' => "'Poppins', Arial, Helvetica, sans-serif",
        'Montserrat' => "'Montserrat', Arial, Helvetica, sans-serif",
        'DM Sans' => "'DM Sans', Arial, Helvetica, sans-serif",
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
        return '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
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
@media screen and (max-width:680px) {
    .mp-container { width:100% !important; max-width:100% !important; }
    .mp-stack { display:block !important; width:100% !important; max-width:100% !important; }
    .mp-hide-mobile { display:none !important; }
    .mp-mobile-edge { padding-left:0 !important; padding-right:0 !important; }
    .mp-pad-mobile { padding-left:22px !important; padding-right:22px !important; }
    .mp-center-mobile { text-align:center !important; }
    .mp-mobile-top { padding-top:20px !important; }
    .mp-mobile-gap { padding-top:12px !important; }
    .mp-process-border { border-right:none !important; border-bottom:1px solid #dde5f0 !important; padding-top:16px !important; padding-bottom:16px !important; }
}
</style>';
}

function mailpilotBuilderBlockValue($block, $key, $default = '') {
    return $block[$key] ?? $default;
}

function mailpilotBuilderPremiumBlockHtml($block, $state) {
    $type = $block['type'] ?? '';
    $fontStack = mailpilotBuilderFontStack(mailpilotBuilderSetting($state, 'font', 'DM Sans'));
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
    $pipeGraphic = function() use ($esc, $attr) {
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#102641;background-image:linear-gradient(135deg,rgba(255,255,255,.08),rgba(255,255,255,0));"><tr><td align="center" style="padding:24px 10px;"><table role="presentation" width="218" cellspacing="0" cellpadding="0" border="0" style="width:218px;max-width:100%;border-collapse:separate;"><tr><td width="86">&nbsp;</td><td align="center" colspan="2"><div style="height:20px;line-height:20px;border-radius:999px;background:#cfd7df;background-image:linear-gradient(#f1f5f9,#aeb8c1);box-shadow:0 5px 0 rgba(0,0,0,.18);font-size:1px;">&nbsp;</div></td></tr><tr><td width="112" align="center" valign="top" style="padding-top:8px;"><div style="width:104px;height:84px;border-radius:999px;background:#ffffff;color:#0b1d3a;text-align:center;padding-top:20px;box-shadow:0 20px 42px rgba(0,0,0,.22);"><div style="font-size:24px;color:' . $attr('accent', '#f47c20') . ';font-weight:800;line-height:1;">' . $esc('visualTop', 'FREE') . '</div><div style="font-size:12px;font-weight:800;padding-top:5px;line-height:1.25;">' . $esc('visualLine1', 'Landing Page') . '<br>' . $esc('visualLine2', 'Audit') . '</div></div></td><td width="106" align="center" valign="top"><div style="width:62px;height:74px;border-left:22px solid #b7c0c7;border-right:22px solid #b7c0c7;border-bottom:22px solid #b7c0c7;border-top:0;border-radius:0 0 58px 58px;line-height:1px;font-size:1px;">&nbsp;</div></td></tr></table></td></tr></table>';
    };
    $diceFace = function($pips) {
        $rows = '';
        for ($row = 0; $row < 3; $row++) {
            $rows .= '<tr>';
            for ($col = 0; $col < 3; $col++) {
                $index = ($row * 3) + $col + 1;
                $pip = in_array($index, $pips, true) ? '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#111111;line-height:14px;font-size:1px;">&nbsp;</span>' : '&nbsp;';
                $rows .= '<td align="center" valign="middle" width="33.33%" height="27" style="font-size:1px;line-height:1px;">' . $pip . '</td>';
            }
            $rows .= '</tr>';
        }

        return '<table role="presentation" width="98" height="98" cellspacing="0" cellpadding="0" border="0" style="width:98px;height:98px;background:#ffffff;border-radius:18px;box-shadow:0 18px 32px rgba(31,55,84,.15);border-collapse:separate;">' . $rows . '</table>';
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
        return '<tr><td style="padding:' . $padding . 'px 28px 6px 28px;background:' . $attr('bg', '#ffffff') . ';">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                <td align="left" style="font-family:' . mailpilotBuilderAttr($fontStack) . ';"><div style="font-size:24px;font-weight:800;letter-spacing:0;color:' . $attr('color', '#0b1d3a') . ';line-height:.72;">' . $esc('brand', 'Abdullah') . '<span style="color:' . $attr('dotColor', '#f47c20') . ';">.</span><br><span style="font-size:7px;font-weight:700;letter-spacing:.28em;color:' . $attr('muted', '#8fa3bf') . ';text-transform:uppercase;">' . $esc('tagline', 'GROWTH EXPERT') . '</span></div></td>
                <td align="right" class="mp-hide-mobile" style="font-size:12px;color:' . $attr('muted', '#8fa3bf') . ';font-weight:700;">' . $esc('rightText') . '</td>
            </tr></table>
        </td></tr>';
    }

    if ($type === 'premiumPlumberHeroScore') {
        $padding = mailpilotBuilderNum($get('padding', 28));
        $accent = $attr('accent', '#f47c20');
        $muted = $attr('muted', '#8fa3bf');
        $stat = function($title, $text, $icon) use ($accent, $muted) {
            return '<td width="33.33%" align="left" valign="top" style="padding-right:8px;"><div style="width:42px;height:42px;border-radius:14px;background:#fff5eb;color:' . $accent . ';font-size:15px;line-height:42px;text-align:center;font-weight:800;margin:0 0 10px 0;">' . mailpilotBuilderEsc($icon) . '</div><div style="font-size:12px;font-weight:800;color:#ffffff;">' . mailpilotBuilderEsc($title) . '</div><div style="font-size:10px;line-height:1.45;color:' . $muted . ';">' . mailpilotBuilderEsc($text) . '</div></td>';
        };
        $row = function($title, $text, $icon, $border = true) use ($accent) {
            return '<tr><td style="padding:8px 0;' . ($border ? 'border-bottom:1px solid #dde5f0;' : '') . '"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td width="32"><div style="width:26px;height:26px;line-height:26px;border-radius:8px;background:#fff5eb;color:' . $accent . ';text-align:center;font-size:11px;font-weight:800;">' . mailpilotBuilderEsc($icon) . '</div></td><td><div style="font-size:11px;font-weight:800;color:#0b1d3a;line-height:1.25;">' . mailpilotBuilderEsc($title) . '</div><div style="font-size:9px;color:#4a6080;line-height:1.35;">' . mailpilotBuilderEsc($text) . '</div></td></tr></table></td></tr>';
        };
        return '<tr><td style="background:' . $attr('bg', '#0b1d3a') . ';background-image:linear-gradient(140deg,' . $attr('bg', '#0b1d3a') . ' 0%,' . $attr('bg2', '#0f2a55') . ' 58%,#0e1e3e 100%);color:#ffffff;padding:' . $padding . 'px 28px 32px 28px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                <td class="mp-stack" width="54%" valign="top" style="padding-right:20px;">
                    <span style="display:inline-block;padding:7px 13px;border-radius:999px;background:#fff5eb;color:' . $accent . ';font-size:10px;font-weight:800;line-height:1;letter-spacing:.12em;text-transform:uppercase;border:1px solid #fbd6bd;">' . $esc('pill') . '</span>
                    <div style="font-size:24px;line-height:1.10;font-weight:800;letter-spacing:-1.2px;margin:0;padding-top:22px;color:#ffffff;">' . $esc('title') . ' <span style="color:#f99148;">' . $esc('titleAccent') . '</span></div>
                    <div style="font-size:14px;line-height:1.72;margin:0;padding-top:18px;color:#dbeafe;">' . $lines('text') . '</div>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:30px;"><tr>' . $stat($get('stat1Title'), $get('stat1Text'), '+') . $stat($get('stat2Title'), $get('stat2Text'), '$') . $stat($get('stat3Title'), $get('stat3Text'), 'B') . '</tr></table>
                    <div style="margin-top:28px;height:1px;background:rgba(255,255,255,.14);line-height:1px;font-size:1px;">&nbsp;</div>
                    <div style="padding-top:14px;color:' . $muted . ';font-size:12px;line-height:1.55;"><span style="width:7px;height:7px;display:inline-block;border-radius:50%;background:#fb923c;vertical-align:middle;margin-right:8px;"></span>' . $esc('note') . '</div>
                </td>
                <td class="mp-stack mp-mobile-top" width="46%" valign="top">
                    <div style="background:#ffffff;border:1px solid #dde5f0;border-radius:18px;overflow:hidden;box-shadow:0 20px 55px rgba(8,24,50,.14);">
                        <div style="padding:17px 20px 16px 20px;background:#0b1d3a;background-image:linear-gradient(90deg,rgba(11,29,58,.98),rgba(11,29,58,.82));"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td><span style="display:inline-block;background:rgba(244,124,32,.16);color:#fb923c;border:1px solid rgba(251,146,60,.22);padding:6px 9px;border-radius:999px;font-size:8px;letter-spacing:.11em;font-weight:800;text-transform:uppercase;">' . $esc('cardPill') . '</span></td><td align="right" style="font-size:10px;color:' . $muted . ';font-weight:700;">' . $esc('cardMeta') . '</td></tr></table><div style="font-size:20px;line-height:1.14;margin:13px 0 0 0;font-weight:800;letter-spacing:-.7px;color:#ffffff;max-width:165px;">' . $lines('cardTitle') . '</div><div style="font-size:11px;line-height:1.48;margin:8px 0 0 0;color:' . $muted . ';max-width:175px;">' . $lines('cardText') . '</div></div>
                        <div style="padding:18px 18px 14px 18px;background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td width="38%" align="center" valign="middle" style="padding-right:12px;"><div style="width:98px;height:98px;border-radius:50%;background:' . $accent . ';padding:7px;box-shadow:0 18px 40px rgba(244,124,32,.18);"><div style="width:84px;height:84px;border-radius:50%;background:#ffffff;text-align:center;padding-top:22px;"><div style="font-size:24px;line-height:1;font-weight:800;letter-spacing:-1px;color:#0b1d3a;">' . $esc('score') . '</div><div style="font-size:11px;font-weight:800;color:#4a6080;">/ 100</div></div></div><div style="font-size:8px;color:#4a6080;font-weight:800;padding-top:8px;letter-spacing:.08em;text-transform:uppercase;line-height:1.3;">' . $esc('scoreLabel') . '</div></td><td width="62%" valign="middle"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $row($get('check1Title'), $get('check1Text'), 'C') . $row($get('check2Title'), $get('check2Text'), 'M') . $row($get('check3Title'), $get('check3Text'), 'T', false) . '</table></td></tr></table></div>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#0d2347;color:#ffffff;"><tr><td align="center" style="padding:11px 8px;border-right:1px solid rgba(255,255,255,.12);font-size:10px;font-weight:800;color:#ffffff;"><span style="color:' . $accent . ';">P</span><br><span style="font-size:8px;color:' . $muted . ';font-weight:700;">' . $esc('bottom1') . '</span></td><td align="center" style="padding:11px 8px;border-right:1px solid rgba(255,255,255,.12);font-size:10px;font-weight:800;color:#ffffff;"><span style="color:' . $accent . ';">Q</span><br><span style="font-size:8px;color:' . $muted . ';font-weight:700;">' . $esc('bottom2') . '</span></td><td align="center" style="padding:11px 8px;font-size:10px;font-weight:800;color:#ffffff;"><span style="color:' . $accent . ';">B</span><br><span style="font-size:8px;color:' . $muted . ';font-weight:700;">' . $esc('bottom3') . '</span></td></tr></table>
                    </div>
                </td>
            </tr></table>
        </td></tr>';
    }

    if ($type === 'premiumPlumberFindings') {
        $padding = mailpilotBuilderNum($get('padding', 32));
        $accent = $attr('accent', '#f47c20');
        $item = function($text, $label, $last = false) use ($accent) {
            return '<tr><td style="background:rgba(255,255,255,.065);border-radius:12px;padding:15px 16px;color:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td width="56"><div style="width:44px;height:44px;border-radius:50%;background:#fff5eb;color:' . $accent . ';line-height:44px;text-align:center;font-size:15px;font-weight:800;">' . mailpilotBuilderEsc($label) . '</div></td><td style="font-size:13px;line-height:1.45;color:#ffffff;font-weight:800;">' . mailpilotBuilderEsc($text) . '</td></tr></table></td></tr>' . ($last ? '' : '<tr><td height="10" style="font-size:1px;line-height:10px;">&nbsp;</td></tr>');
        };
        return '<tr><td style="padding:' . $padding . 'px 28px;background:' . $attr('bg', '#0b1d3a') . ';background-image:linear-gradient(145deg,' . $attr('bg', '#0b1d3a') . ' 0%,' . $attr('bg2', '#0f2a55') . ' 100%);border-radius:0 0 8px 8px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td class="mp-stack" width="36%" valign="middle" style="padding-right:22px;"><div style="font-size:10px;color:#fb923c;font-weight:800;letter-spacing:.16em;text-transform:uppercase;">' . $esc('eyebrow') . '</div><div style="font-size:15px;line-height:1.18;font-weight:800;letter-spacing:-.45px;color:#ffffff;padding-top:12px;">' . $lines('title') . '</div><div style="width:66px;height:2px;background:' . $accent . ';margin:15px 0 18px 0;line-height:1px;font-size:1px;">&nbsp;</div><div style="font-size:12px;line-height:1.55;color:#dde5f0;">' . $lines('text') . '</div></td><td class="mp-stack mp-mobile-top" width="64%" valign="middle"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $item($get('item1'), '1') . $item($get('item2'), '2') . $item($get('item3'), '3', true) . '</table></td></tr></table></td></tr>';
    }

    if ($type === 'premiumPlumberProcess') {
        $padding = mailpilotBuilderNum($get('padding', 32));
        $accent = $attr('accent', '#f47c20');
        $card = function($title, $text, $icon, $last = false) use ($accent) {
            return '<td class="mp-stack ' . ($last ? '' : 'mp-process-border') . '" width="25%" align="center" valign="top" style="padding:0 11px;' . ($last ? '' : 'border-right:1px solid #dde5f0;') . '"><div style="width:42px;height:42px;border-radius:14px;background:#fff5eb;color:' . $accent . ';font-size:16px;line-height:42px;text-align:center;font-weight:800;margin:0 auto 10px auto;">' . mailpilotBuilderEsc($icon) . '</div><div style="font-size:11px;font-weight:800;color:#1e3048;">' . mailpilotBuilderEsc($title) . '</div><div style="font-size:10px;line-height:1.45;color:#4a6080;padding-top:5px;">' . mailpilotBuilderLines($text) . '</div></td>';
        };
        return '<tr><td style="padding:' . $padding . 'px 28px;background:#ffffff;text-align:center;"><div style="font-size:10px;color:#fb923c;font-weight:800;letter-spacing:.18em;text-transform:uppercase;">' . $esc('eyebrow') . '</div><div style="font-size:15px;line-height:1.18;font-weight:800;letter-spacing:-.45px;color:#1e3048;padding-top:9px;">' . $esc('title') . '</div><div style="font-size:14px;line-height:1.72;color:#4a6080;padding:14px 28px 26px 28px;">' . $lines('text') . '</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>' . $card($get('item1Title'), $get('item1Text'), 'C') . $card($get('item2Title'), $get('item2Text'), 'S') . $card($get('item3Title'), $get('item3Text'), 'T') . $card($get('item4Title'), $get('item4Text'), 'D', true) . '</tr></table></td></tr>';
    }

    if ($type === 'premiumPlumberIncludes') {
        $padding = mailpilotBuilderNum($get('padding', 28));
        $accent = $attr('accent', '#f47c20');
        $item = function($text, $icon, $last = false) use ($accent) {
            return '<td width="25%" align="center" valign="top" style="' . ($last ? '' : 'border-right:1px solid #dde5f0;') . 'padding:0 7px;"><div style="font-size:24px;line-height:1;color:' . $accent . ';font-weight:800;">' . mailpilotBuilderEsc($icon) . '</div><div style="font-size:10px;line-height:1.45;font-weight:800;color:#0b1d3a;padding-top:8px;">' . mailpilotBuilderLines($text) . '</div></td>';
        };
        return '<tr><td style="padding:0 28px ' . $padding . 'px 28px;background:#ffffff;"><div style="border:1px solid #dde5f0;border-radius:14px;padding:20px 12px;background:#ffffff;box-shadow:0 10px 34px rgba(9,17,22,.04);text-align:center;"><div style="font-size:16px;line-height:1.35;font-weight:800;color:#1e3048;padding-bottom:17px;">' . $esc('title') . '</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>' . $item($get('item1'), '+') . $item($get('item2'), 'M') . $item($get('item3'), 'F') . $item($get('item4'), 'C', true) . '</tr></table></div></td></tr>';
    }

    if ($type === 'premiumPlumberFinalCta') {
        $padding = mailpilotBuilderNum($get('padding', 28));
        $accent = $attr('accent', '#f47c20');
        return '<tr><td style="padding:0 28px ' . $padding . 'px 28px;background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $attr('bg', '#fff5eb') . ';border-radius:18px;border:1px solid ' . $attr('border', '#fbd6bd') . ';border-collapse:separate;"><tr><td class="mp-stack" width="62%" style="padding:24px 22px;"><div style="font-size:15px;line-height:1.18;font-weight:800;letter-spacing:-.45px;color:#1e3048;">' . $esc('title') . '</div><div style="font-size:12px;line-height:1.55;color:#4a6080;padding-top:8px;">' . $lines('text') . '</div></td><td class="mp-stack mp-center-mobile" align="right" style="padding:24px 22px;"><a href="' . $attr('buttonUrl', '#') . '" style="display:inline-block;background:' . $accent . ';color:#ffffff;text-decoration:none;padding:15px 23px;border-radius:8px;font-size:10px;font-weight:800;letter-spacing:.01em;box-shadow:0 12px 30px rgba(244,124,32,.24);">' . $esc('buttonText') . ' &#8594;</a><div style="font-size:10px;line-height:1.45;color:#4a6080;padding-top:8px;">' . $esc('note') . '</div></td></tr></table></td></tr>';
    }

    if ($type === 'premiumPlumberFooter') {
        $padding = mailpilotBuilderNum($get('padding', 26));
        $accent = $attr('accent', '#f47c20');
        return '<tr><td style="padding:' . $padding . 'px 28px 20px 28px;background:' . $attr('bg', '#f7f9fc') . ';border-top:1px solid #dde5f0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td class="mp-stack" width="48%" valign="top" style="padding-right:18px;"><div style="font-size:23px;font-weight:800;letter-spacing:0;color:#0b1d3a;line-height:.72;">' . $esc('brand', 'Abdullah') . '<span style="color:' . $accent . ';">.</span><br><span style="font-size:7px;font-weight:700;letter-spacing:.28em;color:#8fa3bf;text-transform:uppercase;">' . $esc('tagline', 'GROWTH EXPERT') . '</span></div><div style="font-size:12px;line-height:1.55;color:' . $attr('muted', '#4a6080') . ';padding-top:14px;">' . $lines('text') . '</div></td><td class="mp-hide-mobile" width="4%" style="border-left:1px solid #dde5f0;font-size:1px;line-height:1px;">&nbsp;</td><td class="mp-stack mp-mobile-top" width="48%" valign="top" style="padding-left:24px;"><div style="font-size:16px;line-height:1.35;font-weight:800;color:#1e3048;">' . $esc('title') . '</div><table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:12px;"><tr><td style="font-size:14px;padding:4px 9px 4px 0;color:' . $accent . ';font-weight:800;">P</td><td style="font-size:12px;line-height:1.55;color:#0d2347;">' . $esc('phone') . '</td></tr></table></td></tr></table><div style="font-size:10px;line-height:1.45;color:' . $attr('muted', '#4a6080') . ';text-align:center;padding-top:24px;margin:0;">' . $lines('note') . '</div></td></tr>';
    }

    if ($type === 'premiumFunnel') {
        $accent = $attr('accent', '#f47c20');
        $blue = $attr('blue', '#3367ff');
        $step = function($title, $text, $last = false) {
            return '<tr><td style="font-size:10px;line-height:1.45;padding:8px 0;color:#ffffff;' . ($last ? '' : 'border-bottom:1px solid rgba(255,255,255,.12);') . '"><strong>' . mailpilotBuilderEsc($title) . '</strong><br><span style="color:#8fa3bf;">' . mailpilotBuilderEsc($text) . '</span></td></tr>';
        };
        return '<tr><td style="padding:0 28px 28px 28px;background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $attr('bg', '#071b34') . ';border-radius:18px;border-collapse:separate;overflow:hidden;color:#ffffff;"><tr><td class="mp-stack" width="38%" valign="middle" style="padding:28px 24px;"><div style="font-size:15px;line-height:1.18;font-weight:800;letter-spacing:-.45px;color:#ffffff;">' . $esc('titleLine1') . ' <span style="color:' . $accent . ';">' . $esc('titleAccent') . '</span></div><div style="font-size:14px;line-height:1.72;color:#8fa3bf;padding-top:16px;">' . $lines('text') . '</div></td><td class="mp-stack mp-mobile-top" width="34%" valign="middle" align="center" style="padding:22px 0;"><table role="presentation" width="218" cellspacing="0" cellpadding="0" border="0" align="center" style="width:218px;max-width:100%;"><tr><td align="center"><span style="display:inline-block;background:#ffffff;color:#0b1d3a;border-radius:6px;padding:7px 12px;font-size:12px;font-weight:800;margin:0 8px 8px 0;">' . $esc('labelOne') . '</span><span style="display:inline-block;background:#ffffff;color:#0b1d3a;border-radius:6px;padding:7px 12px;font-size:12px;font-weight:800;margin:0 0 8px 8px;">' . $esc('labelTwo') . '</span></td></tr><tr><td align="center" style="height:30px;background:' . $blue . ';border-radius:12px 12px 4px 4px;"></td></tr><tr><td align="center"><div style="width:154px;height:30px;background:#2b60eb;"></div></td></tr><tr><td align="center"><span style="display:inline-block;background:#ffffff;color:#0b1d3a;border-radius:6px;padding:7px 12px;font-size:12px;font-weight:800;margin:0 0 8px 0;">' . $esc('labelThree') . '</span></td></tr><tr><td align="center"><div style="width:72px;height:58px;background:#122444;border-radius:0 0 18px 18px;"></div></td></tr></table></td><td class="mp-stack mp-mobile-top" width="28%" valign="middle" style="padding:28px 24px 28px 4px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $step($get('step1Title'), $get('step1Text')) . $step($get('step2Title'), $get('step2Text')) . $step($get('step3Title'), $get('step3Text')) . $step($get('step4Title'), $get('step4Text'), true) . '</table></td></tr></table></td></tr>';
    }

    if ($type === 'premiumLeakHero') {
        $padding = mailpilotBuilderNum($get('padding', 0));
        $accent = $attr('accent', '#f47c20');
        $buttonBg = $attr('buttonBg', '#f47c20');
        return '<tr><td style="padding:' . $padding . 'px 28px 28px 28px;background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $attr('bg', '#0b1d3a') . ';border-radius:18px;border-collapse:separate;overflow:hidden;color:#ffffff;"><tr><td class="mp-stack" width="55%" valign="middle" style="padding:30px 26px;"><div style="font-size:15px;line-height:1.18;font-weight:800;letter-spacing:-.45px;color:#ffffff;">' . $esc('titleLine1') . '<br>' . $esc('titleLine2') . ' <span style="color:' . $accent . ';">' . $esc('titleAccent') . '</span></div><div style="font-size:14px;line-height:1.72;color:#dde5f0;padding-top:14px;">' . $lines('text') . '</div><div style="padding-top:20px;"><a href="' . $attr('buttonUrl', '#') . '" style="display:inline-block;background:' . $buttonBg . ';color:#ffffff;text-decoration:none;padding:15px 23px;border-radius:8px;font-size:10px;font-weight:800;letter-spacing:.01em;box-shadow:0 12px 30px rgba(244,124,32,.24);">' . $esc('buttonText') . ' &#8594;</a></div></td><td class="mp-stack" width="45%" valign="middle">' . $pipeGraphic() . '</td></tr></table></td></tr>';
    }

    if ($type === 'premiumImpactDice') {
        $padding = mailpilotBuilderNum($get('padding', 18));
        $accent = $attr('accent', '#f47c20');
        $buttonBg = $attr('buttonBg', '#0a1f3d');
        return '<tr><td style="padding:' . $padding . 'px 28px;background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #d6e1ee;border-radius:20px;border-collapse:separate;overflow:hidden;background-color:#f7fbff;background-image:linear-gradient(#d9e5f2 1px, transparent 1px),linear-gradient(90deg,#d9e5f2 1px, transparent 1px);background-size:32px 32px;"><tr><td align="center" style="padding:44px 42px 20px 42px;"><div style="font-size:34px;line-height:1.14;font-weight:800;color:#20334f;margin-bottom:18px;"><span style="font-style:italic;">' . $esc('smallWord', 'Small') . '</span> ' . $esc('smallTail', 'moves.') . '<br><span style="font-style:italic;color:' . $accent . ';">' . $esc('bigWord', 'Big') . '</span>' . $esc('bigTail', ' impact.') . '</div><div style="font-size:17px;line-height:1.7;color:#4d6485;margin:0 auto 28px auto;max-width:470px;">' . $lines('text') . '</div><a href="' . $attr('buttonUrl', '#') . '" style="display:inline-block;background:' . $buttonBg . ';color:#ffffff;text-decoration:none;padding:16px 24px;border-radius:8px;font-size:15px;line-height:1;font-weight:800;">' . $esc('buttonText', 'Start Today') . '</a><table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:30px auto 0 auto;"><tr><td style="padding:10px 0 0 0;">' . $diceFace([1, 3, 5, 7, 9]) . '</td><td style="padding:0 0 0 10px;">' . $diceFace([1, 3, 7, 9]) . '</td></tr></table></td></tr></table></td></tr>';
    }

    if ($type === 'premiumCompare') {
        $padding = mailpilotBuilderNum($get('padding', 18));
        $accent = $attr('accent', '#ff8a32');
        return '<tr><td style="padding:' . $padding . 'px 28px 26px 28px;background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $attr('bg', '#0a1f3d') . ';border-radius:24px;border-collapse:separate;overflow:hidden;"><tr><td align="center" style="padding:32px 28px 10px 28px;font-size:38px;line-height:1.05;font-weight:800;color:#ffffff;">' . $esc('title', 'Convert or Leak..?') . '</td></tr><tr><td style="padding:10px 28px 30px 28px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;"><tr><td class="mp-stack mp-mobile-edge" width="50%" valign="top" style="padding:0 12px 0 0;"><div style="background:#294360;border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:24px 22px;color:#ffffff;"><div style="font-size:19px;font-weight:800;color:' . $accent . ';margin-bottom:22px;">' . $esc('leftLabel') . '</div><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px;height:58px;border:10px solid ' . $accent . ';border-radius:50%;text-align:center;line-height:58px;font-size:20px;font-weight:800;">' . $esc('leftPercent') . '</div></td><td><div style="font-size:18px;line-height:1.18;font-weight:800;">' . $esc('leftTitle') . '</div><div style="font-size:14px;line-height:1.45;color:#cbd7e8;">' . $esc('leftText') . '</div></td></tr></table><div style="margin-top:24px;">' . $sparkBars($accent, [16, 28, 34, 39, 55, 48, 61, 66, 78]) . '</div></div></td><td class="mp-stack mp-mobile-edge mp-mobile-gap" width="50%" valign="top" style="padding:0 0 0 12px;"><div style="background:#294360;border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:24px 22px;color:#ffffff;"><div style="font-size:19px;font-weight:800;color:#d5deeb;margin-bottom:22px;">' . $esc('rightLabel') . '</div><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px;height:58px;border:10px solid #9aa8ba;border-radius:50%;text-align:center;line-height:58px;font-size:20px;font-weight:800;">' . $esc('rightPercent') . '</div></td><td><div style="font-size:18px;line-height:1.18;font-weight:800;">' . $esc('rightTitle') . '</div><div style="font-size:14px;line-height:1.45;color:#cbd7e8;">' . $esc('rightText') . '</div></td></tr></table><div style="margin-top:24px;">' . $sparkBars('#ffffff', [76, 61, 56, 47, 38, 31, 23, 18, 10]) . '</div></div></td></tr></table></td></tr></table></td></tr>';
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
                <div style="display:inline-block; background:' . mailpilotBuilderAttr($block['iconBg'] ?? '#eff6ff') . '; color:' . mailpilotBuilderAttr($block['iconColor'] ?? '#2563eb') . '; border-radius:10px; padding:8px 10px; font-size:12px; font-weight:bold; margin-bottom:14px;">' . mailpilotBuilderEsc($icon) . '</div>
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
                        <div style="font-size:25px; line-height:1.08; font-weight:800; color:' . mailpilotBuilderAttr($textColor) . '; margin-bottom:22px;">' . mailpilotBuilderEsc($block['titleLine1'] ?? '') . '<br>' . mailpilotBuilderEsc($block['titleLine2'] ?? '') . ' <span style="color:' . mailpilotBuilderAttr($accentColor) . ';">' . mailpilotBuilderEsc($block['titleAccent'] ?? '') . '</span></div>
                        <div style="font-size:17px; line-height:1.65; color:' . mailpilotBuilderAttr($block['muted'] ?? '#d9e6f8') . '; margin-bottom:26px;">' . mailpilotBuilderLines($block['text'] ?? '') . '</div>
                        <a href="' . mailpilotBuilderAttr($block['buttonUrl'] ?? '#') . '" style="display:inline-block; background:' . mailpilotBuilderAttr($buttonBg) . '; color:#ffffff; text-decoration:none; padding:16px 24px; border-radius:8px; font-size:15px; line-height:1; font-weight:800;">' . mailpilotBuilderEsc($block['buttonText'] ?? '') . ' &#8594;</a>
                    </td>
                    <td class="mp-stack mp-pad-mobile mp-mobile-top" width="48%" valign="middle" style="padding:24px 28px 24px 0;">
                        <div style="position:relative; min-height:178px; background:#102641; overflow:hidden;">
                            <div style="position:absolute; right:20px; top:46px; width:160px; height:17px; border-radius:20px; background:#cfd7df; box-shadow:0 3px 0 rgba(0,0,0,.35) inset;"></div>
                            <div style="position:absolute; right:53px; top:60px; width:70px; height:88px; border:26px solid #b8c3cc; border-top:0; border-radius:0 0 62px 62px;"></div>
                            <div style="position:absolute; left:28px; top:60px; width:118px; height:118px; border-radius:70px; background:#ffffff; text-align:center;">
                                <div style="padding-top:27px; color:' . mailpilotBuilderAttr($accentColor) . '; font-size:26px; line-height:1; font-weight:800;">' . mailpilotBuilderEsc($block['visualTop'] ?? 'FREE') . '</div>
                                <div style="margin-top:6px; color:#0a1f3d; font-size:15px; line-height:1.25; font-weight:800;">' . mailpilotBuilderEsc($block['visualLine1'] ?? 'Landing Page') . '<br>' . mailpilotBuilderEsc($block['visualLine2'] ?? 'Audit') . '</div>
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
                        <div style="font-size:23px; line-height:1.14; font-weight:800; color:#ffffff; margin-bottom:24px;">' . mailpilotBuilderEsc($block['titleLine1'] ?? '') . '<br><span style="color:' . mailpilotBuilderAttr($accentColor) . ';">' . mailpilotBuilderEsc($block['titleAccent'] ?? '') . '</span></div>
                        <div style="font-size:19px; line-height:1.65; color:#9fb1ca;">' . mailpilotBuilderLines($block['text'] ?? '') . '</div>
                    </td>
                    <td class="mp-stack mp-pad-mobile mp-mobile-top" width="35%" valign="middle" align="center" style="padding:28px 6px;">
                        <div style="position:relative; width:220px; max-width:100%; height:178px; margin:0 auto;">
                            <div style="position:absolute; left:25px; top:20px; width:170px; height:118px; background:' . mailpilotBuilderAttr($blue) . '; clip-path:polygon(0 0,100% 0,68% 100%,32% 100%);"></div>
                            <div style="position:absolute; left:82px; top:118px; width:56px; height:58px; border-radius:0 0 20px 20px; background:#123b96;"></div>
                            <div style="position:absolute; left:17px; top:13px; transform:rotate(-6deg); background:#ffffff; color:#0f172a; border-radius:5px; padding:10px 17px; font-size:14px; font-weight:800;">' . mailpilotBuilderEsc($block['labelOne'] ?? 'Traffic') . '</div>
                            <div style="position:absolute; right:0; top:14px; transform:rotate(5deg); background:#ffffff; color:#0f172a; border-radius:5px; padding:10px 17px; font-size:14px; font-weight:800;">' . mailpilotBuilderEsc($block['labelTwo'] ?? 'CTAs') . '</div>
                            <div style="position:absolute; left:78px; top:70px; transform:rotate(-3deg); background:#ffffff; color:#0f172a; border-radius:5px; padding:10px 18px; font-size:14px; font-weight:800;">' . mailpilotBuilderEsc($block['labelThree'] ?? 'Follow-up') . '</div>
                        </div>
                    </td>
                    <td class="mp-stack mp-pad-mobile mp-mobile-top" width="33%" valign="middle" style="padding:34px 28px 34px 12px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                            <tr><td style="padding:0 0 12px 0; border-bottom:1px solid rgba(255,255,255,.12);"><div style="font-size:13px; color:#ffffff; font-weight:800;">' . mailpilotBuilderEsc($block['step1Title'] ?? '') . '</div><div style="font-size:12px; color:#9fb1ca;">' . mailpilotBuilderEsc($block['step1Text'] ?? '') . '</div></td></tr>
                            <tr><td style="padding:12px 0; border-bottom:1px solid rgba(255,255,255,.12);"><div style="font-size:13px; color:#ffffff; font-weight:800;">' . mailpilotBuilderEsc($block['step2Title'] ?? '') . '</div><div style="font-size:12px; color:#9fb1ca;">' . mailpilotBuilderEsc($block['step2Text'] ?? '') . '</div></td></tr>
                            <tr><td style="padding:12px 0; border-bottom:1px solid rgba(255,255,255,.12);"><div style="font-size:13px; color:#ffffff; font-weight:800;">' . mailpilotBuilderEsc($block['step3Title'] ?? '') . '</div><div style="font-size:12px; color:#9fb1ca;">' . mailpilotBuilderEsc($block['step3Text'] ?? '') . '</div></td></tr>
                            <tr><td style="padding:12px 0 0 0;"><div style="font-size:13px; color:#ffffff; font-weight:800;">' . mailpilotBuilderEsc($block['step4Title'] ?? '') . '</div><div style="font-size:12px; color:#9fb1ca;">' . mailpilotBuilderEsc($block['step4Text'] ?? '') . '</div></td></tr>
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
                    <div style="font-size:34px; line-height:1.14; font-weight:800; color:#20334f; margin-bottom:18px;"><span style="font-style:italic;">' . mailpilotBuilderEsc($block['smallWord'] ?? 'Small') . '</span> ' . mailpilotBuilderEsc($block['smallTail'] ?? 'moves.') . '<br><span style="font-style:italic; color:' . mailpilotBuilderAttr($accentColor) . ';">' . mailpilotBuilderEsc($block['bigWord'] ?? 'Big') . '</span>' . mailpilotBuilderEsc($block['bigTail'] ?? ' impact.') . '</div>
                    <div style="font-size:17px; line-height:1.7; color:#4d6485; margin:0 auto 28px auto; max-width:470px;">' . mailpilotBuilderLines($block['text'] ?? '') . '</div>
                    <a href="' . mailpilotBuilderAttr($block['buttonUrl'] ?? '#') . '" style="display:inline-block; background:' . mailpilotBuilderAttr($buttonBg) . '; color:#ffffff; text-decoration:none; padding:16px 24px; border-radius:8px; font-size:15px; line-height:1; font-weight:800;">' . mailpilotBuilderEsc($block['buttonText'] ?? '') . '</a>
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
                <tr><td align="center" style="padding:32px 28px 10px 28px; font-size:38px; line-height:1.05; font-weight:800; color:#ffffff;">' . mailpilotBuilderEsc($block['title'] ?? '') . '</td></tr>
                <tr><td style="padding:10px 28px 30px 28px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;">
                        <tr>
                            <td class="mp-stack mp-mobile-edge" width="50%" valign="top" style="padding:0 12px 0 0;">
                                <div style="background:#294360; border:1px solid rgba(255,255,255,.12); border-radius:18px; padding:24px 22px; color:#ffffff;">
                                    <div style="font-size:19px; font-weight:800; color:' . mailpilotBuilderAttr($accentColor) . '; margin-bottom:22px;">' . mailpilotBuilderEsc($block['leftLabel'] ?? '') . '</div>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px; height:58px; border:10px solid ' . mailpilotBuilderAttr($accentColor) . '; border-radius:50%; text-align:center; line-height:58px; font-size:20px; font-weight:800;">' . mailpilotBuilderEsc($block['leftPercent'] ?? '') . '</div></td><td><div style="font-size:18px; line-height:1.18; font-weight:800;">' . mailpilotBuilderEsc($block['leftTitle'] ?? '') . '</div><div style="font-size:14px; line-height:1.45; color:#cbd7e8;">' . mailpilotBuilderEsc($block['leftText'] ?? '') . '</div></td></tr></table>
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
                                    <div style="font-size:19px; font-weight:800; color:#d5deeb; margin-bottom:22px;">' . mailpilotBuilderEsc($block['rightLabel'] ?? '') . '</div>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px; height:58px; border:10px solid #9aa8ba; border-radius:50%; text-align:center; line-height:58px; font-size:20px; font-weight:800;">' . mailpilotBuilderEsc($block['rightPercent'] ?? '') . '</div></td><td><div style="font-size:18px; line-height:1.18; font-weight:800;">' . mailpilotBuilderEsc($block['rightTitle'] ?? '') . '</div><div style="font-size:14px; line-height:1.45; color:#cbd7e8;">' . mailpilotBuilderEsc($block['rightText'] ?? '') . '</div></td></tr></table>
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
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' . mailpilotBuilderFontImport($font) . mailpilotBuilderResponsiveCss() . '</head>
<body style="margin:0; padding:0; background:' . mailpilotBuilderAttr($bg) . '; font-family:' . mailpilotBuilderAttr($fontStack) . ';">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background:' . mailpilotBuilderAttr($bg) . '; border-collapse:collapse;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" class="mp-container" width="' . $width . '" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:' . $width . 'px; background:' . mailpilotBuilderAttr($contentBg) . '; border-collapse:collapse; font-family:' . mailpilotBuilderAttr($fontStack) . ';">
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

    $hasPlaceholder = strpos((string)$html, 'Open this saved template in the Mailpilot builder') !== false;
    if (!$force && !$hasPlaceholder) {
        return $html;
    }

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
    $uploadDir = UPLOAD_DIR;
    
    // Match img src pointing anywhere under assets/uploads/.
    preg_match_all('/src=["\']([^"\']*assets\/uploads\/([^"\']+))["\']/i', $html, $matches);
    
    if (!empty($matches[2])) {
        foreach ($matches[2] as $index => $filename) {
            $filepath = $uploadDir . $filename;
            if (file_exists($filepath)) {
                $cid = 'img_' . md5($filename) . '_' . pathinfo($filename, PATHINFO_FILENAME);
                $images[] = [
                    'path' => $filepath,
                    'cid' => $cid,
                    'name' => $filename,
                    'original_src' => $matches[1][$index],
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
