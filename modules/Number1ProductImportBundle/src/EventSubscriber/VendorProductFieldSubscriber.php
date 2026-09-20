<?php

declare(strict_types=1);

namespace Number1ProductImportBundle\EventSubscriber;

use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Registers the "number1_*" custom fields this bundle writes onto ProductCore during
 * import — the QuickBooks/vendor metadata columns (NAME, VENDOR, GL accounts, NOTES)
 * that have no equivalent column on ProductCore itself. Once registered, these fields
 * render on the product add/edit form for free via core's existing CustomFieldRenderer,
 * same as FeeBCTireBundle's own field subscribers.
 *
 * LOCATION deliberately has no field here — it drives real per-row FulfillmentRegion
 * inventory placement instead (see ProductCsvTransformer), so storing it again as inert
 * text would just duplicate what the Fulfillment Regions screen already shows.
 */
final class VendorProductFieldSubscriber implements EventSubscriberInterface
{
    public const SOURCE = 'Number1ProductImportBundle';

    /** @var array<string, array{label: string}> */
    public const FIELDS = [
        'number1_qb_item_name' => ['label' => 'Number 1 — QuickBooks Item Name'],
        'number1_supplier' => ['label' => 'Number 1 — Supplier'],
        'number1_gl_accounts' => ['label' => 'Number 1 — GL Accounts'],
        'number1_notes' => ['label' => 'Number 1 — Import Notes'],
    ];

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

        foreach (self::FIELDS as $slug => $def) {
            $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $slug, [
                'label' => $def['label'],
                'fieldType' => CustomFieldDefinition::FIELD_TYPE_TEXT,
                'visibleOnAdd' => true,
                'visibleOnEdit' => true,
                'visibleOnListing' => true,
                'source' => self::SOURCE,
            ]);
        }
    }
}
