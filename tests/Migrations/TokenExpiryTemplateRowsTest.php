<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\Tests\DoctrineIntegrationTestCase;
use App\Twig\SandboxedTemplateRenderer;
use PHPUnit\Framework\Attributes\Group;

/**
 * The email_template rows a real database carries must print {{ expiry_description }}, not "1 hour".
 *
 * This is the coverage gap that let #475 ship. #450 fixed templates/emails/*.twig and every test it
 * added asserted against those files — but the send paths render the file and then, whenever a row
 * exists for the code, throw that render away and re-render the row instead. Production has all
 * three rows, so production kept mailing "expire in 1 hour" for admin-issued resets that really
 * lasted 30 days, with a full green suite.
 *
 * Both suites build their schema from entity mappings rather than the chain, so email_template is
 * empty in the test database and a test reading it there would pass whether or not the migration
 * exists. The rows therefore come from a real replay into a throwaway SQLite file, the same way
 * EmailTemplateHardcodesFixedTest gets them.
 *
 * The rendering half matters just as much: DB bodies go through SandboxedTemplateRenderer, and a
 * variable the sandbox refuses fails SILENTLY — an empty string where the duration should be, which
 * is indistinguishable from the bug being fixed. So the real row bodies are rendered here, and the
 * output has to contain the duration that was passed in.
 */
#[Group('migrations')]
final class TokenExpiryTemplateRowsTest extends DoctrineIntegrationTestCase
{
    private const CODES = ['forgot_password', 'invite', 'new_user_invited'];

    private static ?string $databaseFile = null;

    /** @var array<string, string>|null */
    private static ?array $bodies = null;

