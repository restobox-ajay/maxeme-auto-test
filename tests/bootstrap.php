<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Guards against a stale compiled container/metadata cache under var/cache/test carrying
// mappings for an entity shape that no longer matches current code — cheap enough to always
// pay before a run, and DoctrineIntegrationTestCase-based tests boot a real kernel against
// this cache.
(new \Symfony\Component\Filesystem\Filesystem())->remove(dirname(__DIR__) . '/var/cache/test');
