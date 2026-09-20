<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A sales return raised by mistake can be got rid of without first pretending the customer agreed —
 * and nothing that was already frozen thawed on the way.
 *
 * ## The defect these tests pin down
 *
 * `SalesReturn::decline()` used to start at Authorised. `close()` starts at Received, `receive()` at
 * Authorised, and `SalesReturnController` has no delete route — nothing in this application does,
 * for any document. So a Requested return had exactly one exit: authorise it, then decline it.
 * Getting rid of an RMA typed against the wrong customer meant first recording an agreement with
 * that customer that never happened.
 *
 * The sharp case is the return that sums to NO UNITS, and
 * `theZeroUnitReturnThatUsedToHaveNoExitAtAll()` is written for it specifically. `authorise()`
 * refuses a return with no units on it, and `SalesReturnLine::getUnits()` rounds — so a line typed
 * as 0.40 passes the editor's "at least one line with a product and a quantity on it" check, is
 * stored as `0.4000`, and still sums to zero whole units. That document could not be authorised,
 * therefore could not be declined, therefore could not be closed, and could not be deleted. There
 * was no sequence of actions on any screen that disposed of it. It was permanent.
 *
 * That path is driven here through the real create form rather than by constructing an entity,
 * because the claim being made is that a person can reach this state, not that PHP can.
 *
 * ## Why declining is the abandon route, rather than a new Cancelled state
 *
 * `App\Enum\SalesReturnStatus` has argued the case since #596 and it is followed rather than
 * re-litigated: "a return the customer changed their mind about is Declined, with the reason saying
 * so", because two terminal not-happening states are a choice with no consequence attached to it.
 * `decline()` records the from-state in `notes`, so a draft dropped before anybody agreed and a
 * parcel refused after it arrived stay legible as the different events they are.
 *
 * The buy side had the identical defect and was fixed first — see
 * `VendorReturnDraftCancellationCest`, which this mirrors. That fix quoted this enum's argument
 * from core; this is core closing the loop rather than core having been right all along.
 *
 * ## Both directions are asserted, per #624
 *
 * The third and fourth tests matter as much as the first two. A fix that made everything declinable
 * would pass the first two alone and would be a far worse defect than the one being fixed. So they
 * prove the refusals that must survive — receipt still only from Authorised, lines frozen past
 * Requested, terminal states staying terminal — and that the stock a Received return moves is
 * exactly what it always moved, while a declined draft moves none of it and raises no credit.
 *
 * Every assertion re-reads `sales_return` / `sales_return_line` / `product_inventory` /
 * `inventory_detail` / `credit_memo` from the database rather than trusting an entity fetched
 * beforehand, and every test carries a second, unrelated return that is proved untouched.
 */
final class SalesReturnDraftCancellationCest
{
    /**
     * AppSettings caches its rows in a pool that lives OUTSIDE the per-test transaction, so a
     * snapshot taken here survives the rollback and is read by whatever runs next. Every return
     * allocates a number through SalesReturnNumberGenerator, which reads `sales_return_number_prefix`
     * through that cache — so this clears it for the reason AdminCreditMemoCest does.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    /*
     * ------------------------------------------------------------------------------------------
     * 1. A draft is abandoned where it stands
     * ------------------------------------------------------------------------------------------
     */

    /**
     * A Requested return is declined without being authorised first, and the return beside it does
     * not move.
     *
     * The control return is the cheap half of #624 and it is doing real work: `decline()` reaches a
     * status column and APPENDS to a notes column, and a guard written against the wrong subject
     * would land on every Requested row in the table.
     */
    public function aRequestedReturnIsDeclinedWithoutBeingAuthorisedFirst(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);
        $seed = $this->seed($I);

        $mistake = $this->raiseReturn($I, $seed, 'a', '2.00', 'Raised against the wrong customer.');
        $control = $this->raiseReturn($I, $seed, 'b', '7.00', 'A genuine return, must be untouched.');

        // --- Before: both are Requested, agreed with nobody ------------------------------------------
        $em->clear();
        $mistakeBefore = $this->returnRow($em, $mistake);
        $controlBefore = $this->returnRow($em, $control);
        $I->assertSame('Requested', $mistakeBefore['status']);
        $I->assertSame('Requested', $controlBefore['status']);
        $I->assertNull($mistakeBefore['authorised_at'], 'guard: nothing has been agreed with the customer yet');
        $I->assertNull($mistakeBefore['received_at'], 'guard: and no parcel has arrived');
        $I->assertSame(['7.00'], $this->lineQuantities($em, $control), 'guard: the control return names seven units before anything happens');

