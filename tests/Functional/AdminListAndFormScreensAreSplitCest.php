<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\UnitOfMeasure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Tracking Policies and Units of Measure, after the list / create / edit split, conducted (#624).
 *
 * ## The defect the split fixes, and why a test can see it at all
 *
 * Both screens used to render an add form above their grid and edit by reloading themselves with
 * `?edit={id}`. `app.css:7764` clamps a `.content-frame` holding a top-level `.table-card` to
 * `calc(100vh - 3rem)` — the model that gives every grid in this app its sticky header and one
 * scroll region — and the clamp assumes **the grid IS the page**. It matched here, because the grid
 * card IS a direct child of the frame, and then a 693px create form inside the clamped frame left
 * the card 44px of 852. `.table-card` is `overflow: hidden` and `.table-scroll-region` has a 220px
 * floor, so the excess was clipped with no scrollbar: on Tracking Policies the first data row landed
 * at y=850 against a card whose bottom edge was y=852, and the document scrolled 14px, so scrolling
 * did not reach the rows either. Every policy was in the HTML and none was on the screen.
 *
 * A functional test cannot see 44 pixels. What it CAN see is the structural cause, which is the
 * thing that was actually wrong: a page carrying both a `.form-card` and a top-level `.table-card`.
 * {@see self::neitherListPageCarriesAFormAnyMore()} asserts exactly that, on both screens, and it is
 * the assertion that fails the day somebody puts a form back on a list page. The pixel measurement
 * is recorded in the controllers' docblocks because it is how the cost was established, not because
 * a test should try to reproduce it.
 *
 * ## What is conducted and what is merely fetched
 *
 * Everything that writes is driven the way an admin drives it: GET the real page, scrape the real
 * CSRF token out of the real form, and POST with {@see FunctionalTester::sendFormPostRequest()} —
 * a plain form post with no `X-Requested-With`, i.e. what a browser with JavaScript off sends when a
 * submit button is pressed. Nothing here announces itself as an XHR, because nothing here is one.
 *
 * What is asserted afterwards is `tracking_policy.*` and `unit_of_measure.*` read back **through the
 * DBAL connection, by column**, not through an entity handed out before the request. An entity
 * fetched beforehand is the same object the controller mutated: asserting on it proves the setter
 * ran, not that anything was written. A raw `SELECT` cannot be satisfied by the identity map.
 *
 * Every write test carries a **control row** that must come back byte-identical. That is the cheap
 * half and the half that catches a save resolving the wrong row — which on `unit_of_measure`, shared
 * by every product in the application since #659, would be the worst defect the table could have and
 * is completely invisible from the row being edited.
 *
 * No assertion in this file is a `see()` on a number (#627). Where a count matters it is compared as
 * an integer read from the database.
 */
final class AdminListAndFormScreensAreSplitCest
{
    /** Every column of `tracking_policy` except the id, so a control row is compared whole. */
    private const POLICY_COLUMNS = 'name, mode, requires_expiry, track_in, track_out, sentinel_in, sentinel_out';

    /** Every column of `unit_of_measure` except the id. */
    private const UNIT_COLUMNS = 'code, name, family, factor_to_family_base, rounding_precision';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('split-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        // The shipped units and the default tracking policy used to be created by RENDERING the two
        // list screens this file drives. They are now created once, on LoginSuccessEvent, and
        // amLoggedInAs() does not dispatch that event — it installs a token without running the
        // authenticator. So this stands in for the real login's side effect; ReferenceDataSeedingCest
        // posts the actual login form.
        $I->haveSeededReferenceData();
    }

    /**
     * One row of a table, by id, read straight off the connection.
     *
     * @return array<string, mixed>
     */
    private function row(FunctionalTester $I, string $table, string $columns, int $id): array
    {
        /** @var Connection $connection */
        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();
        $row = $connection->fetchAssociative(
            sprintf('SELECT %s FROM %s WHERE id = ?', $columns, $table),
            [$id],
        );

        $I->assertIsArray($row, sprintf('%s has no row with id %d', $table, $id));

        return $row;
    }

    /**
     * Whether a row is still there, as a plain boolean.
     *
     * Separate from {@see self::row()} so that a refusal test fails with the reason the refusal
     * exists rather than with "no row with id N" from the reader's own guard — a failing test that
     * does not name the defect costs the next reader the same investigation twice.
     */
    private function rowExists(FunctionalTester $I, string $table, int $id): bool
    {
        /** @var Connection $connection */
        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();

        return $connection->fetchAssociative(sprintf('SELECT id FROM %s WHERE id = ?', $table), [$id]) !== false;
    }

    /** The token the page currently loaded actually renders, inside the form that will be posted. */
    private function formToken(FunctionalTester $I): string
    {
        return (string) $I->grabAttributeFrom('form[method="post"] input[name="_token"]', 'value');
    }

    // ---------------------------------------------------------------------------------------
    // The shape
    // ---------------------------------------------------------------------------------------

    /**
     * Neither list page carries a create form, and the grid is a direct child of the content frame.
     *
     * This is the defect itself, stated structurally. `.form-card` beside a top-level `.table-card`
     * is the combination that put a 693px form inside the height-clamped frame and left the grid
     * 44px; the split is what removes it, and this assertion is what stops it coming back.
     *
     * The `.content-frame > .table-card` half is asserted too, because it is the OTHER way the same
     * page could go wrong: a grid that stops being a direct child silently loses the clamp, the
     * sticky header and the single scroll region, which is the failure
     * `AdminListScreenConventionsCest` classifies as EMBEDDED rather than GRID.
     */
    public function neitherListPageCarriesAFormAnyMore(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        foreach ([
            '/admin/product/tracking-policies',
            '/admin/product/units-of-measure',
        ] as $path) {
            $I->amOnPage($path);
            $I->seeResponseCodeIs(200);

            $I->dontSeeElement('.form-card');
            $I->seeElement('.content-frame > .table-card');
            $I->dontSeeElement('form[action="/admin/product/tracking-policies/save"]');
            $I->dontSeeElement('form[action="/admin/product/units-of-measure/save"]');
        }
    }

    /**
     * Create is reached from the list, as a link in the header panel's actions.
     *
     * A GET link and not a button that a script turns into one: with JavaScript off, a create
     * affordance that is not an anchor or a submit control is not an affordance at all.
     */
    public function createIsReachedFromTheHeaderPanelOnBothLists(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/tracking-policies');
        $I->seeElement('.panel-actions a[href="/admin/product/tracking-policies/new"]');

        $I->amOnPage('/admin/product/units-of-measure');
        $I->seeElement('.panel-actions a[href="/admin/product/units-of-measure/new"]');
    }

    /**
     * The create page is a page, and its form is postable with JavaScript off.
     *
     * A real `<form method="post">` with a real `<button type="submit">` inside it, and the hidden
     * `id` at 0 — which is what makes {@see \App\Controller\Admin\TrackingPolicyController::save()}
     * treat the post as a create.
     */
    public function bothCreatePagesArePostableWithoutJavascript(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/tracking-policies/new');
        $I->seeResponseCodeIs(200);
        $I->seeElement('form[method="post"][action="/admin/product/tracking-policies/save"]');
        $I->seeElement('form[method="post"] button[type="submit"]');
        $I->assertSame('0', (string) $I->grabAttributeFrom('input[name="id"]', 'value'));

        $I->amOnPage('/admin/product/units-of-measure/new');
        $I->seeResponseCodeIs(200);
        $I->seeElement('form[method="post"][action="/admin/product/units-of-measure/save"]');
        $I->seeElement('form[method="post"] button[type="submit"]');
        $I->assertSame('0', (string) $I->grabAttributeFrom('input[name="id"]', 'value'));
    }

    /**
     * Row actions are behind one control per row that opens with JavaScript off.
     *
     * The app's own dropdown (`.row-action-toggle`) is `display: none` until `app.js:4220` clones it
     * into the body, so adopting it on a screen whose actions are plain visible buttons today would
     * have made both strictly worse for a browser with scripting off. `<details>`/`<summary>` opens
     * natively, and the delete inside it is a real POST form with a real submit button.
     */
    public function rowActionsOpenWithoutJavascript(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $policy = (new TrackingPolicy())->setName('Openable ' . uniqid())->setMode(TrackingPolicy::MODE_LOT);
        $em->persist($policy);
        $em->flush();
        $policyId = (int) $policy->getId();

        $I->amOnPage('/admin/product/tracking-policies');
        $I->seeElement('details.no-js-row-actions > summary');
        $I->dontSeeElement('.row-action-toggle');
        $I->seeElement(sprintf(
            'details.no-js-row-actions form[method="post"][action="/admin/product/tracking-policies/%d/delete"] button[type="submit"]',
            $policyId,
        ));

        $I->amOnPage('/admin/product/units-of-measure');
        $I->seeElement('details.no-js-row-actions > summary');
        $I->dontSeeElement('.row-action-toggle');
    }

    /**
     * The URL that used to open the editor still opens the editor.
     *
     * `?edit={id}` is in browser histories, in flash-driven round trips and in four other Cests. A
     * link that opened an editor must not quietly become a list — that is a silent behaviour change
     * dressed as a refactor — so the old query redirects to the new page rather than being dropped.
     */
    public function theOldEditQueryStillReachesTheEditorOnBothScreens(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $policy = (new TrackingPolicy())->setName('Bookmarked ' . uniqid())->setMode(TrackingPolicy::MODE_SERIAL);
        $em->persist($policy);
        $em->flush();
        $policyId = (int) $policy->getId();

        $I->amOnPage('/admin/product/tracking-policies?edit=' . $policyId);
        $I->seeResponseCodeIs(200);
        $I->seeCurrentUrlEquals('/admin/product/tracking-policies/' . $policyId . '/edit');
        $I->assertSame((string) $policyId, (string) $I->grabAttributeFrom('input[name="id"]', 'value'));

        // The demo units are seeded on first view of the list, not by the migration, so the list has
        // to be visited before one of them can be named here.
        $I->amOnPage('/admin/product/units-of-measure');
        $unitId = (int) $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'EA'])?->getId();
        $I->assertGreaterThan(0, $unitId, 'the seeded EA unit is what this redirect is checked against');

        $I->amOnPage('/admin/product/units-of-measure?edit=' . $unitId);
        $I->seeResponseCodeIs(200);
        $I->seeCurrentUrlEquals('/admin/product/units-of-measure/' . $unitId . '/edit');
        $I->assertSame((string) $unitId, (string) $I->grabAttributeFrom('input[name="id"]', 'value'));
    }

    // ---------------------------------------------------------------------------------------
    // The round trip that must not break
    // ---------------------------------------------------------------------------------------

    /**
     * A policy is created on the create page, then edited on the edit page, and the edit is stored.
     *
     * The whole risk of this change is in the hidden `id`. `{{ editing ? editing.id : 0 }}` and
     * `save()` reading it back are what make a policy editable at all — the debit memo and vendor
     * return screens hard-code `0` into the same field, so every save there is a create and nothing
     * can be edited. Splitting the routes moved the FORM onto its own page; if it had moved the id
     * out of the form as well, the edit would silently become a second create and the row count
     * would rise instead of the row changing.
     *
     * So the edit is proved three ways at once: the row's own columns are re-read and are the posted
     * values, the id is the SAME id, and the number of policies is unchanged across the edit.
     *
     * The control policy is created first, is not touched by anything below, and is compared whole
     * at the end.
     */
    public function aTrackingPolicyIsCreatedThenEditedAndTheEditIsStored(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $suffix = strtoupper(substr(uniqid(), -6));

        // The row that must NOT change. Distinct in every column from what the subject is edited to.
        $control = (new TrackingPolicy())
            ->setName('Control ' . $suffix)
            ->setMode(TrackingPolicy::MODE_LOT)
            ->setRequiresExpiry(true)
            ->setTrackIn(true)
            ->setTrackOut(false)
            ->setSentinelIn('[CTRL-IN]')
            ->setSentinelOut('[CTRL-OUT]');
        $em->persist($control);
        $em->flush();
        $controlId = (int) $control->getId();
        $controlBefore = $this->row($I, 'tracking_policy', self::POLICY_COLUMNS, $controlId);

        // ---- create, on the create page ----
        $I->amOnPage('/admin/product/tracking-policies/new');
        $I->seeResponseCodeIs(200);

        $I->sendFormPostRequest('/admin/product/tracking-policies/save', [
            '_token' => $this->formToken($I),
            'id' => '0',
            'name' => 'Subject ' . $suffix,
            'mode' => TrackingPolicy::MODE_SERIAL,
            'track_in' => '1',
            'sentinel_in' => '[NEW-IN]',
        ]);

        $em->clear();
        $created = $em->getRepository(TrackingPolicy::class)->findOneBy(['name' => 'Subject ' . $suffix]);
        $I->assertNotNull($created, 'the create page must actually create');
        $subjectId = (int) $created->getId();

        $afterCreate = $this->row($I, 'tracking_policy', self::POLICY_COLUMNS, $subjectId);
        $I->assertSame(TrackingPolicy::MODE_SERIAL, $afterCreate['mode']);
        $I->assertSame(1, (int) $afterCreate['track_in']);
        $I->assertSame(0, (int) $afterCreate['track_out']);
        $I->assertSame('[NEW-IN]', $afterCreate['sentinel_in']);

        $policiesAfterCreate = $this->policyCount($I);

        // ---- edit, on the edit page ----
        $I->amOnPage('/admin/product/tracking-policies/' . $subjectId . '/edit');
        $I->seeResponseCodeIs(200);

        // The round trip itself: the edit page carries the id of the row it is editing, and it is
        // in the field save() reads. If this is 0 the next post is a create, not an edit.
        $I->assertSame(
            (string) $subjectId,
            (string) $I->grabAttributeFrom('input[name="id"]', 'value'),
            'the edit page must carry the id of the policy it is editing — a 0 here is the debit-memo defect',
        );

        $I->sendFormPostRequest('/admin/product/tracking-policies/save', [
            '_token' => $this->formToken($I),
            'id' => (string) $subjectId,
            'name' => 'Subject Renamed ' . $suffix,
            'mode' => TrackingPolicy::MODE_LOT,
            'requires_expiry' => '1',
            'track_in' => '1',
            'track_out' => '1',
            'sentinel_in' => '[EDITED-IN]',
            'sentinel_out' => '[EDITED-OUT]',
        ]);

        // Re-read by column, off the connection. Not the entity the controller mutated.
        $afterEdit = $this->row($I, 'tracking_policy', self::POLICY_COLUMNS, $subjectId);
        $I->assertSame('Subject Renamed ' . $suffix, $afterEdit['name']);
        $I->assertSame(TrackingPolicy::MODE_LOT, $afterEdit['mode']);
        $I->assertSame(1, (int) $afterEdit['requires_expiry']);
        $I->assertSame(1, (int) $afterEdit['track_in']);
        $I->assertSame(1, (int) $afterEdit['track_out']);
        $I->assertSame('[EDITED-IN]', $afterEdit['sentinel_in']);
        $I->assertSame('[EDITED-OUT]', $afterEdit['sentinel_out']);

        // An edit changes a row; it does not add one. This is what a hidden id of 0 would break,
        // and the column assertions above would not catch it — a second row called "Subject
        // Renamed" satisfies every one of them.
        $I->assertSame(
            $policiesAfterCreate,
            $this->policyCount($I),
            'editing a policy must change the row, not create a second one',
        );

        // ---- the row that should NOT have changed ----
        $I->assertSame(
            $controlBefore,
            $this->row($I, 'tracking_policy', self::POLICY_COLUMNS, $controlId),
            'the control policy was not edited and must come back exactly as it was left',
        );
    }

    /**
     * The same round trip on Units of Measure, where the shared table makes the control row matter
     * most: since #659 every quantity in the application is denominated in one of these rows.
     */
    public function aUnitIsCreatedThenEditedAndTheEditIsStored(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $suffix = strtoupper(substr(uniqid(), -6));

        $control = (new UnitOfMeasure())
            ->setCode('CTRL-' . $suffix)
            ->setName('Control unit')
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase('7')
            ->setRoundingPrecision('1');
        $em->persist($control);
        $em->flush();
        $controlId = (int) $control->getId();
        $controlBefore = $this->row($I, 'unit_of_measure', self::UNIT_COLUMNS, $controlId);

        $I->amOnPage('/admin/product/units-of-measure/new');
        $I->seeResponseCodeIs(200);

        $I->sendFormPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $this->formToken($I),
            'id' => '0',
            'code' => 'SUBJ-' . $suffix,
            'name' => 'Subject unit',
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '5',
            'rounding_precision' => '1',
        ]);

        $em->clear();
        $created = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'SUBJ-' . $suffix]);
        $I->assertNotNull($created, 'the create page must actually create');
        $subjectId = (int) $created->getId();

        $I->amOnPage('/admin/product/units-of-measure/' . $subjectId . '/edit');
        $I->seeResponseCodeIs(200);
        $I->assertSame(
            (string) $subjectId,
            (string) $I->grabAttributeFrom('input[name="id"]', 'value'),
            'the edit page must carry the id of the unit it is editing',
        );

        // Nothing references this unit, so the freeze has nothing to hold and a restatement is
        // allowed. That is the positive control for the refusal asserted below.
        $I->sendFormPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $this->formToken($I),
            'id' => (string) $subjectId,
            'code' => 'SUBJ2-' . $suffix,
            'name' => 'Subject unit renamed',
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '9',
            'rounding_precision' => '1',
        ]);

        $afterEdit = $this->row($I, 'unit_of_measure', self::UNIT_COLUMNS, $subjectId);
        $I->assertSame('SUBJ2-' . $suffix, $afterEdit['code']);
        $I->assertSame('Subject unit renamed', $afterEdit['name']);
        // Compared as a number, not as a string: the column is NUMERIC(18,6) and SQLite stores what
        // it was given, so the same value reads back as '9' here and as '9.000000' through the
        // entity's DECIMAL type. Asserting the string would be asserting the storage engine.
        $I->assertSame(9.0, (float) $afterEdit['factor_to_family_base']);

        $I->assertSame(
            $controlBefore,
            $this->row($I, 'unit_of_measure', self::UNIT_COLUMNS, $controlId),
            'the control unit was not edited and must come back exactly as it was left',
        );
    }

    // ---------------------------------------------------------------------------------------
    // The refusals, through the new routes
    // ---------------------------------------------------------------------------------------

    /**
     * A referenced unit is still frozen and still undeletable, posting from the NEW pages.
     *
     * This is the assertion the split has to earn. `UnitOfMeasureService` is what refuses a
     * restatement — `Box-12` means twelve forever, and editing it to mean twenty-four would restate
     * every document ever written against it at once, with nothing recorded and nothing to reconcile.
     * A split that gave the edit page a write of its own would route around that guard, and the
     * screen would look identical while doing the one thing this table must never do.
     *
     * It does not, because the split added no writer: the new pages are GET renderers and post to
     * the same `save()` that has always called the service. Proved rather than asserted — the post
     * is made from the new edit page, and the factor is re-read from the column afterwards.
     *
     * A refusal on its own proves nothing: a post that never arrived — a wrong action, a rejected
     * token, a 404 on the new route — leaves the row exactly as untouched as a refusal does. So the
     * refused post is PAIRED with one that must succeed from the same page: a name-only correction,
     * which restates nothing and which the service deliberately still allows on a referenced unit,
     * because a description nobody computes with should not be made permanent by a typo. The pair is
     * what separates "the guard fired" from "nothing reached the controller".
     *
     * Note the refusal is all-or-nothing: `update()` validates and then throws before applying any
     * field, so the rejected post did not quietly land its harmless half either.
     */
    public function aReferencedUnitIsStillFrozenAndUndeletableThroughTheNewPages(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $suffix = strtoupper(substr(uniqid(), -6));

        $box = (new UnitOfMeasure())
            ->setCode('FRZ-' . $suffix)
            ->setName('Box of 12')
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase('12')
            ->setRoundingPrecision('1');
        $em->persist($box);

        // The reference. `product_core.unit_id` is one of the columns the service's metadata sweep
        // discovers, so pointing a product at the unit is enough to freeze it.
        $product = (new ProductCore())->setSku('FRZ-' . $suffix)->setName('Frozen unit product');
        $product->setBaseUnit($box);
        $em->persist($product);
        $em->flush();

        $boxId = (int) $box->getId();
        $before = $this->row($I, 'unit_of_measure', self::UNIT_COLUMNS, $boxId);

        // ---- the restatement, posted from the new edit page ----
        $I->amOnPage('/admin/product/units-of-measure/' . $boxId . '/edit');
        $I->seeResponseCodeIs(200);

        $I->sendFormPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $this->formToken($I),
            'id' => (string) $boxId,
            'code' => 'FRZ-' . $suffix,
            'name' => 'Box of twenty-four',
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '24',
            'rounding_precision' => '1',
        ]);

        $afterRestate = $this->row($I, 'unit_of_measure', self::UNIT_COLUMNS, $boxId);
        $I->assertSame(
            $before,
            $afterRestate,
            'a referenced unit means what it meant — the freeze must apply to the new edit page too,'
            . ' and it refuses the whole post rather than landing part of it',
        );

        // ---- the positive control: a correction that restates nothing, from the same page ----
        $I->amOnPage('/admin/product/units-of-measure/' . $boxId . '/edit');
        $I->sendFormPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $this->formToken($I),
            'id' => (string) $boxId,
            'code' => 'FRZ-' . $suffix,
            'name' => 'Box of twelve',
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '12',
            'rounding_precision' => '1',
        ]);

        $afterCorrection = $this->row($I, 'unit_of_measure', self::UNIT_COLUMNS, $boxId);
        $I->assertSame(
            'Box of twelve',
            $afterCorrection['name'],
            'a name is a description nobody computes with and stays correctable on a referenced unit'
            . ' — without this passing, the refusal above could just as well be a post that never'
            . ' reached the controller',
        );
        $I->assertSame(
            $before['factor_to_family_base'],
            $afterCorrection['factor_to_family_base'],
            'and the correction changed nothing the freeze holds',
        );

        // ---- the deletion, posted from the new list page ----
        $I->amOnPage('/admin/product/units-of-measure');
        $I->sendFormPostRequest('/admin/product/units-of-measure/' . $boxId . '/delete', [
            '_token' => $I->csrfToken(),
        ]);

        $I->assertSame(
            $afterCorrection,
            $this->row($I, 'unit_of_measure', self::UNIT_COLUMNS, $boxId),
            'a referenced unit must not be deletable, and nothing about it may change on the attempt',
        );
    }

    /**
     * Deleting a tracking policy: what it does, what it refuses, and the row it must not touch.
     *
     * The delete was already correct before the split and is checked here because the brief asked
     * what it does, and because "already correct" is a claim a test should make rather than a note:
     *
     *  - a policy products point at is REFUSED. The foreign key is `SET NULL`, so cascading would
     *    quietly untrack every product on it — a silent change to what identity their stock must
     *    carry, which is the exact thing this table exists to state out loud;
     *  - the default policy is REFUSED, because it is what every product falls back to;
     *  - an unused, non-default policy is deleted.
     *
     * The product is re-read by column afterwards, not just the policy: the failure mode worth
     * fearing is not "the row survived" but "the row survived and the product lost its pointer".
     */
    public function deletingAPolicyRefusesTheOnesItMustAndRemovesTheOneItMay(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $suffix = strtoupper(substr(uniqid(), -6));

        $inUse = (new TrackingPolicy())->setName('In use ' . $suffix)->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true);
        $em->persist($inUse);

        $spare = (new TrackingPolicy())->setName('Spare ' . $suffix)->setMode(TrackingPolicy::MODE_SERIAL);
        $em->persist($spare);

        $product = (new ProductCore())->setSku('TRKDEL-' . $suffix)->setName('Tracked product');
        $product->setTrackingPolicy($inUse);
        $em->persist($product);
        $em->flush();

        $inUseId = (int) $inUse->getId();
        $spareId = (int) $spare->getId();
        $productId = (int) $product->getId();

        $I->amOnPage('/admin/product/tracking-policies');
        $I->seeResponseCodeIs(200);
        $token = $I->csrfToken();

        // The default is seeded by the index above, so it exists to be refused.
        $defaultId = (int) $em->getRepository(TrackingPolicy::class)
            ->findOneBy(['name' => TrackingPolicy::DEFAULT_NAME])?->getId();
        $I->assertGreaterThan(0, $defaultId, 'the default policy is seeded on first view of the list');

        // ---- refused: in use ----
        $I->sendFormPostRequest('/admin/product/tracking-policies/' . $inUseId . '/delete', ['_token' => $token]);
        $I->assertTrue(
            $this->rowExists($I, 'tracking_policy', $inUseId),
            'a policy products point at must not be deletable — the foreign key is SET NULL, so'
            . ' deleting it would silently untrack every product on it',
        );
        $I->assertSame(
            $inUseId,
            (int) $this->row($I, 'product_core', 'tracking_policy_id', $productId)['tracking_policy_id'],
            'and the product must still point at it — a refusal that untracked the product anyway'
            . ' would be the defect the refusal exists to prevent',
        );

        // ---- refused: the default ----
        $I->amOnPage('/admin/product/tracking-policies');
        $I->sendFormPostRequest('/admin/product/tracking-policies/' . $defaultId . '/delete', ['_token' => $I->csrfToken()]);
        $I->assertTrue(
            $this->rowExists($I, 'tracking_policy', $defaultId),
            'the policy every product falls back to must not be deletable',
        );

        // ---- allowed: unused and not the default ----
        $I->amOnPage('/admin/product/tracking-policies');
        $I->sendFormPostRequest('/admin/product/tracking-policies/' . $spareId . '/delete', ['_token' => $I->csrfToken()]);

        $I->assertFalse(
            $this->rowExists($I, 'tracking_policy', $spareId),
            'an unused, non-default policy is deleted — otherwise the two refusals above prove'
            . ' nothing, since a delete route that did nothing at all would satisfy them both',
        );
    }

    private function policyCount(FunctionalTester $I): int
    {
        /** @var Connection $connection */
        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM tracking_policy');
    }
}
