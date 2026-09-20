<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Entity\AbstractCustomFieldValue;
use App\Service\CustomField\CustomFieldObjectTypeCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every entity extending `AbstractCustomFieldValue` is registered with a matching
 * `CustomFieldObjectTypeProviderInterface` entry, or it is unreachable (#745).
 *
 * ## What this replaces
 *
 * Before #745, the same seven mappings were named twice by hand — once in
 * `CustomFieldValueRepository::MAP`, once in `CustomFieldController::OBJECT_TYPES` — and a class
 * that extended `AbstractCustomFieldValue` without an entry in both was simply dead code: mapped,
 * migrated, and never reachable through `CustomFieldValueRepository`, because nothing told it which
 * target entity or association property the new table used. Vendor could not join the seven without
 * core naming `ProcurementBundle\Entity\Vendor`, which is the layering violation this repository is
 * built to avoid.
 *
 * ## Why this discovers subjects instead of listing them
 *
 * A test naming today's eight `CustomFieldValue*` classes passes forever while a ninth is added and
 * forgets to register — the same failure #615 found waiting in `CustomFieldController::OBJECT_TYPES`
 * for `credit_memo_number_prefix` and `sales_return_number_prefix`. So the subjects come from
 * Doctrine's own metadata: any class in `src/Entity` or a bundle's `src/Entity` that extends
 * `AbstractCustomFieldValue` is a subject the moment it is mapped, whether this test's author knew
 * about it or not.
 */
final class EveryCustomFieldValueEntityIsRegisteredTest extends KernelTestCase
{
    public function testEveryValueEntityHasAMatchingProvider(): void
    {
        $valueClasses = $this->customFieldValueClasses();

        self::bootKernel();
        $registered = array_map(
            static fn ($type): string => $type->valueEntityClass,
            self::getContainer()->get(CustomFieldObjectTypeCatalogue::class)->all(),
        );

        $offenders = array_values(array_diff($valueClasses, $registered));

        self::assertSame([], $offenders, sprintf(
            "These entities extend AbstractCustomFieldValue but no CustomFieldObjectTypeProviderInterface"
            . " declares them:\n  %s\n\nCustomFieldRenderer can only save and render a field whose object"
            . " type resolves through the catalogue — see AbstractCustomFieldValue's docblock and"
            . " CustomFieldObjectTypeProviderInterface for the four things a provider must declare"
            . " (key, label, this class, the target entity class, the association property).",
            implode("\n  ", $offenders),
        ));
    }

    /**
     * The reverse direction: nothing in the catalogue points at a value-entity class that does not
     * exist or does not actually extend AbstractCustomFieldValue. A provider that names the wrong
     * class would make CustomFieldValueRepository construct something that cannot hold a value at
     * all, and that mistake should fail here rather than the first time a value is saved.
     */
    public function testNothingInTheCatalogueNamesAnUnmappedOrWrongShapedClass(): void
    {
        $valueClasses = $this->customFieldValueClasses();

        self::bootKernel();
        $types = self::getContainer()->get(CustomFieldObjectTypeCatalogue::class)->all();

        foreach ($types as $type) {
            self::assertContains($type->valueEntityClass, $valueClasses, sprintf(
                'CustomFieldObjectType "%s" names %s as its value entity, but that class is not a'
                . ' mapped AbstractCustomFieldValue subclass — check the class exists and is spelled'
                . ' correctly in its provider.',
                $type->key,
                $type->valueEntityClass,
            ));
        }
    }

    /**
     * Guards the discovery itself, same reasoning as `EveryDocumentDeclaresItsContractTest`'s own
     * guard: a subject list that quietly empties out would pass both tests above instantly and
     * prove nothing.
     */
    public function testTheDiscoveryFindsValueEntitiesToCheck(): void
    {
        $valueClasses = $this->customFieldValueClasses();

        self::assertNotEmpty($valueClasses, 'No AbstractCustomFieldValue subclasses discovered at all — the mapping changed under this test.');
        self::assertGreaterThanOrEqual(8, count($valueClasses), 'Fewer CustomFieldValue* classes than the seven core ones plus Vendor — one was removed or the mapping is incomplete.');
    }

    /**
     * Every mapped class extending AbstractCustomFieldValue, discovered from Doctrine — core's and
     * every bundle's.
     *
     * @return list<class-string>
     */
    private function customFieldValueClasses(): array
    {
        self::bootKernel();

        $factory = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory();

        $classes = [];
        foreach ($factory->getAllMetadata() as $metadata) {
            if (!$metadata instanceof ClassMetadata) {
                continue;
            }

            $name = $metadata->getName();
            if (is_subclass_of($name, AbstractCustomFieldValue::class)) {
                $classes[] = $name;
            }
        }

        sort($classes);

        return $classes;
    }
}
