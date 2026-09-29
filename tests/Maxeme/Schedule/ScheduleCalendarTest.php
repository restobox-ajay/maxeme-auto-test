<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Schedule;

use App\Entity\AppSetting;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Schedule\ScheduleCalendar;
use App\Maxeme\Schedule\ScheduleSettings;
use App\Service\AppSettings;
use App\Tests\DoctrineIntegrationTestCase;

/** The calendar feeds work in shop time while the database holds UTC. */
final class ScheduleCalendarTest extends DoctrineIntegrationTestCase
{
    private ScheduleCalendar $calendar;
    private ScheduleSettings $settings;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $timezone = $this->em->getRepository(AppSetting::class)->findOneBy(['settingKey' => AppSettings::TIMEZONE_KEY])
            ?? (new AppSetting())->setSettingKey(AppSettings::TIMEZONE_KEY)->setName('Timezone');
        $timezone->setSettingValue('America/Vancouver');
        $this->em->persist($timezone);

        $client = (new Client())->setFirstName('Dan')->setLastName('Kovac')->setHomeNumber('604-555-0142');
        $this->vehicle = (new Vehicle($client))->setYear(2016)->setManufacturer('Honda')->setModel('Civic');
        $this->em->persist($client);
        $this->em->persist($this->vehicle);
        $this->em->flush();
        self::getContainer()->get(AppSettings::class)->clearCache();

        $this->calendar = self::getContainer()->get(ScheduleCalendar::class);
        $this->settings = self::getContainer()->get(ScheduleSettings::class);
    }

    public function testShopTimesAreStoredInUtcAndShownBackInShopTime(): void
    {
        $utc = $this->settings->toUtc('2026-10-02 09:00:00');

        self::assertSame('2026-10-02 16:00:00', $utc->format('Y-m-d H:i:s'), 'PDT is UTC-7');
        self::assertSame('2026-10-02 09:00', $this->settings->toLocal($utc)->format('Y-m-d H:i'));
    }

    public function testFeedsCountAppointmentsPerSlotAndDayInShopTime(): void
    {
        $this->book('2026-10-02 09:00:00', '2026-10-02 11:00:00');
        $this->book('2026-10-02 09:05:00', '2026-10-02 10:00:00');
        $this->book('2026-10-02 18:30:00', '2026-10-02 19:00:00'); // 01:30 UTC the next day

        $slots = $this->calendar->slotCounts('2026-10-02');
        $busy = array_column(array_filter($slots, static fn (array $slot): bool => $slot['count'] > 0), 'count', 'title');

        self::assertCount(48, $slots, '07:00–19:00 in 15-minute slots');
        self::assertSame(['9:00 AM' => 2, '6:30 PM' => 1], $busy);
        self::assertSame([['title' => '3', 'start' => '2026-10-02', 'allDay' => true]], $this->calendar->dayCounts('2026-10-01', '2026-10-04'));

        $events = $this->calendar->events('2026-10-02', '2026-10-03');
        self::assertCount(3, $events);
        self::assertSame('2026-10-02T09:00:00', $events[0]['start']);
        self::assertStringStartsWith('[New] 2016 Honda Civic Dan Kovac 604-555-0142', $events[0]['title']);
    }

    public function testCheckInOnlyFromNew(): void
    {
        $appointment = $this->book('2026-10-02 09:00:00', '2026-10-02 11:00:00');
        $appointment->checkIn();
        self::assertSame(AppointmentStatus::InProgress, $appointment->getStatus());
        self::assertSame(2.0, $appointment->getDurationInHours());

        $this->expectException(\DomainException::class);
        $appointment->checkIn();
    }

    private function book(string $start, string $end): Appointment
    {
        $appointment = new Appointment($this->vehicle, $this->settings->toUtc($start), $this->settings->toUtc($end));
        $this->em->persist($appointment);
        $this->em->flush();

        return $appointment;
    }
}
