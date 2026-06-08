<?php
/**
 * Bounce detection, logging, suppression, and IMAP scan helpers.
 */

function bounceNormalizeEmail($email) {
    return strtolower(trim((string) $email));
}

function bounceExtractStatusCode($message) {
    $message = (string) $message;

    if (preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $message, $match)) {
        return $match[1];
    }

    if (preg_match('/\b([245]\d{2})\b/', $message, $match)) {
        return $match[1];
    }

    return '';
}

function bounceClassifyMessage($message) {
    $message = trim((string) $message);
    $code = bounceExtractStatusCode($message);
    $haystack = strtolower($message);

    $result = [
        'type' => 'unknown',
        'code' => $code,
        'reason' => 'No reliable bounce signature matched.',
        'confidence' => 'low',
    ];

    $complaintPatterns = [
        '/abuse/i',
        '/spam complaint/i',
        '/feedback loop/i',
        '/reported as spam/i',
        '/message considered spam/i',
        '/blocked.*(policy|spam|reputation|blacklist)/i',
        '/blacklist|dnsbl|rbl/i',
        '/rejected.*policy/i',
        '/5\.7\.\d+/',
        '/\b57\d\b/',
    ];

    $hardPatterns = [
        '/user unknown/i',
        '/unknown user/i',
        '/no such user/i',
        '/recipient address rejected/i',
        '/address rejected/i',
        '/mailbox (not found|unavailable|disabled|does not exist)/i',
        '/recipient (not found|does not exist|invalid|unknown|rejected)/i',
        '/invalid (recipient|mailbox|address|user)/i',
        '/account (disabled|suspended|closed|inactive)/i',
        '/domain (not found|does not exist|invalid)/i',
        '/host or domain name not found/i',
        '/bad destination mailbox address/i',
        '/5\.1\.\d+/',
        '/5\.2\.1/',
        '/5\.3\.0/',
        '/5\.4\.4/',
        '/\b55[0134]\b/',
    ];

    $softPatterns = [
        '/temporar(?:y|ily)/i',
        '/try again later/i',
        '/deferred/i',
        '/greylist/i',
        '/rate limit/i',
        '/too many (connections|recipients|messages|requests)/i',
        '/mailbox full/i',
        '/quota exceeded/i',
        '/over quota/i',
        '/insufficient storage/i',
        '/connection timed out/i',
        '/timeout/i',
        '/service unavailable/i',
        '/local error/i',
        '/4\.\d{1,3}\.\d{1,3}/',
        '/\b4\d{2}\b/',
        '/\b421\b|\b450\b|\b451\b|\b452\b/',
    ];

    foreach ($complaintPatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return [
                'type' => 'complaint',
                'code' => $code,
                'reason' => 'Policy, spam, abuse, or blacklist rejection.',
                'confidence' => 'high',
            ];
        }
    }

    foreach ($hardPatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return [
                'type' => 'hard',
                'code' => $code,
                'reason' => 'Permanent recipient, mailbox, or domain failure.',
                'confidence' => 'high',
            ];
        }
    }

    foreach ($softPatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return [
                'type' => 'soft',
                'code' => $code,
                'reason' => 'Temporary delivery failure.',
                'confidence' => 'high',
            ];
        }
    }

    if ($code !== '') {
        if ($code[0] === '5') {
            $result['type'] = 'hard';
            $result['reason'] = 'SMTP permanent failure code.';
            $result['confidence'] = 'medium';
        } elseif ($code[0] === '4') {
            $result['type'] = 'soft';
            $result['reason'] = 'SMTP temporary failure code.';
            $result['confidence'] = 'medium';
        }
    } elseif (strpos($haystack, 'delivery status notification') !== false || strpos($haystack, 'undeliver') !== false) {
        $result['reason'] = 'Delivery notification found, but the failure class was unclear.';
        $result['confidence'] = 'medium';
    }

    return $result;
}

function bounceExtractRecipient($text) {
    $text = (string) $text;
    $patterns = [
        '/Final-Recipient:\s*rfc822;\s*([^\s;<>]+@[^\s;<>]+)/i',
        '/Original-Recipient:\s*rfc822;\s*([^\s;<>]+@[^\s;<>]+)/i',
        '/X-Failed-Recipients:\s*([^\s,;<>]+@[^\s,;<>]+)/i',
        '/failed recipients?:\s*([^\s,;<>]+@[^\s,;<>]+)/i',
        '/The following address(?:es)? failed:\s*([^\s,;<>]+@[^\s,;<>]+)/i',
        '/<([^<>\s]+@[^<>\s]+)>/',
        '/\b([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $match)) {
            return bounceNormalizeEmail($match[1]);
        }
    }

    return '';
}

function bounceRecord($email, $classification, $context = []) {
    $email = bounceNormalizeEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    $queueId = !empty($context['queue_id']) ? (int) $context['queue_id'] : null;
    $source = $context['source'] ?? 'smtp_response';

    if ($queueId) {
        $existing = dbFetchValue(
            "SELECT id FROM bounces WHERE queue_id = ? AND source = ? LIMIT 1",
            [$queueId, $source]
        );
        if ($existing) {
            return (int) $existing;
        }
    }

    $message = trim(($classification['reason'] ?? '') . "\n" . ($context['message'] ?? ''));
    $message = substr($message, 0, 1000);

    return (int) dbInsert(
        "INSERT INTO bounces (email, smtp_account_id, campaign_id, queue_id, bounce_type, bounce_code, bounce_message, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $email,
            $context['smtp_account_id'] ?? null,
            $context['campaign_id'] ?? null,
            $queueId,
            $classification['type'] ?? 'unknown',
            $classification['code'] ?? '',
            $message,
            $source,
        ]
    );
}

