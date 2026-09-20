<?php

declare(strict_types=1);

namespace ProcurementBundle\Rfq;

use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Rfq;
use ProcurementBundle\Entity\RfqVendorReply;
use ProcurementBundle\Enum\RfqStatus;
use ProcurementBundle\Enum\RfqVendorReplyStatus;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;
use ProcurementBundle\Purchase\PurchaseTaxBreakdown;

/**
 * RfqVendorReply -> PurchaseOrder (#637): a copy, not an in-place conversion — mirroring
 * `App\Service\EstimateConversionService` deliberately, including the one piece that matters most,
 * the atomic claim.
 *
 * ## Why the claim has to be atomic here too
 *
 * An RFQ can have several replies sitting Replied at once — that is the entire point of a tender.
 * Two admins opening the same RFQ and each clicking "Accept" on a different reply at the same
 * moment is exactly the estimate double-accept race, with one more way to trigger it: it can also
 * happen on the SAME reply, clicked twice. A single conditional UPDATE against the `rfq` row —
 * Sent -> Accepted, only while no reply has already won — settles both at once.
 *
 * ## What "the vendor whose quote was NOT taken" means here
 *
 * #637's own conducted-test requirement, quoted verbatim in the issue: "assert the resulting
 * purchase_order and purchase_order_line rows, including the vendor whose quote was NOT taken."
 * Every other Replied reply on the same RFQ is marked Rejected in the same operation the winner is
 * marked Accepted — see rejectOtherReplies() — so that row is exactly as inspectable as the winner.
 */
final class RfqConversionService
{
    public function __construct(
        private readonly PurchaseDocumentNumberGenerator $numbers,
        // #655: purchase_order.tax became a DERIVED figure — the sum of the document's frozen tax
        // lines — so a quote's tax has to arrive as a line rather than as a scalar, or
        // recalculateTotals() reads an empty snapshot and zeroes a tax the vendor actually quoted.
        private readonly PurchaseTaxBreakdown $purchaseTax,
    ) {
    }

    /**
     * $actorName only labels the purchase order log entry this writes; the service has no security
     * context of its own, matching EstimateConversionService's own reasoning.
     */
    public function convert(RfqVendorReply $reply, EntityManagerInterface $entityManager, ?string $actorName = null): PurchaseOrder
    {
        $rfq = $reply->getRfq();

        if ($rfq->getStatus() !== RfqStatus::Sent) {
            throw new \LogicException('Only an RFQ that has been sent can have a reply accepted.');
        }
        if ($reply->getStatus() !== RfqVendorReplyStatus::Replied) {
            throw new \LogicException('Only a fully-priced reply can be accepted.');
        }
        if (!$reply->isFullyPriced()) {
            throw new \LogicException('Cannot convert a reply that is not fully priced.');
        }
        // Cheap in-memory rejection of the ordinary double-submit — every entry point gets it, not
        // just the accept action — deliberately not the whole story; see claimForConversion() for
        // the part that actually closes the race.
        if ($reply->getPurchaseOrder() !== null) {
            throw RfqAlreadyConvertedException::forRfq($rfq);
        }

        $this->claimForConversion($rfq, $entityManager);

        // Rfq::send() refuses to leave Draft without a warehouse, and conversion is only reachable
        // from Sent — so this is guaranteed, not merely hoped for. Asserted rather than left to
        // PurchaseOrder::$warehouse's own uninitialized-property error, which would name the wrong
        // class to whoever reads the exception.
        $warehouse = $rfq->getWarehouse();
        if ($warehouse === null) {
            throw new \LogicException(sprintf('RFQ %s has no warehouse; it should not have been sendable without one.', $rfq->documentLabel()));
        }

        $order = (new PurchaseOrder())
            ->setPoNumber($this->numbers->next($entityManager, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER))
            ->setVendor($reply->getVendor())
            // The warehouse and, with it, the tax province the RFQ's delivery site implies (queue
            // item 37). One call, because a destination that could move without the province
            // following it is two places holding one fact.
            ->deriveTaxProvinceFrom($warehouse)
            ->setCurrency($reply->getCurrency())
            ->setDocumentDate((new \DateTimeImmutable())->format('Y-m-d'))
            // The vendor's own quoted tax, carried across as a manual tax line rather than as the
            // bare `tax` scalar it used to be (#655). Same figure, and now a row that prints and
            // can be reported on — and, unlike the scalar, one that survives recalculateTotals().
            // No calculator is consulted: this is what the vendor said their tax would be, and a
            // quote is not ours to re-price.
            ->setTaxLines($this->purchaseTax->toJson($this->purchaseTax->quotedTaxBreakdown($reply->getTax())));

        $sortOrder = 0;
        foreach ($reply->getLines() as $replyLine) {
            $requirement = $replyLine->getRfqLine();

            $orderLine = (new PurchaseOrderLine())
                ->setProduct($requirement?->getProduct())
                ->setName($requirement?->getName() ?? 'Unnamed line')
                ->setSku($requirement?->getSku())
                ->setQuantityOrdered($requirement?->getQuantity() ?? '0.00')
                ->setUnitCost($replyLine->getUnitCost() ?? '0.0000')
                ->setSubtotal($replyLine->getSubtotal() ?? '0.00')
                ->setSortOrder($sortOrder++);

            $order->addLine($orderLine);
        }

        $order->recalculateTotals();
        $entityManager->persist($order);

        $reply->markAccepted($order);
        $rfq->markAccepted();
        $this->rejectOtherReplies($rfq, $reply);

        $this->logConversion($order, $reply, $actorName);

        return $order;
    }

    /**
     * Every OTHER reply on this RFQ that hadn't already reached a terminal state of its own — the
     * row #637's conducted test names explicitly: the vendor whose quote was not taken.
     */
    private function rejectOtherReplies(Rfq $rfq, RfqVendorReply $winner): void
    {
        foreach ($rfq->getReplies() as $candidate) {
            if ($candidate === $winner) {
                continue;
            }

            $candidate->markRejected();
        }
    }

    /**
     * Same mechanism as EstimateConversionService::claimForConversion(), same reason: a
     * read-then-write check cannot close a race where two requests both read Sent before either
     * writes, and a conditional UPDATE is atomic on both SQLite and MySQL with no schema change.
     */
    private function claimForConversion(Rfq $rfq, EntityManagerInterface $entityManager): void
    {
        $rfqId = $rfq->getId();
        if ($rfqId === null) {
            return;
        }

        $metadata = $entityManager->getClassMetadata(Rfq::class);
        $claimed = (int) $entityManager->getConnection()->executeStatement(
            sprintf(
                'UPDATE %s SET %s = ? WHERE %s = ? AND %s = ?',
                $metadata->getTableName(),
                $metadata->getColumnName('status'),
                $metadata->getSingleIdentifierColumnName(),
                $metadata->getColumnName('status'),
            ),
            [RfqStatus::Accepted->value, $rfqId, RfqStatus::Sent->value],
        );

        if ($claimed !== 1) {
            throw RfqAlreadyConvertedException::forRfq($rfq);
        }
    }

    private function logConversion(PurchaseOrder $order, RfqVendorReply $reply, ?string $actorName): void
    {
        $userName = trim((string) $actorName) !== '' ? trim((string) $actorName) : 'System';

        $order->queueActivityLogEntry()
            ->setUserName($userName)
            ->setComment(sprintf('Created from RFQ %s, vendor reply %s.', $reply->getRfq()->getDocumentNumber(), $reply->getDocumentNumber()))
            ->setType('System');
    }
}
