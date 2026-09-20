<?php

declare(strict_types=1);

namespace TaxCanadaSimpleBundle\Service;

use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use App\Service\RegionSeedData;
use TaxCanadaSimpleBundle\Tax\CanadaSimpleTaxCalculator;

/**
 * Stores a company's provincial tax registration numbers, one custom field per province.
 *
 * Separate fields rather than one shared "provincial tax #": these are distinct numbers per
 * province, and none of them is the BC PST number the core Company::$pstNumber field holds.
 *
 * Storage only, and deliberately so: CanadaSimpleTaxCalculator does not read these values and
 * they must not be wired into tax calculation. They are a stored record of the company's
 * registration numbers; nothing is inferred from one being present or absent.
 */
final class CanadaTaxRegistrations
{
    /**
     * Province code => the field that province's registration number is stored in.
     *
     * The configured set of provinces that get a field. Adding one here is all that is needed
     * to register and expose it; the slug is permanent once values are stored against it.
     */
    public const PROVINCES = [
        'SK' => ['slug' => 'tax_reg_sk_pst', 'label' => 'Saskatchewan PST #'],
        'MB' => ['slug' => 'tax_reg_mb_rst', 'label' => 'Manitoba RST #'],
        'QC' => ['slug' => 'tax_reg_qc_qst', 'label' => 'Quebec QST #'],
    ];

    public function __construct(
        private readonly CustomFieldDefinitionRepository $definitionRepo,
        private readonly CustomFieldValueRepository $valueRepo,
    ) {
    }

    /**
     * Registers every province's field if it doesn't already exist.
     *
     * Idempotent — ensureBySlug() is keyed on (objectType, slug) and is a no-op once the row
     * exists, which is why the subscriber can call this on every request. Fields are visible on
     * the company add/edit forms so CustomFieldRenderer gives admins a box to type into without
     * this bundle shipping any form code; sortOrder keeps them grouped in province order rather
     * than interleaved with other bundles' company fields.
     */
    public function ensureDefinitions(): void
    {
        $sortOrder = 0;
        foreach (self::PROVINCES as $field) {
            $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY, $field['slug'], [
                'label' => $field['label'],
                'fieldType' => CustomFieldDefinition::FIELD_TYPE_TEXT,
                'visibleOnAdd' => true,
                'visibleOnEdit' => true,
                'source' => CanadaSimpleTaxCalculator::SOURCE,
                'sortOrder' => ++$sortOrder,
            ]);
        }
    }

    /** Whether this province has a registration field configured in PROVINCES. */
    public function supports(string $province): bool
    {
        return isset(self::PROVINCES[$this->normalize($province)]);
    }

    /**
     * The company's registration number for one province, or null when there is none on file.
     *
     * Null is also the answer for a province with no field configured, and for a field that has
     * not been registered yet — callers get "nothing on file" either way rather than having to
     * distinguish an unregistered field from an empty value.
     */
    public function get(int $companyId, string $province): ?string
    {
        $definition = $this->definition($province);
        if ($definition === null) {
            return null;
        }

        $value = trim((string) $this->valueRepo->getValue($definition, $companyId));

        return $value === '' ? null : $value;
    }

    /**
     * Records the company's registration number for one province.
     *
     * Blanks normalise to null so "cleared" and "never entered" are one state, matching how
     * get() reports them. Unlike get(), this registers the field if it is missing — a write has
     * to have somewhere to go, and the caller may run before the subscriber's first request.
     *
     * Writes through the shared CustomFieldValue table but does not flush; the caller's own
     * flush persists it, same as every other custom-field writer in the app.
     */
    public function set(int $companyId, string $province, ?string $value): void
    {
        if (!$this->supports($province)) {
            throw new \InvalidArgumentException(sprintf(
                'Province "%s" has no registration field configured; expected one of: %s.',
                $province,
                implode(', ', array_keys(self::PROVINCES)),
            ));
        }

        $this->ensureDefinitions();

        $definition = $this->definition($province);
        if ($definition === null) {
            return;
        }

        $value = trim((string) $value);
        $this->valueRepo->setValue($definition, $companyId, $value === '' ? null : $value);
    }

    /**
     * Every province's registration for one company, province code => number or null.
     *
     * Always returns a key for every supported province, so a caller rendering a list gets a
     * stable set of rows rather than one that changes shape with the data.
     *
     * @return array<string, string|null>
     */
    public function all(int $companyId): array
    {
        $out = [];
        foreach (array_keys(self::PROVINCES) as $province) {
            $out[$province] = $this->get($companyId, $province);
        }

        return $out;
    }

    /** The human-readable label for a province's registration, or null if it has none. */
    public function label(string $province): ?string
    {
        return self::PROVINCES[$this->normalize($province)]['label'] ?? null;
    }

    private function definition(string $province): ?CustomFieldDefinition
    {
        $slug = self::PROVINCES[$this->normalize($province)]['slug'] ?? null;
        if ($slug === null) {
            return null;
        }

        return $this->definitionRepo->findBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY, $slug);
    }

    /**
     * Addresses store province codes, so this is normally a pass-through. It still resolves a
     * legacy display name ("Saskatchewan") the same way TaxContext does, so a caller reading an
     * unconverted address cannot silently look up the wrong province and get null.
     */
    private function normalize(string $province): string
    {
        return RegionSeedData::resolveProvinceAnyCountry($province);
    }
}
