<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\ShipmentLine;
use Twig\Environment;

/**
 * A "Shipments" box on the invoice detail page
 * (`docs/plans/2026-09-14-shipment-dispatch.md`'s injection-point panel — named and deliberately
 * deferred in the original plan until the Shipment screens themselves existed: *"wiring it in is UI
 * polish for showing shipments on the invoice screen... Build it when the Shipment screens
 * themselves are built, as ordinary controller/template work, not as a core-vs-bundle integration
 * exercise up front."*
 *
 * ## Core has no idea this exists
 *
 * `App\Contract\Hook\InjectionPointProviderInterface` is how that stays true: this class is
 * discovered purely by the `app.injection_point` tag and matched at render time against
 * `admin_invoice_detail_after_totals`, a slot `templates/admin/invoice/detail.html.twig` already
 * calls unconditionally (through the shared `commercial_document_view.html.twig` base) whether or
 * not anything answers it. Delete this bundle, or switch it Inactive on App Management, and the slot
 * renders nothing — `App\Twig\InjectionPointExtension` filters providers by
 * `BundleStatusRepository::isActive($provider->getSource())` before this class is ever asked to
 * render, so there is nothing to guard against here.
 *
 * ## What it shows, and what it deliberately doesn't
 *
 * Every `ShipmentLine` recorded against any line on this invoice, across however many separate
 * shipments — including a combined shipment that also carries lines from a *different* invoice for
 * the same company, which this panel correctly has no reason to mention. A link to
 * `ShipmentController::new()` pre-filled with this invoice already added, so "Record a shipment" from
 * here is one click into a form that already knows which invoice it's for.
 *
 * Read-only. This never writes anything — the same discipline as every other injection-point panel
 * in this codebase.
 */
final class InvoiceShipmentsPanelProvider implements InjectionPointProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
    ) {
    }

    public function getPoint(): string
    {
        return 'admin_invoice_detail_after_totals';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return 'InventoryDepthBundle';
    }

    public function render(array $context): string
    {
        $invoice = $context['document'] ?? null;
        if (!$invoice instanceof Invoice || $invoice->getId() === null) {
            return '';
        }

        /** @var list<ShipmentLine> $lines */
        $lines = $this->em->createQueryBuilder()
            ->select('sl', 's')
            ->from(ShipmentLine::class, 'sl')
            ->innerJoin('sl.shipment', 's')
            ->innerJoin('sl.invoiceLine', 'il')
            ->andWhere('il.invoice = :invoice')->setParameter('invoice', $invoice)
            ->orderBy('s.shippedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->twig->render('@InventoryDepth/_invoice_shipments_panel.html.twig', [
            'invoice' => $invoice,
            'lines' => $lines,
        ]);
    }
}
