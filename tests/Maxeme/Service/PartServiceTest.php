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
}
