<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Maxeme\Dto\PartData;
use App\Maxeme\Dto\RestockData;
use App\Maxeme\Entity\InventoryHistory;
use App\Maxeme\Entity\Part;
use App\Maxeme\Enum\PartType;
use App\Maxeme\Service\PartService;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;

/** Every change of a part's quantity leaves a stock history row (StockLedger). */
final class PartServiceTest extends DoctrineIntegrationTestCase
{
    private PartService $parts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parts = self::getContainer()->get(PartService::class);
    }

    public function testEveryQuantityChangeIsRecorded(): void
    {
        $part = new Part();
        $this->parts->save($part, PartData::fromRequest(new Request(request: ['name' => 'Oil filter', 'type' => 'kit', 'unit_price' => '5.25', 'sale_price' => '7.80', 'quantity' => '5'])));
        self::assertSame(PartType::Kit, $part->getType());

        self::assertSame([], $this->parts->saveField($part, 'quantity', '12'));

        $restock = RestockData::fromRequest(new Request(request: ['po_number' => 'PO-7', 'quantity' => '-2', 'note' => 'Used']));
        $this->parts->restock($part, $restock);

        self::assertSame(10, $part->getQuantity());
        self::assertSame(
            [[5, 'Opening stock', null], [7, 'Quantity edited', null], [-2, 'Used', 'PO-7']],
            array_map(
                static fn (InventoryHistory $row): array => [$row->getQuantity(), $row->getNote(), $row->getPoNumber()],
                $this->em->getRepository(InventoryHistory::class)->findBy(['part' => $part], ['id' => 'ASC']),
            ),
        );
    }

    public function testAnInvalidCellIsRefusedAndNothingChanges(): void
    {
        $part = new Part();
        $this->parts->save($part, PartData::fromRequest(new Request(request: ['name' => 'Pads', 'sale_price' => '40'])));

        self::assertArrayHasKey('salePrice', $this->parts->saveField($part, 'sale_price', 'forty'));
        $this->em->refresh($part);
        self::assertSame(40.0, (float) $part->getSalePrice());
    }

    public function testAPhysicalCountRecordsOnlyTheDifferences(): void
    {
        $filters = new Part();
        $pads = new Part();
        $wipers = new Part();
        $this->parts->save($filters, PartData::fromRequest(new Request(request: ['name' => 'Oil filter', 'quantity' => '10'])));
        $this->parts->save($pads, PartData::fromRequest(new Request(request: ['name' => 'Pads', 'quantity' => '4'])));
        $this->parts->save($wipers, PartData::fromRequest(new Request(request: ['name' => 'Wipers', 'quantity' => '6'])));

        $result = $this->parts->recordCount([
            $filters->getId() => '12',  // 2 more on the shelf
            $pads->getId() => '1',      // 3 missing
            $wipers->getId() => '',     // not counted
            999999 => '5',              // no such part
        ], 'SHEET-9');

        self::assertSame([12, 1, 6], [$filters->getQuantity(), $pads->getQuantity(), $wipers->getQuantity()]);
        self::assertSame([2, 2, 3], [$result->counted, $result->surplus, $result->shortfall]);
        self::assertSame([$filters, $pads], $result->changed);

        $latest = $this->em->getRepository(InventoryHistory::class)->findOneBy(['part' => $pads], ['id' => 'DESC']);
        self::assertSame([-3, 'Physical count SHEET-9'], [$latest->getQuantity(), $latest->getNote()]);

        $agreeing = $this->parts->recordCount([$filters->getId() => '12'], null);
        self::assertSame([1, []], [$agreeing->counted, $agreeing->changed]);
        self::assertStringContainsString('everything agreed', $agreeing->summary());
    }
}
