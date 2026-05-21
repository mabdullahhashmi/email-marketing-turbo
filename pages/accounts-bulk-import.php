<?php
/**
 * Bulk SMTP Import
 */
$pageTitle = 'Bulk SMTP Import';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">📥</span>Bulk SMTP Import</h1>
        <div class="subtitle">Upload a CSV file with SMTP account rows and map the columns once.</div>
    </div>
    <a href="<?= $basePath ?>/pages/accounts.php" class="btn btn-outline">← Back to SMTP Accounts</a>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3>Paste or Upload</h3>
    </div>
    <div class="card-body">
        <p class="text-muted fs-sm" style="margin-bottom: 12px;">
            Paste rows directly or upload a CSV/TXT file. Supported fields include label, SMTP host, port, encryption, username, password, from name, from email, daily limit, IMAP settings, and seed account flag.
        </p>
        <div class="form-group">
            <label>Paste rows here</label>
            <textarea id="smtpPasteInput" class="form-control" rows="10" placeholder="Label\tSMTP Host\tSMTP Port\tSMTP Username\tSMTP Password\tSMTP Enc."></textarea>
            <div class="form-hint">Tab, comma, or semicolon separated. Daily limit will default to 100 if not provided.</div>
        </div>
        <div style="display:flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap;">
            <button type="button" class="btn btn-outline" onclick="loadPasteToPreview()">Use Pasted Rows</button>
            <button type="button" class="btn btn-outline" onclick="loadTemplateRows()">Load Template</button>
        </div>
        <div class="file-upload-area" id="smtpCsvUploadArea">
            <div class="upload-icon">📁</div>
            <h4>Drop your CSV file here</h4>
            <p>or click to browse. Max 10MB.</p>
            <input type="file" id="smtpCsvFileInput" accept=".csv,.txt">
        </div>

        <div id="smtpCsvFileInfo" style="display: none; margin-top: 12px;" class="d-flex align-center gap-2">
            <span>📄</span>
            <span id="smtpCsvFileName" class="fw-500"></span>
            <span id="smtpCsvFileSize" class="text-muted fs-sm"></span>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3>Map Columns</h3>
    </div>
    <div class="card-body">
        <p class="text-muted fs-sm mb-4">Map each CSV column to an SMTP field. Required fields are marked with an asterisk.</p>
        <div id="smtpColumnMappings" style="display:grid; gap:10px;"></div>
    </div>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3>Preview</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <div id="smtpPreviewSummary" style="padding: 16px 24px 0; display:flex; gap:12px; flex-wrap:wrap;"></div>
        <div id="smtpPreviewEmpty" style="padding: 24px; text-align:center; color: var(--text-muted);">
            No file loaded yet. Upload a CSV to preview the first rows.
        </div>
        <div id="smtpPreviewTableWrap" class="table-wrapper" style="display:none;">
            <table>
                <thead>
                    <tr id="smtpPreviewHead"></tr>
                </thead>
                <tbody id="smtpPreviewBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Result</h3>
    </div>
    <div class="card-body">
        <div id="smtpImportResult" class="text-muted">No import run yet.</div>
        <div style="display:flex; gap: 8px; margin-top: 16px;">
            <button type="button" class="btn btn-outline" onclick="previewSmtpRows()">Preview Rows</button>
            <button type="button" class="btn btn-primary" id="smtpImportBtn" onclick="importSmtpRows()">Import SMTP Accounts</button>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'JS'
let smtpCsvData = null;
let smtpImportMode = 'paste';

