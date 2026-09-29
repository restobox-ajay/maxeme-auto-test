<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The read-only connection to the legacy database (LEGACY_DATABASE_URL). Kept out of Doctrine's
 * configured connections on purpose: only the import command ever needs it.
 */
final class LegacyConnectionFactory
{
    public function __construct(
        #[Autowire(param: 'maxeme.legacy_database_url')]
        private readonly string $databaseUrl,
    ) {
    }

    public function create(): Connection
    {
        if (trim($this->databaseUrl) === '') {
            throw new \RuntimeException('LEGACY_DATABASE_URL is not set. Add it to .env.local, e.g. mysql://root@127.0.0.1:3306/maxemead_1?serverVersion=9.1.0&charset=utf8mb4');
        }

        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($this->databaseUrl);

        return DriverManager::getConnection($params);
    }
}
