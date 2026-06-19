<?php
/**
 * API: Export Campaign Recipients with Open Status
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$campaignId = (int)($_GET['id'] ?? 0);
$format = strtolower($_GET['format'] ?? 'csv');

if (!$campaignId) {
    http_response_code(400);
    exit('Campaign id is required.');
}

if (!in_array($format, ['csv', 'xls'], true)) {
    http_response_code(400);
    exit('Unsupported export format.');
}

ensureCampaignOpenTrackingTable();
ensureCampaignBatchColumn();

$campaign = dbFetchOne("
    SELECT c.*, s.from_email, cl.name as list_name
    FROM campaigns c
    LEFT JOIN smtp_accounts s ON c.smtp_account_id = s.id
    LEFT JOIN contact_lists cl ON c.contact_list_id = cl.id
    WHERE c.id = ?
", [$campaignId]);

if (!$campaign) {
    http_response_code(404);
    exit('Campaign not found.');
}

$rows = dbFetchAll("
    SELECT
        eq.id as queue_id,
        eq.to_email,
        eq.to_name,
        eq.subject,
        eq.body_html,
        eq.status as queue_status,
        eq.scheduled_at,
        eq.sent_at,
        eq.error_message,
        cot.opened_at,
        COALESCE(cot.open_count, 0) as open_count,
        cot.ip_address
    FROM email_queue eq
    LEFT JOIN campaign_open_tracking cot ON cot.queue_id = eq.id
    WHERE eq.campaign_id = ?
    ORDER BY eq.id ASC
", [$campaignId]);

$headers = [
    'campaign_id',
    'campaign_name',
    'campaign_status',
    'list_name',
    'batch',
    'from_email',
    'recipient_email',
    'recipient_name',
    'subject',
    'send_status',
    'opened',
    'open_count',
    'opened_at',
    'open_ip',
    'scheduled_at',
    'sent_at',
    'error_message',
    'email_body_html',
];

$filenameBase = preg_replace('/[^A-Za-z0-9_-]+/', '-', $campaign['name']);
$filenameBase = trim($filenameBase, '-') ?: 'campaign-' . $campaignId;

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '-report.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);

    foreach ($rows as $row) {
        fputcsv($out, [
            $campaignId,
            $campaign['name'],
            $campaign['status'],
            $campaign['list_name'] ?? '',
            $campaign['contact_batch'] ?? '',
            $campaign['from_email'] ?? '',
            $row['to_email'],
            $row['to_name'],
            $row['subject'],
            $row['queue_status'],
            !empty($row['opened_at']) ? 'Opened' : 'Not opened',
            (int)$row['open_count'],
            $row['opened_at'] ?? '',
            $row['ip_address'] ?? '',
            $row['scheduled_at'] ?? '',
            $row['sent_at'] ?? '',
            $row['error_message'] ?? '',
            mailpilotRenderBuilderHtml($row['body_html'] ?? $campaign['body_html']),
        ]);
    }

    fclose($out);
    exit;
}

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filenameBase . '-report.xls"');
echo "\xEF\xBB\xBF";
?>
<table border="1">
    <thead>
        <tr>
            <?php foreach ($headers as $header): ?>
                <th><?= e($header) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= e($campaignId) ?></td>
                <td><?= e($campaign['name']) ?></td>
                <td><?= e($campaign['status']) ?></td>
                <td><?= e($campaign['list_name'] ?? '') ?></td>
                <td><?= e($campaign['contact_batch'] ?? '') ?></td>
                <td><?= e($campaign['from_email'] ?? '') ?></td>
                <td><?= e($row['to_email']) ?></td>
                <td><?= e($row['to_name']) ?></td>
                <td><?= e($row['subject']) ?></td>
                <td><?= e($row['queue_status']) ?></td>
                <td><?= !empty($row['opened_at']) ? 'Opened' : 'Not opened' ?></td>
                <td><?= (int)$row['open_count'] ?></td>
                <td><?= e($row['opened_at'] ?? '') ?></td>
                <td><?= e($row['ip_address'] ?? '') ?></td>
                <td><?= e($row['scheduled_at'] ?? '') ?></td>
                <td><?= e($row['sent_at'] ?? '') ?></td>
                <td><?= e($row['error_message'] ?? '') ?></td>
                <td><?= e(mailpilotRenderBuilderHtml($row['body_html'] ?? $campaign['body_html'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