const smtpPresetText = `hello@clients-flow.app\tsmtp.clients-flow.app\t465\thello@clients-flow.app\ttiktok@unPassword\tSSL\t100
contact@clients-flow.app\tsmtp.clients-flow.app\t465\tcontact@clients-flow.app\ttiktok@unPassword\tSSL\t100
connect@clients-flow.app\tsmtp.clients-flow.app\t465\tconnect@clients-flow.app\ttiktok@unPassword\tSSL\t100
grow@clients-flow.app\tsmtp.clients-flow.app\t465\tgrow@clients-flow.app\ttiktok@unPassword\tSSL\t100
reach@clients-flow.app\tsmtp.clients-flow.app\t465\treach@clients-flow.app\ttiktok@unPassword\tSSL\t100
start@clients-flow.app\tsmtp.clients-flow.app\t465\tstart@clients-flow.app\ttiktok@unPassword\tSSL\t100
hello@growthnest.tech\tsmtp.growthnest.tech\t465\thello@growthnest.tech\ttiktok@unPassword\tSSL\t100
contact@growthnest.tech\tsmtp.growthnest.tech\t465\tcontact@growthnest.tech\ttiktok@unPassword\tSSL\t100
connect@growthnest.tech\tsmtp.growthnest.tech\t465\tconnect@growthnest.tech\ttiktok@unPassword\tSSL\t100
grow@growthnest.tech\tsmtp.growthnest.tech\t465\tgrow@growthnest.tech\ttiktok@unPassword\tSSL\t100
reach@growthnest.tech\tsmtp.growthnest.tech\t465\treach@growthnest.tech\ttiktok@unPassword\tSSL\t100
start@growthnest.tech\tsmtp.growthnest.tech\t465\tstart@growthnest.tech\ttiktok@unPassword\tSSL\t100
hello@boostrive.app\tsmtp.boostrive.app\t465\thello@boostrive.app\ttiktok@unPassword\tSSL\t100
contact@boostrive.app\tsmtp.boostrive.app\t465\tcontact@boostrive.app\ttiktok@unPassword\tSSL\t100
connect@boostrive.app\tsmtp.boostrive.app\t465\tconnect@boostrive.app\ttiktok@unPassword\tSSL\t100
grow@boostrive.app\tsmtp.boostrive.app\t465\tgrow@boostrive.app\ttiktok@unPassword\tSSL\t100
reach@boostrive.app\tsmtp.boostrive.app\t465\treach@boostrive.app\ttiktok@unPassword\tSSL\t100
start@boostrive.app\tsmtp.boostrive.app\t465\tstart@boostrive.app\ttiktok@unPassword\tSSL\t100
hello@boostrive.pro\tmy.mailbux.com\t587\thello@boostrive.pro\tliinwztdefgsikqc\tTLS\t100
connect@boostrive.pro\tmy.mailbux.com\t587\tconnect@boostrive.pro\tnokgfjrapqtdtacs\tTLS\t100
grow@boostrive.pro\tmy.mailbux.com\t587\tgrow@boostrive.pro\toguppqptmbekelhj\tTLS\t100
reach@boostrive.pro\tmy.mailbux.com\t587\treach@boostrive.pro\tjrnzgfpkmxpbrjtg\tTLS\t100
start@boostrive.pro\tmy.mailbux.com\t587\tstart@boostrive.pro\trlmclwhgcgtoicjv\tTLS\t100
hello@scalebridge.app\tmy.mailbux.com\t587\thello@scalebridge.app\txddebujyzzrejpaw\tTLS\t100
connect@scalebridge.app\tmy.mailbux.com\t587\tconnect@scalebridge.app\tedlwdvlntrsxxpcq\tTLS\t100
grow@scalebridge.app\tmy.mailbux.com\t587\tgrow@scalebridge.app\tuvcaerarkgxtzpue\tTLS\t100
reach@scalebridge.app\tmy.mailbux.com\t587\treach@scalebridge.app\tcrkwcstkbjrotwrw\tTLS\t100
start@scalebridge.app\tmy.mailbux.com\t587\tstart@scalebridge.app\twbmnykvfgzbuanfy\tTLS\t100
hello@scalitive.com\tsmtp.clients-flow.app\t465\thello@clients-flow.app\ttiktok@unPassword\tSSL\t100
contact@scalitive.com\tsmtp.clients-flow.app\t465\tcontact@clients-flow.app\ttiktok@unPassword\tSSL\t100
connect@scalitive.com\tsmtp.clients-flow.app\t465\tconnect@clients-flow.app\ttiktok@unPassword\tSSL\t100
grow@scalitive.com\tsmtp.clients-flow.app\t465\tgrow@clients-flow.app\ttiktok@unPassword\tSSL\t100
reach@scalitive.com\tsmtp.clients-flow.app\t465\treach@clients-flow.app\ttiktok@unPassword\tSSL\t100
start@scalitive.com\tsmtp.clients-flow.app\t465\tstart@clients-flow.app\ttiktok@unPassword\tSSL\t100`;

const smtpPasteHeaders = ['label', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'daily_limit'];

