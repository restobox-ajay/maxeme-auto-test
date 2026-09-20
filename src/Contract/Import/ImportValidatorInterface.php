<?php

declare(strict_types=1);

namespace App\Contract\Import;

/**
 * Per-row validation for one import type. Called twice for a real (non-validation-only) run —
 * once in-request via ImportRunner::validate() and again inside import:process via
 * ImportRunner::executeQueued() — because rules may have changed in between (see D3 in the
 * framework plan); a validation-only run calls it exactly once and stops there.
 */
interface ImportValidatorInterface
{
    /**
     * Validate one already-column-mapped row. An empty return means the row is valid.
     *
     * @param array<string, mixed> $mappedData target field key => value, per
     *                                          ImportDefinitionInterface::targetFields()
     *
     * @return list<string> human-readable error messages; empty = valid
     */
    public function validate(array $mappedData): array;
}
