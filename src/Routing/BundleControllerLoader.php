<?php

declare(strict_types=1);

namespace App\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Discovers each module's admin/customer controllers by globbing modules/*\/src/Controller
 * instead of listing every bundle by name in config/routes.yaml. A deleted (or not-yet-added)
 * module folder just yields one fewer glob() match here — nothing to edit, nothing to break.
 *
 * Registered via the tag in config/services.yaml and invoked from config/routes/bundle_controllers.yaml
 * per https://symfony.com/doc/current/routing/custom_route_loader.html.
 */
final class BundleControllerLoader extends Loader
{
    private bool $loaded = false;

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \RuntimeException('Do not add the "bundle_controllers" loader twice.');
        }

        $collection = new RouteCollection();

        $controllerDirs = glob(\dirname(__DIR__, 2) . '/modules/*/src/Controller', \GLOB_ONLYDIR) ?: [];
        sort($controllerDirs);

        foreach ($controllerDirs as $dir) {
            $collection->addCollection($this->import($dir, 'attribute'));
        }

        $this->loaded = true;

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'bundle_controllers' === $type;
    }
}
