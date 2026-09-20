<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The status seam on the first document wired to it (queue item 64, stage 2).
 *
 * `SalesOrder` was chosen as the one document to prove the seam on, over the smaller candidates,
 * for three reasons. It is the document the handoff's section 2b was written about —
 * `SalesOrderStatusDeriver::statusFor()` is named there as the model. It is the only sales document
 * that exercises every branch of the interface: a human action (`approve()`), a terminal human
 * action (`void()`), a derived path, a real timeline entity, and a column that already holds legacy
 * values the vocabulary has never known. And its `$status` is already a plain `string` column, so
 * wiring it changes no property type and no Doctrine mapping — the buy-side documents are
 * enum-typed and will.
 *
 * ## What kind of evidence this file is, stated plainly
 *
 * The change is SHAPE ONLY, so most of what a screen can see is supposed to be identical before and
 * after — and the cases below that go through the real screens are CHARACTERISATION: they pin
 * behaviour that must not move, and they pass on both sides of the change. Claiming them as
 * failure-then-pass evidence would be a lie about what they are.
 *
 * The cases that genuinely could not pass before the seam existed are the ones asking the new
 * questions — `statusLabel()`, `allowedTransitions()`, `isStatus()`, `statusIsRecognised()` — and
 * the one proving a status change can no longer be recorded against nobody. Those are marked.
 *
 * Every case re-reads its claim from the database BY COLUMN through a fresh connection, and every
 * case carries a second order that must NOT move.
 */
final class SalesOrderStatusSeamCest
{
    private function loginAsAdmin(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('status-seam-' . uniqid() . '@example.test')
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
            ->setName('Status Seam Co')
            ->setCode('SSC-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('SSC-SKU-' . uniqid())
            ->setName('Status Seam Widget')
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

    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product, string $status): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SSC-' . uniqid())
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

        // A match with no default on purpose: asking for a status an order cannot be PUT INTO
        // fails loudly here rather than quietly producing a Draft that passes the wrong test.
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
     * The status COLUMN, read straight out of SQL.
     *
     * Not `getStatus()`, deliberately: the whole claim of this file is about what reaches the
     * database, and an accessor that returned a cached or defaulted value would let every assertion
     * below pass without a row ever changing.
     */
    private function statusColumn(FunctionalTester $I, int $orderId): string
    {
        $connection = $I->grabService(Connection::class);

        return (string) $connection->fetchOne('SELECT status FROM sales_order WHERE id = ?', [$orderId]);
    }

    /**
     * @return list<array{comment: string, user_name: ?string, type: string}> oldest first
     *
     * Reads audit_log now — SalesOrder's own timeline entries land there
     * (AbstractSalesDocument::queueActivityLogEntry(), drained by AuditLogSubscriber), not in a
     * dedicated sales_order_log table any more. Aliased back to the old column names so every
     * caller below reads unchanged.
     */
    private function logRows(FunctionalTester $I, int $orderId): array
    {
        $connection = $I->grabService(Connection::class);

        // actor_type = 'document' is the narrative timeline's own marker (set by
        // AuditLogger::flushQueued()'s activity-log branch) — it is what tells these rows apart
        // from the generic field-diff rows AuditLogSubscriber already writes for SalesOrder as an
        // ordinary entity (a 'created'/'updated' row on every save, same entity_type, unrelated
        // actor_type), which this table now also carries.
        return $connection->fetchAllAssociative(
            "SELECT summary AS comment, actor_name AS user_name, action AS type
             FROM audit_log WHERE entity_type = 'SalesOrder' AND entity_id = ? AND actor_type = 'document'
             ORDER BY id ASC",
            [$orderId],
        );
    }

    private function reload(FunctionalTester $I, int $orderId): SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(SalesOrder::class, $orderId);
    }

    // ---------------------------------------------------------------------------------------------
    // Characterisation: the real screens must behave exactly as they did. These pass before and
    // after, and they are here because a shape-only change that moved any of them would be a defect.
    // ---------------------------------------------------------------------------------------------

