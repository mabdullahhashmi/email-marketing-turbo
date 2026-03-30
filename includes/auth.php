<?php
/**
 * Authentication & Session Management
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

/**
 * Check if user is logged in, redirect to login if not
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: ' . getBasePath() . '/login.php');
        exit;
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Attempt login
 */
function attemptLogin($password) {
    $stored = getSetting('admin_password');
    if ($stored && password_verify($password, $stored)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        regenerateCSRF();
        return true;
    }
    return false;
}

/**
 * Logout
 */
function logout() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Generate CSRF token
 */
function regenerateCSRF() {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}

/**
 * Get current CSRF token
 */
function getCSRFToken() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        regenerateCSRF();
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validate CSRF token
 */
function validateCSRF($token = null) {
    if ($token === null) {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_GET[CSRF_TOKEN_NAME] ?? '';
    }
    if (!isset($_SESSION[CSRF_TOKEN_NAME]) || !hash_equals($_SESSION[CSRF_TOKEN_NAME], $token)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']));
    }
}

/**
 * Get / Set application settings
 */
function getSetting($key) {
    return dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
}

function setSetting($key, $value) {
    $existing = dbFetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
    if ($existing) {
        dbExecute("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
    } else {
        dbInsert("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
    }
}

/**
 * Get the base path for the application
 */
function getBasePath() {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    // If we're in a subdirectory (pages/, api/, etc.), go up one level
    $basePath = $scriptDir;
    if (preg_match('#/(pages|api|cron|track)$#', $scriptDir)) {
        $basePath = dirname($scriptDir);
    }
    return rtrim($basePath, '/');
}
