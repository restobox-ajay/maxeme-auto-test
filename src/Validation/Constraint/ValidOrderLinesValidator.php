<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Line-for-line port of OrderController::validateOrderLines(): at least one posted row has to be a
 * line the admin actually filled in (a product chosen or a name typed), or there is nothing for the
 * save to write. isBlankLine() is exposed as the single source of truth for that judgement — it used
 * to live as OrderController::isBlankOrderLine(), which now delegates here instead of restating it.
 */
final class ValidOrderLinesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidOrderLines) {
            throw new UnexpectedTypeException($constraint, ValidOrderLines::class);
        }

        if (!is_array($value)) {
            throw new UnexpectedValueException($value, 'array');
        }

        foreach ($value as $line) {
            if (is_array($line) && !self::isBlankLine($line)) {
                return;
            }
        }

        $this->context->buildViolation('Please add at least one product or blank line before saving the order.')
            ->addViolation();
    }

    /**
     * A posted row the admin never filled in — no product chosen, no name typed.
     *
     * product_id_manual counts as choosing a product. It is the no-JS id box the form renders inside
     * <noscript> once the catalog outgrows the inline <select> (see OrderController::lineProductId()),
     * and for those saves it is the ONLY field carrying the product — so leaving it out here dropped
     * the row as blank before the save loop ever looked at it.
     */
    public static function isBlankLine(array $line): bool
    {
        return self::rawLineValue($line['product_id'] ?? null) === ''
            && self::rawLineValue($line['product_id_manual'] ?? null) === ''
            && self::rawLineValue($line['name'] ?? null) === '';
    }

    /**
     * A line field's own text, or '' when missing or arrived as an array instead of a scalar. A
     * tampered or malformed post can turn `lines[N][product_id]`/`lines[N][name]` into a nested
     * array (e.g. `lines[N][name][]=`), and casting an array straight to string is a PHP warning
     * some environments promote to an uncaught error and a stack-trace 500 (#395) — this runs before
     * OrderController's own line loop even starts, so it needs the same guard
     * AbstractAdminController::rawLineAmount() applies there.
     */
    private static function rawLineValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
