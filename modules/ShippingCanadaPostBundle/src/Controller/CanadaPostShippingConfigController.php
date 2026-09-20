<?php

declare(strict_types=1);

namespace ShippingCanadaPostBundle\Controller;

use ShippingCanadaPostBundle\Shipping\CanadaPostShippingCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/bundles/shipping')]
final class CanadaPostShippingConfigController extends AbstractController
{
    #[Route('/canada-post', name: 'admin_bundle_shipping_canada_post', methods: ['GET'])]
    public function config(): Response
    {
        return $this->render('@ShippingCanadaPost/config.html.twig', [
            'groundBase'     => CanadaPostShippingCalculator::GROUND_BASE,
            'groundPerUnit'  => CanadaPostShippingCalculator::GROUND_PER_UNIT,
            'groundDays'     => CanadaPostShippingCalculator::GROUND_DAYS,
            'expressBase'    => CanadaPostShippingCalculator::EXPRESS_BASE,
            'expressPerUnit' => CanadaPostShippingCalculator::EXPRESS_PER_UNIT,
            'expressDays'    => CanadaPostShippingCalculator::EXPRESS_DAYS,
        ]);
    }
}
