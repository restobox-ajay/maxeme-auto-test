<?php

declare(strict_types=1);

namespace App\Contract\Connector;

/**
 * How a connector type's aggregate status reads on the core directory (#741) — semantic, not the
 * accent color: same vocabulary as every other status badge in this app (ok/warn/err), kept as its
 * own enum here because nothing existing already names it and a directory row is exactly the kind
 * of small, fixed, non-user-editable vocabulary an enum is for.
 */
enum ConnectorStatusTone: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Err = 'err';
}
