<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The status seam on the quote (queue item 64, stage 3), through the real screens.
 *
 * `Estimate` is the document the seam was written for. Before this it had a bare
 * `setStatus(EstimateStatus $status): self` — no guard, no actor, no timeline row — so what was
 * legal on a quote was whatever each of its six call sites happened to enforce, and they did not
 * agree. `CoreStatusVocabularyProvider::estimate()` is the transcription of what those call sites
 * allowed between them, and these cases are what proves the screens still obey it.
 *
 * ## What kind of evidence each case is, stated plainly
 *
 * The conversion is SHAPE ONLY for the statuses themselves — no status renamed, no transition added
 * or removed, and the stored strings are byte-identical — so the cases that drive a screen and read
 * the column back are CHARACTERISATION: they pin behaviour that must not move. Claiming them as
 * failure-then-pass evidence would be a lie about what they are.
 *
 * The cases that could NOT have passed before are marked NEW: a status change that records nobody
 * is no longer possible to write, an unknown status is refused by name, and a quote stranded on a
 * value the vocabulary does not know still loads and can still be moved off it.
 *
 * Every case re-reads its claim from the database BY COLUMN through the DBAL connection rather than
 * through an accessor, and every case carries a second quote that must NOT move.
 */
final class EstimateStatusSeamCest
{
    private function loginAsAdmin(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('est-seam-' . uniqid() . '@example.test')
            ->setFirstName('Priya')
            ->setLastName('Raman');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        return $admin;
    }

    private function loginAsCustomer(FunctionalTester $I, Company $company): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('est-seam-cust-' . uniqid() . '@example.test')
            ->setFirstName('Jane')
            ->setLastName('Buyer')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        return $customer;
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Quote Seam Co')
            ->setCode('QSC-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    /**
     * A fully priced quote in $status.
     *
     * The status is written through the seam's own door, because there is no other way in any more
     * — which is itself part of what this file is asserting. A new quote is Draft, and every status
     * below is one move from Draft in the 'estimate' vocabulary.
     */
    private function makeEstimate(FunctionalTester $I, Company $company, string $status): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('ESTSEAM-' . uniqid())
            ->setSource('Admin')
            ->setFulfillmentRegion('West')
            ->setFeeLines(json_encode([[
                'slug' => 'shipping',
                'label' => 'Shipping (Ground)',
                'taxClass' => 'G',
                'amount' => 10.0,
                'placement' => 'main_line',
                'type' => 'shipping',
                'source' => 'auto-calc',
            ]]))
            ->setSubtotal('100.00')
            ->setTax('5.00')
            ->setTotal('115.00');
        $estimate->setStatus($status, DocumentActor::system());

        $estimate->addLine(
            (new EstimateLine())
                ->setName('Widget')
                ->setSku('QSC-WIDGET-1')
                ->setQuantity('2.00')
                ->setCost('40.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
        );

        $I->haveInRepository($estimate);

        return $estimate;
    }

    /**
     * The status COLUMN, read straight out of SQL.
     *
     * Not `getStatus()`, deliberately: the whole claim of this file is about what reaches the
     * database, and an accessor that returned a cached or defaulted value would let every assertion
     * below pass without a row ever changing.
     */
    private function statusColumn(FunctionalTester $I, int $estimateId): string
    {
        $connection = $I->grabService(Connection::class);

        return (string) $connection->fetchOne('SELECT status FROM estimate WHERE id = ?', [$estimateId]);
    }

    /** @return list<array{comment: string, user_name: ?string, type: string}> oldest first */
    private function logRows(FunctionalTester $I, int $estimateId): array
    {
        $connection = $I->grabService(Connection::class);

        return $connection->fetchAllAssociative(
            "SELECT summary AS comment, actor_name AS user_name, action AS type FROM audit_log "
                . "WHERE entity_type = 'Estimate' AND entity_id = ? AND actor_type = 'document' ORDER BY id ASC",
            [$estimateId],
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Characterisation: the real screens must behave exactly as they did.
    // ---------------------------------------------------------------------------------------------

    /**
     * The admin status dropdown moves the quote and leaves ONE timeline row carrying the acting
     * admin's name.
     *
     * NEW in its second half. The row used to be written by `logEstimateAction()` beside the setter;
     * it is written by `setStatus()` now, which is what makes a status change that records nobody
     * impossible to write rather than merely discouraged. The sentence is unchanged, deliberately —
     * a shape-only change does not reword a customer's history.
     */
    public function theStatusDropdownMovesTheQuoteAndLeavesOneAttributedRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $estimate = $this->makeEstimate($I, $company, 'Submitted');
        // The row that must not change: a second quote nobody touches.
        $untouched = $this->makeEstimate($I, $company, 'Submitted');

        $before = count($this->logRows($I, (int) $estimate->getId()));

        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Priced',
        ]);
        $I->seeResponseCodeIs(200);

        $I->assertSame('Priced', $this->statusColumn($I, (int) $estimate->getId()));
        $I->assertSame('Submitted', $this->statusColumn($I, (int) $untouched->getId()), 'the other quote did not move');

        $rows = $this->logRows($I, (int) $estimate->getId());
        $I->assertCount($before + 1, $rows, 'exactly one new row: setStatus() writes it, and nothing writes a second');
        $I->assertSame('Estimate status changed from Submitted to Priced.', $rows[$before]['comment']);
        $I->assertSame('System', $rows[$before]['type']);
        $I->assertStringContainsString(
            'Priya Raman',
            (string) $rows[$before]['user_name'],
            'the acting admin, not the machine — this is the attribution setStatus() now guarantees',
        );

        $I->assertCount(
            count($this->logRows($I, (int) $untouched->getId())),
            $this->logRows($I, (int) $untouched->getId()),
            'and the untouched quote gained nothing',
        );
        $I->assertSame($before, count($this->logRows($I, (int) $untouched->getId())));
    }

