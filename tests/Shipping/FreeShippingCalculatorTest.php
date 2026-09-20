<?php

declare(strict_types=1);

namespace App\Tests\Shipping;

use App\Entity\Cart;
use PHPUnit\Framework\TestCase;
use ShippingFreeBundle\Shipping\FreeShippingCalculator;

final class FreeShippingCalculatorTest extends TestCase
{
    /**
     * Issue #145: the "Free shipping on all orders." blurb was removed from the Free Shipping
     * option's description, so the checkout renders just "Free Shipping" with no dash/blurb.
     */
    public function testFreeShippingOptionHasNoDescription(): void
    {
        $options = (new FreeShippingCalculator())->getOptions(new Cart());

        self::assertCount(1, $options);
        self::assertSame('Free Shipping', $options[0]->label);
        self::assertSame('', $options[0]->description);
    }

    /**
     * An empty document is a real case rather than a test convenience: the admin order create page
     * resolves options against a brand-new order with no lines and no address, and a guest cart has
     * neither buyer nor rows. A calculator must answer for one without reaching for anything.
     */
    public function testAnEmptyDocumentStillProducesAnOption(): void
    {
        $calculator = new FreeShippingCalculator();
        $cart = new Cart();

        self::assertTrue($calculator->supports($cart));
        self::assertSame('E', $calculator->getOptions($cart)[0]->taxClass, 'no rows means nothing taxable');
    }
}
