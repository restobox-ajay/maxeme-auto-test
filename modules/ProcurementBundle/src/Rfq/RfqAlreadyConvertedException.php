<?php

declare(strict_types=1);

namespace ProcurementBundle\Rfq;

use ProcurementBundle\Entity\Rfq;

/**
 * Raised when an RFQ is asked to convert a second time — the ordinary double-submit, and the losing
 * side of two simultaneous "accept this quote" clicks (see RfqConversionService::claimForConversion()).
 *
 * Mirrors App\Service\EstimateAlreadyConvertedException exactly, including why it is its own type
 * rather than a \LogicException: it is not a programming error, and the caller's correct response
 * is to send the admin to the purchase order that already exists.
 */
final class RfqAlreadyConvertedException extends \RuntimeException
{
    public static function forRfq(Rfq $rfq): self
    {
        return new self(sprintf('RFQ %s has already been converted to a purchase order.', $rfq->documentLabel()));
    }
}
