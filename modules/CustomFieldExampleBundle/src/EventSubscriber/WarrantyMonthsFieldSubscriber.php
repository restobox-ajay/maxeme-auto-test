<?php

declare(strict_types=1);

namespace CustomFieldExampleBundle\EventSubscriber;

use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class WarrantyMonthsFieldSubscriber implements EventSubscriberInterface
{
    public const SOURCE = 'CustomFieldExampleBundle';
    public const SLUG = 'warranty_months';

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

        $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, self::SLUG, [
            'label' => 'Warranty (months)',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_NUMBER,
            'visibleOnAdd' => true,
            'visibleOnEdit' => true,
            'visibleOnListing' => true,
            'searchable' => true,
            'source' => self::SOURCE,
        ]);
    }
}
