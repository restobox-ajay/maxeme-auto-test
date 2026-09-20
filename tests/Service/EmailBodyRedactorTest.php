<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EmailBodyRedactor;
use App\Service\ResetTokenService;
use PHPUnit\Framework\TestCase;

final class EmailBodyRedactorTest extends TestCase
{
    private EmailBodyRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new EmailBodyRedactor();
    }

    public function testRealisticResetEmailKeepsNoSixtyFourHexRunAndIsMasked(): void
    {
        $token = (new ResetTokenService())->generate();
        $body = <<<HTML
            <p>Hi Jane,</p>
            <p>We received a request to reset your password.</p>
            <p><a href="https://shop.example.test/auth/reset-password?token={$token}">Reset your password</a></p>
            <p>This link expires in 60 minutes. If you did not request it, ignore this email.</p>
            HTML;

        $redacted = $this->redactor->redact($body);

        self::assertDoesNotMatchRegularExpression('/[0-9a-fA-F]{64}/', $redacted);
        self::assertStringNotContainsString($token, $redacted);
        self::assertStringContainsString(EmailBodyRedactor::MASK, $redacted);
        // The rest of the mail survives, so an operator can still see what was sent.
        self::assertStringContainsString('Hi Jane,', $redacted);
        self::assertStringContainsString('https://shop.example.test/auth/reset-password?token=', $redacted);
        self::assertStringContainsString('This link expires in 60 minutes.', $redacted);
    }

    public function testNoPartialTokenSurvivesEvenWhenTokenIsNotInAUrl(): void
    {
        $token = (new ResetTokenService())->generate();

        $redacted = $this->redactor->redact("Your code is {$token} - use it once.");

        self::assertSame('Your code is ' . EmailBodyRedactor::MASK . ' - use it once.', $redacted);
        foreach ([0, 12, 32, 48] as $offset) {
            self::assertStringNotContainsString(substr($token, $offset, 16), $redacted);
        }
    }

    public function testOrdinaryEmailContentIsLeftUntouched(): void
    {
        $body = <<<HTML
            <p>Hi Jane,</p>
            <p>Order #WM-10432 shipped today. Tracking: 1Z999AA10123456784.</p>
            <p><a href="https://shop.example.test/orders/10432?utm_source=email">View your order</a></p>
            <p>Total: $1,204.55 - thanks for your business.</p>
            HTML;

        self::assertSame($body, $this->redactor->redact($body));
    }

    public function testSetupAccountTokenInPathIsMasked(): void
    {
        $token = str_repeat('a1b2', 16);

        $redacted = $this->redactor->redact("https://shop.example.test/auth/setup-account/{$token}");

        self::assertSame('https://shop.example.test/auth/setup-account/' . EmailBodyRedactor::MASK, $redacted);
    }

    /**
     * The query-parameter rule is the belt-and-braces half: it must hold even if the token stops
     * being 64 hex characters.
     */
    public function testTokenQueryParameterIsMaskedForNonHexTokens(): void
    {
        $redacted = $this->redactor->redact('<a href="https://shop.example.test/reset?token=zzz-not-hex-at-all&lang=en">go</a>');

        self::assertStringNotContainsString('zzz-not-hex-at-all', $redacted);
        self::assertStringContainsString('?token=' . EmailBodyRedactor::MASK, $redacted);
        self::assertStringContainsString('&lang=en', $redacted);
    }

    public function testShortTokenParameterNameAndHtmlEscapedSeparatorAreHandled(): void
    {
        $redacted = $this->redactor->redact('https://shop.example.test/reset?id=7&amp;t=SHORTSECRET&amp;lang=en');

        self::assertStringNotContainsString('SHORTSECRET', $redacted);
        self::assertStringContainsString('?id=7', $redacted);
        self::assertStringContainsString('&amp;t=' . EmailBodyRedactor::MASK, $redacted);
        self::assertStringContainsString('&amp;lang=en', $redacted);
    }

    public function testParametersMerelyEndingInTAreNotMasked(): void
    {
        $body = 'https://shop.example.test/orders?cart=9&content=keep-me&utm_content=keep-me-too';

        self::assertSame($body, $this->redactor->redact($body));
    }

    public function testEmptyBodyIsReturnedUnchanged(): void
    {
        self::assertSame('', $this->redactor->redact(''));
    }

    public function testRedactionIsIdempotent(): void
    {
        $once = $this->redactor->redact('https://shop.example.test/reset?token=' . (new ResetTokenService())->generate());

        self::assertSame($once, $this->redactor->redact($once));
    }

    /**
     * Dev instances need to see a live reset/setup link in email_log to test the flow without a
     * real mailbox. Every other environment (the implicit 'prod' default used above, plus 'test'
     * and any deployed non-dev instance) keeps redacting.
     */
    public function testDevEnvironmentSkipsRedactionEntirely(): void
    {
        $devRedactor = new EmailBodyRedactor('dev');
        $token = (new ResetTokenService())->generate();
        $body = "https://shop.example.test/auth/reset-password?token={$token}";

        self::assertSame($body, $devRedactor->redact($body));
    }

    public function testTestEnvironmentStillRedacts(): void
    {
        $testRedactor = new EmailBodyRedactor('test');
        $token = (new ResetTokenService())->generate();

        self::assertStringNotContainsString($token, $testRedactor->redact("token={$token}"));
    }
}
