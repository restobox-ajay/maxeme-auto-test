<?php

declare(strict_types=1);

namespace App\Maxeme\Command;

use App\Bundle\InstalledBundleDirectory;
use App\Entity\AppSetting;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applies config/packages/maxeme.yaml to the database: the shop name, timezone and reset-link
 * window, and which installed modules stay Active (`maxeme.enabled_bundles`). Safe to re-run;
 * run it after migrating a fresh database.
 */
#[AsCommand(name: 'app:maxeme:setup', description: 'Apply the Maxeme shop configuration (name, timezone, active modules)')]
final class SetupCommand
{
    /** @param list<string> $enabledBundles */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettings $appSettings,
        private readonly BundleStatusRepository $bundleStatuses,
        private readonly InstalledBundleDirectory $installedBundles,
        #[Autowire(param: 'maxeme.app_name')]
        private readonly string $appName,
        #[Autowire(param: 'maxeme.company_name')]
        private readonly string $companyName,
        #[Autowire(param: 'maxeme.timezone')]
        private readonly string $timezone,
        #[Autowire(param: 'maxeme.password_reset_hours')]
        private readonly int $passwordResetHours,
        #[Autowire(param: 'maxeme.enabled_bundles')]
        private readonly array $enabledBundles,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->applySettings($io);
        $this->applyBundles($io);

        return Command::SUCCESS;
    }

    private function applySettings(SymfonyStyle $io): void
    {
        $settings = [
            'app_name' => $this->appName,
            'company_name' => $this->companyName,
            AppSettings::PASSWORD_RESET_EXPIRY_KEY => (string) $this->passwordResetHours,
            AppSettings::TIMEZONE_KEY => $this->timezone,
        ];

        foreach ($settings as $key => $value) {
            $setting = $this->entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
            if ($setting === null) {
                $io->warning(sprintf('App setting "%s" does not exist; run the migrations first.', $key));
                continue;
            }

            $setting->setSettingValue($value);
        }

        $this->entityManager->flush();
        $this->appSettings->clearCache();
        $io->success(sprintf('Shop name set to "%s" (%s), timezone %s; reset links last %d hours.', $this->appName, $this->companyName, $this->timezone, $this->passwordResetHours));
    }

    private function applyBundles(SymfonyStyle $io): void
    {
        $turnedOff = [];
        foreach ($this->installedBundles->sources() as $source) {
            if (in_array($source, $this->enabledBundles, true)) {
                $this->bundleStatuses->activate($source);
            } elseif ($this->bundleStatuses->isActive($source)) {
                $this->bundleStatuses->deactivate($source);
                $turnedOff[] = $source;
            }
        }

        $io->success($turnedOff === []
            ? 'Modules already match maxeme.enabled_bundles.'
            : sprintf('Switched off %d module(s): %s. Nothing was deleted.', count($turnedOff), implode(', ', $turnedOff)));
    }
}
