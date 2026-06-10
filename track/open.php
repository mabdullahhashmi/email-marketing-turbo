<?php
/**
 * Campaign Open Tracking Pixel
 *
 * Records when a recipient loads the hidden 1x1 image in a campaign email.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = $_GET['t'] ?? '';

if ($token && strlen($token) <= 64) {
    try {
        ensureCampaignOpenTrackingTable();

        $tracking = dbFetchOne(
            "SELECT id, opened_at FROM campaign_open_tracking WHERE tracking_token = ?",
            [$token]
        );

        if ($tracking) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            dbExecute(
                "UPDATE campaign_open_tracking
                 SET opened_at = COALESCE(opened_at, NOW()),
                     open_count = open_count + 1,
                     ip_address = ?,
                     user_agent = ?
                 WHERE id = ?",
                [$ip, $userAgent, $tracking['id']]
            );
        }
    } catch (Exception $e) {
        // Never expose tracking errors to image clients.
    }
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

echo base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
