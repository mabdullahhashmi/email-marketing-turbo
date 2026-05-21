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

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    validateCSRF();

    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['account_ids'] ?? []), fn($id) => $id > 0)));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        dbExecute("DELETE FROM smtp_accounts WHERE id IN ({$placeholders})", $ids);
        setFlash('success', count($ids) . ' SMTP account(s) deleted successfully.');
    } else {
        setFlash('error', 'Please select at least one SMTP account.');
    }

    redirect($basePath . '/pages/accounts.php');
}

// Fetch accounts
$accounts = dbFetchAll("SELECT * FROM smtp_accounts ORDER BY created_at DESC");

if (!function_exists('smtpDomainColor')) {
    function smtpDomainColor($domain) {
        $palette = ['#60a5fa', '#34d399', '#f59e0b', '#f472b6', '#a78bfa', '#22c55e', '#fb7185', '#38bdf8', '#c084fc', '#f97316'];
        $hash = 0;
        $domain = strtolower(trim((string) $domain));
        for ($i = 0; $i < strlen($domain); $i++) {
            $hash = (($hash << 5) - $hash) + ord($domain[$i]);
            $hash &= 0x7fffffff;
        }
        return $palette[$hash % count($palette)];
    }
}

$domainCounts = [];
foreach ($accounts as $account) {
    $domain = '';
    if (!empty($account['from_email']) && strpos($account['from_email'], '@') !== false) {
        $domain = strtolower(substr(strrchr($account['from_email'], '@'), 1));
    }
    if ($domain) {
        $domainCounts[$domain] = ($domainCounts[$domain] ?? 0) + 1;
    }
}
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">🔧</span>SMTP Accounts</h1>
        <div class="subtitle">Manage your email sending servers (Hostinger, Namecheap, etc.)</div>
    </div>
    <div style="display:flex; gap:8px;">
        <a class="btn btn-outline" href="<?= $basePath ?>/pages/accounts-bulk-gmail.php">📥 Bulk Gmail Import</a>
        <a class="btn btn-outline" href="<?= $basePath ?>/pages/accounts-bulk-import.php">📥 Bulk SMTP Import</a>
        <button class="btn btn-primary" onclick="openAddAccountModal()">
            ✚ Add Account
        </button>
    </div>
</div>

