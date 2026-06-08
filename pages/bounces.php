<?php
/**
 * Bounce Management Dashboard
 */
$pageTitle = 'Bounce Management';
require_once __DIR__ . '/../includes/header.php';

// Stats
$totalBounces = getCount('bounces');
$hardBounces = getCount('bounces', "bounce_type = 'hard'");
$softBounces = getCount('bounces', "bounce_type = 'soft'");
$complaints = getCount('bounces', "bounce_type = 'complaint'");
$suppressed = getCount('suppression_list');
$imapAccounts = getCount('smtp_accounts', "is_active = 1 AND imap_host IS NOT NULL AND imap_host != ''");

// Filters
$filterType = $_GET['type'] ?? '';
$filterEmail = trim($_GET['email'] ?? '');
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if ($filterType && in_array($filterType, ['hard','soft','complaint','unknown'])) {
    $where .= ' AND b.bounce_type = ?';
    $params[] = $filterType;
}
if ($filterEmail) {
    $where .= ' AND b.email LIKE ?';
    $params[] = "%{$filterEmail}%";
}
if ($filterDateFrom) {
    $where .= ' AND b.created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo) {
    $where .= ' AND b.created_at <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
}

$totalFiltered = (int)dbFetchValue("SELECT COUNT(*) FROM bounces b WHERE $where", $params);
$totalPages = max(1, ceil($totalFiltered / $perPage));

