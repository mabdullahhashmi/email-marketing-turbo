<?php
/**
 * Campaigns Listing
 */
$pageTitle = 'Campaigns';
require_once __DIR__ . '/../includes/header.php';

$statusFilter = $_GET['status'] ?? '';
$whereClause = '1=1';
$params = [];

if ($statusFilter && in_array($statusFilter, ['draft', 'scheduled', 'sending', 'completed', 'paused'])) {
    $whereClause = "c.status = ?";
    $params[] = $statusFilter;
}

$campaigns = dbFetchAll("
    SELECT c.*, s.from_email, s.label as smtp_label, cl.name as list_name
    FROM campaigns c 
    LEFT JOIN smtp_accounts s ON c.smtp_account_id = s.id
    LEFT JOIN contact_lists cl ON c.contact_list_id = cl.id
    WHERE {$whereClause}
    ORDER BY c.created_at DESC
", $params);

// Get status counts
$statusCounts = dbFetchAll("SELECT status, COUNT(*) as cnt FROM campaigns GROUP BY status");
$counts = [];
foreach ($statusCounts as $sc) {
    $counts[$sc['status']] = $sc['cnt'];
}
$totalCount = array_sum($counts);
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">📨</span>Campaigns</h1>
        <div class="subtitle"><?= $totalCount ?> total campaigns</div>
    </div>
    <a href="<?= $basePath ?>/pages/campaign-create.php" class="btn btn-primary">
        ✚ New Campaign
    </a>
</div>

<!-- Status Filter Tabs -->
<div style="display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap;">
    <a href="?status=" class="btn <?= !$statusFilter ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        All (<?= $totalCount ?>)
    </a>
    <a href="?status=draft" class="btn <?= $statusFilter === 'draft' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        Draft (<?= $counts['draft'] ?? 0 ?>)
    </a>
    <a href="?status=scheduled" class="btn <?= $statusFilter === 'scheduled' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        Scheduled (<?= $counts['scheduled'] ?? 0 ?>)
    </a>
    <a href="?status=sending" class="btn <?= $statusFilter === 'sending' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        Sending (<?= $counts['sending'] ?? 0 ?>)
    </a>
    <a href="?status=completed" class="btn <?= $statusFilter === 'completed' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        Completed (<?= $counts['completed'] ?? 0 ?>)
    </a>
    <a href="?status=paused" class="btn <?= $statusFilter === 'paused' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        Paused (<?= $counts['paused'] ?? 0 ?>)
    </a>
</div>

<!-- Campaigns Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($campaigns)): ?>
            <div class="empty-state">
                <div class="empty-icon">📨</div>
                <h3><?= $statusFilter ? 'No ' . $statusFilter . ' campaigns' : 'No campaigns yet' ?></h3>
                <p>Create your first email campaign to start reaching your audience.</p>
                <a href="<?= $basePath ?>/pages/campaign-create.php" class="btn btn-primary">✚ Create Campaign</a>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>List</th>
                            <th>SMTP</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Scheduled</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaigns as $c): 
                            $pct = $c['total_emails'] > 0 ? round(($c['sent_count'] / $c['total_emails']) * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong style="color: var(--text-primary);"><?= e($c['name']) ?></strong>
                                <div class="text-muted fs-sm" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= e($c['subject']) ?></div>
                            </td>
                            <td><?= e($c['list_name'] ?? '—') ?></td>
                            <td><span class="text-muted fs-sm"><?= e($c['from_email'] ?? '—') ?></span></td>
                            <td><?= statusBadge($c['status']) ?></td>
                            <td style="min-width: 120px;">
                                <?php if ($c['total_emails'] > 0): ?>
                                <div class="progress-bar-container">
                                    <div class="progress-bar-fill" style="width: <?= $pct ?>%"></div>
                                </div>
                                <div class="progress-text"><?= $c['sent_count'] ?>/<?= $c['total_emails'] ?></div>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $c['scheduled_at'] ? formatDateTime($c['scheduled_at']) : '—' ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="<?= $basePath ?>/pages/campaign-view.php?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">View</a>
                                    <?php if ($c['status'] === 'draft'): ?>
                                        <a href="<?= $basePath ?>/pages/campaign-create.php?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
