<?php
/**
 * Campaign View - Stats & Email Queue
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$campaignId = (int)($_GET['id'] ?? 0);
if (!$campaignId) {
    header('Location: campaigns.php');
    exit;
}

$campaign = dbFetchOne("
    SELECT c.*, s.label as smtp_label, s.from_email, cl.name as list_name
    FROM campaigns c
    LEFT JOIN smtp_accounts s ON c.smtp_account_id = s.id
    LEFT JOIN contact_lists cl ON c.contact_list_id = cl.id
    WHERE c.id = ?
", [$campaignId]);

if (!$campaign) {
    header('Location: campaigns.php');
    exit;
}

$pageTitle = $campaign['name'];
require_once __DIR__ . '/../includes/header.php';

// Queue status counts
$pendingCount = getCount('email_queue', 'campaign_id = ? AND status = ?', [$campaignId, 'pending']);
$sentCount = $campaign['sent_count'];
$failedCount = $campaign['failed_count'];
$clickCount = getCount('click_tracking', 'campaign_id = ? AND clicked_at IS NOT NULL', [$campaignId]);

// Queue items
$queuePage = max(1, (int)($_GET['qpage'] ?? 1));
$queuePerPage = 25;
$queueOffset = ($queuePage - 1) * $queuePerPage;
$totalQueueItems = getCount('email_queue', 'campaign_id = ?', [$campaignId]);
$totalQueuePages = max(1, ceil($totalQueueItems / $queuePerPage));

$queueItems = dbFetchAll("
    SELECT eq.*, c.email as contact_email, c.name as contact_name
    FROM email_queue eq
    LEFT JOIN contacts c ON eq.contact_id = c.id
    WHERE eq.campaign_id = ?
    ORDER BY eq.scheduled_at ASC
    LIMIT {$queuePerPage} OFFSET {$queueOffset}
", [$campaignId]);

// Click tracking data
$clicks = dbFetchAll("
    SELECT ct.*, c.email, c.name
    FROM click_tracking ct
    LEFT JOIN contacts c ON ct.contact_id = c.id
    WHERE ct.campaign_id = ? AND ct.clicked_at IS NOT NULL
    ORDER BY ct.clicked_at DESC
    LIMIT 50
", [$campaignId]);

$pct = $campaign['total_emails'] > 0 ? round(($sentCount / $campaign['total_emails']) * 100) : 0;
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">📨</span><?= e($campaign['name']) ?></h1>
        <div class="subtitle"><?= e($campaign['subject']) ?></div>
    </div>
    <div class="btn-group">
        <?php if ($campaign['status'] === 'draft'): ?>
            <a href="campaign-create.php?id=<?= $campaignId ?>" class="btn btn-primary">✏️ Edit</a>
        <?php elseif (in_array($campaign['status'], ['sending', 'scheduled'])): ?>
            <button class="btn btn-outline" onclick="pauseCampaign(<?= $campaignId ?>)">⏸ Pause</button>
        <?php elseif ($campaign['status'] === 'paused'): ?>
            <button class="btn btn-success" onclick="resumeCampaign(<?= $campaignId ?>)">▶ Resume</button>
        <?php endif; ?>
        <button class="btn btn-outline" onclick="deleteCampaign(<?= $campaignId ?>)">🗑️ Delete</button>
        <a href="<?= $basePath ?>/pages/campaigns.php" class="btn btn-outline">← Back</a>
    </div>
</div>

<!-- Status & Progress -->
<div class="card mb-6">
    <div class="card-body">
        <div class="d-flex align-center gap-3 mb-4" style="flex-wrap: wrap;">
            <span id="statusBadge" class="<?= 'badge badge-' . $campaign['status'] ?>" style="font-size: 14px; padding: 6px 16px;">
                <?= ucfirst($campaign['status']) ?>
            </span>
            <span class="text-muted">•</span>
            <span class="text-muted fs-sm">From: <strong style="color: var(--text-primary);"><?= e($campaign['from_email'] ?? '—') ?></strong></span>
            <span class="text-muted">•</span>
            <span class="text-muted fs-sm">List: <strong style="color: var(--text-primary);"><?= e($campaign['list_name'] ?? '—') ?></strong></span>
            <?php if ($campaign['scheduled_at']): ?>
                <span class="text-muted">•</span>
                <span class="text-muted fs-sm">Scheduled: <strong style="color: var(--text-primary);"><?= formatDateTime($campaign['scheduled_at']) ?></strong></span>
            <?php endif; ?>
        </div>
        
        <?php if ($campaign['total_emails'] > 0): ?>
        <div class="progress-bar-container" style="height: 12px;">
            <div class="progress-bar-fill" id="progressFill" style="width: <?= $pct ?>%"></div>
        </div>
        <div class="progress-text" id="progressText" style="font-size: 14px;">
            <?= $sentCount ?> / <?= $campaign['total_emails'] ?> emails sent (<?= $pct ?>%)
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid mb-6">
    <div class="stat-item">
        <div class="stat-number" id="statSent" style="color: var(--color-success);"><?= $sentCount ?></div>
        <div class="stat-label">Sent</div>
    </div>
    <div class="stat-item">
        <div class="stat-number" id="statPending" style="color: var(--color-warning);"><?= $pendingCount ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-item">
        <div class="stat-number" id="statFailed" style="color: var(--color-danger);"><?= $failedCount ?></div>
        <div class="stat-label">Failed</div>
    </div>
    <div class="stat-item">
        <div class="stat-number" id="statClicked" style="color: var(--color-info);"><?= $clickCount ?></div>
        <div class="stat-label">Clicks</div>
    </div>
    <div class="stat-item">
        <div class="stat-number"><?= $campaign['total_emails'] ?></div>
        <div class="stat-label">Total</div>
    </div>
</div>

<!-- Email Queue -->
<div class="card mb-6">
    <div class="card-header">
        <h2>📋 Email Queue</h2>
        <span class="text-muted fs-sm"><?= $totalQueueItems ?> items</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($queueItems)): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <h3>Queue is empty</h3>
                <p>No emails have been queued for this campaign yet.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Recipient</th>
                            <th>Status</th>
                            <th>Scheduled</th>
                            <th>Sent At</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queueItems as $qi): ?>
                        <tr>
                            <td>
                                <strong style="color: var(--text-primary);"><?= e($qi['to_email']) ?></strong>
                                <?php if ($qi['to_name']): ?>
                                    <div class="text-muted fs-sm"><?= e($qi['to_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= statusBadge($qi['status']) ?></td>
                            <td><?= formatDateTime($qi['scheduled_at']) ?></td>
                            <td><?= $qi['sent_at'] ? formatDateTime($qi['sent_at']) : '—' ?></td>
                            <td>
                                <?php if ($qi['error_message']): ?>
                                    <span class="text-danger fs-sm" title="<?= e($qi['error_message']) ?>">
                                        <?= e(substr($qi['error_message'], 0, 60)) ?>...
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($totalQueuePages > 1): ?>
            <div class="card-footer" style="justify-content: center; gap: 8px;">
                <?php if ($queuePage > 1): ?>
                    <a href="?id=<?= $campaignId ?>&qpage=<?= $queuePage - 1 ?>" class="btn btn-outline btn-sm">← Prev</a>
                <?php endif; ?>
                <span class="text-muted fs-sm">Page <?= $queuePage ?> of <?= $totalQueuePages ?></span>
                <?php if ($queuePage < $totalQueuePages): ?>
                    <a href="?id=<?= $campaignId ?>&qpage=<?= $queuePage + 1 ?>" class="btn btn-outline btn-sm">Next →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Click Tracking -->
<?php if (!empty($clicks)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h2>🖱️ Click Tracking</h2>
        <span class="text-muted fs-sm"><?= $clickCount ?> clicks</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>URL</th>
                        <th>Clicks</th>
                        <th>Last Click</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clicks as $click): ?>
                    <tr>
                        <td>
                            <strong style="color: var(--text-primary);"><?= e($click['email']) ?></strong>
                            <?php if ($click['name']): ?>
                                <div class="text-muted fs-sm"><?= e($click['name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= e($click['original_url']) ?>" target="_blank" class="text-muted fs-sm" 
                               style="max-width: 300px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= e($click['original_url']) ?>
                            </a>
                        </td>
                        <td><?= $click['click_count'] ?></td>
                        <td><?= timeAgo($click['clicked_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Campaign Config Details -->
<div class="card">
    <div class="card-header">
        <h2>⚙️ Configuration</h2>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div>
                <div class="text-muted fs-sm">SMTP Account</div>
                <div style="color: var(--text-primary); font-weight: 500;"><?= e($campaign['smtp_label'] ?? '—') ?></div>
            </div>
            <div>
                <div class="text-muted fs-sm">Contact List</div>
                <div style="color: var(--text-primary); font-weight: 500;"><?= e($campaign['list_name'] ?? '—') ?></div>
            </div>
            <div>
                <div class="text-muted fs-sm">Delay Range</div>
                <div style="color: var(--text-primary); font-weight: 500;"><?= $campaign['min_delay_seconds'] ?>s — <?= $campaign['max_delay_seconds'] ?>s</div>
            </div>
            <div>
                <div class="text-muted fs-sm">Created</div>
                <div style="color: var(--text-primary); font-weight: 500;"><?= formatDateTime($campaign['created_at']) ?></div>
            </div>
        </div>
    </div>
</div>

<?php
$pageScript = <<<JS
// Start polling if campaign is actively sending
const campaignStatus = '{$campaign['status']}';
if (['sending', 'scheduled'].includes(campaignStatus)) {
    startStatusPolling({$campaignId}, 5000);
}

async function pauseCampaign(id) {
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    Modal.confirm('Pause Campaign?', 'This will stop sending remaining queued emails.', async () => {
        try {
            const result = await apiCall(basePath + '/api/campaign-pause.php', { id: id, action: 'pause' });
            if (result.success) {
                Toast.success('Campaign paused');
                setTimeout(() => location.reload(), 1000);
            }
        } catch (err) {
            Toast.error(err.message);
        }
    });
}

async function resumeCampaign(id) {
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    try {
        const result = await apiCall(basePath + '/api/campaign-pause.php', { id: id, action: 'resume' });
        if (result.success) {
            Toast.success('Campaign resumed');
            setTimeout(() => location.reload(), 1000);
        }
    } catch (err) {
        Toast.error(err.message);
    }
}

async function deleteCampaign(id) {
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    Modal.confirm('Delete Campaign?', 'This will permanently delete the campaign and all its queued emails.', async () => {
        try {
            const result = await apiCall(basePath + '/api/campaign-delete.php', { id: id });
            if (result.success) {
                Toast.success('Campaign deleted');
                setTimeout(() => { window.location = basePath + '/pages/campaigns.php'; }, 1000);
            }
        } catch (err) {
            Toast.error(err.message);
        }
    });
}
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
