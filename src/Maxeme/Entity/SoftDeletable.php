<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

/**
 * A record the legacy app never deleted: removing it clears `active`, so it leaves every list and
 * search while invoices, appointments and history that point at it stay intact.
 */
interface SoftDeletable
{
    public function isActive(): bool;

    public function deactivate(): void;
}
