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
                        <span class="builder-action-divider"></span>
                        <button type="button" class="btn btn-primary btn-sm" onclick="builderSaveCurrentTemplate()">Save Template</button>
                        <select id="savedTemplateSelect" class="builder-template-select" onchange="builderApplySavedTemplate(this.value)">
                            <option value="">Saved templates...</option>
                        </select>
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderPreviewHtml()">Preview HTML</button>
                    </div>

                    <textarea id="emailBody" name="body" style="display:none;"><?= e($campaign['body_html'] ?? '') ?></textarea>

                    <div class="email-builder-shell">
                        <aside class="builder-panel builder-panel-left">
                            <div class="builder-panel-title">Blocks</div>
                            <div class="builder-block-grid">
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('hero')"><strong>Hero</strong><span>Headline + CTA</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('brandHeader')"><strong>Header</strong><span>Brand bar</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('text')"><strong>Text</strong><span>Rich copy</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('auditGrid')"><strong>Audit Cards</strong><span>4 benefits</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('checklistPanel')"><strong>Checklist</strong><span>Dark proof panel</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('metricBars')"><strong>Metrics</strong><span>Mini bar chart</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('browserAudit')"><strong>Mockup</strong><span>Website audit</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('ctaPanel')"><strong>CTA Panel</strong><span>Audit offer</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('image')"><strong>Image</strong><span>Upload visual</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('button')"><strong>Button</strong><span>Action link</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('twoColumn')"><strong>Columns</strong><span>Two sections</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('product')"><strong>Product</strong><span>Offer card</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('divider')"><strong>Divider</strong><span>Separator</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('spacer')"><strong>Spacer</strong><span>Vertical gap</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('social')"><strong>Social</strong><span>Profile links</span></button>
                                <button type="button" class="builder-block-button" onclick="builderAddBlock('html')"><strong>HTML</strong><span>Custom code</span></button>
                            </div>

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
                                <option value="Arial">Arial</option>
                                <option value="Helvetica">Helvetica</option>
                                <option value="Verdana">Verdana</option>
                                <option value="Trebuchet MS">Trebuchet MS</option>
                                <option value="Georgia">Georgia</option>
                                <option value="Times New Roman">Times New Roman</option>
                            </select>
                        </aside>

                        <section class="builder-stage">
                            <div class="builder-toolbar">
                                <div>
                                    <strong>Canvas</strong>
                                    <span class="text-muted fs-sm">Email-safe 640px layout</span>
                                </div>
                                <button type="button" class="btn btn-outline btn-sm" onclick="builderUndo()">Undo</button>
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
        'Poppins': "'Poppins', Arial, Helvetica, sans-serif",
        'Montserrat': "'Montserrat', Arial, Helvetica, sans-serif",
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
        return "<link href=\"https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">";
    }
    if (builderState.settings.font === 'Montserrat') {
        return "<link href=\"https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">";
    }
    return '';
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
    };
}

