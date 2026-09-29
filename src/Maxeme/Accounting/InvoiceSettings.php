<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The invoice tax rates (`maxeme.invoice`): each tax is either charged at its rate or not at all. */
final class InvoiceSettings
{
    public readonly int $gstRate;
    public readonly int $pstRate;

    /** @param array{gst_rate: int, pst_rate: int} $invoice */
    public function __construct(#[Autowire(param: 'maxeme.invoice')] array $invoice)
    {
        $this->gstRate = (int) $invoice['gst_rate'];
        $this->pstRate = (int) $invoice['pst_rate'];
    }

    /** $rate when it is 0 or the GST rate; otherwise the GST rate. */
    public function gst(int $rate): int
    {
        return $rate === 0 ? 0 : $this->gstRate;
    }

    public function pst(int $rate): int
    {
        return $rate === 0 ? 0 : $this->pstRate;
    }
}
