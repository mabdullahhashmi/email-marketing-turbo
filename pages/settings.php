<?php
/**
 * Settings Page
 */
$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/header.php';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        $stored = getSetting('admin_password');
        if (!password_verify($current, $stored)) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 6) {
            setFlash('error', 'New password must be at least 6 characters.');
        } elseif ($new !== $confirm) {
            setFlash('error', 'New passwords do not match.');
        } else {
            setSetting('admin_password', password_hash($new, PASSWORD_BCRYPT));
            setFlash('success', 'Password updated successfully.');
        }
        redirect($basePath . '/pages/settings.php');
    }
    
    if ($action === 'reset_counters') {
        dbExecute("UPDATE smtp_accounts SET sent_today = 0");
        setFlash('success', 'Daily counters reset.');
        redirect($basePath . '/pages/settings.php');
    }
}

$installedAt = getSetting('app_installed_at');
$totalSent = (int) dbFetchValue("SELECT COALESCE(SUM(sent_count), 0) FROM campaigns");
$totalContacts = getCount('contacts');
$queueSize = getCount('email_queue', "status = 'pending'");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">⚙️</span>Settings</h1>
        <div class="subtitle">Application configuration and management</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <!-- Change Password -->
    <div class="card">
        <div class="card-header">
            <h2>🔐 Change Password</h2>
        </div>
        <form method="POST">
            <div class="card-body">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= e(getCSRFToken()) ?>">
                
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">🔐 Update Password</button>
            </div>
        </form>
    </div>
    
    <!-- System Info -->
    <div class="card">
        <div class="card-header">
            <h2>📊 System Info</h2>
        </div>
        <div class="card-body">
            <table style="width: 100%;">
                <tbody>
                    <tr>
                        <td class="text-muted" style="padding: 8px 0;">App Version</td>
                        <td style="padding: 8px 0; text-align: right; color: var(--text-primary); font-weight: 500;"><?= APP_VERSION ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted" style="padding: 8px 0;">PHP Version</td>
                        <td style="padding: 8px 0; text-align: right; color: var(--text-primary); font-weight: 500;"><?= phpversion() ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted" style="padding: 8px 0;">Installed</td>
                        <td style="padding: 8px 0; text-align: right; color: var(--text-primary); font-weight: 500;"><?= formatDateTime($installedAt) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted" style="padding: 8px 0;">Total Emails Sent</td>
                        <td style="padding: 8px 0; text-align: right; color: var(--text-primary); font-weight: 500;"><?= number_format($totalSent) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted" style="padding: 8px 0;">Total Contacts</td>
                        <td style="padding: 8px 0; text-align: right; color: var(--text-primary); font-weight: 500;"><?= number_format($totalContacts) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted" style="padding: 8px 0;">Queue Size</td>
                        <td style="padding: 8px 0; text-align: right; color: var(--text-primary); font-weight: 500;"><?= number_format($queueSize) ?> pending</td>
                    </tr>
                    <tr>
                        <td class="text-muted" style="padding: 8px 0;">Timezone</td>
                        <td style="padding: 8px 0; text-align: right; color: var(--text-primary); font-weight: 500;"><?= APP_TIMEZONE ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="reset_counters">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= e(getCSRFToken()) ?>">
                <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('Reset all daily send counters?')">
                    🔄 Reset Daily Counters
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Cron Job Setup Guide -->
<div class="card mt-6">
    <div class="card-header">
        <h2>⏰ Cron Job Setup</h2>
    </div>
    <div class="card-body">
        <p style="color: var(--text-secondary); margin-bottom: 16px;">
            To automatically send queued emails, set up a Cron Job on your hosting panel to run every minute:
        </p>
        
        <div style="background: var(--bg-input); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 16px; font-family: 'SF Mono', monospace; font-size: 13px; color: var(--color-info); margin-bottom: 16px; word-break: break-all;">
            * * * * * php <?= __DIR__ ?>/../cron/process-queue.php secret=<?= CRON_SECRET ?>
        </div>
        
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 12px;">
            <strong>For Hostinger hPanel:</strong>
        </p>
        <ol style="color: var(--text-muted); font-size: 13px; padding-left: 24px; line-height: 2;">
            <li>Go to <strong>hPanel → Advanced → Cron Jobs</strong></li>
            <li>Set type to <strong>PHP</strong></li>
            <li>Enter the path to: <code>cron/process-queue.php</code></li>
            <li>Set interval to <strong>Every minute</strong></li>
            <li>Click Save</li>
        </ol>
        
        <div class="form-hint mt-4" style="color: var(--color-warning);">
            ⚠ The cron job must include the secret key parameter: <code>secret=<?= CRON_SECRET ?></code>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
