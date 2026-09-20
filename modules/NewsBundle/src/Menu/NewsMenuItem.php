<?php

declare(strict_types=1);

namespace NewsBundle\Menu;

use App\Contract\Hook\InjectionPointMenuItemInterface;

final class NewsMenuItem implements InjectionPointMenuItemInterface
{
    public function getLabel(): string { return 'News Posts'; }
    public function getRoute(): string { return 'admin_bundle_news_index'; }
    public function getSection(): string { return 'Number1 Integration'; }
}
