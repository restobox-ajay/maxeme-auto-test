<?php

namespace App\Security;

/**
 * Helpers for the phpLiteAdmin console credential.
 *
 * The credential is an opaque, randomly generated token (see DbConsoleSession). It is NOT derived from
 * the user id or any secret: it is unguessable on its own, stored server-side as a hash, and looked up
 * per request by the gateway (public/db-admin.php). That is what lets it expire and be revoked — there
 * is a row to delete — unlike the previous deterministic signed cookie, which was the same value forever.
 *
 * Kept as small pure functions so both the mint controller and the standalone gateway share them and
 * they stay unit-testable.
 */
final class ConsoleCookie
{
    public const COOKIE_NAME = 'db_console';

    /** Default console window (minutes) when DB_CONSOLE_WINDOW_MINUTES is unset/invalid. */
    public const DEFAULT_WINDOW_MINUTES = 30;

    /** Kill-switch window in seconds from the env value (minutes); falls back to the default. */
    public static function windowSeconds(int|string|null $envMinutes): int
    {
        $minutes = (int) $envMinutes;

        return ($minutes > 0 ? $minutes : self::DEFAULT_WINDOW_MINUTES) * 60;
    }

    /** A fresh 256-bit opaque token (64 hex chars). This raw value is handed to the client and nothing else. */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The stored form of a token. Only the hash is persisted, so a leak of the database does not yield a
     * usable credential. hash() with a fixed-length output means hash_equals-style comparison isn't
     * needed here — the value is a random 256-bit token, not a low-entropy secret.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Resolve a Doctrine SQLite DATABASE_URL to a filesystem path, the way the gateway needs it.
     *
     * The gateway (public/db-admin.php) runs outside the kernel, so it can't ask Doctrine where the
     * database is; it has to read DATABASE_URL and resolve it by hand. Doing that here — from the same
     * value the kernel uses — is what stops the gateway and the app from ever disagreeing about the
     * path, which is exactly what broke a deployment whose .env.local pointed at var/data/data_prod.sqlite
     * while the gateway assumed the var/data_%env%.db convention.
     *
     * Dotenv populates $_ENV['DATABASE_URL'] with the raw value; it does NOT expand %kernel.project_dir%
     * or %kernel.environment% (those are container parameters, not env vars — Doctrine only sees them
     * resolved because framework.yaml routes DATABASE_URL through the `env(resolve:...)` processor,
     * which runs inside the DI container), so this substitutes both by hand. Returns null for anything
     * that isn't a concrete sqlite file path (a non-sqlite driver, :memory:, or empty), which the caller
     * treats as "no database" and fails closed.
     */
    public static function sqlitePath(string $databaseUrl, string $projectDir, string $environment = 'dev'): ?string
    {
        if (!str_starts_with($databaseUrl, 'sqlite://')) {
            return null;
        }

        $path = substr($databaseUrl, \strlen('sqlite://'));
        $path = explode('?', $path, 2)[0];         // drop any ?query the DSN may carry
        if (str_starts_with($path, '/')) {
            $path = substr($path, 1);              // sqlite:///<path> — the third slash is a separator
        }
        $path = str_replace(
            ['%kernel.project_dir%', '%kernel.environment%'],
            [$projectDir, $environment],
            $path,
        );

        if ($path === '' || $path === ':memory:') {
            return null;
        }

        // A path with no placeholder may still be project-relative; anchor it like the kernel would.
        if (!str_starts_with($path, '/')) {
            $path = $projectDir . '/' . $path;
        }

        return $path;
    }
}
