<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Contract\Status\HasStatus;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use Tests\Support\FunctionalTester;

/**
 * The three master-data classes migrated onto {@see HasStatus} this session stay on it.
 *
 * ## Why this exists, and why it is not folded into NoStatusVerbIsReachableCest
 *
 * That file's `everyDocumentWithAStatusIsOnTheSeam()` already catches a SALES DOCUMENT quietly
 * leaving the seam — but it does so by walking every entity with a `status` property and asserting
 * `HasStatus` ONLY on the ones that are also `AbstractSalesDocument` subclasses. AdminUser,
 * CustomerUser and Company are not documents, so that check silently skips them: remove
 * `implements HasStatus` (and the trait) from any of the three and nothing in that file objects —
 * `documentsOnTheSeam()` just stops finding the class, and the whole file's other assertions
 * (no public verb, no external bypass, the gate is public) start passing vacuously for it again,
 * which is exactly the failure mode `everyDocumentWithAStatusIsOnTheSeam()` exists to prevent for
 * documents.
 *
 * This is the equivalent guard for the three classes this session put on the seam. It is
 * deliberately NOT a generic "every entity with a `status` property must implement HasStatus" scan:
 * most of the ~20 other entities with their own `setStatus()` (Vendor, Warehouse, PriceList,
 * BundleStatus, TransferOrder, ProductCategory, and more — see
 * docs/reviews/2026-09-20-one-gate-audit.md) have not been migrated yet, and a generic scan would
 * fail on every one of them for work nobody has done. Once one of them migrates, add it here rather
 * than widen this into a hand-kept exemption list the other direction.
 */
final class MasterDataStaysOnTheStatusSeamCest
{
    /** @return list<class-string> */
    private function migratedMasterDataClasses(): array
    {
        return [AdminUser::class, CustomerUser::class, Company::class];
    }

    public function everyMigratedMasterDataClassStillImplementsHasStatus(FunctionalTester $I): void
    {
        $offSeam = [];

        foreach ($this->migratedMasterDataClasses() as $class) {
            if (!(new \ReflectionClass($class))->implementsInterface(HasStatus::class)) {
                $offSeam[] = $class;
            }
        }

        $I->assertSame(
            [],
            $offSeam,
            "A class migrated onto HasStatus this session no longer implements it.\n\n"
            . "NoStatusVerbIsReachableCest cannot catch this on its own for a non-document class — "
            . "it only re-derives its subject list from classes that ARE still on the seam, so "
            . "leaving the seam removes the class from its own checks instead of failing them. "
            . "See this file's class docblock.",
        );
    }

    /**
     * The companion half: still has a real vocabulary (StatusVocabularyLoader would throw a plain
     * LogicException otherwise, which ShippedVocabulariesMatchTheirEnumsTest also guards from the
     * enum side) and still has no bare setStatus(string) escape hatch sitting next to the gate.
     */
    public function noneOfThemExposeABareSingleArgumentSetStatus(FunctionalTester $I): void
    {
        $offenders = [];

        foreach ($this->migratedMasterDataClasses() as $class) {
            $method = new \ReflectionMethod($class, 'setStatus');
            $params = $method->getParameters();

            if (count($params) < 2 || $params[1]->getType() === null) {
                $offenders[] = sprintf('%s::setStatus() takes fewer than 2 typed parameters', $class);

                continue;
            }

            $actorType = $params[1]->getType();
            $actorTypeName = $actorType instanceof \ReflectionNamedType ? $actorType->getName() : (string) $actorType;

            if (ltrim($actorTypeName, '?') !== \App\Service\DocumentActor::class) {
                $offenders[] = sprintf(
                    '%s::setStatus()\'s second parameter is %s, not DocumentActor',
                    $class,
                    $actorTypeName,
                );
            }

            // The type check above passes for `?DocumentActor $actor = null` just as readily as for
            // `DocumentActor $actor` — nullable-with-a-default is exactly how the optional version
            // was written before the owner asked for it back. Whether the actor can be OMITTED is a
            // property of the parameter's default, not its type, so it needs its own check.
            if ($params[1]->isOptional()) {
                $offenders[] = sprintf(
                    '%s::setStatus()\'s $actor parameter is optional — it must be required',
                    $class,
                );
            }
        }

        $I->assertSame(
            [],
            $offenders,
            "setStatus() must take (string \$status, DocumentActor \$actor, ?string \$comment = null) "
            . "-- a bare setStatus(string \$status) sitting alongside it would be exactly the second "
            . "door HasStatus exists to close.",
        );
    }
}
