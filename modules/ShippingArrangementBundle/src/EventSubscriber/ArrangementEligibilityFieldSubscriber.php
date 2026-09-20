<?php

declare(strict_types=1);

namespace ShippingArrangementBundle\EventSubscriber;

use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ArrangementEligibilityFieldSubscriber implements EventSubscriberInterface
{
    public const SOURCE = 'ShippingArrangementBundle';
    public const SLUG = 'arrangement_shipping_eligible';

    public function __construct(
        private readonly CustomFieldDefinitionRepository $definitionRepo,
    ) {}

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

        // visibleOnAdd/visibleOnEdit/visibleOnListing are irrelevant here — this bundle never
        // calls CustomFieldRenderer, it reads/writes CustomFieldValue directly from its own
        // config screen — but ensureBySlug()'s signature still requires them, so they're false.
        $seedData = [
            'label' => 'Eligible for Existing-Arrangement Shipping',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_CHECKBOX,
            'visibleOnAdd' => false,
            'visibleOnEdit' => false,
            'visibleOnListing' => false,
            'source' => self::SOURCE,
        ];

        $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY, self::SLUG, $seedData);
        $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY_ADDRESS, self::SLUG, $seedData);
    }
}