<!-- Accounts List -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div style="padding: 16px 24px 0; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
            <span class="badge badge-completed">Total SMTPs: <?= number_format(count($accounts)) ?></span>
            <span class="badge badge-draft">Unique Domains: <?= number_format(count($domainCounts)) ?></span>
            <?php foreach ($domainCounts as $domain => $count): ?>
                <span class="badge" style="background: <?= smtpDomainColor($domain) ?>; color: #fff;"><?= e($domain) ?> (<?= $count ?>)</span>
            <?php endforeach; ?>
        </div>
        <?php if (empty($accounts)): ?>
            <div class="empty-state">
                <div class="empty-icon">🔧</div>
                <h3>No SMTP accounts configured</h3>
                <p>Add your first email server to start sending campaigns.</p>
                <button class="btn btn-primary" onclick="openAddAccountModal()">✚ Add SMTP Account</button>
            </div>
        <?php else: ?>
            <form method="POST" id="smtpBulkDeleteForm" onsubmit="return confirmBulkDeleteSmtp(event)">
                <input type="hidden" name="action" value="bulk_delete">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= e(getCSRFToken()) ?>">
                <div style="display:flex; justify-content: space-between; align-items:center; gap:12px; padding: 16px 24px 0; flex-wrap: wrap;">
                    <div class="text-muted fs-sm">Select rows to delete multiple SMTP accounts at once.</div>
                    <div style="display:flex; align-items:center; gap: 10px;">
                        <span class="text-muted fs-sm"><span id="smtpSelectedCount">0</span> selected</span>
                        <button type="submit" class="btn btn-outline" style="color: var(--color-danger); border-color: var(--color-danger);" id="smtpBulkDeleteBtn" disabled>🗑️ Delete Selected</button>
                    </div>
                </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 36px;">
                                <input type="checkbox" id="smtpSelectAll" onchange="toggleAllSmtpAccounts(this.checked)" style="width:auto;">
                            </th>
                            <th>Label</th>
                            <th>Server</th>
                            <th>From</th>
                            <th>Status</th>
                            <th>Warm-Up</th>
                            <th>Sent Today</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $acc): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="account_ids[]" value="<?= $acc['id'] ?>" class="smtp-account-checkbox" onchange="updateSmtpBulkDeleteState()" style="width:auto;">
                            </td>
                            <td><strong style="color: var(--text-primary);"><?= e($acc['label']) ?></strong></td>
                            <td>
                                <span style="color: var(--text-primary);"><?= e($acc['smtp_host']) ?></span>
                                <span class="text-muted">:<?= $acc['smtp_port'] ?></span>
                                <span class="badge" style="font-size:10px; padding: 2px 6px; background: rgba(99,102,241,0.1); color: #a5b4fc;"><?= strtoupper($acc['smtp_encryption']) ?></span>
                            </td>
                            <td>
                                <?php
                                    $fromEmail = $acc['from_email'] ?? '';
                                    $fromDomain = '';
                                    if ($fromEmail && strpos($fromEmail, '@') !== false) {
                                        $fromDomain = strtolower(substr(strrchr($fromEmail, '@'), 1));
                                    }
                                    $domainColor = $fromDomain ? smtpDomainColor($fromDomain) : 'var(--text-primary)';
                                ?>
                                <div style="color: <?= $domainColor ?>; font-weight: 600;"><?= e($acc['from_name']) ?></div>
                                <div class="text-muted fs-sm" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <span style="color: <?= $domainColor ?>; font-weight: 600;"><?= e($fromEmail) ?></span>
                                    <?php if ($fromDomain): ?>
                                        <span class="badge" style="background: <?= $domainColor ?>; color:#fff; font-size:10px; padding: 2px 6px;"><?= e($fromDomain) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($acc['is_active']): ?>
                                    <span class="badge badge-completed">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-draft">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($acc['warmup_status'] === 'active'): ?>
                                    <span class="badge" style="background:#f59e0b;color:#fff;">🔥 Warm-up (Day <?= $acc['warmup_current_day'] ?: 1 ?>/30)</span>
                                    <?php if ($acc['is_seed_account']): ?>
                                        <div class="text-muted fs-sm mt-1">🌱 Seed Account</div>
                                    <?php endif; ?>
                                <?php elseif ($acc['warmup_status'] === 'completed'): ?>
                                    <span class="badge" style="background:#10b981;color:#fff;">✅ Warmup Complete</span>
                                    <div class="text-muted fs-sm mt-1">Ready for cold outreach</div>
                                <?php else: ?>
                                    <span class="text-muted fs-sm">Idle</span>
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
            </form>
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
                    <label>Email Provider</label>
                    <select id="accProvider" class="form-control" onchange="applyProviderPreset(this.value)">
                        <option value="custom">Other SMTP (Custom)</option>
                        <option value="gmail">Google Gmail</option>
                    </select>
                    <div class="form-hint">Choose Gmail for auto-filled Google SMTP/IMAP settings.</div>
                </div>
                
                <div class="form-group">
                    <label>Label <span class="required">*</span></label>
                    <input type="text" id="accLabel" class="form-control" placeholder="e.g., Hostinger - info@domain.com" required>
                </div>
                
                <div class="form-row" id="smtpServerRow">
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
                        <label id="accPasswordLabel">Password <span class="required">*</span></label>
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

                <hr style="margin: 20px 0; border: 0; border-top: 1px solid var(--border-color);">
                
                <h4>🔥 Warm-Up & IMAP Settings (Optional)</h4>
                <p class="text-muted fs-sm mb-3">Settings required if this account will be used for automated warm-up.</p>

                <div class="form-row" id="imapServerRow">
                    <div class="form-group">
                        <label>IMAP Host</label>
                        <input type="text" id="accImapHost" class="form-control" placeholder="imap.hostinger.com">
                    </div>
                    <div class="form-group">
                        <label>IMAP Port</label>
                        <input type="number" id="accImapPort" class="form-control" value="993">
                    </div>
                </div>

                <div class="form-group">
                    <label>IMAP Encryption</label>
                    <select id="accImapEncryption" class="form-control">
                        <option value="ssl">SSL</option>
                        <option value="tls">TLS</option>
                        <option value="">None</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>IMAP Username</label>
                        <input type="email" id="accImapUsername" class="form-control" placeholder="Usually same as email">
                    </div>
                    <div class="form-group">
                        <label>IMAP Password</label>
                        <input type="password" id="accImapPassword" class="form-control" placeholder="Usually same as SMTP">
                    </div>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="accIsSeed" style="width: auto;">
                        <strong>Use as Warm-Up Seed Account</strong>
                    </label>
                    <div class="form-hint">Check this if this is a free Gmail/Outlook account meant primarily to receive and reply to emails to build your other domains' reputation.</div>
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
function openAddAccountModal() {
    document.getElementById('accountModalTitle').textContent = 'Add SMTP Account';
    document.getElementById('smtpForm').reset();
    document.getElementById('accountId').value = '';
    document.getElementById('accPassword').required = true;
    document.getElementById('accPassword').placeholder = 'Email password';
    document.getElementById('accImapPassword').placeholder = 'Usually same as SMTP';
    document.getElementById('accProvider').value = 'custom';
    applyProviderPreset('custom');
    Modal.open('addAccountModal');
}

