<?php
/**
 * Email verifier helpers for syntax, DNS/MX, risk, and optional SMTP probing.
 */

function emailVerifierEnsureTable() {
    try {
        dbExecute("
            CREATE TABLE IF NOT EXISTS `email_verifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL,
                `domain` VARCHAR(255) NOT NULL,
                `status` ENUM('valid','invalid','risky','unknown') NOT NULL DEFAULT 'unknown',
                `score` INT NOT NULL DEFAULT 0,
                `mx_valid` TINYINT(1) NOT NULL DEFAULT 0,
                `smtp_status` VARCHAR(30) DEFAULT NULL,
                `is_disposable` TINYINT(1) NOT NULL DEFAULT 0,
                `is_role` TINYINT(1) NOT NULL DEFAULT 0,
                `is_catch_all` TINYINT(1) NOT NULL DEFAULT 0,
                `suggestion` VARCHAR(255) DEFAULT NULL,
                `details` JSON,
                `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email (`email`),
                INDEX idx_domain (`domain`),
                INDEX idx_status (`status`),
                INDEX idx_checked (`checked_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function emailVerifierNormalize($email) {
    $email = trim((string) $email);
    $email = trim($email, " \t\n\r\0\x0B<>\"'");
    return strtolower($email);
}

function emailVerifierExtractEmails($text) {
    $emails = [];
    $tokens = preg_split('/[\s,;]+/', (string) $text);

    foreach ($tokens as $token) {
        $normalized = emailVerifierNormalize($token);
        if ($normalized !== '' && strpos($normalized, '@') !== false) {
            $emails[$normalized] = $normalized;
        }
    }

    return array_values($emails);
}

function emailVerifierRoleLocalParts() {
    return [
        'abuse', 'admin', 'administrator', 'billing', 'compliance', 'contact', 'devnull',
        'dns', 'help', 'hostmaster', 'info', 'it', 'legal', 'mailer-daemon', 'marketing',
        'news', 'newsletter', 'no-reply', 'noreply', 'operations', 'owner', 'postmaster',
        'privacy', 'root', 'sales', 'security', 'support', 'sysadmin', 'team', 'webmaster',
    ];
}

function emailVerifierDisposableDomains() {
    return [
        '10minutemail.com', '20minutemail.com', '33mail.org', 'anonaddy.com',
        'burnermail.io', 'dispostable.com', 'emailondeck.com', 'fakeinbox.com',
        'getnada.com', 'guerrillamail.com', 'guerrillamail.net', 'mailinator.com',
        'maildrop.cc', 'moakt.com', 'sharklasers.com', 'temp-mail.org',
        'tempmail.com', 'throwawaymail.com', 'trashmail.com', 'yopmail.com',
    ];
}

function emailVerifierCommonDomains() {
    return [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com',
        'live.com', 'msn.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me',
        'protonmail.com', 'zoho.com', 'gmx.com', 'mail.com',
    ];
}

function emailVerifierSuggestDomain($domain) {
    $domain = strtolower((string) $domain);
    $best = '';
    $bestDistance = 99;

    foreach (emailVerifierCommonDomains() as $known) {
        $distance = levenshtein($domain, $known);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $best = $known;
        }
    }

    return ($bestDistance > 0 && $bestDistance <= 2) ? $best : '';
}

function emailVerifierGetMxRecords($domain) {
    $hosts = [];
    $weights = [];
    $records = [];

    if (@getmxrr($domain, $hosts, $weights) && !empty($hosts)) {
        foreach ($hosts as $i => $host) {
            $records[] = [
                'host' => rtrim(strtolower($host), '.'),
                'priority' => isset($weights[$i]) ? (int) $weights[$i] : 0,
            ];
        }
        usort($records, function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
    }

    return $records;
}

function emailVerifierReadSmtp($socket) {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
        if (strlen($response) > 8192) {
            break;
        }
    }
    return trim($response);
}

function emailVerifierSmtpCode($response) {
    if (preg_match('/^(\d{3})/m', (string) $response, $match)) {
        return (int) $match[1];
    }
    return 0;
}

function emailVerifierSendSmtp($socket, $command) {
    fwrite($socket, $command . "\r\n");
    return emailVerifierReadSmtp($socket);
}

function emailVerifierProbeSmtp($email, $mxRecords, $senderEmail, $timeout = 6) {
    if (!function_exists('fsockopen')) {
        return ['status' => 'unavailable', 'message' => 'SMTP probing is unavailable because fsockopen is disabled.'];
    }

    if (empty($mxRecords)) {
        return ['status' => 'not_checked', 'message' => 'No MX records available for SMTP probing.'];
    }

    $parts = explode('@', $email, 2);
    $domain = $parts[1] ?? '';
    $senderEmail = filter_var($senderEmail, FILTER_VALIDATE_EMAIL) ? $senderEmail : 'postmaster@localhost.localdomain';
    $heloHost = gethostname() ?: 'localhost.localdomain';
    $mxRecords = array_slice($mxRecords, 0, 3);
    $lastError = '';

    foreach ($mxRecords as $record) {
        $host = $record['host'] ?? '';
        if ($host === '') {
            continue;
        }

        $socket = @fsockopen($host, 25, $errno, $errstr, $timeout);
        if (!$socket) {
            $lastError = trim($errstr ?: ('Connection failed with error ' . $errno));
            continue;
        }

        stream_set_timeout($socket, $timeout);
        $banner = emailVerifierReadSmtp($socket);
        $bannerCode = emailVerifierSmtpCode($banner);
        if ($bannerCode && $bannerCode >= 400) {
            fclose($socket);
            $lastError = $banner;
            continue;
        }

        $helo = emailVerifierSendSmtp($socket, 'HELO ' . $heloHost);
        if (emailVerifierSmtpCode($helo) >= 400) {
            $helo = emailVerifierSendSmtp($socket, 'EHLO ' . $heloHost);
        }

        emailVerifierSendSmtp($socket, 'MAIL FROM:<' . $senderEmail . '>');
        $targetResponse = emailVerifierSendSmtp($socket, 'RCPT TO:<' . $email . '>');
        $targetCode = emailVerifierSmtpCode($targetResponse);

        $randomLocal = 'mailpilot-check-' . bin2hex(random_bytes(6));
        $catchAllResponse = emailVerifierSendSmtp($socket, 'RCPT TO:<' . $randomLocal . '@' . $domain . '>');
        $catchAllCode = emailVerifierSmtpCode($catchAllResponse);
        emailVerifierSendSmtp($socket, 'QUIT');
        fclose($socket);

        if (in_array($targetCode, [250, 251, 252], true)) {
            if (in_array($catchAllCode, [250, 251, 252], true)) {
                return [
                    'status' => 'catch_all',
                    'message' => 'Mailbox accepted, but the domain also accepts random recipients.',
                    'mx_host' => $host,
                    'response' => $targetResponse,
                ];
            }
            return [
                'status' => 'valid',
                'message' => 'Mailbox accepted by SMTP server.',
                'mx_host' => $host,
                'response' => $targetResponse,
            ];
        }

        if (in_array($targetCode, [550, 551, 552, 553, 554], true)) {
            return [
                'status' => 'invalid',
                'message' => 'Mailbox rejected by SMTP server.',
                'mx_host' => $host,
                'response' => $targetResponse,
            ];
        }

        if ($targetCode >= 400 && $targetCode < 500) {
            return [
                'status' => 'temporary',
                'message' => 'SMTP server returned a temporary response.',
                'mx_host' => $host,
                'response' => $targetResponse,
            ];
        }

        return [
            'status' => 'unknown',
            'message' => 'SMTP server did not provide a decisive mailbox response.',
            'mx_host' => $host,
            'response' => $targetResponse,
        ];
    }

    return [
        'status' => 'unreachable',
        'message' => $lastError ?: 'Could not connect to the domain MX servers on port 25.',
    ];
}

function emailVerifierDefaultProbeSender() {
    try {
        $email = dbFetchValue("SELECT from_email FROM smtp_accounts WHERE is_active = 1 AND from_email != '' ORDER BY id ASC LIMIT 1");
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    } catch (Exception $e) {
        // Ignore and use app URL fallback.
    }

    $host = parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST);
    if ($host) {
        return 'verify@' . $host;
    }

    return 'postmaster@localhost.localdomain';
}

function emailVerifierVerify($email, $options = []) {
    $email = emailVerifierNormalize($email);
    $result = [
        'email' => $email,
        'domain' => '',
        'status' => 'unknown',
        'score' => 0,
        'mx_valid' => false,
        'mx_records' => [],
        'smtp_status' => 'not_checked',
        'smtp_message' => '',
        'is_disposable' => false,
        'is_role' => false,
        'is_catch_all' => false,
        'suggestion' => '',
        'reasons' => [],
    ];

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $result['status'] = 'invalid';
        $result['reasons'][] = 'Invalid email syntax.';
        return $result;
    }

    [$local, $domain] = explode('@', $email, 2);
    $result['domain'] = $domain;
    $result['score'] += 25;

    if (strlen($local) > 64 || strlen($domain) > 253) {
        $result['status'] = 'invalid';
        $result['score'] = 10;
        $result['reasons'][] = 'Email local part or domain is too long.';
        return $result;
    }

    if (in_array($local, emailVerifierRoleLocalParts(), true)) {
        $result['is_role'] = true;
        $result['reasons'][] = 'Role-based mailbox.';
    }

    if (in_array($domain, emailVerifierDisposableDomains(), true)) {
        $result['is_disposable'] = true;
        $result['reasons'][] = 'Disposable or temporary email domain.';
    }

    $suggestedDomain = emailVerifierSuggestDomain($domain);
    if ($suggestedDomain !== '') {
        $result['suggestion'] = $local . '@' . $suggestedDomain;
        $result['reasons'][] = 'Possible domain typo: ' . $suggestedDomain . '.';
    }

    $mxRecords = emailVerifierGetMxRecords($domain);
    if (!empty($mxRecords)) {
        $result['mx_valid'] = true;
        $result['mx_records'] = $mxRecords;
        $result['score'] += 35;
    } elseif (@checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA')) {
        $result['mx_valid'] = true;
        $result['mx_records'] = [['host' => $domain, 'priority' => 0]];
        $result['score'] += 20;
        $result['reasons'][] = 'No MX record found; using A/AAAA fallback.';
    } else {
        $result['status'] = 'invalid';
        $result['score'] = min($result['score'], 20);
        $result['reasons'][] = 'Domain has no MX, A, or AAAA DNS record.';
        return $result;
    }

    $smtpCheck = !empty($options['smtp_check']);
    if ($smtpCheck) {
        $probe = emailVerifierProbeSmtp(
            $email,
            $result['mx_records'],
            $options['probe_sender'] ?? emailVerifierDefaultProbeSender(),
            (int)($options['timeout'] ?? 6)
        );
        $result['smtp_status'] = $probe['status'] ?? 'unknown';
        $result['smtp_message'] = $probe['message'] ?? '';

        if ($result['smtp_status'] === 'valid') {
            $result['score'] += 35;
            $result['reasons'][] = 'SMTP server accepted the mailbox.';
        } elseif ($result['smtp_status'] === 'invalid') {
            $result['status'] = 'invalid';
            $result['score'] = min($result['score'], 30);
            $result['reasons'][] = 'SMTP server rejected the mailbox.';
            return $result;
        } elseif ($result['smtp_status'] === 'catch_all') {
            $result['is_catch_all'] = true;
            $result['score'] += 15;
            $result['reasons'][] = 'Domain appears to be catch-all.';
        } else {
            $result['score'] += 5;
            $result['reasons'][] = $result['smtp_message'] ?: 'SMTP mailbox check was inconclusive.';
        }
    } else {
        $result['reasons'][] = 'SMTP mailbox probe was not requested.';
        $result['score'] += 20;
    }

    if ($result['is_disposable']) {
        $result['score'] -= 30;
    }
    if ($result['is_role']) {
        $result['score'] -= 10;
    }
    if ($result['is_catch_all']) {
        $result['score'] -= 15;
    }
    if ($result['suggestion'] !== '') {
        $result['score'] -= 20;
    }

    $result['score'] = max(0, min(100, (int) $result['score']));

    if ($result['is_disposable'] || $result['is_role'] || $result['is_catch_all'] || $result['suggestion'] !== '') {
        $result['status'] = 'risky';
    } elseif ($smtpCheck && in_array($result['smtp_status'], ['temporary', 'unreachable', 'unavailable', 'unknown'], true)) {
        $result['status'] = 'unknown';
    } else {
        $result['status'] = 'valid';
    }

    if (empty($result['reasons'])) {
        $result['reasons'][] = 'Email passed available checks.';
    }

    return $result;
}

function emailVerifierSaveResult($result) {
    emailVerifierEnsureTable();

    return dbInsert(
        "INSERT INTO email_verifications
         (email, domain, status, score, mx_valid, smtp_status, is_disposable, is_role, is_catch_all, suggestion, details)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $result['email'],
            $result['domain'] ?? '',
            $result['status'],
            (int) $result['score'],
            !empty($result['mx_valid']) ? 1 : 0,
            $result['smtp_status'] ?? 'not_checked',
            !empty($result['is_disposable']) ? 1 : 0,
            !empty($result['is_role']) ? 1 : 0,
            !empty($result['is_catch_all']) ? 1 : 0,
            $result['suggestion'] ?? null,
            json_encode($result),
        ]
    );
}
