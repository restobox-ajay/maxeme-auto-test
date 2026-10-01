<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Contract\Document\DocumentPrefix;
use App\Entity\AppSetting;
use App\Maxeme\Audit\ActivityRecorder;
use App\Service\AppSettings;
use App\Service\Document\DocumentPrefixCatalogue;
use App\Validation\Constraint\ValidDocumentPrefixes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Config › Settings › Doc Prefixes: the shop's four prefixes (DocumentNumbers::PREFIX_KEYS), stored
 * as core's `*_number_prefix` app settings and checked by core's own rule (ValidDocumentPrefixes).
 * Core's entity diff skips app settings, so a change is recorded in the Activity Log here.
 */
final class DocPrefixSettings
{
    public function __construct(
        private readonly DocumentPrefixCatalogue $catalogue,
        private readonly DocumentNumbers $numbers,
        private readonly AppSettings $appSettings,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ActivityRecorder $activity,
    ) {
    }

    /** @return list<DocumentPrefix> the screen's fields, in order */
    public function definitions(): array
    {
        return array_values(array_filter(array_map($this->catalogue->get(...), DocumentNumbers::PREFIX_KEYS)));
    }

    /** @return array<string, string> key => the prefix in force */
    public function values(): array
    {
        $values = [];
        foreach ($this->definitions() as $definition) {
            $values[$definition->key] = $this->numbers->prefix($definition->key);
        }

        return $values;
    }

    /**
     * Saves every submitted prefix, or none when one is invalid.
     *
     * @param array<string, string> $submitted key => prefix as typed
     *
     * @return ?string the error, or null when saved
     */
    public function save(array $submitted): ?string
    {
        $before = $this->values();
        $after = [];
        foreach ($this->definitions() as $definition) {
            if (\array_key_exists($definition->key, $submitted)) {
                $after[$definition->key] = strtoupper(trim($submitted[$definition->key]));
            }
        }

        $violations = $this->validator->validate(new \ArrayObject($after), new ValidDocumentPrefixes());
        if (count($violations) > 0) {
            return (string) $violations[0]->getMessage();
        }

        foreach ($this->definitions() as $definition) {
            if (\array_key_exists($definition->key, $after)) {
                $this->store($definition, $after[$definition->key]);
            }
        }
        $this->entityManager->flush();
        $this->appSettings->clearCache();
        $this->activity->settingsChanged('document prefixes', $before, $after + $before);

        return null;
    }

    private function store(DocumentPrefix $definition, string $value): void
    {
        $setting = $this->entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => $definition->key])
            ?? (new AppSetting())->setSettingKey($definition->key);

        $setting->setName($definition->settingName)
            ->setSettingValue($value)
            ->setDescription($definition->settingDescription)
            ->touch();
        $this->entityManager->persist($setting);
    }
}
