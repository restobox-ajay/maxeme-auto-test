<?php

declare(strict_types=1);

namespace App\Maxeme\Twig;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Repository\InvoiceRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ repair_order_invoices(repairOrder) }}: its invoices, newest first, for the document switcher's
 * Invoice menu (maxeme/repair_order/_docnav.html.twig, mockup 345c037).
 */
final class RepairOrderDocumentsExtension extends AbstractExtension
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('repair_order_invoices', $this->invoices(...))];
    }

    /** @return list<Invoice> */
    public function invoices(RepairOrder $repairOrder): array
    {
        return $repairOrder->getId() !== null ? $this->invoices->findForRepairOrder($repairOrder) : [];
    }
}