function applyProviderPreset(provider, preserveExisting = false) {
    const smtpRow = document.getElementById('smtpServerRow');
    const imapRow = document.getElementById('imapServerRow');
    const passwordLabel = document.getElementById('accPasswordLabel');
    const passwordInput = document.getElementById('accPassword');
    const fromEmail = document.getElementById('accFromEmail');
    const username = document.getElementById('accUsername');
    const imapUser = document.getElementById('accImapUsername');

    if (provider === 'gmail') {
        smtpRow.style.display = 'none';
        imapRow.style.display = 'none';

        document.getElementById('accHost').value = 'smtp.gmail.com';
        document.getElementById('accPort').value = '587';
        document.getElementById('accEncryption').value = 'tls';

        document.getElementById('accImapHost').value = 'imap.gmail.com';
        document.getElementById('accImapPort').value = '993';
        document.getElementById('accImapEncryption').value = 'ssl';

        if (!preserveExisting || !imapUser.value) {
            imapUser.value = username.value;
        }
        if (!preserveExisting || !fromEmail.value) {
            fromEmail.value = username.value;
        }

        passwordLabel.innerHTML = 'Google App Password <span class="required">*</span>';
        passwordInput.placeholder = '16-character Google App Password';
        document.getElementById('accImapPassword').placeholder = 'Same App Password';
    } else {
        smtpRow.style.display = '';
        imapRow.style.display = '';
        passwordLabel.innerHTML = 'Password <span class="required">*</span>';
        if (!preserveExisting) {
            passwordInput.placeholder = 'Email password';
            document.getElementById('accImapPassword').placeholder = 'Usually same as SMTP';
        }
    }
}

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
            imap_host: document.getElementById('accImapHost').value,
            imap_port: document.getElementById('accImapPort').value,
            imap_encryption: document.getElementById('accImapEncryption').value,
            imap_username: document.getElementById('accImapUsername').value,
            imap_password: document.getElementById('accImapPassword').value,
            is_seed_account: document.getElementById('accIsSeed').checked ? 1 : 0
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
    const detectedProvider = (acc.smtp_host || '').toLowerCase() === 'smtp.gmail.com' ? 'gmail' : 'custom';
    document.getElementById('accProvider').value = detectedProvider;
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
    
    document.getElementById('accImapHost').value = acc.imap_host || '';
    document.getElementById('accImapPort').value = acc.imap_port || '993';
    document.getElementById('accImapEncryption').value = acc.imap_encryption || 'ssl';
    document.getElementById('accImapUsername').value = acc.imap_username || '';
    document.getElementById('accImapPassword').value = '';
    document.getElementById('accImapPassword').placeholder = 'Leave blank to keep current';
    document.getElementById('accIsSeed').checked = acc.is_seed_account == 1;

    applyProviderPreset(detectedProvider, true);
    
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
    const imapUser = document.getElementById('accImapUsername');
    if (!fromEmail.value || fromEmail.value === '') {
        fromEmail.value = this.value;
    }
    if (!imapUser.value || imapUser.value === '') {
        imapUser.value = this.value;
    }
});

// Port auto-set based on encryption
document.getElementById('accEncryption').addEventListener('change', function() {
    document.getElementById('accPort').value = this.value === 'ssl' ? 465 : 587;
});

function toggleAllSmtpAccounts(checked) {
    document.querySelectorAll('.smtp-account-checkbox').forEach((checkbox) => {
        checkbox.checked = checked;
    });
    updateSmtpBulkDeleteState();
}

function updateSmtpBulkDeleteState() {
    const selected = document.querySelectorAll('.smtp-account-checkbox:checked').length;
    const countEl = document.getElementById('smtpSelectedCount');
    const btn = document.getElementById('smtpBulkDeleteBtn');
    const selectAll = document.getElementById('smtpSelectAll');

    if (countEl) countEl.textContent = String(selected);
    if (btn) btn.disabled = selected === 0;
    if (selectAll) {
        const total = document.querySelectorAll('.smtp-account-checkbox').length;
        selectAll.checked = total > 0 && selected === total;
        selectAll.indeterminate = selected > 0 && selected < total;
    }
}

function confirmBulkDeleteSmtp(event) {
    const selected = document.querySelectorAll('.smtp-account-checkbox:checked').length;
    if (!selected) {
        event.preventDefault();
        Toast.error('Please select at least one SMTP account.');
        return false;
    }
    return confirm(`Delete ${selected} selected SMTP account(s)?`);
}

updateSmtpBulkDeleteState();
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
