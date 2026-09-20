<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Contract\Tax\TaxContext;
use App\Entity\ProductCore;
use App\Repository\AuditLogRepository;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActorResolver;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\RfqLine;
use ProcurementBundle\Entity\RfqVendorReply;
use ProcurementBundle\Entity\RfqVendorReplyLine;
use ProcurementBundle\Tax\PurchaseDocumentTax;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One vendor's priced reply to an RFQ, as a document screen: view, price, print.
 *
 * ## Why this controller exists at all
 *
 * `RfqVendorReply` extends `AbstractPurchaseDocument`, carries a number, a vendor, a date, a
 * currency, a subtotal, tax and a total, and IS a `CommercialDocument` — and it had no screen. It
 * was priced through a two-column form bolted into a cell of the RFQ detail page's replies table,
 * one per reply, five of them stacked on one page if five vendors were asked. The money document of
 * the whole tender flow was the only document in the application you could not open.
 *
 * This is the estimate's screen set, applied to it. `App\Controller\Admin\EstimateController` is the
 * reference deliberately — not the purchase order page, which is a sketch of the same kind being
 * rebuilt on its own branch. What an estimate is to a customer, a vendor reply is to us: one
 * counterparty, priced, convertible into an order exactly once.
 *
 * ## What is deliberately NOT here
 *
 * **The RFQ's own money, because there is none.** The header names a requirement put to several
 * vendors at once, so it has no counterparty, no currency and no total until one of them answers —
 * the argued exception in `EveryDocumentDeclaresItsContractTest::NOT_COMMERCIAL`. Every price on
 * this screen belongs to THIS reply and to no other, which is the property
 * `RfqVendorReplyScreensCest` conducts against the replies that were not priced.
 *
 * **A list screen.** A reply is reached from its RFQ, the way a receipt is reached from its
 * purchase order: there is no question a flat list of every vendor quote in the database answers
 * that "open the RFQ and read its replies side by side" does not answer better.
 *
 * **Fees.** A vendor's quote for freight or brokerage is real and is not built anywhere in this
 * application; purchase fees are separate new logic, deliberately not started here. See
 * `PurchaseDocumentTax` for where they attach when they arrive.
 */
