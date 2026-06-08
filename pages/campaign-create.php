<?php
/**
 * Campaign Create/Edit - WYSIWYG Email Builder
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

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
        (SELECT COUNT(*) FROM contacts c WHERE c.list_id = cl.id AND c.is_unsubscribed = 0) as active_count
    FROM contact_lists cl 
    ORDER BY cl.name
");
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
    
    <div style="display: grid; grid-template-columns: 1fr 340px; gap: 24px; align-items: start;">
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
                        <button type="button" class="btn btn-outline btn-sm" onclick="builderPreviewHtml()">Preview HTML</button>
                    </div>

                    <textarea id="emailBody" name="body" style="display:none;"><?= e($campaign['body_html'] ?? '') ?></textarea>

                    <div class="email-builder-shell">
                        <aside class="builder-panel builder-panel-left">
                            <div class="builder-panel-title">Blocks</div>
                            <div class="builder-block-grid">
                                <button type="button" onclick="builderAddBlock('hero')">Hero</button>
                                <button type="button" onclick="builderAddBlock('text')">Text</button>
                                <button type="button" onclick="builderAddBlock('image')">Image</button>
                                <button type="button" onclick="builderAddBlock('button')">Button</button>
                                <button type="button" onclick="builderAddBlock('twoColumn')">2 Columns</button>
                                <button type="button" onclick="builderAddBlock('product')">Product</button>
                                <button type="button" onclick="builderAddBlock('divider')">Divider</button>
                                <button type="button" onclick="builderAddBlock('spacer')">Spacer</button>
                                <button type="button" onclick="builderAddBlock('social')">Social</button>
                                <button type="button" onclick="builderAddBlock('html')">HTML</button>
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
                            <label class="builder-control-label">Page Background</label>
                            <input type="color" id="builderBg" value="#f4f7fb" onchange="builderUpdateSettings()">
                            <label class="builder-control-label">Email Background</label>
                            <input type="color" id="builderContentBg" value="#ffffff" onchange="builderUpdateSettings()">
                            <label class="builder-control-label">Accent Color</label>
                            <input type="color" id="builderAccent" value="#2563eb" onchange="builderUpdateSettings()">
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
                        <select id="contactListId" class="form-control" required>
                            <option value="">— Select List —</option>
                            <?php foreach ($contactLists as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= ($campaign['contact_list_id'] ?? '') == $cl['id'] ? 'selected' : '' ?>>
                                    <?= e($cl['name']) ?> (<?= $cl['active_count'] ?> contacts)
                                </option>
                            <?php endforeach; ?>
                        </select>
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
let builderState = {
    settings: { bg: '#f4f7fb', contentBg: '#ffffff', accent: '#2563eb' },
    blocks: []
};
let builderSelectedId = null;
let builderHistory = [];
let builderFocusedField = null;

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
            imageUrl: '',
            align: 'center',
            bg: '#eef4ff',
            textColor: '#111827',
            padding: 42,
        },
        text: {
            content: 'Hi {{first_name}},\n\nAdd your message here. Keep it clear, useful, and focused on one next action.',
            fontSize: 16,
            color: '#334155',
            align: 'left',
            padding: 28,
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
    document.getElementById('builderBg').value = builderState.settings.bg || '#f4f7fb';
    document.getElementById('builderContentBg').value = builderState.settings.contentBg || '#ffffff';
    document.getElementById('builderAccent').value = builderState.settings.accent || '#2563eb';
    builderRender();
}

function builderUpdateSettings() {
    builderState.settings.bg = document.getElementById('builderBg').value;
    builderState.settings.contentBg = document.getElementById('builderContentBg').value;
    builderState.settings.accent = document.getElementById('builderAccent').value;
    document.getElementById('builderPreviewWrap').style.background = builderState.settings.bg;
    document.getElementById('builderCanvas').style.background = builderState.settings.contentBg;
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
            </div>
        `;
    }
    if (block.type === 'text') {
        return `<div style="padding:${Number(block.padding) || 0}px 34px; text-align:${builderAttr(block.align)}; color:${builderAttr(block.color)}; font-size:${Number(block.fontSize) || 16}px; line-height:1.7;">${builderLines(block.content)}</div>`;
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
    if (block.type === 'html') {
        return `<div style="padding:${Number(block.padding) || 0}px 34px;">${block.html || ''}</div>`;
    }
    return '';
}

function builderRenderCanvas() {
    const canvas = document.getElementById('builderCanvas');
    document.getElementById('builderPreviewWrap').style.background = builderState.settings.bg;
    canvas.style.background = builderState.settings.contentBg;
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
        <label class="builder-control-label">${label}</label>
        <input class="builder-control" type="${type}" value="${builderAttr(block[key] ?? '')}" onfocus="builderFocusedField={id:'${block.id}',key:'${key}'}" oninput="builderSet('${block.id}','${key}',this.value)">
    `;
}

function builderTextarea(label, key, rows = 4) {
    const block = builderGetBlock();
    return `
        <label class="builder-control-label">${label}</label>
        <textarea class="builder-control" rows="${rows}" onfocus="builderFocusedField={id:'${block.id}',key:'${key}'}" oninput="builderSet('${block.id}','${key}',this.value)">${builderEsc(block[key] ?? '')}</textarea>
    `;
}

function builderColor(label, key) {
    const block = builderGetBlock();
    return `
        <label class="builder-control-label">${label}</label>
        <div class="builder-color-row">
            <input type="color" value="${builderAttr(block[key] || '#ffffff')}" oninput="builderSet('${block.id}','${key}',this.value)">
            <input class="builder-control" type="text" value="${builderAttr(block[key] || '')}" oninput="builderSet('${block.id}','${key}',this.value)">
        </div>
    `;
}

function builderSelect(label, key, options) {
    const block = builderGetBlock();
    return `
        <label class="builder-control-label">${label}</label>
        <select class="builder-control" onchange="builderSet('${block.id}','${key}',this.value)">
            ${options.map((opt) => `<option value="${builderAttr(opt)}" ${block[key] === opt ? 'selected' : ''}>${builderEsc(opt)}</option>`).join('')}
        </select>
    `;
}

function builderNumber(label, key, min = 0, max = 999) {
    const block = builderGetBlock();
    return `
        <label class="builder-control-label">${label}</label>
        <input class="builder-control" type="number" min="${min}" max="${max}" value="${builderAttr(block[key] ?? '')}" oninput="builderSet('${block.id}','${key}',this.value)">
    `;
}

function builderImageControl(label, key) {
    const block = builderGetBlock();
    return `
        ${builderInput(label, key, 'text')}
        <label class="builder-control-label">Upload</label>
        <input class="builder-control" type="file" accept="image/*" onchange="builderUploadImage('${block.id}','${key}',this)">
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
        html += builderInput('Eyebrow', 'eyebrow') + builderInput('Title', 'title') + builderTextarea('Subtitle', 'subtitle') + builderInput('Button Text', 'buttonText') + builderInput('Button URL', 'buttonUrl') + builderImageControl('Image URL', 'imageUrl') + builderSelect('Alignment', 'align', ['left', 'center', 'right']) + builderColor('Background', 'bg') + builderColor('Text Color', 'textColor') + builderNumber('Padding', 'padding', 12, 90);
    } else if (block.type === 'text') {
        html += builderTextarea('Content', 'content', 7) + builderNumber('Font Size', 'fontSize', 10, 28) + builderColor('Text Color', 'color') + builderSelect('Alignment', 'align', ['left', 'center', 'right']) + builderNumber('Padding', 'padding', 8, 70);
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
        </td></tr>`;
    }
    if (block.type === 'text') {
        return `<tr><td align="${builderAttr(block.align)}" style="padding:${Number(block.padding) || 0}px 34px; text-align:${builderAttr(block.align)}; color:${builderAttr(block.color)}; font-size:${Number(block.fontSize) || 16}px; line-height:1.7;">${builderLines(block.content)}</td></tr>`;
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
    if (block.type === 'html') {
        return `<tr><td style="padding:${Number(block.padding) || 0}px 34px;">${block.html || ''}</td></tr>`;
    }
    return '';
}

function builderGenerateHtml() {
    const rows = builderState.blocks.map(builderEmailBlock).join('');
    return `<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0; padding:0; background:${builderAttr(builderState.settings.bg)};">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background:${builderAttr(builderState.settings.bg)}; border-collapse:collapse;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:640px; background:${builderAttr(builderState.settings.contentBg)}; border-collapse:collapse; font-family:Arial, Helvetica, sans-serif;">
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
        'This will queue all emails from the selected contact list. Emails will start sending based on the schedule and delay settings.',
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

builderInit();
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
