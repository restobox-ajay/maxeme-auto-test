<?php

declare(strict_types=1);

namespace ShippingBulkBundle\Controller;

use ShippingBulkBundle\Shipping\BulkShippingCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/bundles/shipping')]
final class BulkShippingConfigController extends AbstractController
{
    #[Route('/bulk', name: 'admin_bundle_shipping_bulk', methods: ['GET'])]
    public function config(): Response
    {
        return $this->render('@ShippingBulk/config.html.twig', [
            'rates'        => BulkShippingCalculator::RATES,
            'rateDefault'  => BulkShippingCalculator::RATE_DEFAULT,
            'deliveryDays' => BulkShippingCalculator::DELIVERY_DAYS,
        ]);
    }
}
