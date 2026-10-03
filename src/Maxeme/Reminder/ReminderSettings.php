<?php

declare(strict_types=1);

namespace App\Maxeme\Reminder;

use App\Entity\AppSetting;
use App\Maxeme\Audit\ActivityRecorder;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Config › Settings › Reminder Emails: the website's booking form, linked from the service reminder
 * emails ({{booking link}}). Stored as an app setting; until one is saved, maxeme.company.booking_url.
 * Core's entity diff skips app settings, so a change is recorded in the Activity Log here.
 */
final class ReminderSettings
{
    public const BOOKING_URL = 'maxeme_booking_url';

    /** @param array{booking_url: string} $company */
    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ActivityRecorder $activity,
        #[Autowire(param: 'maxeme.company')]
        private readonly array $company,
    ) {
    }

    public function bookingUrl(): string
    {
        return $this->appSettings->get(self::BOOKING_URL) ?? $this->company['booking_url'];
    }

    /** @return ?string the error, or null once saved (blank leaves the link out of the emails) */
    public function saveBookingUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url !== '' && count($this->validator->validate($url, [new Assert\Url(protocols: ['http', 'https'], requireTld: true), new Assert\Length(max: 500)])) > 0) {
            return 'Enter the booking link as a full web address, e.g. https://www.maxemeauto.com/book.';
        }

        $before = $this->bookingUrl();
        $setting = $this->entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => self::BOOKING_URL])
            ?? (new AppSetting())->setSettingKey(self::BOOKING_URL);
        $setting->setName('Booking link')
            ->setSettingValue($url)
            ->setDescription('The website booking form linked from service reminder emails ({{booking link}}).')
            ->touch();
        $this->entityManager->persist($setting);
        $this->entityManager->flush();
        $this->appSettings->clearCache();
        $this->activity->settingsChanged('reminder emails', ['booking link' => $before], ['booking link' => $url]);

        return null;
    }
}
