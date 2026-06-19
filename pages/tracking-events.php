<?php
/**
 * Global Tracking Events
 *
 * Read-only drilldown for dashboard open and click totals.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

ensureCampaignOpenTrackingTable();

$typeParam = strtolower(trim($_GET['type'] ?? 'opens'));
$isClicks = $typeParam === 'clicks';
$type = $isClicks ? 'clicks' : 'opens';
$pageTitle = $isClicks ? 'Link Clicks' : 'Email Opens';

$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedPerPage = [50, 100, 250, 500];
$perPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 100;
}
$offset = ($page - 1) * $perPage;

$table = $isClicks ? 'click_tracking' : 'campaign_open_tracking';
$alias = $isClicks ? 'ct' : 'cot';
$eventColumn = $isClicks ? 'clicked_at' : 'opened_at';
$countColumn = $isClicks ? 'click_count' : 'open_count';
$eventNoun = $isClicks ? 'click' : 'open';
$eventLabel = $isClicks ? 'Clicked' : 'Opened';
$countLabel = $isClicks ? 'Clicks' : 'Opens';

$joins = "
    LEFT JOIN email_queue eq ON {$alias}.queue_id = eq.id
    LEFT JOIN contacts c ON {$alias}.contact_id = c.id
    LEFT JOIN campaigns cam ON {$alias}.campaign_id = cam.id
    LEFT JOIN smtp_accounts s ON s.id = COALESCE(eq.smtp_account_id, cam.smtp_account_id)
";

$where = ["{$alias}.{$eventColumn} IS NOT NULL"];
$params = [];

if ($search !== '') {
    $searchColumns = [
        'eq.to_email',
        'eq.to_name',
        'c.email',
        'c.name',
        'cam.name',
        'cam.subject',
        's.from_email',
        's.label',
        'eq.subject',
    ];

    if ($isClicks) {
        $searchColumns[] = "{$alias}.original_url";
    }

    $where[] = '(' . implode(' OR ', array_map(static function ($column) {
        return "{$column} LIKE ?";
    }, $searchColumns)) . ')';

    $like = "%{$search}%";
    foreach ($searchColumns as $_) {
        $params[] = $like;
    }
}

$whereSql = implode(' AND ', $where);
$totalEvents = (int) dbFetchValue("SELECT COUNT(*) FROM {$table} {$alias} {$joins} WHERE {$whereSql}", $params);
$totalPages = max(1, (int)ceil($totalEvents / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$urlSelect = $isClicks ? ", {$alias}.original_url" : "";
$events = dbFetchAll("
    SELECT
        {$alias}.id,
        {$alias}.campaign_id,
        {$alias}.contact_id,
        {$alias}.queue_id,
        {$alias}.{$eventColumn} AS event_at,
        {$alias}.{$countColumn} AS event_count,
        {$alias}.ip_address,
        {$alias}.user_agent
        {$urlSelect},
        eq.to_email,
        eq.to_name,
        eq.subject AS queued_subject,
        eq.sent_at,
        eq.scheduled_at,
        c.email AS contact_email,
        c.name AS contact_name,
        cam.name AS campaign_name,
        cam.subject AS campaign_subject,
        s.from_email AS smtp_from_email,
        s.label AS smtp_label
    FROM {$table} {$alias}
    {$joins}
    WHERE {$whereSql}
    ORDER BY {$alias}.{$eventColumn} DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

$buildUrl = static function (array $overrides = []) use ($type, $search, $perPage) {
    $query = [
        'type' => $type,
        'per_page' => $perPage,
    ];

    if ($search !== '') {
        $query['q'] = $search;
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return '?' . http_build_query($query);
};

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon"><?= $isClicks ? 'Click' : 'Open' ?></span><?= e($pageTitle) ?></h1>
        <div class="subtitle">
            Read-only tracking report across all campaigns. Sending campaigns are not changed here.
        </div>
    </div>
    <div class="btn-group">
        <a href="?type=opens" class="btn <?= !$isClicks ? 'btn-primary' : 'btn-outline' ?>">Email Opens</a>
        <a href="?type=clicks" class="btn <?= $isClicks ? 'btn-primary' : 'btn-outline' ?>">Link Clicks</a>
        <a href="<?= $basePath ?>/index.php" class="btn btn-outline">Dashboard</a>
    </div>
</div>

<div class="stat-cards">
    <div class="stat-card <?= $isClicks ? 'card-orange' : 'card-green' ?>">
        <div class="stat-icon"><?= $isClicks ? 'URL' : 'Open' ?></div>
        <div class="stat-value"><?= number_format($totalEvents) ?></div>
        <div class="stat-label">Tracked <?= e($countLabel) ?></div>
    </div>
    <div class="stat-card card-cyan">
        <div class="stat-icon">Rows</div>
        <div class="stat-value"><?= number_format(count($events)) ?></div>
        <div class="stat-label">Showing on This Page</div>
    </div>
    <div class="stat-card card-purple">
        <div class="stat-icon">Page</div>
        <div class="stat-value"><?= number_format($page) ?></div>
        <div class="stat-label">of <?= number_format($totalPages) ?></div>
    </div>
</div>

<div class="card mb-6">
    <div class="card-body" style="padding: 16px 24px;">
        <form method="GET" action="" class="d-flex gap-2" style="flex-wrap: wrap;">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="text" name="q" class="form-control" placeholder="Search recipient, name, campaign, subject, or SMTP..." value="<?= e($search) ?>" style="max-width: 560px;">
            <select name="per_page" class="form-control" style="max-width: 150px;" onchange="this.form.submit()">
                <?php foreach ($allowedPerPage as $option): ?>
                    <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?> / page</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline">Search</button>
            <?php if ($search !== ''): ?>
                <a href="?type=<?= e($type) ?>&per_page=<?= $perPage ?>" class="btn btn-ghost">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= e($pageTitle) ?> Details</h2>
        <span class="text-muted fs-sm"><?= number_format($totalEvents) ?> total <?= e($eventNoun) ?> records</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($events)): ?>
            <div class="empty-state">
                <div class="empty-icon"><?= $isClicks ? 'Clicks' : 'Opens' ?></div>
                <h3>No <?= e($eventNoun) ?> records found</h3>
                <p><?= $search !== '' ? 'Try a different search term.' : 'Tracking records will appear here after recipients interact with sent emails.' ?></p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Recipient</th>
                            <th>Campaign</th>
                            <th>SMTP From</th>
                            <?php if ($isClicks): ?>
                                <th>Clicked URL</th>
                            <?php endif; ?>
                            <th><?= e($countLabel) ?></th>
                            <th><?= e($eventLabel) ?> At</th>
                            <th>Sent At</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event):
                            $recipientEmail = $event['to_email'] ?: ($event['contact_email'] ?: '');
                            $recipientName = $event['to_name'] ?: ($event['contact_name'] ?: '');
                            $campaignName = $event['campaign_name'] ?: 'Campaign #' . (int)$event['campaign_id'];
                            $campaignSubject = $event['queued_subject'] ?: ($event['campaign_subject'] ?: '');
                            $smtpFrom = $event['smtp_from_email'] ?: '';
                            $smtpLabel = $event['smtp_label'] ?: '';
                            $sentAt = $event['sent_at'] ?: $event['scheduled_at'];
                        ?>
                        <tr>
                            <td>
                                <strong style="color: var(--text-primary);"><?= e($recipientEmail ?: 'Unknown recipient') ?></strong>
                                <?php if ($recipientName !== ''): ?>
                                    <div class="text-muted fs-sm"><?= e($recipientName) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($event['campaign_id'])): ?>
                                    <a href="<?= $basePath ?>/pages/campaign-view.php?id=<?= (int)$event['campaign_id'] ?>" style="color: var(--text-primary); font-weight: 600;">
                                        <?= e($campaignName) ?>
                                    </a>
                                <?php else: ?>
                                    <strong style="color: var(--text-primary);"><?= e($campaignName) ?></strong>
                                <?php endif; ?>
                                <?php if ($campaignSubject !== ''): ?>
                                    <div class="text-muted fs-sm"><?= e($campaignSubject) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: var(--text-primary);"><?= e($smtpFrom ?: 'Unknown SMTP') ?></strong>
                                <?php if ($smtpLabel !== '' && $smtpLabel !== $smtpFrom): ?>
                                    <div class="text-muted fs-sm"><?= e($smtpLabel) ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if ($isClicks): ?>
                                <td>
                                    <?php if (!empty($event['original_url'])): ?>
                                        <a href="<?= e($event['original_url']) ?>" target="_blank" rel="noopener" class="text-muted fs-sm"
                                           style="max-width: 340px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?= e($event['original_url']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted fs-sm">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <strong style="color: var(--text-primary);"><?= number_format((int)$event['event_count']) ?></strong>
                            </td>
                            <td>
                                <strong style="color: var(--text-primary);"><?= formatDateTime($event['event_at']) ?></strong>
                                <div class="text-muted fs-sm"><?= timeAgo($event['event_at']) ?></div>
                            </td>
                            <td><?= $sentAt ? formatDateTime($sentAt) : '-' ?></td>
                            <td class="text-muted fs-sm"><?= e($event['ip_address'] ?: '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="card-footer" style="justify-content: center; gap: 8px;">
                <?php if ($page > 1): ?>
                    <a href="<?= e($buildUrl(['page' => $page - 1])) ?>" class="btn btn-outline btn-sm">Prev</a>
                <?php endif; ?>
                <span class="text-muted fs-sm">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e($buildUrl(['page' => $page + 1])) ?>" class="btn btn-outline btn-sm">Next</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
