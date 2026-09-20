<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Status\StatusVocab;
use PHPUnit\Framework\TestCase;

/**
 * The dictionary itself: slug/label split, the derived flag, and what `allows()` will and will not
 * answer (handoff sections 3, 5 and 9).
 */
final class StatusVocabTest extends TestCase
{
    private function vocab(): StatusVocab
    {
        return StatusVocab::fromArray('sales_order', [
            'statuses' => [
                'Draft' => ['label' => 'Draft', 'derived' => true],
                'Approved' => ['label' => 'Approved', 'derived' => true],
                // The one place slug and label differ, so that every assertion below can tell which
                // of the two it got. Today every real vocabulary has them equal, which is exactly
                // why a screen printing the slug looks correct and fails the moment one diverges.
                'Partially Invoiced' => ['label' => 'Part-invoiced'],
                'Void' => 'Void',
            ],
            'transitions' => [
                'Draft' => ['Approved', 'Void'],
                'Approved' => ['Partially Invoiced', 'Void'],
                'Partially Invoiced' => ['Void'],
                'Void' => [],
            ],
        ]);
    }

    public function testLabelsAreSeparateFromSlugsAndBothAreAvailable(): void
    {
        $vocab = $this->vocab();

        self::assertSame('Part-invoiced', $vocab->labelFor('Partially Invoiced'));
        // The control on the same call: a status whose label really does equal its slug still
        // returns the label, so the assertion above is about the split and not about the lookup.
        self::assertSame('Draft', $vocab->labelFor('Draft'));

        self::assertSame(
            ['Draft' => 'Draft', 'Approved' => 'Approved', 'Partially Invoiced' => 'Part-invoiced', 'Void' => 'Void'],
            $vocab->labels(),
            'a dropdown needs the slug as the value and the label as the text, so both must survive',
        );
    }

    public function testAnUnknownStatusThrowsRatherThanReturningFalse(): void
    {
        $vocab = $this->vocab();

        // The typo guard. A raw === 'Draftt' would silently be false and take the wrong branch.
        self::assertTrue($vocab->has('Draft'), 'positive control on the same method');
        self::assertFalse($vocab->has('Draftt'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('There is no status "Draftt" in the "sales_order" vocabulary.');

        $vocab->labelFor('Draftt');
    }

    public function testTheDerivedFlagIsPerStatusAndDoesNotRemoveTheMoveFromTheMap(): void
    {
        $vocab = $this->vocab();

        self::assertTrue($vocab->isDerived('Approved'));
        self::assertFalse($vocab->isDerived('Void'));

        // Approved is derived AND is what approve() writes. It must still be offered as a move out
        // of Draft, carrying its flag — leaving it out would make the map lie about what is legal.
        $moves = $vocab->transitionsFrom('Draft');
        self::assertSame(['Approved', 'Void'], array_keys($moves));
        self::assertTrue($moves['Approved']['derived']);
        self::assertFalse($moves['Void']['derived']);
    }

    public function testAllowsAnswersOnlyTheStatusQuestion(): void
    {
        $vocab = $this->vocab();

        self::assertTrue($vocab->allows('Draft', 'Approved'));
        self::assertFalse($vocab->allows('Draft', 'Partially Invoiced'));
        self::assertFalse($vocab->allows('Void', 'Draft'), 'Void is final');
    }

    /**
     * A no-op is legal. This is what keeps a recalculation on every flush that happened to touch the
     * document from being a refusal — and it is the behaviour the deriver depends on.
     */
    public function testAMoveToTheStatusAlreadyHeldIsLegal(): void
    {
        $vocab = $this->vocab();

        self::assertTrue($vocab->allows('Void', 'Void'), 'even out of a final status');
        self::assertTrue($vocab->allows('Draft', 'Draft'));
        // The control: the self-move being legal must not have made everything legal.
        self::assertFalse($vocab->allows('Void', 'Approved'));
    }

    /**
     * You can always leave a place that no longer exists; you just cannot go back to it.
     *
     * The guard this replaces read `!has($from) || !allows($from, $to)`, so a document holding a
     * value the vocabulary no longer knew had every move out of it refused before the target was
     * even considered — it could not be transitioned by any means and could not be saved at all,
     * because the save path reaches that guard through the deriver.
     */
    public function testAStatusThisVocabularyDoesNotKnowMayBeLeftForOneItDoes(): void
    {
        $vocab = $this->vocab();

        self::assertFalse($vocab->has('Processing'), 'guard: the from-state really is a stranger here');

        self::assertTrue($vocab->allows('Processing', 'Draft'));
        self::assertTrue($vocab->allows('Processing', 'Void'), 'including into a final status');

        // The control on the same method: the rule is about the FROM being unknown, not about
        // allows() having been made to answer true for everybody.
        self::assertFalse($vocab->allows('Void', 'Draft'), 'Void is a place the vocabulary knows, and it is final');
    }

    /**
     * The other direction is NOT relaxed: nothing transitions INTO a status this vocabulary does not
     * have. $to is always the caller's argument, so it stays the typo guard's throw.
     */
    public function testNothingTransitionsIntoAStatusThisVocabularyDoesNotKnow(): void
    {
        $vocab = $this->vocab();

        self::assertTrue($vocab->allows('Draft', 'Approved'), 'positive control on the same method');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('There is no status "Processing" in the "sales_order" vocabulary.');

        $vocab->allows('Draft', 'Processing');
    }

    /**
     * The picker half of the same ruling. An empty list reads as "final" to everything that consumes
     * it, so a screen built from one would offer a stranded document no way off its value.
     */
    public function testAnUnknownStatusIsOfferedEveryKnownStatusAsAWayOut(): void
    {
        $vocab = $this->vocab();

        $moves = $vocab->transitionsFrom('Processing');

        self::assertSame(['Draft', 'Approved', 'Partially Invoiced', 'Void'], array_keys($moves));
        self::assertSame('Part-invoiced', $moves['Partially Invoiced']['label'], 'both halves still travel with it');
        self::assertTrue($moves['Approved']['derived'], 'and so does the flag');

        // The control on the same method: a recognised final status still offers nothing, so the
        // list above is the unknown-status rule and not "everything for everybody".
        self::assertSame([], $vocab->transitionsFrom('Void'));
    }

    public function testABareStringIsShorthandForAnUndervivedLabel(): void
    {
        $vocab = StatusVocab::fromArray('tiny', ['statuses' => ['Open' => 'Open']]);

        self::assertSame('Open', $vocab->labelFor('Open'));
        self::assertFalse($vocab->isDerived('Open'));
        self::assertSame([], $vocab->transitionsFrom('Open'), 'no transitions declared means none legal');
    }

    public function testAnEmptyLabelIsRefusedAtBoot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Status "Open" in vocabulary "tiny" has an empty label.');

        StatusVocab::fromArray('tiny', ['statuses' => ['Open' => ['label' => '  ']]]);
    }

    public function testSlugsKeepDeclarationOrder(): void
    {
        // Order is what a filter bar renders in, so it is part of the contract rather than incidental.
        self::assertSame(['Draft', 'Approved', 'Partially Invoiced', 'Void'], $this->vocab()->slugs());
    }
}
