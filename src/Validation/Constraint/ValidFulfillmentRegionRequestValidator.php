<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\FulfillmentRegion;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Line-for-line port of the checks ConfigController::handleFulfillmentRegionForm() used to run by hand.
 *
 * ## The uniqueness check asks the question the DATABASE asks
 *
 * It used to be `findOneBy(['name' => $value])` — an exact match, and on SQLite a case-SENSITIVE one.
 * So the Fulfillment Regions screen happily created `main` beside `Main`, while every stock lookup in
 * the application had already decided those were one region:
 * `WarehouseFulfillmentRegionService::warehousesByLowerRegionName()` keys on `strtolower(trim(name))`,
 * `regionNamed()` matches the same way, and `ProductImportController::findOrCreateRegionByName()`
 * matches on `LOWER(...)`. Two rows spelt that way are one region of which only the lowest id is ever
 * resolved; the other holds a warehouse, a price list and customer links that nothing reaches.
 *
 * The comparison here is therefore `LOWER(TRIM(name))` — literally the expression
 * `uniq_fulfillment_region_name` is built on (`Version20260918120000`). That equivalence is the point
 * and not a coincidence: the screen must refuse exactly what the database would refuse, or the admin
 * meets a 500 where a field error belongs. `strtolower()` rather than `mb_strtolower()` on the PHP
 * side for the same reason — SQLite's `LOWER()` is ASCII-only, and a check that folded more than the
 * index does would refuse names the database would have accepted.
 *
 * ## Trimmed before anything else
 *
 * `handleFulfillmentRegionForm()` already trims what it passes, so this changes nothing there; it
 * matters because a validator is reachable from anywhere and because a name of nothing but spaces is
 * a name of nothing. Trimming first makes that the "required" refusal it always should have been,
 * rather than a region called `'   '` that the index would then collide with the next one.
 */
final class ValidFulfillmentRegionRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidFulfillmentRegionRequest) {
            throw new UnexpectedTypeException($constraint, ValidFulfillmentRegionRequest::class);
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $name = trim($value);

        if ($name === '') {
            $this->context->buildViolation('Fulfillment region name is required.')->addViolation();

            return;
        }

        $existing = $this->regionWearingThisName($constraint, $name);
        if (!$existing instanceof FulfillmentRegion || $existing->getId() === $constraint->currentRegionId) {
            return;
        }

        // Two different refusals, because they are two different mistakes. An exact repeat is
        // somebody adding a region that is already on the list. A variant is somebody who believes
        // capitalisation or a stray space makes a second region, and has to be told that it does not
        // — naming the row that already wears the name, since it is spelt differently from what they
        // typed and they would otherwise go looking for their own spelling on the list screen.
        $this->context
            ->buildViolation(
                $existing->getName() === $name
                    ? sprintf('Fulfillment region "%s" already exists.', $name)
                    : sprintf(
                        'Fulfillment region "%s" already exists, and "%s" is the same name to this application: '
                        . 'regions are matched without regard to capitalisation or surrounding spaces, because that '
                        . 'is how every stock lookup reads them. Rename the existing region, or give this one a name '
                        . 'that is genuinely different.',
                        $existing->getName(),
                        $name,
                    )
            )
            ->atPath('name')
            ->addViolation();
    }

    /**
     * The region this name already belongs to, or null.
     *
     * Lowest id first, so it answers with the same row the rest of the application resolves —
     * `CustomerPricingResolver::resolveFulfillmentRegionEntity()` walks `findAll()` in id order, and
     * `WarehouseFulfillmentRegionService::regionNamed()` documents the same choice. On an installation
     * that predates `uniq_fulfillment_region_name` there can still be more than one to choose from.
     */
    private function regionWearingThisName(ValidFulfillmentRegionRequest $constraint, string $name): ?FulfillmentRegion
    {
        $found = $constraint->entityManager->createQueryBuilder()
            ->select('r')
            ->from(FulfillmentRegion::class, 'r')
            ->andWhere('LOWER(TRIM(r.name)) = :name')
            ->setParameter('name', strtolower($name))
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $found instanceof FulfillmentRegion ? $found : null;
    }
}
