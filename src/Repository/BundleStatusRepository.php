<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BundleStatus;
use App\Service\Bundle\BundleDeactivationResult;
use App\Service\Bundle\BundleDependencyException;
use App\Service\Bundle\BundleDependencyGraph;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BundleStatus>
 */
class BundleStatusRepository extends ServiceEntityRepository
{
    /**
     * The root namespace of core itself, which is not a bundle and is never gated.
     *
     * {@see self::isActiveForInstance()} derives a source from an object's root namespace segment.
     * That is right for anything contributed by a module — `TaxBCBundle\Tax\BCTaxCalculator` gives
     * `TaxBCBundle` — but four core services are registered on the same tags and give `App`:
     * `App\Menu\Core\ContactMenuItem`, `FaqMenuItem`, `MyOrdersMenuItem` and `MyQuotesMenuItem`,
     * all tagged `app.frontend_menu_item` and all passed through `isActiveForInstance()` by
     * {@see \App\Twig\FrontendMenuExtension::getFrontendMenuItems()} and
     * {@see \App\Controller\Admin\FrontendMenuManagementController::index()}.
     *
     * Under the old "absent means Active" default that was invisible: `App` had no row and answered
     * true. Under the new default it would answer FALSE, and the four core entries would vanish
     * from the customer-facing nav — core going dark, not a bundle.
     *
     * This is not an always-on allow-list for bundles; there is still no such thing. `App` is the
     * application, not an app: it has no descriptor, appears on no management screen, is matched by
     * no `modules/*` directory, and there is no control anywhere that could ever activate it. A
     * source that cannot be activated by any means must not be gated on having been activated.
     */
    public const CORE_SOURCE = 'App';

    public function __construct(
        ManagerRegistry $registry,
        private readonly BundleDependencyGraph $dependencyGraph,
    ) {
        parent::__construct($registry, BundleStatus::class);
    }

    public function findBySource(string $source): ?BundleStatus
    {
        return $this->findOneBy(['source' => $source]);
    }

    /**
     * A bundle is Inactive until a status row explicitly says it is Active.
     *
     * ## The flip
     *
     * This used to read `$status === null || $status->getStatus() === STATUS_ACTIVE` — absence of a
     * row meant ENABLED, so a bundle did its work from the moment its folder appeared under
     * `modules/`. Ruled the other way round: a bundle present on disk is INERT until a human
     * presses Activate on App Management or runs `app:bundle:activate`.
     *
     * ## What had to happen first
     *
     * Every installation that predates this rule has bundles running with NO row at all — that was
     * the enabled state. Inverting the default without warning takes every one of them dark at
     * once: procurement, tax, fees, shipping, inventory depth, all of it. So
     * {@see \DoctrineMigrations\Version20260916104500} writes an explicit Active row for every
     * module directory present at migration time, BEFORE this default can be observed by anything.
     * It is INSERT-only and idempotent; see that migration for the ordering and crash analysis.
     *
     * `tests/_bootstrap.php` does the same thing for the test database, which is built from
     * Doctrine metadata and never runs the migration chain.
     */
    public function isActive(string $source): bool
    {
        if ($source === self::CORE_SOURCE) {
            return true;
        }

        $status = $this->findBySource($source);

        return $status !== null && $status->getStatus() === BundleStatus::STATUS_ACTIVE;
    }

    /**
     * Derives the owning bundle's source from an instance's class name (the
     * root namespace segment, e.g. "TaxBCBundle\Tax\BCTaxCalculator" -> "TaxBCBundle")
     * and checks it against the same convention BundleDescriptorInterface::getSource()
     * and the existing Fee/SalesTax "source" columns already use.
     *
     * Core services reach this too and give {@see self::CORE_SOURCE}; see that constant.
     */
    public function isActiveForInstance(object $instance): bool
    {
        return $this->isActive(self::sourceFromClass($instance::class));
    }

