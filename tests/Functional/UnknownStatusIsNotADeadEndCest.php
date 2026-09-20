<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Exception\StatusTransitionRefused;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A status the vocabulary no longer knows is a place you may LEAVE (queue item 64, the dead end).
 *
 * ## The defect
 *
 * The status seam's transition guard read:
 *
 *     if (!$vocabulary->has($current) || !$vocabulary->allows($current, $status)) { throw ... }
 *
 * The first clause refused EVERY move out of a status the vocabulary does not know — the throw
 * fired before the target was even looked at. So a sales order holding a legacy value like
 * `Processing` (retired from `SalesOrderStatus` by #539 stage 2, still in customer databases)
 * could not be transitioned by any means, including the status control on its own detail screen,
 * and could not be SAVED at all, because the save path reaches `setStatus()` through the deriver.
 * It was a permanent dead end with no manual escape.
 *
 * ## The ruling
 *
 * **You can always leave a place that no longer exists; you just cannot go back to it.** An unknown
 * CURRENT status permits moves out, to any status the vocabulary does know; nothing may transition
 * INTO an unknown status. That is the transition-side form of what handoff section 7 already says
 * about a load: report an unrecognised value, never refuse on it.
 *
 * ## What each case here is
 *
 * The first three could not pass before this change — they are the failure-then-pass evidence. The
 * fourth is the REGRESSION CONTROL and passes on both sides: without it, a "fix" that merely stopped
 * throwing would pass everything else in this file.
 *
 * Every claim is re-read from the database BY COLUMN through a fresh connection, every case carries
 * a row that must NOT change, and every absence assertion is paired with a positive control on the
 * same method or the same element (#624, #627). No `see()` of a bare status word: `Void`, `Draft`
 * and `Approved` all occur elsewhere on an order page, so every page assertion is anchored to an
 * element id.
 *
 * Codeception reuses ONE instance of this class across its methods, so nothing below is stored on
 * `$this` — every fixture is a local built fresh per case.
 */
final class UnknownStatusIsNotADeadEndCest
{
    /**
     * The legacy value used throughout. A real one: `Processing` was a `SalesOrderStatus` case until
     * #539 stage 2 moved the fulfilment statuses onto the invoice, and rows still hold it.
     */
    private const LEGACY = 'Processing';

    private function loginAsAdmin(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('dead-end-' . uniqid() . '@example.test')
            ->setFirstName('Priya')
            ->setLastName('Raman');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        return $admin;
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Dead End Co')
            ->setCode('DEC-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('DEC-SKU-' . uniqid())
            ->setName('Dead End Widget')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveStockFor($product);

        return $product;
    }

    /**
     * A match with no default, like the other status Cests: asking for a status an order cannot be
     * PUT INTO fails loudly here rather than quietly producing a Draft that passes the wrong test.
     */
    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product, string $status): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('DEC-' . uniqid())
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku((string) $product->getSku())
                ->setQuantity('2.00')
                ->setCost('30.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
                ->setTaxCode('E')
        );

        match ($status) {
            'Draft' => null,
            'Approved' => $order->setStatus('Approved', DocumentActor::system(), 'Order approved.'),
            // Two writes in one arm: the gate takes one target at a time, so reaching Void means
            // approving and then voiding. Evaluated left to right.
            'Void' => [
                $order->setStatus('Approved', DocumentActor::system(), 'Order approved.'),
                $order->setStatus('Void', DocumentActor::system()),
            ],
        };

        $I->haveInRepository($order);

        return $order;
    }

    /**
     * Strands an order on a value the vocabulary has never known.
     *
     * Straight into the column, which is the only way to produce one — nothing in the application
     * can write an unknown status, which is the point case three proves. Same technique as
     * `SalesOrderStatusSeamCest` and as the `ProductCore` case handoff section 7 names as the model.
     */
    private function strand(FunctionalTester $I, SalesOrder $order, string $value = self::LEGACY): int
    {
        $id = (int) $order->getId();

        $I->grabService(Connection::class)->executeStatement(
            'UPDATE sales_order SET status = ? WHERE id = ?',
            [$value, $id],
        );
        $I->assertSame($value, $this->statusColumn($I, $id), 'guard: the legacy row was not seeded');

        return $id;
    }

    /**
     * The status COLUMN, out of SQL.
     *
     * Not `getStatus()`: the claim of every case here is about what reaches the database, and an
     * accessor answering from the identity map would let them pass without a row ever changing.
     */
    private function statusColumn(FunctionalTester $I, int $orderId): string
    {
        return (string) $I->grabService(Connection::class)
            ->fetchOne('SELECT status FROM sales_order WHERE id = ?', [$orderId]);
    }

    /** @return list<string> the timeline comments, oldest first */
    private function logComments(FunctionalTester $I, int $orderId): array
    {
        return array_column(
            $I->grabService(Connection::class)->fetchAllAssociative(
                "SELECT summary AS comment FROM audit_log WHERE entity_type = 'SalesOrder' AND entity_id = ? "
                    . "AND actor_type = 'document' ORDER BY id ASC",
                [$orderId],
            ),
            'comment',
        );
    }

    private function reload(FunctionalTester $I, int $orderId): SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(SalesOrder::class, $orderId);
    }

    /** The fields a real save of this order posts. No `save_mode`: the #236 case. */
    private function savePost(FunctionalTester $I, Company $company, ProductCore $product, array $extra = []): array
    {
        return array_merge([
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ], $extra);
    }

    // ---------------------------------------------------------------------------------------------
    // Failure-then-pass. None of these could pass before the guard was inverted.
    // ---------------------------------------------------------------------------------------------

    /**
     * THE MANUAL ESCAPE: an order stranded on a legacy value is moved off it from the status control
     * on its own detail screen.
     *
     * The control is `#status-modal` on the order detail page, and what it renders is worth being
     * precise about, because the fix does not come from there: the modal's two options — Approve and
     * Void — are HARDCODED in the template rather than built from `allowedTransitions()`, and it
     * posts to `admin_order_update_status`. So what makes the escape work is the guard inside
     * `setStatus()`, not the picker. Before this change, `Void` from a legacy value threw
     * `StatusTransitionRefused` and the endpoint answered 409 with nothing written.
     *
     * `Approve` is NOT the escape and is not asserted as one: `approve()` is stricter than the
     * vocabulary on purpose and takes only a Draft, so from a legacy value it is refused — by its own
     * guard, exactly as it was before this change. Case four pins that.
     */
    public function aStrandedOrderIsMovedOffItsLegacyStatusFromTheStatusControl(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $strandedId = $this->strand($I, $this->makeOrder($I, $company, $product, 'Draft'));
        // The row that must not change: a second order, stranded on the same value, nobody touches.
        $untouchedId = $this->strand($I, $this->makeOrder($I, $company, $product, 'Draft'));

        // The screen really renders for a legacy value, and really carries the control. Anchored to
        // the element id, never a bare word: 'Void' and 'Approved' both occur elsewhere on this page.
        $I->amOnPage('/admin/order/detail/' . $strandedId);
        $I->seeResponseCodeIs(200);
        $I->seeElement('#status-modal');
        $I->see('Void', '#status-modal select');
        // The positive control on the SAME element: the picker holds both of its options, so the
        // assertion above is about Void being offered and not about the select being empty.
        $I->see('Approved', '#status-modal select');

        // The picker's OTHER option, pinned rather than assumed: `approve()` is stricter than the
        // vocabulary on purpose and takes only a Draft, so it is still refused here — by its own
        // guard, exactly as it was before this change. The escape from a legacy value is Void, and
        // then the deriver (case two) is what returns an order to Draft.
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $strandedId, [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
        ]);
        $I->seeResponseCodeIs(400);
        $I->assertSame(self::LEGACY, $this->statusColumn($I, $strandedId), 'a refused verb writes nothing');

        $I->sendAjaxPostRequest('/admin/order/update-status/' . $strandedId, [
            '_token' => $I->csrfToken(),
            'status' => 'Void',
        ]);
        $I->seeResponseCodeIs(200);

        $I->assertSame('Void', $this->statusColumn($I, $strandedId), 'the legacy order was moved off its stranded value');
        $I->assertSame(
            self::LEGACY,
            $this->statusColumn($I, $untouchedId),
            'the untouched order is still stranded — the move was this order\'s, not the vocabulary\'s',
        );

        // The move is recorded, with the from-state void() has always written into its wording.
        $I->assertContains(
            sprintf('Order voided (was %s).', self::LEGACY),
            $this->logComments($I, $strandedId),
            'setStatus() wrote the timeline row for a move out of an unrecognised status too',
        );
        $I->assertSame([], $this->logComments($I, $untouchedId), 'the untouched order gained no row');
    }

    /**
     * #236, the primary target: the DERIVER normalises a legacy value DOWN to Draft, and a save that
     * stated no mode never promotes it.
     *
     * A separate path from the human transition above — nobody names a status here. The save posts
     * no `save_mode`, `SalesOrderDerivedStatusSubscriber` recalculates on the flush,
     * `deriveStatus()` reads a value its enum does not know as not-approved, and
     * `applyDerivedStatus()` writes Draft. Every one of those steps went through the guard that
     * refused, so before this change the save was refused outright and nothing at all was written —
     * which is why `po_number` is asserted here as well: a status that held still because the save
     * never happened would prove nothing.
     */
    public function theDeriverNormalisesALegacyStatusDownToDraftOnAnOrdinarySave(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $strandedId = $this->strand($I, $this->makeOrder($I, $company, $product, 'Draft'));
        $untouchedId = $this->strand($I, $this->makeOrder($I, $company, $product, 'Draft'));

        $I->amOnPage('/admin/order/edit/' . $strandedId);
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $strandedId,
            $this->savePost($I, $company, $product, ['po_number' => 'PO-STRANDED'])
        );

        $saved = $this->reload($I, $strandedId);
        $I->assertSame('PO-STRANDED', $saved->getPoNumber(), 'the save was refused, so nothing here is about the deriver');
        $I->assertSame(
            'Draft',
            $this->statusColumn($I, $strandedId),
            'a legacy status is normalised DOWN by the deriver, never promoted by a save that stated no mode',
        );
        $I->assertSame(
            self::LEGACY,
            $this->statusColumn($I, $untouchedId),
            'the order nobody saved is still stranded',
        );

        $I->assertContains(
            sprintf('Order status changed from %s to Draft.', self::LEGACY),
            $this->logComments($I, $strandedId),
            'the machine signed the normalisation, in the sentence #539 has always written',
        );
    }

    /**
     * The picker is not empty: every status the vocabulary knows is a way OUT of one it does not.
     *
     * A fix that only stopped `setStatus()` throwing would leave `allowedTransitions()` answering the
     * empty list, which reads as "final" to everything that consumes it — so any screen built from it
     * would still offer a stranded document no way off its value. The absence half of this case (Void
     * offers nothing) is paired with the same method on the same two objects.
     */
    public function aStrandedOrderIsOfferedEveryKnownStatusAsAWayOut(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        // Every fixture first, then the reloads: reload() clears the EntityManager, and a
        // makeOrder() after one of those cascades a detached $company as a new entity.
        $strandedId = $this->strand($I, $this->makeOrder($I, $company, $product, 'Draft'));
        $voidId = (int) $this->makeOrder($I, $company, $product, 'Void')->getId();

        $stranded = $this->reload($I, $strandedId);

        $I->assertSame(self::LEGACY, $stranded->getStatus(), 'getStatus() is still forgiving');
        $I->assertFalse($stranded->statusIsRecognised(), 'and the value really is one the vocabulary does not know');

        $moves = $stranded->allowedTransitions();
        $I->assertSame(
            ['Draft', 'Approved', 'Partially Invoiced', 'Invoiced', 'Closed', 'Void'],
            array_keys($moves),
            'every status the vocabulary knows is a way out of one it does not',
        );
        $I->assertNotContains(self::LEGACY, array_keys($moves), 'and the stranded value is not a way back in');
        // Both halves of each entry survive, exactly as they do from a recognised status: a picker
        // needs the slug as the value and the label as the text, and the flag to filter on.
        $I->assertSame('Partially Invoiced', $moves['Partially Invoiced']['label']);
        $I->assertTrue($moves['Approved']['derived']);
        $I->assertFalse($moves['Void']['derived']);

        $I->assertTrue($stranded->canTransitionTo('Void'), 'and the move it offers is one it will accept');

        // The control on the SAME method: an order on a recognised FINAL status still offers nothing,
        // so the list above came from the unknown-status rule and not from the method having been
        // made to answer "everything" for everybody.
        $I->assertSame(
            [],
            $this->reload($I, $voidId)->allowedTransitions(),
            'Void is still final: nothing moves out of it',
        );
    }

    /**
     * Nothing transitions INTO a status the vocabulary does not know — the other half of the ruling,
     * and the half that must NOT have been relaxed.
     *
     * Three doors, each asserted with its positive control on the same door: the endpoint the status
     * control posts to, the predicate, and the one write door itself.
     */
    public function nothingTransitionsIntoAStatusTheVocabularyDoesNotKnow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $orderId = (int) $this->makeOrder($I, $company, $product, 'Draft')->getId();
        $untouchedId = (int) $this->makeOrder($I, $company, $product, 'Draft')->getId();

        // 1. Through the real screen. The endpoint narrows what it may WRITE to the enum, so a
        //    legacy value posted at it is refused before anything is touched.
        $I->amOnPage('/admin/order/detail/' . $orderId);
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $orderId, [
            '_token' => $I->csrfToken(),
            'status' => self::LEGACY,
        ]);
        $I->seeResponseCodeIs(400);
        $I->assertSame('Draft', $this->statusColumn($I, $orderId), 'a refused target writes nothing');

        // 2. The predicate, both ways on the same method.
        $order = $this->reload($I, $orderId);
        $I->assertFalse($order->canTransitionTo(self::LEGACY), 'an unknown target is never legal');
        $I->assertFalse($order->canTransitionTo('Waiting for Quote'), 'nor is any other stray');
        $I->assertTrue($order->canTransitionTo('Approved'), 'positive control on the same method');

        // 3. The one write door. A LogicException rather than a refusal, because an unknown TARGET is
        //    the caller's argument being wrong — this is the typo guard the enum used to provide.
        try {
            $order->setStatus(self::LEGACY, DocumentActor::system());
            $I->fail('setStatus() must refuse a target the vocabulary does not know.');
        } catch (\LogicException $e) {
            $I->assertStringContainsString('There is no status "' . self::LEGACY . '"', $e->getMessage());
            $I->assertStringContainsString('sales_order', $e->getMessage());
        }

        // The positive control on the SAME method and the same object: a known target still lands.
        $I->assertSame('Approved', $order->setStatus('Approved', DocumentActor::system(), 'Approved by the sweep.'));
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->assertSame('Approved', $this->statusColumn($I, $orderId));
        $I->assertSame('Draft', $this->statusColumn($I, $untouchedId), 'the untouched order did not move');
    }

    // ---------------------------------------------------------------------------------------------
    // The regression control. This passes on BOTH sides of the change, and it is the case that makes
    // the three above mean something: a "fix" that simply stopped throwing would pass all of them.
    // ---------------------------------------------------------------------------------------------

    /**
     * An order on a KNOWN status still has its illegal moves refused, exactly where they were refused
     * before.
     *
     * Two refusals, because there are two kinds and a fix could have flattened either:
     *
     *  - the VOCABULARY's — Void is final, nothing moves out of it, and `setStatus()` says so;
     *  - the VERB's — `approve()` takes only a Draft, which is deliberately stricter than the map
     *    (the map has to keep Approved reachable as a DERIVED target from every live state).
     */
    public function anOrderOnAKnownStatusStillHasItsIllegalMovesRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $voidId = (int) $this->makeOrder($I, $company, $product, 'Void')->getId();
        $liveId = (int) $this->makeOrder($I, $company, $product, 'Approved')->getId();
        $draftId = (int) $this->makeOrder($I, $company, $product, 'Draft')->getId();

        // --- the vocabulary's refusal, at the one write door -------------------------------------
        $void = $this->reload($I, $voidId);
        $I->assertTrue($void->statusIsRecognised(), 'this one is on a status the vocabulary DOES know');
        $I->assertFalse($void->canTransitionTo('Approved'), 'and Void is final');

        try {
            $void->setStatus('Approved', DocumentActor::system());
            $I->fail('Void is final; moving out of it must still be refused.');
        } catch (StatusTransitionRefused $e) {
            $I->assertSame('Void', $e->from);
            $I->assertSame('Approved', $e->to);
            $I->assertStringContainsString('is a final status', $e->getMessage());
        }

        $I->assertSame('Void', $this->statusColumn($I, $voidId), 'the refusal wrote nothing');

        // The positive control on the SAME method: a Draft still transitions, so the refusal above is
        // about Void and not about setStatus() having been broken shut.
        $draft = $this->reload($I, $draftId);
        $I->assertSame('Approved', $draft->setStatus('Approved', DocumentActor::system(), 'Order approved.'));
        $I->grabService(EntityManagerInterface::class)->flush();
        $I->assertSame('Approved', $this->statusColumn($I, $draftId));

        // --- the verb's refusal, through the real screen -------------------------------------------
        $before = count($this->logComments($I, $liveId));

        $I->amOnPage('/admin/order/detail/' . $liveId);
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $liveId, [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
        ]);
        $I->seeResponseCodeIs(400);
        $I->assertSame('Approved', $this->statusColumn($I, $liveId), 'approving a live order is still refused');
        $I->assertSame($before, count($this->logComments($I, $liveId)), 'and a refusal writes no timeline row');

        // The positive control on the SAME endpoint and the SAME order: the control still works.
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $liveId, [
            '_token' => $I->csrfToken(),
            'status' => 'Void',
        ]);
        $I->seeResponseCodeIs(200);
        $I->assertSame('Void', $this->statusColumn($I, $liveId));

        $I->assertSame('Void', $this->statusColumn($I, $voidId), 'the order nobody could move is where it was');
    }
}
