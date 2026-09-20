<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

/**
 * One product's declaration contradicts itself, so that product is left exactly as it was (#565).
 *
 * The case this exists for: a product carrying **both** a product-level total and bin rows in the
 * same file. If bin rows are given they are the declaration and the total is their sum, so a
 * separate total sitting alongside them is two answers to one question.
 *
 * Refusing beats resolving. Deriving a winner silently would let a typo'd total through unnoticed,
 * and a wrong total nobody was told about is the one outcome that cannot be spotted afterwards.
 * Refusing a product costs someone a re-upload; accepting a wrong number costs a stock count.
 *
 * **Per product, never per run.** Core catches this, leaves that product's rows and total
 * untouched, names it in the summary, and imports everything else normally — the same shape the
 * importer already uses for row issues.
 */
final class DimensionalImportRefusal extends \RuntimeException
{
}
