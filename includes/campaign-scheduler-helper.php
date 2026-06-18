<?php
/**
 * Shared campaign scheduling helpers.
 */

function scheduleCampaignQueue($campaignId, $subject, $bodyHtml, $smtpAccountId, $contactListId, $contactBatch, $scheduledAt, $minDelay, $maxDelay) {
    $contacts = dbFetchAll(
        "SELECT c.*, cl.name as list_name FROM contacts c
         JOIN contact_lists cl ON c.list_id = cl.id
         WHERE c.list_id = ? AND c.is_unsubscribed = 0",
        [$contactListId]
    );

    $contactBatch = trim((string)$contactBatch);
    if ($contactBatch !== '') {
        $contacts = array_values(array_filter($contacts, function($contact) use ($contactBatch) {
            return getContactBatchValue($contact) === $contactBatch;
        }));
    }

    if (empty($contacts)) {
        return [
            'success' => false,
            'message' => $contactBatch !== ''
                ? 'No active contacts found for badge/batch "' . $contactBatch . '".'
                : 'No active contacts found in the selected list.',
            'queued' => 0,
        ];
    }

    dbExecute("DELETE FROM email_queue WHERE campaign_id = ?", [$campaignId]);
    dbExecute("DELETE FROM click_tracking WHERE campaign_id = ?", [$campaignId]);
    ensureCampaignOpenTrackingTable();
    dbExecute("DELETE FROM campaign_open_tracking WHERE campaign_id = ?", [$campaignId]);

    $startTime = $scheduledAt ? strtotime($scheduledAt) : time();
    if ($startTime === false || $startTime < time()) {
        $startTime = time();
    }

    $minDelay = max(10, (int)$minDelay);
    $maxDelay = max($minDelay, (int)$maxDelay);
    $currentTime = $startTime;
    $totalQueued = 0;

    foreach ($contacts as $index => $contact) {
        $renderedSubject = replaceShortcodes($subject, $contact, [
            'list_name' => $contact['list_name'] ?? '',
        ]);
        $renderedBody = replaceShortcodes($bodyHtml, $contact, [
            'list_name' => $contact['list_name'] ?? '',
        ]);

        if ($index > 0) {
            $currentTime += rand($minDelay, $maxDelay);
        }

        dbInsert(
            "INSERT INTO email_queue (campaign_id, contact_id, smtp_account_id, to_email, to_name, subject, body_html, status, scheduled_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
            [
                $campaignId,
                $contact['id'],
                $smtpAccountId,
                $contact['email'],
                $contact['name'],
                $renderedSubject,
                $renderedBody,
                date('Y-m-d H:i:s', $currentTime),
            ]
        );

        $totalQueued++;
    }

    dbExecute(
        "UPDATE campaigns SET status = 'scheduled', total_emails = ?, sent_count = 0, failed_count = 0,
         scheduled_at = ?, updated_at = NOW() WHERE id = ?",
        [$totalQueued, date('Y-m-d H:i:s', $startTime), $campaignId]
    );

    return [
        'success' => true,
        'message' => "Campaign scheduled with {$totalQueued} queued emails.",
        'queued' => $totalQueued,
    ];
}
