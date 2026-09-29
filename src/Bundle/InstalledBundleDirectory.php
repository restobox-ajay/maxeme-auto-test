<?php

declare(strict_types=1);

namespace App\Bundle;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Which first-party modules this installation actually has — the "present on disk" half of the
 * three states App Management and `app:bundle:list` distinguish.
 *
 * ## Why the kernel and not a glob
 *
 * `config/bundles.php` discovers modules with `glob(modules/*)` and only registers one that has a
 * `src/<Name>.php` declaring `<Name>\<Name>`. Re-globbing here would re-implement that and could
 * disagree with it — a folder left behind by a half-deleted module would show up as installed while
 * the application had never loaded it. Asking the booted kernel which bundles it loaded, and
 * keeping the ones whose path is inside `modules/`, is the same question answered by the component
 * that actually decides it.
 *
 * The one place that CANNOT do this is the upgrade migration, which has no kernel; see
 * {@see \DoctrineMigrations\Version20260916104500} for why its own glob is a faithful and safe
 * stand-in there.
 *
 * ## Present is not the same as active
 *
 * Nothing here consults `bundle_status`. A module is present because its folder is on disk and the
 * kernel loaded it, which is true whether it is Active, Inactive or has never been activated at
 * all — that is exactly the distinction the flip introduced and this class must not blur it.
 */
final class InstalledBundleDirectory
{
    /** @var list<string>|null */
    private ?array $cache = null;

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    /**
     * The sources of every first-party module the kernel loaded, sorted.
     *
     * A bundle's "source" is its short name, which is both its root namespace segment and its
     * folder name — the same string `BundleDescriptorInterface::getSource()` returns and
     * {@see \App\Repository\BundleStatusRepository::sourceFromClass()} derives.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $modulesDir = rtrim($this->kernel->getProjectDir(), '/') . '/modules/';
        $sources = [];

        foreach ($this->kernel->getBundles() as $bundle) {
            // realpath() so a project dir reached through a symlink still matches the bundle paths
            // the kernel reports. Worktrees and deploy-by-symlink layouts both hit this.
            $path = realpath($bundle->getPath());
            $modules = realpath($modulesDir);

            if ($path === false || $modules === false) {
                continue;
            }

            // DIRECTORY_SEPARATOR, not '/': realpath() answers with backslashes on Windows.
            if (str_starts_with($path, rtrim($modules, '/\\') . DIRECTORY_SEPARATOR)) {
                $sources[] = $bundle->getName();
            }
        }

        sort($sources);

        return $this->cache = $sources;
    }

    public function isInstalled(string $source): bool
    {
        return in_array($source, $this->sources(), true);
    }
}
