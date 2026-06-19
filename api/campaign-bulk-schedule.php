<?php
/**
 * API: Bulk schedule separate campaigns.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campaign-scheduler-helper.php';
require_once __DIR__ . '/../includes/hvac-cold-templates.php';

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

seedHVACColdOutreachTemplates();

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
$listsById = [];
$listsByName = [];
foreach (dbFetchAll("SELECT id, name FROM contact_lists") as $row) {
    $listId = (int)$row['id'];
    $listIds[(string)$listId] = true;
    $listsById[$listId] = $row;
    $listNameKey = strtolower(trim((string)$row['name']));
    if (!isset($listsByName[$listNameKey])) {
        $listsByName[$listNameKey] = [];
    }
    $listsByName[$listNameKey][] = $row;
}

function bulkListHasBatch($listId, $contactBatch) {
    $contacts = dbFetchAll(
        "SELECT * FROM contacts WHERE list_id = ? AND (is_unsubscribed = 0 OR is_unsubscribed IS NULL)",
        [(int)$listId]
    );

    foreach ($contacts as $contact) {
        if (contactBatchMatches($contact, $contactBatch)) {
            return true;
        }
    }

    return false;
}

function bulkListActiveCount($listId) {
    return (int)dbFetchValue(
        "SELECT COUNT(*) FROM contacts WHERE list_id = ? AND (is_unsubscribed = 0 OR is_unsubscribed IS NULL)",
        [(int)$listId]
    );
}

function resolveBulkContactListByName($contactListName, $contactBatch, $listsByName) {
    $listNameKey = strtolower(trim((string)$contactListName));
    if ($listNameKey === '' || empty($listsByName[$listNameKey])) {
        return 0;
    }

    $matches = $listsByName[$listNameKey];
    if (count($matches) === 1) {
        return (int)$matches[0]['id'];
    }

    $contactBatch = trim((string)$contactBatch);
    if ($contactBatch !== '') {
        foreach ($matches as $match) {
            if (bulkListHasBatch((int)$match['id'], $contactBatch)) {
                return (int)$match['id'];
            }
        }
    }

    foreach ($matches as $match) {
        if (bulkListActiveCount((int)$match['id']) > 0) {
            return (int)$match['id'];
        }
    }

    return (int)$matches[0]['id'];
}

function resolveBulkContactListId($contactListId, $contactListName, $contactBatch, $listsById, $listsByName) {
    $contactListId = (int)$contactListId;
    $contactListName = trim((string)$contactListName);
    $contactBatch = trim((string)$contactBatch);

    if ($contactListId && isset($listsById[$contactListId])) {
        if ($contactBatch === '' || bulkListHasBatch($contactListId, $contactBatch)) {
            return $contactListId;
        }

        $selectedListName = $contactListName !== '' ? $contactListName : ($listsById[$contactListId]['name'] ?? '');
        $matchingListId = resolveBulkContactListByName($selectedListName, $contactBatch, $listsByName);
        if ($matchingListId && $matchingListId !== $contactListId && bulkListHasBatch($matchingListId, $contactBatch)) {
            return $matchingListId;
        }

        return $contactListId;
    }

    return resolveBulkContactListByName($contactListName, $contactBatch, $listsByName);
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
    if ($templateName === '' && $templateId !== '' && !isset($templatesById[$templateId])) {
        $templateName = $templateId;
    }
    $smtpAccountIdRaw = trim((string)($row['smtp_account_id'] ?? ''));
    $smtpAccountId = ctype_digit($smtpAccountIdRaw) ? (int)$smtpAccountIdRaw : 0;
    $smtpAccountName = trim((string)($row['smtp_account'] ?? $row['smtp_account_name'] ?? ''));
    if (!$smtpAccountId && $smtpAccountName === '' && $smtpAccountIdRaw !== '') {
        $smtpAccountName = $smtpAccountIdRaw;
    }
    $contactListIdRaw = trim((string)($row['contact_list_id'] ?? ''));
    $contactListId = ctype_digit($contactListIdRaw) ? (int)$contactListIdRaw : 0;
    $contactListName = trim((string)($row['contact_list'] ?? $row['contact_list_name'] ?? $row['list_name'] ?? ''));
    if (!$contactListId && $contactListName === '' && $contactListIdRaw !== '') {
        $contactListName = $contactListIdRaw;
    }
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
    $contactListId = resolveBulkContactListId($contactListId, $contactListName, $contactBatch, $listsById, $listsByName);

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

    $templateBodyHtml = mailpilotRenderBuilderHtml($template['body_html'] ?? '');

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
                $templateBodyHtml,
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
            $templateBodyHtml,
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
