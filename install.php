<?php
/**
 * Installation Wizard
 * First-time setup: creates database tables and sets admin password
 */
require_once __DIR__ . '/config.php';

$step = $_POST['step'] ?? 'check';
$error = '';
$success = false;

// If already installed, redirect
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $result = $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'installed'");
    if ($result && $result->fetchColumn() > 0) {
        header('Location: login.php');
        exit;
    }
} catch (PDOException $e) {
    // Table doesn't exist yet — that's fine, we need to install
    // Only show error if it's NOT a "table not found" error
    if (strpos($e->getMessage(), '1146') === false && strpos($e->getMessage(), '42S02') === false) {
        $error = 'Database connection failed: ' . $e->getMessage();
        $step = 'check';
    }
}

// Process installation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'install') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
        $step = 'setup';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
        $step = 'setup';
    } else {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create tables
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `settings` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
                    `setting_value` TEXT,
                    INDEX idx_key (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `smtp_accounts` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `label` VARCHAR(100) NOT NULL,
                    `smtp_host` VARCHAR(255) NOT NULL,
                    `smtp_port` INT NOT NULL DEFAULT 465,
                    `smtp_encryption` ENUM('ssl','tls') NOT NULL DEFAULT 'ssl',
                    `smtp_username` VARCHAR(255) NOT NULL,
                    `smtp_password` TEXT NOT NULL,
                    `from_name` VARCHAR(255) NOT NULL,
                    `from_email` VARCHAR(255) NOT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `daily_limit` INT NOT NULL DEFAULT 0,
                    `sent_today` INT NOT NULL DEFAULT 0,
                    `last_reset_date` DATE DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_active (`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `contact_lists` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(255) NOT NULL,
                    `description` TEXT,
                    `total_contacts` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `contacts` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `list_id` INT NOT NULL,
                    `email` VARCHAR(255) NOT NULL,
                    `name` VARCHAR(255) DEFAULT '',
                    `custom_fields` JSON,
                    `is_unsubscribed` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_list (`list_id`),
                    INDEX idx_email (`email`),
                    FOREIGN KEY (`list_id`) REFERENCES `contact_lists`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `campaigns` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(255) NOT NULL,
                    `subject` VARCHAR(500) NOT NULL,
                    `body_html` LONGTEXT,
                    `smtp_account_id` INT DEFAULT NULL,
                    `contact_list_id` INT DEFAULT NULL,
                    `status` ENUM('draft','scheduled','sending','completed','paused') NOT NULL DEFAULT 'draft',
                    `scheduled_at` DATETIME DEFAULT NULL,
                    `min_delay_seconds` INT NOT NULL DEFAULT 60,
                    `max_delay_seconds` INT NOT NULL DEFAULT 3600,
                    `total_emails` INT NOT NULL DEFAULT 0,
                    `sent_count` INT NOT NULL DEFAULT 0,
                    `failed_count` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_status (`status`),
                    INDEX idx_scheduled (`scheduled_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `email_queue` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `campaign_id` INT NOT NULL,
                    `contact_id` INT NOT NULL,
                    `smtp_account_id` INT NOT NULL,
                    `to_email` VARCHAR(255) NOT NULL,
                    `to_name` VARCHAR(255) DEFAULT '',
                    `subject` VARCHAR(500) NOT NULL,
                    `body_html` LONGTEXT NOT NULL,
                    `status` ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
                    `scheduled_at` DATETIME NOT NULL,
                    `sent_at` DATETIME DEFAULT NULL,
                    `error_message` TEXT,
                    `attempts` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_status_schedule (`status`, `scheduled_at`),
                    INDEX idx_campaign (`campaign_id`),
                    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `click_tracking` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `campaign_id` INT NOT NULL,
                    `contact_id` INT NOT NULL,
                    `queue_id` INT DEFAULT NULL,
                    `original_url` TEXT NOT NULL,
                    `tracking_token` VARCHAR(64) NOT NULL UNIQUE,
                    `clicked_at` DATETIME DEFAULT NULL,
                    `click_count` INT NOT NULL DEFAULT 0,
                    `ip_address` VARCHAR(45) DEFAULT NULL,
                    `user_agent` TEXT,
                    INDEX idx_token (`tracking_token`),
                    INDEX idx_campaign (`campaign_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `campaign_images` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `campaign_id` INT DEFAULT NULL,
                    `filename` VARCHAR(255) NOT NULL,
                    `original_name` VARCHAR(255) NOT NULL,
                    `mime_type` VARCHAR(50) NOT NULL,
                    `file_size` INT NOT NULL DEFAULT 0,
                    `cid` VARCHAR(100) NOT NULL,
                    `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            
            // Save admin password
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute(['admin_password', $hashedPassword]);
            $stmt->execute(['installed', '1']);
            $stmt->execute(['app_installed_at', date('Y-m-d H:i:s')]);
            
            // Create logs directory
            $logDir = __DIR__ . '/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $success = true;
            $step = 'done';
        } catch (PDOException $e) {
            $error = 'Installation failed: ' . $e->getMessage();
            $step = 'setup';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📧</text></svg>">
</head>
<body>
    <div class="install-page">
        <div class="install-card">
            <!-- Progress Steps -->
            <div class="install-steps">
                <div class="install-step <?= in_array($step, ['check','setup','install','done']) ? 'active' : '' ?> <?= in_array($step, ['setup','install','done']) ? 'done' : '' ?>"></div>
                <div class="install-step <?= in_array($step, ['setup','install','done']) ? 'active' : '' ?> <?= in_array($step, ['install','done']) ? 'done' : '' ?>"></div>
                <div class="install-step <?= $step === 'done' ? 'active done' : '' ?>"></div>
            </div>
            
            <div style="text-align: center; margin-bottom: 32px;">
                <div class="login-brand-icon" style="display: inline-flex;">✉</div>
                <h1 style="font-size: 24px; font-weight: 700; color: var(--text-heading); margin-top: 16px;">Install <?= APP_NAME ?></h1>
                <p style="color: var(--text-muted); font-size: 14px; margin-top: 4px;">Let's get your email marketing tool set up</p>
            </div>
            
            <?php if ($error): ?>
                <div class="flash-message flash-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($step === 'check' || $step === 'setup'): ?>
                <!-- Step 1: DB Check + Password Setup -->
                <form method="POST" action="">
                    <input type="hidden" name="step" value="install">
                    
                    <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--radius-sm); padding: 12px 16px; margin-bottom: 24px; font-size: 13px; color: #6ee7b7;">
                        ✓ Database connection successful
                    </div>
                    
                    <h3 style="font-size: 16px; color: var(--text-heading); margin-bottom: 16px;">Set Admin Password</h3>
                    
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required minlength="6" autocomplete="new-password">
                    </div>
                    
                    <div class="form-group">
                        <label>Confirm Password <span class="required">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required minlength="6" autocomplete="new-password">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg" style="width: 100;">
                        🚀 Install Now
                    </button>
                </form>
            
            <?php elseif ($step === 'done'): ?>
                <!-- Step 3: Success -->
                <div class="install-success">
                    <div class="success-icon">🎉</div>
                    <h2 style="font-size: 22px; color: var(--text-heading); margin-bottom: 8px;">Installation Complete!</h2>
                    <p style="color: var(--text-muted); margin-bottom: 24px;">Your email marketing tool is ready to use.</p>
                    
                    <div style="background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: var(--radius); padding: 16px; margin-bottom: 24px; text-align: left;">
                        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;"><strong>Next steps:</strong></p>
                        <ol style="font-size: 13px; color: var(--text-muted); padding-left: 20px; line-height: 2;">
                            <li>Log in with your new password</li>
                            <li>Add your first SMTP account</li>
                            <li>Import your contacts via CSV</li>
                            <li>Create and schedule your first campaign</li>
                        </ol>
                    </div>
                    
                    <a href="login.php" class="btn btn-primary btn-lg" style="width: 100%;">
                        🔑 Go to Login
                    </a>
                </div>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 24px; font-size: 12px; color: var(--text-muted);">
                <?= APP_NAME ?> v<?= APP_VERSION ?>
            </div>
        </div>
    </div>
</body>
</html>
