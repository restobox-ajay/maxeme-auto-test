<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CompanyAddress;
use App\Entity\EstimateAddress;
use App\Entity\SalesOrderAddress;
use App\Service\TextInput;
use PHPUnit\Framework\TestCase;

/**
 * The copy paths cap delivery instructions too, and this is the case that makes that necessary
 * rather than merely tidy.
 *
 * Every write path bounds the field now, but nothing did before, and both columns are CLOBs with no
 * normalising migration behind them — so an address row created before this change can still be
 * holding a value of any size. Without a cap here the bound would be a rule about new input only,
 * and one legacy address row would keep stamping fresh over-length snapshots onto every new order
 * placed against it: an uncapped value entering a document with nobody having typed it.
 */
final class DocumentAddressDeliveryInstructionsTest extends TestCase
{
    public function testCopyFromTruncatesAnOverLengthLegacyAddressValue(): void
    {
        // Set on the entity directly, the way a row written before the cap existed looks when it is
        // hydrated back out — no controller in the path to bound it.
        $legacy = (new CompanyAddress())->setDeliveryInstructions(str_repeat('L', 2000));

        $snapshot = (new SalesOrderAddress())->copyFrom($legacy);

        self::assertSame(
            TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH,
            strlen((string) $snapshot->getDeliveryInstructions())
        );
    }

    public function testCopyFromLeavesAValueUnderTheCapAlone(): void
    {
        $address = (new CompanyAddress())->setDeliveryInstructions('Back entrance, closed after 3pm.');

        $snapshot = (new SalesOrderAddress())->copyFrom($address);

        self::assertSame('Back entrance, closed after 3pm.', $snapshot->getDeliveryInstructions());
    }

    /**
     * Estimate-to-order conversion copies snapshot to snapshot rather than re-resolving the address
     * book, so a quote written before the cap carries its own over-length value into the order it
     * becomes unless this path bounds it as well.
     */
    public function testCopyFromSnapshotTruncatesAnOverLengthLegacySnapshot(): void
    {
        $legacyQuoteAddress = (new EstimateAddress())->setDeliveryInstructions(str_repeat('Q', 2000));

        $orderAddress = (new SalesOrderAddress())->copyFromSnapshot($legacyQuoteAddress);

        self::assertSame(
            TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH,
            strlen((string) $orderAddress->getDeliveryInstructions())
        );
    }

    public function testCopyFromSnapshotLeavesAValueUnderTheCapAlone(): void
    {
        $quoteAddress = (new EstimateAddress())->setDeliveryInstructions('Ring the bell twice.');

        $orderAddress = (new SalesOrderAddress())->copyFromSnapshot($quoteAddress);

        self::assertSame('Ring the bell twice.', $orderAddress->getDeliveryInstructions());
    }
}
