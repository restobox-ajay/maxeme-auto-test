<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Entity\AppSetting;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use App\Service\DocumentActorResolver;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Match\ThreeWayMatchService;
use ProcurementBundle\Receiving\MinimumShelfLife;
use ProcurementBundle\Repository\ProductReceivingRuleRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The bundle's own settings (#555): match tolerances and receiving rules.
 *
 * ## Where the prefixes went
 *
 * They were here, and #615 moved them to core's Settings → Document Prefixes. This docblock used to
 * argue they belonged here: putting a PO prefix on core's screen would have meant core's
 * configuration UI knowing this bundle exists and showing three dead fields once it was removed.
 * That was right about the cost and right about the constraint — core's screen named its three
 * prefixes in three variables, so the only way onto it was for core to hardcode ours.
 *
 * #615 removed the constraint instead of paying the cost. Core's screen renders whatever registered
 * through `app.document_prefix_provider`, so core still knows nothing about this bundle and the
 * fields vanish when it is removed or switched Inactive. The prefixes are declared by
 * {@see \ProcurementBundle\Document\ProcurementDocumentPrefixProvider}; ownership did not move, only
 * the field did.
 *
 * ## Why the tolerances start at zero
 *
 * A tolerance auto-approves a variance without anyone looking at it. Starting exact and loosening
 * once the noise is understood is much easier than discovering, six months later, what has been
 * approving itself — which is the plan's own recommendation and worth restating where the fields
 * actually are.
 */