    public static function sourceFromClass(string $class): string
    {
        $separatorPosition = strpos($class, '\\');

        return $separatorPosition === false ? $class : substr($class, 0, $separatorPosition);
    }

    /**
     * Lazy upsert: finds by source; if missing, creates it INACTIVE and returns it.
     *
     * The status it creates changed with the flip, and that is the whole point — this method used
     * to be how a bundle got switched on, silently, as a side effect of somebody opening App
     * Management. `BundleManagementController::index()` called it once per descriptor on every
     * render, so rendering a read-only listing wrote up to 36 Active rows.
     *
     * It no longer does. Rendering asks {@see self::findBySource()} and shows the three states
     * honestly. What survives here is the narrow meaning the name claims — "there is a row for this
     * source" — for callers that then set a status on it, which is what every remaining caller
     * (all of them tests) does immediately afterwards.
     *
     * Prefer {@see self::activate()} / {@see self::deactivate()} for anything that means to CHANGE
     * a status: they are the one shared path, and they do not leave a half-written Inactive row
     * behind if the caller forgets to flush.
     */
    public function ensureBySource(string $source): BundleStatus
    {
        $status = $this->findBySource($source);
        if ($status !== null) {
            return $status;
        }

        $status = (new BundleStatus())
            ->setSource($source)
            ->setStatus(BundleStatus::STATUS_INACTIVE);

        $em = $this->getEntityManager();
        $em->persist($status);
        $em->flush();

        return $status;
    }

    /**
     * The one place a status row is actually written. {@see self::activate()} and
     * {@see self::deactivate()} are the only callers — both are reached by the Activate/Deactivate
     * buttons on App Management ({@see \App\Controller\Admin\BundleManagementController}) and by
     * the console commands ({@see \App\Command\Bundle\BundleActivateCommand},
     * {@see \App\Command\Bundle\BundleDeactivateCommand}), so those four entry points cannot drift
     * into disagreeing about what activation or deactivation means.
     *
     * ## Nothing here needs a request
     *
     * Deliberately: no user, no session, no firewall, no bundle. The ROLE_TECH_SUPPORT check lives
     * in the controller, where it belongs — it is a property of the HTTP entry point, not of
     * activation — and this class's own dependencies (ManagerRegistry, BundleDependencyGraph) are
     * both reachable with none of those things either. That is what makes the console route a real
     * escape hatch rather than a decoration: it works on an installation where every bundle is off,
     * no admin can log in, or the admin UI will not render.
     *
     * Creates the row when there is none, which is correct for both callers: pressing Activate on a
     * bundle that has never had a row is exactly the transition the new default exists to require.
     *
     * @throws \InvalidArgumentException on a status that is neither Active nor Inactive — the
     *     column is 32 characters of free text at the database level, so a typo would otherwise
     *     store a value that {@see self::isActive()} reads as "not Active" forever.
     *
     * Private (#788): this used to be public with no caller outside this class, which is exactly
     * the bypass a dependency rule cannot have — anything reaching it directly instead of through
     * {@see self::activate()} / {@see self::deactivate()} would skip the requirement check and the
     * cascade. There being no such caller today does not mean there never will be one; not being
     * reachable is what keeps that true.
     */
    private function setStatusForSource(string $source, string $status): BundleStatus
    {
        if ($status !== BundleStatus::STATUS_ACTIVE && $status !== BundleStatus::STATUS_INACTIVE) {
            throw new \InvalidArgumentException(sprintf(
                'Bundle status must be "%s" or "%s", got "%s".',
                BundleStatus::STATUS_ACTIVE,
                BundleStatus::STATUS_INACTIVE,
                $status,
            ));
        }

        $row = $this->findBySource($source);
        $em = $this->getEntityManager();

        if ($row === null) {
            $row = (new BundleStatus())->setSource($source);
            $em->persist($row);
        }

        $row->setStatus($status);
        $em->flush();

        return $row;
    }

