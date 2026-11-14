<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Entity\ProductCore;
use App\Maxeme\Entity\TaxClass;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A part's taxes (audit fix in mockup 8b30420): the Tax Class whose code is the product's
 * sales_tax_code ("E" exempt, "G" GST only, "S" both); a product without one, or with a code no
 * class has, is taxed both.
 */
final class PartTaxes
{
    /** @var array<string, TaxClass>|null by code */
    private ?array $classes = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function of(?ProductCore $product): LineTax
    {
        return LineTax::of($this->classOf($product));
    }

    public function classOf(?ProductCore $product): ?TaxClass
    {
        $code = $product?->getSalesTaxCode();
        if ($code === null || $code === '') {
            return null;
        }
        if ($this->classes === null) {
            $this->classes = [];
            foreach ($this->entityManager->getRepository(TaxClass::class)->findAll() as $class) {
                $this->classes[$class->getCode()] = $class;
            }
        }

        return $this->classes[$code] ?? null;
    }
}
