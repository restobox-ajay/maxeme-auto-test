<?php

declare(strict_types=1);

namespace Number1GuestCoverPageBundle\Service;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

final class GuestCoverPageTemplateStore
{
    private const SETTING_KEY = 'guest_cover_page_template_source';

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** The admin-edited override, or null if the bundle's shipped file is still in use. */
    public function getCustomSource(): ?string
    {
        $value = $this->appSettings->get(self::SETTING_KEY);

        return $value !== null && trim($value) !== '' ? $value : null;
    }

    /** The bundle's shipped template, used to pre-fill the editor and as the "reset" target. */
    public function getDefaultSource(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/login.html.twig');
    }

    public function save(string $source): void
    {
        $setting = $this->entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => self::SETTING_KEY]);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey(self::SETTING_KEY)->setName('Guest Cover Page Template Source');
            $this->entityManager->persist($setting);
        }
        $setting->setSettingValue($source)->touch();
        $this->entityManager->flush();
        $this->appSettings->clearCache();
    }

    public function reset(): void
    {
        $setting = $this->entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => self::SETTING_KEY]);
        if ($setting instanceof AppSetting) {
            $this->entityManager->remove($setting);
            $this->entityManager->flush();
            $this->appSettings->clearCache();
        }
    }
}
