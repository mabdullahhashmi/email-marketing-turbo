<?php
/**
 * Page Header & Sidebar Navigation
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
requireAuth();

$basePath = getBasePath();
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');

// Get counts for nav badges
try {
    $activeCampaigns = getCount('campaigns', "status IN ('sending','scheduled')");
} catch (Exception $e) {
    $activeCampaigns = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(getCSRFToken()) ?>">
    <meta name="base-path" content="<?= e($basePath) ?>">
    <title><?= e($pageTitle ?? 'Dashboard') ?> — <?= APP_NAME ?></title>
    <meta name="description" content="<?= APP_NAME ?> — Email Marketing Campaign Manager">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📧</text></svg>">
</head>
<body>
    <button class="mobile-menu-toggle" onclick="toggleSidebar()" aria-label="Toggle menu">☰</button>
    
    <div class="app-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <div class="brand-icon">✉</div>
                <div>
                    <div class="brand-text"><?= APP_NAME ?></div>
                    <div class="brand-version">v<?= APP_VERSION ?></div>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Overview</div>
                    <a href="<?= $basePath ?>/index.php" class="nav-item <?= $currentPage === 'index' ? 'active' : '' ?>">
                        <span class="nav-icon">📊</span>
                        Dashboard
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Email</div>
                    <a href="<?= $basePath ?>/pages/campaigns.php" class="nav-item <?= in_array($currentPage, ['campaigns','campaign-create','campaign-view']) ? 'active' : '' ?>">
                        <span class="nav-icon">📨</span>
                        Campaigns
                        <?php if ($activeCampaigns > 0): ?>
                            <span class="nav-badge"><?= $activeCampaigns ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= $basePath ?>/pages/contacts.php" class="nav-item <?= in_array($currentPage, ['contacts','contact-list']) ? 'active' : '' ?>">
                        <span class="nav-icon">👥</span>
                        Contacts
                    </a>
                    <a href="<?= $basePath ?>/pages/warmup.php" class="nav-item <?= $currentPage === 'warmup' ? 'active' : '' ?>">
                        <span class="nav-icon">🔥</span>
                        Email Warm-up
                    </a>
                    <a href="<?= $basePath ?>/pages/warmup-report.php" class="nav-item <?= $currentPage === 'warmup-report' ? 'active' : '' ?>">
                        <span class="nav-icon">📈</span>
                        Warmup Reports
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Deliverability</div>
                    <a href="<?= $basePath ?>/pages/bounces.php" class="nav-item <?= $currentPage === 'bounces' ? 'active' : '' ?>">
                        <span class="nav-icon">↩️</span>
                        Bounces
                    </a>
                    <a href="<?= $basePath ?>/pages/reputation.php" class="nav-item <?= $currentPage === 'reputation' ? 'active' : '' ?>">
                        <span class="nav-icon">🏆</span>
                        Reputation
                    </a>
                    <a href="<?= $basePath ?>/pages/spam-analyzer.php" class="nav-item <?= $currentPage === 'spam-analyzer' ? 'active' : '' ?>">
                        <span class="nav-icon">🛡️</span>
                        Spam Analyzer
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <a href="<?= $basePath ?>/pages/accounts.php" class="nav-item <?= $currentPage === 'accounts' ? 'active' : '' ?>">
                        <span class="nav-icon">🔧</span>
                        SMTP Accounts
                    </a>
                    <a href="<?= $basePath ?>/pages/accounts-bulk-import.php" class="nav-item <?= $currentPage === 'accounts-bulk-import' ? 'active' : '' ?>">
                        <span class="nav-icon">📥</span>
                        Bulk SMTP Import
                    </a>
                    <a href="<?= $basePath ?>/pages/accounts-bulk-gmail.php" class="nav-item <?= $currentPage === 'accounts-bulk-gmail' ? 'active' : '' ?>">
                        <span class="nav-icon">📥</span>
                        Bulk Gmail Import
                    </a>
                    <a href="<?= $basePath ?>/pages/settings.php" class="nav-item <?= $currentPage === 'settings' ? 'active' : '' ?>">
                        <span class="nav-icon">⚙️</span>
                        Settings
                    </a>
                </div>
            </nav>
            
            <div class="sidebar-footer">
                <a href="<?= $basePath ?>/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <span>🚪</span>
                    Sign Out
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <?php
            $flash = getFlash();
            if ($flash): ?>
                <div class="flash-message flash-<?= e($flash['type']) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>
