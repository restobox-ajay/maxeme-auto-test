<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Contract\Menu\FrontendMenuItemInterface;
use App\Entity\CustomMenuItem;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomMenuItemRepository;
use App\Repository\FrontendMenuItemStatusRepository;
use App\Twig\FrontendMenuExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

final class FrontendMenuExtensionTest extends TestCase
{
    /** @param array<string, mixed> $routeParams */
    private function item(string $key, string $label, string $route, bool $visible = true, array $routeParams = []): FrontendMenuItemInterface
    {
        $item = $this->createStub(FrontendMenuItemInterface::class);
        $item->method('getKey')->willReturn($key);
        $item->method('getLabel')->willReturn($label);
        $item->method('getRoute')->willReturn($route);
        $item->method('getRouteParams')->willReturn($routeParams);
        $item->method('isVisible')->willReturn($visible);

        return $item;
    }

    private function alwaysActiveBundleRepo(): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActiveForInstance')->willReturn(true);

        return $repo;
    }

    private function alwaysActiveMenuItemStatusRepo(int $sortOrder = 0): FrontendMenuItemStatusRepository
    {
        $repo = $this->createStub(FrontendMenuItemStatusRepository::class);
        $repo->method('isActive')->willReturn(true);
        $repo->method('sortOrderFor')->willReturn($sortOrder);

        return $repo;
    }

    private function emptyCustomMenuItemRepo(): CustomMenuItemRepository
    {
        $repo = $this->createStub(CustomMenuItemRepository::class);
        $repo->method('findActiveOrdered')->willReturn([]);

        return $repo;
    }

    private function customItem(string $label, string $url, int $sortOrder = 0): CustomMenuItem
    {
        return (new CustomMenuItem())->setLabel($label)->setUrl($url)->setSortOrder($sortOrder);
    }

    public function testGetFrontendMenuItemsReturnsLabelAndRouteForEachItem(): void
    {
        $extension = new FrontendMenuExtension(
            [$this->item('news.index', 'News', 'news_index')],
            $this->alwaysActiveBundleRepo(),
            $this->alwaysActiveMenuItemStatusRepo(),
            $this->emptyCustomMenuItemRepo(),
        );

        self::assertSame(
            [['label' => 'News', 'route' => 'news_index', 'routeParams' => [], 'url' => null]],
            $extension->getFrontendMenuItems(),
        );
    }

    public function testGetFrontendMenuItemsPassesThroughRouteParams(): void
    {
        $extension = new FrontendMenuExtension(
            [$this->item('catalog.tire', 'Tire', 'customer_catalog', routeParams: ['ProductSearch[category_id]' => 5])],
            $this->alwaysActiveBundleRepo(),
            $this->alwaysActiveMenuItemStatusRepo(),
            $this->emptyCustomMenuItemRepo(),
        );

        self::assertSame(
            [['label' => 'Tire', 'route' => 'customer_catalog', 'routeParams' => ['ProductSearch[category_id]' => 5], 'url' => null]],
            $extension->getFrontendMenuItems(),
        );
    }

    public function testGetFrontendMenuItemsSkipsItemsFromInactiveBundle(): void
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActiveForInstance')->willReturn(false);

        $extension = new FrontendMenuExtension(
            [$this->item('news.index', 'News', 'news_index')],
            $repo,
            $this->alwaysActiveMenuItemStatusRepo(),
            $this->emptyCustomMenuItemRepo(),
        );

        self::assertSame([], $extension->getFrontendMenuItems());
    }

    public function testGetFrontendMenuItemsSkipsItemsTurnedOffByAdmin(): void
    {
        $repo = $this->createStub(FrontendMenuItemStatusRepository::class);
        $repo->method('isActive')->willReturn(false);
        $repo->method('sortOrderFor')->willReturn(0);

        $extension = new FrontendMenuExtension(
            [$this->item('news.index', 'News', 'news_index')],
            $this->alwaysActiveBundleRepo(),
            $repo,
            $this->emptyCustomMenuItemRepo(),
        );

        self::assertSame([], $extension->getFrontendMenuItems());
    }

    public function testGetFrontendMenuItemsSkipsItemsThatReportThemselvesNotVisible(): void
    {
        $extension = new FrontendMenuExtension(
            [$this->item('news.index', 'News', 'news_index', visible: false)],
            $this->alwaysActiveBundleRepo(),
            $this->alwaysActiveMenuItemStatusRepo(),
            $this->emptyCustomMenuItemRepo(),
        );

        self::assertSame([], $extension->getFrontendMenuItems());
    }

    public function testGetFrontendMenuItemsIncludesActiveCustomItemsWithUrlAndNoRoute(): void
    {
        $customRepo = $this->createStub(CustomMenuItemRepository::class);
        $customRepo->method('findActiveOrdered')->willReturn([$this->customItem('Warranty', '/warranty')]);

        $extension = new FrontendMenuExtension(
            [],
            $this->alwaysActiveBundleRepo(),
            $this->alwaysActiveMenuItemStatusRepo(),
            $customRepo,
        );

        self::assertSame(
            [['label' => 'Warranty', 'route' => null, 'routeParams' => [], 'url' => '/warranty']],
            $extension->getFrontendMenuItems(),
        );
    }

    public function testGetFrontendMenuItemsOrdersCoreAndCustomItemsTogetherBySortOrder(): void
    {
        $customRepo = $this->createStub(CustomMenuItemRepository::class);
        $customRepo->method('findActiveOrdered')->willReturn([$this->customItem('Warranty', '/warranty', 0)]);

        $extension = new FrontendMenuExtension(
            [$this->item('news.index', 'News', 'news_index')],
            $this->alwaysActiveBundleRepo(),
            $this->alwaysActiveMenuItemStatusRepo(sortOrder: 5),
            $customRepo,
        );

        self::assertSame(
            [
                ['label' => 'Warranty', 'route' => null, 'routeParams' => [], 'url' => '/warranty'],
                ['label' => 'News', 'route' => 'news_index', 'routeParams' => [], 'url' => null],
            ],
            $extension->getFrontendMenuItems(),
        );
    }

    public function testGetFunctionsRegistersFrontendMenuItemsFunction(): void
    {
        $extension = new FrontendMenuExtension(
            [],
            $this->alwaysActiveBundleRepo(),
            $this->alwaysActiveMenuItemStatusRepo(),
            $this->emptyCustomMenuItemRepo(),
        );

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertInstanceOf(TwigFunction::class, $functions[0]);
        self::assertSame('frontend_menu_items', $functions[0]->getName());
    }
}
