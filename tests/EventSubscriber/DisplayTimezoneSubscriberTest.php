<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\EventSubscriber\DisplayTimezoneSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;
use Twig\Extension\CoreExtension;

final class DisplayTimezoneSubscriberTest extends TestCase
{
    private function appSettingsWithTimezone(string $timezone): AppSettings
    {
        $setting = (new AppSetting())->setSettingKey('timezone')->setName('Timezone')->setSettingValue($timezone);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn([$setting]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new AppSettings($em, new ArrayAdapter());
    }

    private function requestEvent(bool $isMainRequest): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new RequestEvent(
            $kernel,
            Request::create('/'),
            $isMainRequest ? KernelInterface::MAIN_REQUEST : KernelInterface::SUB_REQUEST,
        );
    }

    private function consoleCommandEvent(): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(
            null,
            $this->createStub(InputInterface::class),
            $this->createStub(OutputInterface::class),
        );
    }

    public function testOnKernelRequestAppliesTheConfiguredTimezoneToTwig(): void
    {
        $twig = new Environment($this->createStub(\Twig\Loader\LoaderInterface::class));
        $appSettings = $this->appSettingsWithTimezone('America/Vancouver');

        (new DisplayTimezoneSubscriber($twig, $appSettings))->onKernelRequest($this->requestEvent(true));

        self::assertSame(
            'America/Vancouver',
            $twig->getExtension(CoreExtension::class)->getTimezone()->getName(),
        );
    }

    public function testOnKernelRequestIgnoresSubRequests(): void
    {
        $twig = new Environment($this->createStub(\Twig\Loader\LoaderInterface::class));
        $twig->getExtension(CoreExtension::class)->setTimezone('UTC');
        $appSettings = $this->appSettingsWithTimezone('America/Vancouver');

        (new DisplayTimezoneSubscriber($twig, $appSettings))->onKernelRequest($this->requestEvent(false));

        self::assertSame('UTC', $twig->getExtension(CoreExtension::class)->getTimezone()->getName());
    }

    public function testOnConsoleCommandAppliesTheConfiguredTimezoneToTwig(): void
    {
        $twig = new Environment($this->createStub(\Twig\Loader\LoaderInterface::class));
        $appSettings = $this->appSettingsWithTimezone('Asia/Kolkata');

        (new DisplayTimezoneSubscriber($twig, $appSettings))->onConsoleCommand($this->consoleCommandEvent());

        self::assertSame(
            'Asia/Kolkata',
            $twig->getExtension(CoreExtension::class)->getTimezone()->getName(),
        );
    }

    public function testGetSubscribedEventsMapsRequestAndConsoleCommand(): void
    {
        self::assertSame(
            [
                'kernel.request' => 'onKernelRequest',
                'console.command' => 'onConsoleCommand',
            ],
            DisplayTimezoneSubscriber::getSubscribedEvents(),
        );
    }
}
