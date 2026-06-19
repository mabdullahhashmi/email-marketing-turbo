<?php
/**
 * Bulk Campaign Scheduler
 */
$pageTitle = 'Bulk Campaign Scheduler';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/hvac-cold-templates.php';

ensureCampaignBatchColumn();
ensureCampaignTemplatesTable();
seedHVACColdOutreachTemplates();

$smtpAccounts = dbFetchAll("SELECT id, label, from_email FROM smtp_accounts WHERE is_active = 1 ORDER BY label");
$contactLists = dbFetchAll("
    SELECT cl.id, cl.name,
        (SELECT COUNT(*) FROM contacts c WHERE c.list_id = cl.id AND (c.is_unsubscribed = 0 OR c.is_unsubscribed IS NULL)) as active_count
    FROM contact_lists cl
    ORDER BY cl.name
");
$templates = dbFetchAll("SELECT id, name, subject FROM campaign_templates ORDER BY name");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">Bulk</span>Bulk Campaign Scheduler</h1>
        <div class="subtitle">Create separate scheduled campaigns from table rows or CSV.</div>
    </div>
    <div class="btn-group">
        <button type="button" class="btn btn-outline" onclick="exportBulkCampaignCsv()">Export CSV</button>
        <button type="button" class="btn btn-primary" onclick="scheduleBulkCampaigns()">Create Campaigns</button>
        <a href="<?= $basePath ?>/pages/campaigns.php" class="btn btn-outline">Back</a>
    </div>
</div>

<div class="card mb-6">
    <div class="card-body">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div class="form-group" style="min-width:280px; margin-bottom:0;">
                <label>Import CSV</label>
                <input type="file" id="bulkCampaignFile" class="form-control" accept=".csv,.txt" onchange="importBulkCampaignFile(this)">
            </div>
            <button type="button" class="btn btn-outline" onclick="Modal.open('bulkCampaignPasteModal')">Paste Rows</button>
            <button type="button" class="btn btn-outline" onclick="addBulkCampaignRow()">Add Row</button>
            <button type="button" class="btn btn-ghost" onclick="downloadBulkCampaignSample()">Download Sample</button>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Campaign Name</th>
                        <th>Subject</th>
                        <th>Template</th>
                        <th>SMTP Account</th>
                        <th>Contact List</th>
                        <th>Badge/Batch</th>
                        <th>Start Time</th>
                        <th>Min Delay</th>
                        <th>Max Delay</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="bulkCampaignRows"></tbody>
            </table>
        </div>
        <div class="card-footer" style="justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div class="text-muted fs-sm" id="bulkCampaignSummary">0 campaign rows ready.</div>
            <button type="button" class="btn btn-primary" id="bulkCampaignSubmitBtn" onclick="scheduleBulkCampaigns()">Create Separate Scheduled Campaigns</button>
        </div>
    </div>
</div>

<div class="card mt-6" id="bulkCampaignResultsCard" style="display:none;">
    <div class="card-header">
        <h2>Results</h2>
    </div>
    <div class="card-body">
        <div id="bulkCampaignResults"></div>
    </div>
</div>

<div class="modal-overlay" id="bulkCampaignPasteModal">
    <div class="modal" style="max-width:820px;">
        <div class="modal-header">
            <h3>Paste Campaign Rows</h3>
            <button class="modal-close" onclick="Modal.close('bulkCampaignPasteModal')">x</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Rows</label>
                <textarea id="bulkCampaignPasteData" class="form-control" rows="12" placeholder="campaign_name,subject,template_name,smtp_account_id,contact_list_id,badge_number,scheduled_at,min_delay_seconds,max_delay_seconds"></textarea>
                <div class="form-text">You can use template/contact/SMTP IDs or exact names. Dates can be YYYY-MM-DD HH:MM or datetime-local format.</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="Modal.close('bulkCampaignPasteModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="importBulkCampaignPaste()">Import Rows</button>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'JS'
const bulkTemplates = __BULK_TEMPLATES__;
const bulkSmtpAccounts = __BULK_SMTP__;
const bulkContactLists = __BULK_LISTS__;
let bulkRowCounter = 0;

function bulkEsc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}