function builderLoadTemplate(name) {
    builderSnapshot();
    builderState.blocks = builderTemplates()[name] || builderTemplates().newsletter;
    builderSelectedId = builderState.blocks[0]?.id || null;
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
        { bg: '#f4f7fb', contentBg: '#ffffff', accent: '#2563eb', font: 'Poppins' },
        builderState.settings || {}
    );
    document.getElementById('builderBg').value = builderState.settings.bg || '#f4f7fb';
    document.getElementById('builderContentBg').value = builderState.settings.contentBg || '#ffffff';
    document.getElementById('builderAccent').value = builderState.settings.accent || '#2563eb';
    document.getElementById('builderFont').value = builderState.settings.font || 'Poppins';
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
    if (savedState && Array.isArray(savedState.blocks)) {
        builderState = savedState;
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
    const hidden = document.getElementById('emailBody');
    const existing = hidden.value.trim();
    const savedState = builderParseStateFromHtml(existing);
    if (savedState && Array.isArray(savedState.blocks)) {
        builderState = savedState;
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
    document.getElementById('builderPreviewWrap').style.background = builderState.settings.bg;
    document.getElementById('builderCanvas').style.background = builderState.settings.contentBg;
    document.getElementById('builderCanvas').style.fontFamily = builderFontStack();
    builderRenderCanvas();
}

function builderAddBlock(type) {
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

function builderBlockInnerHtml(block) {
    if (block.type === 'hero') {
        return `
            <div style="background:${builderAttr(block.bg)}; color:${builderAttr(block.textColor)}; text-align:${builderAttr(block.align)}; padding:${Number(block.padding) || 0}px 34px;">
                ${block.imageUrl ? `<img src="${builderAttr(block.imageUrl)}" alt="" style="display:block; max-width:100%; margin:0 auto 22px; border-radius:6px;">` : ''}
                <div style="font-size:12px; font-weight:700; letter-spacing:1px; color:${builderAttr(builderState.settings.accent)}; margin-bottom:10px;">${builderEsc(block.eyebrow)}</div>
                <div style="font-size:34px; line-height:1.15; font-weight:800; margin-bottom:14px;">${builderEsc(block.title)}</div>
                <div style="font-size:16px; line-height:1.65; margin-bottom:22px;">${builderLines(block.subtitle)}</div>
                ${block.buttonText ? `<a href="${builderAttr(block.buttonUrl)}" style="display:inline-block; background:${builderAttr(builderState.settings.accent)}; color:#ffffff; text-decoration:none; padding:13px 22px; border-radius:4px; font-weight:700;">${builderEsc(block.buttonText)}</a>` : ''}
                ${block.secondaryButtonText ? `<a href="${builderAttr(block.secondaryButtonUrl)}" style="display:inline-block; background:${builderAttr(block.secondaryButtonBg || '#ffffff')}; color:${builderAttr(block.secondaryButtonColor || '#111827')}; text-decoration:none; padding:12px 20px; border-radius:4px; font-weight:700; margin:4px;">${builderEsc(block.secondaryButtonText)}</a>` : ''}
            </div>
        `;
    }
    if (block.type === 'brandHeader') {
        return `<div style="display:flex; justify-content:space-between; gap:18px; align-items:center; background:${builderAttr(block.bg)}; color:${builderAttr(block.color)}; padding:${Number(block.padding) || 0}px 28px;">
            <div style="font-size:17px; font-weight:800;">${builderEsc(block.brand)}</div>
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
                    <div style="display:inline-block; background:${builderAttr(block.iconBg)}; color:${builderAttr(block.iconColor)}; border-radius:10px; padding:8px 10px; font-size:12px; font-weight:800; margin-bottom:14px;">${builderEsc(card[0])}</div>
                    <div style="font-weight:800; color:#0f172a; margin-bottom:8px;">${builderEsc(card[1])}</div>
                    <div style="font-size:13px; color:#475569; line-height:1.55;">${builderLines(card[2])}</div>
                </div>`).join('')}
            </div>
        </div>`;
    }
    if (block.type === 'checklistPanel') {
        const items = [block.item1, block.item2, block.item3, block.item4].filter(Boolean);
        return `<div style="padding:${Number(block.padding) || 0}px 30px; background:#ffffff;">
            <div style="background:${builderAttr(block.bg)}; color:${builderAttr(block.color)}; border-radius:12px; padding:24px;">
                <div style="font-size:21px; font-weight:800; margin-bottom:12px;">${builderEsc(block.title)}</div>
                <div style="font-size:14px; line-height:1.7; margin-bottom:16px;">${builderLines(block.intro)}</div>
                ${items.map((item) => `<div style="border-top:1px solid ${builderAttr(block.lineColor)}; padding:12px 0; font-size:14px;"><span style="color:${builderAttr(block.accent)}; font-weight:800;">&#10003;</span> ${builderEsc(item)}</div>`).join('')}
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
            <div style="font-size:22px; font-weight:800; margin-bottom:8px;">${builderEsc(block.title)}</div>
            <div style="font-size:13px; color:${builderAttr(block.muted)}; margin-bottom:24px;">${builderEsc(block.subtitle)}</div>
            <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:18px;">
                ${metrics.map((metric) => `<div style="text-align:center;">
                    <div style="font-size:11px; color:${builderAttr(block.muted)}; font-weight:800; letter-spacing:1px; text-transform:uppercase; margin-bottom:12px;">${builderEsc(metric[0])}</div>
                    <div style="height:116px; display:flex; align-items:flex-end; justify-content:center; gap:10px; margin-bottom:12px;">
                        <div style="width:34px; height:38px; background:#64748b; border-radius:5px 5px 0 0; color:#fff; font-size:11px; font-weight:800; padding-top:5px;">${builderEsc(metric[1])}</div>
                        <div style="width:40px; height:92px; background:${builderAttr(block.accent)}; border-radius:5px 5px 0 0; color:#fff; font-size:11px; font-weight:800; padding-top:5px;">${builderEsc(metric[2])}</div>
                    </div>
                    <div style="display:inline-block; border:1px solid rgba(251,146,60,.45); background:rgba(251,146,60,.14); color:${builderAttr(block.accent)}; border-radius:20px; padding:7px 10px; font-size:12px; font-weight:800;">${builderEsc(metric[3])}</div>
                </div>`).join('')}
            </div>
        </div>`;
    }
    if (block.type === 'browserAudit') {
        const issues = [block.issue1, block.issue2, block.issue3, block.issue4].filter(Boolean);
        return `<div style="padding:${Number(block.padding) || 0}px 30px; background:${builderAttr(block.bg)};">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;">
                <div style="font-size:12px; letter-spacing:2px; text-transform:uppercase; font-weight:800; color:#8aa0c8;">${builderEsc(block.label)}</div>
                <div style="background:#ef4444; color:#fff; border-radius:7px; padding:9px 12px; font-weight:800;">${builderEsc(block.score)}</div>
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
                <div style="font-size:24px; line-height:1.2; font-weight:800; margin-bottom:12px;">${builderEsc(block.title)}</div>
                <div style="font-size:14px; line-height:1.7; margin-bottom:20px;">${builderLines(block.text)}</div>
                <a href="${builderAttr(block.buttonUrl)}" style="display:inline-block; background:${builderAttr(block.buttonBg)}; color:#ffffff; text-decoration:none; padding:13px 22px; border-radius:7px; font-weight:800; margin:4px;">${builderEsc(block.buttonText)}</a>
                ${block.secondaryButtonText ? `<a href="${builderAttr(block.secondaryButtonUrl)}" style="display:inline-block; background:${builderAttr(block.secondaryButtonBg || '#ffffff')}; color:${builderAttr(block.secondaryButtonColor || '#0f172a')}; border:1px solid ${builderAttr(block.border)}; text-decoration:none; padding:12px 20px; border-radius:7px; font-weight:800; margin:4px;">${builderEsc(block.secondaryButtonText)}</a>` : ''}
            </div>
        </div>`;
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
                    <div><div style="font-size:18px; font-weight:800; margin-bottom:8px;">${builderEsc(block.leftTitle)}</div><div style="font-size:14px; line-height:1.65;">${builderLines(block.leftText)}</div></div>
                    <div><div style="font-size:18px; font-weight:800; margin-bottom:8px;">${builderEsc(block.rightTitle)}</div><div style="font-size:14px; line-height:1.65;">${builderLines(block.rightText)}</div></div>
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
                        <div style="font-size:22px; font-weight:800; color:#111827; margin-bottom:8px;">${builderEsc(block.title)}</div>
                        <div style="font-size:14px; color:#475569; line-height:1.6; margin-bottom:10px;">${builderLines(block.description)}</div>
                        <div style="font-size:18px; font-weight:800; color:#111827; margin-bottom:14px;">${builderEsc(block.price)}</div>
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
                <div style="width:42px; height:42px; border-radius:50%; background:#0f172a; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800;">${builderEsc(block.avatarText)}</div>
                <div>
                    <div style="font-weight:800;">${builderEsc(block.name)}</div>
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
    document.getElementById('builderPreviewWrap').style.background = builderState.settings.bg;
    canvas.style.background = builderState.settings.contentBg;
    canvas.style.fontFamily = builderFontStack();
    canvas.innerHTML = builderState.blocks.length
        ? builderState.blocks.map(builderPreviewBlock).join('')
        : '<div class="builder-empty-state">Choose a template or add a block to start building.</div>';
    builderSyncHtml();
}

function builderRender() {
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
    const rows = builderState.blocks.map(builderEmailBlock).join('');
    return `<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">${builderFontImport()}</head>
<body style="margin:0; padding:0; background:${builderAttr(builderState.settings.bg)}; font-family:${builderAttr(builderFontStack())};">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background:${builderAttr(builderState.settings.bg)}; border-collapse:collapse;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:640px; background:${builderAttr(builderState.settings.contentBg)}; border-collapse:collapse; font-family:${builderAttr(builderFontStack())};">
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
