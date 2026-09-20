<?php

declare(strict_types=1);

namespace TechnicalDocsBundle\Docs;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Everything this bundle reads from disk goes through here, and only here. Both entry
 * points resolve strictly inside the app's own docs/ directory:
 *
 * - list(): a Finder search rooted at docs/, matching *.md only.
 * - resolve(): realpath()'s the requested relative path and requires the result to
 *   still be inside docs/ (blocks ../ traversal and symlinks pointing outside it) and
 *   to end in .md — so even a malformed or malicious $relativePath can only ever
 *   resolve to a markdown file already inside docs/, never an arbitrary file elsewhere
 *   on disk.
 */
final class DocsRepository
{
    private readonly string $docsRoot;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->docsRoot = rtrim($projectDir, '/') . '/docs';
    }

    /** @return list<string> relative paths (e.g. "inventory-calculation.md", "bundles/shipping.md"), sorted */
    public function list(): array
    {
        if (!is_dir($this->docsRoot)) {
            return [];
        }

        $finder = (new Finder())
            ->in($this->docsRoot)
            ->files()
            ->name('*.md')
            ->sortByName();

        $relative = [];
        foreach ($finder as $file) {
            $relative[] = $file->getRelativePathname();
        }

        return $relative;
    }

    /** Returns the file's contents, or null if $relativePath doesn't resolve to a real .md file inside docs/. */
    public function read(string $relativePath): ?string
    {
        $real = $this->resolve($relativePath);

        return $real === null ? null : file_get_contents($real);
    }

    private function resolve(string $relativePath): ?string
    {
        $docsRootReal = realpath($this->docsRoot);
        if ($docsRootReal === false) {
            return null;
        }

        $candidate = realpath($docsRootReal . '/' . $relativePath);
        if ($candidate === false || !str_ends_with(strtolower($candidate), '.md')) {
            return null;
        }

        if (!str_starts_with($candidate, $docsRootReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $candidate;
    }
}
