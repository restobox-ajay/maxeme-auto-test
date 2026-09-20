<?php

declare(strict_types=1);

namespace Number1RimImportBundle\EventSubscriber;

use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Registers the "rim_*" custom fields this bundle writes onto ProductCore during sync — the
 * fitment/spec columns (Offset, PCD, CB, Backspace, Seat, Made, Load Rating, Size) that have no
 * equivalent ProductCore column. Once registered, these render on the product add/edit form and
 * the product listing's "Custom Fields" column for free via core's CustomFieldRenderer, same as
 * Number1ProductImportBundle\EventSubscriber\VendorProductFieldSubscriber.
 *
 * Deliberately does NOT register suggested_price_type/suggested_price_value here — those are now
 * native ProductCore columns (SUGGESTED_PRICE_CORE_ADAPTATION_PLAN.md phase 2; originally
 * Number1SuggestedPriceBundle Custom Fields, see RIM_API_IMPORT_PLAN.md §3/§4), written directly
 * via ProductCore::setSuggestedPriceType()/setSuggestedPriceValue() in RimApiImportService.
 */
final class RimSpecFieldSubscriber implements EventSubscriberInterface
{
    public const SOURCE = 'Number1RimImportBundle';

    /** @var array<string, array{label: string}> */
    public const FIELDS = [
        // Previously only ever folded into buildName()'s generated product name — never captured
        // as its own value, same gap Finish had. See CATEGORY_PAGE_FILTER_PLAN.md §2d.
        'rim_model' => ['label' => 'Rim — Model'],
        'rim_offset' => ['label' => 'Rim — Offset'],
        'rim_pcd' => ['label' => 'Rim — PCD'],
        'rim_cb' => ['label' => 'Rim — Center Bore (CB)'],
        'rim_backspace' => ['label' => 'Rim — Backspace'],
        'rim_seat' => ['label' => 'Rim — Seat'],
        'rim_made' => ['label' => 'Rim — Made (Cast/Flow Form)'],
        'rim_load_rating' => ['label' => 'Rim — Load Rating'],
        'rim_size' => ['label' => 'Rim — Size'],
        // Derived from rim_size (e.g. "18x7.5") by RimApiTransformer — kept as their own clean
        // fields so they're independently filterable, see CATEGORY_PAGE_FILTER_PLAN.md §2d.
        'rim_dimension' => ['label' => 'Rim — Diameter'],
        'rim_width' => ['label' => 'Rim — Width'],
        // Not derived from anything else — the API's own Finish column was previously only ever
        // folded into the generated product name, never captured as its own value. See
        // CATEGORY_PAGE_FILTER_PLAN.md §2d.
        'rim_finish' => ['label' => 'Rim — Finish'],
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
