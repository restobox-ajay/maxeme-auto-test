<?php

declare(strict_types=1);

namespace ShippingAmazonFBABundle\Controller;

use App\Entity\AppSetting;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use ShippingAmazonFBABundle\Shipping\AmazonFBAShippingCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/bundles/shipping')]
final class AmazonFBAShippingConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $appSettings,
    ) {}

    #[Route('/amazon-fba', name: 'admin_bundle_shipping_amazon_fba', methods: ['GET', 'POST'])]
    public function config(Request $request, AmazonFBAShippingCalculator $calculator): Response
    {
        if ($request->isMethod('POST')) {
            $raw = (string) $request->request->get('product_ids', '');
            $newIds = array_values(array_unique(array_filter(
                array_map('intval', array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: []), 'strlen'))
            )));

            $setting = $this->em->getRepository(AppSetting::class)
                ->findOneBy(['settingKey' => AmazonFBAShippingCalculator::SETTING_KEY]);

            if ($setting === null) {
                $setting = (new AppSetting())
                    ->setSettingKey(AmazonFBAShippingCalculator::SETTING_KEY)
                    ->setName('Amazon FBA Eligible Product IDs')
                    ->setDescription('Product IDs eligible for Amazon FBA fulfilment. FBA shipping only appears when ALL cart items are on this list.');
                $this->em->persist($setting);
            }

            $setting->setSettingValue(json_encode($newIds) ?: '[]');
            $setting->touch();
            $this->em->flush();
            $this->appSettings->clearCache();

            $this->addFlash('success', 'Amazon FBA eligible products updated.');

            return $this->redirectToRoute('admin_bundle_shipping_amazon_fba');
        }

        $eligibleIds = $calculator->eligibleProductIds();
        $products    = $eligibleIds !== []
            ? $this->em->getRepository(ProductCore::class)->findBy(['id' => $eligibleIds])
            : [];

        return $this->render('@ShippingAmazonFBA/config.html.twig', [
            'eligibleIds' => $eligibleIds,
            'products'    => $products,
        ]);
    }
}
