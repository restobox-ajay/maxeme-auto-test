<?php

/**
 * phpLiteAdmin auth gateway.
 *
 * Runs directly under the web server, outside Symfony's kernel. It does NOT read the Symfony session;
 * it authorises a request purely by looking its cookie token up in db_console_session — a short-lived,
 * randomly generated, per-admin credential minted by DatabaseConsoleController.
 *
 * A request is served only if ALL of these hold:
 *   1. the console is switched on (app_setting.db_console_enabled_until is in the future);
 *   2. the IP is not currently locked out for too many failed attempts (see below);
 *   3. (if DB_CONSOLE_ALLOWED_IPS is set) the IP is on the allowlist;
 *   4. the cookie token hashes to a row in db_console_session;
 *   5. that row has not expired;
 *   6. the request comes from the IP the session was opened from;
 *   7. the owning admin still exists, is Active, and still holds ROLE_SUPER_ADMIN or ROLE_TECH_SUPPORT.
 *
 * Check 1 is the kill-switch and is deliberately unconditional: switching the console off (or letting
 * the window lapse) locks out every live session on its next request, no matter how valid its token.
 *
 * Every load that fails 3-7 is counted as a failed login against the requesting IP (db_console_throttle),
 * mirroring the firewall's login_throttling (5 attempts / 2 minutes): once an IP trips the limit it is
 * refused outright until the window passes, even with an otherwise-valid token. A successful load clears
 * the IP's counter.
 *
 * Fail closed: any problem — bad/absent/expired token, wrong IP, revoked account, missing DB, or any
 * error at all — redirects to /admin/login and never loads phpLiteAdmin.
 */

declare(strict_types=1);

use App\Security\ConsoleCookie;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

// Matches security.yaml login_throttling: 5 attempts per 2-minute window.
const DB_CONSOLE_MAX_FAILED_ATTEMPTS = 5;
const DB_CONSOLE_THROTTLE_WINDOW = 120;

$denyToLogin = static function (): never {
    if (!headers_sent()) {
        header('Location: /admin/login');
    }
    exit;
};

