<?php

declare(strict_types=1);

namespace TaxBCBundle\EventSubscriber;

use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use TaxBCBundle\Tax\BCTaxCalculator;

/**
 * PST # moves off the core Company entity entirely (#124) and becomes this bundle's
 * own field, same shape as FeeBCTireBundle's TSBC # field — a company that has its
 * own BC PST registration on file is presumed to self-remit, so BCTaxCalculator
 * skips PST for them (see BCTaxCalculator::calculate()).
 */
final class BCPstNumberFieldSubscriber implements EventSubscriberInterface
{
    public const SLUG = 'bc_pst_number';

    /**
     * Order-scoped snapshot of the company's PST # at the moment the order was
     * created/edited (see BCTaxCalculator::applyOrderSnapshot()) — a distinct slug
     * from SLUG above so it never appears as an editable field via the generic
     * CustomFieldRenderer order form (visibleOnAdd/Edit/Listing all false, same
     * reasoning FeeBCTireBundle uses for its own TSBC order-snapshot field).
     */
    public const ORDER_SNAPSHOT_SLUG = 'bc_pst_number_on_order';

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
            'label' => 'PST #',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_TEXT,
            'visibleOnAdd' => true,
            'visibleOnEdit' => true,
            'source' => BCTaxCalculator::SOURCE,
        ]);

        $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_ORDER, self::ORDER_SNAPSHOT_SLUG, [
            'label' => 'PST # (snapshot at order time)',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_TEXT,
            'visibleOnAdd' => false,
            'visibleOnEdit' => false,
            'visibleOnListing' => false,
            'source' => BCTaxCalculator::SOURCE,
        ]);
    }
}
