<?php
/**
 * SMTP Accounts Management
 */
$pageTitle = 'SMTP Accounts';
require_once __DIR__ . '/../includes/header.php';

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $token = $_GET['token'] ?? '';
    if (hash_equals(getCSRFToken(), $token)) {
        dbExecute("DELETE FROM smtp_accounts WHERE id = ?", [$id]);
        setFlash('success', 'SMTP account deleted successfully.');
        redirect($basePath . '/pages/accounts.php');
    }
}

// Fetch accounts
$accounts = dbFetchAll("SELECT * FROM smtp_accounts ORDER BY created_at DESC");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">🔧</span>SMTP Accounts</h1>
        <div class="subtitle">Manage your email sending servers (Hostinger, Namecheap, etc.)</div>
    </div>
    <button class="btn btn-primary" onclick="Modal.open('addAccountModal')">
        ✚ Add Account
    </button>
</div>

<!-- Accounts List -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($accounts)): ?>
            <div class="empty-state">
                <div class="empty-icon">🔧</div>
                <h3>No SMTP accounts configured</h3>
                <p>Add your first email server to start sending campaigns.</p>
                <button class="btn btn-primary" onclick="Modal.open('addAccountModal')">✚ Add SMTP Account</button>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Server</th>
                            <th>From</th>
                            <th>Status</th>
                            <th>Sent Today</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $acc): ?>
                        <tr>
                            <td><strong style="color: var(--text-primary);"><?= e($acc['label']) ?></strong></td>
                            <td>
                                <span style="color: var(--text-primary);"><?= e($acc['smtp_host']) ?></span>
                                <span class="text-muted">:<?= $acc['smtp_port'] ?></span>
                                <span class="badge" style="font-size:10px; padding: 2px 6px; background: rgba(99,102,241,0.1); color: #a5b4fc;"><?= strtoupper($acc['smtp_encryption']) ?></span>
                            </td>
                            <td>
                                <div style="color: var(--text-primary);"><?= e($acc['from_name']) ?></div>
                                <div class="text-muted fs-sm"><?= e($acc['from_email']) ?></div>
                            </td>
                            <td>
                                <?php if ($acc['is_active']): ?>
                                    <span class="badge badge-completed">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-draft">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $acc['sent_today'] ?>
                                <?php if ($acc['daily_limit'] > 0): ?>
                                    / <?= $acc['daily_limit'] ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-ghost btn-sm" onclick="testSmtp(<?= $acc['id'] ?>)" title="Test Connection">🔍 Test</button>
                                    <button class="btn btn-ghost btn-sm" onclick="editAccount(<?= htmlspecialchars(json_encode($acc)) ?>)" title="Edit">✏️</button>
                                    <a href="?delete=<?= $acc['id'] ?>&token=<?= e(getCSRFToken()) ?>" 
                                       class="btn btn-ghost btn-sm" 
                                       onclick="return confirm('Delete this SMTP account?')" 
                                       title="Delete">🗑️</a>
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

