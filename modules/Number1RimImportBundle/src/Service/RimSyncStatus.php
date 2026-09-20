<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Service;

use App\Service\ProductImport\ProductImportResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tracks the state of a sync in a small status file (var/number1_rim_import/status.json) — same
 * "a file under var/, not the database" convention core's own ProductImportService uses for its
 * progress_token mechanism. This is what makes "click the button, it runs in the background, poll
 * for status" possible: SyncRimProductsCommand writes to this file whether it was launched by cron
 * or spawned as a detached process from RimImportController, so both trigger paths report through
 * the exact same status the admin config screen polls.
 */
final class RimSyncStatus
{
    /** A "running" status older than this is treated as stale/abandoned even if the PID check is inconclusive. */
    private const STALE_AFTER_SECONDS = 20 * 60;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /** @return array{status: string, pid: ?int, startedAt: ?string, finishedAt: ?string, message: ?string, result: ?array<string, mixed>} */
    public function read(): array
    {
        $default = ['status' => 'idle', 'pid' => null, 'startedAt' => null, 'finishedAt' => null, 'message' => null, 'result' => null];

        $raw = @file_get_contents($this->filePath());
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }

        return is_array($decoded) ? array_merge($default, $decoded) : $default;
    }

    /** True if a sync is genuinely in progress right now — not just a stale/crashed "running" record. */
    public function isRunning(): bool
    {
        $status = $this->read();
        if ($status['status'] !== 'running') {
            return false;
        }

        $pid = $status['pid'];
        if (is_int($pid) && $pid > 0) {
            // /proc/<pid> only exists on Linux while the process is alive — this deployment target
            // is Linux-only already (see RIM_API_IMPORT_PLAN.md), so this is a reliable check here.
            if (is_dir('/proc')) {
                return is_dir('/proc/' . $pid);
            }
        }

        $startedAt = is_string($status['startedAt']) ? strtotime($status['startedAt']) : false;

        return $startedAt !== false && (time() - $startedAt) < self::STALE_AFTER_SECONDS;
    }

    public function markRunning(): void
    {
        $this->write([
            'status' => 'running',
            'pid' => getmypid() ?: null,
            'startedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'finishedAt' => null,
            'message' => null,
            'result' => null,
        ]);
    }

    public function markSuccess(ProductImportResult $result): void
    {
        $this->write([
            'status' => 'success',
            'pid' => null,
            'startedAt' => $this->read()['startedAt'],
            'finishedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'message' => null,
            'result' => [
                'totalRows' => $result->totalRows,
                'validRows' => $result->validRows,
                'warningRows' => $result->warningRows,
                'errorRows' => $result->errorRows,
                'created' => $result->created,
                'updated' => $result->updated,
                'skipped' => $result->skipped,
                'duplicates' => $result->duplicates,
                'inactivated' => $result->inactivated,
                'errors' => $result->errors,
                'warnings' => $result->warnings,
                'rowResults' => $result->rowResults,
            ],
        ]);
    }

    public function markError(string $message): void
    {
        $this->write([
            'status' => 'error',
            'pid' => null,
            'startedAt' => $this->read()['startedAt'],
            'finishedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'message' => $message,
            'result' => null,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function write(array $payload): void
    {
        $dir = \dirname($this->filePath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        @file_put_contents($this->filePath(), $json);
    }

    private function filePath(): string
    {
        return $this->projectDir . '/var/number1_rim_import/status.json';
    }
}
