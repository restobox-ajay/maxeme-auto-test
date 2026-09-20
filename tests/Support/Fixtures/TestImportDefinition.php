<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\Import\ImportDefinitionInterface;
use App\Enum\ImportAction;
use App\Model\ImportTargetField;

/**
 * Registered as `app.import_runner_factory` (via TestImportRunnerFactory) only in the test
 * environment (see `when@test` in config/services.yaml) — no real import (VendorSheet, etc.) is
 * wired to the unified import framework yet, so this is what AdminImportLogCest drives end-to-end
 * against real queued/started/killed ImportRun rows and a real import:process invocation.
 */
final class TestImportDefinition implements ImportDefinitionInterface
{
    public const NAME = 'test_fixture_import';

    public function name(): string { return self::NAME; }
    public function label(): string { return 'Test Fixture Import'; }
    public function entityType(): string { return 'TestFixtureEntity'; }

    /** @return list<ImportTargetField> */
    public function targetFields(): array
    {
        return [
            new ImportTargetField('sku', 'SKU'),
            new ImportTargetField('sleep_seconds', 'Sleep Seconds', required: false),
        ];
    }

    /** @return list<ImportAction> */
    public function supportedActions(): array { return [ImportAction::Append]; }
    public function supportsValidationOnly(): bool { return true; }
}
