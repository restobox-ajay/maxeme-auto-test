<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class FaviconFileExistsCheck extends AbstractUploadedFileExistsCheck
{
    protected function group(): string
    {
        return 'Branding';
    }

    protected function label(): string
    {
        return 'Favicon File Exists On Disk';
    }

    protected function key(): string
    {
        return 'favicon_file_exists';
    }

    protected function sortOrder(): int
    {
        return 66;
    }

    protected function settingKey(): string
    {
        return 'favicon_url';
    }

    protected function fieldLabel(): string
    {
        return 'Favicon';
    }
}
