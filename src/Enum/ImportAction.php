<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a row (or a whole run, as the comma-joined set of its rows' actions) did to the target
 * entity. Which of these an import supports at all is declared per import —
 * see ImportDefinitionInterface::supportedActions().
 */
enum ImportAction: string
{
    case Append = 'append';
    case Update = 'update';
    case Delete = 'delete';

    /**
     * The stored value never changes — every ImportRunRow already on disk, and every `===
     * ImportAction::Append` comparison in this codebase, reads it. This is display only: "Add"
     * reads better than "append" to an admin looking at what an import did, and is the word the
     * vendor sheet import's own Add/Update/Delete switches use, so the switch an admin ticked and
     * the outcome a row reports say the same thing.
     */
    public function label(): string
    {
        return match ($this) {
            self::Append => 'Add',
            self::Update => 'Update',
            self::Delete => 'Delete',
        };
    }
}
