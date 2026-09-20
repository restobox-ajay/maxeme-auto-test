<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use App\Service\AppSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shared logic for "logo file is not missing" / "favicon file not missing" (#426) — the setting
 * (logo_url/favicon_url) can be filled out yet still point at a file that was deleted from disk
 * outside the app (a bad deploy, a manual `rm`, a restore from an incomplete backup), which is
 * exactly the gap LogoUrlConfiguredCheck alone would miss.
 *
 * ConfigController only ever writes these settings as a server-relative path under
 * /uploads/branding/ (see ConfigController::branding()) — never an absolute URL. Anything else
 * (an http(s):// URL, a `..` path segment, a value that resolves outside the public/ directory)
 * is treated as invalid rather than followed, both because this check has no business fetching a
 * remote URL and because resolving outside public/ would let a corrupted setting value make this
 * check report on an arbitrary file elsewhere on disk.
 */
abstract class AbstractUploadedFileExistsCheck implements OnboardingCheckInterface
{
    public function __construct(
        private readonly AppSettings $appSettings,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    abstract protected function group(): string;

    abstract protected function label(): string;

    abstract protected function key(): string;

    abstract protected function sortOrder(): int;

    abstract protected function settingKey(): string;

    abstract protected function fieldLabel(): string;

    final public function getKey(): string
    {
        return $this->key();
    }

    final public function getGroup(): string
    {
        return $this->group();
    }

    final public function getLabel(): string
    {
        return $this->label();
    }

    final public function getSortOrder(): int
    {
        return $this->sortOrder();
    }

    final public function run(): OnboardingCheckResult
    {
        $value = trim((string) ($this->appSettings->get($this->settingKey()) ?? ''));

        if ($value === '') {
            return OnboardingCheckResult::fail($this->fieldLabel() . ' is not configured.');
        }

        if (str_contains($value, '://') || str_starts_with($value, '//')) {
            return OnboardingCheckResult::fail(
                $this->fieldLabel() . ' is set to an external URL, which cannot be verified as an '
                . 'uploaded file. Re-upload it under Settings > Branding.'
            );
        }

        $publicRoot = rtrim($this->projectDir, '/') . '/public';
        $resolvedPublicRoot = realpath($publicRoot);
        $candidate = $publicRoot . '/' . ltrim($value, '/');
        $resolvedCandidate = realpath($candidate);

        // realpath() fails outright for a missing file, which is the common "file was deleted"
        // case. It can also fail to resolve for other reasons (permissions, dangling symlink) —
        // either way, "cannot be confirmed to exist" is a fail, not a crash.
        if ($resolvedPublicRoot === false || $resolvedCandidate === false) {
            return OnboardingCheckResult::fail(
                $this->fieldLabel() . " points to \"$value\", but that file is missing on disk. "
                . 'Re-upload it under Settings > Branding.'
            );
        }

        // A `..` (or symlink) that escapes public/ must not be reported on as if it were the
        // logo/favicon — refuse to confirm existence of anything outside the upload root.
        if (!str_starts_with($resolvedCandidate . '/', $resolvedPublicRoot . '/')) {
            return OnboardingCheckResult::fail(
                $this->fieldLabel() . ' is set to a path outside the uploads directory and cannot '
                . 'be verified. Re-upload it under Settings > Branding.'
            );
        }

        if (!is_file($resolvedCandidate)) {
            return OnboardingCheckResult::fail(
                $this->fieldLabel() . " points to \"$value\", but that file is missing on disk. "
                . 'Re-upload it under Settings > Branding.'
            );
        }

        return OnboardingCheckResult::pass();
    }
}
