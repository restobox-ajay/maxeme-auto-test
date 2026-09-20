<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CustomFieldDefinition;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use App\Service\CustomFieldRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CustomFieldRendererTest extends TestCase
{
    private function definition(
        string $slug,
        string $label,
        string $fieldType = CustomFieldDefinition::FIELD_TYPE_TEXT,
        ?array $options = null,
        bool $visibleOnAdd = true,
        bool $visibleOnEdit = true,
        bool $visibleOnListing = false,
        ?string $source = null,
        ?ProductCategory $category = null,
    ): CustomFieldDefinition {
        return (new CustomFieldDefinition())
            ->setObjectType(CustomFieldDefinition::OBJECT_TYPE_PRODUCT)
            ->setSlug($slug)
            ->setLabel($label)
            ->setFieldType($fieldType)
            ->setOptions($options)
            ->setVisibleOnAdd($visibleOnAdd)
            ->setVisibleOnEdit($visibleOnEdit)
            ->setVisibleOnListing($visibleOnListing)
            ->setSource($source)
            ->setCategory($category);
    }

    private function category(int $id): ProductCategory
    {
        $category = $this->createStub(ProductCategory::class);
        $category->method('getId')->willReturn($id);

        return $category;
    }

    private function product(?int $id, ?ProductCategory $category = null): ProductCore
    {
        $product = $this->createStub(ProductCore::class);
        $product->method('getId')->willReturn($id);
        $product->method('getCategory')->willReturn($category);

        return $product;
    }

    /** @param CustomFieldDefinition[] $definitions */
    private function definitionRepo(array $definitions): CustomFieldDefinitionRepository
    {
        $repo = $this->createStub(CustomFieldDefinitionRepository::class);
        $repo->method('findByObjectType')->willReturn($definitions);

        return $repo;
    }

    private function activeBundleRepo(): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturn(true);

        return $repo;
    }

    private function renderer(
        array $definitions,
        ?CustomFieldValueRepository $valueRepo = null,
        ?BundleStatusRepository $bundleStatusRepo = null,
    ): CustomFieldRenderer {
        return new CustomFieldRenderer(
            $this->definitionRepo($definitions),
            $valueRepo ?? $this->createStub(CustomFieldValueRepository::class),
            $bundleStatusRepo ?? $this->activeBundleRepo(),
        );
    }

    public function testRenderFieldsRendersTextInputWithEscapedLabelAndBlankValueWhenNoEntity(): void
    {
        $valueRepo = $this->createMock(CustomFieldValueRepository::class);
        $valueRepo->expects(self::never())->method('getValue');

        $renderer = $this->renderer([$this->definition('sku_note', 'SKU <Note>')], $valueRepo);

        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, null, CustomFieldRenderer::CONTEXT_ADD);

        self::assertStringContainsString('SKU &lt;Note&gt;', $html);
        self::assertStringContainsString('name="custom_field[sku_note]"', $html);
        self::assertStringContainsString('type="text"', $html);
        self::assertStringContainsString('value=""', $html);
    }

    public function testRenderFieldsUsesStoredValueWhenEntityHasId(): void
    {
        $valueRepo = $this->createStub(CustomFieldValueRepository::class);
        $valueRepo->method('getValue')->willReturn('Stored & Value');

        $renderer = $this->renderer([$this->definition('note', 'Note')], $valueRepo);

        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $this->product(5), CustomFieldRenderer::CONTEXT_ADD);

        self::assertStringContainsString('value="Stored &amp; Value"', $html);
    }

    public function testRenderFieldsSkipsDefinitionFromInactiveBundle(): void
    {
        $bundleRepo = $this->createStub(BundleStatusRepository::class);
        $bundleRepo->method('isActive')->willReturn(false);

        $renderer = $this->renderer([$this->definition('note', 'Note', source: 'SomeBundle')], null, $bundleRepo);

        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, null, CustomFieldRenderer::CONTEXT_ADD);

        self::assertSame('', $html);
    }

    public function testRenderFieldsSkipsCategoryScopedDefinitionWhenEntityHasNoCategory(): void
    {
        $renderer = $this->renderer([$this->definition('note', 'Note', category: $this->category(1))]);

        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, null, CustomFieldRenderer::CONTEXT_ADD);

        self::assertSame('', $html);
    }

    public function testRenderFieldsSkipsCategoryScopedDefinitionWhenProductCategoryDiffers(): void
    {
        $renderer = $this->renderer([$this->definition('note', 'Note', category: $this->category(1))]);

        $product = $this->product(5, $this->category(2));
        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $product, CustomFieldRenderer::CONTEXT_ADD);

        self::assertSame('', $html);
    }

    public function testRenderFieldsRendersCategoryScopedDefinitionWhenProductCategoryMatches(): void
    {
        $category = $this->category(1);
        $renderer = $this->renderer([$this->definition('note', 'Note', category: $category)]);

        $product = $this->product(5, $category);
        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $product, CustomFieldRenderer::CONTEXT_ADD);

        self::assertStringContainsString('name="custom_field[note]"', $html);
    }

    public function testRenderFieldsRespectsAddVsEditVisibility(): void
    {
        $renderer = $this->renderer([$this->definition('note', 'Note', visibleOnAdd: false, visibleOnEdit: true)]);

        self::assertSame('', $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, null, CustomFieldRenderer::CONTEXT_ADD));
        self::assertStringContainsString(
            'name="custom_field[note]"',
            $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, null, CustomFieldRenderer::CONTEXT_EDIT),
        );
    }

    public function testRenderFieldsRendersTextarea(): void
    {
        $renderer = $this->renderer([$this->definition('bio', 'Bio', CustomFieldDefinition::FIELD_TYPE_TEXTAREA)]);

        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, null, CustomFieldRenderer::CONTEXT_ADD);

        self::assertStringContainsString('<textarea name="custom_field[bio]">', $html);
    }

    public function testRenderFieldsRendersNumberInput(): void
    {
        $renderer = $this->renderer([$this->definition('weight', 'Weight', CustomFieldDefinition::FIELD_TYPE_NUMBER)]);

        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, null, CustomFieldRenderer::CONTEXT_ADD);

        self::assertStringContainsString('type="number"', $html);
        self::assertStringContainsString('name="custom_field[weight]"', $html);
    }

    public function testRenderFieldsRendersCheckedCheckboxWhenValueIsOne(): void
    {
        $valueRepo = $this->createStub(CustomFieldValueRepository::class);
        $valueRepo->method('getValue')->willReturn('1');

        $renderer = $this->renderer([$this->definition('featured', 'Featured', CustomFieldDefinition::FIELD_TYPE_CHECKBOX)], $valueRepo);

        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $this->product(5), CustomFieldRenderer::CONTEXT_ADD);

        self::assertMatchesRegularExpression('/<input type="checkbox"[^>]*checked[^>]*>/', $html);
    }

    public function testRenderFieldsRendersUncheckedCheckboxWhenValueIsNotOne(): void
    {
        $renderer = $this->renderer([$this->definition('featured', 'Featured', CustomFieldDefinition::FIELD_TYPE_CHECKBOX)]);

        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, null, CustomFieldRenderer::CONTEXT_ADD);

        self::assertDoesNotMatchRegularExpression('/<input type="checkbox"[^>]*checked/', $html);
    }

    public function testRenderFieldsRendersSelectWithSelectedOption(): void
    {
        $valueRepo = $this->createStub(CustomFieldValueRepository::class);
        $valueRepo->method('getValue')->willReturn('Blue');

        $renderer = $this->renderer(
            [$this->definition('color', 'Color', CustomFieldDefinition::FIELD_TYPE_SELECT, ['Red', 'Blue'])],
            $valueRepo,
        );

        $html = $renderer->renderFields(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $this->product(5), CustomFieldRenderer::CONTEXT_ADD);

        self::assertStringContainsString('<option value="Red">Red</option>', $html);
        self::assertStringContainsString('<option value="Blue" selected>Blue</option>', $html);
    }

    public function testSaveFromRequestReturnsEarlyWhenEntityHasNoId(): void
    {
        $definitionRepo = $this->createMock(CustomFieldDefinitionRepository::class);
        $definitionRepo->expects(self::never())->method('findByObjectType');

        $valueRepo = $this->createMock(CustomFieldValueRepository::class);
        $valueRepo->expects(self::never())->method('setValue');

        $renderer = new CustomFieldRenderer($definitionRepo, $valueRepo, $this->activeBundleRepo());

        $renderer->saveFromRequest(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $this->product(null), new Request());
    }

    public function testSaveFromRequestSetsTrimmedValueAndNullsBlankSubmission(): void
    {
        $calls = [];
        $valueRepo = $this->createStub(CustomFieldValueRepository::class);
        $valueRepo->method('setValue')->willReturnCallback(
            function (CustomFieldDefinition $definition, int $objectId, ?string $value) use (&$calls): void {
                $calls[] = [$definition->getSlug(), $objectId, $value];
            },
        );

        $renderer = $this->renderer(
            [$this->definition('note', 'Note'), $this->definition('blank', 'Blank'), $this->definition('untouched', 'Untouched')],
            $valueRepo,
        );

        $request = new Request(request: ['custom_field' => ['note' => '  hello  ', 'blank' => '   ']]);
        $renderer->saveFromRequest(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $this->product(7), $request);

        self::assertSame([
            ['note', 7, 'hello'],
            ['blank', 7, null],
        ], $calls);
    }

    public function testRenderListingFragmentReturnsEmptyWhenProductHasNoId(): void
    {
        $definitionRepo = $this->createMock(CustomFieldDefinitionRepository::class);
        $definitionRepo->expects(self::never())->method('findByObjectType');

        $renderer = new CustomFieldRenderer($definitionRepo, $this->createStub(CustomFieldValueRepository::class), $this->activeBundleRepo());

        self::assertSame('', $renderer->renderListingFragment($this->product(null)));
    }

    public function testRenderListingFragmentSkipsInactiveBundleCategoryMismatchAndBlankValues(): void
    {
        $categoryA = $this->category(1);
        $categoryB = $this->category(2);

        $bundleRepo = $this->createStub(BundleStatusRepository::class);
        $bundleRepo->method('isActive')->willReturnMap([
            ['InactiveBundle', false],
            ['ActiveBundle', true],
        ]);

        $valueRepo = $this->createStub(CustomFieldValueRepository::class);
        $valueRepo->method('getValue')->willReturnCallback(
            fn (CustomFieldDefinition $definition, int $objectId): ?string => match ($definition->getSlug()) {
                'blank' => null,
                'visible' => 'Widget',
                default => null,
            },
        );

        $renderer = new CustomFieldRenderer(
            $this->definitionRepo([
                $this->definition('inactive', 'Inactive', visibleOnListing: true, source: 'InactiveBundle'),
                $this->definition('wrong_category', 'Wrong Category', visibleOnListing: true, category: $categoryB),
                $this->definition('not_listed', 'Not Listed', visibleOnListing: false),
                $this->definition('blank', 'Blank', visibleOnListing: true),
                $this->definition('visible', 'Visible', visibleOnListing: true, source: 'ActiveBundle'),
            ]),
            $valueRepo,
            $bundleRepo,
        );

        $product = $this->product(9, $categoryA);
        $html = $renderer->renderListingFragment($product);

        self::assertSame('<div class="custom-field-listing-item"><strong>Visible:</strong> Widget</div>', $html);
    }

    public function testRenderListingFragmentEscapesLabelAndValue(): void
    {
        $valueRepo = $this->createStub(CustomFieldValueRepository::class);
        $valueRepo->method('getValue')->willReturn('<b>Bold</b>');

        $renderer = $this->renderer(
            [$this->definition('note', 'Note <script>', visibleOnListing: true)],
            $valueRepo,
        );

        $html = $renderer->renderListingFragment($this->product(3));

        self::assertSame(
            '<div class="custom-field-listing-item"><strong>Note &lt;script&gt;:</strong> &lt;b&gt;Bold&lt;/b&gt;</div>',
            $html,
        );
    }
}