    /** @var array<string, array<string, string|null>>|null */
    private static ?array $settings = null;

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$databaseFile, self::$databaseFile . '-wal', self::$databaseFile . '-shm'] as $path) {
            if (\is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        self::$databaseFile = null;
        self::$bodies = null;
        self::$settings = null;
    }

    private function replayChain(): void
    {
        if (self::$bodies !== null) {
            return;
        }

        $projectDir = \dirname(__DIR__, 2);
        self::$databaseFile = sys_get_temp_dir() . '/token-expiry-templates-' . getmypid() . '.sqlite';
        @unlink(self::$databaseFile);

        $env = [
            'APP_ENV' => 'test',
            'DATABASE_URL' => 'sqlite:///' . self::$databaseFile,
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
        ];

        $prefix = '';
        foreach ($env as $key => $value) {
            $prefix .= sprintf('%s=%s ', $key, escapeshellarg($value));
        }

        $output = [];
        $status = 0;
        exec(
            sprintf('cd %s && %sphp bin/console doctrine:migrations:migrate --no-interaction -q 2>&1', escapeshellarg($projectDir), $prefix),
            $output,
            $status,
        );

        self::assertSame(0, $status, "The migration chain does not replay from empty:\n" . implode("\n", $output));

        $pdo = new \PDO('sqlite:' . self::$databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $bodies = [];
        foreach (self::CODES as $code) {
            $stmt = $pdo->prepare('SELECT body FROM email_template WHERE code = :code');
            $stmt->execute(['code' => $code]);
            $body = $stmt->fetchColumn();
            self::assertIsString($body, "email_template has no row for code \"$code\" — has it been renamed or dropped?");
            $bodies[$code] = $body;
        }

        $stmt = $pdo->prepare('SELECT setting_key, setting_value, description, category, visibility FROM app_setting WHERE setting_key IN (:a, :b)');
        $stmt->execute(['a' => AppSettings::PASSWORD_RESET_EXPIRY_KEY, 'b' => AppSettings::INVITE_EXPIRY_KEY]);

        $settings = [];
        /** @var array<string, string|null> $row */
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $settings[(string) $row['setting_key']] = $row;
        }

        self::$bodies = $bodies;
        self::$settings = $settings;
    }

    /** @return array<string, string> */
    private function bodies(): array
    {
        $this->replayChain();

        return self::$bodies ?? [];
    }

    public function testEveryRowPrintsTheVariableInsteadOfALiteralDuration(): void
    {
        foreach ($this->bodies() as $code => $body) {
            self::assertStringContainsString(
                '{{ expiry_description }}',
                $body,
                sprintf('The %s row still writes the duration into the body instead of printing the caller\'s.', $code),
            );
            self::assertStringNotContainsString(
                '1 hour',
                $body,
                sprintf('The %s row still hardcodes "1 hour".', $code),
            );
        }
    }

    /**
     * forgot_password is the row that proves the design: one row, two flows, two lifetimes. It can
     * only be correct for both if it names neither.
     */
    public function testForgotPasswordRowNamesNoDurationAtAll(): void
    {
        $body = $this->bodies()['forgot_password'];

        self::assertStringContainsString('this link will expire in {{ expiry_description }}.', $body);
        self::assertDoesNotMatchRegularExpression('/expire in \d+ (hour|day)/', $body);
    }

    /** The targeted rewrite must not have disturbed anything else in the rows it touched. */
    public function testTheRewriteLeftTheRestOfEachRowAlone(): void
    {
        $bodies = $this->bodies();

        foreach (self::CODES as $code) {
            self::assertStringContainsString("{{ user_email|default('User') }}", $bodies[$code]);
            self::assertStringContainsString("{{ reset_url|default('#') }}", $bodies[$code]);
            self::assertStringContainsString("{% extends 'emails/layout.html.twig' %}", $bodies[$code]);
        }
    }

    public function testPasswordResetExpiryHoursIsSeededAsAnEditableStoreSetting(): void
    {
        $this->replayChain();

        $row = (self::$settings ?? [])[AppSettings::PASSWORD_RESET_EXPIRY_KEY] ?? null;

        self::assertIsArray($row, 'password_reset_expiry_hours has no app_setting row, so it is invisible at /admin/settings.');
        self::assertSame((string) AppSettings::PASSWORD_RESET_EXPIRY_DEFAULT_HOURS, (string) $row['setting_value']);
        self::assertSame('General', (string) $row['category']);
        self::assertSame(AppSetting::VISIBILITY_STORE, (string) $row['visibility']);
        self::assertStringContainsString('anonymous visitor', (string) $row['description']);
    }

    /**
     * The invite setting's own description used to promise that self-service links "always expire
     * after 1 hour". That is now a setting, so the sentence is a lie the admin reads while editing
     * the very screen that contradicts it.
     */
    public function testTheInviteSettingDescriptionNoLongerPromisesAFixedHour(): void
    {
        $this->replayChain();

        $description = (string) ((self::$settings ?? [])[AppSettings::INVITE_EXPIRY_KEY]['description'] ?? '');

        self::assertNotSame('', $description);
        self::assertStringNotContainsString('always expire after 1 hour', $description);
        self::assertStringContainsString('Password Reset Link Expiry (Hours)', $description);
    }

    /**
     * The silent-failure check. UserTemplateSecurityPolicy restricts what an admin-authored body
     * may do, and a refused construct throws where EmailNotifier and the auth controllers swallow
     * it — which is how #379/#381 shipped blank emails. Rendering the real rows under the real
     * container's sandbox is the only thing that proves expiry_description arrives.
     */
    public function testTheRealRowsRenderTheSuppliedDurationUnderTheSandbox(): void
    {
        $renderer = self::getContainer()->get(SandboxedTemplateRenderer::class);
        self::assertInstanceOf(SandboxedTemplateRenderer::class, $renderer);

        foreach ($this->bodies() as $code => $body) {
            $html = $renderer->render($body, [
                'user_email' => 'recipient@example.test',
                'reset_url' => 'https://example.test/reset?token=abc',
                'expiry_description' => '30 days',
            ]);

            self::assertStringContainsString('30 days', $html, sprintf('The %s row rendered without the duration it was given.', $code));
            self::assertStringNotContainsString('expire in .', $html, sprintf('The %s row rendered an empty duration.', $code));
        }
    }

    /** A different value has to come out differently, or the assertion above proves nothing. */
    public function testTheRenderedDurationFollowsWhateverTheCallerSupplies(): void
    {
        $renderer = self::getContainer()->get(SandboxedTemplateRenderer::class);
        self::assertInstanceOf(SandboxedTemplateRenderer::class, $renderer);

        $html = $renderer->render($this->bodies()['forgot_password'], [
            'user_email' => 'recipient@example.test',
            'reset_url' => 'https://example.test/reset?token=abc',
            'expiry_description' => '6 hours',
        ]);

        self::assertStringContainsString('this link will expire in 6 hours.', $html);
    }
}
