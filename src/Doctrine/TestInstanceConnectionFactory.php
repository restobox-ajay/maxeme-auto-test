<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Serves a separate SQLite database per e2e agent, chosen by request header.
 *
 * The e2e suite wants to run several agents at once, and they cannot share one database: one
 * agent deactivating a company pulls the ground out from under another agent's test, and the
 * failure looks like an application bug. Rather than give each agent its own host, vhost or
 * deployment, one dev instance serves all of them:
 *
 *     X-Test-Key: <the shared key>      proves the caller is allowed to do this
 *     X-Test-DB:  w3-a91f               selects var/db_w3-a91f.sqlite, created on first use
 *
 * Both headers are required. A request with only one is served the normal database — half a
 * credential is not a credential, and silently honouring X-Test-DB alone would let anyone
 * browsing the dev site swap the database out from under themselves.
 *
 * WHY A CONNECTION FACTORY. The connection is built lazily, on first use, which is already
 * inside the request — so the RequestStack has the request by then. Doing it in a kernel
 * listener would mean mutating an already-open connection's parameters, which DBAL does not
 * support; doing it in a DBAL middleware is too late, as the middleware wraps a driver that has
 * already been told which file to open.
 *
 * DEV ONLY, and enforced in three independent ways rather than one:
 *   1. the factory is only registered as a decorator in config/services_dev.yaml
 *   2. this class re-checks the kernel environment itself and refuses outside dev
 *   3. it refuses when TEST_INSTANCE_KEY is unset or shorter than 16 characters
 * Any one of those failing closed is enough. A feature that can repoint the database is worth
 * three locks, because the cost of it reaching production is somebody's live data.
 */
final class TestInstanceConnectionFactory extends ConnectionFactory
{
    /**
     * Agents invent their own database names at run time (a random suffix per sandbox), so this
     * cannot be a whitelist of known names. It is a character class instead: lowercase letters,
     * digits, underscore and hyphen only, 1-40 characters.
     *
     * That is what makes the name safe to interpolate into a path. No dot, so "..", ".sqlite"
     * and "../../.env" are all unrepresentable; no slash or backslash, so no directory can be
     * escaped on either platform; no null byte, so the C-level path cannot be truncated early.
     * The check is on the WHOLE string, anchored, and a name that fails is not sanitised into
     * something acceptable — it is refused, and the normal database is served. Quietly
     * "fixing" a hostile name is how a traversal becomes a valid path to somewhere unintended.
     */
    private const SAFE_NAME = '/^[a-z0-9_-]{1,40}$/';

    /**
     * Shared with TestInstanceProvisionCommand so the name rule has exactly one definition. A
     * command that accepts a name the factory rejects would seed a database no request can ever
     * reach, and the operator would have no way to tell why.
     */
    public static function isValidInstanceName(string $name): bool
    {
        return (bool) preg_match(self::SAFE_NAME, $name);
    }

    public static function describeNameRule(): string
    {
        return 'lowercase letters, digits, underscore or hyphen; 1-40 characters '
            . '(no dot, slash or backslash, so no path can be escaped)';
    }

    /**
     * Lock 3's threshold, in one place.
     *
     * Sixteen characters is not a password-strength opinion — this key is a bearer token sent to a
     * dev site by anything that can reach it, and the number exists so that a placeholder somebody
     * typed to "switch the feature on" (test, secret, changeme) cannot switch it on. Anything
     * shorter is read as "not configured" rather than as a weak key, which is why there is no
     * warning path: the mechanism is simply off.
     */
    private const MIN_KEY_LENGTH = 16;

    /**
     * Is TEST_INSTANCE_KEY set well enough for the per-instance mechanism to work at all?
     *
     * Shared with TestInstanceProvisionCommand for the same reason isValidInstanceName() is: the
     * command used to have no opinion on the key, so with it unset the connection factory served
     * the ordinary dev database while the command cheerfully replayed migrations and seeded logins
     * into it, reported "[OK] Instance ready", and created no instance file at all. Whoever ran it
     * then had a polluted shared database and an instance that did not exist. One definition of
     * "usable key" means the command cannot do work the factory will refuse to honour.
     */
    public static function isUsableKey(?string $key): bool
    {
        return \strlen((string) $key) >= self::MIN_KEY_LENGTH;
    }