#[Route('/admin/bundles/procurement/rfq-replies')]
final class RfqVendorReplyController extends AbstractProcurementController
{
    use RendersAPrintableDocument;

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly PurchaseDocumentTax $tax,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    #[Route('/{id}', name: 'admin_bundle_procurement_rfq_reply', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id, AuditLogRepository $auditLogs): Response
    {
        $this->denyIfInactive();

        $reply = $this->replyOr404($id);

        // The estimate detail ends with the same panel, fed the same way. It is worth more on a
        // quote than on most documents: the figures on this page are what somebody will be held to,
        // and "who changed the unit cost after the vendor gave it" is a question the document itself
        // cannot answer. AuditLogSubscriber already records every change to this entity — nothing
        // here writes the trail, it only renders it.
        return $this->render('@Procurement/rfq_vendor_reply_detail.html.twig', $this->documentView($reply) + [
            'auditHistory' => $auditLogs->findForEntity('RfqVendorReply', $id),
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_bundle_procurement_rfq_reply_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyIfInactive();

        $reply = $this->replyOr404($id);

        // Accepted, Rejected and Declined are terminal: an accepted quote is a purchase order
        // already raised, and a rejected one is the record of what was turned down. Redirected
        // rather than 404'd — the document is still there to read, which is exactly what the
        // estimate edit route does with an Accepted quote.
        if (!$reply->getStatus()->allowsPricing()) {
            $this->addFlash('error', sprintf(
                'Reply %s is %s and can no longer be priced. What was quoted is still on the document.',
                $reply->getDocumentNumber(),
                $reply->getStatus()->value,
            ));

            return $this->redirectToRoute('admin_bundle_procurement_rfq_reply', ['id' => $id]);
        }

        return $this->render('@Procurement/rfq_vendor_reply_edit.html.twig', $this->documentView($reply));
    }

    /**
     * Records what this vendor quoted.
     *
     * An admin types this, not the vendor: a `VendorContact` carries no login identity — the same
     * rule the customer-side vendor mirror follows — so a quote arrives by phone or email and
     * somebody in this building writes it down.
     *
     * Every requirement line gets a row, priced or not. `RfqVendorReplyLine::$unitCost` is nullable
     * because null means "not quoted yet" and is a different fact from a real $0.00 — the
     * distinction `RfqVendorReply::isFullyPriced()` reads, and therefore the distinction that
     * decides whether this reply can be accepted at all. The screen this replaces skipped blank
     * boxes entirely, so a half-recorded reply was indistinguishable from one nobody had opened.
     */
    #[Route('/{id}/save', name: 'admin_bundle_procurement_rfq_reply_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $reply = $this->replyOr404($id);

        if (!$reply->getStatus()->allowsPricing()) {
            $this->addFlash('error', sprintf('Reply %s is %s and can no longer be priced.', $reply->getDocumentNumber(), $reply->getStatus()->value));

            return $this->redirectToRoute('admin_bundle_procurement_rfq_reply', ['id' => $id]);
        }

        $reply
            ->setDocumentDate($this->calendarDate((string) $request->request->get('document_date', '')) ?? $reply->getDocumentDate())
            ->setNotes($this->nullable((string) $request->request->get('notes', '')));

        // Per document and never converted — see AbstractPurchaseDocument::$currency. setCurrency()
        // refuses anything that is not three letters and keeps CAD, so a typo cannot denominate a
        // quote in nothing.
        $currency = trim((string) $request->request->get('currency', ''));
        if ($currency !== '') {
            $reply->setCurrency($currency);
        }

        /** @var array<string, string> $costs */
        $costs = $request->request->all('unit_cost');
        /** @var array<string, string> $lineNotes */
        $lineNotes = $request->request->all('line_notes');

        $existing = $this->linesByRequirement($reply);

        foreach ($reply->getRfq()->getLines() as $requirement) {
            $requirementId = (string) $requirement->getId();
            $line = $existing[$requirementId] ?? null;

            if (!$line instanceof RfqVendorReplyLine) {
                $line = (new RfqVendorReplyLine())->setRfqLine($requirement);
                $reply->addLine($line);
                $this->em->persist($line);
            }

            // A key the form did not carry is not an erasure: a narrower POST leaves the row as it
            // stands, the same rule the estimate's line save follows for the columns its own form
            // may or may not render.
            if (array_key_exists($requirementId, $costs)) {
                $raw = trim((string) $costs[$requirementId]);
                $unitCost = ($raw === '' || !is_numeric($raw)) ? null : number_format((float) $raw, 6, '.', '');

                $line
                    ->setUnitCost($unitCost)
                    ->setSubtotal($unitCost === null ? null : self::lineSubtotal($requirement->getQuantity(), $unitCost));
            }

            if (array_key_exists($requirementId, $lineNotes)) {
                $line->setNotes($this->nullable((string) $lineNotes[$requirementId]));
            }
        }

        // Tax through the SHARED calculators, from our own province — see PurchaseDocumentTax for
        // why the buy side has no tax model of its own and must not grow one.
        $reply->setTax(number_format($this->breakdown($reply)['total'], 2, '.', ''));
        $reply->recalculateTotals();

        // markReplied() is what says the vendor's prices are in, and it refuses a half-priced
        // reply — so an admin who has recorded three of five lines saves what they have and the
        // document keeps saying Invited, which is true. Called only when it will succeed rather
        // than caught after the fact: a DomainException flashed on every partial save would train
        // people to ignore the banner that also carries the real refusals.
        $message = sprintf('Quote saved for %s.', $reply->getVendorName());
        if ($reply->isFullyPriced()) {
            $reply->markReplied();
            $message = sprintf('Quote recorded for %s — %s %s.', $reply->getVendorName(), $reply->getCurrency(), $reply->getTotal());
        } else {
            $message .= sprintf(' %d of %d lines still have no price, so it is not ready to accept.', $this->unpricedCount($reply), $reply->getRfq()->getLines()->count());
        }

        $this->em->flush();
        $this->addFlash('success', $message);

        return $this->redirectToRoute(
            (string) $request->request->get('action', '') === 'save_exit'
                ? 'admin_bundle_procurement_rfq_reply'
                : 'admin_bundle_procurement_rfq_reply_edit',
            ['id' => $id],
        );
    }

    /**
     * The quote as a document — the thing an admin prints, files against the tender, or attaches to
     * the approval that raises the purchase order.
     *
     * Same `is_pdf ? pdf_layout : layout` switch the purchase order print uses, which the UI audit
     * named as the one correct example of it in this bundle.
     */
    #[Route('/{id}/print', name: 'admin_bundle_procurement_rfq_reply_print', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function document(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $reply = $this->replyOr404($id);
        $isPdf = $request->query->get('pdf') === '1';

        $html = $this->renderView('@Procurement/rfq_vendor_reply_document.html.twig', $this->documentView($reply) + ['is_pdf' => $isPdf]);

        return $isPdf
            ? $this->pdfResponse($this->dompdf($html), sprintf('VendorQuote-%s.pdf', $reply->getDocumentNumber()))
            : new Response($html);
    }

