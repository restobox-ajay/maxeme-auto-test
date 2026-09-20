<?php

declare(strict_types=1);

namespace App\Command\Demo;

use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use App\Service\OrderNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds an approved demo sales order for a set of SKUs.
 *
 * ## Why a core factory rather than a few lines in the seeder that wants one
 *
 * Because the seeder that wants one is `app:seed-demo-warehouse-ops`, inside WarehouseOpsBundle, and
 * a sales order is not that bundle's document. The bundle's own architecture test
 * (`NoSecondWritePathTest`) makes the point bluntly by refusing any `->setQuantity(` anywhere in its
 * source — a rule written to stop stock being moved without a movement behind it, and one that
 * catches an order line's quantity too. That is a coarse match, but the instinct behind it is right
 * here as well: the pick round is warehouse-ops' business, and the order it picks for is core's.
 *
 * So the bundle says which SKUs and how many, and core builds the document — approving it through
 * `SalesOrder::setStatus('Approved', ...)`, which is the only way an order becomes pickable at all,
 * because a Draft
 * holds no stock and PickListCompiler skips it.
 *
 * ## Why the orders exist
 *
 * PickListCompiler skips any line whose product is not dimensional, and the sell-side seeder's
 * catalogue is entirely simple, so nothing it creates can be picked. These orders are approved and
 * left uninvoiced deliberately: the compiler takes each line's UNINVOICED quantity, less anything
 * backordered, so an invoiced order has nothing left to pick and would compile to an empty list.
 *
 * They carry DemoSeed::DOCUMENT_NOTE in their special instructions, which is both honest on screen
 * and the tag DemoDataCleaner purges them by — an order has no code column, and its number comes
 * from the shared allocator, so there is nowhere else to put one.
 */
final class DemoSalesOrderFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderNumberGenerator $orderNumbers,
    ) {
    }

    /**
     * One approved order, persisted and flushed.
     *
     * @param array<string, int> $lines sku => whole units
     */
    public function approvedOrder(
        Company $company,
        string $regionName,
        array $lines,
        string $poNumber,
        int $daysAgo,
        DocumentActor $actor,
    ): SalesOrder {
        $products = $this->em->getRepository(ProductCore::class);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($this->orderNumbers->next($this->em))
            // The REGION, not a warehouse. OrderInventoryBucketResolver resolves each line's
            // warehouse from the line's own location or, failing that, the order's region — so this
            // is what decides which building is expected to ship it, and therefore which warehouse's
            // pick round can compile it.
            ->setFulfillmentRegion($regionName)
            ->setDocumentDate((new \DateTimeImmutable(sprintf('-%d days', max(0, $daysAgo))))->format('Y-m-d'))
            ->setSource('admin')
            ->setPoNumber($poNumber)
            ->setPaymentMethod('Net Terms')
            ->setPaymentTerm('Net 30')
            ->setSpecialInstructions(DemoSeed::DOCUMENT_NOTE);

        // Frozen copies of the address book rows rather than references to them — see
        // AbstractDocumentAddress. Any other way lets a later address edit rewrite the record of
        // where goods went.
        $order->setBillingAddressFrom($company->getDefaultBillingAddress());
        $order->setShippingAddressFrom($company->getDefaultShippingAddress());
        $order->snapshotCompany($company);

        $subtotal = 0.0;
        $sortOrder = 0;

        foreach ($lines as $sku => $units) {
            $product = $products->findOneBy(['sku' => $sku]);
            if (!$product instanceof ProductCore) {
                continue;
            }

            $price = (float) $product->getDefaultPrice();
            $subtotal += $price * $units;

            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setSku($sku)
                    ->setUnit($product->getUnit())
                    ->setQuantity(number_format((float) $units, 2, '.', ''))
                    ->setCost((string) $product->getCostPrice())
                    ->setPrice(number_format($price, 2, '.', ''))
                    ->setSubtotal(number_format($price * $units, 2, '.', ''))
                    ->setTaxCode('Taxable')
                    ->setSortOrder($sortOrder++),
            );
        }

        // A flat 5% stands in for the tax engine. Nothing here runs the calculators: an order's
        // tax_lines snapshot is the record of what was actually charged, and inventing one would be
        // claiming a calculation that never ran.
        $tax = round($subtotal * 0.05, 2);

        $order
            ->setSubtotal(number_format($subtotal, 2, '.', ''))
            ->setTax(number_format($tax, 2, '.', ''))
            ->setTotal(number_format($subtotal + $tax, 2, '.', ''));

        $this->em->persist($order);
        $this->em->flush();

        // Through the one gate, because an order only holds stock — and so only becomes pickable —
        // once somebody has accepted it.
        $order->setStatus('Approved', $actor, 'Order approved.');
        $this->em->flush();

        return $order;
    }
}