    public static function describeKeyRule(): string
    {
        return sprintf(
            'TEST_INSTANCE_KEY must be set to at least %d characters (in .env.local); '
            . 'anything shorter disables the per-instance database mechanism entirely',
            self::MIN_KEY_LENGTH
        );
    }

    /**
     * The instance a database file belongs to, or null when the path is not one of these files.
     *
     * For code that has to know whether it is looking at an isolated instance or at the shared dev
     * database, and has no request to ask — a console command, typically. Answering from the OPEN
     * CONNECTION's path rather than from getenv('TEST_INSTANCE') is the whole point: the variable
     * says what was ASKED for, and the two differ in exactly the case that matters. With the key
     * unusable this factory ignores TEST_INSTANCE and serves the shared database, so a caller that
     * trusted the variable would take its "isolated instance, safe to rearrange" branch while
     * connected to everybody's data.
     */
    public static function instanceNameForPath(?string $path): ?string
    {
        $file = basename((string) $path);
        if (!str_starts_with($file, 'db_') || !str_ends_with($file, '.sqlite')) {
            return null;
        }

        $name = substr($file, 3, -7);           // db_<name>.sqlite

        return self::isValidInstanceName($name) ? $name : null;
    }

    private const HEADER_KEY = 'X-Test-Key';
    private const HEADER_DB = 'X-Test-DB';

    /** Cookie fallback, for a client that cannot set headers on every request. */
    private const COOKIE_KEY = 'x_test_key';
    private const COOKIE_DB = 'x_test_db';

    public function __construct(
        array $typesConfig,
        private readonly RequestStack $requestStack,
        private readonly string $projectDir,
        private readonly string $environment,
        // Nullable on purpose: %env(default::TEST_INSTANCE_KEY)% resolves to NULL when the
        // variable is absent, not to an empty string. Typing this `string` made an unset key a
        // container-build fatal — which fails safe, but fails for everyone including people not
        // using this feature at all.
        ?string $instanceKey,
    ) {
        $this->instanceKey = (string) $instanceKey;
        parent::__construct($typesConfig);
    }

    private readonly string $instanceKey;

    public function createConnection(
        array $params,
        Configuration|null $config = null,
        $eventManager = null,
        array $mappingTypes = [],
    ): Connection {
        $name = $this->requestedInstance();
        if ($name !== null) {
            // Only the path is replaced. Driver, platform, types and middlewares stay exactly
            // as configured, so a test instance behaves like the real thing rather than like a
            // separately-configured database that happens to be nearby.
            unset($params['url'], $params['memory'], $params['dbname']);
            $params['driver'] = 'pdo_sqlite';
            $params['path'] = $this->projectDir . '/var/db_' . $name . '.sqlite';

            // Over HTTP, refuse an instance nobody has provisioned rather than half-creating it.
            //
            // "created on first use" was the intent, but SQLite's idea of creating a database is
            // an empty file with no tables, so the first request died on the first query and
            // left that file behind — and the provision command then saw it, said "already
            // exists — nothing done", and refused without --force. One request permanently
            // bricked the instance, and the recovery was discoverable only from the warning
            // text. Provisioning cannot happen here either: it replays the whole migration chain
            // and seeds logins, which is not something to do inside a web request.
            //
            // So: say what is wrong and what to run. The CLI path is deliberately exempt —
            // provisioning itself runs with TEST_INSTANCE set and MUST be able to create the
            // file it is about to build.
            if ($this->fromHttpRequest && !$this->instanceIsUsable($params['path'])) {
                throw new ConflictHttpException(sprintf(
                    'Test instance "%s" is not provisioned. Run: '
                    . 'php bin/console app:test-instance:provision --instance=%s',
                    $name,
                    $name
                ));
            }
        }

        return parent::createConnection($params, $config, $eventManager, $mappingTypes);
    }

    /**
     * True when the resolved instance came from an HTTP request rather than the CLI. The
     * provisioning command runs sub-processes with TEST_INSTANCE set and has to be able to
     * create the file it is about to build, so the "must already be provisioned" guard applies
     * only to requests.
     */
    private bool $fromHttpRequest = false;

