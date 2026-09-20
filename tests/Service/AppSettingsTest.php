<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class AppSettingsTest extends TestCase
{
    private const ENV_BACKUP_KEYS = ['GREETING', 'APP_SETTING_GREETING', 'STRIPE_SECRET_KEY'];

    /**
     * The two real MAILER_DSN shapes, with the passwords replaced. Both are in here because they
     * are genuinely different — the first has a raw @ inside the userinfo, which parse_url() gets
     * wrong, and the second percent-encodes it — and a parser that only handles one of them would
     * put the wrong From: on every email of whichever installation it did not expect (#474).
     */
    private const PROD_SHAPE_DSN = 'smtp://no-reply@app.number1tirecentre.ca:'
        . self::DSN_PASSWORD . '@app.number1tirecentre.ca:587';
    private const DEV_SHAPE_DSN = 'smtp://no-reply%40wholesale-b2b-core.dev.rp021.webhelplogin.com:'
        . self::DSN_PASSWORD . '@mail.wholesale-b2b-core.dev.rp021.webhelplogin.com:465';

    /** Fictional, and asserted never to appear anywhere the parser's output can reach. */
    private const DSN_PASSWORD = 'not-a-real-password-9f3a';

    /** @var array<string, array{env: ?string, server: ?string, getenv: ?string}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach (self::ENV_BACKUP_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        foreach ($this->savedEnv as $name => $saved) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            if ($saved['env'] !== null) {
                $_ENV[$name] = $saved['env'];
            }
            if ($saved['server'] !== null) {
                $_SERVER[$name] = $saved['server'];
            }
            if ($saved['getenv'] !== null) {
                putenv($name . '=' . $saved['getenv']);
            }
        }
        $this->savedEnv = [];
    }

    /**
     * .env.test sets MAILER_FROM so the rest of the suite can send, and MAILER_DSN is set for every
     * environment, so the From: chain is never actually empty by accident. Anything testing a tier
     * below the first has to take the tiers above it away, in all three places PHP will look.
     */
    private function setEnv(string $name, ?string $value): void
    {
        if (!\array_key_exists($name, $this->savedEnv)) {
            $existing = getenv($name);
            $this->savedEnv[$name] = [
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
                'getenv' => $existing === false ? null : (string) $existing,
            ];
        }

        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        if ($value === null) {
            return;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }

    private function makeSetting(string $key, ?string $value): AppSetting
    {
        return (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
    }

    /** @param list<AppSetting> $rows */
    private function service(array $rows): AppSettings
    {
        return $this->serviceWithConnection($rows, $this->createStub(Connection::class));
    }

    /**
     * The connection is only ever touched by the error_log breadcrumb fromAddress() leaves when it
     * cannot resolve a sender at all, so the tests that care about that pass a mock and the rest
     * get a stub they never reach.
     *
     * @param list<AppSetting> $rows
     */
    private function serviceWithConnection(array $rows, Connection $connection): AppSettings
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('getConnection')->willReturn($connection);

        return new AppSettings($em, new ArrayAdapter());
    }

    public function testAllReturnsSettingKeyValueMap(): void
    {
        $settings = $this->service([
            $this->makeSetting('site_name', 'Acme Wholesale'),
            $this->makeSetting('support_email', 'help@acme.test'),
        ]);

        self::assertSame(
            ['site_name' => 'Acme Wholesale', 'support_email' => 'help@acme.test'],
            $settings->all()
        );
    }

    public function testGetReturnsStoredValue(): void
    {
        $settings = $this->service([$this->makeSetting('site_name', 'Acme Wholesale')]);

        self::assertSame('Acme Wholesale', $settings->get('site_name'));
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $settings = $this->service([]);

        self::assertSame('fallback', $settings->get('missing_key', 'fallback'));
        self::assertNull($settings->get('missing_key'));
    }

    public function testGetReturnsDefaultWhenStoredValueIsNull(): void
    {
        $settings = $this->service([$this->makeSetting('site_name', null)]);

        self::assertSame('fallback', $settings->get('site_name', 'fallback'));
    }

    public function testHasIsTrueOnlyForExistingRow(): void
    {
        $settings = $this->service([$this->makeSetting('site_name', 'Acme')]);

        self::assertTrue($settings->has('site_name'));
        self::assertFalse($settings->has('missing_key'));
    }

    public function testGetFallsBackToEnvWhenNoDbRowExists(): void
    {
        putenv('APP_SETTING_GREETING=Hello from env');

        $settings = $this->service([]);

        self::assertSame('Hello from env', $settings->get('greeting'));
    }

    public function testBlankStoredValueFallsBackToEnv(): void
    {
        putenv('APP_SETTING_GREETING=Hello from env');

        $settings = $this->service([$this->makeSetting('greeting', '   ')]);

        self::assertSame('Hello from env', $settings->get('greeting'));
    }

    public function testResolvesEnvPlaceholderSyntaxInStoredValue(): void
    {
        putenv('GREETING=Hello there');

        $settings = $this->service([$this->makeSetting('banner', 'Message: ${GREETING} / %env(GREETING)%')]);

        self::assertSame('Message: Hello there / Hello there', $settings->get('banner'));
    }

    public function testSensitiveEnvPlaceholderIsNeverExpanded(): void
    {
        putenv('STRIPE_SECRET_KEY=sk_live_supersecret');

        $settings = $this->service([$this->makeSetting('banner', 'Key: ${STRIPE_SECRET_KEY}')]);

        self::assertSame('Key: ', $settings->get('banner'));
    }

    #[DataProvider('sensitiveEnvVarNameProvider')]
    public function testIsSensitiveEnvVarNameDetectsSecretLikeNames(string $name, bool $expected): void
    {
        self::assertSame($expected, AppSettings::isSensitiveEnvVarName($name));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function sensitiveEnvVarNameProvider(): iterable
    {
        yield 'database url' => ['DATABASE_URL', true];
        yield 'secret' => ['APP_SECRET', true];
        yield 'password' => ['DB_PASSWORD', true];
        yield 'token' => ['API_TOKEN', true];
        yield 'key' => ['STRIPE_SECRET_KEY', true];
        yield 'dsn' => ['MAILER_DSN', true];
        yield 'credential' => ['GOOGLE_CREDENTIAL', true];
        yield 'private' => ['PRIVATE_CERT', true];
        yield 'unrelated' => ['SITE_NAME', false];
        yield 'case insensitive' => ['app_secret', true];
    }

    /**
     * The whole point of #474, stated once: the four contact addresses are not senders any more.
     *
     * Every one of them is set here, with a plausible value, and none of them may appear in the
     * From: header. This is the guard that fails if a later change "restores" them to the chain
     * behind the override, which is exactly what #471 did and what #474 undid.
     */
    public function testTheContactAddressesAreNeverUsedAsTheSender(): void
    {
        $this->setEnv('MAILER_FROM', null);
        $this->setEnv('MAILER_DSN', self::PROD_SHAPE_DSN);

        $settings = $this->service([
            $this->makeSetting(AppSettings::SENDER_FROM_ADDRESS_KEY, ''),
            $this->makeSetting('sales_email', 'sales@acme.test'),
            $this->makeSetting('support_email', 'help@acme.test'),
            $this->makeSetting('tech_support_email', 'platform-ops@acme.test'),
            $this->makeSetting('app_email', 'hello@acme.test'),
        ]);

        foreach (['salesFromAddress', 'supportFromAddress', 'techSupportFromAddress'] as $category) {
            self::assertSame('no-reply@app.number1tirecentre.ca', self::resolve($settings, $category)?->getAddress());
        }
    }

    /**
     * And with the DSN gone too, they are still not consulted: the answer is "no sender", not
     * "fall back to a contact address". Removing them from the chain has to survive the chain
     * running out.
     */
    public function testTheContactAddressesAreNotEvenALastResort(): void
    {
        $this->setEnv('MAILER_FROM', null);
        $this->setEnv('MAILER_DSN', null);

        $settings = $this->service([
            $this->makeSetting('sales_email', 'sales@acme.test'),
            $this->makeSetting('support_email', 'help@acme.test'),
            $this->makeSetting('tech_support_email', 'platform-ops@acme.test'),
            $this->makeSetting('app_email', 'hello@acme.test'),
        ]);

        self::assertNull($settings->supportFromAddress());
    }

    /** Tier one. Set, it beats the environment and everything else. */
    public function testSenderFromAddressWinsOverEverySourceBelowIt(): void
    {
        $this->setEnv('MAILER_FROM', 'env@mail.acme.example');
        $this->setEnv('MAILER_DSN', self::PROD_SHAPE_DSN);

        $settings = $this->service([
            $this->makeSetting('app_name', 'Acme Wholesale'),
            $this->makeSetting(AppSettings::SENDER_FROM_ADDRESS_KEY, 'no-reply@mail.acme.example'),
            $this->makeSetting('support_email', 'help@acme.test'),
            $this->makeSetting('app_email', 'hello@acme.test'),
        ]);

        self::assertSame('no-reply@mail.acme.example', $settings->supportFromAddress()?->getAddress());
        // The display name is untouched: this overrides the address, not who the mail is from.
        self::assertSame('Acme Wholesale', $settings->supportFromAddress()?->getName());
    }

    /** Tier two. MAILER_FROM sits beside the DSN it has to agree with, so it is the default. */
    public function testMailerFromIsUsedWhenTheOverrideIsEmpty(): void
    {
        $this->setEnv('MAILER_FROM', 'env@mail.acme.example');
        $this->setEnv('MAILER_DSN', self::PROD_SHAPE_DSN);

        $settings = $this->service([$this->makeSetting(AppSettings::SENDER_FROM_ADDRESS_KEY, '')]);

        self::assertSame('env@mail.acme.example', $settings->supportFromAddress()?->getAddress());
    }

    /**
     * Tier three, for both DSN shapes actually deployed. The account SMTP signs in as is a real
     * mailbox on the sending domain, so it needs no configuration to be the right answer.
     */
    #[DataProvider('mailerDsnShapeProvider')]
    public function testTheDsnUserIsUsedWhenNothingElseIsConfigured(string $dsn, string $expected): void
    {
        $this->setEnv('MAILER_FROM', null);
        $this->setEnv('MAILER_DSN', $dsn);

        $settings = $this->service([$this->makeSetting(AppSettings::SENDER_FROM_ADDRESS_KEY, '')]);

        self::assertSame($expected, $settings->supportFromAddress()?->getAddress());
    }

    /** @return iterable<string, array{string, string}> */
    public static function mailerDsnShapeProvider(): iterable
    {
        yield 'raw @ in the userinfo, as production writes it' => [
            self::PROD_SHAPE_DSN, 'no-reply@app.number1tirecentre.ca',
        ];
        yield 'percent-encoded @, as the dev host writes it' => [
            self::DEV_SHAPE_DSN, 'no-reply@wholesale-b2b-core.dev.rp021.webhelplogin.com',
        ];
    }

    /**
     * The password is the reason this parses the DSN by hand instead of handing the string to
     * something that might log it. It must not survive into the sender, the display name, or the
     * bytes of the message that gets sent.
     */
    #[DataProvider('mailerDsnShapeProvider')]
    public function testTheDsnPasswordNeverReachesAnythingTheSenderTouches(string $dsn, string $expected): void
    {
        $this->setEnv('MAILER_FROM', null);
        $this->setEnv('MAILER_DSN', $dsn);

        $settings = $this->service([$this->makeSetting('app_name', 'Acme Wholesale')]);

        $address = $settings->supportFromAddress();
        self::assertNotNull($address);
        self::assertSame($expected, $address->getAddress());
        self::assertStringNotContainsString(self::DSN_PASSWORD, $address->getAddress());
        self::assertStringNotContainsString(self::DSN_PASSWORD, $address->getName());

        $email = $settings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to('someone@acme.test')
            ->subject('Subject')
            ->text('Body');

        self::assertStringNotContainsString(self::DSN_PASSWORD, $email->toString());
    }

    /**
     * A DSN with no address in it is common and legitimate — null://null in .env, sendmail://default
     * on a box that relays locally, an SMTP account named as a bare username. Sending mail from
     * "default" would be worse than sending it from nothing, so these produce no candidate at all.
     */
    #[DataProvider('unusableMailerDsnProvider')]
    public function testADsnWithNoPlausibleAddressIsSkipped(string $dsn): void
    {
        self::assertNull(AppSettings::mailerDsnAddress($dsn));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableMailerDsnProvider(): iterable
    {
        yield 'null transport' => ['null://null'];
        yield 'sendmail' => ['sendmail://default'];
        yield 'no credentials at all' => ['smtp://localhost:1025'];
        yield 'a bare username, not an address' => ['smtp://user:pw@host:25'];
        yield 'not a dsn' => ['no-reply@acme.test'];
        yield 'empty' => [''];
    }

    /** All three categories are the same address now, and that is the change, so it is asserted. */
    public function testAllThreeCategoriesResolveToTheSameSender(): void
    {
        $this->setEnv('MAILER_FROM', 'env@mail.acme.example');
        $this->setEnv('MAILER_DSN', self::PROD_SHAPE_DSN);

        $settings = $this->service([
            $this->makeSetting('sales_email', 'sales@acme.test'),
            $this->makeSetting('support_email', 'help@acme.test'),
            $this->makeSetting('tech_support_email', 'platform-ops@acme.test'),
        ]);

        self::assertSame('env@mail.acme.example', $settings->salesFromAddress()?->getAddress());
        self::assertSame('env@mail.acme.example', $settings->supportFromAddress()?->getAddress());
        self::assertSame('env@mail.acme.example', $settings->techSupportFromAddress()?->getAddress());
    }

    private static function resolve(AppSettings $settings, string $category): ?Address
    {
        return match ($category) {
            'salesFromAddress' => $settings->salesFromAddress(),
            'supportFromAddress' => $settings->supportFromAddress(),
            'techSupportFromAddress' => $settings->techSupportFromAddress(),
            default => throw new \InvalidArgumentException($category),
        };
    }

    /** Whitespace is not configuration — the override trims, so a spacebar does not pin the sender. */
    public function testWhitespaceOnlySenderFromAddressIsTreatedAsUnset(): void
    {
        $this->setEnv('MAILER_FROM', 'env@mail.acme.example');

        $settings = $this->service([
            $this->makeSetting(AppSettings::SENDER_FROM_ADDRESS_KEY, "  \t "),
            $this->makeSetting('support_email', 'help@acme.test'),
        ]);

        self::assertSame('env@mail.acme.example', $settings->supportFromAddress()?->getAddress());
    }

    /** A stored value with stray padding is still usable — trimmed, not rejected. */
    public function testSenderFromAddressIsTrimmedBeforeUse(): void
    {
        $settings = $this->service([
            $this->makeSetting(AppSettings::SENDER_FROM_ADDRESS_KEY, '  no-reply@mail.acme.example  '),
        ]);

        self::assertSame('no-reply@mail.acme.example', $settings->supportFromAddress()?->getAddress());
    }

    /**
     * The old chain ended in a RuntimeException, and this is the test that used to assert it.
     *
     * It asserts the opposite now. Refusing to build a password reset because nobody filled in a
     * settings box is not a safety measure, it is an outage, and there is no version of "the
     * application cannot send mail at all" that an exception thrown while composing one message
     * improves. The email is built without a From: header — genuinely without one, so a transport
     * with its own default can still supply it — and the misconfiguration is recorded in error_log
     * instead of thrown.
     */
    public function testNothingConfiguredBuildsTheEmailAnywayAndLogsIt(): void
    {
        $this->setEnv('MAILER_FROM', null);
        $this->setEnv('MAILER_DSN', null);

        $logged = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$logged): int {
                $logged = ['table' => $table, 'data' => $data];

                return 1;
            });

        $settings = $this->serviceWithConnection([], $connection);

        $email = $settings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to('someone@acme.test')
            ->subject('Subject')
            ->text('Body');

        // No exception, a real message, and no empty From: header left behind to block whatever
        // default the transport would otherwise apply.
        self::assertSame([], $email->getFrom());
        self::assertFalse($email->getHeaders()->has('From'));
        self::assertSame(['someone@acme.test'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $email->getTo(),
        ));

        self::assertSame('error_log', $logged['table']);
        self::assertSame('mailer.from', $logged['data']['area']);
        // The message has to name all three places a sender can come from, because which one to
        // set is the only useful thing it can tell whoever reads it.
        self::assertStringContainsString('sender_from_address', $logged['data']['message']);
        self::assertStringContainsString('MAILER_FROM', $logged['data']['message']);
        self::assertStringContainsString('MAILER_DSN', $logged['data']['message']);
    }

    /** One row per email, not one per recipient — the condition is a property of the message. */
    public function testTheUnresolvedSenderIsLoggedOncePerEmailNotPerRecipient(): void
    {
        $this->setEnv('MAILER_FROM', null);
        $this->setEnv('MAILER_DSN', null);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->willReturn(1);

        $settings = $this->serviceWithConnection([], $connection);

        $email = $settings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to('one@acme.test', 'two@acme.test', 'three@acme.test');

        self::assertCount(3, $email->getTo());
    }

    /** If the breadcrumb cannot be written, the email still goes. That is the whole hierarchy. */
    public function testAFailureToLogNeverStopsTheEmail(): void
    {
        $this->setEnv('MAILER_FROM', null);
        $this->setEnv('MAILER_DSN', null);

        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('database is gone'));

        $settings = $this->serviceWithConnection([], $connection);

        self::assertNull($settings->supportFromAddress());
    }

    /** Set, it is on the message, with the store's name beside it like the From: address. */
    public function testReplyToAddressIsUsedWhenConfigured(): void
    {
        $settings = $this->service([
            $this->makeSetting('app_name', 'Acme Wholesale'),
            $this->makeSetting(AppSettings::SENDER_REPLYTO_ADDRESS_KEY, 'replies@acme.example'),
        ]);

        $replyTo = $settings->replyToAddress();

        self::assertNotNull($replyTo);
        self::assertSame('replies@acme.example', $replyTo->getAddress());
        self::assertSame('Acme Wholesale', $replyTo->getName());
    }

    /**
     * Blank means absent. Not support_email, not app_email, not MAILER_FROM, not the From: address.
     * Every one of those is set here and none of them may be inferred: an operator who has not
     * asked for a Reply-To gets no Reply-To, because a header that silently points replies at a
     * mailbox nobody agreed to watch is worse than no header.
     */
    #[DataProvider('blankReplyToProvider')]
    public function testABlankReplyToInfersNothing(?string $configured): void
    {
        $this->setEnv('MAILER_FROM', 'env@mail.acme.example');

        $rows = [
            $this->makeSetting('support_email', 'help@acme.test'),
            $this->makeSetting('sales_email', 'sales@acme.test'),
            $this->makeSetting('app_email', 'hello@acme.test'),
            $this->makeSetting(AppSettings::SENDER_FROM_ADDRESS_KEY, 'no-reply@mail.acme.example'),
        ];
        if ($configured !== null) {
            $rows[] = $this->makeSetting(AppSettings::SENDER_REPLYTO_ADDRESS_KEY, $configured);
        }

        self::assertNull($this->service($rows)->replyToAddress());
    }

    /** @return iterable<string, array{string|null}> */
    public static function blankReplyToProvider(): iterable
    {
        yield 'no row at all' => [null];
        yield 'seeded empty' => [''];
        yield 'whitespace only' => ["  \t "];
        // A typo cannot be allowed to throw: this is read while a message is being composed.
        yield 'not an address' => ['support at acme dot example'];
    }

    /** applyFromAddress() is where the null lands, so both outcomes are pinned here. */
    public function testApplyFromAddressSetsTheHeaderWhenASenderResolves(): void
    {
        $settings = $this->service([
            $this->makeSetting('app_name', 'Acme Wholesale'),
            $this->makeSetting(AppSettings::SENDER_FROM_ADDRESS_KEY, 'no-reply@mail.acme.example'),
        ]);

        $email = $settings->applyFromAddress(new Email(), AppSettings::FROM_SALES);

        self::assertSame('no-reply@mail.acme.example', $email->getFrom()[0]->getAddress());
        self::assertSame('Acme Wholesale', $email->getFrom()[0]->getName());
    }

    public function testApplyFromAddressRejectsAnUnknownCategory(): void
    {
        $settings = $this->service([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown email from-category "billing"/');

        $settings->applyFromAddress(new Email(), 'billing');
    }

    /** #450: the invite/forgot-password emails used to hardcode "1 hour" no matter what this said. */
    public function testInviteTokenExpiryDescriptionUsesConfiguredDayCount(): void
    {
        $settings = $this->service([$this->makeSetting(AppSettings::INVITE_EXPIRY_KEY, '7')]);

        self::assertSame('7 days', $settings->inviteTokenExpiryDescription());
    }

    public function testInviteTokenExpiryDescriptionSingularizesOneDay(): void
    {
        $settings = $this->service([$this->makeSetting(AppSettings::INVITE_EXPIRY_KEY, '1')]);

        self::assertSame('1 day', $settings->inviteTokenExpiryDescription());
    }

    public function testInviteTokenExpiryDescriptionFallsBackToDefaultDaysWhenUnset(): void
    {
        $settings = $this->service([]);

        self::assertSame(AppSettings::INVITE_EXPIRY_DEFAULT_DAYS . ' days', $settings->inviteTokenExpiryDescription());
    }

    /** #475: the self-service window is configuration too, and reads exactly like the invite one. */
    public function testPasswordResetExpiryDescriptionUsesConfiguredHourCount(): void
    {
        $settings = $this->service([$this->makeSetting(AppSettings::PASSWORD_RESET_EXPIRY_KEY, '6')]);

        self::assertSame('6 hours', $settings->passwordResetExpiryDescription());
        self::assertSame(6, $settings->passwordResetExpiryHours());
    }

    public function testPasswordResetExpiryDescriptionSingularizesOneHour(): void
    {
        $settings = $this->service([$this->makeSetting(AppSettings::PASSWORD_RESET_EXPIRY_KEY, '1')]);

        self::assertSame('1 hour', $settings->passwordResetExpiryDescription());
    }

    public function testPasswordResetExpiryDescriptionFallsBackToDefaultHoursWhenUnset(): void
    {
        $settings = $this->service([]);

        self::assertSame(AppSettings::PASSWORD_RESET_EXPIRY_DEFAULT_HOURS . ' hour', $settings->passwordResetExpiryDescription());
    }

    public function testPasswordResetExpiresAtHonoursTheConfiguredValue(): void
    {
        $settings = $this->service([$this->makeSetting(AppSettings::PASSWORD_RESET_EXPIRY_KEY, '6')]);

        self::assertEqualsWithDelta(
            (new \DateTimeImmutable('+6 hours'))->getTimestamp(),
            $settings->passwordResetExpiresAt()->getTimestamp(),
            5,
        );
    }

    /**
     * The values an admin can actually type into the box. Each of these would otherwise cast to 0
     * or a negative int and mint a link that is already dead when it is mailed — the recipient gets
     * "this link will expire in 0 hours" and an error page, with nothing to tell them why.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidExpiryValueProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'not a number' => ['abc'];
        yield 'empty string' => [''];
        yield 'whitespace' => ["  \t "];
    }

    #[DataProvider('invalidExpiryValueProvider')]
    public function testInvalidPasswordResetExpiryFallsBackToTheDefault(string $value): void
    {
        $settings = $this->service([$this->makeSetting(AppSettings::PASSWORD_RESET_EXPIRY_KEY, $value)]);

        self::assertSame(AppSettings::PASSWORD_RESET_EXPIRY_DEFAULT_HOURS, $settings->passwordResetExpiryHours());
        self::assertSame(AppSettings::PASSWORD_RESET_EXPIRY_DEFAULT_HOURS . ' hour', $settings->passwordResetExpiryDescription());
        self::assertGreaterThan(new \DateTimeImmutable(), $settings->passwordResetExpiresAt());
    }

    #[DataProvider('invalidExpiryValueProvider')]
    public function testInvalidInviteExpiryFallsBackToTheDefault(string $value): void
    {
        $settings = $this->service([$this->makeSetting(AppSettings::INVITE_EXPIRY_KEY, $value)]);

        self::assertSame(AppSettings::INVITE_EXPIRY_DEFAULT_DAYS, $settings->inviteTokenExpiryDays());
        self::assertSame(AppSettings::INVITE_EXPIRY_DEFAULT_DAYS . ' days', $settings->inviteTokenExpiryDescription());
        self::assertGreaterThan(new \DateTimeImmutable(), $settings->inviteTokenExpiresAt());
    }

    /** Neither description may ever come back empty — an empty one renders "expire in ." (#475). */
    public function testNeitherExpiryDescriptionIsEverEmpty(): void
    {
        foreach (['0', '-5', 'abc', '', '1', '48'] as $value) {
            $settings = $this->service([
                $this->makeSetting(AppSettings::PASSWORD_RESET_EXPIRY_KEY, $value),
                $this->makeSetting(AppSettings::INVITE_EXPIRY_KEY, $value),
            ]);

            self::assertNotSame('', trim($settings->passwordResetExpiryDescription()));
            self::assertNotSame('', trim($settings->inviteTokenExpiryDescription()));
        }
    }

    public function testClearCacheForcesReReadFromRepository(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::exactly(2))
            ->method('findBy')
            ->willReturnOnConsecutiveCalls(
                [$this->makeSetting('site_name', 'Old Name')],
                [$this->makeSetting('site_name', 'New Name')]
            );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $settings = new AppSettings($em, new ArrayAdapter());

        self::assertSame('Old Name', $settings->get('site_name'));

        $settings->clearCache();

        self::assertSame('New Name', $settings->get('site_name'));
    }

    public function testTimezoneReturnsTheConfiguredIanaZone(): void
    {
        $settings = $this->service([$this->makeSetting('timezone', 'America/Vancouver')]);

        self::assertSame('America/Vancouver', $settings->timezone()->getName());
    }

    public function testTimezoneFallsBackToUtcWhenUnset(): void
    {
        $settings = $this->service([]);

        self::assertSame('UTC', $settings->timezone()->getName());
    }

    public function testTimezoneFallsBackToUtcWhenBlank(): void
    {
        $settings = $this->service([$this->makeSetting('timezone', '   ')]);

        self::assertSame('UTC', $settings->timezone()->getName());
    }

    /**
     * A typo'd or non-IANA value must not take down every page that renders a date — same
     * defensive posture as replyToAddress() for a malformed email address.
     */
    public function testTimezoneFallsBackToUtcWhenNotARealZone(): void
    {
        $settings = $this->service([$this->makeSetting('timezone', 'Not/AZone')]);

        self::assertSame('UTC', $settings->timezone()->getName());
    }

    /**
     * localDayRangeUtc() and today() are not tested here any more: they moved to BusinessDate,
     * which takes a ClockInterface, and their tests moved with them to BusinessDateTest — where a
     * frozen clock lets them assert exact dates on either side of UTC midnight rather than
     * describing the boundary from a distance. timezone() itself stays here because it genuinely
     * is configuration: the value an admin typed, with no clock involved.
     */
}