function parseCsvPreview(raw) {
    const lines = raw.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    if (!lines.length) {
        return { headers: [], rows: [] };
    }

    const delimiter = lines[0].includes('\t') ? '\t' : (lines[0].includes(';') && !lines[0].includes(',')) ? ';' : ',';
    const rows = lines.map((line) => {
        const result = [];
        let current = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if (char === '"') {
                inQuotes = !inQuotes;
            } else if (char === delimiter && !inQuotes) {
                result.push(current.trim());
                current = '';
            } else {
                current += char;
            }
        }
        result.push(current.trim());
        return result;
    });

    return { headers: rows[0] || [], rows: rows.slice(1) };
}

function parseSmtpPasteRows(raw) {
    const lines = raw.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    const rows = [];

    for (const line of lines) {
        let parts = [];
        let delimiter = '\t';
        if (line.includes('\t')) {
            parts = line.split('\t');
            delimiter = '\t';
        } else if (line.includes(',')) {
            parts = line.split(',');
            delimiter = ',';
        } else if (line.includes(';')) {
            parts = line.split(';');
            delimiter = ';';
        } else {
            parts = [line];
        }

        if (parts.length < 6) {
            continue;
        }

        const label = (parts[0] || '').trim();
        const smtpHost = (parts[1] || '').trim();
        const smtpPort = (parts[2] || '').trim();
        const smtpUsername = (parts[3] || '').trim();
        const smtpPassword = (parts[4] || '').trim();
        const smtpEncryption = (parts[5] || '').trim();
        const dailyLimit = (parts[6] || '100').trim() || '100';

        rows.push([
            label,
            smtpHost,
            smtpPort,
            smtpUsername,
            smtpPassword,
            smtpEncryption,
            dailyLimit,
        ]);
    }

    return { headers: smtpPasteHeaders.slice(), rows };
}

function normalizeEmail(value) {
    return String(value || '').trim().toLowerCase();
}

function getDomainFromEmail(value) {
    const email = normalizeEmail(value);
    const atIndex = email.lastIndexOf('@');
    return atIndex >= 0 ? email.slice(atIndex + 1) : '';
}

function getDomainColor(domain) {
    const palette = ['#60a5fa', '#34d399', '#f59e0b', '#f472b6', '#a78bfa', '#22c55e', '#fb7185', '#38bdf8', '#c084fc', '#f97316'];
    const normalized = String(domain || '').toLowerCase();
    let hash = 0;
    for (let i = 0; i < normalized.length; i++) {
        hash = ((hash << 5) - hash) + normalized.charCodeAt(i);
        hash |= 0;
    }
    return palette[Math.abs(hash) % palette.length];
}

function escapeHtml(str) {
    return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function computeSmtpStats() {
    if (!smtpCsvData || !smtpCsvData.rows.length) {
        return { total: 0, domains: {}, uniqueDomains: 0 };
    }

    const domains = {};
    smtpCsvData.rows.forEach((row) => {
        const email = normalizeEmail(row[0] || row[3] || '');
        const domain = getDomainFromEmail(email);
        if (!domain) {
            return;
        }
        domains[domain] = (domains[domain] || 0) + 1;
    });

    return {
        total: smtpCsvData.rows.length,
        domains,
        uniqueDomains: Object.keys(domains).length,
    };
}

function renderSmtpSummary() {
    const summaryEl = document.getElementById('smtpPreviewSummary');
    const stats = computeSmtpStats();

    if (!summaryEl) {
        return;
    }

    if (!stats.total) {
        summaryEl.innerHTML = '';
        return;
    }

    const domainChips = Object.entries(stats.domains)
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([domain, count]) => {
            const color = getDomainColor(domain);
            return `<span class="badge" style="background:${color}; color:#fff;">${escapeHtml(domain)} (${count})</span>`;
        })
        .join('');

    summaryEl.innerHTML = `
        <span class="badge badge-completed">Total Emails: ${stats.total}</span>
        <span class="badge badge-draft">Unique Domains: ${stats.uniqueDomains}</span>
        ${domainChips}
    `;
}

initFileUpload('smtpCsvUploadArea', 'smtpCsvFileInput', (file) => {
    smtpImportMode = 'file';
    document.getElementById('smtpCsvFileInfo').style.display = 'flex';
    document.getElementById('smtpCsvFileName').textContent = file.name;
    document.getElementById('smtpCsvFileSize').textContent = formatBytes(file.size);

    const reader = new FileReader();
    reader.onload = function(e) {
        smtpCsvData = parseCsvPreview(e.target.result || '');
        renderSmtpMappingUI();
        renderSmtpPreview();
    };
    reader.readAsText(file);
});

