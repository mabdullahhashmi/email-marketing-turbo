<?php
/**
 * Campaign Create/Edit - WYSIWYG Email Builder
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

ensureCampaignBatchColumn();

$loadTinyMCE = false;

$editId = (int)($_GET['id'] ?? 0);
$campaign = null;

if ($editId) {
    $campaign = dbFetchOne("SELECT * FROM campaigns WHERE id = ? AND status = 'draft'", [$editId]);
    if (!$campaign) {
        header('Location: campaigns.php');
        exit;
    }
}

$pageTitle = $campaign ? 'Edit Campaign' : 'New Campaign';
require_once __DIR__ . '/../includes/header.php';

// Fetch SMTP accounts and contact lists
$smtpAccounts = dbFetchAll("SELECT id, label, from_email FROM smtp_accounts WHERE is_active = 1 ORDER BY label");
$contactLists = dbFetchAll("
    SELECT cl.id, cl.name, 
        (SELECT COUNT(*) FROM contacts c WHERE c.list_id = cl.id AND (c.is_unsubscribed = 0 OR c.is_unsubscribed IS NULL)) as active_count
    FROM contact_lists cl 
    ORDER BY cl.name
");

$batchOptionsByList = [];
$batchRows = dbFetchAll("
    SELECT list_id, custom_fields
    FROM contacts
    WHERE (is_unsubscribed = 0 OR is_unsubscribed IS NULL) AND custom_fields IS NOT NULL
");
foreach ($batchRows as $row) {
    $batchValue = getContactBatchValue($row);
    if ($batchValue === '') {
        continue;
    }
    $listId = (string) $row['list_id'];
    if (!isset($batchOptionsByList[$listId])) {
        $batchOptionsByList[$listId] = [];
    }
    $batchOptionsByList[$listId][$batchValue] = true;
}
foreach ($batchOptionsByList as $listId => $values) {
    $values = array_keys($values);
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    $batchOptionsByList[$listId] = $values;
}
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon"><?= $campaign ? '✏️' : '✚' ?></span><?= $campaign ? 'Edit Campaign' : 'New Campaign' ?></h1>
        <div class="subtitle">Build and schedule your email campaign</div>
    </div>
    <a href="<?= $basePath ?>/pages/campaigns.php" class="btn btn-outline">← Back to Campaigns</a>
</div>

<form id="campaignForm" onsubmit="saveCampaign(event)">
    <input type="hidden" id="campaignId" value="<?= $editId ?>">
    
    <div class="campaign-builder-layout">
        <!-- Main Content -->
        <div>
            <!-- Campaign Details -->
            <div class="card mb-6">
                <div class="card-header">
                    <h2>📋 Campaign Details</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Campaign Name <span class="required">*</span></label>
                        <input type="text" id="campaignName" class="form-control" 
                               placeholder="e.g., March Newsletter" required
                               value="<?= e($campaign['name'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Email Subject <span class="required">*</span></label>
                        <input type="text" id="campaignSubject" class="form-control" 
                               placeholder="e.g., Hi {{first_name}}, check this out!" required
                               value="<?= e($campaign['subject'] ?? '') ?>">
                        <div class="form-hint">You can use shortcodes in the subject line</div>
                        <div class="shortcode-list">
                            <span class="shortcode-tag" onclick="document.getElementById('campaignSubject').value += '{{name}}'; document.getElementById('campaignSubject').focus();">{{name}}</span>
                            <span class="shortcode-tag" onclick="document.getElementById('campaignSubject').value += '{{first_name}}'; document.getElementById('campaignSubject').focus();">{{first_name}}</span>
                            <span class="shortcode-tag" onclick="document.getElementById('campaignSubject').value += '{{email}}'; document.getElementById('campaignSubject').focus();">{{email}}</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Email Body -->
            <div class="card mb-6">
                <div class="card-header">
                    <h2>✉️ Email Body</h2>
                    <div class="shortcode-list">
                        <span class="shortcode-tag" onclick="builderInsertToken('{{name}}')">{{name}}</span>
                        <span class="shortcode-tag" onclick="builderInsertToken('{{first_name}}')">{{first_name}}</span>
                        <span class="shortcode-tag" onclick="builderInsertToken('{{last_name}}')">{{last_name}}</span>
                        <span class="shortcode-tag" onclick="builderInsertToken('{{email}}')">{{email}}</span>
                        <span class="shortcode-tag" onclick="builderInsertToken('{{date}}')">{{date}}</span>
                        <span class="shortcode-tag" onclick="builderInsertToken('{{unsubscribe_link}}')">{{unsubscribe_link}}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="email-builder-actions">
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderLoadTemplate('newsletter')">Newsletter</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderLoadTemplate('promo')">Promo</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderLoadTemplate('announcement')">Announcement</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderLoadTemplate('premiumLeakAudit')">Premium Leak Audit</button>
                        <span class="builder-action-divider"></span>
                        <button type="button" class="btn btn-primary btn-sm" onclick="builderSaveCurrentTemplate()">Save Template</button>
                        <select id="savedTemplateSelect" class="builder-template-select" onchange="builderApplySavedTemplate(this.value)">
                            <option value="">Saved templates...</option>
                        </select>
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderPreviewEmail()">Clean Preview</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderPreviewHtml()">View Source</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderImportFullHtml()">Paste Full HTML</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderStartRawHtmlMode()">Full HTML Mode</button>
                    </div>

                    <textarea id="emailBody" name="body" style="display:none;"><?= e($campaign['body_html'] ?? '') ?></textarea>

                    <div class="email-builder-shell">
                        <aside class="builder-panel builder-panel-left">
                            <div class="builder-panel-title">Blocks</div>
                            <div class="builder-block-library" id="builderBlockLibrary"></div>

                            <div class="builder-panel-title" style="margin-top:18px;">Shortcodes</div>
                            <div class="shortcode-list">
                                <span class="shortcode-tag" onclick="builderInsertToken('{{name}}')">{{name}}</span>
                                <span class="shortcode-tag" onclick="builderInsertToken('{{first_name}}')">{{first_name}}</span>
                                <span class="shortcode-tag" onclick="builderInsertToken('{{last_name}}')">{{last_name}}</span>
                                <span class="shortcode-tag" onclick="builderInsertToken('{{email}}')">{{email}}</span>
                                <span class="shortcode-tag" onclick="builderInsertToken('{{date}}')">{{date}}</span>
                                <span class="shortcode-tag" onclick="builderInsertToken('{{unsubscribe_link}}')">{{unsubscribe_link}}</span>
                            </div>

                            <div class="builder-panel-title" style="margin-top:18px;">Design</div>
                            <div class="builder-design-grid">
                                <label><span>Page</span><input type="color" id="builderBg" value="#f4f7fb" onchange="builderUpdateSettings()"></label>
                                <label><span>Email</span><input type="color" id="builderContentBg" value="#ffffff" onchange="builderUpdateSettings()"></label>
                                <label><span>Accent</span><input type="color" id="builderAccent" value="#2563eb" onchange="builderUpdateSettings()"></label>
                            </div>
                            <label class="builder-control-label">Email Font</label>
                            <select id="builderFont" class="builder-control" onchange="builderUpdateSettings()">
                                <option value="Poppins">Poppins</option>
                                <option value="Montserrat">Montserrat</option>
                                <option value="DM Sans">DM Sans</option>
                                <option value="Arial">Arial</option>
                                <option value="Helvetica">Helvetica</option>
                                <option value="Verdana">Verdana</option>
                                <option value="Trebuchet MS">Trebuchet MS</option>
                                <option value="Georgia">Georgia</option>
                                <option value="Times New Roman">Times New Roman</option>
                            </select>
                            <label class="builder-control-label">Email Width</label>
                            <input type="number" id="builderWidth" class="builder-control" value="640" min="320" max="720" step="10" oninput="builderUpdateSettings()">
                        </aside>

                        <section class="builder-stage">
                            <div class="builder-toolbar">
                                <div>
                                    <strong>Canvas</strong>
                                    <span class="text-muted fs-sm">Email-safe responsive layout</span>
                                </div>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-primary btn-sm" id="builderEditModeBtn" onclick="builderSetCanvasMode('edit')">Edit</button>
                                    <button type="button" class="btn btn-outline btn-sm" id="builderPreviewModeBtn" onclick="builderSetCanvasMode('preview')">Preview</button>
                                    <button type="button" class="btn btn-primary btn-sm" id="builderDesktopPreviewBtn" onclick="builderSetPreviewMode('desktop')">Desktop</button>
                                    <button type="button" class="btn btn-outline btn-sm" id="builderMobilePreviewBtn" onclick="builderSetPreviewMode('mobile')">Mobile</button>
                                    <button type="button" class="btn btn-outline btn-sm" onclick="builderUndo()">Undo</button>
                                </div>
                            </div>
                            <div class="builder-preview-wrap" id="builderPreviewWrap">
                                <div class="builder-email-preview" id="builderCanvas"></div>
                            </div>
                        </section>

                        <aside class="builder-panel builder-panel-right">
                            <div class="builder-panel-title">Block Settings</div>
                            <div id="builderInspector" class="builder-empty-inspector">Select a block to edit its content and styling.</div>
                        </aside>
                    </div>
                    <div class="form-hint mt-2">
                        Use image blocks for uploaded visuals. The final email HTML is generated automatically when saving, testing, or scheduling.
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sidebar Settings -->
        <div>
            <!-- Send Settings -->
            <div class="card mb-6">
                <div class="card-header">
                    <h2>⚡ Send Settings</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>SMTP Account <span class="required">*</span></label>
                        <select id="smtpAccountId" class="form-control" required>
                            <option value="">— Select Account —</option>
                            <?php foreach ($smtpAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>" <?= ($campaign['smtp_account_id'] ?? '') == $acc['id'] ? 'selected' : '' ?>>
                                    <?= e($acc['label']) ?> (<?= e($acc['from_email']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($smtpAccounts)): ?>
                            <div class="form-hint" style="color: var(--color-warning);">⚠ No SMTP accounts configured. <a href="accounts.php">Add one first.</a></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Contact List <span class="required">*</span></label>
                        <select id="contactListId" class="form-control" required onchange="updateBatchOptions()">
                            <option value="">— Select List —</option>
                            <?php foreach ($contactLists as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= ($campaign['contact_list_id'] ?? '') == $cl['id'] ? 'selected' : '' ?>>
                                    <?= e($cl['name']) ?> (<?= $cl['active_count'] ?> contacts)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Batch Number</label>
                        <select id="contactBatch" class="form-control">
                            <option value="">All contacts in selected list</option>
                        </select>
                        <div class="form-hint">Uses CSV custom fields named batch_number, batch, Batch Number, batch no, or badge_number.</div>
                    </div>
                </div>
            </div>
            
            <!-- Schedule -->
            <div class="card mb-6">
                <div class="card-header">
                    <h2>⏰ Schedule</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Start Sending At</label>
                        <input type="datetime-local" id="scheduledAt" class="form-control" 
                               value="<?= $campaign['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($campaign['scheduled_at'])) : '' ?>">
                        <div class="form-hint">Leave empty to start immediately when scheduled</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Random Delay Between Emails</label>
                        <div class="form-hint mb-2">Each email will be sent at a random interval within this range</div>
                        <div class="delay-config">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Min (seconds)</label>
                                <input type="number" id="minDelay" class="form-control" 
                                       value="<?= $campaign['min_delay_seconds'] ?? 60 ?>" min="10" required>
                            </div>
                            <div class="delay-separator">to</div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Max (seconds)</label>
                                <input type="number" id="maxDelay" class="form-control" 
                                       value="<?= $campaign['max_delay_seconds'] ?? 3600 ?>" min="10" required>
                            </div>
                        </div>
                        <div class="form-hint mt-2">
                            60s = 1 min, 300s = 5 min, 900s = 15 min, 3600s = 1 hour
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="card">
                <div class="card-body">
                    <button type="submit" class="btn btn-outline btn-lg" style="width: 100%; margin-bottom: 12px;" id="saveDraftBtn">
                        💾 Save as Draft
                    </button>
                    <button type="button" class="btn btn-primary btn-lg" style="width: 100%; margin-bottom: 12px;" onclick="scheduleCampaign()">
                        🚀 Save & Schedule
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" style="width: 100%;" onclick="sendTestEmail()">
                        📧 Send Test Email
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
$pageScript = <<<'JS'
const contactBatchOptions = __CONTACT_BATCH_OPTIONS__;
const selectedContactBatch = __SELECTED_CONTACT_BATCH__;

function updateBatchOptions() {
    const listId = document.getElementById('contactListId').value;
    const batchSelect = document.getElementById('contactBatch');
    const options = contactBatchOptions[listId] || [];
    const previousListId = batchSelect.dataset.listId || '';
    const isSameList = !previousListId || previousListId === listId;
    const currentValue = isSameList ? (batchSelect.value || selectedContactBatch || '') : '';
    batchSelect.dataset.listId = listId;

    batchSelect.innerHTML = '<option value="">All contacts in selected list</option>';
    options.forEach((batch) => {
        const option = document.createElement('option');
        option.value = batch;
        option.textContent = 'Batch ' + batch;
        if (batch === currentValue) option.selected = true;
        batchSelect.appendChild(option);
    });

    if (currentValue && isSameList && !options.includes(currentValue)) {
        const option = document.createElement('option');
        option.value = currentValue;
        option.textContent = 'Batch ' + currentValue;
        option.selected = true;
        batchSelect.appendChild(option);
    }
}

let builderState = {
    settings: { bg: '#f4f7fb', contentBg: '#ffffff', accent: '#2563eb', font: 'Poppins' },
    blocks: []
};
let builderSelectedId = null;
let builderHistory = [];
let builderFocusedField = null;
let builderSavedTemplates = [];
let builderPreviewMode = 'desktop';
let builderCanvasMode = 'edit';

const builderBlockGroups = [
    {
        title: 'Core Elements',
        blocks: [
            ['hero', 'Hero', 'Headline + CTA'],
            ['brandHeader', 'Header', 'Brand bar'],
            ['text', 'Text', 'Rich copy'],
            ['image', 'Image', 'Upload visual'],
            ['button', 'Button', 'Action link'],
            ['divider', 'Divider', 'Separator'],
            ['spacer', 'Spacer', 'Vertical gap'],
        ],
    },
    {
        title: 'Layout',
        blocks: [
            ['twoColumn', 'Columns', 'Two sections'],
            ['product', 'Offer Card', 'Image + CTA'],
            ['ctaPanel', 'CTA Panel', 'Audit offer'],
            ['social', 'Social', 'Profile links'],
        ],
    },
    {
        title: 'Proof + Visuals',
        blocks: [
            ['auditGrid', 'Audit Cards', '4 benefits'],
            ['checklistPanel', 'Checklist', 'Dark proof panel'],
            ['metricBars', 'Metrics', 'Mini bar chart'],
            ['browserAudit', 'Mockup', 'Website audit'],
        ],
    },
    {
        title: 'Premium Sections',
        blocks: [
            ['premiumPlumberHeader', 'LP Header', 'Plumber kit'],
            ['premiumPlumberHeroScore', 'LP Hero', 'Scorecard'],
            ['premiumPlumberFindings', 'Findings', 'Dark wins'],
            ['premiumPlumberProcess', 'Process', '4 steps'],
            ['premiumPlumberIncludes', 'Included', '4 features'],
            ['premiumLeakHero', 'Audit Hero', 'Pipe graphic'],
            ['premiumFunnel', 'Funnel Leak', 'Traffic flow'],
            ['premiumImpactDice', 'Impact Grid', 'Dice visual'],
            ['premiumCompare', 'Convert Card', 'Results compare'],
            ['premiumPlumberFinalCta', 'Final CTA', 'Book audit'],
            ['premiumPlumberFooter', 'Footer', 'Signature'],
        ],
    },
    {
        title: 'Advanced',
        blocks: [
            ['html', 'HTML', 'Partial snippet'],
            ['fullHtml', 'Full HTML', 'Complete email source'],
        ],
    },
];

function builderRenderBlockLibrary() {
    const library = document.getElementById('builderBlockLibrary');
    if (!library) return;

    library.innerHTML = builderBlockGroups.map((group) => `
        <div class="builder-block-group">
            <button type="button" class="builder-block-group-toggle" onclick="this.closest('.builder-block-group').classList.toggle('collapsed')">
                <span>${builderEsc(group.title)}</span>
                <span>Toggle</span>
            </button>
            <div class="builder-block-grid">
                ${group.blocks.map(([type, label, help]) => {
                    const action = type === 'fullHtml' ? 'builderStartRawHtmlMode()' : `builderAddBlock('${type}')`;
                    return `<button type="button" class="builder-block-button" onclick="${action}"><strong>${builderEsc(label)}</strong><span>${builderEsc(help)}</span></button>`;
                }).join('')}
            </div>
        </div>
    `).join('');
}

function builderId() {
    return 'blk_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7);
}

function builderEsc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function builderAttr(value) {
    return builderEsc(value).replace(/\n/g, ' ');
}

function builderLines(value) {
    return builderEsc(value).replace(/\n/g, '<br>');
}

function builderFontStack(font = builderState.settings.font) {
    const stacks = {
        'Poppins': "'Poppins', 'Segoe UI', Arial, Helvetica, sans-serif",
        'Montserrat': "'Montserrat', 'Segoe UI', Arial, Helvetica, sans-serif",
        'DM Sans': "'DM Sans', 'Segoe UI', Arial, Helvetica, sans-serif",
        'Arial': 'Arial, Helvetica, sans-serif',
        'Helvetica': 'Helvetica, Arial, sans-serif',
        'Verdana': 'Verdana, Geneva, sans-serif',
        'Trebuchet MS': "'Trebuchet MS', Arial, sans-serif",
        'Georgia': 'Georgia, serif',
        'Times New Roman': "'Times New Roman', Times, serif",
    };
    return stacks[font] || stacks.Poppins;
}

function builderFontImport() {
    if (builderState.settings.font === 'Poppins') {
        return "<link href=\"https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap\" rel=\"stylesheet\">";
    }
    if (builderState.settings.font === 'Montserrat') {
        return "<link href=\"https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">";
    }
    if (builderState.settings.font === 'DM Sans') {
        return "<link href=\"https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">";
    }
    return '';
}

function builderResponsiveCss() {
    return `<style>
@media screen and (max-width:680px) {
    .mp-container { width:100% !important; max-width:100% !important; }
    .mp-stack { display:block !important; width:100% !important; max-width:100% !important; }
    .mp-hide-mobile { display:none !important; }
    .mp-mobile-edge { padding-left:0 !important; padding-right:0 !important; }
    .mp-pad-mobile { padding-left:22px !important; padding-right:22px !important; }
    .mp-center-mobile { text-align:center !important; }
    .mp-mobile-top { padding-top:20px !important; }
    .mp-mobile-gap { padding-top:12px !important; }
    .mp-process-border { border-right:none !important; border-bottom:1px solid #dde5f0 !important; padding-top:16px !important; padding-bottom:16px !important; }
}
</style>`;
}

function builderIsRawHtmlMode() {
    return builderState?.settings?.mode === 'rawHtml';
}

function builderLooksLikeFullHtml(html) {
    const value = String(html || '').trim();
    return /<!doctype\s+html/i.test(value) || /<html[\s>]/i.test(value) || (/<head[\s>]/i.test(value) && /<body[\s>]/i.test(value));
}

function builderSetRawHtmlState(html) {
    builderState = {
        settings: {
            bg: '#f7f9fc',
            contentBg: '#ffffff',
            accent: '#f47c20',
            font: 'Poppins',
            mode: 'rawHtml',
        },
        rawHtml: String(html || ''),
        blocks: [],
    };
    builderSelectedId = null;
}

function builderStartRawHtmlMode() {
    if (!builderIsRawHtmlMode() && builderState.blocks.length) {
        const ok = confirm('Switch to Full HTML Mode? This keeps one complete email source instead of editable blocks.');
        if (!ok) return;
    }

    builderSnapshot();
    const currentHtml = builderIsRawHtmlMode() ? builderState.rawHtml : builderGenerateHtml();
    builderSetRawHtmlState(currentHtml);
    builderApplyStateSettingsToControls();
    builderRender();
}

function builderImportFullHtml() {
    const modal = document.createElement('div');
    modal.className = 'builder-source-modal';
    modal.innerHTML = `
        <div>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;">
                <h3 style="margin:0;">Paste Full HTML Email</h3>
                <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('.builder-source-modal').remove()">Close</button>
            </div>
            <div class="form-hint" style="margin-bottom:10px;">Use this only for a complete email document with doctype/html/head/body. Partial snippets belong in an HTML block.</div>
            <textarea class="builder-control" id="builderFullHtmlImport" placeholder="Paste complete email HTML here..."></textarea>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:12px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('.builder-source-modal').remove()">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="builderApplyFullHtmlImport(this)">Use Full HTML</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    modal.querySelector('textarea').focus();
}

function builderApplyFullHtmlImport(button) {
    const modal = button.closest('.builder-source-modal');
    const html = modal.querySelector('#builderFullHtmlImport')?.value || '';
    if (!html.trim()) {
        Toast.warning('Paste complete email HTML first.');
        return;
    }

    if (!builderLooksLikeFullHtml(html)) {
        Toast.warning('That does not look like a complete HTML email. Use an HTML block for partial snippets.');
        return;
    }

    builderSnapshot();
    builderSetRawHtmlState(html);
    builderApplyStateSettingsToControls();
    builderRender();
    builderSyncHtml();
    modal.remove();
    Toast.success('Full HTML email loaded.');
}

function builderSetRawHtml(value) {
    if (!builderIsRawHtmlMode()) return;
    builderState.rawHtml = value;
    builderRenderCanvas();
}

function builderSetPreviewMode(mode) {
    builderPreviewMode = mode === 'mobile' ? 'mobile' : 'desktop';
    const wrap = document.getElementById('builderPreviewWrap');
    if (wrap) {
        wrap.classList.toggle('builder-preview-mobile', builderPreviewMode === 'mobile');
    }

    const desktopBtn = document.getElementById('builderDesktopPreviewBtn');
    const mobileBtn = document.getElementById('builderMobilePreviewBtn');
    if (desktopBtn && mobileBtn) {
        desktopBtn.className = 'btn btn-sm ' + (builderPreviewMode === 'desktop' ? 'btn-primary' : 'btn-outline');
        mobileBtn.className = 'btn btn-sm ' + (builderPreviewMode === 'mobile' ? 'btn-primary' : 'btn-outline');
    }

    builderRenderCanvas();
}

function builderUpdateCanvasModeButtons() {
    const editBtn = document.getElementById('builderEditModeBtn');
    const previewBtn = document.getElementById('builderPreviewModeBtn');
    if (!editBtn || !previewBtn) return;

    editBtn.className = 'btn btn-sm ' + (builderCanvasMode === 'edit' ? 'btn-primary' : 'btn-outline');
    previewBtn.className = 'btn btn-sm ' + (builderCanvasMode === 'preview' ? 'btn-primary' : 'btn-outline');
}

function builderSetCanvasMode(mode) {
    builderCanvasMode = mode === 'preview' ? 'preview' : 'edit';
    builderUpdateCanvasModeButtons();
    builderRenderCanvas();
}

function builderSnapshot() {
    builderHistory.push(JSON.stringify(builderState));
    if (builderHistory.length > 30) builderHistory.shift();
}

function builderUndo() {
    const previous = builderHistory.pop();
    if (!previous) {
        Toast.info('Nothing to undo.');
        return;
    }
    builderState = JSON.parse(previous);
    builderSelectedId = null;
    builderRender();
}

function builderBlock(type, data = {}) {
    const common = { id: builderId(), type };
    const defaults = {
        hero: {
            eyebrow: 'NEW COLLECTION',
            title: 'Build emails your audience wants to open',
            subtitle: 'Use this section for launches, offers, and high-impact announcements.',
            buttonText: 'Shop now',
            buttonUrl: '#',
            secondaryButtonText: '',
            secondaryButtonUrl: '',
            secondaryButtonBg: '#ffffff',
            secondaryButtonColor: '#111827',
            imageUrl: '',
            align: 'center',
            bg: '#eef4ff',
            textColor: '#111827',
            padding: 42,
        },
        brandHeader: {
            brand: 'Abdullah Hashmi',
            label: 'Website & Landing Page Specialist',
            bg: '#0f172a',
            color: '#ffffff',
            padding: 18,
        },
        text: {
            content: 'Hi {{first_name}},\n\nAdd your message here. Keep it clear, useful, and focused on one next action.',
            fontSize: 16,
            color: '#334155',
            align: 'left',
            padding: 28,
        },
        auditGrid: {
            item1Title: 'More Calls',
            item1Text: 'Clear call buttons and mobile-first page flow for visitors who need help fast.',
            item1Icon: 'Phone',
            item2Title: 'Quote Requests',
            item2Text: 'Better forms and CTA sections to collect serious enquiries from homeowners.',
            item2Icon: 'Quote',
            item3Title: 'More Trust',
            item3Text: 'Reviews, service areas, guarantees, and proof placed where visitors hesitate.',
            item3Icon: 'Trust',
            item4Title: 'Faster Action',
            item4Text: 'Simple page sections so visitors understand the offer and contact quickly.',
            item4Icon: 'Fast',
            bg: '#ffffff',
            cardBg: '#f8fafc',
            border: '#dbe3ef',
            iconBg: '#dbeafe',
            iconColor: '#2563eb',
            padding: 26,
        },
        checklistPanel: {
            title: 'Small website changes can make a big difference.',
            intro: 'Most plumbing websites already have the services listed. The real issue is how the page guides the visitor toward taking action.',
            item1: 'Strong headline focused on customer problems',
            item2: 'Emergency service CTA above the fold',
            item3: 'Separate sections for drain cleaning, leak repair, water heaters, and emergency service',
            item4: 'Trust-building layout designed for local homeowners',
            bg: '#0f172a',
            color: '#ffffff',
            lineColor: '#26364f',
            accent: '#38bdf8',
            padding: 28,
        },
        metricBars: {
            title: 'Before vs After - Key Metrics',
            subtitle: 'Illustrative page improvements after fixing the visitor journey',
            metric1Label: 'Calls / Week',
            metric1Before: '2',
            metric1After: '9',
            metric1Note: '4.5x more calls',
            metric2Label: 'Page Speed',
            metric2Before: '28',
            metric2After: '96',
            metric2Note: '3.4x higher score',
            metric3Label: 'Quote Rate',
            metric3Before: '1.1%',
            metric3After: '5.8%',
            metric3Note: '5x conversion lift',
            bg: '#17345a',
            color: '#ffffff',
            muted: '#a8b5c7',
            accent: '#fb923c',
            padding: 32,
        },
        browserAudit: {
            label: 'Typical Plumbing Website Right Now',
            domain: 'plumberyourcity.com',
            score: 'Speed: 28/100',
            issue1: 'No clickable phone number found',
            issue2: 'Not mobile-optimized',
            issue3: 'No same-day service CTA',
            issue4: 'Zero trust signals or reviews',
            bg: '#ffffff',
            warningBg: '#fef2f2',
            warningColor: '#dc2626',
            chromeBg: '#e5eaf1',
            padding: 28,
        },
        ctaPanel: {
            title: 'Want me to check your website?',
            text: 'I can send a quick free audit with 2-3 improvements that may help your plumbing website get more calls and quote requests.',
            buttonText: 'Get Free Website Audit',
            buttonUrl: '#',
            secondaryButtonText: 'How It Works',
            secondaryButtonUrl: '#',
            bg: '#eff6ff',
            border: '#bfdbfe',
            buttonBg: '#2563eb',
            secondaryButtonBg: '#ffffff',
            secondaryButtonColor: '#0f172a',
            color: '#0f172a',
            padding: 30,
        },
        premiumPlumberHeader: {
            brand: 'Abdullah',
            tagline: 'GROWTH EXPERT',
            rightText: 'Helping Plumbers Get More Jobs Online',
            dotColor: '#f47c20',
            bg: '#ffffff',
            color: '#0b1d3a',
            muted: '#8fa3bf',
            padding: 26,
        },
        premiumPlumberHeroScore: {
            pill: 'A quick idea for you',
            title: 'Your landing page could be costing you',
            titleAccent: 'new customers.',
            text: 'We help plumbing businesses turn more traffic into booked jobs with high-converting landing pages built for calls, quote requests and emergency leads.',
            stat1Title: 'More Leads',
            stat1Text: 'from the same traffic',
            stat2Title: 'Lower Cost',
            stat2Text: 'per qualified lead',
            stat3Title: 'More Booked',
            stat3Text: 'jobs on calendar',
            note: 'No obligation. Just value.',
            cardPill: 'Free audit preview',
            cardMeta: '2-min scan',
            cardTitle: 'Landing Page\nLeak Scorecard',
            cardText: 'A quick plumbing-focused check to show where leads may be dropping off.',
            score: '62',
            scoreLabel: 'Lead leak score',
            check1Title: 'CTA Visibility',
            check1Text: 'Is the call button obvious?',
            check2Title: 'Mobile Quote Flow',
            check2Text: 'Can users request fast?',
            check3Title: 'Trust Proof',
            check3Text: 'Reviews before the CTA?',
            bottom1: 'Calls',
            bottom2: 'Quotes',
            bottom3: 'Bookings',
            bg: '#0b1d3a',
            bg2: '#0f2a55',
            accent: '#f47c20',
            muted: '#8fa3bf',
            padding: 28,
        },
        premiumPlumberFindings: {
            eyebrow: 'What we found',
            title: 'Small changes.\nBig results.',
            text: 'These quick wins can make a big difference when local customers are ready to book.',
            item1: 'Stronger headline could increase conversions by improving first-click clarity',
            item2: 'Mobile experience issues may be losing emergency plumbing leads',
            item3: 'Shorter, trust-driven form can increase quote submissions',
            bg: '#0b1d3a',
            bg2: '#0f2a55',
            accent: '#f47c20',
            padding: 32,
        },
        premiumPlumberProcess: {
            eyebrow: 'Our Process',
            title: 'Strategy. Design. Results.',
            text: 'We design and optimize landing pages specifically for plumbing businesses so you get more calls, more leads, and more booked jobs.',
            item1Title: 'Conversion Focused',
            item1Text: 'Every element is built to convert visitors into leads.',
            item2Title: 'Speed Optimized',
            item2Text: 'Fast-loading pages that rank better and convert more.',
            item3Title: 'Trust Built-In',
            item3Text: 'Proof signals that turn visitors into buyers.',
            item4Title: 'Data Driven',
            item4Text: 'Continuous testing and optimization for maximum results.',
            accent: '#f47c20',
            padding: 32,
        },
        premiumPlumberIncludes: {
            title: "What's Included",
            item1: 'Conversion-Focused\nDesign',
            item2: 'Mobile-Friendly\nPages',
            item3: 'Fast Load\nSpeed',
            item4: 'Clear CTAs\nThat Convert',
            accent: '#f47c20',
            padding: 28,
        },
        premiumLeakHero: {
            titleLine1: 'Stop Losing Jobs',
            titleLine2: 'to a',
            titleAccent: 'Weak Landing Page.',
            text: "Let's build a page that brings you more calls, more bookings, and more revenue.",
            buttonText: 'Get My Free Landing Page Audit',
            buttonUrl: '#',
            visualTop: 'FREE',
            visualLine1: 'Landing Page',
            visualLine2: 'Audit',
            bg: '#0a1f3d',
            textColor: '#ffffff',
            muted: '#d9e6f8',
            accent: '#ff7a1a',
            buttonBg: '#ff7a1a',
            padding: 24,
        },
        premiumFunnel: {
            titleLine1: "More traffic isn't the solution if your",
            titleAccent: 'funnel leaks.',
            text: 'We plug the gaps that are costing you calls and jobs.',
            labelOne: 'Traffic',
            labelTwo: 'CTAs',
            labelThree: 'Follow-up',
            step1Title: 'Visitors',
            step1Text: 'Traffic comes in',
            step2Title: 'Leaky Pages',
            step2Text: 'Visitors drop off',
            step3Title: 'Lost Opportunities',
            step3Text: 'No calls, no bookings',
            step4Title: 'Optimized Landing Page',
            step4Text: 'More calls. More jobs.',
            bg: '#071b34',
            accent: '#ff7a1a',
            blue: '#3367ff',
            padding: 18,
        },
        premiumImpactDice: {
            smallWord: 'Small',
            smallTail: 'moves.',
            bigWord: 'Big',
            bigTail: ' impact.',
            text: "Big impact doesn't come from one big step. It comes from making the small things clear, fast, and easy to act on.",
            buttonText: 'Start Today',
            buttonUrl: '#',
            accent: '#ff7a1a',
            buttonBg: '#0a1f3d',
            padding: 18,
        },
        premiumCompare: {
            title: 'Convert or Leak..?',
            leftLabel: 'with optimization',
            leftPercent: '68%',
            leftTitle: 'more quote requests',
            leftText: 'from clearer page flow',
            rightLabel: 'without optimization',
            rightPercent: '18%',
            rightTitle: 'visitors bounce',
            rightText: 'before they call',
            bg: '#0a1f3d',
            accent: '#ff8a32',
            padding: 18,
        },
        premiumPlumberFinalCta: {
            title: 'Ready to turn more clicks into booked plumbing jobs?',
            text: "Send us your landing page and we'll show the biggest conversion leaks.",
            buttonText: 'Book Free Audit',
            buttonUrl: '#',
            note: 'No pressure. No hard pitch.',
            bg: '#fff5eb',
            border: '#fbd6bd',
            accent: '#f47c20',
            padding: 28,
        },
        premiumPlumberFooter: {
            brand: 'Abdullah',
            tagline: 'GROWTH EXPERT',
            text: 'Specialized landing pages for plumbers who want more calls and fewer lost leads.',
            title: "Let's Grow Your Plumbing Business",
            phone: '+92 308 7667665',
            note: "You're receiving this email because we thought your business could benefit from a better landing page. Unsubscribe: {{unsubscribe_link}}",
            bg: '#f7f9fc',
            accent: '#f47c20',
            muted: '#4a6080',
            padding: 26,
        },
        image: {
            url: '',
            alt: 'Campaign image',
            link: '',
            width: 100,
            padding: 20,
        },
        button: {
            text: 'Call to action',
            url: '#',
            align: 'center',
            bg: builderState.settings.accent,
            color: '#ffffff',
            padding: 24,
        },
        twoColumn: {
            leftTitle: 'Feature one',
            leftText: 'Explain the benefit in one or two short sentences.',
            rightTitle: 'Feature two',
            rightText: 'Use paired sections for comparisons, services, or highlights.',
            bg: '#ffffff',
            color: '#334155',
            padding: 26,
        },
        product: {
            imageUrl: '',
            title: 'Featured product',
            description: 'A concise product description that explains why it matters.',
            price: '$49',
            buttonText: 'View product',
            buttonUrl: '#',
            bg: '#f8fafc',
            padding: 24,
        },
        divider: {
            color: '#e2e8f0',
            thickness: 1,
            padding: 18,
        },
        spacer: {
            height: 28,
        },
        social: {
            facebook: '#',
            instagram: '#',
            linkedin: '#',
            website: '#',
            align: 'center',
            padding: 24,
        },
        signature: {
            name: 'Abdullah Hashmi',
            title: 'Website & Landing Page Specialist',
            website: 'abdullahhashmi.com',
            note: 'If this is not relevant, simply reply "not interested" and I will not contact you again.',
            avatarText: 'AH',
            bg: '#ffffff',
            color: '#0f172a',
            muted: '#64748b',
            padding: 24,
        },
        html: {
            html: '<p style="margin:0;">Custom HTML block</p>',
            padding: 20,
        },
    };
    return { ...common, ...(defaults[type] || {}), ...data };
}

function builderTemplates() {
    return {
        newsletter: [
            builderBlock('hero', {
                eyebrow: 'MONTHLY UPDATE',
                title: 'Your June digest is here, {{first_name}}',
                subtitle: 'A sharp roundup of what changed, what matters, and what to do next.',
                buttonText: 'Read the update',
            }),
            builderBlock('text', {
                content: 'Here are the top stories and updates from our team this month. Keep the copy concise and make each section easy to scan.',
            }),
            builderBlock('twoColumn'),
            builderBlock('button', { text: 'Explore everything', url: '#' }),
            builderBlock('divider'),
            builderBlock('social'),
            builderBlock('text', {
                content: 'You are receiving this because you subscribed to updates.\nUnsubscribe here: {{unsubscribe_link}}',
                fontSize: 12,
                color: '#64748b',
                align: 'center',
                padding: 22,
            }),
        ],
        promo: [
            builderBlock('hero', {
                eyebrow: 'LIMITED OFFER',
                title: 'A clean, focused promo layout',
                subtitle: 'Show the offer, support it with one proof point, and drive one action.',
                buttonText: 'Claim the offer',
                bg: '#fff7ed',
            }),
            builderBlock('product'),
            builderBlock('text', {
                content: 'Use urgency carefully. Add a deadline, guarantee, or customer proof to reduce hesitation.',
            }),
            builderBlock('button', { text: 'Get started', bg: '#ea580c' }),
            builderBlock('social'),
        ],
        announcement: [
            builderBlock('hero', {
                eyebrow: 'ANNOUNCEMENT',
                title: 'Something important just launched',
                subtitle: 'Explain the change in plain language and give readers a clear next step.',
                buttonText: 'See what is new',
                bg: '#ecfdf5',
            }),
            builderBlock('text', {
                content: 'What changed:\n- A clearer benefit\n- A faster workflow\n- Better results for your team',
            }),
            builderBlock('divider'),
            builderBlock('button', { text: 'Learn more', bg: '#059669' }),
        ],
        premiumLeakAudit: {
            settings: { bg: '#f7f9fc', contentBg: '#ffffff', accent: '#f47c20', font: 'Poppins', width: 580 },
            blocks: [
                builderBlock('premiumPlumberHeader'),
                builderBlock('premiumPlumberHeroScore'),
                builderBlock('premiumPlumberFindings'),
                builderBlock('premiumPlumberProcess'),
                builderBlock('premiumPlumberIncludes'),
                builderBlock('premiumFunnel'),
                builderBlock('premiumLeakHero', {
                    buttonUrl: 'https://calendly.com/mu-abdullahhashmi/30min?utm_source=mailpilot&utm_medium=email&utm_campaign=plumber_premium_leak_audit',
                    bg: '#0b1d3a',
                    accent: '#f47c20',
                    buttonBg: '#f47c20',
                    padding: 0,
                }),
                builderBlock('premiumImpactDice', {
                    buttonUrl: 'https://calendly.com/mu-abdullahhashmi/30min?utm_source=mailpilot&utm_medium=email&utm_campaign=plumber_premium_leak_audit',
                    accent: '#f47c20',
                }),
                builderBlock('premiumCompare'),
                builderBlock('premiumPlumberFinalCta', {
                    buttonUrl: 'https://calendly.com/mu-abdullahhashmi/30min?utm_source=mailpilot&utm_medium=email&utm_campaign=plumber_premium_leak_audit',
                }),
                builderBlock('premiumPlumberFooter'),
            ],
        },
    };
}

function builderLoadTemplate(name) {
    builderSnapshot();
    delete builderState.settings.mode;
    delete builderState.rawHtml;
    const template = builderTemplates()[name] || builderTemplates().newsletter;
    if (Array.isArray(template)) {
        builderState.blocks = template;
    } else {
        builderState.settings = Object.assign(
            { bg: '#f4f7fb', contentBg: '#ffffff', accent: '#2563eb', font: 'Poppins' },
            template.settings || {}
        );
        builderState.blocks = template.blocks || [];
    }
    builderSelectedId = builderState.blocks[0]?.id || null;
    builderApplyStateSettingsToControls();
    builderRender();
}

function builderParseStateFromHtml(html) {
    const match = String(html || '').match(/<!--MAILPILOT_BUILDER\s+([A-Za-z0-9+/=]+)-->/);
    if (!match) return null;
    try {
        return JSON.parse(decodeURIComponent(escape(atob(match[1]))));
    } catch (e) {
        return null;
    }
}

function builderApplyStateSettingsToControls() {
    builderState.settings = Object.assign(
        { bg: '#f4f7fb', contentBg: '#ffffff', accent: '#2563eb', font: 'Poppins', width: 640 },
        builderState.settings || {}
    );
    document.getElementById('builderBg').value = builderState.settings.bg || '#f4f7fb';
    document.getElementById('builderContentBg').value = builderState.settings.contentBg || '#ffffff';
    document.getElementById('builderAccent').value = builderState.settings.accent || '#2563eb';
    document.getElementById('builderFont').value = builderState.settings.font || 'Poppins';
    const widthInput = document.getElementById('builderWidth');
    if (widthInput) widthInput.value = Number(builderState.settings.width) || 640;
}

function builderRenderTemplateSelect() {
    const select = document.getElementById('savedTemplateSelect');
    if (!select) return;
    const options = builderSavedTemplates.map((template) => {
        const subject = template.subject ? ` - ${template.subject}` : '';
        return `<option value="${builderAttr(template.id)}">${builderEsc(template.name + subject)}</option>`;
    }).join('');
    select.innerHTML = `<option value="">Saved templates...</option>${options}`;
}

async function builderLoadSavedTemplateList() {
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    try {
        const result = await apiCall(basePath + '/api/campaign-template-list.php', {}, 'GET');
        builderSavedTemplates = result.templates || [];
        builderRenderTemplateSelect();
    } catch (err) {
        Toast.warning(err.message || 'Saved templates could not be loaded.');
    }
}

async function builderSaveCurrentTemplate() {
    builderSyncHtml();
    const defaultName = document.getElementById('campaignName').value || 'Campaign Template';
    const name = prompt('Template name:', defaultName);
    if (!name) return;

    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    try {
        const result = await apiCall(basePath + '/api/campaign-template-save.php', {
            name: name.trim(),
            subject: document.getElementById('campaignSubject').value,
            body_html: document.getElementById('emailBody').value,
        });

        if (result.success) {
            Toast.success('Template saved.');
            await builderLoadSavedTemplateList();
            const select = document.getElementById('savedTemplateSelect');
            if (select && result.template_id) select.value = result.template_id;
        } else {
            Toast.error(result.message || 'Template save failed.');
        }
    } catch (err) {
        Toast.error(err.message || 'Template save failed.');
    }
}

function builderApplySavedTemplate(templateId) {
    if (!templateId) return;
    const template = builderSavedTemplates.find((item) => String(item.id) === String(templateId));
    if (!template) return;

    builderSnapshot();
    const savedState = builderParseStateFromHtml(template.body_html);
    if (savedState && savedState.settings?.mode === 'rawHtml') {
        builderState = savedState;
    } else if (savedState && Array.isArray(savedState.blocks)) {
        builderState = savedState;
    } else if (builderLooksLikeFullHtml(template.body_html)) {
        builderSetRawHtmlState(template.body_html);
    } else {
        builderState = {
            settings: { bg: '#f4f7fb', contentBg: '#ffffff', accent: '#2563eb', font: 'Poppins' },
            blocks: [builderBlock('html', { html: template.body_html, padding: 0 })],
        };
    }

    if (template.subject) {
        document.getElementById('campaignSubject').value = template.subject;
    }

    builderSelectedId = builderState.blocks[0]?.id || null;
    builderApplyStateSettingsToControls();
    builderRender();
    builderSyncHtml();
    Toast.success('Template loaded.');
}

function builderInit() {
    builderRenderBlockLibrary();
    const hidden = document.getElementById('emailBody');
    const existing = hidden.value.trim();
    const savedState = builderParseStateFromHtml(existing);
    if (savedState && savedState.settings?.mode === 'rawHtml') {
        builderState = savedState;
    } else if (savedState && Array.isArray(savedState.blocks)) {
        builderState = savedState;
    } else if (builderLooksLikeFullHtml(existing)) {
        builderSetRawHtmlState(existing);
    } else if (existing) {
        builderState.blocks = [builderBlock('html', { html: existing, padding: 0 })];
    } else {
        builderState.blocks = builderTemplates().newsletter;
    }
    builderSelectedId = builderState.blocks[0]?.id || null;
    builderApplyStateSettingsToControls();
    builderRender();
    builderLoadSavedTemplateList();
}

function builderUpdateSettings() {
    builderState.settings.bg = document.getElementById('builderBg').value;
    builderState.settings.contentBg = document.getElementById('builderContentBg').value;
    builderState.settings.accent = document.getElementById('builderAccent').value;
    builderState.settings.font = document.getElementById('builderFont').value;
    const widthInput = document.getElementById('builderWidth');
    builderState.settings.width = Math.min(720, Math.max(320, Number(widthInput?.value) || 640));
    document.getElementById('builderPreviewWrap').style.background = builderState.settings.bg;
    document.getElementById('builderCanvas').style.background = builderState.settings.contentBg;
    document.getElementById('builderCanvas').style.fontFamily = builderFontStack();
    document.getElementById('builderCanvas').style.maxWidth = `${builderState.settings.width}px`;
    builderRenderCanvas();
}

function builderAddBlock(type) {
    if (builderIsRawHtmlMode()) {
        const ok = confirm('Leave Full HTML Mode and use editable blocks instead?');
        if (!ok) return;

        builderSnapshot();
        delete builderState.settings.mode;
        delete builderState.rawHtml;
        builderState.blocks = [];
    }

    builderSnapshot();
    const block = builderBlock(type);
    const selectedIndex = builderState.blocks.findIndex((item) => item.id === builderSelectedId);
    if (selectedIndex >= 0) {
        builderState.blocks.splice(selectedIndex + 1, 0, block);
    } else {
        builderState.blocks.push(block);
    }
    builderSelectedId = block.id;
    builderRender();
}

function builderSelectBlock(id) {
    builderSelectedId = id;
    builderRender();
}

function builderGetBlock(id = builderSelectedId) {
    return builderState.blocks.find((block) => block.id === id);
}

function builderSet(id, key, value) {
    if (key === 'html' && builderLooksLikeFullHtml(value)) {
        const ok = confirm('This is a complete HTML document. Switch to Full HTML Mode so it sends as the full email instead of placing it inside a block?');
        if (ok) {
            builderSnapshot();
            builderSetRawHtmlState(value);
            builderApplyStateSettingsToControls();
            builderRender();
            builderSyncHtml();
            return;
        }
        Toast.warning('Full email source was not inserted into a partial HTML block.');
        return;
    }

    const block = builderGetBlock(id);
    if (!block) return;
    block[key] = value;
    builderRenderCanvas();
}

function builderMove(id, direction) {
    builderSnapshot();
    const index = builderState.blocks.findIndex((block) => block.id === id);
    const next = index + direction;
    if (index < 0 || next < 0 || next >= builderState.blocks.length) return;
    const [block] = builderState.blocks.splice(index, 1);
    builderState.blocks.splice(next, 0, block);
    builderSelectedId = id;
    builderRender();
}

function builderDuplicate(id) {
    builderSnapshot();
    const index = builderState.blocks.findIndex((block) => block.id === id);
    if (index < 0) return;
    const copy = JSON.parse(JSON.stringify(builderState.blocks[index]));
    copy.id = builderId();
    builderState.blocks.splice(index + 1, 0, copy);
    builderSelectedId = copy.id;
    builderRender();
}

function builderDelete(id) {
    builderSnapshot();
    const index = builderState.blocks.findIndex((block) => block.id === id);
    if (index < 0) return;
    builderState.blocks.splice(index, 1);
    builderSelectedId = builderState.blocks[Math.min(index, builderState.blocks.length - 1)]?.id || null;
    builderRender();
}

function builderBlockTools(block, index) {
    return `
        <div class="builder-block-tools" onclick="event.stopPropagation()">
            <button type="button" onclick="builderMove('${block.id}', -1)" ${index === 0 ? 'disabled' : ''}>Up</button>
            <button type="button" onclick="builderMove('${block.id}', 1)" ${index === builderState.blocks.length - 1 ? 'disabled' : ''}>Down</button>
            <button type="button" onclick="builderDuplicate('${block.id}')">Copy</button>
            <button type="button" onclick="builderDelete('${block.id}')">Del</button>
        </div>
    `;
}

function builderPreviewBlock(block) {
    const html = builderBlockInnerHtml(block);
    const index = builderState.blocks.findIndex((item) => item.id === block.id);
    return `
        <div class="builder-render-block ${block.id === builderSelectedId ? 'selected' : ''}" onclick="builderSelectBlock('${block.id}')">
            ${builderBlockTools(block, index)}
            ${html}
        </div>
    `;
}

function builderPremiumIconSymbol(label) {
    const map = {
        '+': '&#8599;',
        '$': '$',
        B: '&#10003;',
        C: '&#8599;',
        M: '&#9637;',
        T: '&#9733;',
        S: '&#9889;',
        D: '&#9635;',
        F: '&#9889;',
        P: '&#9742;',
        Q: '&#9998;',
        phone: '&#9742;',
        quote: '&#9998;',
        booked: '&#10003;',
    };
    return map[String(label)] || builderEsc(label);
}

function builderPremiumIcon(label, bg = '#fff5eb', color = '#f47c20', size = 42, fontSize = 16, radius = 14, margin = '0 auto 10px auto') {
    return `<div style="width:${size}px;height:${size}px;border-radius:${radius}px;background:${builderAttr(bg)};color:${builderAttr(color)};font-size:${fontSize}px;line-height:${size}px;text-align:center;font-weight:700;margin:${margin};box-shadow:inset 0 1px 0 rgba(255,255,255,.9),0 8px 18px rgba(244,124,32,.12);">${builderPremiumIconSymbol(label)}</div>`;
}

function builderPremiumMiniIcon(label, bg = '#fff5eb', color = '#f47c20') {
    return builderPremiumIcon(label, bg, color, 42, 16, 14);
}

function builderPremiumPlumberHeaderHtml(block) {
    const pad = Number(block.padding) || 0;
    return `
        <div style="padding:${pad}px 28px 6px 28px;background:${builderAttr(block.bg || '#ffffff')};">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td align="left" style="font-family:${builderAttr(builderFontStack())};">
                        <div style="font-size:24px;font-weight:700;letter-spacing:0;color:${builderAttr(block.color || '#0b1d3a')};line-height:.72;">${builderEsc(block.brand)}<span style="color:${builderAttr(block.dotColor || '#f47c20')};">.</span><br><span style="font-size:7px;font-weight:700;letter-spacing:.28em;color:${builderAttr(block.muted || '#8fa3bf')};text-transform:uppercase;">${builderEsc(block.tagline)}</span></div>
                    </td>
                    <td align="right" class="mp-hide-mobile" style="font-size:12px;color:${builderAttr(block.muted || '#4a6080')};font-weight:700;">${builderEsc(block.rightText)}</td>
                </tr>
            </table>
        </div>
    `;
}

function builderPremiumPlumberHeroScoreHtml(block) {
    const pad = Number(block.padding) || 0;
    const accent = builderAttr(block.accent || '#f47c20');
    const muted = builderAttr(block.muted || '#8fa3bf');
    const row = (title, text, icon, border = true) => `
        <tr>
            <td style="padding:8px 0;${border ? 'border-bottom:1px solid #dde5f0;' : ''}">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td width="32">${builderPremiumIcon(icon, '#fff5eb', accent, 26, 12, 8, '0')}</td>
                        <td><div style="font-size:11px;font-weight:700;color:#0b1d3a;line-height:1.25;">${builderEsc(title)}</div><div style="font-size:9px;color:#4a6080;line-height:1.35;">${builderEsc(text)}</div></td>
                    </tr>
                </table>
            </td>
        </tr>
    `;
    const stat = (title, text, icon) => `
        <td width="33.33%" align="left" valign="top" style="padding-right:8px;">
            ${builderPremiumIcon(icon, '#fff5eb', accent, 42, 15, 14, '0 0 10px 0')}
            <div style="font-size:12px;font-weight:700;color:#ffffff;">${builderEsc(title)}</div>
            <div style="font-size:10px;line-height:1.45;color:${muted};">${builderEsc(text)}</div>
        </td>
    `;
    return `
        <div style="background:${builderAttr(block.bg || '#0b1d3a')};background-image:linear-gradient(140deg,${builderAttr(block.bg || '#0b1d3a')} 0%,${builderAttr(block.bg2 || '#0f2a55')} 58%,#0e1e3e 100%);color:#ffffff;padding:${pad}px 28px 32px 28px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td class="mp-stack" width="54%" valign="top" style="padding-right:20px;">
                        <span style="display:inline-block;padding:7px 13px;border-radius:999px;background:#fff5eb;color:${accent};font-size:10px;font-weight:700;line-height:1;letter-spacing:.12em;text-transform:uppercase;border:1px solid #fbd6bd;">${builderEsc(block.pill)}</span>
                        <div style="font-size:24px;line-height:1.10;font-weight:700;letter-spacing:-1.2px;margin:0;padding-top:22px;color:#ffffff;">${builderEsc(block.title)} <span style="color:#f99148;">${builderEsc(block.titleAccent)}</span></div>
                        <div style="font-size:14px;line-height:1.72;margin:0;padding-top:18px;color:#dbeafe;">${builderLines(block.text)}</div>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:30px;"><tr>${stat(block.stat1Title, block.stat1Text, '+')}${stat(block.stat2Title, block.stat2Text, '$')}${stat(block.stat3Title, block.stat3Text, 'B')}</tr></table>
                        <div style="margin-top:28px;height:1px;background:rgba(255,255,255,.14);line-height:1px;font-size:1px;">&nbsp;</div>
                        <div style="padding-top:14px;color:${muted};font-size:12px;line-height:1.55;"><span style="width:7px;height:7px;display:inline-block;border-radius:50%;background:#fb923c;vertical-align:middle;margin-right:8px;"></span>${builderEsc(block.note)}</div>
                    </td>
                    <td class="mp-stack mp-mobile-top" width="46%" valign="top">
                        <div style="background:#ffffff;border:1px solid #dde5f0;border-radius:18px;overflow:hidden;box-shadow:0 20px 55px rgba(8,24,50,.14);">
                            <div style="padding:17px 20px 16px 20px;background:#0b1d3a;background-image:linear-gradient(90deg,rgba(11,29,58,.98),rgba(11,29,58,.82));">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td><span style="display:inline-block;background:rgba(244,124,32,.16);color:#fb923c;border:1px solid rgba(251,146,60,.22);padding:6px 9px;border-radius:999px;font-size:8px;letter-spacing:.11em;font-weight:700;text-transform:uppercase;">${builderEsc(block.cardPill)}</span></td><td align="right" style="font-size:10px;color:${muted};font-weight:700;">${builderEsc(block.cardMeta)}</td></tr></table>
                                <div style="font-size:20px;line-height:1.14;margin:13px 0 0 0;font-weight:700;letter-spacing:-.7px;color:#ffffff;max-width:165px;">${builderLines(block.cardTitle)}</div>
                                <div style="font-size:11px;line-height:1.48;margin:8px 0 0 0;color:${muted};max-width:175px;">${builderLines(block.cardText)}</div>
                            </div>
                            <div style="padding:18px 18px 14px 18px;background:#ffffff;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        <td width="38%" align="center" valign="middle" style="padding-right:12px;">
                                            <div style="width:98px;height:98px;border-radius:50%;background:${accent};padding:7px;box-shadow:0 18px 40px rgba(244,124,32,.18);"><div style="width:84px;height:84px;border-radius:50%;background:#ffffff;text-align:center;padding-top:22px;"><div style="font-size:24px;line-height:1;font-weight:700;letter-spacing:-1px;color:#0b1d3a;">${builderEsc(block.score)}</div><div style="font-size:11px;font-weight:700;color:#4a6080;">/ 100</div></div></div>
                                            <div style="font-size:8px;color:#4a6080;font-weight:700;padding-top:8px;letter-spacing:.08em;text-transform:uppercase;line-height:1.3;">${builderEsc(block.scoreLabel)}</div>
                                        </td>
                                        <td width="62%" valign="middle"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">${row(block.check1Title, block.check1Text, 'C')}${row(block.check2Title, block.check2Text, 'M')}${row(block.check3Title, block.check3Text, 'T', false)}</table></td>
                                    </tr>
                                </table>
                            </div>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#0d2347;color:#ffffff;">
                                <tr>
                                    <td align="center" style="padding:11px 8px;border-right:1px solid rgba(255,255,255,.12);font-size:10px;font-weight:700;color:#ffffff;"><span style="color:${accent};font-size:13px;">${builderPremiumIconSymbol('P')}</span><br><span style="font-size:8px;color:${muted};font-weight:700;">${builderEsc(block.bottom1)}</span></td>
                                    <td align="center" style="padding:11px 8px;border-right:1px solid rgba(255,255,255,.12);font-size:10px;font-weight:700;color:#ffffff;"><span style="color:${accent};font-size:13px;">${builderPremiumIconSymbol('Q')}</span><br><span style="font-size:8px;color:${muted};font-weight:700;">${builderEsc(block.bottom2)}</span></td>
                                    <td align="center" style="padding:11px 8px;font-size:10px;font-weight:700;color:#ffffff;"><span style="color:${accent};font-size:13px;">${builderPremiumIconSymbol('B')}</span><br><span style="font-size:8px;color:${muted};font-weight:700;">${builderEsc(block.bottom3)}</span></td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    `;
}

function builderPremiumPlumberFindingsHtml(block) {
    const item = (text, label) => `<tr><td style="background:rgba(255,255,255,.065);border-radius:12px;padding:15px 16px;color:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td width="56"><div style="width:44px;height:44px;border-radius:50%;background:#fff5eb;color:${builderAttr(block.accent || '#f47c20')};line-height:44px;text-align:center;font-size:15px;font-weight:700;">${label}</div></td><td style="font-size:13px;line-height:1.45;color:#ffffff;font-weight:700;">${builderEsc(text)}</td></tr></table></td></tr><tr><td height="10" style="font-size:1px;line-height:10px;">&nbsp;</td></tr>`;
    return `
        <div style="padding:${Number(block.padding) || 0}px 28px;background:${builderAttr(block.bg || '#0b1d3a')};background-image:linear-gradient(145deg,${builderAttr(block.bg || '#0b1d3a')} 0%,${builderAttr(block.bg2 || '#0f2a55')} 100%);border-radius:0 0 8px 8px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td class="mp-stack" width="36%" valign="middle" style="padding-right:22px;">
                        <div style="font-size:10px;color:#fb923c;font-weight:700;letter-spacing:.16em;text-transform:uppercase;">${builderEsc(block.eyebrow)}</div>
                        <div style="font-size:15px;line-height:1.18;font-weight:700;letter-spacing:-.45px;color:#ffffff;padding-top:12px;">${builderLines(block.title)}</div>
                        <div style="width:66px;height:2px;background:${builderAttr(block.accent || '#f47c20')};margin:15px 0 18px 0;line-height:1px;font-size:1px;">&nbsp;</div>
                        <div style="font-size:12px;line-height:1.55;color:#dde5f0;">${builderLines(block.text)}</div>
                    </td>
                    <td class="mp-stack mp-mobile-top" width="64%" valign="middle"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">${item(block.item1, '1')}${item(block.item2, '2')}${item(block.item3, '3').replace('<tr><td height="10" style="font-size:1px;line-height:10px;">&nbsp;</td></tr>', '')}</table></td>
                </tr>
            </table>
        </div>
    `;
}

function builderPremiumPlumberProcessHtml(block) {
    const accent = builderAttr(block.accent || '#f47c20');
    const card = (title, text, icon, last = false) => `<td class="mp-stack ${last ? '' : 'mp-process-border'}" width="25%" align="center" valign="top" style="padding:0 11px;${last ? '' : 'border-right:1px solid #dde5f0;'}">${builderPremiumMiniIcon(icon, '#fff5eb', accent)}<div style="font-size:11px;font-weight:700;color:#1e3048;">${builderEsc(title)}</div><div style="font-size:10px;line-height:1.45;color:#4a6080;padding-top:5px;">${builderLines(text)}</div></td>`;
    return `
        <div style="padding:${Number(block.padding) || 0}px 28px;background:#ffffff;text-align:center;">
            <div style="font-size:10px;color:#fb923c;font-weight:700;letter-spacing:.18em;text-transform:uppercase;">${builderEsc(block.eyebrow)}</div>
            <div style="font-size:15px;line-height:1.18;font-weight:700;letter-spacing:-.45px;color:#1e3048;padding-top:9px;">${builderEsc(block.title)}</div>
            <div style="font-size:14px;line-height:1.72;color:#4a6080;padding:14px 28px 26px 28px;">${builderLines(block.text)}</div>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>${card(block.item1Title, block.item1Text, 'C')}${card(block.item2Title, block.item2Text, 'S')}${card(block.item3Title, block.item3Text, 'T')}${card(block.item4Title, block.item4Text, 'D', true)}</tr></table>
        </div>
    `;
}

function builderPremiumPlumberIncludesHtml(block) {
    const accent = builderAttr(block.accent || '#f47c20');
    const item = (text, icon, last = false) => `<td width="25%" align="center" valign="top" style="${last ? '' : 'border-right:1px solid #dde5f0;'}padding:0 7px;"><div style="font-size:24px;line-height:1;color:${accent};font-weight:700;">${builderPremiumIconSymbol(icon)}</div><div style="font-size:10px;line-height:1.45;font-weight:700;color:#0b1d3a;padding-top:8px;">${builderLines(text)}</div></td>`;
    return `
        <div style="padding:0 28px ${Number(block.padding) || 0}px 28px;background:#ffffff;">
            <div style="border:1px solid #dde5f0;border-radius:14px;padding:20px 12px;background:#ffffff;box-shadow:0 10px 34px rgba(9,17,22,.04);text-align:center;">
                <div style="font-size:16px;line-height:1.35;font-weight:700;color:#1e3048;padding-bottom:17px;">${builderEsc(block.title)}</div>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>${item(block.item1, '+')}${item(block.item2, 'M')}${item(block.item3, 'F')}${item(block.item4, 'C', true)}</tr></table>
            </div>
        </div>
    `;
}

function builderPremiumPipeGraphicHtml(block) {
    const accent = builderAttr(block.accent || '#f47c20');
    return `
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#102641;background-image:linear-gradient(135deg,rgba(255,255,255,.08),rgba(255,255,255,0));">
            <tr>
                <td align="center" style="padding:24px 10px;">
                    <table role="presentation" width="218" cellspacing="0" cellpadding="0" border="0" style="width:218px;max-width:100%;border-collapse:separate;">
                        <tr>
                            <td width="86">&nbsp;</td>
                            <td align="center" colspan="2" style="padding:0 0 0 0;">
                                <div style="height:20px;line-height:20px;border-radius:999px;background:#cfd7df;background-image:linear-gradient(#f1f5f9,#aeb8c1);box-shadow:0 5px 0 rgba(0,0,0,.18);font-size:1px;">&nbsp;</div>
                            </td>
                        </tr>
                        <tr>
                            <td width="112" align="center" valign="top" style="padding-top:8px;">
                                <div style="width:104px;height:84px;border-radius:999px;background:#ffffff;color:#0b1d3a;text-align:center;padding-top:20px;box-shadow:0 20px 42px rgba(0,0,0,.22);">
                                    <div style="font-size:24px;color:${accent};font-weight:700;line-height:1;">${builderEsc(block.visualTop || 'FREE')}</div>
                                    <div style="font-size:12px;font-weight:700;padding-top:5px;line-height:1.25;">${builderEsc(block.visualLine1 || 'Landing Page')}<br>${builderEsc(block.visualLine2 || 'Audit')}</div>
                                </div>
                            </td>
                            <td width="106" align="center" valign="top">
                                <div style="width:62px;height:74px;border-left:22px solid #b7c0c7;border-right:22px solid #b7c0c7;border-bottom:22px solid #b7c0c7;border-top:0;border-radius:0 0 58px 58px;line-height:1px;font-size:1px;">&nbsp;</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    `;
}

function builderPremiumDiceFaceHtml(pips, rotate = 0) {
    const pipSet = new Set(pips);
    const cells = Array.from({ length: 9 }, (_, index) => {
        const pip = pipSet.has(index + 1) ? '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#111111;line-height:14px;font-size:1px;box-shadow:inset 1px 1px 2px rgba(255,255,255,.18),0 2px 3px rgba(0,0,0,.18);">&nbsp;</span>' : '&nbsp;';
        return `<td align="center" valign="middle" width="33.33%" height="27" style="font-size:1px;line-height:1px;">${pip}</td>`;
    });

    return `
        <table role="presentation" width="104" height="104" cellspacing="0" cellpadding="0" border="0" style="width:104px;height:104px;background:#ffffff;background-image:linear-gradient(145deg,#ffffff 0%,#f9fbfd 46%,#e9eff6 100%);border:1px solid #ffffff;border-radius:20px;box-shadow:inset 4px 4px 9px rgba(255,255,255,.96),inset -6px -6px 13px rgba(148,163,184,.22),0 22px 42px rgba(31,55,84,.24);border-collapse:separate;transform:rotate(${rotate}deg);">
            <tr>${cells.slice(0, 3).join('')}</tr>
            <tr>${cells.slice(3, 6).join('')}</tr>
            <tr>${cells.slice(6, 9).join('')}</tr>
        </table>
    `;
}

function builderPremiumSparkBarsHtml(color, heights) {
    return `
        <table role="presentation" width="100%" height="86" cellspacing="0" cellpadding="0" border="0" style="height:86px;border-left:2px solid rgba(255,255,255,.6);border-bottom:2px solid rgba(255,255,255,.28);">
            <tr>
                ${heights.map((height) => `<td valign="bottom" align="center" style="padding:0 3px;"><div style="width:18px;height:${height}px;background:${color};border-radius:6px 6px 0 0;line-height:${height}px;font-size:1px;">&nbsp;</div></td>`).join('')}
            </tr>
        </table>
    `;
}

function builderPremiumLeakHeroHtml(block) {
    const padding = Number(block.padding) || 0;
    const bg = builderAttr(block.bg || '#0a1f3d');
    const accent = builderAttr(block.accent || '#ff7a1a');
    const buttonBg = builderAttr(block.buttonBg || block.accent || '#ff7a1a');
    return `
        <div style="padding:${padding}px 28px 28px 28px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:${bg};border-radius:18px;border-collapse:separate;overflow:hidden;color:#ffffff;">
                <tr>
                    <td class="mp-stack" width="55%" valign="middle" style="padding:30px 26px;">
                        <div style="font-size:15px;line-height:1.18;font-weight:700;letter-spacing:-.45px;color:#ffffff;">${builderEsc(block.titleLine1)}<br>${builderEsc(block.titleLine2)} <span style="color:${accent};">${builderEsc(block.titleAccent)}</span></div>
                        <div style="font-size:14px;line-height:1.72;color:#dde5f0;padding-top:14px;">${builderLines(block.text)}</div>
                        <div style="padding-top:20px;"><a href="${builderAttr(block.buttonUrl)}" style="display:inline-block;background:${buttonBg};color:#ffffff;text-decoration:none;padding:15px 23px;border-radius:8px;font-size:10px;font-weight:700;letter-spacing:.01em;box-shadow:0 12px 30px rgba(244,124,32,.24);">${builderEsc(block.buttonText)} &#8594;</a></div>
                    </td>
                    <td class="mp-stack" width="45%" valign="middle">
                        ${builderPremiumPipeGraphicHtml(block)}
                    </td>
                </tr>
            </table>
        </div>
    `;
}

function builderPremiumFunnelHtml(block) {
    const padding = Number(block.padding) || 0;
    const bg = builderAttr(block.bg || '#071b34');
    const accent = builderAttr(block.accent || '#ff7a1a');
    const blue = builderAttr(block.blue || '#3367ff');
    return `
        <div style="padding:0 28px 28px 28px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:${bg}; border-radius:18px; border-collapse:separate; overflow:hidden;color:#ffffff;">
                <tr>
                    <td class="mp-stack" width="38%" valign="middle" style="padding:28px 24px;">
                        <div style="font-size:15px;line-height:1.18;font-weight:700;letter-spacing:-.45px;color:#ffffff;">${builderEsc(block.titleLine1)} <span style="color:${accent};">${builderEsc(block.titleAccent)}</span></div>
                        <div style="font-size:14px;line-height:1.72;color:#8fa3bf;padding-top:16px;">${builderLines(block.text)}</div>
                    </td>
                    <td class="mp-stack mp-mobile-top" width="34%" valign="middle" align="center" style="padding:22px 0;">
                        <table role="presentation" width="218" cellspacing="0" cellpadding="0" border="0" align="center" style="width:218px;max-width:100%;">
                            <tr><td align="center"><span style="display:inline-block;background:#ffffff;color:#0b1d3a;border-radius:6px;padding:7px 12px;font-size:12px;font-weight:700;margin:0 8px 8px 0;">${builderEsc(block.labelOne)}</span><span style="display:inline-block;background:#ffffff;color:#0b1d3a;border-radius:6px;padding:7px 12px;font-size:12px;font-weight:700;margin:0 0 8px 8px;">${builderEsc(block.labelTwo)}</span></td></tr>
                            <tr><td align="center" style="height:30px;background:${blue};border-radius:12px 12px 4px 4px;"></td></tr>
                            <tr><td align="center"><div style="width:154px;height:30px;background:#2b60eb;"></div></td></tr>
                            <tr><td align="center"><span style="display:inline-block;background:#ffffff;color:#0b1d3a;border-radius:6px;padding:7px 12px;font-size:12px;font-weight:700;margin:0 0 8px 0;">${builderEsc(block.labelThree)}</span></td></tr>
                            <tr><td align="center"><div style="width:72px;height:58px;background:#122444;border-radius:0 0 18px 18px;"></div></td></tr>
                        </table>
                    </td>
                    <td class="mp-stack mp-mobile-top" width="28%" valign="middle" style="padding:28px 24px 28px 4px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                            <tr><td style="padding:0 0 12px 0; border-bottom:1px solid rgba(255,255,255,.12);"><div style="font-size:13px; color:#ffffff; font-weight:700;">${builderEsc(block.step1Title)}</div><div style="font-size:12px; color:#9fb1ca;">${builderEsc(block.step1Text)}</div></td></tr>
                            <tr><td style="padding:12px 0; border-bottom:1px solid rgba(255,255,255,.12);"><div style="font-size:13px; color:#ffffff; font-weight:700;">${builderEsc(block.step2Title)}</div><div style="font-size:12px; color:#9fb1ca;">${builderEsc(block.step2Text)}</div></td></tr>
                            <tr><td style="padding:12px 0; border-bottom:1px solid rgba(255,255,255,.12);"><div style="font-size:13px; color:#ffffff; font-weight:700;">${builderEsc(block.step3Title)}</div><div style="font-size:12px; color:#9fb1ca;">${builderEsc(block.step3Text)}</div></td></tr>
                            <tr><td style="padding:12px 0 0 0;"><div style="font-size:13px; color:#ffffff; font-weight:700;">${builderEsc(block.step4Title)}</div><div style="font-size:12px; color:#9fb1ca;">${builderEsc(block.step4Text)}</div></td></tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
    `;
}

function builderPremiumImpactDiceHtml(block) {
    const padding = Number(block.padding) || 0;
    const accent = builderAttr(block.accent || '#ff7a1a');
    const buttonBg = builderAttr(block.buttonBg || '#0a1f3d');
    return `
        <div style="padding:${padding}px 28px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #d6e1ee; border-radius:20px; border-collapse:separate; overflow:hidden; background-color:#f7fbff; background-image:linear-gradient(#d9e5f2 1px, transparent 1px), linear-gradient(90deg, #d9e5f2 1px, transparent 1px); background-size:32px 32px;">
                <tr><td align="center" style="padding:44px 42px 20px 42px;">
                    <div style="font-size:34px; line-height:1.14; font-weight:700; color:#20334f; margin-bottom:18px;"><span style="font-style:italic;">${builderEsc(block.smallWord)}</span> ${builderEsc(block.smallTail)}<br><span style="font-style:italic; color:${accent};">${builderEsc(block.bigWord)}</span>${builderEsc(block.bigTail)}</div>
                    <div style="font-size:17px; line-height:1.7; color:#4d6485; margin:0 auto 28px auto; max-width:470px;">${builderLines(block.text)}</div>
                    <a href="${builderAttr(block.buttonUrl)}" style="display:inline-block; background:${buttonBg}; color:#ffffff; text-decoration:none; padding:16px 24px; border-radius:8px; font-size:15px; line-height:1; font-weight:700;">${builderEsc(block.buttonText)}</a>
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:30px auto 0 auto;">
                        <tr>
                            <td style="padding:10px 0 0 0;">${builderPremiumDiceFaceHtml([1, 3, 5, 7, 9], -9)}</td>
                            <td style="padding:0 0 0 10px;">${builderPremiumDiceFaceHtml([1, 3, 7, 9], 7)}</td>
                        </tr>
                    </table>
                </td></tr>
            </table>
        </div>
    `;
}

function builderPremiumCompareHtml(block) {
    const padding = Number(block.padding) || 0;
    const bg = builderAttr(block.bg || '#0a1f3d');
    const accent = builderAttr(block.accent || '#ff8a32');
    return `
        <div style="padding:${padding}px 28px 26px 28px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:${bg}; border-radius:24px; border-collapse:separate; overflow:hidden;">
                <tr><td align="center" style="padding:32px 28px 10px 28px; font-size:38px; line-height:1.05; font-weight:700; color:#ffffff;">${builderEsc(block.title)}</td></tr>
                <tr><td style="padding:10px 28px 30px 28px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;">
                        <tr>
                            <td class="mp-stack mp-mobile-edge" width="50%" valign="top" style="padding:0 12px 0 0;">
                                <div style="background:#294360; border:1px solid rgba(255,255,255,.12); border-radius:18px; padding:24px 22px; color:#ffffff;">
                                    <div style="font-size:19px; font-weight:700; color:${accent}; margin-bottom:22px;">${builderEsc(block.leftLabel)}</div>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px; height:58px; border:10px solid ${accent}; border-radius:50%; text-align:center; line-height:58px; font-size:20px; font-weight:700;">${builderEsc(block.leftPercent)}</div></td><td><div style="font-size:18px; line-height:1.18; font-weight:700;">${builderEsc(block.leftTitle)}</div><div style="font-size:14px; line-height:1.45; color:#cbd7e8;">${builderEsc(block.leftText)}</div></td></tr></table>
                                    <div style="margin-top:24px;">${builderPremiumSparkBarsHtml(accent, [16, 28, 34, 39, 55, 48, 61, 66, 78])}</div>
                                </div>
                            </td>
                            <td class="mp-stack mp-mobile-edge mp-mobile-gap" width="50%" valign="top" style="padding:0 0 0 12px;">
                                <div style="background:#294360; border:1px solid rgba(255,255,255,.12); border-radius:18px; padding:24px 22px; color:#ffffff;">
                                    <div style="font-size:19px; font-weight:700; color:#d5deeb; margin-bottom:22px;">${builderEsc(block.rightLabel)}</div>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="82"><div style="width:58px; height:58px; border:10px solid #9aa8ba; border-radius:50%; text-align:center; line-height:58px; font-size:20px; font-weight:700;">${builderEsc(block.rightPercent)}</div></td><td><div style="font-size:18px; line-height:1.18; font-weight:700;">${builderEsc(block.rightTitle)}</div><div style="font-size:14px; line-height:1.45; color:#cbd7e8;">${builderEsc(block.rightText)}</div></td></tr></table>
                                    <div style="margin-top:24px;">${builderPremiumSparkBarsHtml('#ffffff', [76, 61, 56, 47, 38, 31, 23, 18, 10])}</div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </td></tr>
            </table>
        </div>
    `;
}

function builderPremiumPlumberFinalCtaHtml(block) {
    return `
        <div style="padding:0 28px ${Number(block.padding) || 0}px 28px;background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:${builderAttr(block.bg || '#fff5eb')};border-radius:18px;border:1px solid ${builderAttr(block.border || '#fbd6bd')};border-collapse:separate;">
                <tr>
                    <td class="mp-stack" width="62%" style="padding:24px 22px;">
                        <div style="font-size:15px;line-height:1.18;font-weight:700;letter-spacing:-.45px;color:#1e3048;">${builderEsc(block.title)}</div>
                        <div style="font-size:12px;line-height:1.55;color:#4a6080;padding-top:8px;">${builderLines(block.text)}</div>
                    </td>
                    <td class="mp-stack mp-center-mobile" align="right" style="padding:24px 22px;">
                        <a href="${builderAttr(block.buttonUrl)}" style="display:inline-block;background:${builderAttr(block.accent || '#f47c20')};color:#ffffff;text-decoration:none;padding:15px 23px;border-radius:8px;font-size:10px;font-weight:700;letter-spacing:.01em;box-shadow:0 12px 30px rgba(244,124,32,.24);">${builderEsc(block.buttonText)} &#8594;</a>
                        <div style="font-size:10px;line-height:1.45;color:#4a6080;padding-top:8px;">${builderEsc(block.note)}</div>
                    </td>
                </tr>
            </table>
        </div>
    `;
}

function builderPremiumPlumberFooterHtml(block) {
    const accent = builderAttr(block.accent || '#f47c20');
    return `
        <div style="padding:${Number(block.padding) || 0}px 28px 20px 28px;background:${builderAttr(block.bg || '#f7f9fc')};border-top:1px solid #dde5f0;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td class="mp-stack" width="48%" valign="top" style="padding-right:18px;">
                        <div style="font-size:23px;font-weight:700;letter-spacing:0;color:#0b1d3a;line-height:.72;">${builderEsc(block.brand)}<span style="color:${accent};">.</span><br><span style="font-size:7px;font-weight:700;letter-spacing:.28em;color:#8fa3bf;text-transform:uppercase;">${builderEsc(block.tagline)}</span></div>
                        <div style="font-size:12px;line-height:1.55;color:${builderAttr(block.muted || '#4a6080')};padding-top:14px;">${builderLines(block.text)}</div>
                    </td>
                    <td class="mp-hide-mobile" width="4%" style="border-left:1px solid #dde5f0;font-size:1px;line-height:1px;">&nbsp;</td>
                    <td class="mp-stack mp-mobile-top" width="48%" valign="top" style="padding-left:24px;">
                        <div style="font-size:16px;line-height:1.35;font-weight:700;color:#1e3048;">${builderEsc(block.title)}</div>
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top:12px;"><tr><td style="font-size:14px;padding:4px 9px 4px 0;color:${accent};font-weight:700;">${builderPremiumIconSymbol('P')}</td><td style="font-size:12px;line-height:1.55;color:#0d2347;">${builderEsc(block.phone)}</td></tr></table>
                    </td>
                </tr>
            </table>
            <div style="font-size:10px;line-height:1.45;color:${builderAttr(block.muted || '#4a6080')};text-align:center;padding-top:24px;margin:0;">${builderLines(block.note)}</div>
        </div>
    `;
}

function builderBlockInnerHtml(block) {
    if (block.type === 'hero') {
        return `
            <div style="background:${builderAttr(block.bg)}; color:${builderAttr(block.textColor)}; text-align:${builderAttr(block.align)}; padding:${Number(block.padding) || 0}px 34px;">
                ${block.imageUrl ? `<img src="${builderAttr(block.imageUrl)}" alt="" style="display:block; max-width:100%; margin:0 auto 22px; border-radius:6px;">` : ''}
                <div style="font-size:12px; font-weight:700; letter-spacing:1px; color:${builderAttr(builderState.settings.accent)}; margin-bottom:10px;">${builderEsc(block.eyebrow)}</div>
                <div style="font-size:34px; line-height:1.15; font-weight:700; margin-bottom:14px;">${builderEsc(block.title)}</div>
                <div style="font-size:16px; line-height:1.65; margin-bottom:22px;">${builderLines(block.subtitle)}</div>
                ${block.buttonText ? `<a href="${builderAttr(block.buttonUrl)}" style="display:inline-block; background:${builderAttr(builderState.settings.accent)}; color:#ffffff; text-decoration:none; padding:13px 22px; border-radius:4px; font-weight:700;">${builderEsc(block.buttonText)}</a>` : ''}
                ${block.secondaryButtonText ? `<a href="${builderAttr(block.secondaryButtonUrl)}" style="display:inline-block; background:${builderAttr(block.secondaryButtonBg || '#ffffff')}; color:${builderAttr(block.secondaryButtonColor || '#111827')}; text-decoration:none; padding:12px 20px; border-radius:4px; font-weight:700; margin:4px;">${builderEsc(block.secondaryButtonText)}</a>` : ''}
            </div>
        `;
    }
    if (block.type === 'brandHeader') {
        return `<div style="display:flex; justify-content:space-between; gap:18px; align-items:center; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)}; padding:${Number(block.padding) || 0}px 28px;">
            <div style="font-size:17px; font-weight:700;">${builderEsc(block.brand)}</div>
            <div style="font-size:13px;">${builderEsc(block.label)}</div>
        </div>`;
    }
    if (block.type === 'text') {
        return `<div style="padding:${Number(block.padding) || 0}px 34px; text-align:${builderAttr(block.align)}; color:${builderAttr(block.color)}; font-size:${Number(block.fontSize) || 16}px; line-height:1.7;">${builderLines(block.content)}</div>`;
    }
    if (block.type === 'auditGrid') {
        const cards = [
            [block.item1Icon, block.item1Title, block.item1Text],
            [block.item2Icon, block.item2Title, block.item2Text],
            [block.item3Icon, block.item3Title, block.item3Text],
            [block.item4Icon, block.item4Title, block.item4Text],
        ];
        return `<div style="padding:${Number(block.padding) || 0}px 30px; background:${builderAttr(block.bg)};">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                ${cards.map((card) => `<div style="border:1px solid ${builderAttr(block.border)}; background:${builderAttr(block.cardBg)}; border-radius:10px; padding:18px;">
                    <div style="display:inline-block; background:${builderAttr(block.iconBg)}; color:${builderAttr(block.iconColor)}; border-radius:10px; padding:8px 10px; font-size:12px; font-weight:700; margin-bottom:14px;">${builderEsc(card[0])}</div>
                    <div style="font-weight:700; color:#0f172a; margin-bottom:8px;">${builderEsc(card[1])}</div>
                    <div style="font-size:13px; color:#475569; line-height:1.55;">${builderLines(card[2])}</div>
                </div>`).join('')}
            </div>
        </div>`;
    }
    if (block.type === 'checklistPanel') {
        const items = [block.item1, block.item2, block.item3, block.item4].filter(Boolean);
        return `<div style="padding:${Number(block.padding) || 0}px 30px; background:#ffffff;">
            <div style="background:${builderAttr(block.bg)}; color:${builderAttr(block.color)}; border-radius:12px; padding:24px;">
                <div style="font-size:21px; font-weight:700; margin-bottom:12px;">${builderEsc(block.title)}</div>
                <div style="font-size:14px; line-height:1.7; margin-bottom:16px;">${builderLines(block.intro)}</div>
                ${items.map((item) => `<div style="border-top:1px solid ${builderAttr(block.lineColor)}; padding:12px 0; font-size:14px;"><span style="color:${builderAttr(block.accent)}; font-weight:700;">&#10003;</span> ${builderEsc(item)}</div>`).join('')}
            </div>
        </div>`;
    }
    if (block.type === 'metricBars') {
        const metrics = [
            [block.metric1Label, block.metric1Before, block.metric1After, block.metric1Note],
            [block.metric2Label, block.metric2Before, block.metric2After, block.metric2Note],
            [block.metric3Label, block.metric3Before, block.metric3After, block.metric3Note],
        ];
        return `<div style="padding:${Number(block.padding) || 0}px 30px; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)};">
            <div style="font-size:22px; font-weight:700; margin-bottom:8px;">${builderEsc(block.title)}</div>
            <div style="font-size:13px; color:${builderAttr(block.muted)}; margin-bottom:24px;">${builderEsc(block.subtitle)}</div>
            <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:18px;">
                ${metrics.map((metric) => `<div style="text-align:center;">
                    <div style="font-size:11px; color:${builderAttr(block.muted)}; font-weight:700; letter-spacing:1px; text-transform:uppercase; margin-bottom:12px;">${builderEsc(metric[0])}</div>
                    <div style="height:116px; display:flex; align-items:flex-end; justify-content:center; gap:10px; margin-bottom:12px;">
                        <div style="width:34px; height:38px; background:#64748b; border-radius:5px 5px 0 0; color:#fff; font-size:11px; font-weight:700; padding-top:5px;">${builderEsc(metric[1])}</div>
                        <div style="width:40px; height:92px; background:${builderAttr(block.accent)}; border-radius:5px 5px 0 0; color:#fff; font-size:11px; font-weight:700; padding-top:5px;">${builderEsc(metric[2])}</div>
                    </div>
                    <div style="display:inline-block; border:1px solid rgba(251,146,60,.45); background:rgba(251,146,60,.14); color:${builderAttr(block.accent)}; border-radius:20px; padding:7px 10px; font-size:12px; font-weight:700;">${builderEsc(metric[3])}</div>
                </div>`).join('')}
            </div>
        </div>`;
    }
    if (block.type === 'browserAudit') {
        const issues = [block.issue1, block.issue2, block.issue3, block.issue4].filter(Boolean);
        return `<div style="padding:${Number(block.padding) || 0}px 30px; background:${builderAttr(block.bg)};">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;">
                <div style="font-size:12px; letter-spacing:2px; text-transform:uppercase; font-weight:700; color:#8aa0c8;">${builderEsc(block.label)}</div>
                <div style="background:#ef4444; color:#fff; border-radius:7px; padding:9px 12px; font-weight:700;">${builderEsc(block.score)}</div>
            </div>
            <div style="border:1px solid #dbe3ef; background:${builderAttr(block.chromeBg)}; border-radius:10px; padding:14px; margin-bottom:16px;">
                <div style="display:flex; gap:6px; margin-bottom:12px;"><span style="width:10px;height:10px;background:#ef4444;border-radius:50%;display:inline-block;"></span><span style="width:10px;height:10px;background:#f59e0b;border-radius:50%;display:inline-block;"></span><span style="width:10px;height:10px;background:#22c55e;border-radius:50%;display:inline-block;"></span><span style="background:#fff;border-radius:4px;padding:4px 12px;font-size:12px;color:#64748b;">${builderEsc(block.domain)}</span></div>
                <div style="height:86px; background:#d5dce5; border-radius:8px; margin-bottom:12px;"></div>
                <div style="height:8px; background:#d5dce5; border-radius:8px; margin-bottom:8px; width:96%;"></div>
                <div style="height:8px; background:#d5dce5; border-radius:8px; margin-bottom:8px; width:74%;"></div>
                <div style="height:8px; background:#d5dce5; border-radius:8px; width:56%;"></div>
            </div>
            ${issues.map((issue) => `<div style="background:${builderAttr(block.warningBg)}; color:${builderAttr(block.warningColor)}; border:1px solid #fecaca; border-radius:8px; padding:13px; font-size:14px; margin-bottom:10px;">&times; ${builderEsc(issue)}</div>`).join('')}
        </div>`;
    }
    if (block.type === 'ctaPanel') {
        return `<div style="padding:${Number(block.padding) || 0}px 30px; background:#ffffff; text-align:center;">
            <div style="background:${builderAttr(block.bg)}; border:1px solid ${builderAttr(block.border)}; color:${builderAttr(block.color)}; border-radius:12px; padding:28px;">
                <div style="font-size:24px; line-height:1.2; font-weight:700; margin-bottom:12px;">${builderEsc(block.title)}</div>
                <div style="font-size:14px; line-height:1.7; margin-bottom:20px;">${builderLines(block.text)}</div>
                <a href="${builderAttr(block.buttonUrl)}" style="display:inline-block; background:${builderAttr(block.buttonBg)}; color:#ffffff; text-decoration:none; padding:13px 22px; border-radius:7px; font-weight:700; margin:4px;">${builderEsc(block.buttonText)}</a>
                ${block.secondaryButtonText ? `<a href="${builderAttr(block.secondaryButtonUrl)}" style="display:inline-block; background:${builderAttr(block.secondaryButtonBg || '#ffffff')}; color:${builderAttr(block.secondaryButtonColor || '#0f172a')}; border:1px solid ${builderAttr(block.border)}; text-decoration:none; padding:12px 20px; border-radius:7px; font-weight:700; margin:4px;">${builderEsc(block.secondaryButtonText)}</a>` : ''}
            </div>
        </div>`;
    }
    if (block.type === 'premiumPlumberHeader') {
        return builderPremiumPlumberHeaderHtml(block);
    }
    if (block.type === 'premiumPlumberHeroScore') {
        return builderPremiumPlumberHeroScoreHtml(block);
    }
    if (block.type === 'premiumPlumberFindings') {
        return builderPremiumPlumberFindingsHtml(block);
    }
    if (block.type === 'premiumPlumberProcess') {
        return builderPremiumPlumberProcessHtml(block);
    }
    if (block.type === 'premiumPlumberIncludes') {
        return builderPremiumPlumberIncludesHtml(block);
    }
    if (block.type === 'premiumLeakHero') {
        return builderPremiumLeakHeroHtml(block);
    }
    if (block.type === 'premiumFunnel') {
        return builderPremiumFunnelHtml(block);
    }
    if (block.type === 'premiumImpactDice') {
        return builderPremiumImpactDiceHtml(block);
    }
    if (block.type === 'premiumCompare') {
        return builderPremiumCompareHtml(block);
    }
    if (block.type === 'premiumPlumberFinalCta') {
        return builderPremiumPlumberFinalCtaHtml(block);
    }
    if (block.type === 'premiumPlumberFooter') {
        return builderPremiumPlumberFooterHtml(block);
    }
    if (block.type === 'image') {
        const img = block.url ? `<img src="${builderAttr(block.url)}" alt="${builderAttr(block.alt)}" style="display:block; width:${Number(block.width) || 100}%; max-width:100%; height:auto; border:0;">` : '<div style="padding:46px 20px; background:#f1f5f9; color:#64748b; text-align:center;">Select this block and upload an image</div>';
        return `<div style="padding:${Number(block.padding) || 0}px 34px;">${block.link ? `<a href="${builderAttr(block.link)}">${img}</a>` : img}</div>`;
    }
    if (block.type === 'button') {
        return `<div style="padding:${Number(block.padding) || 0}px 34px; text-align:${builderAttr(block.align)};"><a href="${builderAttr(block.url)}" style="display:inline-block; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)}; text-decoration:none; padding:13px 24px; border-radius:4px; font-weight:700;">${builderEsc(block.text)}</a></div>`;
    }
    if (block.type === 'twoColumn') {
        return `
            <div style="padding:${Number(block.padding) || 0}px 34px; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)};">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px;">
                    <div><div style="font-size:18px; font-weight:700; margin-bottom:8px;">${builderEsc(block.leftTitle)}</div><div style="font-size:14px; line-height:1.65;">${builderLines(block.leftText)}</div></div>
                    <div><div style="font-size:18px; font-weight:700; margin-bottom:8px;">${builderEsc(block.rightTitle)}</div><div style="font-size:14px; line-height:1.65;">${builderLines(block.rightText)}</div></div>
                </div>
            </div>
        `;
    }
    if (block.type === 'product') {
        return `
            <div style="padding:${Number(block.padding) || 0}px 34px; background:${builderAttr(block.bg)};">
                <div style="display:grid; grid-template-columns:180px 1fr; gap:20px; align-items:center;">
                    <div>${block.imageUrl ? `<img src="${builderAttr(block.imageUrl)}" alt="" style="display:block; width:100%; border-radius:6px;">` : '<div style="height:150px; background:#e2e8f0; color:#64748b; display:flex; align-items:center; justify-content:center;">Image</div>'}</div>
                    <div>
                        <div style="font-size:22px; font-weight:700; color:#111827; margin-bottom:8px;">${builderEsc(block.title)}</div>
                        <div style="font-size:14px; color:#475569; line-height:1.6; margin-bottom:10px;">${builderLines(block.description)}</div>
                        <div style="font-size:18px; font-weight:700; color:#111827; margin-bottom:14px;">${builderEsc(block.price)}</div>
                        <a href="${builderAttr(block.buttonUrl)}" style="display:inline-block; background:${builderAttr(builderState.settings.accent)}; color:#ffffff; text-decoration:none; padding:11px 18px; border-radius:4px; font-weight:700;">${builderEsc(block.buttonText)}</a>
                    </div>
                </div>
            </div>
        `;
    }
    if (block.type === 'divider') {
        return `<div style="padding:${Number(block.padding) || 0}px 34px;"><div style="border-top:${Number(block.thickness) || 1}px solid ${builderAttr(block.color)};"></div></div>`;
    }
    if (block.type === 'spacer') {
        return `<div style="height:${Number(block.height) || 20}px; line-height:${Number(block.height) || 20}px;">&nbsp;</div>`;
    }
    if (block.type === 'social') {
        const links = [
            ['Facebook', block.facebook],
            ['Instagram', block.instagram],
            ['LinkedIn', block.linkedin],
            ['Website', block.website],
        ].filter((item) => item[1]);
        return `<div style="padding:${Number(block.padding) || 0}px 34px; text-align:${builderAttr(block.align)};">${links.map((item) => `<a href="${builderAttr(item[1])}" style="display:inline-block; margin:0 7px; color:${builderAttr(builderState.settings.accent)}; font-weight:700; text-decoration:none;">${builderEsc(item[0])}</a>`).join('')}</div>`;
    }
    if (block.type === 'signature') {
        return `<div style="padding:${Number(block.padding) || 0}px 30px; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)};">
            <div style="display:flex; gap:12px; align-items:center; border-top:1px solid #e2e8f0; padding-top:20px;">
                <div style="width:42px; height:42px; border-radius:50%; background:#0f172a; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700;">${builderEsc(block.avatarText)}</div>
                <div>
                    <div style="font-weight:700;">${builderEsc(block.name)}</div>
                    <div style="font-size:13px; color:${builderAttr(block.muted)};">${builderEsc(block.title)}</div>
                    <div style="font-size:13px; color:${builderAttr(builderState.settings.accent)};">${builderEsc(block.website)}</div>
                </div>
            </div>
            <div style="font-size:11px; line-height:1.6; color:${builderAttr(block.muted)}; text-align:center; margin-top:22px;">${builderLines(block.note)}</div>
        </div>`;
    }
    if (block.type === 'html') {
        return `<div style="padding:${Number(block.padding) || 0}px 34px;">${block.html || ''}</div>`;
    }
    return '';
}

function builderRenderCanvas() {
    const canvas = document.getElementById('builderCanvas');
    const wrap = document.getElementById('builderPreviewWrap');
    wrap.style.background = builderState.settings.bg;
    wrap.classList.toggle('builder-preview-mobile', builderPreviewMode === 'mobile');
    canvas.style.background = builderState.settings.contentBg;
    canvas.style.fontFamily = builderFontStack();
    canvas.style.maxWidth = `${Number(builderState.settings.width) || 640}px`;
    canvas.classList.toggle('builder-email-preview-raw', builderIsRawHtmlMode());
    canvas.classList.toggle('builder-email-preview-clean', builderCanvasMode === 'preview' && !builderIsRawHtmlMode());

    if (builderIsRawHtmlMode()) {
        canvas.innerHTML = '<iframe class="builder-raw-preview" title="Full HTML email preview" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>';
        const iframe = canvas.querySelector('iframe');
        iframe.srcdoc = builderState.rawHtml || '';
        builderSyncHtml();
        return;
    }

    if (builderCanvasMode === 'preview') {
        canvas.innerHTML = '<iframe class="builder-raw-preview builder-canvas-clean-frame" title="Generated email preview" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>';
        const iframe = canvas.querySelector('iframe');
        iframe.srcdoc = builderGenerateHtml();
        builderSyncHtml();
        return;
    }

    canvas.innerHTML = builderState.blocks.length
        ? builderState.blocks.map(builderPreviewBlock).join('')
        : '<div class="builder-empty-state">Choose a template or add a block to start building.</div>';
    builderSyncHtml();
}

function builderRender() {
    builderUpdateCanvasModeButtons();
    builderRenderCanvas();
    builderRenderInspector();
}

function builderInput(label, key, type = 'text') {
    const block = builderGetBlock();
    return `
        <div class="builder-field">
        <label class="builder-control-label">${label}</label>
        <input class="builder-control" type="${type}" value="${builderAttr(block[key] ?? '')}" onfocus="builderFocusedField={id:'${block.id}',key:'${key}'}" oninput="builderSet('${block.id}','${key}',this.value)">
        </div>
    `;
}

function builderTextarea(label, key, rows = 4) {
    const block = builderGetBlock();
    return `
        <div class="builder-field builder-field-wide">
        <label class="builder-control-label">${label}</label>
        <textarea class="builder-control" rows="${rows}" onfocus="builderFocusedField={id:'${block.id}',key:'${key}'}" oninput="builderSet('${block.id}','${key}',this.value)">${builderEsc(block[key] ?? '')}</textarea>
        </div>
    `;
}

function builderColor(label, key) {
    const block = builderGetBlock();
    return `
        <div class="builder-field">
        <label class="builder-control-label">${label}</label>
        <div class="builder-color-row">
            <input type="color" value="${builderAttr(block[key] || '#ffffff')}" oninput="builderSet('${block.id}','${key}',this.value)">
            <input class="builder-control" type="text" value="${builderAttr(block[key] || '')}" oninput="builderSet('${block.id}','${key}',this.value)">
        </div>
        </div>
    `;
}

function builderSelect(label, key, options) {
    const block = builderGetBlock();
    return `
        <div class="builder-field">
        <label class="builder-control-label">${label}</label>
        <select class="builder-control" onchange="builderSet('${block.id}','${key}',this.value)">
            ${options.map((opt) => `<option value="${builderAttr(opt)}" ${block[key] === opt ? 'selected' : ''}>${builderEsc(opt)}</option>`).join('')}
        </select>
        </div>
    `;
}

function builderNumber(label, key, min = 0, max = 999) {
    const block = builderGetBlock();
    return `
        <div class="builder-field">
        <label class="builder-control-label">${label}</label>
        <input class="builder-control" type="number" min="${min}" max="${max}" value="${builderAttr(block[key] ?? '')}" oninput="builderSet('${block.id}','${key}',this.value)">
        </div>
    `;
}

function builderImageControl(label, key) {
    const block = builderGetBlock();
    return `
        ${builderInput(label, key, 'text')}
        <div class="builder-field">
        <label class="builder-control-label">Upload</label>
        <input class="builder-control" type="file" accept="image/*" onchange="builderUploadImage('${block.id}','${key}',this)">
        </div>
    `;
}

function builderRenderInspector() {
    const block = builderGetBlock();
    const inspector = document.getElementById('builderInspector');
    if (builderIsRawHtmlMode()) {
        inspector.className = '';
        inspector.innerHTML = `
            <div style="font-weight:700; color:var(--text-primary); margin-bottom:10px;">Full HTML Email</div>
            <div class="form-hint" style="grid-column:1/-1; margin-bottom:8px;">Edit the complete email source. This mode preserves the document head, font imports, responsive CSS, and exact HTML structure.</div>
            <div class="builder-field builder-field-wide">
                <label class="builder-control-label">Complete HTML Source</label>
                <textarea class="builder-control" rows="18" oninput="builderSetRawHtml(this.value)">${builderEsc(builderState.rawHtml || '')}</textarea>
            </div>
            <div class="builder-field builder-field-wide">
                <button type="button" class="btn btn-outline btn-sm" onclick="builderPreviewHtml()">View Source</button>
            </div>
        `;
        return;
    }

    if (!block) {
        inspector.className = 'builder-empty-inspector';
        inspector.innerHTML = 'Select a block to edit its content and styling.';
        return;
    }

    inspector.className = '';
    let html = `<div style="font-weight:700; color:var(--text-primary); margin-bottom:10px;">${builderEsc(block.type)}</div>`;
    if (block.type === 'hero') {
        html += builderInput('Eyebrow', 'eyebrow') + builderInput('Title', 'title') + builderTextarea('Subtitle', 'subtitle') + builderInput('Button Text', 'buttonText') + builderInput('Button URL', 'buttonUrl') + builderInput('Secondary Button Text', 'secondaryButtonText') + builderInput('Secondary Button URL', 'secondaryButtonUrl') + builderColor('Secondary Button Background', 'secondaryButtonBg') + builderColor('Secondary Button Text', 'secondaryButtonColor') + builderImageControl('Image URL', 'imageUrl') + builderSelect('Alignment', 'align', ['left', 'center', 'right']) + builderColor('Background', 'bg') + builderColor('Text Color', 'textColor') + builderNumber('Padding', 'padding', 12, 90);
    } else if (block.type === 'brandHeader') {
        html += builderInput('Brand', 'brand') + builderInput('Right Label', 'label') + builderColor('Background', 'bg') + builderColor('Text Color', 'color') + builderNumber('Padding', 'padding', 8, 50);
    } else if (block.type === 'text') {
        html += builderTextarea('Content', 'content', 7) + builderNumber('Font Size', 'fontSize', 10, 28) + builderColor('Text Color', 'color') + builderSelect('Alignment', 'align', ['left', 'center', 'right']) + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'auditGrid') {
        html += builderInput('Card 1 Icon', 'item1Icon') + builderInput('Card 1 Title', 'item1Title') + builderTextarea('Card 1 Text', 'item1Text', 3) + builderInput('Card 2 Icon', 'item2Icon') + builderInput('Card 2 Title', 'item2Title') + builderTextarea('Card 2 Text', 'item2Text', 3) + builderInput('Card 3 Icon', 'item3Icon') + builderInput('Card 3 Title', 'item3Title') + builderTextarea('Card 3 Text', 'item3Text', 3) + builderInput('Card 4 Icon', 'item4Icon') + builderInput('Card 4 Title', 'item4Title') + builderTextarea('Card 4 Text', 'item4Text', 3) + builderColor('Background', 'bg') + builderColor('Card Background', 'cardBg') + builderColor('Border', 'border') + builderColor('Icon Background', 'iconBg') + builderColor('Icon Text', 'iconColor') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'checklistPanel') {
        html += builderInput('Title', 'title') + builderTextarea('Intro', 'intro', 4) + builderInput('Item 1', 'item1') + builderInput('Item 2', 'item2') + builderInput('Item 3', 'item3') + builderInput('Item 4', 'item4') + builderColor('Background', 'bg') + builderColor('Text Color', 'color') + builderColor('Line Color', 'lineColor') + builderColor('Accent', 'accent') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'metricBars') {
        html += builderInput('Title', 'title') + builderTextarea('Subtitle', 'subtitle', 3) + builderInput('Metric 1 Label', 'metric1Label') + builderInput('Metric 1 Before', 'metric1Before') + builderInput('Metric 1 After', 'metric1After') + builderInput('Metric 1 Note', 'metric1Note') + builderInput('Metric 2 Label', 'metric2Label') + builderInput('Metric 2 Before', 'metric2Before') + builderInput('Metric 2 After', 'metric2After') + builderInput('Metric 2 Note', 'metric2Note') + builderInput('Metric 3 Label', 'metric3Label') + builderInput('Metric 3 Before', 'metric3Before') + builderInput('Metric 3 After', 'metric3After') + builderInput('Metric 3 Note', 'metric3Note') + builderColor('Background', 'bg') + builderColor('Text Color', 'color') + builderColor('Muted Text', 'muted') + builderColor('Accent', 'accent') + builderNumber('Padding', 'padding', 8, 80);
    } else if (block.type === 'browserAudit') {
        html += builderInput('Label', 'label') + builderInput('Domain', 'domain') + builderInput('Score Badge', 'score') + builderInput('Issue 1', 'issue1') + builderInput('Issue 2', 'issue2') + builderInput('Issue 3', 'issue3') + builderInput('Issue 4', 'issue4') + builderColor('Background', 'bg') + builderColor('Warning Background', 'warningBg') + builderColor('Warning Text', 'warningColor') + builderColor('Browser Background', 'chromeBg') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'ctaPanel') {
        html += builderInput('Title', 'title') + builderTextarea('Text', 'text', 4) + builderInput('Primary Button Text', 'buttonText') + builderInput('Primary Button URL', 'buttonUrl') + builderInput('Secondary Button Text', 'secondaryButtonText') + builderInput('Secondary Button URL', 'secondaryButtonUrl') + builderColor('Background', 'bg') + builderColor('Border', 'border') + builderColor('Primary Button Background', 'buttonBg') + builderColor('Secondary Button Background', 'secondaryButtonBg') + builderColor('Secondary Button Text', 'secondaryButtonColor') + builderColor('Text Color', 'color') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'premiumPlumberHeader') {
        html += builderInput('Brand', 'brand') + builderInput('Tagline', 'tagline') + builderInput('Right Text', 'rightText') + builderColor('Background', 'bg') + builderColor('Text Color', 'color') + builderColor('Dot / Accent', 'dotColor') + builderColor('Muted Text', 'muted') + builderNumber('Padding', 'padding', 8, 60);
    } else if (block.type === 'premiumPlumberHeroScore') {
        html += builderInput('Pill', 'pill') + builderInput('Title', 'title') + builderInput('Orange Title Text', 'titleAccent') + builderTextarea('Intro Text', 'text', 4) + builderInput('Stat 1 Title', 'stat1Title') + builderInput('Stat 1 Text', 'stat1Text') + builderInput('Stat 2 Title', 'stat2Title') + builderInput('Stat 2 Text', 'stat2Text') + builderInput('Stat 3 Title', 'stat3Title') + builderInput('Stat 3 Text', 'stat3Text') + builderInput('Note', 'note') + builderInput('Card Pill', 'cardPill') + builderInput('Card Meta', 'cardMeta') + builderTextarea('Card Title', 'cardTitle', 3) + builderTextarea('Card Text', 'cardText', 3) + builderInput('Score', 'score') + builderInput('Score Label', 'scoreLabel') + builderInput('Check 1 Title', 'check1Title') + builderInput('Check 1 Text', 'check1Text') + builderInput('Check 2 Title', 'check2Title') + builderInput('Check 2 Text', 'check2Text') + builderInput('Check 3 Title', 'check3Title') + builderInput('Check 3 Text', 'check3Text') + builderInput('Bottom Label 1', 'bottom1') + builderInput('Bottom Label 2', 'bottom2') + builderInput('Bottom Label 3', 'bottom3') + builderColor('Background', 'bg') + builderColor('Second Background', 'bg2') + builderColor('Accent', 'accent') + builderColor('Muted Text', 'muted') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'premiumPlumberFindings') {
        html += builderInput('Eyebrow', 'eyebrow') + builderTextarea('Title', 'title', 3) + builderTextarea('Text', 'text', 3) + builderTextarea('Item 1', 'item1', 3) + builderTextarea('Item 2', 'item2', 3) + builderTextarea('Item 3', 'item3', 3) + builderColor('Background', 'bg') + builderColor('Second Background', 'bg2') + builderColor('Accent', 'accent') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'premiumPlumberProcess') {
        html += builderInput('Eyebrow', 'eyebrow') + builderInput('Title', 'title') + builderTextarea('Text', 'text', 4) + builderInput('Item 1 Title', 'item1Title') + builderTextarea('Item 1 Text', 'item1Text', 3) + builderInput('Item 2 Title', 'item2Title') + builderTextarea('Item 2 Text', 'item2Text', 3) + builderInput('Item 3 Title', 'item3Title') + builderTextarea('Item 3 Text', 'item3Text', 3) + builderInput('Item 4 Title', 'item4Title') + builderTextarea('Item 4 Text', 'item4Text', 3) + builderColor('Accent', 'accent') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'premiumPlumberIncludes') {
        html += builderInput('Title', 'title') + builderTextarea('Item 1', 'item1', 2) + builderTextarea('Item 2', 'item2', 2) + builderTextarea('Item 3', 'item3', 2) + builderTextarea('Item 4', 'item4', 2) + builderColor('Accent', 'accent') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'premiumLeakHero') {
        html += builderInput('Title Line 1', 'titleLine1') + builderInput('Title Line 2', 'titleLine2') + builderInput('Orange Title Text', 'titleAccent') + builderTextarea('Intro Text', 'text', 4) + builderInput('Button Text', 'buttonText') + builderInput('Button URL', 'buttonUrl') + builderInput('Badge Top Text', 'visualTop') + builderInput('Badge Line 1', 'visualLine1') + builderInput('Badge Line 2', 'visualLine2') + builderColor('Background', 'bg') + builderColor('Text Color', 'textColor') + builderColor('Muted Text', 'muted') + builderColor('Accent', 'accent') + builderColor('Button Background', 'buttonBg') + builderNumber('Padding', 'padding', 8, 60);
    } else if (block.type === 'premiumFunnel') {
        html += builderInput('Title Line', 'titleLine1') + builderInput('Orange Title Text', 'titleAccent') + builderTextarea('Body Text', 'text', 4) + builderInput('Funnel Label 1', 'labelOne') + builderInput('Funnel Label 2', 'labelTwo') + builderInput('Funnel Label 3', 'labelThree') + builderInput('Step 1 Title', 'step1Title') + builderInput('Step 1 Text', 'step1Text') + builderInput('Step 2 Title', 'step2Title') + builderInput('Step 2 Text', 'step2Text') + builderInput('Step 3 Title', 'step3Title') + builderInput('Step 3 Text', 'step3Text') + builderInput('Step 4 Title', 'step4Title') + builderInput('Step 4 Text', 'step4Text') + builderColor('Background', 'bg') + builderColor('Accent', 'accent') + builderColor('Funnel Blue', 'blue') + builderNumber('Padding', 'padding', 8, 60);
    } else if (block.type === 'premiumImpactDice') {
        html += builderInput('First Emphasis', 'smallWord') + builderInput('First Tail', 'smallTail') + builderInput('Orange Emphasis', 'bigWord') + builderInput('Second Tail', 'bigTail') + builderTextarea('Text', 'text', 4) + builderInput('Button Text', 'buttonText') + builderInput('Button URL', 'buttonUrl') + builderColor('Accent', 'accent') + builderColor('Button Background', 'buttonBg') + builderNumber('Padding', 'padding', 8, 60);
    } else if (block.type === 'premiumCompare') {
        html += builderInput('Title', 'title') + builderInput('Left Label', 'leftLabel') + builderInput('Left Percent', 'leftPercent') + builderInput('Left Main Text', 'leftTitle') + builderInput('Left Subtext', 'leftText') + builderInput('Right Label', 'rightLabel') + builderInput('Right Percent', 'rightPercent') + builderInput('Right Main Text', 'rightTitle') + builderInput('Right Subtext', 'rightText') + builderColor('Background', 'bg') + builderColor('Accent', 'accent') + builderNumber('Padding', 'padding', 8, 60);
    } else if (block.type === 'premiumPlumberFinalCta') {
        html += builderInput('Title', 'title') + builderTextarea('Text', 'text', 3) + builderInput('Button Text', 'buttonText') + builderInput('Button URL', 'buttonUrl') + builderInput('Note', 'note') + builderColor('Background', 'bg') + builderColor('Border', 'border') + builderColor('Accent', 'accent') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'premiumPlumberFooter') {
        html += builderInput('Brand', 'brand') + builderInput('Tagline', 'tagline') + builderTextarea('Left Text', 'text', 3) + builderInput('Right Title', 'title') + builderInput('Phone', 'phone') + builderTextarea('Footer Note', 'note', 4) + builderColor('Background', 'bg') + builderColor('Accent', 'accent') + builderColor('Muted Text', 'muted') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'image') {
        html += builderImageControl('Image URL', 'url') + builderInput('Alt Text', 'alt') + builderInput('Link URL', 'link') + builderNumber('Width %', 'width', 20, 100) + builderNumber('Padding', 'padding', 0, 70);
    } else if (block.type === 'button') {
        html += builderInput('Text', 'text') + builderInput('URL', 'url') + builderSelect('Alignment', 'align', ['left', 'center', 'right']) + builderColor('Background', 'bg') + builderColor('Text Color', 'color') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'twoColumn') {
        html += builderInput('Left Title', 'leftTitle') + builderTextarea('Left Text', 'leftText') + builderInput('Right Title', 'rightTitle') + builderTextarea('Right Text', 'rightText') + builderColor('Background', 'bg') + builderColor('Text Color', 'color') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'product') {
        html += builderImageControl('Image URL', 'imageUrl') + builderInput('Title', 'title') + builderTextarea('Description', 'description') + builderInput('Price', 'price') + builderInput('Button Text', 'buttonText') + builderInput('Button URL', 'buttonUrl') + builderColor('Background', 'bg') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'divider') {
        html += builderColor('Line Color', 'color') + builderNumber('Thickness', 'thickness', 1, 8) + builderNumber('Padding', 'padding', 4, 60);
    } else if (block.type === 'spacer') {
        html += builderNumber('Height', 'height', 4, 120);
    } else if (block.type === 'social') {
        html += builderInput('Facebook URL', 'facebook') + builderInput('Instagram URL', 'instagram') + builderInput('LinkedIn URL', 'linkedin') + builderInput('Website URL', 'website') + builderSelect('Alignment', 'align', ['left', 'center', 'right']) + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'signature') {
        html += builderInput('Name', 'name') + builderInput('Title', 'title') + builderInput('Website', 'website') + builderInput('Avatar Text', 'avatarText') + builderTextarea('Footer Note', 'note', 4) + builderColor('Background', 'bg') + builderColor('Text Color', 'color') + builderColor('Muted Text', 'muted') + builderNumber('Padding', 'padding', 8, 70);
    } else if (block.type === 'html') {
        html += builderTextarea('Custom HTML', 'html', 10) + builderNumber('Padding', 'padding', 0, 70);
    }
    inspector.innerHTML = html;
}

async function builderUploadImage(blockId, key, input) {
    const file = input.files && input.files[0];
    if (!file) return;
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    const formData = new FormData();
    formData.append('file', file);
    const campaignId = document.getElementById('campaignId').value;
    if (campaignId) formData.append('campaign_id', campaignId);

    try {
        Toast.info('Uploading image...', 8000);
        const result = await apiCall(basePath + '/api/upload-image.php', formData);
        builderSet(blockId, key, result.location);
        builderRenderInspector();
        Toast.success('Image uploaded.');
    } catch (err) {
        Toast.error(err.message || 'Image upload failed');
    } finally {
        input.value = '';
    }
}

function builderInsertToken(token) {
    if (builderFocusedField && builderGetBlock(builderFocusedField.id)) {
        const block = builderGetBlock(builderFocusedField.id);
        block[builderFocusedField.key] = String(block[builderFocusedField.key] || '') + token;
        builderRender();
        return;
    }
    if (builderSelectedId) {
        const block = builderGetBlock(builderSelectedId);
        const key = ['content', 'subtitle', 'title', 'text', 'html'].find((candidate) => candidate in block);
        if (key) {
            block[key] = String(block[key] || '') + token;
            builderRender();
            return;
        }
    }
    document.getElementById('campaignSubject').value += token;
}

function builderEmailRow(inner, padding = 0) {
    return `<tr><td style="padding:0;">${inner}</td></tr>`;
}

function builderEmailBlock(block) {
    if (block.type === 'hero') {
        return `<tr><td align="${builderAttr(block.align)}" style="background:${builderAttr(block.bg)}; color:${builderAttr(block.textColor)}; padding:${Number(block.padding) || 0}px 34px; text-align:${builderAttr(block.align)};">
            ${block.imageUrl ? `<img src="${builderAttr(block.imageUrl)}" alt="" width="572" style="display:block; width:100%; max-width:572px; height:auto; border:0; margin:0 auto 22px;">` : ''}
            <div style="font-size:12px; font-weight:bold; letter-spacing:1px; color:${builderAttr(builderState.settings.accent)}; margin-bottom:10px;">${builderEsc(block.eyebrow)}</div>
            <div style="font-size:34px; line-height:1.15; font-weight:bold; margin-bottom:14px;">${builderEsc(block.title)}</div>
            <div style="font-size:16px; line-height:1.65; margin-bottom:22px;">${builderLines(block.subtitle)}</div>
            ${block.buttonText ? `<a href="${builderAttr(block.buttonUrl)}" style="display:inline-block; background:${builderAttr(builderState.settings.accent)}; color:#ffffff; text-decoration:none; padding:13px 22px; border-radius:4px; font-weight:bold;">${builderEsc(block.buttonText)}</a>` : ''}
            ${block.secondaryButtonText ? `<a href="${builderAttr(block.secondaryButtonUrl)}" style="display:inline-block; background:${builderAttr(block.secondaryButtonBg || '#ffffff')}; color:${builderAttr(block.secondaryButtonColor || '#111827')}; text-decoration:none; padding:12px 20px; border-radius:4px; font-weight:bold; margin:4px;">${builderEsc(block.secondaryButtonText)}</a>` : ''}
        </td></tr>`;
    }
    if (block.type === 'brandHeader') {
        return `<tr><td style="background:${builderAttr(block.bg)}; color:${builderAttr(block.color)}; padding:${Number(block.padding) || 0}px 28px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                <tr>
                    <td align="left" style="font-size:17px; font-weight:bold; color:${builderAttr(block.color)};">${builderEsc(block.brand)}</td>
                    <td align="right" style="font-size:13px; color:${builderAttr(block.color)};">${builderEsc(block.label)}</td>
                </tr>
            </table>
        </td></tr>`;
    }
    if (block.type === 'text') {
        return `<tr><td align="${builderAttr(block.align)}" style="padding:${Number(block.padding) || 0}px 34px; text-align:${builderAttr(block.align)}; color:${builderAttr(block.color)}; font-size:${Number(block.fontSize) || 16}px; line-height:1.7;">${builderLines(block.content)}</td></tr>`;
    }
    if (block.type === 'auditGrid') {
        const card = (icon, title, text) => `<td width="50%" valign="top" style="padding:7px;">
            <div style="border:1px solid ${builderAttr(block.border)}; background:${builderAttr(block.cardBg)}; border-radius:10px; padding:18px;">
                <div style="display:inline-block; background:${builderAttr(block.iconBg)}; color:${builderAttr(block.iconColor)}; border-radius:10px; padding:8px 10px; font-size:12px; font-weight:bold; margin-bottom:14px;">${builderEsc(icon)}</div>
                <div style="font-weight:bold; color:#0f172a; margin-bottom:8px;">${builderEsc(title)}</div>
                <div style="font-size:13px; color:#475569; line-height:1.55;">${builderLines(text)}</div>
            </div>
        </td>`;
        return `<tr><td style="padding:${Number(block.padding) || 0}px 27px; background:${builderAttr(block.bg)};">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                <tr>${card(block.item1Icon, block.item1Title, block.item1Text)}${card(block.item2Icon, block.item2Title, block.item2Text)}</tr>
                <tr>${card(block.item3Icon, block.item3Title, block.item3Text)}${card(block.item4Icon, block.item4Title, block.item4Text)}</tr>
            </table>
        </td></tr>`;
    }
    if (block.type === 'checklistPanel') {
        const items = [block.item1, block.item2, block.item3, block.item4].filter(Boolean).map((item) => `<tr><td style="border-top:1px solid ${builderAttr(block.lineColor)}; padding:12px 0; font-size:14px; color:${builderAttr(block.color)};"><span style="color:${builderAttr(block.accent)}; font-weight:bold;">&#10003;</span> ${builderEsc(item)}</td></tr>`).join('');
        return `<tr><td style="padding:${Number(block.padding) || 0}px 30px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:${builderAttr(block.bg)}; color:${builderAttr(block.color)}; border-radius:12px; border-collapse:separate;">
                <tr><td style="padding:24px 24px 0 24px; font-size:21px; line-height:1.3; font-weight:bold; color:${builderAttr(block.color)};">${builderEsc(block.title)}</td></tr>
                <tr><td style="padding:12px 24px 16px 24px; font-size:14px; line-height:1.7; color:${builderAttr(block.color)};">${builderLines(block.intro)}</td></tr>
                <tr><td style="padding:0 24px 18px 24px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">${items}</table></td></tr>
            </table>
        </td></tr>`;
    }
    if (block.type === 'metricBars') {
        const metric = (label, before, after, note) => `<td width="33.33%" align="center" valign="top" style="padding:0 8px;">
            <div style="font-size:11px; color:${builderAttr(block.muted)}; font-weight:bold; letter-spacing:1px; text-transform:uppercase; margin-bottom:12px;">${builderEsc(label)}</div>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="height:116px;"><tr>
                <td valign="bottom" style="padding:0 5px;"><div style="width:34px; height:38px; background:#64748b; border-radius:5px 5px 0 0; color:#ffffff; font-size:11px; font-weight:bold; padding-top:5px;">${builderEsc(before)}</div></td>
                <td valign="bottom" style="padding:0 5px;"><div style="width:40px; height:92px; background:${builderAttr(block.accent)}; border-radius:5px 5px 0 0; color:#ffffff; font-size:11px; font-weight:bold; padding-top:5px;">${builderEsc(after)}</div></td>
            </tr></table>
            <div style="display:inline-block; border:1px solid rgba(251,146,60,.45); background:rgba(251,146,60,.14); color:${builderAttr(block.accent)}; border-radius:20px; padding:7px 10px; font-size:12px; font-weight:bold;">${builderEsc(note)}</div>
        </td>`;
        return `<tr><td style="padding:${Number(block.padding) || 0}px 30px; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)};">
            <div style="font-size:22px; font-weight:bold; margin-bottom:8px; color:${builderAttr(block.color)};">${builderEsc(block.title)}</div>
            <div style="font-size:13px; color:${builderAttr(block.muted)}; margin-bottom:24px;">${builderEsc(block.subtitle)}</div>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;"><tr>
                ${metric(block.metric1Label, block.metric1Before, block.metric1After, block.metric1Note)}
                ${metric(block.metric2Label, block.metric2Before, block.metric2After, block.metric2Note)}
                ${metric(block.metric3Label, block.metric3Before, block.metric3After, block.metric3Note)}
            </tr></table>
        </td></tr>`;
    }
    if (block.type === 'browserAudit') {
        const issues = [block.issue1, block.issue2, block.issue3, block.issue4].filter(Boolean).map((issue) => `<div style="background:${builderAttr(block.warningBg)}; color:${builderAttr(block.warningColor)}; border:1px solid #fecaca; border-radius:8px; padding:13px; font-size:14px; margin-bottom:10px;">&times; ${builderEsc(issue)}</div>`).join('');
        return `<tr><td style="padding:${Number(block.padding) || 0}px 30px; background:${builderAttr(block.bg)};">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse; margin-bottom:12px;"><tr>
                <td align="left" style="font-size:12px; letter-spacing:2px; text-transform:uppercase; font-weight:bold; color:#8aa0c8;">${builderEsc(block.label)}</td>
                <td align="right"><span style="background:#ef4444; color:#ffffff; border-radius:7px; padding:9px 12px; font-weight:bold;">${builderEsc(block.score)}</span></td>
            </tr></table>
            <div style="border:1px solid #dbe3ef; background:${builderAttr(block.chromeBg)}; border-radius:10px; padding:14px; margin-bottom:16px;">
                <div style="font-size:12px; color:#64748b; background:#ffffff; display:inline-block; padding:5px 14px; border-radius:4px; margin-bottom:12px;">${builderEsc(block.domain)}</div>
                <div style="height:86px; background:#d5dce5; border-radius:8px; margin-bottom:12px;"></div>
                <div style="height:8px; background:#d5dce5; border-radius:8px; margin-bottom:8px; width:96%;"></div>
                <div style="height:8px; background:#d5dce5; border-radius:8px; margin-bottom:8px; width:74%;"></div>
                <div style="height:8px; background:#d5dce5; border-radius:8px; width:56%;"></div>
            </div>
            ${issues}
        </td></tr>`;
    }
    if (block.type === 'ctaPanel') {
        return `<tr><td align="center" style="padding:${Number(block.padding) || 0}px 30px; background:#ffffff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:${builderAttr(block.bg)}; border:1px solid ${builderAttr(block.border)}; border-radius:12px; border-collapse:separate;">
                <tr><td align="center" style="padding:28px; color:${builderAttr(block.color)};">
                    <div style="font-size:24px; line-height:1.2; font-weight:bold; margin-bottom:12px;">${builderEsc(block.title)}</div>
                    <div style="font-size:14px; line-height:1.7; margin-bottom:20px;">${builderLines(block.text)}</div>
                    <a href="${builderAttr(block.buttonUrl)}" style="display:inline-block; background:${builderAttr(block.buttonBg)}; color:#ffffff; text-decoration:none; padding:13px 22px; border-radius:7px; font-weight:bold; margin:4px;">${builderEsc(block.buttonText)}</a>
                    ${block.secondaryButtonText ? `<a href="${builderAttr(block.secondaryButtonUrl)}" style="display:inline-block; background:${builderAttr(block.secondaryButtonBg || '#ffffff')}; color:${builderAttr(block.secondaryButtonColor || '#0f172a')}; border:1px solid ${builderAttr(block.border)}; text-decoration:none; padding:12px 20px; border-radius:7px; font-weight:bold; margin:4px;">${builderEsc(block.secondaryButtonText)}</a>` : ''}
                </td></tr>
            </table>
        </td></tr>`;
    }
    if (block.type === 'premiumPlumberHeader') {
        return `<tr><td style="padding:0;">${builderPremiumPlumberHeaderHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumPlumberHeroScore') {
        return `<tr><td style="padding:0;">${builderPremiumPlumberHeroScoreHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumPlumberFindings') {
        return `<tr><td style="padding:0;">${builderPremiumPlumberFindingsHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumPlumberProcess') {
        return `<tr><td style="padding:0;">${builderPremiumPlumberProcessHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumPlumberIncludes') {
        return `<tr><td style="padding:0;">${builderPremiumPlumberIncludesHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumLeakHero') {
        return `<tr><td style="padding:0;">${builderPremiumLeakHeroHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumFunnel') {
        return `<tr><td style="padding:0;">${builderPremiumFunnelHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumImpactDice') {
        return `<tr><td style="padding:0;">${builderPremiumImpactDiceHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumCompare') {
        return `<tr><td style="padding:0;">${builderPremiumCompareHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumPlumberFinalCta') {
        return `<tr><td style="padding:0;">${builderPremiumPlumberFinalCtaHtml(block)}</td></tr>`;
    }
    if (block.type === 'premiumPlumberFooter') {
        return `<tr><td style="padding:0;">${builderPremiumPlumberFooterHtml(block)}</td></tr>`;
    }
    if (block.type === 'image') {
        const img = block.url ? `<img src="${builderAttr(block.url)}" alt="${builderAttr(block.alt)}" width="${Math.round(572 * ((Number(block.width) || 100) / 100))}" style="display:block; width:${Number(block.width) || 100}%; max-width:100%; height:auto; border:0;">` : '';
        return `<tr><td align="center" style="padding:${Number(block.padding) || 0}px 34px;">${block.link ? `<a href="${builderAttr(block.link)}">${img}</a>` : img}</td></tr>`;
    }
    if (block.type === 'button') {
        return `<tr><td align="${builderAttr(block.align)}" style="padding:${Number(block.padding) || 0}px 34px; text-align:${builderAttr(block.align)};"><a href="${builderAttr(block.url)}" style="display:inline-block; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)}; text-decoration:none; padding:13px 24px; border-radius:4px; font-weight:bold;">${builderEsc(block.text)}</a></td></tr>`;
    }
    if (block.type === 'twoColumn') {
        return `<tr><td style="padding:${Number(block.padding) || 0}px 34px; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)};">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                <tr>
                    <td width="50%" valign="top" style="padding-right:10px;">
                        <div style="font-size:18px; font-weight:bold; margin-bottom:8px;">${builderEsc(block.leftTitle)}</div>
                        <div style="font-size:14px; line-height:1.65;">${builderLines(block.leftText)}</div>
                    </td>
                    <td width="50%" valign="top" style="padding-left:10px;">
                        <div style="font-size:18px; font-weight:bold; margin-bottom:8px;">${builderEsc(block.rightTitle)}</div>
                        <div style="font-size:14px; line-height:1.65;">${builderLines(block.rightText)}</div>
                    </td>
                </tr>
            </table>
        </td></tr>`;
    }
    if (block.type === 'product') {
        return `<tr><td style="padding:${Number(block.padding) || 0}px 34px; background:${builderAttr(block.bg)};">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                <tr>
                    <td width="190" valign="middle" style="padding-right:20px;">${block.imageUrl ? `<img src="${builderAttr(block.imageUrl)}" alt="" width="180" style="display:block; width:180px; max-width:100%; height:auto; border:0;">` : ''}</td>
                    <td valign="middle">
                        <div style="font-size:22px; font-weight:bold; color:#111827; margin-bottom:8px;">${builderEsc(block.title)}</div>
                        <div style="font-size:14px; color:#475569; line-height:1.6; margin-bottom:10px;">${builderLines(block.description)}</div>
                        <div style="font-size:18px; font-weight:bold; color:#111827; margin-bottom:14px;">${builderEsc(block.price)}</div>
                        <a href="${builderAttr(block.buttonUrl)}" style="display:inline-block; background:${builderAttr(builderState.settings.accent)}; color:#ffffff; text-decoration:none; padding:11px 18px; border-radius:4px; font-weight:bold;">${builderEsc(block.buttonText)}</a>
                    </td>
                </tr>
            </table>
        </td></tr>`;
    }
    if (block.type === 'divider') {
        return `<tr><td style="padding:${Number(block.padding) || 0}px 34px;"><div style="border-top:${Number(block.thickness) || 1}px solid ${builderAttr(block.color)}; line-height:1px; font-size:1px;">&nbsp;</div></td></tr>`;
    }
    if (block.type === 'spacer') {
        return `<tr><td style="height:${Number(block.height) || 20}px; line-height:${Number(block.height) || 20}px; font-size:1px;">&nbsp;</td></tr>`;
    }
    if (block.type === 'social') {
        const links = [
            ['Facebook', block.facebook],
            ['Instagram', block.instagram],
            ['LinkedIn', block.linkedin],
            ['Website', block.website],
        ].filter((item) => item[1]).map((item) => `<a href="${builderAttr(item[1])}" style="display:inline-block; margin:0 7px; color:${builderAttr(builderState.settings.accent)}; font-weight:bold; text-decoration:none;">${builderEsc(item[0])}</a>`).join('');
        return `<tr><td align="${builderAttr(block.align)}" style="padding:${Number(block.padding) || 0}px 34px; text-align:${builderAttr(block.align)};">${links}</td></tr>`;
    }
    if (block.type === 'signature') {
        return `<tr><td style="padding:${Number(block.padding) || 0}px 30px; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)};">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-top:1px solid #e2e8f0; padding-top:20px; border-collapse:collapse;">
                <tr>
                    <td width="52" valign="top"><div style="width:42px; height:42px; border-radius:50%; background:#0f172a; color:#ffffff; text-align:center; line-height:42px; font-weight:bold;">${builderEsc(block.avatarText)}</div></td>
                    <td valign="top">
                        <div style="font-weight:bold; color:${builderAttr(block.color)};">${builderEsc(block.name)}</div>
                        <div style="font-size:13px; color:${builderAttr(block.muted)};">${builderEsc(block.title)}</div>
                        <div style="font-size:13px; color:${builderAttr(builderState.settings.accent)};">${builderEsc(block.website)}</div>
                    </td>
                </tr>
            </table>
            <div style="font-size:11px; line-height:1.6; color:${builderAttr(block.muted)}; text-align:center; margin-top:22px;">${builderLines(block.note)}</div>
        </td></tr>`;
    }
    if (block.type === 'html') {
        return `<tr><td style="padding:${Number(block.padding) || 0}px 34px;">${block.html || ''}</td></tr>`;
    }
    return '';
}

function builderGenerateHtml() {
    if (builderIsRawHtmlMode()) {
        return String(builderState.rawHtml || '');
    }

    const rows = builderState.blocks.map(builderEmailBlock).join('');
    const emailWidth = Number(builderState.settings.width) || 640;
    return `<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">${builderFontImport()}${builderResponsiveCss()}</head>
<body style="margin:0; padding:0; background:${builderAttr(builderState.settings.bg)}; font-family:${builderAttr(builderFontStack())};">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background:${builderAttr(builderState.settings.bg)}; border-collapse:collapse;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" class="mp-container" width="${emailWidth}" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:${emailWidth}px; background:${builderAttr(builderState.settings.contentBg)}; border-collapse:collapse; font-family:${builderAttr(builderFontStack())};">
${rows}
</table>
</td>
</tr>
</table>
</body>
</html>`;
}

function builderEncodeState() {
    return btoa(unescape(encodeURIComponent(JSON.stringify(builderState))));
}

function builderSyncHtml() {
    const hidden = document.getElementById('emailBody');
    if (!hidden) return '';
    if (builderIsRawHtmlMode()) {
        hidden.value = builderGenerateHtml();
        return hidden.value;
    }

    const html = `<!--MAILPILOT_BUILDER ${builderEncodeState()}-->\n${builderGenerateHtml()}`;
    hidden.value = html;
    return html;
}

function builderPreviewHtml() {
    const html = builderSyncHtml();
    const modal = document.createElement('div');
    modal.className = 'builder-source-modal';
    modal.innerHTML = `
        <div>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;">
                <h3 style="margin:0;">Generated Email HTML</h3>
                <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('.builder-source-modal').remove()">Close</button>
            </div>
            <textarea class="builder-control" readonly>${builderEsc(html)}</textarea>
        </div>
    `;
    document.body.appendChild(modal);
}

function builderPreviewEmail() {
    const html = builderGenerateHtml();
    const modal = document.createElement('div');
    modal.className = 'builder-source-modal builder-email-preview-modal';
    modal.innerHTML = `
        <div>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;">
                <h3 style="margin:0;">Clean Email Preview</h3>
                <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('.builder-source-modal').remove()">Close</button>
            </div>
            <iframe class="builder-clean-preview-frame" title="Clean email preview" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>
        </div>
    `;
    document.body.appendChild(modal);
    modal.querySelector('iframe').srcdoc = html;
}

async function saveCampaign(e, andSchedule = false) {
    if (e) e.preventDefault();
    
    builderSyncHtml();
    
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    const btn = document.getElementById('saveDraftBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving...';
    
    try {
        const data = {
            id: document.getElementById('campaignId').value,
            name: document.getElementById('campaignName').value,
            subject: document.getElementById('campaignSubject').value,
            body_html: document.getElementById('emailBody').value,
            smtp_account_id: document.getElementById('smtpAccountId').value,
            contact_list_id: document.getElementById('contactListId').value,
            contact_batch: document.getElementById('contactBatch').value,
            scheduled_at: document.getElementById('scheduledAt').value,
            min_delay_seconds: document.getElementById('minDelay').value,
            max_delay_seconds: document.getElementById('maxDelay').value,
            schedule: andSchedule,
        };
        
        const result = await apiCall(basePath + '/api/campaign-save.php', data);
        
        if (result.success) {
            if (andSchedule && result.campaign_id) {
                Toast.success('Campaign scheduled! Redirecting...');
                setTimeout(() => {
                    window.location = basePath + '/pages/campaign-view.php?id=' + result.campaign_id;
                }, 1000);
            } else {
                Toast.success('Campaign saved as draft!');
                if (result.campaign_id && !document.getElementById('campaignId').value) {
                    document.getElementById('campaignId').value = result.campaign_id;
                    history.replaceState(null, '', '?id=' + result.campaign_id);
                }
            }
        } else {
            Toast.error(result.message || 'Save failed');
        }
    } catch (err) {
        Toast.error(err.message || 'Save failed');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '💾 Save as Draft';
    }
}

async function scheduleCampaign() {
    // Validate
    if (!document.getElementById('campaignName').value) {
        Toast.error('Campaign name is required');
        return;
    }
    if (!document.getElementById('campaignSubject').value) {
        Toast.error('Subject line is required');
        return;
    }
    if (!document.getElementById('smtpAccountId').value) {
        Toast.error('Please select an SMTP account');
        return;
    }
    if (!document.getElementById('contactListId').value) {
        Toast.error('Please select a contact list');
        return;
    }
    
    Modal.confirm(
        'Schedule Campaign?',
        document.getElementById('contactBatch').value
            ? 'This will queue only contacts from the selected list and batch. Emails will start sending based on the schedule and delay settings.'
            : 'This will queue all emails from the selected contact list. Emails will start sending based on the schedule and delay settings.',
        () => saveCampaign(null, true)
    );
}

async function sendTestEmail() {
    const email = prompt('Send a test email to:');
    if (!email) return;
    
    builderSyncHtml();
    
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    Toast.info('Sending test email...', 10000);
    
    try {
        const data = {
            smtp_account_id: document.getElementById('smtpAccountId').value,
            subject: document.getElementById('campaignSubject').value,
            body_html: document.getElementById('emailBody').value,
            to_email: email,
        };
        
        const result = await apiCall(basePath + '/api/smtp-test.php', data);
        
        if (result.success) {
            Toast.success('Test email sent to ' + email + '!');
        } else {
            Toast.error(result.message || 'Failed to send test email');
        }
    } catch (err) {
        Toast.error(err.message || 'Failed to send test email');
    }
}

updateBatchOptions();
builderInit();
JS;

$pageScript = str_replace(
    ['__CONTACT_BATCH_OPTIONS__', '__SELECTED_CONTACT_BATCH__'],
    [
        json_encode($batchOptionsByList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
        json_encode((string)($campaign['contact_batch'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
    ],
    $pageScript
);

require_once __DIR__ . '/../includes/footer.php';
?>
