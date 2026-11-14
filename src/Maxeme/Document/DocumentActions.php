<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A document page's PDF menu (maxeme/document/page.html.twig): Print, Save as PDF, Save and Email of
 * the document shown. Going to the repair order's other documents is the switcher beside the title
 * (maxeme/repair_order/_docnav.html.twig, mockup 345c037). Offered only to a role allowed to open the
 * document; Save and Email only to one allowed to edit it.
 */
final class DocumentActions
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly Security $security,
    ) {
    }

    /** @return list<array<string, mixed>> the {menu: {label, printUrl, pdfUrl, emailUrl}} of the document shown, or nothing */
    public function for(DocumentKind $current, ?RepairOrder $repairOrder, ?Invoice $invoice = null): array
    {
        if ($current === DocumentKind::Invoice) {
            if ($invoice === null || !$this->security->isGranted(Permission::ACCOUNTING_VIEW)) {
                return [];
            }
            $key = ['invoiceKey' => $invoice->getInvoiceKey()];

            return [['menu' => [
                'label' => 'Invoice PDF',
                'printUrl' => null,
                'pdfUrl' => $this->urls->generate('maxeme_invoice_pdf', $key),
                'emailUrl' => $this->security->isGranted(Permission::ACCOUNTING_EDIT) ? $this->urls->generate('maxeme_invoice_send', $key) : null,
            ]]];
        }
        if ($repairOrder === null || !$this->security->isGranted(Permission::WORK_ORDER_VIEW)) {
            return [];
        }
        $route = $current === DocumentKind::Quote ? 'maxeme_repair_order_quote' : 'maxeme_repair_order_work_order';
        $id = ['id' => $repairOrder->getId()];

        return [['menu' => [
            'label' => $current->emailTitle() . ' PDF',
            'printUrl' => null,
            'pdfUrl' => $this->urls->generate($route . '_pdf', $id),
            'emailUrl' => $this->security->isGranted(Permission::WORK_ORDER_EDIT) ? $this->urls->generate($route . '_email', $id) : null,
        ]]];
    }
}