function loadTemplateRows() {
    document.getElementById('smtpPasteInput').value = smtpPresetText;
    loadPasteToPreview();
}

function loadPasteToPreview() {
    const raw = document.getElementById('smtpPasteInput').value || '';
    if (!raw.trim()) {
        Toast.error('Paste rows first or load the template.');
        return;
    }
    smtpImportMode = 'paste';
    smtpCsvData = parseSmtpPasteRows(raw);
    renderSmtpMappingUI();
    renderSmtpPreview();
    Toast.success(`Parsed ${smtpCsvData.rows.length} row(s).`);
}

function renderSmtpMappingUI() {
    const container = document.getElementById('smtpColumnMappings');
    const headers = smtpCsvData?.headers || [];

    if (!headers.length) {
        container.innerHTML = '<div class="text-muted fs-sm">Upload a file first to see available columns.</div>';
        return;
    }

    const fields = [
        { key: 'label', label: 'Label', required: true },
        { key: 'smtp_host', label: 'SMTP Host', required: true },
        { key: 'smtp_port', label: 'SMTP Port' },
        { key: 'smtp_encryption', label: 'SMTP Encryption' },
        { key: 'smtp_username', label: 'SMTP Username', required: true },
        { key: 'smtp_password', label: 'SMTP Password', required: true },
        { key: 'from_name', label: 'From Name', required: true },
        { key: 'from_email', label: 'From Email', required: true },
        { key: 'daily_limit', label: 'Daily Limit' },
        { key: 'imap_host', label: 'IMAP Host' },
        { key: 'imap_port', label: 'IMAP Port' },
        { key: 'imap_encryption', label: 'IMAP Encryption' },
        { key: 'imap_username', label: 'IMAP Username' },
        { key: 'imap_password', label: 'IMAP Password' },
        { key: 'is_seed_account', label: 'Seed Account Flag' },
        { key: 'skip', label: 'Skip' },
    ];

    const autoMap = (header) => {
        const h = String(header).toLowerCase().trim();
        if (['label', 'name', 'account_name', 'account label', 'email'].includes(h)) return 'label';
        if (['smtp_host', 'host', 'server', 'smtp'].includes(h)) return 'smtp_host';
        if (['smtp_port', 'port'].includes(h)) return 'smtp_port';
        if (['smtp_encryption', 'encryption', 'security'].includes(h)) return 'smtp_encryption';
        if (['smtp_username', 'username', 'email', 'email_address'].includes(h)) return 'smtp_username';
        if (['smtp_password', 'password', 'app_password', 'app password'].includes(h)) return 'smtp_password';
        if (['from_name', 'sender_name', 'from name'].includes(h)) return 'from_name';
        if (['from_email', 'from email', 'reply_email'].includes(h)) return 'from_email';
        if (['daily_limit', 'limit'].includes(h)) return 'daily_limit';
        if (['imap_host'].includes(h)) return 'imap_host';
        if (['imap_port'].includes(h)) return 'imap_port';
        if (['imap_encryption'].includes(h)) return 'imap_encryption';
        if (['imap_username'].includes(h)) return 'imap_username';
        if (['imap_password'].includes(h)) return 'imap_password';
        if (['is_seed_account', 'seed', 'warmup_seed'].includes(h)) return 'is_seed_account';
        if (['email'].includes(h)) return 'label';
        return 'skip';
    };

    container.innerHTML = headers.map((header, index) => {
        const selected = autoMap(header);
        return `
            <div class="mapping-row" style="display:grid; grid-template-columns: minmax(160px, 1fr) 24px minmax(220px, 320px); gap: 12px; align-items:center; padding: 12px; border: 1px solid var(--border-color); border-radius: 12px;">
                <div class="mapping-source" style="font-weight: 500; color: var(--text-primary);">${escapeHtml(header)}</div>
                <div class="mapping-arrow" style="text-align:center; color: var(--text-muted);">→</div>
                <select class="form-control" id="smtpMapping_${index}" style="font-size: 13px;">
                    ${fields.map(field => `<option value="${field.key}" ${selected === field.key ? 'selected' : ''}>${field.label}${field.required ? ' *' : ''}</option>`).join('')}
                </select>
            </div>
        `;
    }).join('');
}

