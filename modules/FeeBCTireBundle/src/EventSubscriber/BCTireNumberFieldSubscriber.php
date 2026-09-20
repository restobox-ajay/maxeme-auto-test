<?php

declare(strict_types=1);

namespace FeeBCTireBundle\EventSubscriber;

use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use FeeBCTireBundle\Fee\BCTireFeeCalculator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Mirrors PST's own self-exemption field exactly: a company that has its own TSBC
 * registration number on file is presumed to self-remit, so the fee is skipped for
 * them (see BCTireFeeCalculator::calculate()). Matches number1_inventory's TSBC #
 * behavior — empty means charge, non-empty means skip — per client confirmation.
 */
final class BCTireNumberFieldSubscriber implements EventSubscriberInterface
{
    public const SLUG = 'bc_tsbc_number';

    /**
     * Order-scoped snapshot of the company's TSBC # at the moment the order was
     * created/edited (see BCTireFeeCalculator::applyOrderSnapshot()) — a distinct
     * slug from SLUG above so it never appears as an editable field via the generic
     * CustomFieldRenderer order form (visibleOnAdd/Edit/Listing all false, same
     * reasoning ShippingArrangementBundle uses for its own bundle-written field).
     */
    public const ORDER_SNAPSHOT_SLUG = 'bc_tsbc_number_on_order';

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

        $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY, self::SLUG, [
            'label' => 'TSBC #',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_TEXT,
            'visibleOnAdd' => true,
            'visibleOnEdit' => true,
            'source' => BCTireFeeCalculator::SOURCE,
        ]);

        $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_ORDER, self::ORDER_SNAPSHOT_SLUG, [
            'label' => 'TSBC # (snapshot at order time)',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_TEXT,
            'visibleOnAdd' => false,
            'visibleOnEdit' => false,
            'visibleOnListing' => false,
            'source' => BCTireFeeCalculator::SOURCE,
        ]);
    }
}