try {
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    // Load the app's env outside the kernel so APP_ENV is set. The argument is the BASE path, not the
    // file to load: bootEnv derives the chain from it and prefers the compiled `.env.local.php` when
    // present, exactly as public/index.php and bin/console do.
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

    // Resolve the client IP with the SAME helper the app uses at mint time (Symfony
    // Request::getClientIp), reading the shared trusted-proxy config so IPs agree behind a proxy.
    $trustedProxies = trim((string) ($_ENV['TRUSTED_PROXIES'] ?? ''));
    if ($trustedProxies !== '') {
        Request::setTrustedProxies(
            array_filter(array_map('trim', explode(',', $trustedProxies))),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
        );
    }
    $currentIp = (string) Request::createFromGlobals()->getClientIp();

    // Point phpLiteAdmin at the SAME SQLite file the app uses. Resolved from DATABASE_URL (which
    // bootEnv above put in $_ENV) rather than hardcoded, so the gateway follows whatever path each
    // environment declares — var/data_prod.db, var/data/data_prod.sqlite, anything — instead of
    // assuming one convention and silently denying when a deployment differs.
    $dbPath = ConsoleCookie::sqlitePath(
        (string) ($_ENV['DATABASE_URL'] ?? ''),
        dirname(__DIR__),
        (string) ($_ENV['APP_ENV'] ?? 'dev'),
    );
    if ($dbPath === null || !is_file($dbPath)) {
        $denyToLogin();
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $now = time();

    // Kill-switch, above everything else: if the console is not switched on, nothing else matters.
    // Setting db_console_enabled_until to 0 (or letting it lapse) shuts every live session out on its
    // next request, which is the lever incident response reaches for. A missing row reads as 0 => off.
    $sw = $pdo->prepare('SELECT setting_value FROM app_setting WHERE setting_key = :k LIMIT 1');
    $sw->execute(['k' => 'db_console_enabled_until']);
    if ((int) $sw->fetchColumn() <= $now) {
        $denyToLogin();
    }

    // Rate limit, keyed by IP. A load that fails any auth check below is a failed login; once an IP is
    // over the limit within the window it is refused here before any further work.
    $throttle = $pdo->prepare('SELECT window_start, attempts FROM db_console_throttle WHERE ip_address = :ip LIMIT 1');
    $throttle->execute(['ip' => $currentIp]);
    $throttleRow = $throttle->fetch(PDO::FETCH_ASSOC);
    $throttled = $throttleRow !== false
        && ($now - (int) $throttleRow['window_start']) < DB_CONSOLE_THROTTLE_WINDOW
        && (int) $throttleRow['attempts'] >= DB_CONSOLE_MAX_FAILED_ATTEMPTS;
    if ($throttled) {
        $denyToLogin();
    }

    // Records this load as a failed login against the IP (fixed window), then denies.
    $denyFailedLogin = static function () use ($pdo, $currentIp, $now, $throttleRow, $denyToLogin): never {
        if ($throttleRow === false || ($now - (int) $throttleRow['window_start']) >= DB_CONSOLE_THROTTLE_WINDOW) {
            $stmt = $pdo->prepare(
                'INSERT OR REPLACE INTO db_console_throttle (ip_address, window_start, attempts) VALUES (:ip, :ws, 1)'
            );
            $stmt->execute(['ip' => $currentIp, 'ws' => $now]);
        } else {
            $stmt = $pdo->prepare('UPDATE db_console_throttle SET attempts = attempts + 1 WHERE ip_address = :ip');
            $stmt->execute(['ip' => $currentIp]);
        }
        $denyToLogin();
    };

    // Master override: if DB_CONSOLE_ALLOWED_IPS is set, ONLY those IPs/CIDRs may reach the console at
    // all — a hard gate above every other check, for very critical deployments. Empty => no restriction.
    $allowIps = trim((string) ($_ENV['DB_CONSOLE_ALLOWED_IPS'] ?? ''));
    if ($allowIps !== '' && !IpUtils::checkIp($currentIp, array_filter(array_map('trim', explode(',', $allowIps))))) {
        $denyFailedLogin();
    }

    // The credential: an opaque random token. Hash it and look the session up.
    $token = $_COOKIE[ConsoleCookie::COOKIE_NAME] ?? '';
    if (!is_string($token) || $token === '') {
        $denyFailedLogin();
    }

    $stmt = $pdo->prepare(
        'SELECT id, admin_id, expires_at, ip_address FROM db_console_session WHERE token_hash = :h LIMIT 1'
    );
    $stmt->execute(['h' => ConsoleCookie::hashToken($token)]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session === false) {
        $denyFailedLogin();
    }

    // Expired: drop the row and count it as a failed login.
    if (strtotime((string) $session['expires_at']) <= $now) {
        $pdo->prepare('DELETE FROM db_console_session WHERE id = :id')->execute(['id' => $session['id']]);
        $denyFailedLogin();
    }

    // Bound to the IP that opened it.
    if ((string) $session['ip_address'] !== $currentIp) {
        $denyFailedLogin();
    }

    // The account must still exist, be Active, and still hold ROLE_SUPER_ADMIN or ROLE_TECH_SUPPORT —
    // the gateway runs outside the kernel (no role_hierarchy), so both are listed, and this is the
    // only place a since-revoked admin is caught.
    $acct = $pdo->prepare('SELECT status, roles FROM admin_user WHERE id = :id LIMIT 1');
    $acct->execute(['id' => $session['admin_id']]);
    $account = $acct->fetch(PDO::FETCH_ASSOC);
    $roles = $account !== false ? json_decode((string) $account['roles'], true) : null;
    if (
        $account === false
        || (string) $account['status'] !== 'Active'
        || !is_array($roles)
        || array_intersect(['ROLE_SUPER_ADMIN', 'ROLE_TECH_SUPPORT'], $roles) === []
    ) {
        $denyFailedLogin();
    }

    // Authorised. Clear the IP's failed-login counter, slide the session's expiry forward on activity
    // (auto-offs after the window's idle), and tidy up any lapsed sessions.
    $pdo->prepare('DELETE FROM db_console_throttle WHERE ip_address = :ip')->execute(['ip' => $currentIp]);

    $window = ConsoleCookie::windowSeconds($_ENV['DB_CONSOLE_WINDOW_MINUTES'] ?? null);
    $slide = $pdo->prepare('UPDATE db_console_session SET expires_at = :exp WHERE id = :id');
    $slide->execute(['exp' => date('Y-m-d H:i:s', $now + $window), 'id' => $session['id']]);

    $pdo->prepare('DELETE FROM db_console_session WHERE expires_at <= :now')
        ->execute(['now' => date('Y-m-d H:i:s', $now)]);

    $pdo = null;
} catch (\Throwable) {
    $denyToLogin();
}

// Authorised — hand off to phpLiteAdmin (kept outside the docroot).
define('APP_DB_PATH', $dbPath);
$password = '';
define('PHPLITEADMIN_AUTHORIZED', true);
require_once dirname(__DIR__) . '/tools/phpliteadmin.php';
