<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Movement;

use App\Service\QuantityScale;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * Chooses which detail rows answer a withdrawal, when nobody has said (#550).
 *
 * **Earliest expiry first, then lowest bin `sort_key`.** No UI, no pick list, no operator choice —
 * that is warehouse operations (#552), and building it here would be building the wrong half. What
 * this exists for is that a withdrawal expressed only as "take 5 of this product out of this
 * warehouse" must still name real rows, or the invariant breaks the moment anything uses it.
 *
 * FEFO as an operator-facing *workflow* is explicitly out of scope for #550; FEFO as the tiebreak
 * this uses is not the same thing and costs nothing.
 *
 * Returns the plan rather than applying it, so a caller can show it, refuse it, or fold it into a
 * larger MovementRequest — and so a short pick stays expressible as more than one fact.
 */
final class AutomaticSourcePicker
{
    public function __construct(private readonly InventoryDetailRepository $details)
    {
    }

    /**
     * @return list<array{detail: InventoryDetail, quantity: string}>
     *
     * @throws InsufficientStockException when the warehouse cannot cover the request at all
     */
    public function plan(ProductCore $product, Warehouse $warehouse, string|int|float $quantity): array
    {
        // Exact decimal strings throughout, so a fractional row is taken from exactly and nothing
        // here ever mixes an int with a decimal string (which PHP resolves as a float).
        $wanted = QuantityScale::canonical($quantity);
        if (QuantityScale::compare($wanted, 0) <= 0) {
            return [];
        }

        $plan = [];
        $outstanding = $wanted;

        foreach ($this->details->pickableRows($product, $warehouse) as $row) {
            if (QuantityScale::compare($outstanding, 0) <= 0) {
                break;
            }

            $available = QuantityScale::canonical($row->getQuantity());
            $take = QuantityScale::compare($outstanding, $available) <= 0 ? $outstanding : $available;
            if (QuantityScale::compare($take, 0) <= 0) {
                continue;
            }

            $plan[] = ['detail' => $row, 'quantity' => $take];
            $outstanding = QuantityScale::sub($outstanding, $take);
        }

        if (QuantityScale::compare($outstanding, 0) > 0) {
            throw new InsufficientStockException(sprintf(
                'Only %s of the %s requested unit(s) of %s are available in %s.',
                QuantityScale::sub($wanted, $outstanding),
                $wanted,
                $product->getSku() ?: ('#' . ($product->getId() ?? '?')),
                $warehouse->getName(),
            ));
        }

        return $plan;
    }

    /**
     * The same plan, already folded into $request as `from` sides pointing at $status.
     *
     * The `to` side reuses each source row's own bin, lot and serial with only the status changed,
     * which is what "these exact units became damaged / were picked / were shipped" means. A
     * terminal status drops the bin on the way in — DetailKey::forStatus() does that, not this.
     */
    public function addWithdrawal(
        MovementRequest $request,
        ProductCore $product,
        Warehouse $warehouse,
        string|int|float $quantity,
        string $toStatus,
    ): MovementRequest {
        foreach ($this->plan($product, $warehouse, $quantity) as $step) {
            $row = $step['detail'];

            $from = new DetailKey(
                $warehouse,
                $row->getLocation(),
                $row->getLot(),
                $row->getSerial(),
                InventoryDetail::STATUS_AVAILABLE,
            );

            $request->move($product, $from, $from->forStatus($toStatus), $step['quantity']);
        }

        return $request;
    }
}
