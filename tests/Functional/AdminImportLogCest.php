<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ImportRun;
use App\Entity\ImportRunRow;
use App\Enum\ImportAction;
use App\Enum\ImportRowStatus;
use App\Enum\ImportRunStatus;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers Admin\ImportLogController: the role gate, every column/filter/badge on the list page, the
 * queue status panel's FREE state, and the detail page's summary + per-row audit table — see
 * docs/plans/2026-09-18-unified-import-framework.md.
 *
 * The kill button, the flock, and the "Process Queue" button actually draining a run are
 * deliberately NOT covered here — see tests/Command/ImportProcessCommandTest.php's own docblock for
 * why: this suite wraps every test in a transaction rolled back after, so a row written here is
 * invisible to the genuinely separate OS process a real `import:process` spawn is. Those three are
 * exactly the adversarial cases that need a real second process, so they live in a PHPUnit test
 * built on DoctrineIntegrationTestCase instead, which persists for real.
 */
final class AdminImportLogCest
{
    private function loginAsAdmin(FunctionalTester $I, array $roles = []): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('import-log-functional-test@example.test')
            ->setRoles($roles);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');

        return $admin;
    }

    /** @param list<array{sku:string, action:ImportAction, status:ImportRowStatus, error:?string}> $rows */
    private function haveImportRun(
        FunctionalTester $I,
        string $importType,
        string $description,
        ImportRunStatus $status,
        bool $validationOnly,
        array $rows,
        ?string $action = null,
    ): ImportRun {
        $run = new ImportRun($importType, 'TestFixtureEntity');
        $run->setDescription($description);
        $run->setValidationOnly($validationOnly);
        $run->setStatus($status);
        $run->setAction($action);
        $run->setSource('ui');
        if (!$validationOnly) {
            $run->setQueuedAt(new \DateTimeImmutable());
        }
        if ($status !== ImportRunStatus::Queued) {
            $run->setStartedAt(new \DateTimeImmutable());
            $run->setFinishedAt(new \DateTimeImmutable());
        }

        $rowNumber = 1;
        $validated = 0;
        $errors = 0;
        $executed = 0;
        foreach ($rows as $rowSpec) {
            $mapped = ['sku' => $rowSpec['sku']];
            $row = new ImportRunRow($run, $rowNumber, $mapped, $mapped);
            $row->setAction($rowSpec['action']);
            $row->setStatus($rowSpec['status']);
            $row->setError($rowSpec['error']);
            $run->addRow($row);
            ++$rowNumber;

            if ($rowSpec['status'] !== ImportRowStatus::Failed) {
                ++$validated;
            } else {
                ++$errors;
            }
            if ($rowSpec['status'] === ImportRowStatus::Succeeded) {
                ++$executed;
            }
        }
        $run->setRowCount(\count($rows));
        $run->setValidatedCount($validated);
        $run->setErrorCount($errors);
        $run->setExecutedCount($executed);

        $I->haveInRepository($run);

        return $run;
    }

    /**
     * List/detail are open to any admin — an ordinary admin lands on the detail page right after a
     * routine product/vendor import, the same way they always landed on that import's own progress
     * page before this framework existed. See ImportLogController's own docblock.
     */
    public function listAndDetailAreOpenToAPlainAdmin(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports');
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/imports/999999');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/imports');
    }

    public function killAndProcessQueueActionsAreBlockedForAPlainAdmin(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $I->csrfToken();
        $I->sendFormPostRequest('/admin/imports/kill', ['_token' => $token]);
        $I->seeResponseCodeIs(403);

        $I->sendFormPostRequest('/admin/imports/process-queue', ['_token' => $token]);
        $I->seeResponseCodeIs(403);
    }

    public function listPageRendersEveryColumnForAnExecutedRun(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $this->haveImportRun(
            $I,
            'vendor_sheet_col_test',
            'Vendor: Acme Column Test',
            ImportRunStatus::Completed,
            validationOnly: false,
            rows: [
                ['sku' => 'COL-OK-1', 'action' => ImportAction::Append, 'status' => ImportRowStatus::Succeeded, 'error' => null],
                ['sku' => 'COL-OK-2', 'action' => ImportAction::Update, 'status' => ImportRowStatus::Succeeded, 'error' => null],
                ['sku' => 'COL-BAD', 'action' => null, 'status' => ImportRowStatus::Failed, 'error' => 'sku required'],
            ],
            action: 'append,update',
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Imports');

        // Import Name
        $I->see('vendor_sheet_col_test');
        // Description
        $I->see('Vendor: Acme Column Test');
        // Action (comma-joined)
        $I->see('append,update');
        // Mode
        $I->see('✓ Executed');
        // Total / Validated / Errors
        $I->see('3');
        $I->see('⚠ 1');
        // Status
        $I->see('completed');
    }

    public function listPageRendersValidationOnlyModeBadge(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $this->haveImportRun(
            $I,
            'product_validation_test',
            'Source: QuickBooks Validation Test',
            ImportRunStatus::Completed,
            validationOnly: true,
            rows: [
                ['sku' => 'VAL-1', 'action' => null, 'status' => ImportRowStatus::Validated, 'error' => null],
            ],
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports');
        $I->seeResponseCodeIsSuccessful();
        $I->see('⚪ Validation');
        $I->see('product_validation_test');
    }

    public function queueStatusPanelShowsFreeWithZeroQueuedWhenNothingIsQueued(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports');
        $I->seeResponseCodeIsSuccessful();
        $I->see('FREE');
        $I->see('0 imports queued');
        // No queued runs, so the Process Queue button must not render at all.
        $I->dontSee('Process Queue');
    }

    public function queueStatusPanelShowsQueuedCountAndProcessQueueButtonWhenSomethingIsQueued(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $this->haveImportRun(
            $I,
            'queued_panel_test',
            'Queued panel test',
            ImportRunStatus::Queued,
            validationOnly: false,
            rows: [
                ['sku' => 'Q-1', 'action' => null, 'status' => ImportRowStatus::Validated, 'error' => null],
            ],
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports');
        $I->seeResponseCodeIsSuccessful();
        $I->see('FREE');
        $I->see('1 import queued');
        $I->seeElement('form[action="/admin/imports/process-queue"]');
        $I->see('Process Queue');
    }

    public function filterByImportTypeShowsOnlyMatchingRuns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $this->haveImportRun($I, 'filter_type_match', 'Matching type run', ImportRunStatus::Completed, false, [
            ['sku' => 'FT-1', 'action' => ImportAction::Append, 'status' => ImportRowStatus::Succeeded, 'error' => null],
        ]);
        $this->haveImportRun($I, 'filter_type_other', 'Other type run', ImportRunStatus::Completed, false, [
            ['sku' => 'FT-2', 'action' => ImportAction::Append, 'status' => ImportRowStatus::Succeeded, 'error' => null],
        ]);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports?filters[importType]=filter_type_match');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Matching type run');
        $I->dontSee('Other type run');
    }

    public function filterByStatusShowsOnlyMatchingRuns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $this->haveImportRun($I, 'filter_status_completed', 'Completed status run', ImportRunStatus::Completed, false, [
            ['sku' => 'FS-1', 'action' => ImportAction::Append, 'status' => ImportRowStatus::Succeeded, 'error' => null],
        ]);
        $this->haveImportRun($I, 'filter_status_queued', 'Queued status run', ImportRunStatus::Queued, false, [
            ['sku' => 'FS-2', 'action' => null, 'status' => ImportRowStatus::Validated, 'error' => null],
        ]);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports?filters[status]=completed');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Completed status run');
        $I->dontSee('Queued status run');
    }

    public function filterByModeShowsOnlyMatchingRuns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $this->haveImportRun($I, 'filter_mode_executed', 'Executed mode run', ImportRunStatus::Completed, false, [
            ['sku' => 'FM-1', 'action' => ImportAction::Append, 'status' => ImportRowStatus::Succeeded, 'error' => null],
        ]);
        $this->haveImportRun($I, 'filter_mode_validation', 'Validation mode run', ImportRunStatus::Completed, true, [
            ['sku' => 'FM-2', 'action' => null, 'status' => ImportRowStatus::Validated, 'error' => null],
        ]);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports?filters[mode]=executed');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Executed mode run');
        $I->dontSee('Validation mode run');

        $I->amOnPage('/admin/imports?filters[mode]=validation');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Validation mode run');
        $I->dontSee('Executed mode run');
    }

    public function filterByHasErrorsShowsOnlyRunsWithErrors(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $this->haveImportRun($I, 'filter_errors_clean', 'Clean run no errors', ImportRunStatus::Completed, false, [
            ['sku' => 'FE-1', 'action' => ImportAction::Append, 'status' => ImportRowStatus::Succeeded, 'error' => null],
        ]);
        $this->haveImportRun($I, 'filter_errors_dirty', 'Dirty run with errors', ImportRunStatus::Completed, false, [
            ['sku' => 'FE-2', 'action' => null, 'status' => ImportRowStatus::Failed, 'error' => 'boom'],
        ]);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports?filters[hasErrors]=1');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Dirty run with errors');
        $I->dontSee('Clean run no errors');
    }

    public function detailPageShowsRunSummaryAndPerRowAuditTable(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $run = $this->haveImportRun(
            $I,
            'detail_page_test',
            'Detail page description',
            ImportRunStatus::Completed,
            validationOnly: false,
            rows: [
                ['sku' => 'DETAIL-OK', 'action' => ImportAction::Append, 'status' => ImportRowStatus::Succeeded, 'error' => null],
                ['sku' => 'DETAIL-BAD', 'action' => null, 'status' => ImportRowStatus::Failed, 'error' => 'sku required'],
            ],
            action: 'append',
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports/' . $run->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Import #' . $run->getId() . ': detail_page_test');
        $I->see('Detail page description');
        $I->see('completed');
        $I->see('✓ Executed');
        $I->see('append');
        $I->see('TestFixtureEntity');

        // Per-row audit table: row number, mapped data, action, status, error.
        $I->see('DETAIL-OK');
        $I->see('DETAIL-BAD');
        $I->see('succeeded');
        $I->see('failed');
        $I->see('sku required');

        // The list page's View link points straight here.
        $I->amOnPage('/admin/imports');
        $I->seeElement('a[href="/admin/imports/' . $run->getId() . '"]');
    }

    public function detailPageRedirectsWithFlashWhenRunNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports/999999');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/imports');
        $I->see('Import run could not be found.');
    }

    public function emptyListStateRendersWhenNoImportsExist(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/imports');
        $I->seeResponseCodeIsSuccessful();
        $I->see('No imports yet.');
    }
}