    /**
     * Has this instance actually been provisioned, or is it just a file?
     *
     * Opened read-only, which does NOT create it — the whole point is not to bring another
     * empty database into existence while checking for one. A file with no tables counts as
     * unprovisioned: that is exactly what a first request used to leave behind, and treating it
     * as "exists" is what made the state unrecoverable without --force.
     */
    private function instanceIsUsable(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $tables = $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' "
                . "AND name NOT LIKE 'sqlite_%'"
            )->fetchColumn();

            return (int) $tables > 0;
        } catch (\Throwable) {
            // Unreadable or not a database. Either way it is not something to serve.
            return false;
        }
    }

    /**
     * The instance name this request asked for, or null to use the normal database.
     */
    private function requestedInstance(): ?string
    {
        $this->fromHttpRequest = false;

        // Lock 2 and 3. Checked before anything is read off the request, so a misconfigured
        // production deploy cannot be talked into this by a header.
        if ($this->environment !== 'dev' || !self::isUsableKey($this->instanceKey)) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            // CLI. The provisioning command runs the app's own console commands as
            // sub-processes and needs to point them at one instance.
            //
            // It used to pass DATABASE_URL, which does not work and fails in the worst possible
            // direction: PHP's default variables_order is "GPCS" — no E — so $_ENV is empty,
            // Symfony's Dotenv sees no existing value, and overrides the inherited one with
            // .env.local's. The sub-process then ran its schema step against the REAL dev
            // database. It aborted on the first existing table, but only by luck.
            //
            // getenv() is read directly because it is unaffected by variables_order, and
            // TEST_INSTANCE is not declared in any .env file, so Dotenv has nothing to override
            // it with.
            $cli = (string) (getenv('TEST_INSTANCE') ?: '');

            return ($cli !== '' && preg_match(self::SAFE_NAME, $cli)) ? $cli : null;
        }

        // Header wins over cookie, both for the key and the name, and they are read as one pair
        // per source. Taking the key from a header and the name from a cookie would let a
        // planted cookie redirect an otherwise legitimate request.
        $key = (string) $request->headers->get(self::HEADER_KEY, '');
        $name = (string) $request->headers->get(self::HEADER_DB, '');

        // Fall back to cookies only when NEITHER header is present. The old condition was "or",
        // which overwrote a header that WAS supplied with an empty cookie whenever its partner
        // was missing — so a request carrying only X-Test-Key collapsed to "neither supplied"
        // and was served the shared database with a 200, which is the very case the both-or-
        // neither rule exists to catch.
        if ($key === '' && $name === '') {
            $key = (string) $request->cookies->get(self::COOKIE_KEY, '');
            $name = (string) $request->cookies->get(self::COOKIE_DB, '');
        }

        // Neither supplied: an ordinary request for the ordinary database. Nothing to report.
        if ($key === '' && $name === '') {
            return null;
        }

        // From here the caller is ASKING for an instance, so a refusal is an error and not a
        // silent fallback. Returning null here served the shared dev database with a 200, and
        // the caller could not tell "isolated" from "you are now writing to everybody's data" —
        // the exact failure this class exists to prevent, one level down. An agent that
        // mistypes its key would quietly corrupt the database every other agent is using.
        if ($key === '' || $name === '') {
            throw new BadRequestHttpException(sprintf(
                'Both %s and %s are required, or neither.',
                self::HEADER_KEY,
                self::HEADER_DB
            ));
        }
        // hash_equals: a plain comparison leaks the key's length and matching prefix through
        // timing, and this key is sent by anything that can reach the dev site.
        if (!hash_equals($this->instanceKey, $key)) {
            throw new AccessDeniedHttpException(sprintf('%s is not valid.', self::HEADER_KEY));
        }
        if (!preg_match(self::SAFE_NAME, $name)) {
            throw new BadRequestHttpException(sprintf(
                '%s must be %s.',
                self::HEADER_DB,
                self::describeNameRule()
            ));
        }

        $this->fromHttpRequest = true;

        return $name;
    }
}
