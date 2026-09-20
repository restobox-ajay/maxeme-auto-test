<?php

declare(strict_types=1);

namespace App\Service\Uom;

/**
 * A unit-of-measure change the model refuses to make (#601, phase 1 #643).
 *
 * One exception class for both refusals, because they are one rule wearing two hats: **a unit never
 * changes meaning once something is denominated in it.** A packaging rung a document references and
 * a product's base unit under existing stock are the same situation — restating history by editing a
 * number nobody is looking at.
 *
 * The message is written for the admin who hit it and is rendered straight into a flash: it says
 * what was refused and what to do instead (add a new rung and repoint; make a new product), never
 * "constraint violation".
 */
final class UnitOfMeasureRefusal extends \RuntimeException
{
}
