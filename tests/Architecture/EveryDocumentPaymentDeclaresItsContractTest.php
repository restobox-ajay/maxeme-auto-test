<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Contract\Document\CommercialDocument;
use App\Contract\Payment\PayableDocument;
use App\Contract\Payment\PaymentApplication;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every claim against a commercial document implements {@see PaymentApplication}, or is excluded by
 * name and with a reason (queue item 34, #708).
 *
 * ## Built the same way as its older sibling, on purpose
 *
 * `App\Tests\Architecture\EveryDocumentDeclaresItsContractTest` is the pattern this repository
 * already uses for exactly this problem, and this is deliberately recognisable as the same thing
 * rather than a second approach to it: subjects DISCOVERED from Doctrine's own metadata, exclusions
 * as a small argued set each carrying its reason, and a mirror test that fails when an exclusion
 * stops being true. The next person reads one pattern, not two.
 *
 * ## Why discovery and not a list
 *
 * `docs/QUEUE.md` names a hand-kept list as the anti-pattern, and the history behind that is this
 * repository's own: `CommercialDocument` was written in core and implemented by two of the eight
 * documents that existed a year later, and nothing failed. A third kind of claim — a customer
 * deposit, a refund row, whatever the next document needs — must not be able to skip this quietly.
 * So the subjects come out of the mapping, and a payment application entity that did not exist when
 * this file was written is a subject the moment it is mapped.
 *
 * ## What counts as a payment application, and why the convention moved
 *
 * A mapped entity whose short name ends in `PaymentApplication` and which has a to-ONE association
 * to a class implementing {@see CommercialDocument}. Both halves are needed, for the reasons the
 * original version of this file gave for `*Payment`:
 *
 *  - the association alone would sweep in every line, log, tax row and charge row on every document;
 *  - the name alone would sweep in configuration that points at no document at all.
 *
 * #708 split what used to be one row — a payment, pointed straight at one document — into a POOL
 * (`InvoicePayment`, `VendorBillPayment`: money received or paid, scoped to a counterparty, with NO
 * to-one association to any document any more) and a CLAIM against one document for part of that
 * pool (`InvoicePaymentApplication`, `VendorBillPaymentApplication`). The pool is no longer what this
 * test is about — it cannot be moved, refused by a document, or read for a currency the way a claim
 * can, because it no longer knows which document to ask. So the convention that used to catch
 * `*Payment` now catches `*PaymentApplication` instead, deliberately narrower than `*Application`
 * alone would be: `CreditMemoApplication` also ends in `Application` and is not a payment claim, so
 * sweeping in every `*Application` would demand an interface of a class that was never meant to carry
 * it. The same answer applies to "what if somebody names theirs `FooRemittance`" as it always did:
 * the convention it broke is visible in review in a way a missing interface never was, and every
 * `*PaymentApplication` from that day on is still caught.
 *
 * ## Moved here from ProcurementBundle 2026-09-17
 *
 * The interfaces themselves moved to core the same day, once the core freeze lifted — this test
 * moved with them for the same reason the sibling document test lives in core: it now discovers
 * subjects across core AND every bundle, and a test about a core contract belongs beside it. The
 * one argued exception this file used to carry — `App\Entity\InvoicePayment`, TEMPORARILY unable to
 * implement an interface an optional bundle owned — is gone: that entity is a pool now and is no
 * longer a subject of this test at all, for the reason above.
 */
final class EveryDocumentPaymentDeclaresItsContractTest extends KernelTestCase
{
    /**
     * Payment application entities that state no money claimed against a commercial document, with
     * the reason each is exempt. Empty today — see the class docblock for why.
     *
     * @var array<class-string, string>
     */
    private const NOT_YET_SHARED = [];

    public function testEveryDocumentPaymentImplementsTheSharedContractOrIsAnArguedException(): void
    {
        $offenders = [];

        foreach ($this->paymentClasses() as $class) {
            if (is_a($class, PaymentApplication::class, true) || isset(self::NOT_YET_SHARED[$class])) {
                continue;
            }

            $offenders[] = $class;
        }

        self::assertSame([], $offenders, sprintf(
            "These payment applications implement neither %s nor an argued exception:\n  %s\n\n"
            . "Implement it if the row is a claim of money against a document: an id, an amount, the"
            . " document it applies to, and the date it was applied — plus the four delegated reads"
            . " (currency, method, reference, recorded by) taken from the pool behind it. Every one of"
            . " them must come from state the entity already stores — this is an interface change with"
            . " no migration behind it.\n\n"
            . "If it genuinely is not that, add it to NOT_YET_SHARED with a sentence saying what it"
            . " is instead, and put the same argument in the entity's own docblock. An exception"
            . " with no reason beside it is the silence this test exists to break.",
            PaymentApplication::class,
            implode("\n  ", $offenders),
        ));
    }

    /**
     * An exception that stopped being true is as bad as a missing one.
     */
    public function testTheArguedExceptionsAreStillExceptions(): void
    {
        $payments = $this->paymentClasses();

        foreach (self::NOT_YET_SHARED as $class => $why) {
            if (!class_exists($class)) {
                continue;
            }

            self::assertContains($class, $payments, sprintf(
                '%s is listed as a payment application that does not yet share the contract, but it is'
                . ' not a payment application any more (or was renamed). Remove the entry rather than'
                . ' leaving it to describe nothing.',
                $class,
            ));

            self::assertFalse(is_a($class, PaymentApplication::class, true), sprintf(
                '%s now implements %s, so the exception recorded here — "%s" — is out of date.'
                . ' Remove it from NOT_YET_SHARED.',
                $class,
                PaymentApplication::class,
                $why,
            ));

            self::assertNotSame('', trim($why), sprintf('The exception for %s has no reason beside it.', $class));
        }
    }

    /**
     * A claim's document declares {@see PayableDocument}, or the claim has nowhere legal to move to.
     *
     * The two interfaces are one idea in two halves — the money and the thing it is against — and a
     * claim that declared one without the other would satisfy the guard's signature and fail at the
     * first call. Checked for the implementors only: an excluded claim's document is excluded with
     * it, for the same reason and by the same freeze.
     */
    public function testEveryImplementingPaymentPointsAtAPayableDocument(): void
    {
        $checked = 0;

        foreach ($this->paymentClasses() as $class) {
            if (!is_a($class, PaymentApplication::class, true)) {
                continue;
            }

            foreach ($this->documentTargetsOf($class) as $target) {
                self::assertTrue(is_a($target, PayableDocument::class, true), sprintf(
                    '%s implements %s but the document it points at, %s, does not implement %s.'
                    . ' A claim that can be read but whose document cannot say whether it accepts'
                    . ' one, what currency it is in or who its counterparty is cannot be moved'
                    . ' anywhere: %s needs all three to decide.',
                    $class,
                    PaymentApplication::class,
                    $target,
                    PayableDocument::class,
                    'App\Payment\PaymentApplicationGuard',
                ));
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked, 'No implementing payment application was checked — the discovery found nothing to assert against.');
    }

    /**
     * Guards the discovery itself: a conformance test whose subject list quietly empties out passes
     * instantly and proves nothing.
     */
    public function testTheDiscoveryFindsPaymentsToCheck(): void
    {
        $classes = $this->paymentClasses();

        self::assertNotEmpty($classes, 'No document payment applications discovered at all — the mapping or the *PaymentApplication convention changed under this test.');

        self::assertContains('App\Entity\InvoicePaymentApplication', $classes, 'The sell side stopped being discovered as a document payment application.');
        self::assertContains('ProcurementBundle\Entity\VendorBillPaymentApplication', $classes, 'The buy side stopped being discovered as a document payment application.');

        $implementing = array_values(array_filter($classes, static fn (string $c): bool => is_a($c, PaymentApplication::class, true)));

        self::assertNotEmpty($implementing, 'Nothing implements PaymentApplication. An interface written and left unimplemented is how CommercialDocument spent its first year.');
    }

    /**
     * Every mapped payment application entity, discovered from Doctrine.
     *
     * @return list<class-string>
     */
    private function paymentClasses(): array
    {
        $classes = [];
        foreach ($this->allMetadata() as $metadata) {
            $name = $metadata->getName();

            if (!$this->isPaymentClass($name)) {
                continue;
            }

            if ($this->documentTargetsOf($name) === []) {
                continue;
            }

            $classes[] = $name;
        }

        sort($classes);

        return $classes;
    }

    /**
     * The commercial documents $class points at through a to-one association.
     *
     * @return list<class-string>
     */
    private function documentTargetsOf(string $class): array
    {
        $targets = [];

        foreach ($this->allMetadata() as $metadata) {
            if ($metadata->getName() !== $class) {
                continue;
            }

            foreach ($metadata->getAssociationMappings() as $mapping) {
                if (!$mapping->isToOne()) {
                    continue;
                }

                if (is_a($mapping->targetEntity, CommercialDocument::class, true)) {
                    $targets[] = $mapping->targetEntity;
                }
            }
        }

        return array_values(array_unique($targets));
    }

    /** @return list<ClassMetadata<object>> */
    private function allMetadata(): array
    {
        self::bootKernel();

        $factory = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory();

        $all = [];
        foreach ($factory->getAllMetadata() as $metadata) {
            if (!$metadata instanceof ClassMetadata || $metadata->isMappedSuperclass) {
                continue;
            }

            $all[] = $metadata;
        }

        return $all;
    }

    private function isPaymentClass(string $class): bool
    {
        $short = str_contains($class, '\\') ? substr((string) strrchr($class, '\\'), 1) : $class;

        return str_ends_with($short, 'PaymentApplication');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }
}
