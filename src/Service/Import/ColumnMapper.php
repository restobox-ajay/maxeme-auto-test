<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\ImportColumnMapping;
use App\Model\ImportTargetField;
use App\Repository\ImportColumnMappingRepository;
use App\Service\TextInput;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A file's CSV headers cannot be assumed to match this app's field names, or to be the same across
 * uploads. This is the step between "a file was uploaded" and "rows can be processed": show what
 * target fields the import needs, let the admin say which detected header answers each one, and
 * remember that choice per (import type, scope) so a later upload of the same shape pre-fills
 * instead of asking again. Shared by every import — each just declares its own target fields (see
 * ImportDefinitionInterface::targetFields()) and, if it has one, a scope string (e.g. a vendor ID)
 * to remember the mapping by.
 */
final class ColumnMapper
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportColumnMappingRepository $mappings,
    ) {
    }

    /**
     * One row per target field, for the mapping screen: which header (if any) is currently
     * selected, pre-filled from a remembered mapping when the file still has that header.
     *
     * @param list<ImportTargetField> $fields
     * @param list<string>            $headers detected in the uploaded file, in file order
     *
     * @return list<array{key: string, label: string, required: bool, selected: ?string}>
     */
    public function formRows(array $fields, array $headers, string $importType, string $scope = ''): array
    {
        $remembered = $this->mappings->findOneForScope($importType, $scope)?->getMapping() ?? [];

        return $this->rowsForMapping($fields, $headers, $remembered);
    }

    /**
     * The same per-field row shape as formRows(), but pre-filled from an arbitrary mapping rather
     * than the remembered one — what the mapping screen redisplays with when a submission was
     * rejected for missing a required field, so the admin's other choices are not lost.
     *
     * @param list<ImportTargetField> $fields
     * @param list<string>            $headers
     * @param array<string, string>   $mapping
     *
     * @return list<array{key: string, label: string, required: bool, selected: ?string}>
     */
    public function rowsForMapping(array $fields, array $headers, array $mapping): array
    {
        $rows = [];
        foreach ($fields as $field) {
            $chosen = $mapping[$field->key] ?? null;
            $rows[] = [
                'key' => $field->key,
                'label' => $field->label,
                'required' => $field->required,
                'selected' => \in_array($chosen, $headers, true) ? $chosen : null,
            ];
        }

        return $rows;
    }

    /**
     * The posted mapping — target field key => chosen header, or omitted where the admin left it
     * unmapped ("-- not in this file --").
     *
     * @param list<ImportTargetField> $fields
     * @param array<string, mixed>    $posted request body, e.g. column_map[vendor_sku] = "Item Code"
     *
     * @return array<string, string> target field key => chosen header (blank/absent entries omitted)
     */
    public function fromRequest(array $fields, array $posted): array
    {
        $mapping = [];
        foreach ($fields as $field) {
            // TextInput::nullableString() — the app-wide "trim, strip control chars, blank-as-null"
            // helper (#303/#395) — rather than a hand-rolled trim(): $posted is raw request data, and
            // a tampered `column_map[key][]=x` post makes this genuinely non-scalar, which
            // nullableString() already treats as absent instead of crashing on the (string) cast.
            $value = TextInput::nullableString($posted[$field->key] ?? null);
            if ($value !== null) {
                $mapping[$field->key] = $value;
            }
        }

        return $mapping;
    }

    /**
     * Required fields the admin left unmapped — empty means the mapping is good to proceed with.
     *
     * @param list<ImportTargetField> $fields
     * @param array<string, string>   $mapping
     *
     * @return list<string> labels of the missing required fields
     */
    public function missingRequired(array $fields, array $mapping): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if ($field->required && !isset($mapping[$field->key])) {
                $missing[] = $field->label;
            }
        }

        return $missing;
    }

    /**
     * Apply a mapping to one raw CSV row (header => value) and produce the target-field-keyed row
     * ImportRunRow::$mappedData and every validator/executor read.
     *
     * @param array<string, string> $mapping   target field key => header
     * @param array<string, mixed>  $rawRow    header => raw cell value
     *
     * @return array<string, mixed> target field key => value (missing/unmapped fields simply absent)
     */
    public function applyMapping(array $mapping, array $rawRow): array
    {
        $mapped = [];
        foreach ($mapping as $fieldKey => $header) {
            if (\array_key_exists($header, $rawRow)) {
                $mapped[$fieldKey] = $rawRow[$header];
            }
        }

        return $mapped;
    }

    /**
     * Remember this mapping for next time — a plain convenience (see ImportColumnMapping's own
     * docblock); nothing downstream depends on this having been called.
     *
     * @param array<string, string> $mapping
     */
    public function remember(string $importType, string $scope, array $mapping): void
    {
        $existing = $this->mappings->findOneForScope($importType, $scope);
        if ($existing instanceof ImportColumnMapping) {
            $existing->setMapping($mapping);
        } else {
            $this->em->persist(new ImportColumnMapping($importType, $scope, $mapping));
        }

        $this->em->flush();
    }
}
