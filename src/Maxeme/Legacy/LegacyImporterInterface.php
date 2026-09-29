<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One step of `app:maxeme:import-legacy`: copies one kind of record from the legacy Maxeme MySQL
 * database into this app. Steps run in ascending order(), so a step can rely on the ones before
 * it (vehicles after clients, and so on). Every step must be safe to run again: it updates what it
 * imported before rather than duplicating it.
 */
#[AutoconfigureTag(self::TAG)]
interface LegacyImporterInterface
{
    public const TAG = 'maxeme.legacy_importer';

    /** The --only name, e.g. "users". */
    public static function name(): string;

    public static function order(): int;

    /** @return int how many records were created or updated */
    public function import(Connection $legacy, SymfonyStyle $io): int;
}