    /**
     * Approving through the real screen writes Approved, and writes ONE timeline row carrying the
     * acting admin's name.
     *
     * The row is now written by `setStatus()` rather than by `approve()` beside it. That is the
     * whole of section 8's attribution rule, and the visible consequence is supposed to be none at
     * all — same status, same one row, same name.
     */
    public function approvingAnOrderThroughTheScreenWritesTheStatusAndOneAttributedTimelineRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $order = $this->makeOrder($I, $company, $product, 'Draft');
        // The row that must not change: a second draft nobody touches.
        $untouched = $this->makeOrder($I, $company, $product, 'Draft');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
        ]);
        $I->seeResponseCodeIs(200);

        $I->assertSame('Approved', $this->statusColumn($I, (int) $order->getId()));
        $I->assertSame('Draft', $this->statusColumn($I, (int) $untouched->getId()));

        $rows = $this->logRows($I, (int) $order->getId());
        $I->assertCount(1, $rows, 'exactly one row: setStatus() writes it, and approve() no longer writes a second');
        $I->assertSame('Order approved.', $rows[0]['comment']);
        $I->assertSame('System', $rows[0]['type']);
        $I->assertStringContainsString(
            'Priya Raman',
            (string) $rows[0]['user_name'],
            'the acting admin, not the machine — this is the attribution setStatus() now guarantees',
        );

        $I->assertSame([], $this->logRows($I, (int) $untouched->getId()), 'the untouched order gained no row');
    }

    /**
     * Voiding writes Void and keeps its own wording, including the from-state it has always
     * recorded.
     */
    public function voidingAnOrderThroughTheScreenKeepsItsOwnWording(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $order = $this->makeOrder($I, $company, $product, 'Approved');
        $untouched = $this->makeOrder($I, $company, $product, 'Approved');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Void',
        ]);
        $I->seeResponseCodeIs(200);

        $I->assertSame('Void', $this->statusColumn($I, (int) $order->getId()));
        $I->assertSame('Approved', $this->statusColumn($I, (int) $untouched->getId()));

        $comments = array_column($this->logRows($I, (int) $order->getId()), 'comment');
        $I->assertContains(
            'Order voided (was Approved).',
            $comments,
            'the verb passes its own wording through setStatus() rather than setStatus() inventing one',
        );
    }

    /**
     * A derived move writes the status and exactly ONE row, attributed to the machine.
     *
     * The deriver used to write that row itself, right after calling `applyDerivedStatus()`. It no
     * longer does — `setStatus()` does. If both had been left in place this case would find two
     * rows, which is the specific defect the handoff warns about in section 2b.
     */
    public function aDerivedMoveWritesOneRowAttributedToTheMachine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $order = $this->makeOrder($I, $company, $product, 'Approved');
        $untouched = $this->makeOrder($I, $company, $product, 'Approved');

        $before = count($this->logRows($I, (int) $order->getId()));

        // The real screen that raises an invoice against an order — which is what moves the order
        // off Approved, through the deriver, without anybody naming a status. Half the ordered
        // quantity, so the order lands on Partially Invoiced rather than skipping to Invoiced.
        $line = $order->getLines()->first();
        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['id' => (string) $line->getId(), 'qty' => '1.00', 'price' => '50.00']],
        ]);

        $status = $this->statusColumn($I, (int) $order->getId());
        $I->assertNotSame('Approved', $status, 'raising an invoice must have moved the order');
        $I->assertContains($status, ['Partially Invoiced', 'Invoiced', 'Closed']);

        // Counted by CONTENT, not by total. Raising an invoice also writes an unrelated
        // "Invoice INV-1 raised against this order." row, and a bare count would be asserting
        // against that as much as against the status change.
        $rows = $this->logRows($I, (int) $order->getId());
        $statusRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_starts_with((string) $row['comment'], 'Order status changed from'),
        ));

        $I->assertCount(
            1,
            $statusRows,
            'ONE row for one derived transition — two is what leaving the deriver writing its own alongside'
                . ' setStatus() would produce, which is the defect section 2b names',
        );
        $I->assertSame('System', $statusRows[0]['user_name'], 'nobody performed this; it is a consequence of an invoice');
        $I->assertSame(
            sprintf('Order status changed from Approved to %s.', $status),
            $statusRows[0]['comment'],
            'the sentence #539 has always written, kept byte-for-byte',
        );
        // The control on the same collection: the unrelated event is still there, so the filter
        // above narrowed the rows rather than the change having swallowed the timeline.
        $I->assertGreaterThan($before, count($rows), 'the order gained timeline rows overall');

        $I->assertSame('Approved', $this->statusColumn($I, (int) $untouched->getId()));
    }

    /** A move the endpoint refuses leaves the column exactly where it was. */
    public function aRefusedMoveChangesNoRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $order = $this->makeOrder($I, $company, $product, 'Approved');
        $untouched = $this->makeOrder($I, $company, $product, 'Draft');

        $before = count($this->logRows($I, (int) $order->getId()));

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        // Approving an order that is already live: refused by approve()'s own guard, which is
        // deliberately stricter than the vocabulary because the vocabulary has to keep Approved
        // reachable as a DERIVED target from every live state.
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
        ]);

        $I->seeResponseCodeIs(400);
        $I->assertSame('Approved', $this->statusColumn($I, (int) $order->getId()));
        $I->assertSame('Draft', $this->statusColumn($I, (int) $untouched->getId()));
        // Not "no rows" — the fixture's own approve() wrote one. The claim is that the REFUSAL
        // added nothing to it, which is the thing that would have changed had setStatus() written
        // its row before checking the move.
        $I->assertSame(
            $before,
            count($this->logRows($I, (int) $order->getId())),
            'a refusal writes no timeline row',
        );
    }

    // ---------------------------------------------------------------------------------------------
    // The seam itself. None of these could pass before SalesOrder implemented HasStatus — the
    // methods did not exist on it.
    // ---------------------------------------------------------------------------------------------

    /** The vocabulary reaches a real, hydrated order, and carries both halves of every status. */
    public function aHydratedOrderAnswersFromTheVocabulary(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product, 'Draft');

        $reloaded = $this->reload($I, (int) $order->getId());

        $I->assertSame('sales_order', $reloaded->statusVocabulary());
        $I->assertSame(
            ['Draft', 'Approved', 'Partially Invoiced', 'Invoiced', 'Closed', 'Void'],
            array_keys(SalesOrder::listStatuses()),
            'the slugs, in declaration order — this is what a filter bar renders',
        );
        $I->assertSame('Draft', SalesOrder::listStatuses()['Draft'], 'and the label beside each one');

        $I->assertTrue($reloaded->isStatus('Draft'));
        $I->assertFalse($reloaded->isStatus('Approved'));
        $I->assertTrue($reloaded->statusIsRecognised());
        $I->assertSame('Draft', $reloaded->statusLabel());
    }

    /**
     * The typo guard. This is the whole reason `isStatus()` exists rather than a raw comparison.
     *
     * A `getStatus() === 'Draftt'` silently returns false, takes the wrong branch and tells nobody.
     * The positive control on the same object is the line above it: the correctly spelled status
     * answers true, so the throw is about the spelling and not about the object.
     */
    public function aMisspelledStatusThrowsRatherThanQuietlyAnsweringFalse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->reload($I, (int) $this->makeOrder($I, $company, $product, 'Draft')->getId());

        $I->assertTrue($order->isStatus('Draft'), 'positive control: the real spelling answers');

        try {
            $order->isStatus('Draftt');
            $I->fail('A status the vocabulary does not know must throw, not return false.');
        } catch (\LogicException $e) {
            $I->assertStringContainsString('There is no status "Draftt"', $e->getMessage());
            $I->assertStringContainsString('sales_order', $e->getMessage());
        }
    }

    /** Legal moves come from the CURRENT state, and derived ones are in the list carrying their flag. */
    public function allowedTransitionsAnswerFromWhereTheOrderIsNow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        // Every fixture first, then the reloads. reload() clears the EntityManager, which detaches
        // $company and $product — a makeOrder() after one of those cascades them as NEW entities and
        // Doctrine refuses the flush.
        $draftId = (int) $this->makeOrder($I, $company, $product, 'Draft')->getId();
        $voidId = (int) $this->makeOrder($I, $company, $product, 'Void')->getId();

        $draft = $this->reload($I, $draftId);
        $moves = $draft->allowedTransitions();

        $I->assertArrayHasKey('Approved', $moves);
        $I->assertArrayHasKey('Void', $moves);
        $I->assertTrue($moves['Approved']['derived'], 'the deriver may write Approved — and so may approve()');
        $I->assertFalse($moves['Void']['derived'], 'Void is only ever a judgement somebody makes');

        $I->assertTrue($draft->canTransitionTo('Approved'));
        $I->assertTrue($draft->canTransitionTo('Void'));

        $I->assertSame(
            [],
            $this->reload($I, $voidId)->allowedTransitions(),
            'Void is final: nothing moves out of it',
        );
    }

    /**
     * Section 7: a stored value the vocabulary has never known still LOADS, and says so.
     *
     * Written straight into the column, which is the only way to produce one — exactly as the
     * `ProductCore` case this copies does. The row hydrates, `getStatus()` returns what is stored,
     * `statusIsRecognised()` is false, and `statusLabel()` marks it rather than throwing or
     * rendering a blank where a status should be.
     *
     * The control is the second order, whose perfectly ordinary status is asserted through the SAME
     * four methods: an implementation that marked everything unrecognised would pass the first half
     * of this case on its own.
     *
     * **`allowedTransitions()` was amended here, and it is the one assertion in this file that
     * changed.** It used to read `assertSame([], $reloaded->allowedTransitions())` — "and offers no
     * moves rather than throwing". The empty list was the load-side face of a dead end: the same
     * `!has($current)` clause refused every move out of an unrecognised status inside `setStatus()`
     * too, so such a document could not be transitioned by any means and could not be SAVED at all,
     * because the save path reaches that guard through the deriver. The owner's ruling is that you
     * can always leave a place that no longer exists; you just cannot go back to it — so every known
     * status is now a way out, and `UnknownStatusIsNotADeadEndCest` is where that is proved end to
     * end. Nothing else about this case moved: an unrecognised value still loads, still reports, and
     * still never refuses.
     */
    public function aStatusTheVocabularyNeverKnewStillLoadsAndSaysSo(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $stray = $this->makeOrder($I, $company, $product, 'Draft');
        $ordinary = $this->makeOrder($I, $company, $product, 'Draft');

        // A quote-era value, of the kind the SalesOrderStatus docblock records as still being in
        // customer databases. Nothing in the application can write one any more.
        $I->grabService(Connection::class)->executeStatement(
            'UPDATE sales_order SET status = ? WHERE id = ?',
            ['Waiting for Quote', (int) $stray->getId()],
        );

        $reloaded = $this->reload($I, (int) $stray->getId());

        $I->assertSame('Waiting for Quote', $reloaded->getStatus(), 'getStatus() stays forgiving');
        $I->assertFalse($reloaded->statusIsRecognised());
        $I->assertSame('Waiting for Quote (unrecognised)', $reloaded->statusLabel());
        $I->assertSame(
            ['Draft', 'Approved', 'Partially Invoiced', 'Invoiced', 'Closed', 'Void'],
            array_keys($reloaded->allowedTransitions()),
            'every known status is a way OUT of one nobody recognises — and it offers them rather than throwing',
        );

        $control = $this->reload($I, (int) $ordinary->getId());
        $I->assertSame('Draft', $control->getStatus());
        $I->assertTrue($control->statusIsRecognised(), 'positive control on the same four methods');
        $I->assertSame('Draft', $control->statusLabel());
        // Not merely "not empty": a recognised status offers only its OWN declared moves — and the
        // map does not declare Draft -> Draft, so this list is one short of the stranded order's.
        // That is what shows the list above came from the unknown-status rule rather than from the
        // method having been made to answer "everything" for every document.
        $I->assertSame(
            ['Approved', 'Partially Invoiced', 'Invoiced', 'Closed', 'Void'],
            array_keys($control->allowedTransitions()),
            'a Draft still offers exactly what the map declares for a Draft',
        );
        $I->assertArrayNotHasKey(
            'Draft',
            $control->allowedTransitions(),
            'a recognised status is not offered a move to where it already is',
        );
    }

    /**
     * The row that proves section 8's point: a status change cannot be made without recording who
     * made it, because the actor is a required argument and the log is written by the setter.
     */
    public function aStatusChangeCannotBeRecordedAgainstNobody(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->reload($I, (int) $this->makeOrder($I, $company, $product, 'Draft')->getId());

        $order->setStatus('Approved', DocumentActor::automation('Nightly sweep'), 'Approved by the sweep.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $rows = $this->logRows($I, (int) $order->getId());
        $I->assertCount(1, $rows);
        $I->assertSame('Approved by the sweep.', $rows[0]['comment']);
        $I->assertSame(
            'Nightly sweep (automated)',
            $rows[0]['user_name'],
            'an automated actor names itself rather than falling back to System, which would be a FALSE record',
        );

        $I->assertSame('Approved', $this->statusColumn($I, (int) $order->getId()));
    }
}
