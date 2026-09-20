<?php

declare(strict_types=1);

namespace FeeBCTireBundle\Controller;

use App\Entity\AppSetting;
use App\Repository\FeeRepository;
use App\Service\AppSettings;
use App\Validation\Constraint\ValidFee;
use Doctrine\ORM\EntityManagerInterface;
use FeeBCTireBundle\Fee\BCTireFeeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin/bundles/fees/bc-tire')]
final class BCTireFeeConfigController extends AbstractController
{
    private const TAX_CLASSES = ['E' => 'Exempt (E)', 'G' => 'GST only (G)', 'S' => 'Standard (S)'];

    /**
     * Where the TSBC #/status the bundle records on an order is allowed to be displayed —
     * read by BCTireAdminOrderDetailProvider/BCTireCustomerOrderDetailProvider/
     * BCTireInvoiceNoteProvider before they render anything. Stored as global AppSetting
     * rows (no bundle-specific table) since this is exactly the "a few admin-editable
     * toggles" shape that entity already exists for. Defaults to shown (on) so existing
     * installs keep today's behavior until an admin explicitly turns one off.
     */
    public const DISPLAY_SETTING_LABELS = [
        'fee_bc_tire_show_order_detail_admin' => 'Admin order detail page',
        'fee_bc_tire_show_order_detail_customer' => 'Customer order detail page',
        'fee_bc_tire_show_invoice' => 'Invoices (admin & customer, incl. PDF)',
    ];

    public function __construct(
        private readonly FeeRepository $feeRepo,
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $appSettings,
    ) {}

    #[Route('', name: 'admin_bundle_fee_bc_tire_config', methods: ['GET', 'POST'])]
    public function config(Request $request): Response
    {
        // This used to be a loop of $this->feeRepo->ensureBySlug(), which CREATED this
        // bundle's fee rows on a GET. It does not any more: BCTireFeeSeeder
        // owns them and runs once, on the first admin login. This action reads (and, on a
        // POST, writes only what the admin submitted).
        $fees = $this->feeRepo->findBySource(BCTireFeeCalculator::SOURCE);

        if ($request->isMethod('POST')) {
            foreach ($fees as $fee) {
                $slug = $fee->getSlug();
                $fee->setName(trim((string) $request->request->get('name_' . $slug, $fee->getName())));
                $fee->setDefaultValue((float) $request->request->get('value_' . $slug, $fee->getDefaultValue()));
                $fee->setTaxClass((string) $request->request->get('tax_class_' . $slug, $fee->getTaxClass()));
                $fee->setPlacement((string) $request->request->get('placement_' . $slug, $fee->getPlacement()));
            }

            $validator = Validation::createValidator();
            foreach ($fees as $fee) {
                $violations = $validator->validate($fee, new ValidFee());
                if (count($violations) > 0) {
                    $this->addFlash('error', (string) $violations[0]->getMessage());
                    return $this->redirectToRoute('admin_bundle_fee_bc_tire_config');
                }
            }

            foreach (self::DISPLAY_SETTING_LABELS as $key => $label) {
                $this->setDisplaySetting($key, $label, $request->request->getBoolean('display_' . $key));
            }

            $this->em->flush();
            $this->appSettings->clearCache();
            $this->addFlash('success', 'BC Tire fee updated.');
            return $this->redirectToRoute('admin_bundle_fee_bc_tire_config');
        }

        return $this->render('@FeeBCTire/config.html.twig', [
            'fees'       => $fees,
            'taxClasses' => self::TAX_CLASSES,
            'displaySettingLabels' => self::DISPLAY_SETTING_LABELS,
            'displaySettings'      => $this->currentDisplaySettings(),
        ]);
    }

    /** @return array<string, bool> */
    private function currentDisplaySettings(): array
    {
        $out = [];
        foreach (self::DISPLAY_SETTING_LABELS as $key => $label) {
            $out[$key] = $this->appSettings->get($key, '1') === '1';
        }

        return $out;
    }

    private function setDisplaySetting(string $key, string $label, bool $value): void
    {
        $setting = $this->em->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey($key)->setCategory(BCTireFeeCalculator::SOURCE);
            $this->em->persist($setting);
        }

        $setting->setName($label)->setSettingValue($value ? '1' : '0')->touch();
    }
}