        // --- The screen offers the way out, without going through Authorise first ---------------------
        $I->amOnPage('/admin/sales-return/' . $mistake);
        $I->seeResponseCodeIsSuccessful();
        // Positive control for the two assertions below: this proves the detail screen rendered at
        // all, so a missing element is a missing element and not a blank response.
        $I->see('Cancellable Widget A');
        // The <form> shell at the foot of detail.html.twig is unconditional — it is the POST target
        // the controls bind to with the HTML5 `form` attribute — so asserting the form element would
        // pass whatever the status is. The BUTTON is the conditional part, and it is what an
        // operator can actually reach.
        $I->seeElement('button[form="decline-form"]');
        $I->seeElement('#authorise-form button');
        $I->see('does not have to be authorised first just to get rid of it');

        $token = $I->grabAttributeFrom('#decline-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $mistake . '/action/decline', [
            '_token' => $token,
            'reason' => 'Typed against the wrong customer.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        // --- After: declined, and never authorised on the way ------------------------------------------
        $em->clear();
        $mistakeAfter = $this->returnRow($em, $mistake);
        $I->assertSame('Declined', $mistakeAfter['status'], 'sales_return.status moved straight from Requested to Declined');
        $I->assertNull($mistakeAfter['authorised_at'], 'sales_return.authorised_at is still NULL — no customer agreement was fabricated to earn the right to kill it');
        $I->assertNull($mistakeAfter['received_at'], 'sales_return.received_at is still NULL — declining receives nothing');
        $I->assertNull($mistakeAfter['warehouse_id'], 'and no warehouse was stamped: nothing arrived at any building');
        $I->assertStringContainsString('was Requested', (string) $mistakeAfter['notes'], 'the note records the state it was dropped from, so this stays distinguishable from a refusal after receipt');
        $I->assertStringContainsString('Typed against the wrong customer.', (string) $mistakeAfter['notes'], 'and keeps the typed reason');

        // --- The row that must NOT have changed ----------------------------------------------------------
        $controlAfter = $this->returnRow($em, $control);
        $I->assertSame('Requested', $controlAfter['status'], 'the unrelated return is still Requested');
        $I->assertNull($controlAfter['authorised_at']);
        $I->assertNull($controlAfter['notes'], 'nothing was appended to the control return\'s notes');
        $I->assertSame($controlBefore['reason'], $controlAfter['reason'], 'and its reason is byte-for-byte what it was');
        $I->assertSame(['7.00'], $this->lineQuantities($em, $control), 'its line still names seven units');

        // --- Declining a draft is paperwork: no stock, no money -------------------------------------------
        foreach (['a', 'b'] as $tag) {
            $inventory = $this->inventoryFor($em, $seed['product' . $tag], $seed['warehouse']);
            $I->assertSame(10, $inventory->getReceivedQuantity(), 'received_quantity is untouched by a declined draft');
            $I->assertSame(0, $inventory->getQuarantineQuantity(), 'and nothing entered quarantine: no goods arrived');
            $I->assertSame(10, $inventory->getAvailableQuantity(), 'the shelf still holds ten sellable units');
        }
        $I->assertSame(0, $this->returnedUnits($em, $seed['producta'], $seed['warehouse']), 'no `returned` inventory_detail rows were written');
        $I->assertSame(0, $this->creditNoteCount($em, $seed['company']), 'and declining raised no credit note — the money is a separate document and stays one');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * 2. The load-bearing case: the return with no units on it
     * ------------------------------------------------------------------------------------------
     */

    /**
     * The zero-unit return: the document that previously had no exit at all.
     *
     * A line typed as 0.40 is a real thing a person submits — the editor asks for a quantity and
     * offers no minimum — and it survives the "at least one line" check because that check counts
     * LINES. `totalUnits()` rounds it to nothing, so `authorise()` refused, and with decline()
     * starting at Authorised there was nothing else to try.
     *
     * The refusal that creates the trap is asserted here as well as the escape, because the trap is
     * the whole reason the escape has to exist and a fix that loosened `authorise()` instead would
     * be authorising a parcel nobody can check.
     */
    public function theZeroUnitReturnThatUsedToHaveNoExitAtAll(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);
        $seed = $this->seed($I);

        $empty = $this->raiseReturn($I, $seed, 'a', '0.40', 'Opened by mistake.');
        $control = $this->raiseReturn($I, $seed, 'b', '3.00', 'A real return, must be untouched.');

        // --- The document really is in the trap ------------------------------------------------------
        $em->clear();
        $I->assertSame(['0.40'], $this->lineQuantities($em, $empty), 'the form really did store the sub-unit line rather than dropping it');
        $I->assertSame(0, $this->totalUnits($em, $empty), 'and the return sums to no whole units at all');
        $I->assertSame('Requested', $this->returnRow($em, $empty)['status']);

        $I->amOnPage('/admin/sales-return/' . $empty);
        $I->seeResponseCodeIsSuccessful();
        // Positive control: the detail screen rendered, so the elements asserted below are really
        // absent or present rather than the response being blank.
        $I->see('Cancellable Widget A');

        $authToken = $I->grabAttributeFrom('#authorise-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $empty . '/action/authorise', ['_token' => $authToken]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('authorises nothing');

        $em->clear();
        $stillRequested = $this->returnRow($em, $empty);
        $I->assertSame('Requested', $stillRequested['status'], 'authorise() still refuses a return with no units — that refusal is correct and is deliberately kept');
        $I->assertNull($stillRequested['authorised_at']);

        // Nor can it be received or closed: the other two transitions are untouched by this change.
        $I->amOnPage('/admin/sales-return/' . $empty);
        $recvToken = $I->grabAttributeFrom('#receive-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $empty . '/action/receive', [
            '_token' => $recvToken,
            'warehouse_id' => (string) $seed['warehouse'],
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->amOnPage('/admin/sales-return/' . $empty);
        $closeToken = $I->grabAttributeFrom('#decline-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $empty . '/action/close', ['_token' => $closeToken]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Requested', $this->returnRow($em, $empty)['status'], 'receive() and close() both still refuse it — those were never the exit and are not made into one');

        // --- The exit that now exists ------------------------------------------------------------------
        $I->amOnPage('/admin/sales-return/' . $empty);
        $I->seeElement('button[form="decline-form"]');
        $declineToken = $I->grabAttributeFrom('#decline-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $empty . '/action/decline', [
            '_token' => $declineToken,
            'reason' => 'Opened by mistake, nothing on it.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $emptyAfter = $this->returnRow($em, $empty);
        $I->assertSame('Declined', $emptyAfter['status'], 'a return with no units is disposable — decline() carries no units check, and must not gain one');
        $I->assertNull($emptyAfter['authorised_at'], 'and it got there without ever being authorised, which it could not have been anyway');
        $I->assertStringContainsString('was Requested', (string) $emptyAfter['notes']);
        $I->assertSame(['0.40'], $this->lineQuantities($em, $empty), 'its line is left exactly as it was — declining rewrites no history');

        // --- The row that must NOT have changed ----------------------------------------------------------
        $controlAfter = $this->returnRow($em, $control);
        $I->assertSame('Requested', $controlAfter['status'], 'the unrelated return is untouched by the refused authorise, the refused receive, the refused close and the decline');
        $I->assertNull($controlAfter['notes']);
        $I->assertNull($controlAfter['authorised_at']);
        $I->assertSame(['3.00'], $this->lineQuantities($em, $control), 'and still names three units');

        $I->assertSame(0, $this->creditNoteCount($em, $seed['company']), 'no credit note anywhere near this');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * 3. The half that matters as much: nothing else loosened
     * ------------------------------------------------------------------------------------------
     */

    /**
     * Every refusal that was there before is still there.
     *
     * A change that made a draft disposable by making everything disposable would pass both tests
     * above. So this drives an Authorised return and a Received one and proves:
     *
     *  - `receive()` still demands Authorised, so a Requested return cannot reach the stock ledger;
     *  - lines are frozen once authorised — the edit screen refuses and the quantity in the database
     *    is unchanged after a POST that tried to rewrite it;
     *  - Closed and Declined are still terminal and accept no further decline, in the ENTITY and not
     *    only in Twig — each forbidden POST is forced past the screen that no longer offers it.
     */
    public function nothingPastRequestedBecameEditableOrReopenable(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);
        $seed = $this->seed($I);

        $authorised = $this->raiseReturn($I, $seed, 'a', '4.00', 'Agreed with the customer.');
        $stillDraft = $this->raiseReturn($I, $seed, 'b', '2.00', 'Never agreed with anybody.');

        // --- A Requested return cannot be received: stock is still only reachable via Authorise -------
        $I->amOnPage('/admin/sales-return/' . $stillDraft);
        $I->seeResponseCodeIsSuccessful();
        // Positive control paired with the absence assertion below.
        $I->see('Cancellable Widget B');
        $I->dontSeeElement('button[form="receive-form"]');

        $draftToken = $I->grabAttributeFrom('#receive-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $stillDraft . '/action/receive', [
            '_token' => $draftToken,
            'warehouse_id' => (string) $seed['warehouse'],
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('only an authorised return can be received');

        $em->clear();
        $draftRow = $this->returnRow($em, $stillDraft);
        $I->assertSame('Requested', $draftRow['status'], 'a Requested return still cannot be received — being droppable did not make it receivable');
        $I->assertNull($draftRow['received_at']);
        $I->assertSame(
            0,
            $this->inventoryFor($em, $seed['productb'], $seed['warehouse'])->getQuarantineQuantity(),
            'and no units entered quarantine for it',
        );

        // --- Authorise the other one, then try to rewrite its lines ------------------------------------
        $this->authorise($I, $authorised);

        $em->clear();
        $I->assertSame('Authorised', $this->returnRow($em, $authorised)['status'], 'guard: it really is authorised now');
        $I->assertSame(['4.00'], $this->lineQuantities($em, $authorised), 'guard: four units before the edit is attempted');

        // The edit screen is not offered past Requested; the POST is forced anyway, because the
        // refusal has to hold in the controller and not only in the template.
        $I->amOnPage('/admin/sales-return/' . $authorised);
        $I->dontSeeElement('a[href$="/' . $authorised . '/edit"]');
        $editToken = $I->grabAttributeFrom('#decline-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $authorised . '/edit', [
            '_token' => $editToken,
            'reason' => 'Rewritten after the customer agreed.',
            'lines' => [[
                'product_id' => (string) $seed['producta'],
                'name' => 'Cancellable Widget A',
                'sku' => 'RMA-CANCEL-A',
                'quantity' => '99.00',
                'reason' => 'Rewritten',
            ]],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $authorisedRow = $this->returnRow($em, $authorised);
        $I->assertSame(['4.00'], $this->lineQuantities($em, $authorised), 'sales_return_line.quantity is untouched: an authorised return is what the customer was told they may send');
        $I->assertSame('Agreed with the customer.', $authorisedRow['reason'], 'and its header did not move either');
        $I->assertSame('Authorised', $authorisedRow['status']);

        // --- Receive it, close it, and prove Closed is still terminal -----------------------------------
        $this->receive($I, $authorised, $seed['warehouse']);

        $em->clear();
        $I->assertSame('Received', $this->returnRow($em, $authorised)['status'], 'guard: receiving still works from Authorised');

        $I->amOnPage('/admin/sales-return/' . $authorised);
        $realCloseToken = $I->grabAttributeFrom('#close-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $authorised . '/action/close', ['_token' => $realCloseToken]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $closedRow = $this->returnRow($em, $authorised);
        $I->assertSame('Closed', $closedRow['status'], 'guard: closed from Received');

        $I->amOnPage('/admin/sales-return/' . $authorised);
        $I->seeResponseCodeIsSuccessful();
        // Positive control for the absence below.
        $I->see('Cancellable Widget A');
        $I->dontSeeElement('button[form="decline-form"]');

        // Forced past the screen: the token is scraped from the OTHER return's page, so the refusal
        // being asserted is the entity's and not the CSRF subscriber's.
        $I->amOnPage('/admin/sales-return/' . $stillDraft);
        $forcedToken = $I->grabAttributeFrom('#decline-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $authorised . '/action/decline', [
            '_token' => $forcedToken,
            'reason' => 'Trying to reopen a settled matter.',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('cannot be declined');

        $em->clear();
        $stillClosed = $this->returnRow($em, $authorised);
        $I->assertSame('Closed', $stillClosed['status'], 'Closed is still terminal: declining it is refused');
        $I->assertSame($closedRow['notes'], $stillClosed['notes'], 'and nothing was appended to its notes');
        $I->assertSame($closedRow['received_at'], $stillClosed['received_at'], 'nor was its receipt disturbed');

        // --- Declined is terminal too: a second decline appends nothing ----------------------------------
        $I->amOnPage('/admin/sales-return/' . $stillDraft);
        $dropToken = $I->grabAttributeFrom('#decline-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $stillDraft . '/action/decline', [
            '_token' => $dropToken,
            'reason' => 'Dropped.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $declinedOnce = $this->returnRow($em, $stillDraft);
        $I->assertSame('Declined', $declinedOnce['status'], 'guard: the draft dropped cleanly');

        $I->amOnPage('/admin/sales-return/' . $stillDraft);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('button[form="decline-form"]');
        $I->sendFormPostRequest('/admin/sales-return/' . $stillDraft . '/action/decline', [
            '_token' => $dropToken,
            'reason' => 'Dropped twice.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $declinedTwice = $this->returnRow($em, $stillDraft);
        $I->assertSame($declinedOnce['notes'], $declinedTwice['notes'], 'Declined is terminal too: a second decline appends nothing');
        $I->assertSame($declinedOnce['status'], $declinedTwice['status']);
    }

    /*
     * ------------------------------------------------------------------------------------------
     * 4. The stock a receipt moves is exactly what it always moved
     * ------------------------------------------------------------------------------------------
     */

    /**
     * A Received return still puts its units into `returned`, and a declined draft beside it moves
     * nothing.
     *
     * The two halves are in one test on purpose: the point is not that each figure is some number,
     * it is that the receipt's figures are unchanged WHILE the new exit exists, on the same shelf,
     * in the same request sequence. `received_quantity` is asserted as well as quarantine, because
     * it is the column #596 exists to protect and a subscriber that started crediting it would
     * otherwise be invisible here.
     */
    public function aReceivedReturnStillMovesItsStockAndADeclinedDraftMovesNone(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);
        $seed = $this->seed($I);

        $received = $this->raiseReturn($I, $seed, 'a', '3.00', 'Genuinely coming back.');
        $dropped = $this->raiseReturn($I, $seed, 'b', '5.00', 'Raised by mistake.');

        // --- Before ---------------------------------------------------------------------------------
        $em->clear();
        foreach (['a', 'b'] as $tag) {
            $before = $this->inventoryFor($em, $seed['product' . $tag], $seed['warehouse']);
            $I->assertSame(10, $before->getReceivedQuantity(), 'guard: ten arrived originally');
            $I->assertSame(0, $before->getQuarantineQuantity(), 'guard: nothing is in quarantine yet');
            $I->assertSame(10, $before->getAvailableQuantity(), 'guard: all ten are sellable');
        }

        // --- The genuine return runs the whole way -----------------------------------------------------
        $this->authorise($I, $received);
        $this->receive($I, $received, $seed['warehouse']);

        $em->clear();
        $receivedRow = $this->returnRow($em, $received);
        $I->assertSame('Received', $receivedRow['status']);
        $I->assertSame($seed['warehouse'], (int) $receivedRow['warehouse_id'], 'and the receive form\'s warehouse landed on the document');

        $afterReceipt = $this->inventoryFor($em, $seed['producta'], $seed['warehouse']);
        $I->assertSame(3, $afterReceipt->getQuarantineQuantity(), 'the three that came back are held in quarantine, exactly as before this change');
        $I->assertSame(10, $afterReceipt->getReceivedQuantity(), 'and received_quantity did NOT move — the shipment never decremented it, so crediting it back would count them twice');
        $I->assertSame(7, $afterReceipt->getAvailableQuantity(), 'seven of the ten are still sellable');
        $I->assertSame(3, $this->returnedUnits($em, $seed['producta'], $seed['warehouse']), 'and the ledger rows carry status `returned`, not `quarantine` — same bucket, different provenance');

        // --- The mistaken one is dropped from Requested, and touches none of it ---------------------------
        $I->amOnPage('/admin/sales-return/' . $dropped);
        $declineToken = $I->grabAttributeFrom('#decline-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $dropped . '/action/decline', [
            '_token' => $declineToken,
            'reason' => 'Raised by mistake.',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('No goods had arrived, so no stock moved');

        $em->clear();
        $droppedRow = $this->returnRow($em, $dropped);
        $I->assertSame('Declined', $droppedRow['status']);

        $productB = $this->inventoryFor($em, $seed['productb'], $seed['warehouse']);
        $I->assertSame(0, $productB->getQuarantineQuantity(), 'the dropped draft put nothing in quarantine');
        $I->assertSame(10, $productB->getReceivedQuantity(), 'and moved received_quantity not at all');
        $I->assertSame(10, $productB->getAvailableQuantity(), 'its product\'s shelf is exactly as it was');
        $I->assertSame(0, $this->returnedUnits($em, $seed['productb'], $seed['warehouse']), 'no `returned` rows were written for it');

        // --- And the received return, the row that must NOT have changed, is still what it was -------------
        $stillReceived = $this->inventoryFor($em, $seed['producta'], $seed['warehouse']);
        $I->assertSame(3, $stillReceived->getQuarantineQuantity(), 'declining an unrelated draft did not disturb the receipt beside it');
        $I->assertSame(10, $stillReceived->getReceivedQuantity());
        $I->assertSame(7, $stillReceived->getAvailableQuantity());
        $I->assertSame('Received', $this->returnRow($em, $received)['status'], 'nor its status');

        // --- No credit note was raised by any of it ---------------------------------------------------------
        $I->assertSame(
            0,
            $this->creditNoteCount($em, $seed['company']),
            'neither receiving nor declining raises money: the credit note is a separate document with its own number, and #596 exists precisely so the goods and the money can land apart',
        );
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Driving the screens
     * ------------------------------------------------------------------------------------------
     */

    /**
     * Raise a return through the real create form and hand back its id.
     *
     * The id is taken by diffing `sales_return` before and after rather than by querying for the
     * newest row belonging to the invoice, so the helper also asserts that exactly one document was
     * created — which is the only reason the caller can trust the id it gets back.
     *
     * @param array<string, int> $seed
     */
    private function raiseReturn(FunctionalTester $I, array $seed, string $tag, string $quantity, string $reason): int
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $before = $this->returnIds($em);

        $url = '/admin/sales-return/new?invoice=' . $seed['invoice'];
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#sales-return-form input[name="_token"]', 'value');

        $I->sendFormPostRequest($url, [
            '_token' => $token,
            'reason' => $reason,
            'lines' => [[
                'product_id' => (string) $seed['product' . $tag],
                'invoice_line_id' => (string) $seed['invoiceline' . $tag],
                'name' => $tag === 'a' ? 'Cancellable Widget A' : 'Cancellable Widget B',
                'sku' => $tag === 'a' ? 'RMA-CANCEL-A' : 'RMA-CANCEL-B',
                'quantity' => $quantity,
                'reason' => 'cracked casing',
            ]],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $created = array_values(array_diff($this->returnIds($em), $before));
        $I->assertCount(1, $created, 'the create form made exactly one sales return');

        return $created[0];
    }

    private function authorise(FunctionalTester $I, int $id): void
    {
        $I->amOnPage('/admin/sales-return/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#authorise-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $id . '/action/authorise', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
    }

    private function receive(FunctionalTester $I, int $id, int $warehouseId): void
    {
        $I->amOnPage('/admin/sales-return/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#receive-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/sales-return/' . $id . '/action/receive', [
            '_token' => $token,
            'warehouse_id' => (string) $warehouseId,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Reading the database back
     * ------------------------------------------------------------------------------------------
     */

    /** @return list<int> */
    private function returnIds(EntityManagerInterface $em): array
    {
        return array_map('intval', $em->getConnection()->fetchFirstColumn('SELECT id FROM sales_return'));
    }

    /**
     * The document's own row, read from the database rather than from a held entity.
     *
     * @return array<string, mixed>
     */
    private function returnRow(EntityManagerInterface $em, int $id): array
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT status, authorised_at, received_at, warehouse_id, notes, reason FROM sales_return WHERE id = ?',
            [$id],
        );

        if ($row === false) {
            throw new \RuntimeException('No sales_return row with id ' . $id);
        }

        return $row;
    }

    /**
     * Line quantities for one return, in row order, formatted past SQLite's NUMERIC affinity.
     *
     * @return list<string>
     */
    private function lineQuantities(EntityManagerInterface $em, int $id): array
    {
        return array_map(
            static fn ($q): string => number_format((float) $q, 2, '.', ''),
            $em->getConnection()->fetchFirstColumn(
                'SELECT quantity FROM sales_return_line WHERE sales_return_id = ? ORDER BY sort_order ASC, id ASC',
                [$id],
            ),
        );
    }

    /** What the document sums to in whole units, by the same rounding the transitions use. */
    private function totalUnits(EntityManagerInterface $em, int $id): int
    {
        $units = 0;
        foreach ($this->lineQuantities($em, $id) as $quantity) {
            $units += max(0, (int) round((float) $quantity));
        }

        return $units;
    }

    /** Units sitting in `returned` for one product at one warehouse, straight off the ledger. */
    private function returnedUnits(EntityManagerInterface $em, int $productId, int $warehouseId): int
    {
        return (int) $em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND warehouse_id = ? AND status = ?',
            [$productId, $warehouseId, InventoryDetail::STATUS_RETURNED],
        );
    }

    /** How many credit notes exist for the customer. Declining must never create one. */
    private function creditNoteCount(EntityManagerInterface $em, int $companyId): int
    {
        return (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM credit_memo WHERE company_id = ?',
            [$companyId],
        );
    }

    private function inventoryFor(EntityManagerInterface $em, int $productId, int $warehouseId): ProductInventory
    {
        $inventory = $em->getRepository(ProductInventory::class)->findOneBy(['product' => $productId, 'warehouse' => $warehouseId]);
        if (!$inventory instanceof ProductInventory) {
            throw new \RuntimeException('No product_inventory row for that product/warehouse pair.');
        }

        return $inventory;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Fixtures
     * ------------------------------------------------------------------------------------------
     */

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('sr-cancel-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A customer, two dimensional products with ten units of each on the shelf, and one issued
     * invoice naming both — the delivery these returns are raised against.
     *
     * Two products rather than one so the control return in every test names a DIFFERENT shelf from
     * the return under test: a stock assertion on the control is then a real assertion and not the
     * same row read twice.
     *
     * @return array<string, int> warehouse, company, invoice, producta, productb, invoicelinea, invoicelineb
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $movements = $I->grabService(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('RMA Cancel Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $company = (new Company())
            ->setName('Cancellable Buyer Ltd')
            ->setCode('RMAC-' . uniqid())
            ->setPrimaryEmail('buyer@rma-cancel.example');
        $em->persist($company);

        $products = [];
        foreach ([['a', 'A'], ['b', 'B']] as [$tag, $letter]) {
            $product = (new ProductCore())
                ->setSku('RMAC-' . $letter . '-' . random_int(1000, 9999))
                ->setName('Cancellable Widget ' . $letter)
                ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
                ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
            $em->persist($product);
            $products[$tag] = $product;
        }
        $em->flush();

        foreach ($products as $tag => $product) {
            $movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'rmac-seed-' . $tag . '-' . uniqid())
                    ->receive($product, new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10),
            );
        }
        $em->flush();

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('RMACSO-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setSubtotal('500.00')
            ->setTax('0.00')
            ->setTotal('500.00');
        foreach ($products as $product) {
            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setQuantity('10.00')
                    ->setPrice('25.00')
                    ->setSubtotal('250.00'),
            );
        }
        $em->persist($order);
        $em->flush();
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('RMACINV-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setSubtotal('500.00')
            ->setTax('0.00')
            ->setTotal('500.00');
        $order->addInvoice($invoice);

        $invoiceLines = [];
        foreach ($order->getLines() as $orderLine) {
            $product = $orderLine->getProduct();
            $line = (new InvoiceLine())
                ->setSalesOrderLine($orderLine)
                ->setProduct($product)
                ->setName($orderLine->getName())
                ->setSku((string) $product?->getSku())
                ->setQuantity('10.00')
                ->setPrice('25.00')
                ->setSubtotal('250.00');
            $invoice->addLine($line);
            $invoiceLines[] = $line;
        }
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return [
            'warehouse' => (int) $warehouse->getId(),
            'company' => (int) $company->getId(),
            'invoice' => (int) $invoice->getId(),
            'producta' => (int) $products['a']->getId(),
            'productb' => (int) $products['b']->getId(),
            'invoicelinea' => (int) $invoiceLines[0]->getId(),
            'invoicelineb' => (int) $invoiceLines[1]->getId(),
        ];
    }
}
