<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Contract\Bundle\BundleDescriptorInterface;
use App\Contract\Bundle\RequiresBundlesInterface;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Service\Bundle\BundleDependencyException;
use App\Service\Bundle\BundleDependencyGraph;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * App Management — every installed app, what state it is in, and the one control that changes it.
 *
 * ## The three states
 *
 * A bundle being on disk and a bundle being switched on are different facts, and since the default
 * flipped they are no longer the same answer. The screen shows both:
 *
 * | shown           | `bundle_status` row | means                                              |
 * |-----------------|---------------------|----------------------------------------------------|
 * | Active          | Active              | running                                             |
 * | Inactive        | Inactive            | installed, switched off by a person                  |
 * | Not activated   | none                | installed, never switched on by anybody              |
 *
 * The third one is new and is the whole point of the flip. It used to be unreachable: `index()`
 * called `ensureBySource()` per descriptor, so merely OPENING this page created an Active row for
 * every app that did not have one. A read-only listing switched apps on as a side effect of being
 * looked at, and "never activated" could not survive a single page view.
 *
 * It no longer writes anything. Rendering asks `findBySource()` and reports what it finds.
 */
final class BundleManagementController extends AbstractAdminController
{
    /** @param iterable<BundleDescriptorInterface> $descriptors */
    public function __construct(
        #[AutowireIterator('app.bundle_descriptor')]
        private readonly iterable $descriptors,
    ) {}

    #[Route('/admin/bundle-management', name: 'admin_bundle_management_index', methods: ['GET'])]
    public function index(BundleStatusRepository $bundleStatusRepo): Response
    {
        // Enabling/disabling apps is a Tech Support capability, not a general admin one. See issue #116.
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $rows = [];
        $existingBadStates = [];
        foreach ($this->descriptors as $descriptor) {
            // findBySource(), NOT ensureBySource(): a GET must not write. null is a state this
            // screen reports, not a gap it fills in.
            $status = $bundleStatusRepo->findBySource($descriptor->getSource());
            $active = $status !== null && $status->getStatus() === BundleStatus::STATUS_ACTIVE;

            $requiredSources = $descriptor instanceof RequiresBundlesInterface ? $descriptor->getRequiredBundles() : [];
            $inactiveRequirements = array_values(array_filter(
                $requiredSources,
                fn (string $requiredSource): bool => !$bundleStatusRepo->isActive($requiredSource),
            ));

            // Report, never repair (#788): a box can already be in the bad state — this bundle
            // Active while something it requires is Inactive, most likely because the requirement
            // relationship was only just declared. Nothing here fixes it; the banner just says so.
            if ($active && $inactiveRequirements !== []) {
                $existingBadStates[] = [
                    'name' => $descriptor->getName(),
                    'missingNames' => array_map(fn (string $s): string => $this->descriptorNameForSource($s) ?? $s, $inactiveRequirements),
                ];
            }

            $rows[] = [
                'name' => $descriptor->getName(),
                'type' => $descriptor->getType(),
                'source' => $descriptor->getSource(),
                'editRoute' => $descriptor->getEditRoute(),
                'docsUrl' => $descriptor->getDocsUrl(),
                'status' => $status?->getStatus(),
                'active' => $active,
                'requiresNames' => array_map(fn (string $s): string => $this->descriptorNameForSource($s) ?? $s, $requiredSources),
                // Only meaningful (and only ever shown) for an INACTIVE row: activating IT is what a
                // missing requirement blocks. An active row with an inactive requirement is the bad
                // state above, not a blocked activation.
                'activationBlockedByNames' => !$active
                    ? array_map(fn (string $s): string => $this->descriptorNameForSource($s) ?? $s, $inactiveRequirements)
                    : [],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

        return $this->render('admin/bundle_management/index.html.twig', [
            'rows' => $rows,
            'existingBadStates' => $existingBadStates,
        ]);
    }

    /**
     * The Activate / Deactivate control.
     *
     * Still one route and one button, because the action is still binary — but it now reads three
     * states and only two of them mean "on". A bundle with no row at all toggles to Active, which
     * is the transition the new default exists to require a person to make.
     *
     * The write goes through {@see BundleStatusRepository::activate()} /
     * {@see BundleStatusRepository::deactivate()}, which is the same method
     * `app:bundle:activate` and `app:bundle:deactivate` call. This controller contributes the role
     * check, the CSRF check and the confirm step, and nothing else — deliberately, because those
     * are properties of the HTTP entry point, and putting any of them inside the shared method
     * would make the console route impossible.
     *
     * Deactivating deletes nothing: not any row, not any bundle's data.
     *
     * ## Deactivating with Active dependents needs a confirm step (#788)
     *
     * A no-JS-safe one: the first POST here previews what would ALSO go off and renders a page
     * asking to continue, without writing anything; only a second POST carrying `confirmed=1` (and
     * its own CSRF token, from that confirm page's own form) performs it. A deactivation with
     * nothing to cascade skips straight to writing, same as before this existed — the common case
     * (most bundles have no dependents) stays a single click.
     */
    #[Route('/admin/bundle-management/{source}/toggle', name: 'admin_bundle_management_toggle', methods: ['POST'])]
    public function toggle(string $source, Request $request, BundleStatusRepository $bundleStatusRepo, BundleDependencyGraph $dependencyGraph): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $descriptorName = $this->descriptorNameForSource($source) ?? $source;
        $wasActive = $bundleStatusRepo->isActive($source);

        if (!$wasActive) {
            try {
                $bundleStatusRepo->activate($source);
            } catch (BundleDependencyException $e) {
                $missingNames = array_map(fn (string $s): string => $this->descriptorNameForSource($s) ?? $s, $e->missingSources);
                $this->addFlash('error', sprintf(
                    '"%s" needs %s Active first.',
                    $descriptorName,
                    implode(', ', $missingNames),
                ));

                return $this->redirectToRoute('admin_bundle_management_index');
            }

            $this->addFlash('success', sprintf('"%s" is now active.', $descriptorName));

            return $this->redirectToRoute('admin_bundle_management_index');
        }

        $cascade = array_values(array_filter(
            $dependencyGraph->transitiveDependents($source),
            fn (string $dependent): bool => $bundleStatusRepo->isActive($dependent),
        ));

        if ($cascade !== [] && !$request->request->getBoolean('confirmed')) {
            return $this->render('admin/bundle_management/confirm_deactivate.html.twig', [
                'source' => $source,
                'name' => $descriptorName,
                'cascadeNames' => array_map(fn (string $s): string => $this->descriptorNameForSource($s) ?? $s, $cascade),
            ]);
        }

        $result = $bundleStatusRepo->deactivate($source);

        $message = sprintf('"%s" is now inactive.', $descriptorName);
        if ($result->alsoDeactivated !== []) {
            $alsoNames = array_map(fn (string $s): string => $this->descriptorNameForSource($s) ?? $s, $result->alsoDeactivated);
            $message = sprintf('"%s" is now inactive; also turned off: %s.', $descriptorName, implode(', ', $alsoNames));
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_bundle_management_index');
    }

    private function descriptorNameForSource(string $source): ?string
    {
        foreach ($this->descriptors as $descriptor) {
            if ($descriptor->getSource() === $source) {
                return $descriptor->getName();
            }
        }

        return null;
    }
}
