<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding\Checks;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\Service\Onboarding\Checks\FaviconFileExistsCheck;
use App\Service\Onboarding\Checks\LogoFileExistsCheck;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * #426: "check logo file is not missing" / "check favicon file not missing". Adversarial focus:
 * a corrupted or maliciously crafted logo_url/favicon_url setting value must never make the
 * check confirm the existence of a file outside public/ (path traversal), attempt a network
 * fetch (an external URL), or throw on a dangling/impossible path.
 */
final class AbstractUploadedFileExistsCheckTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/onboarding-upload-test-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/public/uploads/branding', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->projectDir);
    }

    private function removeRecursively(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeRecursively($path . '/' . $entry);
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private function appSettingsWith(string $key, ?string $value): AppSettings
    {
        $row = (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($value === null ? [] : [$row]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new AppSettings($em, new ArrayAdapter());
    }

    public function testBlankSettingFails(): void
    {
        $check = new LogoFileExistsCheck($this->appSettingsWith('logo_url', ''), $this->projectDir);

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('not configured', $result->message);
    }

    public function testUnsetSettingFails(): void
    {
        $check = new LogoFileExistsCheck($this->appSettingsWith('logo_url', null), $this->projectDir);

        self::assertFalse($check->run()->passed);
    }

    public function testFileThatActuallyExistsPasses(): void
    {
        file_put_contents($this->projectDir . '/public/uploads/branding/logo.png', 'fake-png-bytes');

        $check = new LogoFileExistsCheck(
            $this->appSettingsWith('logo_url', '/uploads/branding/logo.png'),
            $this->projectDir
        );

        self::assertTrue($check->run()->passed);
    }

    public function testConfiguredButMissingFileFails(): void
    {
        $check = new LogoFileExistsCheck(
            $this->appSettingsWith('logo_url', '/uploads/branding/never-uploaded.png'),
            $this->projectDir
        );

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('missing on disk', $result->message);
    }

    public function testExternalHttpUrlIsRejectedRatherThanFetched(): void
    {
        $check = new LogoFileExistsCheck(
            $this->appSettingsWith('logo_url', 'https://evil.example.test/logo.png'),
            $this->projectDir
        );

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('external URL', $result->message);
    }

    public function testProtocolRelativeUrlIsRejected(): void
    {
        $check = new LogoFileExistsCheck(
            $this->appSettingsWith('logo_url', '//evil.example.test/logo.png'),
            $this->projectDir
        );

        self::assertFalse($check->run()->passed);
    }

    /**
     * The sharpest adversarial case: a `..`-laden value must never cause the check to confirm
     * (or read) a file outside public/uploads/branding — e.g. a secret one level above public/.
     */
    public function testPathTraversalOutsidePublicRootIsRejectedEvenWhenTheTargetFileExists(): void
    {
        file_put_contents($this->projectDir . '/secret-outside-public.txt', 'top secret');

        $check = new LogoFileExistsCheck(
            $this->appSettingsWith('logo_url', '/../secret-outside-public.txt'),
            $this->projectDir
        );

        $result = $check->run();

        self::assertFalse($result->passed, 'a path escaping public/ must never be reported as an existing logo file');
        self::assertStringNotContainsString('secret', $result->message);
    }

    public function testDeeplyNestedTraversalAttemptIsAlsoRejected(): void
    {
        $check = new LogoFileExistsCheck(
            $this->appSettingsWith('logo_url', '/../../../../../../../../etc/passwd'),
            $this->projectDir
        );

        self::assertFalse($check->run()->passed);
    }

    public function testFaviconUsesItsOwnSettingKeyIndependentlyOfLogo(): void
    {
        file_put_contents($this->projectDir . '/public/uploads/branding/favicon.ico', 'ico-bytes');

        $check = new FaviconFileExistsCheck(
            $this->appSettingsWith('favicon_url', '/uploads/branding/favicon.ico'),
            $this->projectDir
        );

        self::assertTrue($check->run()->passed);
    }

    public function testKeysAndLabelsDifferBetweenLogoAndFavicon(): void
    {
        $settings = $this->appSettingsWith('logo_url', null);
        $logo = new LogoFileExistsCheck($settings, $this->projectDir);
        $favicon = new FaviconFileExistsCheck($settings, $this->projectDir);

        self::assertNotSame($logo->getKey(), $favicon->getKey());
        self::assertNotSame($logo->getLabel(), $favicon->getLabel());
    }
}
