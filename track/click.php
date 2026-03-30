<?php
/**
 * Click Tracking Redirect
 * 
 * When a recipient clicks a tracked link in an email, this script:
 * 1. Records the click (time, IP, user agent)
 * 2. Redirects to the original URL
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$token = $_GET['t'] ?? '';

if (!$token || strlen($token) > 64) {
    http_response_code(404);
    die('Link not found.');
}

try {
    // Look up the tracking record
    $tracking = dbFetchOne(
        "SELECT * FROM click_tracking WHERE tracking_token = ?", 
        [$token]
    );
    
    if (!$tracking) {
        http_response_code(404);
        die('Link not found.');
    }
    
    $originalUrl = $tracking['original_url'];
    
    // Record the click
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    dbExecute(
        "UPDATE click_tracking SET clicked_at = NOW(), click_count = click_count + 1, ip_address = ?, user_agent = ? WHERE id = ?",
        [$ip, $userAgent, $tracking['id']]
    );
    
    // Redirect to original URL
    header('Location: ' . $originalUrl, true, 302);
    exit;
    
} catch (Exception $e) {
    // If anything fails, try to redirect anyway
    if (!empty($originalUrl)) {
        header('Location: ' . $originalUrl, true, 302);
    } else {
        http_response_code(500);
        die('An error occurred.');
    }
}
