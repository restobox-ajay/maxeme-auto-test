<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Entity\ServiceItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * For the pages' live totals ({{ tax_map() }}): the catalogue items whose Tax Class doesn't charge
 * both GST and PST, as { service: {id: {gst, pst}}, labour: {…}, govt_fee: {…} }. Anything not
 * listed is taxed both. The pages apply LineTax's rules with it; the server's totals are LineTax's.
 */
final class TaxMap
{
    /** @var array<string, array<int, array{gst: bool, pst: bool}>>|null */
    private ?array $map = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, array<int, array{gst: bool, pst: bool}>> */
    public function get(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = ['service' => [], 'labour' => [], 'govt_fee' => []];
        foreach (['service' => ServiceItem::class, 'labour' => Labour::class, 'govt_fee' => GovtFee::class] as $key => $class) {
            $items = $this->entityManager->createQueryBuilder()
                ->select('i', 't', 'r')->from($class, 'i')
                ->join('i.taxClass', 't')
                ->leftJoin('t.rates', 'r')
                ->getQuery()->getResult();
            foreach ($items as $item) {
                $tax = LineTax::of($item->getTaxClass());
                if (!$tax->isStandard()) {
                    $this->map[$key][$item->getId()] = $tax->toArray();
                }
            }
        }

        return $this->map;
    }
}
