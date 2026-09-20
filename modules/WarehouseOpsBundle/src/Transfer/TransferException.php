<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Transfer;

/**
 * A transfer was refused — wrong status, nothing to send, or a product with no breakdown to move.
 *
 * A sentence, not a stack trace.
 */
final class TransferException extends \RuntimeException
{
}
