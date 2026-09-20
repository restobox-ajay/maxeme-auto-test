<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\Import\ImportRowExecutorInterface;
use App\Enum\ImportAction;

/**
 * `sleep_seconds` (when present and numeric) blocks before returning — the deliberate hook
 * AdminImportLogCest uses to catch a real `import:process` child mid-row (status='started', a real
 * PID holding the real flock) long enough to exercise the admin kill button against it.
 */
final class TestImportRowExecutor implements ImportRowExecutorInterface
{
    public function execute(array $mappedData): ImportAction
    {
        $sleep = $mappedData['sleep_seconds'] ?? null;
        if (is_numeric($sleep)) {
            usleep((int) ((float) $sleep * 1_000_000));
        }

        if (($mappedData['sku'] ?? null) === 'FIXTURE-FAIL') {
            throw new \RuntimeException('Simulated fixture executor failure.');
        }

        return ImportAction::Append;
    }
}
