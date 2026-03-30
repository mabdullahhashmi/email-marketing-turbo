<?php
/**
 * Campaign Create/Edit - WYSIWYG Email Builder
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$loadTinyMCE = true; // Load TinyMCE in footer

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
                        <span class="shortcode-tag" onclick="insertShortcode('{{name}}')">{{name}}</span>
                        <span class="shortcode-tag" onclick="insertShortcode('{{first_name}}')">{{first_name}}</span>
                        <span class="shortcode-tag" onclick="insertShortcode('{{last_name}}')">{{last_name}}</span>
                        <span class="shortcode-tag" onclick="insertShortcode('{{email}}')">{{email}}</span>
                        <span class="shortcode-tag" onclick="insertShortcode('{{date}}')">{{date}}</span>
                        <span class="shortcode-tag" onclick="insertShortcode('{{unsubscribe_link}}')">{{unsubscribe_link}}</span>
                    </div>
                </div>
                <div class="card-body">
                    <textarea id="emailBody" name="body"><?= e($campaign['body_html'] ?? '') ?></textarea>
                    <div class="form-hint mt-2">
                        💡 Upload images using the image button in the toolbar. Images will be embedded directly in the email.
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
async function saveCampaign(e, andSchedule = false) {
    if (e) e.preventDefault();
    
    // Sync TinyMCE content
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        tinymce.activeEditor.save();
    }
    
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
    
    // Sync TinyMCE
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        tinymce.activeEditor.save();
    }
    
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
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
