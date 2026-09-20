<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\AuditLog;
use App\Entity\ProductCore;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Optimistic concurrency on the QUOTE edit page — the same protection #417 gave the order.
 *
 * `SalesOrder` and `Invoice` have carried `#[ORM\Version]` since their tables were built; Estimate
 * was the outlier, with neither the column nor a check, while its edit screen is a full line-item
 * editor. Two admins with that page open meant the second save silently overwrote the first: no
 * refusal, no log entry, and nothing on either screen to say a colleague's work had just been
 * discarded.
 *
 * Conducted per docs/QUEUE.md #624 — real screens, plain form POSTs with the CSRF token scraped off
 * the rendered page, fixtures created here, and every assertion made by reading the estimate's own
 * COLUMNS back out of the database after the POST rather than trusting a flash, a status code or a
 * returned entity.
 *
 * Per #627 nothing here is asserted with a bare `see()` of a number: the version is read off the
 * hidden input by element, and every figure is compared with assertSame against a column.
 *
 * Nothing is kept on `$this` — Codeception reuses one Cest instance for every method in the file,
 * so each case builds its own company, product and quote and holds them in locals.
 */
final class AdminEstimateEditVersionLockCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('estimate-version-lock@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Quote Version Lock Co')
            ->setCode('QVL-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('QVL-SKU-' . uniqid())
            ->setName('Quote Version Lock Widget')
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
     * A quote in an editable state, with one priced line.
     *
     * Submitted rather than Draft or Priced: Draft and Priced both get moved around by edit()'s own
     * status rules (a fully priced Submitted quote becomes Priced on save), and a case about the
     * version column should not also be a case about the status ladder.
     */
    private function makeEstimate(FunctionalTester $I, Company $company, ProductCore $product, string $poNumber): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QVL-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->setPoNumber($poNumber);
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku((string) $product->getSku())
                ->setQuantity('2.00')
                ->setCost('30.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
                ->setTaxCode('E')
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function reload(FunctionalTester $I, int $estimateId): Estimate
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Estimate::class, $estimateId);
    }

    /**
     * The estimate's own columns, read straight off the table rather than through the entity.
     *
     * A version column is exactly the kind of thing an ORM will happily report from an identity map
     * that never went to the database, so the concurrency cases below check the row itself.
     *
     * @return array{po_number: ?string, version: int, status: string}
     */
    private function estimateRow(FunctionalTester $I, int $estimateId): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $row = $entityManager->getConnection()->fetchAssociative(
            'SELECT po_number, version, status FROM estimate WHERE id = ?',
            [$estimateId],
        );

        return [
            'po_number' => $row['po_number'],
            'version' => (int) $row['version'],
            'status' => (string) $row['status'],
        ];
    }

    /** @return list<AuditLog> newest first */
    private function estimateLogs(FunctionalTester $I, int $estimateId): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(AuditLog::class)->findBy(
            ['entityType' => 'Estimate', 'entityId' => $estimateId, 'actorType' => 'document'],
            ['id' => 'DESC'],
        );
    }

    /**
     * The line field set a minimal quote save needs, as the edit form posts it.
     *
     * @return array<string, mixed>
     */
    private function linePost(Estimate $estimate, ProductCore $product): array
    {
        $line = $estimate->getLines()->first();

        return [
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'name' => '', 'sku' => '', 'qty' => '2', 'price' => '50.00'],
            ],
        ];
    }

    // ----------------------------------------------------------------- mapping / round-trip

    /**
     * The hidden field is what round-trips the version — with no field there is nothing for the
     * controller to compare and no protection at all, which is precisely the state the quote form
     * was in.
     */
    public function theQuoteEditPageRendersItsCurrentVersionAsAHiddenField(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product, 'ORIGINAL-PO');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // Anchored to the element, never a bare see() of the number (#627): '1' would match any
        // figure on this page that happens to contain it.
        $I->seeElement('input.js-estimate-version', [
            'name' => 'version',
            'value' => '1',
        ]);

        // And the value it renders is the column's, not a constant: move the row and the field
        // follows it. This is the positive control for the assertion above.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $live = $entityManager->find(Estimate::class, $estimate->getId());
        $live->setPoNumber('BUMPED-BY-ANOTHER-SAVE');
        $entityManager->flush();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('input.js-estimate-version', ['value' => '2']);
        $I->dontSeeElement('input.js-estimate-version', ['value' => '1']);
    }

    // -------------------------------------------------------------------------- the rejection

    /**
     * The core case: admin B's form was rendered at version 1. Before B submits, admin A's save
     * lands and moves the quote to version 2. B's submit — still carrying version 1 — must be
     * refused outright: nothing B typed reaches the table, the quote keeps A's values, and B is
     * told rather than left believing the save worked.
     *
     * The second quote in this test is the row that must NOT change (#624's cheap half): a refusal
     * that reached past the document it was refusing would show up here and nowhere else.
     */
    public function aStaleQuoteSubmitIsRefusedAndDoesNotOverwriteTheNewerSave(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product, 'ORIGINAL-PO');
        $estimateId = (int) $estimate->getId();

        // The bystander: same company, same product, untouched by anything below.
        $bystander = $this->makeEstimate($I, $company, $product, 'BYSTANDER-PO');
        $bystanderId = (int) $bystander->getId();
        $bystanderBefore = $this->estimateRow($I, $bystanderId);

        // Admin B loads the edit page — this is what puts version=1 in B's hidden field.
        $I->amOnPage('/admin/estimate/edit/' . $estimateId);
        $I->seeElement('input.js-estimate-version', ['value' => '1']);
        $token = $I->csrfToken();

        // Admin A's save lands first, in between B's page load and B's submit.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $winner = $entityManager->find(Estimate::class, $estimateId);
        $winner->setPoNumber('SET-BY-ADMIN-A');
        $entityManager->flush();
        $I->assertSame(2, $this->estimateRow($I, $estimateId)['version'], 'admin A\'s save should have moved the version to 2');

        // Admin B submits, unaware — still version 1, still holding the pre-A field values.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimateId, array_merge(
            [
                '_token' => $token,
                'version' => '1',
                'po_number' => 'SET-BY-ADMIN-B-TOO-LATE',
                'action' => 'save',
            ],
            $this->linePost($estimate, $product),
        ));

        // The columns FIRST, because they are the actual claim. Asserting the flash before them
        // would let a version of this test pass its way to the interesting part on the strength of
        // a message, and fail there for a reason that reads like a wording problem rather than the
        // silent overwrite it would actually be.
        $saved = $this->estimateRow($I, $estimateId);
        $I->assertSame('SET-BY-ADMIN-A', $saved['po_number'], 'B\'s stale edit must not have overwritten A\'s already-committed save');
        $I->assertSame(2, $saved['version'], 'a refused submit must not itself move the version');

        // The row that must not have changed.
        $I->assertSame($bystanderBefore, $this->estimateRow($I, $bystanderId), 'the refusal must not have touched another quote');

        // Sent back to a fresh copy of the edit page rather than silently through a successful save,
        // and told why.
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimateId);
        $I->see('changed by someone else');

        $logs = $this->estimateLogs($I, $estimateId);
        $rejection = current(array_filter($logs, static fn (AuditLog $log): bool => str_contains($log->getSummary(), 'Save rejected')));
        $I->assertNotFalse($rejection, 'a refused stale submit should leave its own log entry, not silence');
        $I->assertStringContainsString('version 1', $rejection->getSummary(), 'the log should say which submitted version was stale');
    }

    /**
     * The positive control on the SAME element and the same path: a submit whose version matches is
     * not caught by any of this. It saves exactly as it always did and the column moves by one.
     */
    public function aCurrentQuoteSubmitStillSavesAndTheVersionMovesByOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product, 'ORIGINAL-PO');
        $estimateId = (int) $estimate->getId();

        $bystander = $this->makeEstimate($I, $company, $product, 'BYSTANDER-PO');
        $bystanderId = (int) $bystander->getId();
        $bystanderBefore = $this->estimateRow($I, $bystanderId);

        $I->amOnPage('/admin/estimate/edit/' . $estimateId);
        $I->seeElement('input.js-estimate-version', ['value' => '1']);
        $token = $I->csrfToken();

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimateId, array_merge(
            [
                '_token' => $token,
                'version' => '1',
                'po_number' => 'SET-BY-A-CURRENT-SAVE',
                'action' => 'save',
            ],
            $this->linePost($estimate, $product),
        ));

        $I->dontSee('changed by someone else');

        $saved = $this->estimateRow($I, $estimateId);
        $I->assertSame('SET-BY-A-CURRENT-SAVE', $saved['po_number'], 'a current submit should still apply normally');
        $I->assertSame(2, $saved['version'], 'a successful save should move the version forward by exactly one');

        $I->assertSame($bystanderBefore, $this->estimateRow($I, $bystanderId), 'a normal save must not have touched another quote');

        // And the refusal did not fire on this path — the absence, paired with its presence above.
        $logs = $this->estimateLogs($I, $estimateId);
        $rejection = current(array_filter($logs, static fn (AuditLog $log): bool => str_contains($log->getSummary(), 'Save rejected')));
        $I->assertFalse($rejection, 'a current submit must not be logged as a rejection');
    }

    /**
     * A post with no `version` field at all has nothing to compare against and is left alone —
     * the same allowance OrderController makes, and the reason every quote Cest that predates this
     * column still posts and saves unchanged.
     */
    public function aQuoteSubmitWithNoVersionFieldIsNotRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product, 'ORIGINAL-PO');
        $estimateId = (int) $estimate->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimateId);
        $token = $I->csrfToken();

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimateId, array_merge(
            [
                '_token' => $token,
                'po_number' => 'SET-WITH-NO-VERSION-FIELD',
                'action' => 'save',
            ],
            $this->linePost($estimate, $product),
        ));

        $saved = $this->estimateRow($I, $estimateId);
        $I->assertSame('SET-WITH-NO-VERSION-FIELD', $saved['po_number']);
        $I->assertSame(2, $saved['version'], 'the save still happened, so the column still moved');
    }
}
