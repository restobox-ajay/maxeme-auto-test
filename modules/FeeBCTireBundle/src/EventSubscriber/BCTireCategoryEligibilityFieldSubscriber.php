<?php

declare(strict_types=1);

namespace FeeBCTireBundle\EventSubscriber;

use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use FeeBCTireBundle\Fee\BCTireFeeCalculator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class BCTireCategoryEligibilityFieldSubscriber implements EventSubscriberInterface
{
    public const SLUG = 'bc_tire_fee_eligible';

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

        $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_PRODUCT_CATEGORY, self::SLUG, [
            'label' => 'Eligible for BC Tire Stewardship (TSBC) fee',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_CHECKBOX,
            'visibleOnAdd' => true,
            'visibleOnEdit' => true,
            'source' => BCTireFeeCalculator::SOURCE,
        ]);
    }
}
