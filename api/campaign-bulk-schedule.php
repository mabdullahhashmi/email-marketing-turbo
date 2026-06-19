<?php
/**
 * API: Bulk schedule separate campaigns.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campaign-scheduler-helper.php';

header('Content-Type: application/json');

ensureCampaignBatchColumn();
ensureCampaignTemplatesTable();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['success' => false, 'message' => 'Invalid request data'], 400);
}

validateCSRF($input['csrf_token'] ?? '');

$rows = $input['rows'] ?? [];
if (!is_array($rows) || empty($rows)) {
    jsonResponse(['success' => false, 'message' => 'Add at least one campaign row.'], 400);
}

if (count($rows) > 200) {
    jsonResponse(['success' => false, 'message' => 'Bulk scheduling is limited to 200 campaigns at a time.'], 400);
}

$templates = dbFetchAll("SELECT id, name, subject, body_html FROM campaign_templates");
$templatesById = [];
$templatesByName = [];
foreach ($templates as $template) {
    $templatesById[(string)$template['id']] = $template;
    $templatesByName[strtolower(trim($template['name']))] = $template;
}

$smtpIds = [];
$smtpByName = [];
foreach (dbFetchAll("SELECT id, label, from_email FROM smtp_accounts WHERE is_active = 1") as $row) {
    $smtpIds[(string)$row['id']] = true;
    $smtpByName[strtolower(trim((string)$row['label']))] = (int)$row['id'];
    $smtpByName[strtolower(trim((string)$row['from_email']))] = (int)$row['id'];
}
$listIds = [];
$listByName = [];
foreach (dbFetchAll("SELECT id, name FROM contact_lists") as $row) {
    $listIds[(string)$row['id']] = true;
    $listByName[strtolower(trim((string)$row['name']))] = (int)$row['id'];
}

$created = 0;
$queued = 0;
$failed = 0;
$results = [];

foreach ($rows as $index => $row) {
    $rowNumber = $index + 1;
    if (!is_array($row)) {
        $failed++;
        $results[] = ['row' => $rowNumber, 'success' => false, 'message' => 'Invalid row.'];
        continue;
    }

    $campaignName = trim((string)($row['campaign_name'] ?? ''));
    $subject = trim((string)($row['subject'] ?? ''));
    $templateId = trim((string)($row['template_id'] ?? ''));
    $templateName = trim((string)($row['template_name'] ?? ''));
    $smtpAccountId = (int)($row['smtp_account_id'] ?? 0);
    $smtpAccountName = trim((string)($row['smtp_account'] ?? ''));
    $contactListId = (int)($row['contact_list_id'] ?? 0);
    $contactListName = trim((string)($row['contact_list'] ?? ''));
    $contactBatch = trim((string)($row['contact_batch'] ?? ''));
    $scheduledAtRaw = trim((string)($row['scheduled_at'] ?? ''));
    $minDelay = max(10, (int)($row['min_delay_seconds'] ?? 60));
    $maxDelay = max($minDelay, (int)($row['max_delay_seconds'] ?? 3600));

    if ($campaignName === '') {
        $failed++;
        $results[] = ['row' => $rowNumber, 'success' => false, 'message' => 'Campaign name is required.'];
        continue;
    }
    if (!$smtpAccountId && $smtpAccountName !== '') {
        $smtpAccountId = $smtpByName[strtolower($smtpAccountName)] ?? 0;
    }
    if (!$contactListId && $contactListName !== '') {
        $contactListId = $listByName[strtolower($contactListName)] ?? 0;
    }

    if (!$smtpAccountId || !isset($smtpIds[(string)$smtpAccountId])) {
        $failed++;
        $results[] = ['row' => $rowNumber, 'success' => false, 'message' => 'Valid SMTP account is required.'];
        continue;
    }
    if (!$contactListId || !isset($listIds[(string)$contactListId])) {
        $failed++;
        $results[] = ['row' => $rowNumber, 'success' => false, 'message' => 'Valid contact list is required.'];
        continue;
    }

    $template = null;
    if ($templateId !== '' && isset($templatesById[$templateId])) {
        $template = $templatesById[$templateId];
    } elseif ($templateName !== '' && isset($templatesByName[strtolower($templateName)])) {
        $template = $templatesByName[strtolower($templateName)];
    }

    if (!$template) {
        $failed++;
        $results[] = ['row' => $rowNumber, 'success' => false, 'message' => 'Valid template is required.'];
        continue;
    }

    if ($subject === '') {
        $subject = trim((string)($template['subject'] ?? ''));
    }
    if ($subject === '') {
        $failed++;
        $results[] = ['row' => $rowNumber, 'success' => false, 'message' => 'Subject is required.'];
        continue;
    }

    $scheduledAt = '';
    if ($scheduledAtRaw !== '') {
        $timestamp = strtotime($scheduledAtRaw);
        if ($timestamp !== false) {
            $scheduledAt = date('Y-m-d H:i:s', $timestamp);
        }
    }

    $campaignId = null;
    try {
        $campaignId = dbInsert(
            "INSERT INTO campaigns (name, subject, body_html, smtp_account_id, contact_list_id, contact_batch,
             scheduled_at, min_delay_seconds, max_delay_seconds, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
            [
                $campaignName,
                $subject,
                $template['body_html'],
                $smtpAccountId,
                $contactListId,
                $contactBatch !== '' ? substr($contactBatch, 0, 100) : null,
                $scheduledAt ?: null,
                $minDelay,
                $maxDelay,
            ]
        );

        $scheduleResult = scheduleCampaignQueue(
            $campaignId,
            $subject,
            $template['body_html'],
            $smtpAccountId,
            $contactListId,
            $contactBatch,
            $scheduledAt,
            $minDelay,
            $maxDelay
        );

        if (!$scheduleResult['success']) {
            dbExecute("DELETE FROM campaigns WHERE id = ?", [$campaignId]);
            $failed++;
            $results[] = ['row' => $rowNumber, 'success' => false, 'message' => $scheduleResult['message']];
            continue;
        }

        $created++;
        $queued += (int)$scheduleResult['queued'];
        $results[] = [
            'row' => $rowNumber,
            'success' => true,
            'campaign_id' => (int)$campaignId,
            'queued' => (int)$scheduleResult['queued'],
            'message' => $scheduleResult['message'],
        ];
    } catch (Exception $e) {
        if ($campaignId) {
            dbExecute("DELETE FROM campaigns WHERE id = ?", [$campaignId]);
        }
        $failed++;
        $results[] = ['row' => $rowNumber, 'success' => false, 'message' => $e->getMessage()];
    }
}

jsonResponse([
    'success' => $created > 0,
    'message' => "Created {$created} campaign(s), queued {$queued} email(s), failed {$failed} row(s).",
    'created' => $created,
    'queued' => $queued,
    'failed' => $failed,
    'results' => $results,
]);
