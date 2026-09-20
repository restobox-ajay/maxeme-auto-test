<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\Csrf\Csrf;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ csrf_field() }} — THE generation point for templates. Renders the hidden input that
 * App\EventSubscriber\CsrfProtectionSubscriber verifies.
 *
 * Server-rendered, so forms submit correctly with JavaScript disabled.
 */
final class CsrfExtension extends AbstractExtension
{
    public function __construct(
        private readonly Csrf $csrf,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_field', [$this->csrf, 'field'], ['is_safe' => ['html']]),
            // For the <meta> tag only; app.js reads it to attach the header on AJAX calls.
            new TwigFunction('csrf_token_value', [$this->csrf, 'token']),
        ];
    }
}
