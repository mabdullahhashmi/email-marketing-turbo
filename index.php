<?php
/**
 * Dashboard - Main Overview
 */
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

// Fetch stats
$totalCampaigns = getCount('campaigns');
$totalContacts = getCount('contacts', 'is_unsubscribed = 0');
$totalSent = (int) dbFetchValue("SELECT COALESCE(SUM(sent_count), 0) FROM campaigns");
$totalClicks = getCount('click_tracking', 'clicked_at IS NOT NULL');

$activeCampaignsData = dbFetchAll("
    SELECT c.*, s.label as smtp_label, s.from_email, cl.name as list_name
    FROM campaigns c 
    LEFT JOIN smtp_accounts s ON c.smtp_account_id = s.id
    LEFT JOIN contact_lists cl ON c.contact_list_id = cl.id
    WHERE c.status IN ('sending', 'scheduled')
    ORDER BY c.scheduled_at ASC 
    LIMIT 5
");

$recentCampaigns = dbFetchAll("
    SELECT c.*, s.from_email, cl.name as list_name
    FROM campaigns c 
    LEFT JOIN smtp_accounts s ON c.smtp_account_id = s.id
    LEFT JOIN contact_lists cl ON c.contact_list_id = cl.id
    ORDER BY c.created_at DESC 
    LIMIT 10
");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">📊</span>Dashboard</h1>
        <div class="subtitle">Overview of your email marketing campaigns</div>
    </div>
    <a href="<?= $basePath ?>/pages/campaign-create.php" class="btn btn-primary">
        ✚ New Campaign
    </a>
</div>

<!-- Stat Cards -->
<div class="stat-cards">
    <div class="stat-card card-purple">
        <div class="stat-icon">📨</div>
        <div class="stat-value"><?= number_format($totalCampaigns) ?></div>
        <div class="stat-label">Total Campaigns</div>
    </div>
    <div class="stat-card card-cyan">
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?= number_format($totalContacts) ?></div>
        <div class="stat-label">Active Contacts</div>
    </div>
    <div class="stat-card card-green">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?= number_format($totalSent) ?></div>
        <div class="stat-label">Emails Sent</div>
    </div>
    <div class="stat-card card-orange">
        <div class="stat-icon">🖱️</div>
        <div class="stat-value"><?= number_format($totalClicks) ?></div>
        <div class="stat-label">Link Clicks</div>
    </div>
</div>

<!-- Active Campaigns -->
<?php if (!empty($activeCampaignsData)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h2>⚡ Active Campaigns</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Campaign</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Scheduled</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeCampaignsData as $c): 
                        $pct = $c['total_emails'] > 0 ? round(($c['sent_count'] / $c['total_emails']) * 100) : 0;
                    ?>
                    <tr>
                        <td>
                            <strong style="color: var(--text-primary);"><?= e($c['name']) ?></strong>
                            <div class="text-muted fs-sm"><?= e($c['from_email'] ?? '') ?></div>
                        </td>
                        <td><?= statusBadge($c['status']) ?></td>
                        <td style="min-width: 150px;">
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?= $pct ?>%"></div>
                            </div>
                            <div class="progress-text"><?= $c['sent_count'] ?> / <?= $c['total_emails'] ?> (<?= $pct ?>%)</div>
                        </td>
                        <td><?= formatDateTime($c['scheduled_at']) ?></td>
                        <td>
                            <a href="<?= $basePath ?>/pages/campaign-view.php?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">View →</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Campaigns -->
<div class="card">
    <div class="card-header">
        <h2>📋 Recent Campaigns</h2>
        <a href="<?= $basePath ?>/pages/campaigns.php" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($recentCampaigns)): ?>
            <div class="empty-state">
                <div class="empty-icon">📨</div>
                <h3>No campaigns yet</h3>
                <p>Create your first email campaign to get started.</p>
                <a href="<?= $basePath ?>/pages/campaign-create.php" class="btn btn-primary">✚ Create Campaign</a>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>List</th>
                            <th>Status</th>
                            <th>Sent</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentCampaigns as $c): ?>
                        <tr>
                            <td>
                                <strong style="color: var(--text-primary);"><?= e($c['name']) ?></strong>
                                <div class="text-muted fs-sm"><?= e($c['subject']) ?></div>
                            </td>
                            <td><?= e($c['list_name'] ?? '—') ?></td>
                            <td><?= statusBadge($c['status']) ?></td>
                            <td><?= $c['sent_count'] ?> / <?= $c['total_emails'] ?></td>
                            <td><?= timeAgo($c['created_at']) ?></td>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