#[Route('/admin/bundles/procurement/settings')]
final class SettingsController extends AbstractProcurementController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly AppSettings $appSettings,
        private readonly ProductReceivingRuleRepository $rules,
        private readonly MinimumShelfLife $shelfLife,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    #[Route('', name: 'admin_bundle_procurement_settings', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyIfInactive();

        return $this->render('@Procurement/settings.html.twig', [
            'quantityTolerance' => (string) $this->appSettings->get(ThreeWayMatchService::SETTING_QUANTITY_TOLERANCE, '0'),
            'priceTolerance' => (string) $this->appSettings->get(ThreeWayMatchService::SETTING_PRICE_TOLERANCE, '0'),
            // The global minimum shelf life, in days (item 68). Read through the same resolver
            // receiving measures with rather than straight off `app_setting`, so the number on this
            // screen is the number the dock enforces — including how an unparseable stored value is
            // read, which is the thing two separate parsers always eventually disagree about.
            'minimumShelfLifeDays' => $this->shelfLife->globalMinimumDays(),
            'rules' => $this->rules->allWithRequirements(),
            // Only dimensional products: a rule about lot or serial capture is meaningless for a
            // product whose quantity is a number an admin types, and offering it would suggest
            // receiving could enforce something it structurally cannot.
            'products' => $this->dimensionalProducts(),
        ]);
    }

    #[Route('/save', name: 'admin_bundle_procurement_settings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyIfInactive();

        // The prefixes are no longer posted here — they moved to Settings → Document Prefixes with
        // #615 and are declared by ProcurementDocumentPrefixProvider. This action deliberately does
        // NOT keep reading them as a compatibility shim: two screens writing the same
        // `app_setting` row is how a prefix ends up disagreeing with itself.
        foreach ([ThreeWayMatchService::SETTING_QUANTITY_TOLERANCE, ThreeWayMatchService::SETTING_PRICE_TOLERANCE] as $key) {
            $raw = trim((string) $request->request->get($key, '0'));
            if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0)) {
                $this->addFlash('error', 'A tolerance is a percentage and cannot be negative. Use 0 for an exact match.');

                return $this->redirectToRoute('admin_bundle_procurement_settings');
            }

            $this->upsert($key, ucwords(str_replace('_', ' ', $key)), $raw === '' ? '0' : $raw, 'Percentage variance that auto-approves. 0 means an exact match is required.');
        }

        // The global minimum shelf life, in DAYS (item 68). A whole number of days, where 0 means
        // there is no minimum — the same "start at zero" rule the tolerances above follow, and for
        // the same reason: a setting that quietly begins stopping deliveries nobody asked it to
        // stop is discovered six months later, on a dock, by somebody who cannot change it.
        //
        // Days rather than months because a month is not a length: a "2 month" minimum is 59 days
        // in February and 62 in July, so the same pallet with the same date would pass in one month
        // and fail in the next. See MinimumShelfLife.
        $rawMinimum = trim((string) $request->request->get(MinimumShelfLife::SETTING_MINIMUM_DAYS, ''));
        if ($rawMinimum !== '' && !ctype_digit($rawMinimum)) {
            $this->addFlash('error', 'The minimum shelf life is a whole number of days and cannot be negative. Use 0 for no minimum — 90 is three months.');

            return $this->redirectToRoute('admin_bundle_procurement_settings');
        }

        $this->upsert(
            MinimumShelfLife::SETTING_MINIMUM_DAYS,
            'Procurement Minimum Shelf Life Days',
            $rawMinimum === '' ? '0' : $rawMinimum,
            'Days of shelf life a delivery must have left at receipt. 0 means no minimum. A product may override it.',
        );

        $this->em->flush();
        $this->appSettings->clearCache();

        $this->addFlash('success', 'Procurement settings saved.');

        return $this->redirectToRoute('admin_bundle_procurement_settings');
    }

    /**
     * Set or clear whether a product's receiver must name the bin the goods went into.
     *
     * A row with nothing on it is deleted rather than stored: keeping an empty row would make the
     * table's contents stop meaning "the products procurement has a policy about". The identity
     * requirements are NOT set here and never were settable from two places again — they are the
     * tracking policy's, on the product (item 67).
     *
     * "Nothing on it" is now two columns rather than one, and they are not symmetrical. No bin
     * required carries no information. A minimum shelf life of ZERO carries all of it: it is a
     * deliberate exemption, and deleting the row would turn it back into "not set" and quietly hand
     * the product to the global minimum. {@see ProductReceivingRule::isEmpty()} says so.
     */
    #[Route('/receiving-rule', name: 'admin_bundle_procurement_receiving_rule_save', methods: ['POST'])]
    public function saveReceivingRule(Request $request): Response
    {
        $this->denyIfInactive();

        $product = $this->productOr404($request->request->getInt('product_id', 0));
        $rule = $this->rules->ruleFor($product);

        // Only the bin. Batch, expiry and serial are the product's TRACKING POLICY's to declare and
        // are derived from it (item 67) — offering a second set of boxes for them here is how the
        // two ended up disagreeing, and how receiving came to consult a table nothing wrote to.
        $rule->setLocationRequired($request->request->getBoolean('location_required'));

        // This product's own minimum shelf life, overriding the global (item 68). Three states in
        // one box, and the difference between two of them is the whole point:
        //
        //   blank  -> NOT SET. The override is cleared and the product follows the global, wherever
        //             the global goes next.
        //   0      -> NO MINIMUM AT ALL for this product. A deliberate exemption that has to SURVIVE
        //             the global changing, which is exactly what "not set" would not do.
        //   N      -> this product's own minimum, stricter or looser than the global either way.
        //
        // Stored NULL vs 0 is what keeps those two apart. Collapsing them would make every
        // deliberate exemption evaporate the next time somebody edited the global setting.
        $rawMinimum = trim((string) $request->request->get('minimum_shelf_life_days', ''));
        if ($rawMinimum !== '' && !ctype_digit($rawMinimum)) {
            $this->addFlash('error', 'A minimum shelf life is a whole number of days and cannot be negative. Leave it blank to follow the global minimum, or enter 0 to exempt this product from it entirely.');

            return $this->redirectToRoute('admin_bundle_procurement_settings');
        }

        $rule->setMinimumShelfLifeDays($rawMinimum === '' ? null : (int) $rawMinimum);

        if ($rule->isEmpty()) {
            if ($rule->getId() !== null) {
                $this->em->remove($rule);
            }
            $this->em->flush();

            $this->addFlash('success', sprintf(
                '%s no longer requires anything at receiving, and follows the global minimum shelf life of %d day(s).',
                $product->getSku() ?: $product->getName(),
                $this->shelfLife->globalMinimumDays(),
            ));

            return $this->redirectToRoute('admin_bundle_procurement_settings');
        }

        if ($rule->getId() === null) {
            $this->em->persist($rule);
        }

        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s: %s%s%s',
            $product->getSku() ?: $product->getName(),
            $rule->isLocationRequired()
                ? 'a destination bin is now required at receiving, and deliveries without one will be refused rather than booked in incomplete.'
                : 'no destination bin is required at receiving.',
            match (true) {
                $rule->getMinimumShelfLifeDays() === null => sprintf(' It follows the global minimum shelf life of %d day(s).', $this->shelfLife->globalMinimumDays()),
                $rule->getMinimumShelfLifeDays() === 0 => ' It is exempt from the minimum shelf life entirely — no expiry date will ever be called short for it.',
                default => sprintf(' Its own minimum shelf life is %d day(s), which overrides the global %d. A shorter date warns and can be accepted with a reason.', $rule->getMinimumShelfLifeDays(), $this->shelfLife->globalMinimumDays()),
            },
            $rule->isLotRequired() || $rule->isSerialRequired()
                ? sprintf(
                    ' It also needs %s, which comes from its tracking policy rather than from here.',
                    $rule->isSerialRequired() ? 'a serial per unit' : ('a batch code' . ($rule->isExpiryRequired() ? ' with an expiry date' : '')),
                )
                : '',
        ));

        return $this->redirectToRoute('admin_bundle_procurement_settings');
    }

    /** The same shape as ConfigController::upsertAppSetting(), so a row written here looks like every other. */
    private function upsert(string $key, string $name, string $value, string $description): void
    {
        $setting = $this->em->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);

        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey($key);
            $this->em->persist($setting);
        }

        $setting
            ->setName($name)
            // Empty stored as NULL, not '', so AppSettings::get() falls back to the caller's
            // default rather than handing back a blank prefix that would number documents "1".
            ->setSettingValue($value !== '' ? $value : null)
            ->setDescription($description)
            ->touch();
    }

    /** @return list<ProductCore> the products a rule could sensibly be set on. */
    private function dimensionalProducts(): array
    {
        /** @var list<ProductCore> $rows */
        $rows = $this->em->getRepository(ProductCore::class)->findBy(
            ['inventoryMode' => ProductCore::INVENTORY_MODE_DIMENSIONAL, 'deleted' => false],
            ['sku' => 'ASC'],
        );

        return $rows;
    }
}