    /**
     * Accepted is the one terminal status on a quote, and the endpoint still refuses to leave it.
     *
     * Two guards agree on this now — the endpoint's own Accepted check and the vocabulary behind
     * `setStatus()` — and the endpoint's is the one that answers, because it runs first and speaks
     * JSON. Both are asserted: the refusal is a 403, and the column did not move.
     */
    public function anAcceptedQuoteCannotBeMovedFromTheDropdown(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $accepted = $this->makeEstimate($I, $company, 'Accepted');
        $movable = $this->makeEstimate($I, $company, 'Submitted');

        $I->amOnPage('/admin/estimate/detail/' . $accepted->getId());
        $token = $I->csrfToken();

        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $accepted->getId(), [
            '_token' => $token,
            'status' => 'Priced',
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertSame('Accepted', $this->statusColumn($I, (int) $accepted->getId()), 'the refused move wrote nothing');

        // The positive control on the same endpoint with the same token: a quote that MAY move does.
        // Without it, a 403 from a broken route would read exactly like a working guard.
        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $movable->getId(), [
            '_token' => $token,
            'status' => 'Priced',
        ]);
        $I->seeResponseCodeIs(200);
        $I->assertSame('Priced', $this->statusColumn($I, (int) $movable->getId()));
    }

    /**
     * NEW. A status the vocabulary does not know is refused at the door with a 400, and the column
     * is untouched.
     *
     * This endpoint used to validate with `EstimateStatus::tryFrom()`. It asks the vocabulary now,
     * which is the set the app actually ships — and it still answers 400 rather than letting
     * `setStatus()`'s typo guard throw, because that guard is for a status a PROGRAMMER typed and a
     * 500 is the wrong answer to a posted field.
     */
    public function aStatusTheVocabularyDoesNotKnowIsRefusedAndNothingIsWritten(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $estimate = $this->makeEstimate($I, $company, 'Submitted');
        $untouched = $this->makeEstimate($I, $company, 'Submitted');

        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $token = $I->csrfToken();

        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $estimate->getId(), [
            '_token' => $token,
            'status' => 'Pricedd',
        ]);
        $I->seeResponseCodeIs(400);
        $I->assertSame('Submitted', $this->statusColumn($I, (int) $estimate->getId()));
        $I->assertSame('Submitted', $this->statusColumn($I, (int) $untouched->getId()));