    /**
     * Everything all three screens render, built once.
     *
     * The rows are driven by the REQUIREMENT, not by the reply's own lines: the RFQ asks for the
     * same things of every vendor, and a reply that has priced two of four is two rows priced and
     * two rows blank — not a two-row document. A reply line whose requirement has gone (the FK is
     * ON DELETE SET NULL precisely so losing the link never deletes a quote actually given) is
     * appended at the end rather than dropped, because it is money somebody was quoted.
     *
     * @return array<string, mixed>
     */
    private function documentView(RfqVendorReply $reply): array
    {
        $breakdown = $this->breakdown($reply);
        $byRequirement = $this->linesByRequirement($reply);

        $rows = [];
        $index = 0;
        foreach ($reply->getRfq()->getLines() as $requirement) {
            $line = $byRequirement[(string) $requirement->getId()] ?? null;
            $rows[] = [
                'requirement' => $requirement,
                'line' => $line,
                'taxCode' => self::taxCodeFor($requirement),
                'tax' => $breakdown['perLineTax'][$index] ?? null,
                'taxLabel' => $breakdown['perLineTaxLabel'][$index] ?? '',
            ];
            ++$index;
        }

        foreach ($reply->getLines() as $line) {
            if ($line->getRfqLine() === null) {
                $rows[] = ['requirement' => null, 'line' => $line, 'taxCode' => 'E', 'tax' => null, 'taxLabel' => ''];
            }
        }

        return [
            'reply' => $reply,
            'rfq' => $reply->getRfq(),
            'rows' => $rows,
            'taxLines' => $breakdown['lines'],
            'taxProvince' => $this->tax->buyerProvince(),
            'unpriced' => $this->unpricedCount($reply),
        ];
    }

    /**
     * @return array{lines: \App\Contract\Tax\TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>}
     */
    private function breakdown(RfqVendorReply $reply): array
    {
        $byRequirement = $this->linesByRequirement($reply);

        $lines = [];
        foreach ($reply->getRfq()->getLines() as $requirement) {
            $line = $byRequirement[(string) $requirement->getId()] ?? null;
            $subtotal = $line?->getSubtotal();

            $lines[] = [
                // Null, not 0.0, for an unpriced line: the breakdown reports TBD for it instead of
                // asserting the goods are tax-free next to an empty price box.
                'subtotal' => $subtotal === null ? null : (float) $subtotal,
                'taxCode' => self::taxCodeFor($requirement),
            ];
        }

        return $this->tax->forLines($lines);
    }

    /**
     * This reply's lines keyed by the requirement each one prices.
     *
     * @return array<string, RfqVendorReplyLine>
     */
    private function linesByRequirement(RfqVendorReply $reply): array
    {
        $byRequirement = [];
        foreach ($reply->getLines() as $line) {
            $requirement = $line->getRfqLine();
            if ($requirement instanceof RfqLine && $requirement->getId() !== null) {
                $byRequirement[(string) $requirement->getId()] = $line;
            }
        }

        return $byRequirement;
    }

    private function unpricedCount(RfqVendorReply $reply): int
    {
        $byRequirement = $this->linesByRequirement($reply);

        $unpriced = 0;
        foreach ($reply->getRfq()->getLines() as $requirement) {
            $line = $byRequirement[(string) $requirement->getId()] ?? null;
            if ($line === null || $line->getUnitCost() === null) {
                ++$unpriced;
            }
        }

        return $unpriced;
    }

    /**
     * What tax class this line's goods are in.
     *
     * The product's own code, which is the same column the sell side reads — the goods are taxable
     * or they are not, and that does not change with the direction they travel. A line naming no
     * product (a requirement typed by hand) maps to 'E' through `TaxContext::mapTaxCode()`, which
     * is what an unknown tax class has always meant on both sides.
     */
    private static function taxCodeFor(?RfqLine $requirement): string
    {
        $product = $requirement?->getProduct();

        return TaxContext::mapTaxCode($product instanceof ProductCore ? $product->getSalesTaxCode() : null);
    }

    /**
     * Quantity in ten-thousandths times unit cost in millionths, back to cents — never floats end to
     * end, for the reason `AbstractPurchaseDocument::cents()` gives: 0.10 + 0.20 is not 0.30 in
     * binary, and a quote a hundredth of a cent out is a quote that never matches the bill.
     *
     * The scales are the columns': `rfq_line.quantity` is NUMERIC(14,4) and
     * `rfq_vendor_reply_line.unit_cost` is NUMERIC(18,6) since #645, so a third of a case at
     * $0.833333 each is carried at full width right up to the cent it is rounded to.
     */
    /** Quantity times unit cost, to the cent. Exact throughout — no float, no scaling to lose track of. */
    private static function lineSubtotal(string $quantity, string $unitCost): string
    {
        return bcround(bcmul($quantity, $unitCost, 10), 2, \RoundingMode::HalfAwayFromZero);
    }

    private function replyOr404(int $id): RfqVendorReply
    {
        $reply = $this->em->find(RfqVendorReply::class, $id);
        if (!$reply instanceof RfqVendorReply) {
            throw new NotFoundHttpException('No such vendor reply.');
        }

        return $reply;
    }
}
