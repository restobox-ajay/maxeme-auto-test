<?php

namespace App\EventSubscriber;

use App\Service\AppSettings;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Extension\CoreExtension;

/**
 * Applies the "timezone" app setting to Twig's date filter, once per process, so every existing
 * {{ x|date(...) }} call across admin/customer templates, emails and PDFs displays in the
 * configured zone with no template changes. Storage stays UTC always (see Kernel); this is the
 * one seam where a changed setting takes effect everywhere at once.
 *
 * Runs on both kernel.request (web) and console.command (bin/console, including the messenger
 * worker started by `messenger:consume`) since those are the two process types that render Twig.
 * A request-only listener would leave queued emails rendered by the worker on PHP's ambient
 * default (UTC, since Kernel pins it) instead of the configured display zone.
 */
final class DisplayTimezoneSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AppSettings $appSettings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            ConsoleEvents::COMMAND => 'onConsoleCommand',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->apply();
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->apply();
    }

    private function apply(): void
    {
        $this->twig->getExtension(CoreExtension::class)->setTimezone($this->appSettings->timezone());
    }
}