$bounces = dbFetchAll("
    SELECT b.*, s.from_email as smtp_email, c.name as campaign_name
    FROM bounces b
    LEFT JOIN smtp_accounts s ON b.smtp_account_id = s.id
    LEFT JOIN campaigns c ON b.campaign_id = c.id
    WHERE $where
    ORDER BY b.created_at DESC
    LIMIT $perPage OFFSET $offset
", $params);

// Recent suppressions
$recentSuppressed = dbFetchAll("SELECT * FROM suppression_list ORDER BY created_at DESC LIMIT 10");

// Top bouncing domains
$topBounceDomains = dbFetchAll("
    SELECT SUBSTRING_INDEX(email, '@', -1) as domain, COUNT(*) as cnt, 
           SUM(bounce_type = 'hard') as hard_cnt, SUM(bounce_type = 'soft') as soft_cnt
    FROM bounces GROUP BY domain ORDER BY cnt DESC LIMIT 10
");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">🛡️</span>Bounce Management</h1>
        <div class="subtitle">Track bounces, suppress bad addresses, protect sender reputation</div>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <button type="button" class="btn btn-primary" id="scanBounceBtn" onclick="scanBounceInboxes()">Scan Bounce Inboxes</button>
    </div>
</div>

<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-title">Total Bounces</div>
        <div class="stat-value"><?= number_format($totalBounces) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Hard Bounces</div>
        <div class="stat-value" style="color: var(--color-danger);"><?= number_format($hardBounces) ?></div>
        <div class="stat-subtitle">Auto-suppressed</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Soft Bounces</div>
        <div class="stat-value" style="color: var(--color-warning);"><?= number_format($softBounces) ?></div>
        <div class="stat-subtitle">Retried automatically</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Complaints</div>
        <div class="stat-value" style="color: #f97316;"><?= number_format($complaints) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Suppressed Emails</div>
        <div class="stat-value" style="color: var(--text-muted);"><?= number_format($suppressed) ?></div>
        <div class="stat-subtitle">Will never be sent to</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">IMAP Bounce Inboxes</div>
        <div class="stat-value" style="color: var(--color-primary);"><?= number_format($imapAccounts) ?></div>
        <div class="stat-subtitle">Available for delayed scans</div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group" style="margin:0; flex:1; min-width:140px;">
                <label>Type</label>
                <select name="type" class="form-control">
                    <option value="">All</option>
                    <option value="hard" <?= $filterType === 'hard' ? 'selected' : '' ?>>Hard Bounce</option>
                    <option value="soft" <?= $filterType === 'soft' ? 'selected' : '' ?>>Soft Bounce</option>
                    <option value="complaint" <?= $filterType === 'complaint' ? 'selected' : '' ?>>Complaint</option>
                </select>
            </div>
            <div class="form-group" style="margin:0; flex:2; min-width:180px;">
                <label>Email</label>
                <input type="text" name="email" class="form-control" value="<?= e($filterEmail) ?>" placeholder="Search email...">
            </div>
            <div class="form-group" style="margin:0; flex:1; min-width:140px;">
                <label>From</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($filterDateFrom) ?>">
            </div>
            <div class="form-group" style="margin:0; flex:1; min-width:140px;">
                <label>To</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($filterDateTo) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="?" class="btn btn-outline">Clear</a>
        </form>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px;">

    <!-- Bounces Table -->
    <div class="card">
        <div class="card-header">
            <h3>Bounce Log (<?= number_format($totalFiltered) ?> results)</h3>
        </div>
        <div class="card-body" style="padding:0; max-height: 500px; overflow-y: auto;">
            <?php if (empty($bounces)): ?>
                <div style="padding:24px; text-align:center; color: var(--text-muted);">No bounces recorded yet. Bounces are captured automatically during campaign sends.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Code</th>
                            <th>Source</th>
                            <th>Campaign</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bounces as $b): ?>
                        <tr>
                            <td style="font-size:13px;"><?= e($b['email']) ?></td>
                            <td>
                                <?php
                                $typeColors = ['hard' => 'var(--color-danger)', 'soft' => 'var(--color-warning)', 'complaint' => '#f97316', 'unknown' => 'var(--text-muted)'];
                                $color = $typeColors[$b['bounce_type']] ?? 'var(--text-muted)';
                                ?>
                                <span class="badge" style="background:<?= $color ?>;color:#fff;"><?= ucfirst($b['bounce_type']) ?></span>
                            </td>
                            <td class="text-muted fs-sm"><?= e($b['bounce_code'] ?: '—') ?></td>
                            <td class="text-muted fs-sm">
                                <?= e(ucwords(str_replace('_', ' ', $b['source']))) ?>
                                <div><?= e($b['smtp_email'] ?? '—') ?></div>
                            </td>
                            <td class="text-muted fs-sm"><?= e($b['campaign_name'] ?? '—') ?></td>
                            <td class="text-muted fs-sm"><?= timeAgo($b['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="card-body" style="display:flex; justify-content:center; gap:6px; padding:12px;">
            <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
                <a href="?page=<?= $p ?>&type=<?= e($filterType) ?>&email=<?= e($filterEmail) ?>&date_from=<?= e($filterDateFrom) ?>&date_to=<?= e($filterDateTo) ?>" 
                   class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Side panels -->
    <div>
        <!-- Top Bouncing Domains -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header"><h3>Top Bouncing Domains</h3></div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($topBounceDomains)): ?>
                    <div style="padding:16px; text-align:center; color:var(--text-muted); font-size:13px;">No data yet</div>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Domain</th><th>Hard</th><th>Soft</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($topBounceDomains as $d): ?>
                            <tr>
                                <td style="font-size:13px;"><?= e($d['domain']) ?></td>
                                <td style="color:var(--color-danger); font-size:13px;"><?= $d['hard_cnt'] ?></td>
                                <td style="color:var(--color-warning); font-size:13px;"><?= $d['soft_cnt'] ?></td>
                                <td style="font-size:13px; font-weight:600;"><?= $d['cnt'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Suppressions -->
        <div class="card">
            <div class="card-header"><h3>Recently Suppressed</h3></div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($recentSuppressed)): ?>
                    <div style="padding:16px; text-align:center; color:var(--text-muted); font-size:13px;">No suppressions yet</div>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Email</th><th>Reason</th><th>When</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentSuppressed as $s): ?>
                            <tr>
                                <td style="font-size:12px;"><?= e($s['email']) ?></td>
                                <td><span class="badge" style="background:var(--color-danger);color:#fff;font-size:10px;"><?= e($s['reason']) ?></span></td>
                                <td class="text-muted fs-sm"><?= timeAgo($s['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
async function scanBounceInboxes() {
    const btn = document.getElementById('scanBounceBtn');
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    const originalText = btn.textContent;

    btn.disabled = true;
    btn.textContent = 'Scanning...';

    try {
        const result = await apiCall(basePath + '/api/bounce-scan.php', { limit: 120 }, 'POST');
        const summary = result.summary || {};
        Toast.success(`${result.message} Suppressed ${summary.suppressed || 0} address(es).`, 7000);
        setTimeout(() => window.location.reload(), 1200);
    } catch (err) {
        Toast.error(err.message || 'Bounce scan failed', 8000);
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
