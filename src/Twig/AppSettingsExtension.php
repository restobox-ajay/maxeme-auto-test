<?php

namespace App\Twig;

use App\Service\AppSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppSettingsExtension extends AbstractExtension
{
    public function __construct(private readonly AppSettings $settings)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('json_decode', fn(?string $json) => is_string($json) ? (json_decode($json, true) ?? []) : []),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_setting', [$this, 'getSetting']),
            new TwigFunction('app_settings_all', [$this, 'getAll']),
            new TwigFunction('site_name', [$this, 'getSiteName']),
        ];
    }

    /** The store's public name (app_name -> company_name -> neutral), for titles/headers/emails. */
    public function getSiteName(): string
    {
        return $this->settings->siteName();
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        return $this->settings->get($key, $default);
    }

    /** @return array<string, string|null> */
    public function getAll(): array
    {
        return $this->settings->all();
    }
}