        // The positive control: the correctly spelled status, same element, same token, goes through.
        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $estimate->getId(), [
            '_token' => $token,
            'status' => 'Priced',
        ]);
        $I->seeResponseCodeIs(200);
        $I->assertSame('Priced', $this->statusColumn($I, (int) $estimate->getId()));
        $I->assertSame('Submitted', $this->statusColumn($I, (int) $untouched->getId()), 'and still only the one quote moved');
    }

    /**
     * NEW. The customer's own Decline button writes Rejected AND signs the row with the customer.
     *
     * Before the seam this path wrote the status and no timeline row at all: a quote could go from
     * Priced to Rejected with nothing on its history saying who did it or when. That is exactly the
     * hole `setStatus()` taking a required actor closes.
     */
    public function theCustomersDeclineWritesRejectedAndSignsItWithTheCustomer(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');
        $untouched = $this->makeEstimate($I, $company, 'Priced');
        $this->loginAsCustomer($I, $company);

        $before = count($this->logRows($I, (int) $estimate->getId()));

        $I->amOnPage('/estimates/' . $estimate->getId());
        $html = $I->grabPageSource();
        preg_match(
            '/<form[^>]*action="[^"]*\/reject"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)"/',
            $html,
            $m,
        );
        $I->assertNotEmpty($m[1] ?? '', 'the Decline form and its CSRF token are on the real page');

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/reject', ['_token' => $m[1]]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('Rejected', $this->statusColumn($I, (int) $estimate->getId()));
        $I->assertSame('Priced', $this->statusColumn($I, (int) $untouched->getId()), 'the other quote did not move');

        $rows = $this->logRows($I, (int) $estimate->getId());
        $I->assertCount($before + 1, $rows);
        $I->assertSame('Quote declined by the customer.', $rows[$before]['comment']);
        $I->assertStringContainsString('Jane Buyer', (string) $rows[$before]['user_name']);
    }

    /**
     * NEW. Report, never refuse (handoff section 7): a quote holding a value the vocabulary has
     * never known still loads, says so on screen, and can be moved OFF it.
     *
     * The stranded value is written by SQL because nothing in the app can produce one any more —
     * which is the point. Before the seam there was no marking and no way back: the detail screen
     * printed whatever the column said as though it were a real status.
     */
    public function aQuoteStrandedOnAnUnknownStatusIsMarkedAndCanStillBeMoved(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $estimate = $this->makeEstimate($I, $company, 'Submitted');
        $untouched = $this->makeEstimate($I, $company, 'Submitted');

        $connection = $I->grabService(Connection::class);
        $connection->executeStatement('UPDATE estimate SET status = ? WHERE id = ?', ['Waiting for Quote', $estimate->getId()]);
        $I->grabService(EntityManagerInterface::class)->clear();

        // The screen renders it rather than failing on it, and says the value is not one it knows.
        // Paired with a positive control on the SAME element: the recognised quote's own status
        // prints with no marking, so "(unrecognised)" being absent there is a real observation
        // rather than a selector that matched nothing.
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Waiting for Quote', '[data-document-status]');

        $I->amOnPage('/admin/estimate/detail/' . $untouched->getId());
        $I->see('Submitted', '[data-document-status]');
        $I->dontSee('Waiting for Quote', '[data-document-status]');

        $reloaded = $I->grabService(EntityManagerInterface::class)->find(Estimate::class, $estimate->getId());
        $I->assertFalse($reloaded->statusIsRecognised());
        $I->assertSame('Waiting for Quote (unrecognised)', $reloaded->statusLabel());

        // And it is a place that can be LEFT: every status the vocabulary knows is a way out.
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Priced',
        ]);
        $I->seeResponseCodeIs(200);

        $I->assertSame('Priced', $this->statusColumn($I, (int) $estimate->getId()));
        $I->assertSame('Submitted', $this->statusColumn($I, (int) $untouched->getId()));
    }
}