    /**
     * Refuses when a direct requirement is not Active (#788) — never auto-activates it. Checked
     * against the requirement's OWN status, not against $source's transitive requirement list
     * flattened: a requirement that is itself missing a requirement fails at ITS OWN activate()
     * call, which is the only order a person or `--all-present` could ever satisfy it in anyway.
     *
     * @throws BundleDependencyException when a direct requirement of $source is not Active
     */
    public function activate(string $source): BundleStatus
    {
        $missing = array_values(array_filter(
            $this->dependencyGraph->directRequirements($source),
            fn (string $required): bool => !$this->isActive($required),
        ));

        if ($missing !== []) {
            throw new BundleDependencyException($source, $missing);
        }

        return $this->setStatusForSource($source, BundleStatus::STATUS_ACTIVE);
    }

    /**
     * Activates many sources in one flush, and says how many were not already Active.
     *
     * Same rows, same meaning and the same create-if-missing rule as {@see self::activate()} — this
     * exists only because the callers do it in bulk and per-row flushes are the wrong shape for
     * them. `app:bundle:activate --all-present` runs it once; the PHPUnit base case runs it in
     * setUp() for EVERY test, which is where forty separate flushes would actually be felt.
     *
     * @param iterable<string> $sources
     *
     * @return int how many rows this call changed — already-Active sources are left alone and not
     *     counted, so a second run reports 0 rather than pretending to have done something
     */
    public function activateAll(iterable $sources): int
    {
        $em = $this->getEntityManager();
        $changed = 0;

        foreach ($sources as $source) {
            $row = $this->findBySource($source);

            if ($row === null) {
                $row = (new BundleStatus())->setSource($source);
                $em->persist($row);
            } elseif ($row->getStatus() === BundleStatus::STATUS_ACTIVE) {
                continue;
            }

            $row->setStatus(BundleStatus::STATUS_ACTIVE);
            ++$changed;
        }

        if ($changed > 0) {
            $em->flush();
        }

        return $changed;
    }

    /**
     * Switches a bundle off, and cascades: every Active transitive dependent of $source goes off
     * with it (#788), since a dependent left running with its requirement dark is exactly the bad
     * state #788 exists to stop happening silently. Deletes nothing — not any status row, and not
     * one row of the data any of these bundles own. Off means each one's gates answer false; the
     * rows sit untouched, so reactivating needs no recount, no re-import and no manual step. See
     * {@see \App\EventSubscriber\BundleBucketAvailabilityGate} for the same promise on the
     * inventory buckets.
     *
     * Reactivating $source afterwards does NOT reactivate what was cascaded off — they may have
     * been off on purpose already, and {@see self::activate()} would refuse most of them anyway
     * without their own requirements back on first.
     */
    public function deactivate(string $source): BundleDeactivationResult
    {
        $alsoDeactivated = array_values(array_filter(
            $this->dependencyGraph->transitiveDependents($source),
            fn (string $dependent): bool => $this->isActive($dependent),
        ));

        foreach ($alsoDeactivated as $dependent) {
            $this->setStatusForSource($dependent, BundleStatus::STATUS_INACTIVE);
        }

        $target = $this->setStatusForSource($source, BundleStatus::STATUS_INACTIVE);

        return new BundleDeactivationResult($target, $alsoDeactivated);
    }

    /**
     * Every source this installation holds a row for, mapped to its status.
     *
     * Reads rows only — it says nothing about what is on disk. `app:bundle:list` joins this against
     * {@see \App\Bundle\InstalledBundleDirectory::sources()} to tell the three states apart.
     *
     * @return array<string, string> source => status, ordered by source
     */
    public function statusBySource(): array
    {
        $map = [];
        foreach ($this->findBy([], ['source' => 'ASC']) as $row) {
            $map[$row->getSource()] = $row->getStatus();
        }

        return $map;
    }
}
