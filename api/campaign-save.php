<?php
/**
 * API: Save Campaign (Draft) + Optionally Schedule
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['success' => false, 'message' => 'Invalid request data'], 400);
}

validateCSRF($input['csrf_token'] ?? '');

$id = (int)($input['id'] ?? 0);
$name = trim($input['name'] ?? '');
$subject = trim($input['subject'] ?? '');
$bodyHtml = $input['body_html'] ?? '';
$smtpAccountId = (int)($input['smtp_account_id'] ?? 0);
$contactListId = (int)($input['contact_list_id'] ?? 0);
$scheduledAt = $input['scheduled_at'] ?? '';
$minDelay = max(10, (int)($input['min_delay_seconds'] ?? 60));
$maxDelay = max($minDelay, (int)($input['max_delay_seconds'] ?? 3600));
$doSchedule = !empty($input['schedule']);

// Validate
if (!$name) {
    jsonResponse(['success' => false, 'message' => 'Campaign name is required.'], 400);
}
if (!$subject) {
    jsonResponse(['success' => false, 'message' => 'Subject line is required.'], 400);
}

try {
    if ($id > 0) {
        // Check campaign exists and is draft
        $existing = dbFetchOne("SELECT id, status FROM campaigns WHERE id = ?", [$id]);
        if (!$existing) {
            jsonResponse(['success' => false, 'message' => 'Campaign not found.'], 404);
        }
        if ($existing['status'] !== 'draft') {
            jsonResponse(['success' => false, 'message' => 'Only draft campaigns can be edited.'], 400);
        }
        
        // Update
        dbExecute(
            "UPDATE campaigns SET name = ?, subject = ?, body_html = ?, smtp_account_id = ?, contact_list_id = ?, 
             scheduled_at = ?, min_delay_seconds = ?, max_delay_seconds = ?, updated_at = NOW() WHERE id = ?",
            [$name, $subject, $bodyHtml, $smtpAccountId ?: null, $contactListId ?: null,
             $scheduledAt ?: null, $minDelay, $maxDelay, $id]
        );
    } else {
        // Create new
        $id = dbInsert(
            "INSERT INTO campaigns (name, subject, body_html, smtp_account_id, contact_list_id, 
             scheduled_at, min_delay_seconds, max_delay_seconds, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
            [$name, $subject, $bodyHtml, $smtpAccountId ?: null, $contactListId ?: null,
             $scheduledAt ?: null, $minDelay, $maxDelay]
        );
    }
    
    // Schedule the campaign
    if ($doSchedule) {
        if (!$smtpAccountId) {
            jsonResponse(['success' => false, 'message' => 'Please select an SMTP account to schedule.'], 400);
        }
        if (!$contactListId) {
            jsonResponse(['success' => false, 'message' => 'Please select a contact list to schedule.'], 400);
        }
        
        // Get active contacts from the list
        $contacts = dbFetchAll(
            "SELECT c.*, cl.name as list_name FROM contacts c 
             JOIN contact_lists cl ON c.list_id = cl.id
             WHERE c.list_id = ? AND c.is_unsubscribed = 0",
            [$contactListId]
        );
        
        if (empty($contacts)) {
            jsonResponse(['success' => false, 'message' => 'No active contacts in the selected list.'], 400);
        }
        
        // Clear any existing queue for this campaign
        dbExecute("DELETE FROM email_queue WHERE campaign_id = ?", [$id]);
        dbExecute("DELETE FROM click_tracking WHERE campaign_id = ?", [$id]);
        
        // Calculate scheduled times with random delays
        $startTime = $scheduledAt ? strtotime($scheduledAt) : time();
        if ($startTime < time()) {
            $startTime = time();
        }
        
        $currentTime = $startTime;
        $totalQueued = 0;
        
        foreach ($contacts as $index => $contact) {
            // Replace shortcodes
            $renderedSubject = replaceShortcodes($subject, $contact, [
                'list_name' => $contact['list_name'] ?? '',
            ]);
            $renderedBody = replaceShortcodes($bodyHtml, $contact, [
                'list_name' => $contact['list_name'] ?? '',
            ]);
            
            // Add random delay after first email
            if ($index > 0) {
                $currentTime += rand($minDelay, $maxDelay);
            }
            
            $scheduledDateTime = date('Y-m-d H:i:s', $currentTime);
            
            // Insert queue item
            $queueId = dbInsert(
                "INSERT INTO email_queue (campaign_id, contact_id, smtp_account_id, to_email, to_name, subject, body_html, status, scheduled_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
                [$id, $contact['id'], $smtpAccountId, $contact['email'], $contact['name'], $renderedSubject, $renderedBody, $scheduledDateTime]
            );
            
            $totalQueued++;
        }
        
        // Update campaign status
        dbExecute(
            "UPDATE campaigns SET status = 'scheduled', total_emails = ?, sent_count = 0, failed_count = 0, 
             scheduled_at = ?, updated_at = NOW() WHERE id = ?",
            [$totalQueued, date('Y-m-d H:i:s', $startTime), $id]
        );
        
        jsonResponse([
            'success' => true,
            'message' => "Campaign scheduled! {$totalQueued} emails queued.",
            'campaign_id' => $id,
            'queued' => $totalQueued,
        ]);
    }
    
    jsonResponse([
        'success' => true,
        'message' => 'Campaign saved as draft.',
        'campaign_id' => $id,
    ]);
    
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
