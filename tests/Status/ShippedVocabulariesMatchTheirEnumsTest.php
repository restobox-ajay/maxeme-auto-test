<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Contract\Status\StatusVocabularyLoaderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every enum case exists in its vocabulary, and every vocabulary status has an enum case
 * (handoff section 5, check 1).
 *
 * ## Why the enums were kept
 *
 * This reverses a ruling that stood earlier in the design: the enums stay, stripped to slugs, as the
 * FROZEN CORE CONTRACT. The owner's reason is that the core statuses must not move, because scripts
 * depend on them, and an enum is a good way to reinforce that. That is not a softening of the
 * argument against two sources of truth — it is a different job for the enum, and the job is exactly
 * one thing: declaring the core slugs.
 *
 * Everything at runtime comes from the vocabulary — statuses, labels, transitions, derived flags.
 * The enum is not a fixture anybody keeps in step; it is pinned on purpose, and the only reason to
 * edit one is a deliberate, reviewed change to the core contract. This test is what makes that true
 * rather than aspirational.
 *
 * ## Both directions are errors, and one of them only for now
 *
 * An enum case missing from its vocabulary is a status the contract promises and the app cannot
 * reach. A vocabulary status with no enum case is also an error **for now**, because this phase is
 * shape-only and adds no statuses; it stops being one the day customers can define their own.
 *
 * ## It derives its subjects
 *
 * The vocabulary key gives the enum name by convention — `credit_memo` -> `CreditMemoStatus` — and
 * the enum is looked for in core's namespace and in each module's. So a vocabulary added tomorrow is
 * checked tomorrow, and there is no list for the eleventh document to be left out of. Section 5 asks
 * for exactly that: *"enumerating the enums rather than naming them"*.
 */
final class ShippedVocabulariesMatchTheirEnumsTest extends KernelTestCase
{
    /**
     * The namespaces a status enum may live in: core's, and one per module. Derived from the module
     * directories rather than listed, so a new bundle's enum is found without editing this.
     *
     * @return list<string>
     */
    private function enumNamespaces(): array
    {
        $namespaces = ['App\\Enum\\'];

        foreach (glob(dirname(__DIR__, 2) . '/modules/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $namespaces[] = basename($moduleDir) . '\\Enum\\';
        }

        return $namespaces;
    }

    /** `credit_memo` -> `CreditMemoStatus`. */
    private function enumNameFor(string $vocabularyKey): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $vocabularyKey))) . 'Status';
    }

    /** @return class-string<\BackedEnum>|null */
    private function enumClassFor(string $vocabularyKey): ?string
    {
        $shortName = $this->enumNameFor($vocabularyKey);

        foreach ($this->enumNamespaces() as $namespace) {
            $candidate = $namespace . $shortName;
            if (enum_exists($candidate)) {
                /** @var class-string<\BackedEnum> $candidate */
                return $candidate;
            }
        }

        return null;
    }

    public function testTheConventionFindsAnEnumForEveryShippedVocabulary(): void
    {
        self::bootKernel();
        $loader = self::getContainer()->get(StatusVocabularyLoaderInterface::class);
        self::assertInstanceOf(StatusVocabularyLoaderInterface::class, $loader);

        $missing = [];
        foreach (array_keys($loader->getVocabularies()) as $key) {
            if ($this->enumClassFor($key) === null) {
                $missing[] = sprintf('%s (looked for %s)', $key, $this->enumNameFor($key));
            }
        }

        self::assertSame(
            [],
            $missing,
            'Every status vocabulary must have an enum declaring its core slugs. Missing: '
                . implode(', ', $missing),
        );
    }

    public function testEveryEnumCaseExistsInItsVocabulary(): void
    {
        self::bootKernel();
        $loader = self::getContainer()->get(StatusVocabularyLoaderInterface::class);
        self::assertInstanceOf(StatusVocabularyLoaderInterface::class, $loader);

        $checked = 0;
        $problems = [];

        foreach ($loader->getVocabularies() as $key => $vocabulary) {
            $enumClass = $this->enumClassFor($key);
            if ($enumClass === null) {
                continue; // reported by the test above; not re-reported here
            }

            ++$checked;

            $enumSlugs = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enumClass::cases());
            $vocabularySlugs = $vocabulary->slugs();

            foreach (array_diff($enumSlugs, $vocabularySlugs) as $orphanCase) {
                $problems[] = sprintf(
                    '%s::%s is "%s", which the "%s" vocabulary does not have.',
                    $enumClass,
                    (string) $orphanCase,
                    (string) $orphanCase,
                    $key,
                );
            }

            foreach (array_diff($vocabularySlugs, $enumSlugs) as $orphanStatus) {
                $problems[] = sprintf(
                    'The "%s" vocabulary has "%s", which %s does not declare. This phase is shape-only and'
                        . ' adds no statuses.',
                    $key,
                    $orphanStatus,
                    $enumClass,
                );
            }
        }

        // The positive control: an assertion that found nothing to check would otherwise pass.
        self::assertGreaterThanOrEqual(10, $checked, 'the shipped vocabularies should all have been checked');
        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * The vocabularies really are the ones the app ships, collected from BOTH providers — core's and
     * the bundle's. A loader that had quietly collected only core would still pass every assertion
     * above, because it would have nothing wrong left in it.
     */
    public function testBothCoreAndTheBundleContributed(): void
    {
        self::bootKernel();
        $loader = self::getContainer()->get(StatusVocabularyLoaderInterface::class);
        self::assertInstanceOf(StatusVocabularyLoaderInterface::class, $loader);

        $keys = array_keys($loader->getVocabularies());

        self::assertContains('invoice', $keys, 'core declares the sell side');
        self::assertContains('purchase_order', $keys, 'ProcurementBundle declares the buy side');
        self::assertNotContains('rfq', $keys, 'Rfq is parked on #662 and must not get a vocabulary yet');
    }
}
