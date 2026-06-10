<?php
/**
 * Migration: Advanced Deliverability Suite
 * - Bounce management
 * - Reputation checks
 * - Warmup enhanced logs
 * 
 * Run once: visit /migrate-advanced.php in browser
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ---- 1. Bounces table ----
    echo "Step 1: Creating bounces table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `bounces` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL,
            `smtp_account_id` INT DEFAULT NULL,
            `campaign_id` INT DEFAULT NULL,
            `queue_id` INT DEFAULT NULL,
            `bounce_type` ENUM('hard','soft','complaint','unknown') NOT NULL DEFAULT 'unknown',
            `bounce_code` VARCHAR(10) DEFAULT NULL,
            `bounce_message` TEXT,
            `source` ENUM('smtp_response','imap_scan','manual') NOT NULL DEFAULT 'smtp_response',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (`email`),
            INDEX idx_type (`bounce_type`),
            INDEX idx_smtp (`smtp_account_id`),
            INDEX idx_campaign (`campaign_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "  bounces table ready.\n";

    // ---- 2. Suppression list ----
    echo "Step 2: Creating suppression_list table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `suppression_list` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE,
            `reason` ENUM('hard_bounce','complaint','manual','disposable','invalid_mx') NOT NULL DEFAULT 'hard_bounce',
            `source_detail` TEXT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE INDEX idx_email (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "  suppression_list table ready.\n";

    // ---- 3. Reputation checks table ----
    echo "Step 3: Creating reputation_checks table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reputation_checks` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `domain` VARCHAR(255) NOT NULL,
            `smtp_account_id` INT DEFAULT NULL,
            `spf_status` ENUM('pass','fail','missing','error') DEFAULT NULL,
            `dkim_status` ENUM('pass','fail','missing','error') DEFAULT NULL,
            `dmarc_status` ENUM('pass','fail','missing','error') DEFAULT NULL,
            `blacklist_count` INT NOT NULL DEFAULT 0,
            `blacklists_found` TEXT,
            `mx_valid` TINYINT(1) NOT NULL DEFAULT 0,
            `overall_score` INT NOT NULL DEFAULT 0,
            `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_domain (`domain`),
            INDEX idx_smtp (`smtp_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "  reputation_checks table ready.\n";

    // ---- 4. Email verifier history table ----
    echo "Step 4: Creating email_verifications table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `email_verifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `status` ENUM('valid','invalid','risky','unknown') NOT NULL DEFAULT 'unknown',
            `score` INT NOT NULL DEFAULT 0,
            `mx_valid` TINYINT(1) NOT NULL DEFAULT 0,
            `smtp_status` VARCHAR(30) DEFAULT NULL,
            `is_disposable` TINYINT(1) NOT NULL DEFAULT 0,
            `is_role` TINYINT(1) NOT NULL DEFAULT 0,
            `is_catch_all` TINYINT(1) NOT NULL DEFAULT 0,
            `suggestion` VARCHAR(255) DEFAULT NULL,
            `details` JSON,
            `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (`email`),
            INDEX idx_domain (`domain`),
            INDEX idx_status (`status`),
            INDEX idx_checked (`checked_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "  email_verifications table ready.\n";

    // ---- 5. Campaign open tracking table ----
    echo "Step 5: Creating campaign_open_tracking table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `campaign_open_tracking` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `campaign_id` INT NOT NULL,
            `contact_id` INT NOT NULL,
            `queue_id` INT DEFAULT NULL,
            `tracking_token` VARCHAR(64) NOT NULL UNIQUE,
            `opened_at` DATETIME DEFAULT NULL,
            `open_count` INT NOT NULL DEFAULT 0,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` TEXT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (`tracking_token`),
            INDEX idx_campaign (`campaign_id`),
            INDEX idx_queue (`queue_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "  campaign_open_tracking table ready.\n";

    // ---- 6. Add subject column to warmup_logs for reporting ----
    echo "Step 6: Adding subject/email_body columns to warmup_logs for reporting...\n";
    $cols = [
        "ADD COLUMN `subject` VARCHAR(500) DEFAULT NULL AFTER `message_id`",
        "ADD COLUMN `sender_email` VARCHAR(255) DEFAULT NULL AFTER `subject`",
        "ADD COLUMN `receiver_email` VARCHAR(255) DEFAULT NULL AFTER `sender_email`",
    ];
    foreach ($cols as $col) {
        try {
            $pdo->exec("ALTER TABLE `warmup_logs` $col");
            echo "  Added: $col\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "  Already exists, skipping.\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\nAll advanced migrations complete.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
