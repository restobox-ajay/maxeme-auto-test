<?php

declare(strict_types=1);

namespace NewsBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Service\AppSettings;
use NewsBundle\Controller\Admin\NewsPostController;

final class LoginScrollerProvider implements InjectionPointProviderInterface
{
    public function __construct(
        private readonly NewsScrollerRenderer $renderer,
        private readonly AppSettings $appSettings,
    ) {}

    public function getPoint(): string
    {
        return 'customer_login_after_card';
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
        if ($this->appSettings->get(NewsPostController::SETTING_SHOW_ON_LOGIN) !== '1') {
            return '';
        }

        return $this->renderer->render();
    }
}
