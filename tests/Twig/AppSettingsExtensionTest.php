<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\Twig\AppSettingsExtension;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppSettingsExtensionTest extends TestCase
{
    private function makeSetting(string $key, ?string $value): AppSetting
    {
        return (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
    }

    /** @param list<AppSetting> $rows */
    private function settings(array $rows = []): AppSettings
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new AppSettings($em, new ArrayAdapter());
    }

    public function testGetSettingReturnsStoredValue(): void
    {
        $extension = new AppSettingsExtension($this->settings([
            $this->makeSetting('site_name', 'Acme Wholesale'),
        ]));

        self::assertSame('Acme Wholesale', $extension->getSetting('site_name', 'Default Name'));
    }

    public function testGetSettingReturnsDefaultWhenKeyMissing(): void
    {
        $extension = new AppSettingsExtension($this->settings());

        self::assertSame('Default Name', $extension->getSetting('missing_key', 'Default Name'));
        self::assertNull($extension->getSetting('missing_key'));
    }

    public function testGetAllReturnsSettingKeyValueMap(): void
    {
        $extension = new AppSettingsExtension($this->settings([
            $this->makeSetting('a', '1'),
            $this->makeSetting('b', null),
        ]));

        self::assertSame(['a' => '1', 'b' => null], $extension->getAll());
    }

    public function testGetFunctionsRegistersAppSettingAndAppSettingsAll(): void
    {
        $extension = new AppSettingsExtension($this->settings());

        $functions = $extension->getFunctions();

        self::assertCount(3, $functions);
        self::assertContainsOnlyInstancesOf(TwigFunction::class, $functions);
        self::assertSame('app_setting', $functions[0]->getName());
        self::assertSame('app_settings_all', $functions[1]->getName());
        self::assertSame('site_name', $functions[2]->getName());
    }

    public function testGetFiltersRegistersJsonDecode(): void
    {
        $extension = new AppSettingsExtension($this->settings());

        $filters = $extension->getFilters();

        self::assertCount(1, $filters);
        self::assertInstanceOf(TwigFilter::class, $filters[0]);
        self::assertSame('json_decode', $filters[0]->getName());
    }

    public function testJsonDecodeFilterDecodesValidJsonObject(): void
    {
        $filter = $this->jsonDecodeCallable();

        self::assertSame(['a' => 1, 'b' => 'two'], $filter('{"a":1,"b":"two"}'));
    }

    public function testJsonDecodeFilterReturnsEmptyArrayForNull(): void
    {
        $filter = $this->jsonDecodeCallable();

        self::assertSame([], $filter(null));
    }

    public function testJsonDecodeFilterReturnsEmptyArrayForInvalidJson(): void
    {
        $filter = $this->jsonDecodeCallable();

        self::assertSame([], $filter('{not valid json'));
    }

    public function testJsonDecodeFilterReturnsEmptyArrayForJsonNullLiteral(): void
    {
        $filter = $this->jsonDecodeCallable();

        self::assertSame([], $filter('null'));
    }

    private function jsonDecodeCallable(): callable
    {
        $extension = new AppSettingsExtension($this->settings());
        $filters = $extension->getFilters();

        return $filters[0]->getCallable();
    }
}
