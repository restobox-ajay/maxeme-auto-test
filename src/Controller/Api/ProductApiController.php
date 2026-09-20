<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Customer\CatalogController;
use App\Api\CatalogParams;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * WRAPPER. Routing and nothing else.
 *
 * Every action here is the same three steps: convert the query params (CatalogParams), hand them to
 * the customer controller that already serves this page on the website, return what it gives back.
 * The forged sub-request carries `_api`, which is the only thing that tells that controller to
 * return its rows as data instead of rendering them into Twig — same query, same data, two
 * renderings.
 *
 * Authentication happens before this class runs (ApiKeyAuthenticator on the stateless /api/
 * firewall) and, crucially, authenticates as a real CustomerUser. So by the time CatalogController
 * runs it sees an ordinary logged-in customer, and company scoping, price list, catalog visibility
 * and role gating all apply by themselves.
 *
 * Nothing in this file may decide anything. No query, no price, no visibility check, no entity
 * access. If an endpoint here ever needs more than "convert params, forward, return", the thing it
 * needs belongs in the customer controller where the website can benefit from it too.
 */
#[Route('/api/v1')]
final class ProductApiController extends AbstractController
{
    #[Route('/products', name: 'api_v1_product_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        return $this->forward(
            CatalogController::class . '::index',
            ['_api' => true],
            CatalogParams::toCatalogQuery($request),
        );
    }
}
