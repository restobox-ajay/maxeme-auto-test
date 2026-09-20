<?php

namespace App\Service;

use App\Entity\AppSetting;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AppSettings
{
    /**
     * The single entry holding the whole app_setting table. Public because
     * AppSettingCacheInvalidationSubscriber deletes it too (#442) and has to name the same key:
     * that listener deliberately injects the cache pool rather than this service, since this
     * service depends on the EntityManager and pulling it back into a Doctrine listener is how
     * you get a dependency cycle. One shared const is the seam between them.
     */
    public const CACHE_KEY_ALL = 'app_settings.all.v1';

    private const ENV_PREFIX = 'APP_SETTING_';

    /** Lifetime of invite / admin-issued reset links — see inviteTokenExpiresAt(). */
    public const INVITE_EXPIRY_KEY = 'invite_token_expiry_days';
    public const INVITE_EXPIRY_DEFAULT_DAYS = 30;

    /** Lifetime of self-service "forgot password" links — see passwordResetExpiresAt(). */
    public const PASSWORD_RESET_EXPIRY_KEY = 'password_reset_expiry_hours';
    public const PASSWORD_RESET_EXPIRY_DEFAULT_HOURS = 1;

    /**
     * The store-wide From: header for every outbound email, and the first of the three sources
     * fromAddress() consults. Empty (the seeded default) means "not configured", which since #474
     * lands on MAILER_FROM rather than on any of the contact addresses.
     */
    public const SENDER_FROM_ADDRESS_KEY = 'sender_from_address';

    /**
     * The Reply-To header for every outbound email, and nothing else — see replyToAddress().
     * Seeded empty, and empty means the header is simply not sent (#474).
     */
    public const SENDER_REPLYTO_ADDRESS_KEY = 'sender_replyto_address';

    /** Categories for applyFromAddress() — which kind of mail is being sent, not which address. */
    public const FROM_SALES = 'sales';
    public const FROM_SUPPORT = 'support';
    public const FROM_TECH_SUPPORT = 'tech_support';

    /** The IANA zone datetimes are displayed in — see timezone(). Storage is always UTC. */
    public const TIMEZONE_KEY = 'timezone';
    public const TIMEZONE_DEFAULT = 'UTC';

    /**
     * Env var names matching this pattern (or DATABASE_URL) can never be expanded via the
     * ${VAR} / %env(VAR)% placeholder syntax in a setting value, even though any admin can type
     * that syntax into any setting. Without this, a setting value that's ever rendered anywhere
     * (site content, email templates, ...) could be used to exfiltrate STRIPE_SECRET_KEY,
     * APP_SECRET, MAILER_DSN credentials, etc. to whoever can view that setting's output.
     */
    private const SENSITIVE_ENV_NAME_PATTERN = '/SECRET|PASSWORD|PASS|TOKEN|KEY|DSN|CREDENTIAL|PRIVATE/i';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        /** @var array<string, string|null> $settings */
        $settings = $this->cache->get(self::CACHE_KEY_ALL, function (ItemInterface $item): array {
            // ORM writes invalidate this entry as they happen (AppSettingCacheInvalidationSubscriber,
            // #442), so the TTL is no longer what makes a saved setting take effect. It stays as a
            // backstop for the writes no Doctrine listener can see: the admin SQL console, doctrine
            // migrations, and anyone editing var/data.db with the sqlite3 CLI. Until those are
            // handled explicitly, an hour is the longest such a change can stay invisible.
            $item->expiresAfter(3600);

            try {
                /** @var list<AppSetting> $rows */
                $rows = $this->entityManager->getRepository(AppSetting::class)
                    ->findBy([], ['id' => 'ASC']);
            } catch (DbalException) {
                // A settings table that cannot be read yet is a real state, not an error, and the
                // right answer is "nothing is configured" rather than a fatal.
                //
                // Settings are read while a console command boots, because DisplayTimezoneSubscriber
                // listens on console.command — and the commands that boot include the ones that
                // BUILD the schema. doctrine:schema:create on a fresh checkout runs against a
                // database with no app_setting table at all; doctrine:migrations:migrate runs
                // against one whose app_setting is mid-chain and does not yet have every column the
                // current entity maps, which is a different query error for the same reason. Either
                // one taking down the command makes the schema unbuildable from empty.
                //
                // Hence the DBAL base class rather than TableNotFoundException specifically: what
                // matters is that the read failed at the database, not which shape the mismatch
                // took. It stays a DBAL exception and not \Throwable so a genuine bug in the mapping
                // or this method still surfaces.
                //
                // Not cached: expiresAfter(0) drops the entry immediately, so the next read once the
                // table is readable goes to the database rather than being answered from an empty
                // array for the next hour.
                $item->expiresAfter(0);

                return [];
            }

            $out = [];
            foreach ($rows as $row) {
                $out[$row->getSettingKey()] = $row->getSettingValue();
            }

            return $out;
        });

        // Resolve env placeholders at read time (keeps the DB/UI value unchanged).
        foreach ($settings as $k => $v) {
            $settings[$k] = $this->resolveValue($k, $v);
        }

        return $settings;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->all());
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();

        if (\array_key_exists($key, $all)) {
            return $all[$key] ?? $default;
        }

        // Allow env-only settings without a DB row.
        $env = $this->envForKey($key);
        if ($env !== null && $env !== '') {
            return $env;
        }

        return $default;
    }

    /**
     * The public-facing name of this store — for page titles, headers, emails and email subjects.
     * Prefers app_name (set from "Company Name" on the branding page), then the company_name setting,
     * then a neutral fallback so nothing ever renders a leftover brand name. See issue #118.
     */
    public function siteName(): string
    {
        $appName = trim((string) ($this->get('app_name') ?? ''));
        if ($appName !== '') {
            return $appName;
        }

        $companyName = trim((string) ($this->get('company_name') ?? ''));

        return $companyName !== '' ? $companyName : 'Catalog';
    }

    /**
     * The IANA zone (e.g. "America/Vancouver", "Asia/Kolkata") datetimes should be DISPLAYED in.
     *
     * Storage is always UTC — nothing about this setting affects what gets written; it exists
     * purely for DisplayTimezoneSubscriber to apply to Twig's date filter, and for BusinessDate,
     * which answers "what day is it here" and "where does this day start and end in UTC" on top of
     * it, so a changed setting takes effect everywhere at once with no template or query changes.
     *
     * This is the one part of the shop's day that really is configuration, which is why it stayed
     * here when today() and localDayRangeUtc() moved out to BusinessDate: it is a value an admin
     * typed, not a reading of a clock.
     *
     * A real IANA identifier on purpose, not a fixed offset: an offset would be wrong half the
     * year for any zone that observes DST. Falls back to UTC for a blank or garbage value (a
     * typo'd zone name) rather than 500ing every page that renders a date — this is read while
     * building a response, the same defensive posture as replyToAddress().
     */
    public function timezone(): \DateTimeZone
    {
        $name = trim((string) ($this->get(self::TIMEZONE_KEY) ?? ''));
        if ($name === '') {
            return new \DateTimeZone(self::TIMEZONE_DEFAULT);
        }

        try {
            return new \DateTimeZone($name);
        } catch (\Throwable) {
            return new \DateTimeZone(self::TIMEZONE_DEFAULT);
        }
    }

    /**
     * The From: address+name for order and quote emails — checkout, order status, invoices,
     * quote lifecycle. Resolves through the one chain every category now shares — see
     * fromAddress() — paired with siteName() as the display name.
     *
     * Kept as its own method although all three resolve identically since #474: the call sites are
     * the only remaining record of which category an email belongs to, and that distinction may be
     * wanted again. Null when nothing at all is configured; callers must not set a From: in that
     * case rather than inventing one.
     */
    public function salesFromAddress(): ?Address
    {
        return $this->fromAddress();
    }

    /**
     * The From: address+name for every other transactional email — auth, account changes,
     * registration, contact form, system/inventory alerts. Same chain as salesFromAddress().
     */
    public function supportFromAddress(): ?Address
    {
        return $this->fromAddress();
    }

    /**
     * The From: address+name for system/security notifications aimed at whoever operates this
     * SaaS instance (e.g. "the database console was opened"). tech_support_email remains the
     * *recipient* of those alerts and is still deliberately distinct from support_email (#351);
     * what changed in #474 is only that it no longer doubles as the sender.
     */
    public function techSupportFromAddress(): ?Address
    {
        return $this->fromAddress();
    }

    /**
     * Sets the From: header on $email, or leaves it off entirely when nothing resolves (#474).
     *
     * Every sending site goes through this rather than calling ->from() with a resolver's return
     * value, because that value can now legitimately be null and Email::from() cannot be handed
     * one. Calling ->from() with no arguments is not the same thing: it adds an empty From: header,
     * which is still a header, and a present-but-empty From: would suppress any default the mailer
     * is configured to supply. The header has to be genuinely absent, so the decision lives here
     * rather than being repeated at sixteen call sites, one of which would eventually get it wrong.
     *
     * The category is still named at every call site even though all three resolve identically
     * today. It is the only record of which kind of mail each site sends, and the distinction is
     * one we may want back.
     */
    public function applyFromAddress(Email $email, string $category): Email
    {
        $from = match ($category) {
            self::FROM_SALES => $this->salesFromAddress(),
            self::FROM_SUPPORT => $this->supportFromAddress(),
            self::FROM_TECH_SUPPORT => $this->techSupportFromAddress(),
            default => throw new \InvalidArgumentException(sprintf('Unknown email from-category "%s".', $category)),
        };

        return $from === null ? $email : $email->from($from);
    }

    /**
     * The Reply-To header for outbound email, or null for "send no Reply-To at all" (#474).
     *
     * Deliberately not a chain. Once the From: is a no-reply@ on the sending domain, a customer
     * hitting reply gets a black hole, and the obvious repair is to point replies at
     * support_email. That is the same conflation #471 and #474 spent their time undoing: an
     * address published as "write to us here" is not necessarily an address that should collect
     * automated replies, and quietly routing mail somewhere the operator did not ask for is worse
     * than sending no header. So blank means absent, and nothing is inferred.
     *
     * SenderReplyToSubscriber applies this to every outbound message. A message that already
     * carries a Reply-To — the contact form replies to whoever filled it in — keeps its own.
     *
     * An unparseable value is treated as unset rather than allowed to throw: this is read while a
     * message is being built, at which point a typo in a settings box must not be able to stop
     * every email in the application.
     */
    public function replyToAddress(): ?Address
    {
        $email = trim((string) $this->get(self::SENDER_REPLYTO_ADDRESS_KEY, ''));
        if ($email === '' || !self::isPlausibleEmailAddress($email)) {
            return null;
        }

        return new Address($email, $this->siteName());
    }

    /**
     * When an invite (or an admin-triggered password reset) sent right now should stop working.
     *
     * These links were all hardcoded to +1 hour, the same lifetime as a self-service "I forgot my
     * password" link. That is the wrong comparison. A forgot-password link is requested by the
     * person reading it, who is sitting at their inbox waiting — an hour is generous. An invite is
     * unsolicited: it lands on someone who was not expecting it, who may not open mail that day,
     * and who cannot mint a replacement themselves (admin_account_setup only validates a token that
     * already exists). Once it lapses an admin has to notice and re-send, which is a support ticket
     * for something that should have just worked.
     *
     * The two self-service forgot-password paths (Admin\AuthController, Customer\AuthController)
     * deliberately keep the much shorter window and call passwordResetExpiresAt() instead.
     *
     * Configurable via the invite_token_expiry_days setting, seeded to 30. A non-numeric, zero or
     * negative value falls back to the default rather than producing a link that is already expired
     * the moment it is mailed — an admin who types "thirty" into the box should get a working
     * invite, not a silently dead one.
     */
    public function inviteTokenExpiresAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d days', $this->inviteTokenExpiryDays()));
    }

    public function inviteTokenExpiryDays(): int
    {
        $days = (int) trim((string) ($this->get(self::INVITE_EXPIRY_KEY) ?? ''));

        return $days > 0 ? $days : self::INVITE_EXPIRY_DEFAULT_DAYS;
    }

    /**
     * Human-readable lifetime for invite / admin-issued reset link emails — "1 day" or "N days".
     *
     * Exists because invite.html.twig and forgot_password.html.twig used to hardcode "1 hour"
     * regardless of what inviteTokenExpiresAt() actually granted (#450): an admin could set the
     * expiry to 30 days and every invite email would still tell the recipient they had an hour.
     * Callers that render a token from inviteTokenExpiresAt() should pass this as the template's
     * expiry_description so the copy always matches the real deadline.
     */
    public function inviteTokenExpiryDescription(): string
    {
        $days = $this->inviteTokenExpiryDays();

        return $days === 1 ? '1 day' : sprintf('%d days', $days);
    }

    /**
     * When a self-service "I forgot my password" link minted right now should stop working.
     *
     * Kept separate from inviteTokenExpiresAt() on purpose, and deliberately much shorter: this is
     * the only link an anonymous visitor can request for an arbitrary email address, so its window
     * is the one piece of this that is a security parameter rather than a convenience one. The
     * person reading it asked for it seconds ago and is sitting at their inbox, so an hour costs
     * them nothing.
     *
     * What changed in #475 is that the hour stopped being a literal. Both call sites
     * (Admin\AuthController, Customer\AuthController) hardcoded modify('+1 hour'), which is how the
     * copy and the real deadline were free to drift — and did, once the DB email_template rows kept
     * saying "1 hour" for admin-issued resets that actually lasted 30 days.
     *
     * Same defensive handling as the invite setting: a non-numeric, zero or negative value falls
     * back to the default rather than minting a link that is already expired when it is mailed.
     */
    public function passwordResetExpiresAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d hours', $this->passwordResetExpiryHours()));
    }

    public function passwordResetExpiryHours(): int
    {
        $hours = (int) trim((string) ($this->get(self::PASSWORD_RESET_EXPIRY_KEY) ?? ''));

        return $hours > 0 ? $hours : self::PASSWORD_RESET_EXPIRY_DEFAULT_HOURS;
    }

    /**
     * Human-readable lifetime for self-service reset emails — "1 hour" or "N hours".
     *
     * The counterpart to inviteTokenExpiryDescription(), and the reason forgot_password no longer
     * needs to know which flow rendered it: one email_template row serves both the self-service and
     * the admin-issued reset, with different lifetimes, so the row can only be correct if the
     * duration arrives as expiry_description rather than being written into the body.
     */
    public function passwordResetExpiryDescription(): string
    {
        $hours = $this->passwordResetExpiryHours();

        return $hours === 1 ? '1 hour' : sprintf('%d hours', $hours);
    }

    /**
     * The single resolution behind all three From: addresses (#474):
     *
     *     sender_from_address -> MAILER_FROM env -> the user portion of MAILER_DSN
     *
     * and nothing else. sales_email, support_email, tech_support_email and app_email are no longer
     * consulted for the sender at all.
     *
     * They used to be, and that was the bug. Those four are *contact* addresses: what a customer is
     * told to write to, and where some mail is delivered. They are routinely on a domain this
     * installation does not send from — production had support_email on gmail.com — so using one as
     * the From: header put a gmail.com sender on mail the SMTP session authenticated to send as
     * no-reply@app.number1tirecentre.ca. gmail.com publishes a strict DMARC policy, that mail does
     * not align, and it gets junked or rejected no matter how well the sending host is set up.
     * Reordering the chain would not have helped: as long as a contact address can reach the From:
     * header, editing "Support Email" can silently break deliverability, which is not a trade an
     * admin is in any position to see coming.
     *
     * What is left is three sources that all describe the sending identity rather than the store's
     * public inboxes. MAILER_FROM sits beside the DSN it has to agree with, so it is the default
     * rather than a last resort. The DSN's own user is the final source and needs no configuration
     * at all: SMTP authenticates as a real mailbox on the sending domain, which by construction is
     * the address most likely to pass SPF.
     *
     * There is deliberately no exception at the end. The old code threw, on the reasoning that
     * sending from a placeholder domain is worse than not sending — but with the DSN in the chain
     * the premise is gone, and refusing to send is never the better answer for a password reset or
     * an invite. If all three really are absent the caller gets null, sets no From: header, and the
     * transport applies whatever sender it has; the condition is recorded in error_log so a
     * misconfigured install is visible instead of silent.
     */
    private function fromAddress(): ?Address
    {
        // Every candidate is validated before it reaches Address, because Address does not fail
        // softly: its constructor throws RfcComplianceException on anything that is not an address
        // and InvalidArgumentException on an embedded newline. This method has 16 call sites and
        // none of them catches, so an unusable value here does not degrade one email — it takes
        // down every outbound message in the application, including password resets and order
        // confirmations, as an uncaught 500.
        //
        // The blank check that used to be the only guard is not enough. A newline is the shape a
        // header-injection probe arrives in and it throws a *different* exception than a plain
        // typo, so neither the malicious nor the accidental case was covered. replyToAddress()
        // has validated with the same helper since it was written; only this side was missing it.
        //
        // An invalid candidate is skipped rather than fatal, so resolution falls through to the
        // next source and mail keeps flowing on a misconfiguration. Falling through silently is
        // the point: a wrong From: is a deliverability problem, a thrown one is an outage.
        $override = trim((string) $this->get(self::SENDER_FROM_ADDRESS_KEY, ''));
        if ($override !== '' && self::isPlausibleEmailAddress($override)) {
            return new Address($override, $this->siteName());
        }

        $env = trim((string) ($_ENV['MAILER_FROM'] ?? $_SERVER['MAILER_FROM'] ?? getenv('MAILER_FROM') ?: ''));
        if ($env !== '' && self::isPlausibleEmailAddress($env)) {
            return new Address($env, $this->siteName());
        }

        // Derived from a DSN rather than typed by an admin, but it is still a string from
        // configuration and mailerDsnAddress() only extracts a user portion — it does not promise
        // the result is an address.
        $dsnUser = self::mailerDsnAddress(
            (string) ($_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: '')
        );
        if ($dsnUser !== null && self::isPlausibleEmailAddress($dsnUser)) {
            return new Address($dsnUser, $this->siteName());
        }

        $this->logUnresolvedSender();

        return null;
    }

    /**
     * The email address a MAILER_DSN authenticates as, or null if it does not carry a usable one.
     *
     * parse_url() is not good enough here, because the two DSNs actually in use are shaped
     * differently and one of them is not a valid URL at all:
     *
     *     smtp://no-reply@app.example.ca:PASSWORD@app.example.ca:587      (raw @ in the userinfo)
     *     smtp://no-reply%40mail.example.com:PASSWORD@mail.example.com:465 (percent-encoded)
     *
     * The first has a bare @ inside the userinfo, which parse_url() splits on in the wrong place
     * and hands back a host of "app.example.ca:PASSWORD@app.example.ca". Both forms are out there
     * and neither is going to be normalised by us, so this splits by hand: everything between the
     * scheme separator and the LAST @ is the userinfo, the first : after that is where the password
     * starts, and the remainder is percent-decoded.
     *
     * Only the user portion is ever returned, and the password is never returned, logged, or put in
     * a message anywhere — that is why this returns a string rather than the parsed DSN.
     *
     * A value that does not look like an email address is discarded rather than used: plenty of
     * legitimate DSNs (null://null, sendmail://default, an SMTP account named as a bare username)
     * have no address in them, and sending mail from "default" is worse than sending none.
     */
    public static function mailerDsnAddress(string $dsn): ?string
    {
        $dsn = trim($dsn);
        $separator = strpos($dsn, '://');
        if ($separator === false) {
            return null;
        }

        // Stop at the first path/query character so a password containing one cannot drag the
        // host, or anything after it, into the search for the last @.
        $authority = substr($dsn, $separator + 3);
        $authority = preg_split('~[/?\#]~', $authority)[0] ?? '';

        $lastAt = strrpos($authority, '@');
        if ($lastAt === false) {
            return null;
        }

        $userinfo = substr($authority, 0, $lastAt);
        $colon = strpos($userinfo, ':');
        $user = $colon === false ? $userinfo : substr($userinfo, 0, $colon);
        $user = rawurldecode($user);

        return self::isPlausibleEmailAddress($user) ? $user : null;
    }

    /**
     * Records that an outbound email had to be built with no From: header.
     *
     * Effectively unreachable — both real environments set MAILER_FROM and have a usable DSN — so
     * this is a breadcrumb for a misconfigured install, not a hot path. It is deliberately the only
     * trace the condition leaves: fromAddress() must not throw, and a caller that has already
     * decided to send an email is not the right place to surface a settings problem.
     *
     * Written straight through the connection rather than by persisting an ErrorLog entity, which
     * is how ErrorLogSubscriber does it, because this runs in the middle of building a message: a
     * flush() here would commit whatever half-finished work the calling controller happens to have
     * in the unit of work — an order mid-save, say — as a side effect of writing a log line. Same
     * table, same rows at /admin/error-log, none of the collateral. Any failure is swallowed, since
     * the entire point of the change is that the email still goes out.
     */
    private function logUnresolvedSender(): void
    {
        try {
            $this->entityManager->getConnection()->insert('error_log', [
                'level' => 'error',
                'area' => 'mailer.from',
                'message' => 'No From: address could be resolved, so an email was sent without one. '
                    . 'Set the "sender_from_address" setting, the MAILER_FROM environment variable, '
                    . 'or a user portion on MAILER_DSN.',
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // A diagnostic must never be the reason mail stops going out.
        }
    }

    /**
     * Deliberately FILTER_VALIDATE_EMAIL and not Symfony's Address, which throws: everything
     * checked here comes from a settings box or a DSN, and the answer to a bad value is to ignore
     * it, not to take the application down while an email is being composed.
     */
    private static function isPlausibleEmailAddress(string $candidate): bool
    {
        return filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Rarely needed by application code since #442 — AppSettingCacheInvalidationSubscriber clears
     * this automatically on any ORM insert/update/delete of an AppSetting. Still public, and still
     * called from the existing write paths, because it is idempotent and because it remains the
     * only way to invalidate after a write the ORM never saw (raw SQL, a fixture loaded out of
     * band, a test that manipulates the table directly).
     */
    public function clearCache(): void
    {
        $this->cache->delete(self::CACHE_KEY_ALL);
    }

    /** True if this env var name must never be exposed via a setting's ${VAR}/%env(VAR)% placeholder. */
    public static function isSensitiveEnvVarName(string $name): bool
    {
        return $name === 'DATABASE_URL' || preg_match(self::SENSITIVE_ENV_NAME_PATTERN, $name) === 1;
    }

    private function resolveValue(string $key, ?string $value): ?string
    {
        // If the value is blank, allow env override.
        if ($value === null || trim($value) === '') {
            $env = $this->envForKey($key);
            if ($env !== null && $env !== '') {
                return $env;
            }

            return $value;
        }

        // Support ${ENV_VAR} and %env(ENV_VAR)% placeholders — but never for sensitive-looking
        // names (see SENSITIVE_ENV_NAME_PATTERN): a setting value is admin-editable free text
        // that can end up rendered anywhere, so it must not be able to pull out secrets.
        $value = preg_replace_callback('/\\$\\{([A-Z0-9_]+)\\}/', [self::class, 'resolveEnvPlaceholder'], $value) ?? $value;
        $value = preg_replace_callback('/%env\\(([A-Z0-9_]+)\\)%/', [self::class, 'resolveEnvPlaceholder'], $value) ?? $value;

        return $value;
    }

    /** @param array{0: string, 1: string} $m */
    private static function resolveEnvPlaceholder(array $m): string
    {
        $name = $m[1];
        if (self::isSensitiveEnvVarName($name)) {
            return '';
        }

        $env = getenv($name);
        if ($env === false) {
            $env = $_ENV[$name] ?? $_SERVER[$name] ?? '';
        }

        return (string) $env;
    }

    private function envForKey(string $key): ?string
    {
        $normalized = strtoupper(preg_replace('/[^a-z0-9_]+/i', '_', $key) ?? '');
        $normalized = trim($normalized, '_');

        foreach ([self::ENV_PREFIX . $normalized, $normalized] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                return (string) $value;
            }

            if (isset($_ENV[$name])) {
                return (string) $_ENV[$name];
            }

            if (isset($_SERVER[$name])) {
                return (string) $_SERVER[$name];
            }
        }

        return null;
    }
}
