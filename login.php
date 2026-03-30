<?php
/**
 * Login Page
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check if installed
try {
    $installed = getSetting('installed');
    if (!$installed) {
        header('Location: install.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: install.php');
    exit;
}

// Already logged in?
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (attemptLogin($password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= APP_NAME ?></title>
    <meta name="description" content="Login to <?= APP_NAME ?> Email Marketing Tool">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📧</text></svg>">
</head>
<body>
    <div class="login-page">
        <div class="login-card">
            <div class="login-brand">
                <div class="login-brand-icon">✉</div>
                <h1><?= APP_NAME ?></h1>
                <p>Sign in to your campaign manager</p>
            </div>
            
            <?php if ($error): ?>
                <div class="flash-message flash-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" 
                           placeholder="Enter your password" required autofocus autocomplete="current-password">
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;" id="loginBtn">
                    🔓 Sign In
                </button>
            </form>
            
            <div style="text-align: center; margin-top: 24px; font-size: 12px; color: var(--text-muted);">
                <?= APP_NAME ?> v<?= APP_VERSION ?>
            </div>
        </div>
    </div>
</body>
</html>
