<?php

declare(strict_types=1);

namespace App\Twig;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use App\Menu\Admin\AdminMenuTreeBuilder;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ admin_menu_tree() }} — the admin sidebar as data: App\Menu\Admin\AdminMenuCatalog's default
 * tree, merged with every active AdminMenuOverrideProviderInterface's hide/reorder/reparent/
 * custom-item overrides (see App\Menu\Admin\AdminMenuTreeBuilder).
 *
 * Inert by default. Core ships no storage and no UI behind it: with no registered provider — or
 * with modules/AdminMenuBundle deleted entirely — this is exactly
 * App\Menu\Admin\AdminMenuCatalog::defaultTree(), and templates/admin/_main/layout.html.twig
 * renders exactly the sidebar it used to hardcode.
 *
 * An entry missing from the tree hides a link and nothing else. It is NOT authorization: the
 * route stays reachable by URL and keeps its own security attributes, and no code may read the
 * tree as permission.
 */
final class AdminMenuExtension extends AbstractExtension implements ResetInterface
{
    /**
     * Built once per request — the layout walks the whole tree on every render, and building it
     * costs an AppSettings-style read per active provider.
     *
     * @var list<array{node: \App\Menu\Admin\AdminMenuNode, children: list<\App\Menu\Admin\AdminMenuNode>, affordances: array<string, list<\App\Menu\Admin\AdminMenuNode>>}>|null
     */
    private ?array $tree = null;

    /** @param iterable<AdminMenuOverrideProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('app.admin_menu_override_provider')]
        private readonly iterable $providers,
        private readonly AdminMenuTreeBuilder $treeBuilder,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_menu_tree', [$this, 'getTree']),
        ];
    }

    /** @return list<array{node: \App\Menu\Admin\AdminMenuNode, children: list<\App\Menu\Admin\AdminMenuNode>, affordances: array<string, list<\App\Menu\Admin\AdminMenuNode>>}> */
    public function getTree(): array
    {
        if ($this->tree !== null) {
            return $this->tree;
        }

        return $this->tree = $this->treeBuilder->build($this->providers);
    }

    public function reset(): void
    {
        $this->tree = null;
    }
}
