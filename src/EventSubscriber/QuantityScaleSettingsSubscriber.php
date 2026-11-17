<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\AppSettings;
use App\Service\QuantityScale;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Connects QuantityScale to the "quantity_decimal_places" setting. QuantityScale is static, so
 * nothing else can hand it the settings. Without this it always uses MAX_DECIMALS and ignores
 * the setting.
 *
 * Like DisplayTimezoneSubscriber, it runs on kernel.request (web) and console.command
 * (bin/console and the messenger worker), so both kinds of process round the same way. It binds
 * the AppSettings service, not a number read once: decimals() asks AppSettings on every call,
 * and AppSettings clears its cache when a setting is saved. A long-running worker therefore
 * picks up a change without a restart.
 */
final class QuantityScaleSettingsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AppSettings $appSettings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // High priority, so the binding is in place before any listener that might round a quantity.
        return [
            KernelEvents::REQUEST => ['bind', 256],
            ConsoleEvents::COMMAND => ['bind', 256],
        ];
    }

    public function bind(): void
    {
        QuantityScale::bind($this->appSettings);
    }
}
