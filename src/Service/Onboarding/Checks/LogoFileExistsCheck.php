<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class LogoFileExistsCheck extends AbstractUploadedFileExistsCheck
{
    protected function group(): string
    {
        return 'Branding';
    }

    protected function label(): string
    {
        return 'Logo File Exists On Disk';
    }

    protected function key(): string
    {
        return 'logo_file_exists';
    }

    protected function sortOrder(): int
    {
        return 65;
    }

    protected function settingKey(): string
    {
        return 'logo_url';
    }

    protected function fieldLabel(): string
    {
        return 'Logo';
    }
}
