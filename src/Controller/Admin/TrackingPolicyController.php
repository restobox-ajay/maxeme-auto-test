<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TrackingPolicy;
use App\Repository\TrackingPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Managing the named tracking policies (#573).
 *
 * A small screen on purpose. The whole argument for a named policy rather than columns on
 * `product_core` is that the day a rule changes you edit four rows and not twenty thousand — so
 * this is a short list, and the interesting work is in what a product points at, which lives on the
 * product form.
 *
 * Core, not a bundle screen, because `tracking_policy` and `product_core.tracking_policy_id` are
 * core tables: an instance with no inventory-depth bundle installed still has products, and the
 * declaration of what identity they carry belongs beside them. Nothing here reads a single row any
 * bundle writes.
 *
 * ## Why the list, the create and the edit are three pages
 *
 * They were one. The screen rendered an add form and the grid below it, and edited by reloading
 * itself with `?edit={id}`. That is not the shape anything else in this application uses, and it had
 * a consequence nobody had connected to it: **the grid was clipped to nothing and the owner could
 * not see a policy they had just created.**
 *
 * Measured at 1440x900 before this change, with the stylesheet actually served:
 *
 *   .content-frame        852px   — `app.css:7764` clamps it to calc(100vh - 3rem), and the clamp
 *                                   matched, because the grid card IS a direct child of the frame
 *   .panel.compact-panel   56px   — flex: 0 0 auto
 *   .form-card            693px   — no flex rule and min-height:auto, so it does not shrink
 *   .table-card            44px   — `flex: 1 1 auto; min-height: 0`, the only child that gives
 *   .table-scroll-region  220px floor over a 450px table, inside a card of 44px with overflow:hidden
 *
 * The first data row landed at y=850 against a card whose bottom edge was y=852, and the document
 * scrolled 14px, so the rows could not be reached by scrolling either. Every policy was in the HTML
 * and none was on the screen.
 *
 * The full-height grid clamp assumes **the grid is the page**. It is right about every other list
 * screen in the app and it was wrong here only because a 693px create form sat inside the clamped
 * frame ahead of the table. So the fix is to take the form off the list page rather than to weaken
 * the clamp — weakening it would cost every real grid its sticky header and its one scroll region to
 * accommodate the one screen that should not have had a form on it.
 *
 * `?edit={id}` still works and redirects to {@see self::edit()}: it is in flashes, in the browser
 * history and quite possibly in somebody's bookmarks.
 */
#[Route('/admin')]
final class TrackingPolicyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TrackingPolicyRepository $policies,
    ) {
    }

    #[Route('/product/tracking-policies', name: 'admin_tracking_policy_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // This used to open with $this->policies->ensureDefault(), which CREATED the default policy
        // on a GET. It does not any more: App\Service\ReferenceData\Seeders\TrackingPolicySeeder
        // owns that row and runs once, on the first admin login. This action reads.
        //
        // `?edit={id}` used to re-render this page with the form populated. It is kept as a
        // redirect rather than dropped: the old URL is in browser histories and bookmarks, and a
        // link that used to open an editor should not quietly become a list.
        $editId = $request->query->getInt('edit', 0);
        if ($editId > 0) {
            return $this->redirectToRoute('admin_tracking_policy_edit', ['id' => $editId]);
        }

        $rows = [];
        foreach ($this->policies->allByName() as $policy) {
            $rows[] = ['policy' => $policy, 'products' => $this->policies->productCount($policy)];
        }

        return $this->render('admin/tracking_policy/index.html.twig', [
            'rows' => $rows,
            'defaultName' => TrackingPolicy::DEFAULT_NAME,
        ]);
    }

    /** The create page. Same form as {@see self::edit()}, with nothing to populate it from. */
    #[Route('/product/tracking-policies/new', name: 'admin_tracking_policy_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('admin/tracking_policy/form.html.twig', $this->formViewVars(null));
    }

    /**
     * The edit page.
     *
     * The hidden `id` field is still `{{ editing ? editing.id : 0 }}` and {@see self::save()} still
     * reads it, which is the whole reason editing works on this screen and does not on the debit
     * memo and vendor return screens that hard-code `0` into the same field. Splitting the route did
     * not move the id out of the form — it moved the FORM, and the id rides in it exactly as before.
     */
    #[Route('/product/tracking-policies/{id}/edit', name: 'admin_tracking_policy_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $policy = $this->em->find(TrackingPolicy::class, $id);
        if (!$policy instanceof TrackingPolicy) {
            $this->addFlash('error', 'That tracking policy could not be found.');

            return $this->redirectToRoute('admin_tracking_policy_index');
        }

        return $this->render('admin/tracking_policy/form.html.twig', $this->formViewVars($policy));
    }

    /**
     * @return array{editing: TrackingPolicy|null, modes: list<string>, defaultName: string, defaultSentinel: string, products: int, contradiction: ?string}
     */
    private function formViewVars(?TrackingPolicy $policy): array
    {
        return [
            'editing' => $policy,
            'modes' => TrackingPolicy::modes(),
            'defaultName' => TrackingPolicy::DEFAULT_NAME,
            'defaultSentinel' => TrackingPolicy::DEFAULT_SENTINEL,
            'products' => $policy instanceof TrackingPolicy ? $this->policies->productCount($policy) : 0,
            // A policy SAVED in one of the states {@see self::contradiction()} now refuses. Rows
            // predating that check are not rewritten — nothing here writes to existing data, and a
            // migration that silently unticked somebody's box would be a change to what their stock
            // must carry made by a deploy. So the row stands, receiving goes on behaving exactly as
            // it does today, and the screen says what is wrong the moment anyone opens it. The next
            // save is refused until it is corrected, which is the only moment a person is actually
            // looking.
            'contradiction' => $policy instanceof TrackingPolicy
                ? self::contradiction($policy->getMode(), $policy->isTrackIn(), $policy->isTrackOut())
                : null,
        ];
    }

    #[Route('/product/tracking-policies/save', name: 'admin_tracking_policy_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $id = $request->request->getInt('id', 0);
        $name = trim((string) $request->request->get('name', ''));

        if ($name === '') {
            $this->addFlash('error', 'A tracking policy needs a name — it is what an admin picks it by on the product form.');

            // Back to the form that was posted, not to the list. With the form on its own page a
            // refusal that lands on the grid leaves the admin looking at a flash with no field to
            // correct.
            return $this->backToForm($id);
        }

        // ── The two states a saved policy may not be in (item 68).
        //
        //    Checked on the POSTED values, before anything is created or written, so a refused
        //    create leaves no row behind and a refused edit leaves the stored one exactly as it
        //    was. `setMode()` folds an unrecognised mode to `none`, so the same folding happens
        //    here — otherwise a junk mode would be validated as itself and stored as something
        //    else.
        $mode = (string) $request->request->get('mode', TrackingPolicy::MODE_NONE);
        $mode = \in_array($mode, TrackingPolicy::modes(), true) ? $mode : TrackingPolicy::MODE_NONE;
        $requiresExpiry = $request->request->getBoolean('requires_expiry');
        $trackIn = $request->request->getBoolean('track_in');
        $trackOut = $request->request->getBoolean('track_out');

        $refusal = self::contradiction($mode, $trackIn, $trackOut);
        if ($refusal !== null) {
            $this->addFlash('error', $refusal);

            return $this->backToForm($id);
        }

        $policy = $id > 0 ? $this->em->find(TrackingPolicy::class, $id) : null;

        // Named uniquely, because the name is the whole interface: two policies called "Lot" make
        // the product form a guess. Matched before creating so a re-submitted add edits the row it
        // would otherwise collide with, rather than failing on the unique index.
        $existing = $this->policies->findOneBy(['name' => $name]);
        if ($existing instanceof TrackingPolicy && $existing !== $policy) {
            if ($policy instanceof TrackingPolicy) {
                $this->addFlash('error', sprintf('A tracking policy called "%s" already exists.', $name));

                return $this->backToForm($id);
            }

            $policy = $existing;
        }

        if (!$policy instanceof TrackingPolicy) {
            $policy = new TrackingPolicy();
            $this->em->persist($policy);
        }

        $policy
            ->setName($name)
            ->setMode($mode)
            ->setRequiresExpiry($requiresExpiry)
            ->setTrackIn($trackIn)
            ->setTrackOut($trackOut)
            ->setSentinelIn((string) $request->request->get('sentinel_in', ''))
            ->setSentinelOut((string) $request->request->get('sentinel_out', ''));

        $this->em->flush();
        $this->addFlash('success', sprintf('Tracking policy "%s" saved. %s', $policy->getName(), $policy->describe()));

        return $this->redirectToRoute('admin_tracking_policy_index');
    }

    /**
     * Why this combination of boxes may not be saved, or null when it may (item 68).
     *
     * Static and given the posted values rather than a policy, so it can be asked BEFORE anything
     * is created — and so the edit page can ask the same question about a row that is already in
     * one of these states without going near a write.
     *
     * ## A tracked mode has to track something
     *
     * The other state item 67 reported: `lot` or `serial` with BOTH directions unticked. That is
     * `isInert()` — nothing captured, nothing required, nothing refused — under a name that says
     * otherwise on every screen. "None wearing another name", and the reason a product could sit on
     * a policy called "Lot" and be received with the box blank. `none` with both unticked is the
     * default and is exactly what it claims to be, so it is left alone.
     */
    private static function contradiction(string $mode, bool $trackIn, bool $trackOut): ?string
    {
        if ($mode !== TrackingPolicy::MODE_NONE && !$trackIn && !$trackOut) {
            return sprintf(
                'This policy says units are identified by %s and captures nothing in either direction, which is the "None" policy wearing another name — a product on it would show the word %s on every screen and be received with the box blank. Tick "Capture it on the way in", "Capture it on the way out", or both. If these units really carry no identity, set Identity to None and say so.',
                $mode === TrackingPolicy::MODE_LOT ? 'the batch they came from' : 'their own serial',
                $mode === TrackingPolicy::MODE_LOT ? 'batch' : 'serial',
            );
        }

        return null;
    }

    /** The page a refused save came from: the edit page when it carried an id, the create page otherwise. */
    private function backToForm(int $id): Response
    {
        return $id > 0
            ? $this->redirectToRoute('admin_tracking_policy_edit', ['id' => $id])
            : $this->redirectToRoute('admin_tracking_policy_new');
    }

    #[Route('/product/tracking-policies/{id}/delete', name: 'admin_tracking_policy_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $policy = $this->em->find(TrackingPolicy::class, $id);
        if (!$policy instanceof TrackingPolicy) {
            $this->addFlash('error', 'That tracking policy could not be found.');

            return $this->redirectToRoute('admin_tracking_policy_index');
        }

        if ($policy->getName() === TrackingPolicy::DEFAULT_NAME) {
            $this->addFlash('error', sprintf('"%s" is the policy every product falls back to and cannot be deleted.', TrackingPolicy::DEFAULT_NAME));

            return $this->redirectToRoute('admin_tracking_policy_index');
        }

        // Refused rather than cascaded. The foreign key is SET NULL, so deleting a policy in use
        // would quietly untrack every product pointing at it — a silent change to what identity
        // their stock must carry, which is exactly the kind of thing this table exists to make
        // explicit.
        $inUse = $this->policies->productCount($policy);
        if ($inUse > 0) {
            $this->addFlash('error', sprintf(
                '"%s" is used by %d product(s). Move them to another policy first — deleting it would silently untrack them.',
                $policy->getName(),
                $inUse,
            ));

            return $this->redirectToRoute('admin_tracking_policy_index');
        }

        $name = $policy->getName();
        $this->em->remove($policy);
        $this->em->flush();
        $this->addFlash('success', sprintf('Tracking policy "%s" was deleted.', $name));

        return $this->redirectToRoute('admin_tracking_policy_index');
    }
}
