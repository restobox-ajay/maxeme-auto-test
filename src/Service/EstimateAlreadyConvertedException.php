<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Estimate;

/**
 * Raised when an estimate is asked to convert a second time — the ordinary double-submit, and the
 * losing side of two simultaneous accepts (see EstimateConversionService::claimForConversion()).
 *
 * Its own type rather than the \LogicException the other guards in convert() throw, because it is
 * not a programming error: the caller's correct answer is to send the customer to the order that
 * already exists, not to report a failure for work that in fact succeeded.
 */
final class EstimateAlreadyConvertedException extends \RuntimeException
{
    public static function forEstimate(Estimate $estimate): self
    {
        return new self(sprintf('Estimate %s has already been converted to an order.', $estimate->getDocumentNumber()));
    }
}
