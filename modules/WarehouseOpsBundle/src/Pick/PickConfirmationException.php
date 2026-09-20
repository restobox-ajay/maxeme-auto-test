<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Pick;

/**
 * A task cannot be confirmed at all — a message for the person holding the scanner, not a stack
 * trace.
 *
 * The plan is explicit that a failed scan must be loud: silent failure is how stock goes missing, so
 * every refusal carries a sentence that says what to do about it.
 */
final class PickConfirmationException extends \RuntimeException
{
}
