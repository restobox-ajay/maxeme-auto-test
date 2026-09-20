<?php

/**
 * Router for PHP's built-in web server, used ONLY by DbAdminGatewaySubprocessTest to exercise the real
 * public/db-admin.php as an actual HTTP request instead of testing a stand-in.
 *
 * This file changes nothing about how the gateway runs: it forces APP_ENV=test (the same trick
 * tests/_bootstrap.php uses for Codeception) before requiring the gateway script UNMODIFIED, by its
 * real path. Every check the gateway performs — key lookup, expiry, IP binding — runs exactly as it
 * does in production; only the database it opens (var/data_test.db) differs.
 */

declare(strict_types=1);

// SCRIPT_NAME is unreliable in router-script mode (the built-in server sets it to this router file,
// not the requested path) — REQUEST_URI is the one that reflects what was actually asked for.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path !== '/db-admin.php') {
    return false;
}

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

require dirname(__DIR__, 2) . '/public/db-admin.php';
