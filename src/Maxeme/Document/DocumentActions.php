<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A document page's action bar (maxeme/document/page.html.twig): the document's own PDF menu (Print,
 * Save as PDF, Save and Email), then "Go to" and the PDF menu of each of the repair order's other two
 * documents. The invoice is the one given, or the repair order's current one; with none, "Go to
 * Invoice" opens the repair order's Invoices tab to issue one. Each link is offered only to a role
 * allowed to follow it.
 */
final class DocumentActions
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly Security $security,
        private readonly InvoiceRepository $invoices,
    ) {
    }

    /** @return list<array<string, mixed>> {label, url, class} links and {menu: {label, printUrl, pdfUrl, emailUrl}} menus */
    public function for(DocumentKind $current, ?RepairOrder $repairOrder, ?Invoice $invoice = null): array
    {
        $invoice ??= $repairOrder !== null ? $this->invoices->findCurrentForRepairOrder($repairOrder) : null;
        $order = [$current, ...array_values(array_filter([DocumentKind::Invoice, DocumentKind::WorkOrder, DocumentKind::Quote], static fn (DocumentKind $kind): bool => $kind !== $current))];

        $actions = [];
        foreach ($order as $kind) {
            $isCurrent = $kind === $current;
            if ($kind === DocumentKind::Invoice) {
                if (!$this->security->isGranted(Permission::ACCOUNTING_VIEW)) {
                    continue;
                }
                if ($invoice === null) {
                    if ($repairOrder !== null) {
                        $actions[] = ['label' => 'Go to Invoice', 'url' => $this->urls->generate('maxeme_repair_order_invoices', ['id' => $repairOrder->getId()])];
                    }
                    continue;
                }
                $key = ['invoiceKey' => $invoice->getInvoiceKey()];
                if (!$isCurrent) {
                    $actions[] = ['label' => 'Go to Invoice', 'url' => $this->urls->generate('maxeme_invoice_show', $key)];
                }
                $actions[] = ['menu' => [
                    'label' => 'Invoice PDF',
                    'printUrl' => $isCurrent ? null : $this->urls->generate('maxeme_invoice_show', $key),
                    'pdfUrl' => $this->urls->generate('maxeme_invoice_pdf', $key),
                    'emailUrl' => $this->security->isGranted(Permission::ACCOUNTING_EDIT) ? $this->urls->generate('maxeme_invoice_send', $key) : null,
                ]];
                continue;
            }

            if ($repairOrder === null || !$this->security->isGranted(Permission::WORK_ORDER_VIEW)) {
                continue;
            }
            $route = $kind === DocumentKind::Quote ? 'maxeme_repair_order_quote' : 'maxeme_repair_order_work_order';
            $id = ['id' => $repairOrder->getId()];
            if (!$isCurrent) {
                $actions[] = ['label' => 'Go to ' . $kind->emailTitle(), 'url' => $this->urls->generate($route, $id)];
            }
            $actions[] = ['menu' => [
                'label' => $kind->emailTitle() . ' PDF',
                'printUrl' => $isCurrent ? null : $this->urls->generate($route, $id),
                'pdfUrl' => $this->urls->generate($route . '_pdf', $id),
                'emailUrl' => $this->security->isGranted(Permission::WORK_ORDER_EDIT) ? $this->urls->generate($route . '_email', $id) : null,
            ]];
        }

        return $actions;
    }
}
