<?php

declare(strict_types=1);

namespace TaxCanadaSimpleBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use TaxCanadaSimpleBundle\Service\CanadaTaxRegistrations;

/**
 * Lazily registers this bundle's per-province tax registration fields on company.
 *
 * There is no bundle install step in this app, so "ensure my fields exist" runs opportunistically
 * on each main request — the same shape FeeBCTireBundle's BCTireNumberFieldSubscriber uses for
 * its TSBC # field. The field metadata itself lives in CanadaTaxRegistrations rather than here,
 * so the slugs and labels have one definition shared with the get/set path.
 */
final class CanadaTaxRegistrationFieldSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CanadaTaxRegistrations $registrations,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->registrations->ensureDefinitions();
    }
}
