<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A bad `sender_from_address` must not be able to stop the application sending mail.
 *
 * `Symfony\Component\Mime\Address` does not fail softly — its constructor throws
 * RfcComplianceException for anything that is not an address, and InvalidArgumentException for an
 * embedded newline. `AppSettings::fromAddress()` feeds it a value straight out of an
 * admin-editable setting, and none of its 16 call sites catches. So one unusable value there does
 * not degrade a single email; it takes every outbound message down as an uncaught 500 — password
 * resets, invites, order confirmations, the contact form.
 *
 * The blank-string check that used to be the only guard covered neither case: a typo throws one
 * exception, a newline (the shape a header-injection probe arrives in) throws another.
 * `replyToAddress()` had validated with the same helper since it was written; only the From side
 * was missing it.
 */
final class AppSettingsFromAddressCrashGuardTest extends TestCase
{
    /**
     * Built for real over a stubbed repository rather than doubled, since AppSettings is final —
     * the approach tests/Twig/AppSettingsExtensionTest.php and the coupon calculator tests take.
     */
    private function settings(string $senderFrom): AppSettings
    {
        $rows = [
            (new AppSetting())
                ->setSettingKey(AppSettings::SENDER_FROM_ADDRESS_KEY)
                ->setName('Sender From Address')
                ->setSettingValue($senderFrom),
        ];

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new AppSettings($em, new ArrayAdapter());
    }

    /** @return array<string, array{string}> */
    public static function unusableValues(): array
    {
        return [
            'header injection via newline' => ["evil@example.com\nBcc: victim@example.com"],
            'carriage return' => ["evil@example.com\r\nBcc: victim@example.com"],
            'bare carriage return' => ["evil@example.com\rX-Injected: 1"],
            'not an address at all' => ['not-an-email'],
            'display name form' => ['Support <support@example.com>'],
            'two addresses' => ['a@example.com, b@example.com'],
            'leading whitespace only' => ['   '],
            'angle brackets only' => ['<>'],
            'missing domain' => ['support@'],
            'missing local part' => ['@example.com'],
        ];
    }

    #[DataProvider('unusableValues')]
    public function testAnUnusableSenderAddressNeverThrows(string $value): void
    {
        $settings = $this->settings($value);

        // The assertion is that this line returns at all. Before the fix, half of these threw
        // RfcComplianceException and half InvalidArgumentException, from inside a method with no
        // caller that catches.
        $address = $settings->supportFromAddress();

        // Falling through to MAILER_FROM, or to null when nothing else is configured, is the
        // designed outcome: a wrong From: is a deliverability problem, a thrown one is an outage.
        // Asserted unconditionally so the null case still counts as a check rather than a risky,
        // assertion-free pass.
        $resolved = $address?->getAddress();

        self::assertNotSame(trim($value), $resolved, 'an unusable value must never become the From: address');
        self::assertStringNotContainsString("\n", (string) $resolved);
        self::assertStringNotContainsString("\r", (string) $resolved);
    }

    public function testAValidOverrideIsStillUsed(): void
    {
        $address = $this->settings('sender@example.com')->supportFromAddress();

        self::assertNotNull($address);
        self::assertSame('sender@example.com', $address->getAddress());
    }

    /** Whitespace around an otherwise valid address is trimmed, not treated as unusable. */
    public function testSurroundingWhitespaceIsTolerated(): void
    {
        $address = $this->settings("  sender@example.com  ")->supportFromAddress();

        self::assertNotNull($address);
        self::assertSame('sender@example.com', $address->getAddress());
    }
}
