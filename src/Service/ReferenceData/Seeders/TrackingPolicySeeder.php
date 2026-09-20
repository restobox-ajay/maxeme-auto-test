<?php

declare(strict_types=1);

namespace App\Service\ReferenceData\Seeders;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use App\Entity\TrackingPolicy;
use App\Repository\TrackingPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The default `none` tracking policy (#573).
 *
 * This is what `TrackingPolicyRepository::ensureDefault()` did, called from
 * `TrackingPolicyController::index()` and `::new()`. Same row, created at a moment that is not the
 * rendering of a page.
 *
 * Matched on `name`, which is how the screen and `TrackingPolicy::DEFAULT_NAME` identify it, and an
 * existing row is left exactly as it stands — an admin who has pointed the default at a different
 * mode has said something, and this is not the code to argue with it.
 *
 * Nothing depends on the row existing: `TrackingPolicyRepository::policyFor()` answers with an
 * unsaved inert policy for a product that points at nothing, which is why the absence was quiet
 * rather than loud and why it went unnoticed for as long as it did.
 */
final class TrackingPolicySeeder implements ReferenceDataSeederInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TrackingPolicyRepository $policies,
    ) {
    }

    public function getKey(): string
    {
        return 'core.tracking_policy';
    }

    public function getLabel(): string
    {
        return 'Tracking policies';
    }

    public function seed(): int
    {
        if ($this->policies->findOneBy(['name' => TrackingPolicy::DEFAULT_NAME]) instanceof TrackingPolicy) {
            return 0;
        }

        $this->em->persist(
            (new TrackingPolicy())
                ->setName(TrackingPolicy::DEFAULT_NAME)
                ->setMode(TrackingPolicy::MODE_NONE)
        );

        return 1;
    }
}
