<?php

declare(strict_types=1);

namespace App\Contract\Connector;

/**
 * One connection under a connector type, as the core directory (#741) needs to know it — enough to
 * roll up "3 connections, 1 needs attention" on the type's own directory row. Nothing type-specific:
 * a connector bundle's own screen (its own connections list, its own per-connection settings) is
 * where the real detail lives; core never sees more than this.
 */
final readonly class ConnectorConnectionSummary
{
    public function __construct(
        public string $name,
        public ConnectorStatusTone $statusTone,
        public string $statusLabel,
        public ?\DateTimeImmutable $lastActivityAt = null,
    ) {
    }
}
