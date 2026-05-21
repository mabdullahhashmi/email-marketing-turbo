<?php
/**
 * Migration: SMTP runtime test status fields
 * Run once: visit /migrate-smtp-runtime-status.php in browser
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo '<pre>';
echo "=== SMTP Runtime Status Migration ===\n\n";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $columns = [
        "ADD COLUMN `last_test_status` ENUM('untested','passed','failed') NOT NULL DEFAULT 'untested' AFTER `last_reset_date`",
        "ADD COLUMN `last_test_message` TEXT DEFAULT NULL AFTER `last_test_status`",
        "ADD COLUMN `last_tested_at` DATETIME DEFAULT NULL AFTER `last_test_message`",
    ];

    foreach ($columns as $column) {
        try {
            $pdo->exec("ALTER TABLE `smtp_accounts` $column");
            echo "✅ Added: $column\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "ℹ️ Already exists, skipping.\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\n=== Migration Complete ===\n";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
echo '</pre>';
<?php
/**
 * Migration: SMTP runtime test status fields
 * Run once: visit /migrate-smtp-runtime-status.php in browser
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo '<pre>';
echo "=== SMTP Runtime Status Migration ===\n\n";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $columns = [
        "ADD COLUMN `last_test_status` ENUM('untested','passed','failed') NOT NULL DEFAULT 'untested' AFTER `last_reset_date`",
        "ADD COLUMN `last_test_message` TEXT DEFAULT NULL AFTER `last_test_status`",
        "ADD COLUMN `last_tested_at` DATETIME DEFAULT NULL AFTER `last_test_message`",
    ];

    foreach ($columns as $column) {
        try {
            $pdo->exec("ALTER TABLE `smtp_accounts` $column");
            echo "✅ Added: $column\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "ℹ️ Already exists, skipping.\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\n=== Migration Complete ===\n";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
echo '</pre>';