function renderSmtpPreview() {
    const previewEmpty = document.getElementById('smtpPreviewEmpty');
    const previewWrap = document.getElementById('smtpPreviewTableWrap');
    const previewHead = document.getElementById('smtpPreviewHead');
    const previewBody = document.getElementById('smtpPreviewBody');

    if (!smtpCsvData || !smtpCsvData.headers.length) {
        previewWrap.style.display = 'none';
        previewEmpty.style.display = 'block';
        renderSmtpSummary();
        return;
    }

    previewHead.innerHTML = smtpCsvData.headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('');
    previewBody.innerHTML = smtpCsvData.rows.slice(0, 3).map((row) => {
        const domain = getDomainFromEmail(row[0] || row[3] || '');
        const color = domain ? getDomainColor(domain) : 'var(--text-muted)';
        return `<tr>${row.map((cell, index) => {
            if (index === 0 || index === 3) {
                return `<td><strong style="color:${color};">${escapeHtml(cell)}</strong>${domain ? `<div class="text-muted fs-sm" style="margin-top:4px;">${escapeHtml(domain)}</div>` : ''}</td>`;
            }
            if (index === 5) {
                return `<td><span class="badge" style="background:${color}; color:#fff;">${escapeHtml(cell)}</span></td>`;
            }
            return `<td>${escapeHtml(cell)}</td>`;
        }).join('')}</tr>`;
    }).join('');
    previewEmpty.style.display = 'none';
    previewWrap.style.display = 'block';
    renderSmtpSummary();
}

function previewSmtpRows() {
    if (!smtpCsvData || !smtpCsvData.headers.length) {
        Toast.error('Please upload a CSV file first.');
        return;
    }
    renderSmtpMappingUI();
    renderSmtpPreview();
    Toast.success(`Parsed ${smtpCsvData.rows.length} row(s).`);
}

async function importSmtpRows() {
    if (!smtpCsvData || !smtpCsvData.headers.length) {
        Toast.error('Please upload a CSV file first.');
        return;
    }

    const btn = document.getElementById('smtpImportBtn');
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Importing...';

    try {
        const formData = new FormData();

        if (smtpImportMode === 'paste') {
            const csvText = [smtpCsvData.headers.join(',')].concat(
                smtpCsvData.rows.map((row) => row.map((cell) => `"${String(cell || '').replaceAll('"', '""')}"`).join(','))
            ).join('\n');
            formData.append('file', new Blob([csvText], { type: 'text/csv' }), 'smtp-import.csv');
        } else {
            const fileInput = document.getElementById('smtpCsvFileInput');
            if (!fileInput.files.length) {
                Toast.error('Please upload a CSV file or paste rows first.');
                return;
            }
            formData.append('file', fileInput.files[0]);
        }

        const mappings = {};
        smtpCsvData.headers.forEach((header, index) => {
            const select = document.getElementById(`smtpMapping_${index}`);
            mappings[index] = { field: select ? select.value : 'skip', header };
        });
        formData.append('mappings', JSON.stringify(mappings));

        const result = await apiCall(basePath + '/api/smtp-bulk-import.php', formData);
        const errors = Array.isArray(result.errors) ? result.errors : [];

        document.getElementById('smtpImportResult').innerHTML = `
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom: 8px;">
                <span class="badge badge-completed">Created: ${Number(result.created || 0)}</span>
                <span class="badge badge-draft">Updated: ${Number(result.updated || 0)}</span>
                <span class="badge" style="background:${errors.length ? 'var(--color-danger)' : 'var(--text-secondary)'}; color:#fff;">Skipped: ${Number(result.skipped || 0)}</span>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom: 8px;">${(computeSmtpStats().total ? Object.entries(computeSmtpStats().domains).map(([domain, count]) => `<span class="badge" style="background:${getDomainColor(domain)}; color:#fff;">${escapeHtml(domain)} (${count})</span>`).join('') : '')}</div>
            ${errors.length ? `<div style="margin-top:10px; font-size:12px; color: var(--text-muted); white-space: pre-wrap;">${escapeHtml(errors.join('\n'))}</div>` : ''}
        `;
        Toast.success(result.message || 'SMTP import completed.');
    } catch (err) {
        document.getElementById('smtpImportResult').innerHTML = `<span style="color: var(--color-danger);">${escapeHtml(err.message || 'Import failed')}</span>`;
        Toast.error(err.message || 'Import failed');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Import SMTP Accounts';
    }
}
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
