<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\RichTextSanitizer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ post.content|rich_text }}` prints admin-written HTML through RichTextSanitizer. Use it
 * instead of `|raw` wherever stored HTML is shown.
 */
final class RichTextExtension extends AbstractExtension
{
    public function __construct(private readonly RichTextSanitizer $sanitizer)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('rich_text', $this->sanitizer->sanitize(...), ['is_safe' => ['html']]),
        ];
    }
}