function bounceSuppressIfNeeded($email, $classification, $detail = '') {
    $email = bounceNormalizeEmail($email);
    $type = $classification['type'] ?? 'unknown';

    if (!in_array($type, ['hard', 'complaint'], true)) {
        return false;
    }

    $reason = $type === 'complaint' ? 'complaint' : 'hard_bounce';
    dbExecute(
        "INSERT IGNORE INTO suppression_list (email, reason, source_detail) VALUES (?, ?, ?)",
        [$email, $reason, substr((string) $detail, 0, 1000)]
    );

    return true;
}

function bounceImapMailboxString($account) {
    $host = trim($account['imap_host'] ?? '');
    $port = (int) ($account['imap_port'] ?? 993);
    $encryption = strtolower(trim($account['imap_encryption'] ?? 'ssl'));

    if ($host === '') {
        return '';
    }

    $flags = '/imap';
    if ($encryption === 'ssl') {
        $flags .= '/ssl';
    } elseif ($encryption === 'tls') {
        $flags .= '/tls';
    }
    $flags .= '/novalidate-cert';

    return '{' . $host . ':' . $port . $flags . '}INBOX';
}

function bounceLooksLikeDsn($headers, $body) {
    $text = strtolower((string) $headers . "\n" . (string) $body);

    return strpos($text, 'mailer-daemon') !== false
        || strpos($text, 'postmaster') !== false
        || strpos($text, 'delivery status notification') !== false
        || strpos($text, 'undeliver') !== false
        || strpos($text, 'returned mail') !== false
        || strpos($text, 'failure notice') !== false
        || strpos($text, 'final-recipient') !== false
        || strpos($text, 'diagnostic-code') !== false;
}

function bounceScanImapAccount($account, $limit = 80) {
    if (!function_exists('imap_open')) {
        return ['scanned' => 0, 'recorded' => 0, 'suppressed' => 0, 'errors' => ['PHP IMAP extension is not installed.']];
    }

    $mailbox = bounceImapMailboxString($account);
    if ($mailbox === '') {
        return ['scanned' => 0, 'recorded' => 0, 'suppressed' => 0, 'errors' => ['IMAP host is missing.']];
    }

    $username = trim($account['imap_username'] ?: $account['smtp_username']);
    $password = $account['imap_password'] ? decryptString($account['imap_password']) : decryptString($account['smtp_password']);

    $stream = @imap_open($mailbox, $username, $password, OP_READONLY, 1);
    if (!$stream) {
        return ['scanned' => 0, 'recorded' => 0, 'suppressed' => 0, 'errors' => [imap_last_error() ?: 'Could not open IMAP mailbox.']];
    }

    $total = (int) imap_num_msg($stream);
    $start = max(1, $total - max(1, (int) $limit) + 1);
    $stats = ['scanned' => 0, 'recorded' => 0, 'suppressed' => 0, 'errors' => []];

    for ($msgNo = $total; $msgNo >= $start; $msgNo--) {
        $headers = @imap_fetchheader($stream, $msgNo, FT_PREFETCHTEXT) ?: '';
        $body = @imap_body($stream, $msgNo, FT_PEEK) ?: '';

        if (!bounceLooksLikeDsn($headers, $body)) {
            continue;
        }

        $stats['scanned']++;
        $raw = $headers . "\n" . $body;
        $recipient = bounceExtractRecipient($raw);
        if ($recipient === '') {
            continue;
        }

        $classification = bounceClassifyMessage($raw);
        $existing = dbFetchValue(
            "SELECT id FROM bounces WHERE email = ? AND smtp_account_id = ? AND source = 'imap_scan' AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) LIMIT 1",
            [$recipient, $account['id']]
        );
        if ($existing) {
            continue;
        }

        bounceRecord($recipient, $classification, [
            'smtp_account_id' => $account['id'],
            'source' => 'imap_scan',
            'message' => $raw,
        ]);
        $stats['recorded']++;

        if (bounceSuppressIfNeeded($recipient, $classification, 'IMAP bounce scan: ' . ($classification['reason'] ?? ''))) {
            $stats['suppressed']++;
        }
    }

    imap_close($stream);
    return $stats;
}

function bounceScanConfiguredMailboxes($limit = 80, $accountId = 0) {
    $params = [];
    $where = "is_active = 1 AND imap_host IS NOT NULL AND imap_host != ''";
    if ($accountId > 0) {
        $where .= " AND id = ?";
        $params[] = (int) $accountId;
    }

    $accounts = dbFetchAll("SELECT * FROM smtp_accounts WHERE {$where} ORDER BY id ASC", $params);
    $summary = ['accounts' => count($accounts), 'scanned' => 0, 'recorded' => 0, 'suppressed' => 0, 'errors' => []];

    foreach ($accounts as $account) {
        $stats = bounceScanImapAccount($account, $limit);
        $summary['scanned'] += $stats['scanned'];
        $summary['recorded'] += $stats['recorded'];
        $summary['suppressed'] += $stats['suppressed'];
        foreach ($stats['errors'] as $error) {
            $summary['errors'][] = ($account['label'] ?? ('Account #' . $account['id'])) . ': ' . $error;
        }
    }

    return $summary;
}
