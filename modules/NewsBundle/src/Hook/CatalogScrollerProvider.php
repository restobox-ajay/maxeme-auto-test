<?php

declare(strict_types=1);

namespace NewsBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Service\AppSettings;
use NewsBundle\Controller\Admin\NewsPostController;

final class CatalogScrollerProvider implements InjectionPointProviderInterface
{
    public function __construct(
        private readonly NewsScrollerRenderer $renderer,
        private readonly AppSettings $appSettings,
    ) {}

    public function getPoint(): string
    {
        return 'catalog_before_products';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return 'NewsBundle';
    }

    public function render(array $context): string
    {
        if ($this->appSettings->get(NewsPostController::SETTING_SHOW_ON_CATALOG) !== '1') {
            return '';
        }

        return $this->renderer->render();
    }
}
