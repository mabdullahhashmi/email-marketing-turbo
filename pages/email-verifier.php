<?php
/**
 * Email Verifier
 */
$pageTitle = 'Email Verifier';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/email-verifier-helper.php';

emailVerifierEnsureTable();

$totalChecks = getCount('email_verifications');
$validChecks = getCount('email_verifications', "status = 'valid'");
$invalidChecks = getCount('email_verifications', "status = 'invalid'");
$riskyChecks = getCount('email_verifications', "status = 'risky'");
$unknownChecks = getCount('email_verifications', "status = 'unknown'");

$recentChecks = dbFetchAll("
    SELECT *
    FROM email_verifications
    ORDER BY checked_at DESC
    LIMIT 50
");

function verifierStatusBadge($status) {
    $colors = [
        'valid' => 'var(--color-success)',
        'invalid' => 'var(--color-danger)',
        'risky' => 'var(--color-warning)',
        'unknown' => 'var(--text-muted)',
    ];
    $color = $colors[$status] ?? 'var(--text-muted)';
    return '<span class="badge" style="background:' . $color . '; color:#fff;">' . ucfirst(e($status)) . '</span>';
}
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">@</span>Email Verifier</h1>
        <div class="subtitle">Verify single emails or bulk lists before sending campaigns</div>
    </div>
</div>

<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-title">Total Checked</div>
        <div class="stat-value"><?= number_format($totalChecks) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Valid</div>
        <div class="stat-value" style="color: var(--color-success);"><?= number_format($validChecks) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Invalid</div>
        <div class="stat-value" style="color: var(--color-danger);"><?= number_format($invalidChecks) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Risky</div>
        <div class="stat-value" style="color: var(--color-warning);"><?= number_format($riskyChecks) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Unknown</div>
        <div class="stat-value" style="color: var(--text-muted);"><?= number_format($unknownChecks) ?></div>
    </div>
</div>

<div style="display:grid; grid-template-columns: minmax(280px, 1fr) minmax(320px, 1.4fr); gap:20px; margin-bottom:24px;">
    <div class="card">
        <div class="card-header"><h3>Single Verification</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" id="singleEmail" class="form-control" placeholder="name@example.com" autocomplete="off">
            </div>
            <label style="display:flex; align-items:center; gap:8px; margin-bottom:16px; color:var(--text-secondary); font-size:13px;">
                <input type="checkbox" id="singleSmtpCheck" checked style="width:auto;">
                SMTP mailbox probe
            </label>
            <button type="button" class="btn btn-primary" id="singleVerifyBtn" onclick="verifySingleEmail()">Verify Email</button>
        </div>
        <div class="card-body" id="singleResult" style="display:none; border-top:1px solid var(--border-color);"></div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Bulk Verification</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label>Paste Emails</label>
                <textarea id="bulkEmails" class="form-control" rows="8" placeholder="Paste emails, CSV rows, or any text containing email addresses..."></textarea>
            </div>
            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
                <input type="file" id="bulkEmailFile" accept=".csv,.txt" onchange="loadBulkEmailFile(event)">
                <label style="display:flex; align-items:center; gap:8px; color:var(--text-secondary); font-size:13px;">
                    <input type="checkbox" id="bulkSmtpCheck" style="width:auto;">
                    SMTP probe for bulk
                </label>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" class="btn btn-primary" id="bulkVerifyBtn" onclick="verifyBulkEmails()">Verify Bulk List</button>
                <button type="button" class="btn btn-outline" id="exportVerifierBtn" onclick="exportVerifierResults()" disabled>Export CSV</button>
            </div>
            <div id="bulkProgress" class="text-muted fs-sm" style="margin-top:12px;"></div>
        </div>
    </div>
</div>

<div class="card" id="bulkResultsCard" style="display:none; margin-bottom:24px;">
    <div class="card-header"><h3>Bulk Results</h3></div>
    <div class="card-body" style="padding:0; max-height:520px; overflow:auto;">
        <table>
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Score</th>
                    <th>MX</th>
                    <th>SMTP</th>
                    <th>Notes</th>
                    <th>Suggestion</th>
                </tr>
            </thead>
            <tbody id="bulkResultsBody"></tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Recent Verification History</h3></div>
    <div class="card-body" style="padding:0; max-height:430px; overflow:auto;">
        <?php if (empty($recentChecks)): ?>
            <div style="padding:24px; text-align:center; color:var(--text-muted);">No verification history yet.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Score</th>
                        <th>MX</th>
                        <th>SMTP</th>
                        <th>Risk Flags</th>
                        <th>Checked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentChecks as $row): ?>
                    <tr>
                        <td style="font-size:13px;"><?= e($row['email']) ?></td>
                        <td><?= verifierStatusBadge($row['status']) ?></td>
                        <td class="fw-600"><?= (int)$row['score'] ?>%</td>
                        <td><?= $row['mx_valid'] ? '<span style="color:var(--color-success);">Valid</span>' : '<span style="color:var(--color-danger);">Missing</span>' ?></td>
                        <td class="text-muted fs-sm"><?= e($row['smtp_status'] ?: 'not_checked') ?></td>
                        <td class="text-muted fs-sm">
                            <?php
                            $flags = [];
                            if ($row['is_disposable']) $flags[] = 'disposable';
                            if ($row['is_role']) $flags[] = 'role';
                            if ($row['is_catch_all']) $flags[] = 'catch-all';
                            echo e($flags ? implode(', ', $flags) : '-');
                            ?>
                        </td>
                        <td class="text-muted fs-sm"><?= timeAgo($row['checked_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php
$pageScript = <<<'JS'
let verifierResults = [];

function verifierEsc(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function extractEmailsFromText(text) {
    const tokens = String(text || '').split(/[\s,;]+/);
    return [...new Set(tokens
        .map((email) => email.trim().replace(/^[<"']+|[>"']+$/g, '').toLowerCase())
        .filter((email) => email.includes('@')))];
}

function statusColor(status) {
    if (status === 'valid') return 'var(--color-success)';
    if (status === 'invalid') return 'var(--color-danger)';
    if (status === 'risky') return 'var(--color-warning)';
    return 'var(--text-muted)';
}

function statusBadge(status) {
    return `<span class="badge" style="background:${statusColor(status)}; color:#fff;">${verifierEsc(status.charAt(0).toUpperCase() + status.slice(1))}</span>`;
}

function notesForResult(result) {
    const notes = result.reasons || [];
    const flags = [];
    if (result.is_disposable) flags.push('disposable');
    if (result.is_role) flags.push('role');
    if (result.is_catch_all) flags.push('catch-all');
    return [...flags, ...notes].join('; ');
}

function renderSingleResult(result) {
    const el = document.getElementById('singleResult');
    const mxRecords = (result.mx_records || []).map((record) => record.host).slice(0, 3).join(', ');
    el.style.display = 'block';
    el.innerHTML = `
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom:12px;">
            <div>
                <div style="font-weight:700; color:var(--text-primary);">${verifierEsc(result.email)}</div>
                <div class="text-muted fs-sm">${verifierEsc(result.domain || '')}</div>
            </div>
            ${statusBadge(result.status)}
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; margin-bottom:12px;">
            <div style="border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:10px;"><div class="text-muted fs-sm">Score</div><div style="font-size:24px; font-weight:800; color:${statusColor(result.status)};">${result.score}%</div></div>
            <div style="border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:10px;"><div class="text-muted fs-sm">MX</div><div style="font-size:18px; font-weight:700;">${result.mx_valid ? 'Valid' : 'Missing'}</div><div class="text-muted fs-sm">${verifierEsc(mxRecords || '-')}</div></div>
            <div style="border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:10px;"><div class="text-muted fs-sm">SMTP</div><div style="font-size:18px; font-weight:700;">${verifierEsc(result.smtp_status || 'not_checked')}</div><div class="text-muted fs-sm">${verifierEsc(result.smtp_message || '')}</div></div>
        </div>
        <div class="text-muted fs-sm" style="line-height:1.7;">${verifierEsc(notesForResult(result) || 'No notes')}</div>
        ${result.suggestion ? `<div style="margin-top:10px; color:var(--color-warning);">Suggestion: <strong>${verifierEsc(result.suggestion)}</strong></div>` : ''}
    `;
}

function renderBulkResults(results) {
    const body = document.getElementById('bulkResultsBody');
    document.getElementById('bulkResultsCard').style.display = 'block';
    body.innerHTML = results.map((result) => `
        <tr>
            <td style="font-size:13px;">${verifierEsc(result.email)}</td>
            <td>${statusBadge(result.status)}</td>
            <td class="fw-600">${result.score}%</td>
            <td>${result.mx_valid ? '<span style="color:var(--color-success);">Valid</span>' : '<span style="color:var(--color-danger);">Missing</span>'}</td>
            <td class="text-muted fs-sm">${verifierEsc(result.smtp_status || 'not_checked')}</td>
            <td class="text-muted fs-sm" style="max-width:360px;">${verifierEsc(notesForResult(result))}</td>
            <td class="text-muted fs-sm">${verifierEsc(result.suggestion || '-')}</td>
        </tr>
    `).join('');
}

async function verifySingleEmail() {
    const email = document.getElementById('singleEmail').value.trim();
    if (!email) {
        Toast.error('Enter an email address first.');
        return;
    }

    const btn = document.getElementById('singleVerifyBtn');
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Verifying...';

    try {
        const response = await apiCall(basePath + '/api/email-verify.php', {
            email,
            smtp_check: document.getElementById('singleSmtpCheck').checked,
        });
        const result = response.results[0];
        verifierResults = [result];
        renderSingleResult(result);
        document.getElementById('exportVerifierBtn').disabled = false;
        Toast.success('Email verification complete.');
    } catch (err) {
        Toast.error(err.message || 'Verification failed');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Verify Email';
    }
}

function loadBulkEmailFile(event) {
    const file = event.target.files && event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = () => {
        const textarea = document.getElementById('bulkEmails');
        textarea.value = `${textarea.value}\n${reader.result || ''}`.trim();
        Toast.success('File loaded. Ready to verify.');
    };
    reader.onerror = () => Toast.error('Could not read file.');
    reader.readAsText(file);
}

async function verifyBulkEmails() {
    const emails = extractEmailsFromText(document.getElementById('bulkEmails').value);
    if (!emails.length) {
        Toast.error('Paste or upload emails first.');
        return;
    }

    const smtpCheck = document.getElementById('bulkSmtpCheck').checked;
    if (smtpCheck && emails.length > 100) {
        Toast.error('SMTP bulk probe is limited to 100 emails per run.');
        return;
    }

    const btn = document.getElementById('bulkVerifyBtn');
    const progress = document.getElementById('bulkProgress');
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Verifying...';
    progress.textContent = `Checking ${emails.length} unique email(s)...`;

    try {
        const response = await apiCall(basePath + '/api/email-verify.php', {
            emails,
            smtp_check: smtpCheck,
        });
        verifierResults = response.results || [];
        renderBulkResults(verifierResults);
        document.getElementById('exportVerifierBtn').disabled = verifierResults.length === 0;
        progress.textContent = `Done: ${response.summary.valid} valid, ${response.summary.invalid} invalid, ${response.summary.risky} risky, ${response.summary.unknown} unknown.`;
        Toast.success('Bulk verification complete.');
    } catch (err) {
        progress.textContent = '';
        Toast.error(err.message || 'Bulk verification failed');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Verify Bulk List';
    }
}

function csvCell(value) {
    return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

function exportVerifierResults() {
    if (!verifierResults.length) return;

    const rows = [
        ['email', 'domain', 'status', 'score', 'mx_valid', 'smtp_status', 'disposable', 'role', 'catch_all', 'suggestion', 'notes'],
        ...verifierResults.map((result) => [
            result.email,
            result.domain,
            result.status,
            result.score,
            result.mx_valid ? 'yes' : 'no',
            result.smtp_status || 'not_checked',
            result.is_disposable ? 'yes' : 'no',
            result.is_role ? 'yes' : 'no',
            result.is_catch_all ? 'yes' : 'no',
            result.suggestion || '',
            notesForResult(result),
        ]),
    ];

    const csv = rows.map((row) => row.map(csvCell).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'email-verification-results.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
