<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Service;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

final class CustomHeaderFooterStore
{
    private const HEADER_KEY = 'custom_header_html';
    private const FOOTER_KEY = 'custom_footer_html';

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function getHeaderHtml(): string
    {
        return $this->appSettings->get(self::HEADER_KEY) ?? '';
    }

    public function getFooterHtml(): string
    {
        return $this->appSettings->get(self::FOOTER_KEY) ?? '';
    }

    public function saveHeaderHtml(string $html): void
    {
        $this->saveSetting(self::HEADER_KEY, 'Custom Header HTML (Customer Side)', $html);
    }

    public function saveFooterHtml(string $html): void
    {
        $this->saveSetting(self::FOOTER_KEY, 'Custom Footer HTML (Customer Side)', $html);
    }

    private function saveSetting(string $key, string $name, string $value): void
    {
        $setting = $this->entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey($key)->setName($name);
            $this->entityManager->persist($setting);
        }
        $setting->setSettingValue($value)->touch();
        $this->entityManager->flush();
        $this->appSettings->clearCache();
    }
}