<!-- Add/Edit Account Modal -->
<div class="modal-overlay" id="addAccountModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 id="accountModalTitle">Add SMTP Account</h3>
            <button class="modal-close" onclick="Modal.close('addAccountModal')">✕</button>
        </div>
        <form id="smtpForm" onsubmit="saveAccount(event)">
            <div class="modal-body">
                <input type="hidden" id="accountId" value="">
                
                <div class="form-group">
                    <label>Label <span class="required">*</span></label>
                    <input type="text" id="accLabel" class="form-control" placeholder="e.g., Hostinger - info@domain.com" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>SMTP Host <span class="required">*</span></label>
                        <input type="text" id="accHost" class="form-control" placeholder="smtp.hostinger.com" required>
                        <div class="form-hint">Hostinger: smtp.hostinger.com | Namecheap: mail.privateemail.com</div>
                    </div>
                    <div class="form-group">
                        <label>Port <span class="required">*</span></label>
                        <input type="number" id="accPort" class="form-control" value="465" required>
                        <div class="form-hint">SSL: 465 | TLS: 587</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Encryption</label>
                    <select id="accEncryption" class="form-control">
                        <option value="ssl">SSL</option>
                        <option value="tls">TLS</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Username (Email) <span class="required">*</span></label>
                        <input type="email" id="accUsername" class="form-control" placeholder="info@yourdomain.com" required>
                    </div>
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <input type="password" id="accPassword" class="form-control" placeholder="Email password" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>From Name <span class="required">*</span></label>
                        <input type="text" id="accFromName" class="form-control" placeholder="Your Name / Company" required>
                    </div>
                    <div class="form-group">
                        <label>From Email <span class="required">*</span></label>
                        <input type="email" id="accFromEmail" class="form-control" placeholder="info@yourdomain.com" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Daily Sending Limit</label>
                    <input type="number" id="accDailyLimit" class="form-control" value="0" min="0">
                    <div class="form-hint">Set to 0 for unlimited. Check your hosting plan's limits.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="Modal.close('addAccountModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveAccountBtn">💾 Save Account</button>
            </div>
        </form>
    </div>
</div>

<?php
$pageScript = <<<'JS'
async function saveAccount(e) {
    e.preventDefault();
    const btn = document.getElementById('saveAccountBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving...';
    
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    
    try {
        const data = {
            id: document.getElementById('accountId').value,
            label: document.getElementById('accLabel').value,
            smtp_host: document.getElementById('accHost').value,
            smtp_port: document.getElementById('accPort').value,
            smtp_encryption: document.getElementById('accEncryption').value,
            smtp_username: document.getElementById('accUsername').value,
            smtp_password: document.getElementById('accPassword').value,
            from_name: document.getElementById('accFromName').value,
            from_email: document.getElementById('accFromEmail').value,
            daily_limit: document.getElementById('accDailyLimit').value,
        };
        
        const result = await apiCall(basePath + '/api/smtp-save.php', data);
        
        if (result.success) {
            Toast.success(result.message || 'Account saved!');
            setTimeout(() => location.reload(), 1000);
        } else {
            Toast.error(result.message || 'Save failed');
        }
    } catch (err) {
        Toast.error(err.message || 'Save failed');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '💾 Save Account';
    }
}

function editAccount(acc) {
    document.getElementById('accountModalTitle').textContent = 'Edit SMTP Account';
    document.getElementById('accountId').value = acc.id;
    document.getElementById('accLabel').value = acc.label;
    document.getElementById('accHost').value = acc.smtp_host;
    document.getElementById('accPort').value = acc.smtp_port;
    document.getElementById('accEncryption').value = acc.smtp_encryption;
    document.getElementById('accUsername').value = acc.smtp_username;
    document.getElementById('accPassword').value = '';
    document.getElementById('accPassword').placeholder = 'Leave blank to keep current';
    document.getElementById('accPassword').required = false;
    document.getElementById('accFromName').value = acc.from_name;
    document.getElementById('accFromEmail').value = acc.from_email;
    document.getElementById('accDailyLimit').value = acc.daily_limit;
    Modal.open('addAccountModal');
}

async function testSmtp(accountId) {
    Toast.info('Testing SMTP connection...', 10000);
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    
    try {
        const result = await apiCall(basePath + '/api/smtp-test.php', { id: accountId });
        if (result.success) {
            Toast.success('✓ SMTP connection successful!');
        } else {
            Toast.error('✕ ' + (result.message || 'Connection failed'));
        }
    } catch (err) {
        Toast.error('✕ ' + (err.message || 'Connection test failed'));
    }
}

// Auto-fill from email when username changes
document.getElementById('accUsername').addEventListener('input', function() {
    const fromEmail = document.getElementById('accFromEmail');
    if (!fromEmail.value || fromEmail.value === '') {
        fromEmail.value = this.value;
    }
});

// Port auto-set based on encryption
document.getElementById('accEncryption').addEventListener('change', function() {
    document.getElementById('accPort').value = this.value === 'ssl' ? 465 : 587;
});
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
