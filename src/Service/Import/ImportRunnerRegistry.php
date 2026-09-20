<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Contract\Import\ImportRunnerFactoryInterface;
use App\Entity\ImportRun;

/**
 * Resolves ImportRun::$importType to the ImportRunner that knows how to execute it. Empty until
 * an import registers a factory under the `app.import_runner_factory` tag (see
 * config/services.yaml) — with none registered, import:process simply has nothing it can run,
 * which is the correct state for this framework with no concrete import wired up to it yet.
 */
final class ImportRunnerRegistry
{
    /** @var array<string, ImportRunnerFactoryInterface> */
    private readonly array $factories;

    /** @param iterable<ImportRunnerFactoryInterface> $factories */
    public function __construct(iterable $factories)
    {
        $indexed = [];
        foreach ($factories as $factory) {
            $indexed[$factory->importType()] = $factory;
        }
        $this->factories = $indexed;
    }

    public function has(string $importType): bool
    {
        return isset($this->factories[$importType]);
    }

    /** @throws \RuntimeException if nothing registered a factory for this import type */
    public function get(ImportRun $run): ImportRunner
    {
        $factory = $this->factories[$run->getImportType()] ?? null;
        if ($factory === null) {
            throw new \RuntimeException("No import runner is registered for import type '{$run->getImportType()}'.");
        }

        return $factory->create($run);
    }
}
