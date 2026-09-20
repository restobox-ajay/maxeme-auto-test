<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CustomFieldDefinition;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use Symfony\Component\HttpFoundation\Request;

final class CustomFieldRenderer
{
    public const CONTEXT_ADD = 'add';
    public const CONTEXT_EDIT = 'edit';

    public function __construct(
        private readonly CustomFieldDefinitionRepository $definitionRepo,
        private readonly CustomFieldValueRepository $valueRepo,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    /**
     * The form fragment for one object type, in one context.
     *
     * ## $submitted, and why NOTHING passes it yet
     *
     * Every caller in the application today re-renders a refused save by REDIRECTING back to the
     * form, which re-reads the stored values — so every one of them wants the default and passes
     * nothing. The exception is the standalone invoice create screen, which re-renders ITSELF in
     * answer to a refusal (InvoiceController::renderStandaloneForm() is the single funnel for all
     * six of its re-render paths). That screen is being restructured on another branch and is
     * deliberately not wired to custom fields here; when it is, it is the one caller that must pass
     * the Request, because without it the inputs come back holding the STORED value — or nothing at
     * all on an add — and a refusal over an unrelated field silently bins every custom field the
     * admin had just filled in.
     *
     * So this parameter is the mechanism that screen will need, sitting here rather than being
     * rediscovered: it is opt-in, defaults to null, and with nothing passed the behaviour is
     * byte-identical to what the order, product, company and category screens have always had.
     */
    public function renderFields(string $objectType, ?object $entity, string $context, ?Request $submitted = null): string
    {
        $objectId = $this->extractObjectId($entity);
        // Read off the whole bag rather than through all('custom_field'), which THROWS when the key
        // is present but not an array. A re-render must not turn a malformed post into a 400 on a
        // page that was about to state a perfectly good refusal.
        $submittedValues = $submitted !== null && is_array($submitted->request->all()['custom_field'] ?? null)
            ? $submitted->request->all()['custom_field']
            : null;
        // Category is only ever known for a real ProductCore — a brand-new (not-yet-saved)
        // product on the "add" form has no category yet either, same as any other entity type
        // (company/order/...) which has no concept of category scoping at all. In both cases,
        // only universal (categoryId === null) definitions render — category-scoped fields only
        // start appearing once the product has actually been assigned that category and saved.
        $categoryId = $entity instanceof ProductCore ? $entity->getCategory()?->getId() : null;

        $html = '';
        foreach ($this->definitionRepo->findByObjectType($objectType) as $definition) {
            if ($this->isFromInactiveBundle($definition)) {
                continue;
            }

            $definitionCategory = $definition->getCategory();
            if ($definitionCategory !== null && $definitionCategory->getId() !== $categoryId) {
                continue;
            }

            $visible = $context === self::CONTEXT_ADD ? $definition->isVisibleOnAdd() : $definition->isVisibleOnEdit();
            if (!$visible) {
                continue;
            }

            $value = $objectId !== null ? ($this->valueRepo->getValue($definition, $objectId) ?? '') : '';
            // What was typed wins over what is stored, on a re-render that was handed the post.
            // array_key_exists, not ??: a box deliberately emptied posts '' and must come back
            // empty rather than repainting the value the admin just cleared.
            if ($submittedValues !== null && array_key_exists($definition->getSlug(), $submittedValues)) {
                $value = trim((string) $submittedValues[$definition->getSlug()]);
            }

            $html .= $this->renderField($definition, $value);
        }

        return $html;
    }

    public function saveFromRequest(string $objectType, object $entity, Request $request): void
    {
        $objectId = $this->extractObjectId($entity);
        if ($objectId === null) {
            return;
        }

        $submitted = $request->request->all('custom_field');
        foreach ($this->definitionRepo->findByObjectType($objectType) as $definition) {
            $slug = $definition->getSlug();
            if (!array_key_exists($slug, $submitted)) {
                continue;
            }

            $value = trim((string) $submitted[$slug]);
            $this->valueRepo->setValue($definition, $objectId, $value === '' ? null : $value);
        }
    }

    public function renderListingFragment(ProductCore $product): string
    {
        $objectId = $product->getId();
        if ($objectId === null) {
            return '';
        }

        $categoryId = $product->getCategory()?->getId();

        $parts = [];
        foreach ($this->definitionRepo->findByObjectType(CustomFieldDefinition::OBJECT_TYPE_PRODUCT) as $definition) {
            if ($this->isFromInactiveBundle($definition)) {
                continue;
            }

            $definitionCategory = $definition->getCategory();
            if ($definitionCategory !== null && $definitionCategory->getId() !== $categoryId) {
                continue;
            }

            if (!$definition->isVisibleOnListing()) {
                continue;
            }

            $value = $this->valueRepo->getValue($definition, $objectId);
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = sprintf(
                '<div class="custom-field-listing-item"><strong>%s:</strong> %s</div>',
                htmlspecialchars($definition->getLabel(), ENT_QUOTES),
                htmlspecialchars($value, ENT_QUOTES)
            );
        }

        return implode('', $parts);
    }

    private function isFromInactiveBundle(CustomFieldDefinition $definition): bool
    {
        $source = $definition->getSource();

        return $source !== null && !$this->bundleStatusRepo->isActive($source);
    }

    private function extractObjectId(?object $entity): ?int
    {
        if ($entity === null || !method_exists($entity, 'getId')) {
            return null;
        }

        $id = $entity->getId();

        return is_int($id) ? $id : null;
    }

    private function renderField(CustomFieldDefinition $definition, string $value): string
    {
        $label = htmlspecialchars($definition->getLabel(), ENT_QUOTES);
        $name = htmlspecialchars($definition->getSlug(), ENT_QUOTES);
        $escapedValue = htmlspecialchars($value, ENT_QUOTES);

        return match ($definition->getFieldType()) {
            CustomFieldDefinition::FIELD_TYPE_TEXTAREA => <<<HTML
                <label>
                    {$label}
                    <textarea name="custom_field[{$name}]">{$escapedValue}</textarea>
                </label>
                HTML,
            CustomFieldDefinition::FIELD_TYPE_NUMBER => <<<HTML
                <label>
                    {$label}
                    <input type="number" step="any" name="custom_field[{$name}]" value="{$escapedValue}">
                </label>
                HTML,
            CustomFieldDefinition::FIELD_TYPE_CHECKBOX => <<<HTML
                <label>
                    <input type="hidden" name="custom_field[{$name}]" value="0">
                    <input type="checkbox" name="custom_field[{$name}]" value="1" {$this->checkedAttr($value)}>
                    {$label}
                </label>
                HTML,
            CustomFieldDefinition::FIELD_TYPE_SELECT => $this->renderSelect($definition, $label, $name, $value),
            default => <<<HTML
                <label>
                    {$label}
                    <input type="text" name="custom_field[{$name}]" value="{$escapedValue}">
                </label>
                HTML,
        };
    }

    private function renderSelect(CustomFieldDefinition $definition, string $label, string $name, string $value): string
    {
        $optionsHtml = '';
        foreach ($definition->getOptions() ?? [] as $option) {
            $escapedOption = htmlspecialchars($option, ENT_QUOTES);
            $selected = $option === $value ? ' selected' : '';
            $optionsHtml .= "<option value=\"{$escapedOption}\"{$selected}>{$escapedOption}</option>";
        }

        return <<<HTML
            <label>
                {$label}
                <select name="custom_field[{$name}]">
                    <option value=""></option>
                    {$optionsHtml}
                </select>
            </label>
            HTML;
    }

    private function checkedAttr(string $value): string
    {
        return $value === '1' ? 'checked' : '';
    }
}
