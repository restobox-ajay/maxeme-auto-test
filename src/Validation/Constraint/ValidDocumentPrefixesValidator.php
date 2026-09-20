<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the check ConfigController::documentPrefixes() used to run by hand. */
final class ValidDocumentPrefixesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidDocumentPrefixes) {
            throw new UnexpectedTypeException($constraint, ValidDocumentPrefixes::class);
        }

        if (!$value instanceof \ArrayAccess) {
            throw new UnexpectedValueException($value, \ArrayAccess::class);
        }

        // Every submitted prefix is checked, rather than two named ones, so adding a document type
        // (invoice, #539) is a change to the form and nothing else. The message stays shared and
        // singular because the original check reported one regardless of which prefix failed.
        //
        // Allow a trailing/embedded hyphen too, so prefixes like QT- or SO- work (issue #122).
        //
        // The slash is here because of #615, and it is a widening rather than a preference. The
        // purchase order, receipt and vendor bill prefixes moved onto this screen from
        // ProcurementBundle's own, whose check was `^[A-Z0-9]{1,8}[-\/]?$` — so an instance may
        // already have `PO/` stored. Everything that rule accepted this one now accepts, and
        // nothing this rule accepted before is rejected. A slash is safe in a number:
        // DocumentNumberAllocator seeds a counter with SUBSTR by prefix length, never by parsing
        // the separator.
        foreach ($value as $prefix) {
            if (!preg_match('/^[A-Z0-9\/-]{1,10}$/', (string) $prefix)) {
                $this->context->buildViolation('Prefixes must be 1-10 letters, numbers, hyphens or slashes, for example QO-, QT- or SO-.')->addViolation();

                return;
            }
        }
    }
}
