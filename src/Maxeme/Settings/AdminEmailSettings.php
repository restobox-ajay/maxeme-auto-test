<?php

declare(strict_types=1);

namespace App\Maxeme\Settings;

use App\Entity\AppSetting;
use App\Maxeme\Audit\ActivityRecorder;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Config › Settings › Admin Email: where the system's "tell the admins" emails go (the stock
 * reconciliation alert, contact-form messages when no contact address is set), instead of every
 * active staff login. One address or several, comma separated. Blank = every active admin, as before.
 * Read by App\Service\EmailNotifier::activeAdminEmails().
 */
final class AdminEmailSettings
{
    public const KEY = 'admin_email';

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ActivityRecorder $activity,
    ) {
    }

    public function adminEmail(): string
    {
        return trim((string) $this->appSettings->get(self::KEY, ''));
    }

    /** @return ?string the error, or null once saved */
    public function save(string $value): ?string
    {
        $addresses = array_values(array_unique(array_filter(array_map(
            static fn (string $a): string => strtolower(trim($a)),
            preg_split('/[,;\s]+/', $value) ?: [],
        ), static fn (string $a): bool => $a !== '')));
        foreach ($addresses as $address) {
            if (count($this->validator->validate($address, [new Assert\Email(), new Assert\Length(max: 180)])) > 0) {
                return sprintf('"%s" is not a valid email address.', $address);
            }
        }
        $value = implode(', ', $addresses);
        if (strlen($value) > 500) {
            return 'Enter at most a few addresses (500 characters).';
        }

        $before = $this->adminEmail();
        $setting = $this->entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => self::KEY])
            ?? (new AppSetting())->setSettingKey(self::KEY);
        $setting->setName('Admin Email')
            ->setSettingValue($value)
            ->setDescription('Where system emails for the admins go (stock reconciliation alert, contact-form messages without a contact address). Blank = every active admin.')
            ->touch();
        $this->entityManager->persist($setting);
        $this->entityManager->flush();
        $this->appSettings->clearCache();
        $this->activity->settingsChanged('admin email', ['admin email' => $before], ['admin email' => $value]);

        return null;
    }
}