function bulkOptions(items, selected, labelFn) {
    return `<option value="">Select...</option>` + items.map((item) => {
        const value = String(item.id);
        return `<option value="${bulkEsc(value)}" ${String(selected || '') === value ? 'selected' : ''}>${bulkEsc(labelFn(item))}</option>`;
    }).join('');
}

function addBulkCampaignRow(data = {}) {
    bulkRowCounter++;
    const rowId = `bulkRow${bulkRowCounter}`;
    const tbody = document.getElementById('bulkCampaignRows');
    const tr = document.createElement('tr');
    tr.id = rowId;
    tr.className = 'bulk-campaign-row';
    tr.innerHTML = `
        <td><input type="text" class="form-control" data-field="campaign_name" value="${bulkEsc(data.campaign_name || '')}" placeholder="Campaign name" style="min-width:180px;"></td>
        <td><input type="text" class="form-control" data-field="subject" value="${bulkEsc(data.subject || '')}" placeholder="Subject line" style="min-width:220px;"></td>
        <td><select class="form-control" data-field="template_id" style="min-width:220px;">${bulkOptions(bulkTemplates, data.template_id, (item) => item.name)}</select><input type="hidden" data-field="template_name" value="${bulkEsc(data.template_name || '')}"></td>
        <td><select class="form-control" data-field="smtp_account_id" style="min-width:210px;">${bulkOptions(bulkSmtpAccounts, data.smtp_account_id, (item) => `${item.label} (${item.from_email})`)}</select><input type="hidden" data-field="smtp_account" value="${bulkEsc(data.smtp_account || data.smtp_account_name || '')}"></td>
        <td><select class="form-control" data-field="contact_list_id" style="min-width:210px;">${bulkOptions(bulkContactLists, data.contact_list_id, (item) => `${item.name} #${item.id} (${item.active_count} contacts)`)}</select><input type="hidden" data-field="contact_list" value="${bulkEsc(data.contact_list || data.contact_list_name || data.list_name || '')}"></td>
        <td><input type="text" class="form-control" data-field="contact_batch" value="${bulkEsc(data.contact_batch || data.badge_number || '')}" placeholder="Optional" style="min-width:130px;"></td>
        <td><input type="datetime-local" class="form-control" data-field="scheduled_at" value="${bulkEsc(toDatetimeLocal(data.scheduled_at || ''))}" style="min-width:180px;"></td>
        <td><input type="number" class="form-control" data-field="min_delay_seconds" value="${bulkEsc(data.min_delay_seconds || '60')}" min="10" style="min-width:95px;"></td>
        <td><input type="number" class="form-control" data-field="max_delay_seconds" value="${bulkEsc(data.max_delay_seconds || '3600')}" min="10" style="min-width:95px;"></td>
        <td><button type="button" class="btn btn-ghost btn-sm" onclick="removeBulkCampaignRow('${rowId}')">Remove</button></td>
    `;
    tbody.appendChild(tr);
    tr.querySelectorAll('input,select').forEach((field) => field.addEventListener('input', updateBulkCampaignSummary));
    updateBulkCampaignSummary();
}

function removeBulkCampaignRow(rowId) {
    document.getElementById(rowId)?.remove();
    updateBulkCampaignSummary();
}

function updateBulkCampaignSummary() {
    const count = document.querySelectorAll('.bulk-campaign-row').length;
    document.getElementById('bulkCampaignSummary').textContent = `${count} campaign row(s) ready.`;
}

function toDatetimeLocal(value) {
    if (!value) return '';
    const text = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(text)) return text.slice(0, 16);
    const parsed = new Date(text.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return '';
    const pad = (number) => String(number).padStart(2, '0');
    return `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())}T${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`;
}

function getBulkCampaignRows() {
    return Array.from(document.querySelectorAll('.bulk-campaign-row')).map((row) => {
        const item = {};
        row.querySelectorAll('[data-field]').forEach((field) => {
            item[field.dataset.field] = field.value.trim();
        });
        return item;
    }).filter((row) => row.campaign_name || row.subject || row.template_id || row.smtp_account_id || row.contact_list_id);
}

function parseBulkCsvLine(line) {
    const delimiter = line.includes('\t') ? '\t' : ',';
    const values = [];
    let current = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i++) {
        const char = line[i];
        const next = line[i + 1];
        if (char === '"' && inQuotes && next === '"') {
            current += '"';
            i++;
            continue;
        }
        if (char === '"') {
            inQuotes = !inQuotes;
            continue;
        }
        if (char === delimiter && !inQuotes) {
            values.push(current.trim());
            current = '';
            continue;
        }
        current += char;
    }
    values.push(current.trim());
    return values;
}

function normalizeBulkHeader(value) {
    return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function findBulkByName(items, name) {
    const target = String(name || '').trim().toLowerCase();
    if (!target) return '';
    const match = items.find((item) => [item.name, item.label, item.from_email].some((value) => String(value || '').trim().toLowerCase() === target));
    return match ? String(match.id) : '';
}

function resolveBulkItemId(items, rawId, rawName) {
    const idText = String(rawId || '').trim();
    if (idText && items.some((item) => String(item.id) === idText)) {
        return idText;
    }

    return findBulkByName(items, rawName || idText);
}

function parseBulkCampaignCsv(text) {
    const lines = text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    if (!lines.length) return [];
    const headers = parseBulkCsvLine(lines[0]).map(normalizeBulkHeader);
    const hasHeader = headers.includes('campaignname') || headers.includes('subject') || headers.includes('templateid');
    const keys = hasHeader ? headers : ['campaignname', 'subject', 'templatename', 'smtpaccountid', 'contactlistid', 'badgenumber', 'scheduledat', 'mindelayseconds', 'maxdelayseconds'];
    const dataLines = hasHeader ? lines.slice(1) : lines;

    return dataLines.map((line) => {
        const cols = parseBulkCsvLine(line);
        const raw = {};
        keys.forEach((key, index) => raw[key] = cols[index] || '');
        const templateName = raw.templatename || raw.template || '';
        const smtpName = raw.smtpaccount || raw.smtpaccountname || raw.smtpname || raw.sender || '';
        const contactListName = raw.contactlist || raw.contactlistname || raw.listname || raw.list || '';
        const templateId = resolveBulkItemId(bulkTemplates, raw.templateid, templateName);
        const smtpId = resolveBulkItemId(bulkSmtpAccounts, raw.smtpaccountid, smtpName);
        const listId = resolveBulkItemId(bulkContactLists, raw.contactlistid, contactListName);
        return {
            campaign_name: raw.campaignname || raw.name || '',
            subject: raw.subject || '',
            template_id: templateId,
            template_name: templateName || (!templateId ? raw.templateid : ''),
            smtp_account_id: smtpId,
            smtp_account: smtpName || (!smtpId ? raw.smtpaccountid : ''),
            contact_list_id: listId,
            contact_list: contactListName || (!listId ? raw.contactlistid : ''),
            contact_batch: raw.contactbatch || raw.badgenumber || raw.batchnumber || raw.batch || raw.badge || '',
            scheduled_at: raw.scheduledat || raw.starttime || raw.start || '',
            min_delay_seconds: raw.mindelayseconds || raw.mindelay || '60',
            max_delay_seconds: raw.maxdelayseconds || raw.maxdelay || '3600',
        };
    }).filter((row) => row.campaign_name || row.subject || row.template_id);
}

function importBulkCampaignRows(rows) {
    const tbody = document.getElementById('bulkCampaignRows');
    tbody.innerHTML = '';
    rows.forEach((row) => addBulkCampaignRow(row));
    if (!rows.length) {
        addBulkCampaignRow();
    }
    Toast.success(`Imported ${rows.length} row(s).`);
}

function importBulkCampaignPaste() {
    const rows = parseBulkCampaignCsv(document.getElementById('bulkCampaignPasteData').value);
    importBulkCampaignRows(rows);
    Modal.close('bulkCampaignPasteModal');
}

function importBulkCampaignFile(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => importBulkCampaignRows(parseBulkCampaignCsv(reader.result || ''));
    reader.readAsText(file);
    input.value = '';
}

function csvCell(value) {
    const text = String(value ?? '');
    return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function downloadCsv(filename, rows) {
    const csv = rows.map((row) => row.map(csvCell).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
}

function exportBulkCampaignCsv() {
    const header = ['campaign_name', 'subject', 'template_id', 'smtp_account_id', 'contact_list_id', 'badge_number', 'scheduled_at', 'min_delay_seconds', 'max_delay_seconds'];
    const rows = getBulkCampaignRows().map((row) => [
        row.campaign_name,
        row.subject,
        row.template_id,
        row.smtp_account_id,
        row.contact_list_id,
        row.contact_batch,
        row.scheduled_at,
        row.min_delay_seconds,
        row.max_delay_seconds,
    ]);
    downloadCsv('bulk-campaign-schedule.csv', [header, ...rows]);
}

function downloadBulkCampaignSample() {
    const firstTemplate = bulkTemplates[0] || {};
    const firstSmtp = bulkSmtpAccounts[0] || {};
    const firstList = bulkContactLists[0] || {};
    downloadCsv('bulk-campaign-schedule-sample.csv', [
        ['campaign_name', 'subject', 'template_id', 'smtp_account_id', 'contact_list_id', 'badge_number', 'scheduled_at', 'min_delay_seconds', 'max_delay_seconds'],
        ['Plumber Audit Batch 001', 'Quick idea for your plumbing website', firstTemplate.id || '', firstSmtp.id || '', firstList.id || '', 'Batch 001', '', '60', '3600'],
    ]);
}

async function scheduleBulkCampaigns() {
    const rows = getBulkCampaignRows();
    if (!rows.length) {
        Toast.error('Add at least one campaign row.');
        return;
    }

    if (!confirm(`Create and schedule ${rows.length} separate campaign(s)?`)) {
        return;
    }

    const btn = document.getElementById('bulkCampaignSubmitBtn');
    try {
        btn.disabled = true;
        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
        const result = await apiCall(`${basePath}/api/campaign-bulk-schedule.php`, { rows });
        renderBulkCampaignResults(result.results || []);
        if (result.success) {
            Toast.success(result.message || 'Campaigns created.');
        } else {
            Toast.error(result.message || 'No campaigns were created.');
        }
    } catch (error) {
        Toast.error(error.message || 'Bulk scheduling failed.');
    } finally {
        btn.disabled = false;
    }
}

function renderBulkCampaignResults(results) {
    const card = document.getElementById('bulkCampaignResultsCard');
    const target = document.getElementById('bulkCampaignResults');
    card.style.display = 'block';
    target.innerHTML = results.map((result) => `
        <div style="padding:10px 0; border-bottom:1px solid var(--border-color);">
            <strong>Row ${bulkEsc(result.row)}</strong> -
            <span style="color:${result.success ? 'var(--color-success)' : 'var(--color-danger)'};">${result.success ? 'Created' : 'Failed'}</span>
            <span class="text-muted fs-sm">${bulkEsc(result.message || '')}</span>
            ${result.campaign_id ? `<a class="btn btn-ghost btn-sm" href="campaign-view.php?id=${bulkEsc(result.campaign_id)}">View</a>` : ''}
        </div>
    `).join('');
}

addBulkCampaignRow();
JS;

$pageScript = str_replace(
    ['__BULK_TEMPLATES__', '__BULK_SMTP__', '__BULK_LISTS__'],
    [
        json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
        json_encode($smtpAccounts, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
        json_encode($contactLists, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
    ],
    $pageScript
);

require_once __DIR__ . '/../includes/footer.php';
?>
