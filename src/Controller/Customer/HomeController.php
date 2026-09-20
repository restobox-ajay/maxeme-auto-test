<?php

namespace App\Controller\Customer;

use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractCustomerController
{
    #[Route('/', name: 'customer_home', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $company = $this->currentCompany();
        $blocked = false;
        $selectedRegion = '';

        if ($company instanceof Company) {
            $activeRegions = $this->activeCompanyFulfillmentRegions($entityManager, $company);
            if ($activeRegions === []) {
                $blocked = true;
            } else {
                $selectedRegion = $activeRegions[0]->getFulfillmentRegion()->getName();
            }
        } else {
            $guestRegions = $this->guestVisibleFulfillmentRegions($entityManager);
            if ($guestRegions === []) {
                $blocked = true;
            } else {
                $selectedRegion = $guestRegions[0]->getName();
            }
        }

        $categories = $this->categoriesFromDatabase($entityManager, $selectedRegion);
        $uncategorizedCount = $this->productCountUncategorizedFromDatabase($entityManager, $selectedRegion);
        $totalProductsCount = $this->productCountFromDatabase($entityManager, null, $selectedRegion);

        $featuredCategory = $categories[0] ?? null;
        if (!$featuredCategory && $uncategorizedCount > 0) {
            $featuredCategory = [
                'id' => '-1',
                'name' => 'Uncategorized',
                'count' => (string) $uncategorizedCount,
            ];
        }

        $featuredProducts = $blocked
            ? []
            : $this->productsFromDatabase($entityManager, null, $selectedRegion, '', null, 8, true);

        return $this->render('customer/_main/home.html.twig', [
            'categories' => $categories,
            'products' => $featuredProducts,
            'featuredCategory' => $featuredCategory,
            'uncategorizedCount' => $uncategorizedCount,
            'totalProductsCount' => $totalProductsCount,
            'selectedRegion' => $selectedRegion,
            'blocked' => $blocked,
        ]);
    }
}
