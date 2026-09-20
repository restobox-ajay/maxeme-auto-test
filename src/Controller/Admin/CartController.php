<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Cart;
use App\Repository\CartRepository;
use App\Service\Pricing\CustomerPricingResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only admin visibility into the Cart/CartItem tables — mainly for support (seeing what a
 * customer currently has in their cart) and as a stable page for e2e tests to assert cart state
 * against, since there's no other UI surface that shows a cart independent of an active session.
 *
 * The header totals shown here are what the customer's cart page last computed and wrote back, so
 * they are as of that render rather than live. The per-item money is the opposite: resolved here and
 * now, against the cart's own company and region, because that is the only honest answer to "what
 * would this customer pay for this line" — a stored one would be whatever the price list said the
 * last time somebody looked.
 */
#[Route('/admin/carts')]
final class CartController extends AbstractAdminController
{
    /**
     * The cart list, in the grid model (#621).
     *
     * It used to fetch every cart on file, unpaged, into one `.panel > table.data-table` — markup
     * the list-screen conventions do not reach, which is why this screen had no way to narrow
     * itself at all. A carts table grows with every anonymous session that ever touched the
     * storefront, so "every row, unbounded" was also the one listing here that genuinely could not
     * be read.
     */
    #[Route('', name: 'admin_cart_index', methods: ['GET'])]
    public function index(Request $request, CartRepository $carts): Response
    {
        $filters = $this->filters($request);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(500, $request->query->getInt('limit', 100)));

        $result = $carts->search($filters, $page, $limit);

        $rows = array_map(static fn (Cart $cart): array => [
            'id' => $cart->getId(),
            'sessionId' => $cart->getSessionId(),
            'company' => $cart->getCompany(),
            'itemCount' => $cart->getItems()->count(),
            'subtotal' => $cart->getSubtotal(),
            'total' => $cart->getTotal(),
            'holdExpiresAt' => $cart->getHoldExpiresAt(),
            'updatedAt' => $cart->getUpdatedAt(),
        ], $result['rows']);

        return $this->render('admin/cart/index.html.twig', [
            'rows' => $rows,
            'filters' => $filters,
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($result['total'] / $limit)),
        ]);
    }

    /**
     * The five filter values, all from GET, all read as text.
     *
     * A non-scalar reads as absent rather than being cast — `?filters[session][]=x` makes the value
     * an array, and casting one raises a notice the dev error handler turns into a 500 from nothing
     * but a crafted URL.
     *
     * @return array{session: string, company: string, itemCount: string, held: string, updatedFrom: string}
     */
    private function filters(Request $request): array
    {
        $raw = $request->query->all('filters');

        $filters = [];
        foreach (['session', 'company', 'itemCount', 'held', 'updatedFrom'] as $key) {
            $value = $raw[$key] ?? null;
            $filters[$key] = \is_scalar($value) ? trim((string) $value) : '';
        }

        return $filters;
    }

    #[Route('/{id}', name: 'admin_cart_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, EntityManagerInterface $entityManager, CustomerPricingResolver $pricingResolver): Response
    {
        $cart = $entityManager->find(Cart::class, $id);
        if (!$cart instanceof Cart) {
            $this->addFlash('error', 'Cart could not be found.');

            return $this->redirectToRoute('admin_cart_index');
        }

        // One scope for the whole cart: the price list follows the buyer and the region, not the
        // line, which is the reason the scope exists at all.
        $cart->priceItems($pricingResolver->for($cart->getCompany(), $cart->getFulfillmentRegion()));

        return $this->render('admin/cart/detail.html.twig', ['cart' => $cart]);
    }
}
