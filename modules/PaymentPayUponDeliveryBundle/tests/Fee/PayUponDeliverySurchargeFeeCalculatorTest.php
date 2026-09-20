<?php

declare(strict_types=1);

namespace PaymentPayUponDeliveryBundle\Tests\Fee;

use App\Contract\Fee\FeeContext;
use App\Entity\ProductCore;
use PaymentPayUponDeliveryBundle\Fee\PayUponDeliverySurchargeFeeCalculator;
use PaymentPayUponDeliveryBundle\Payment\PayUponDeliveryPaymentMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayUponDeliverySurchargeFeeCalculatorTest extends TestCase
{
    /**
     * @param list<string|null> $taxCodes one product per code
     */
    private function context(array $taxCodes, ?string $paymentMethod = PayUponDeliveryPaymentMethod::SLUG): FeeContext
    {
        $items = [];
        foreach ($taxCodes as $i => $code) {
            $items[] = [
                'product' => (new ProductCore())->setSku('SKU-' . $i)->setName('Widget ' . $i)->setSalesTaxCode($code),
                'qty' => 1,
            ];
        }

        return new FeeContext('BC', $items, $paymentMethod, subtotal: 100.0);
    }

    public function testSupportsOnlyThePayUponDeliveryPaymentMethod(): void
    {
        $calculator = new PayUponDeliverySurchargeFeeCalculator();

        $this->assertTrue($calculator->supports($this->context(['G'])));
        $this->assertFalse($calculator->supports($this->context(['G'], 'stripe')));
        $this->assertFalse($calculator->supports($this->context(['G'], null)));
    }

    /**
     * The surcharge is taxed at the highest class in the basket, not at a fixed 'G'. Each case is
     * the same basket priced two ways: what the class used to be, and what the contents say it is.
     *
     * @param list<string|null> $taxCodes
     */
    #[DataProvider('baskets')]
    public function testTheSurchargeTakesTheHighestTaxClassInTheBasket(array $taxCodes, string $expected): void
    {
        $lines = (new PayUponDeliverySurchargeFeeCalculator())->calculate($this->context($taxCodes));

        $this->assertCount(1, $lines);
        $this->assertSame($expected, $lines[0]->taxClass);
    }

    /** @return array<string, array{list<string|null>, string}> */
    public static function baskets(): array
    {
        return [
            'all taxable at the full rate' => [['S', 'S'], 'S'],
            'all GST-only' => [['G', 'G'], 'G'],
            // The case the old hard-coded 'G' under-taxed.
            'mixed — one full-rate line pulls the surcharge up' => [['G', 'S'], 'S'],
            'exempt line does not pull anything down' => [['E', 'S'], 'S'],
            // The case the old hard-coded 'G' taxed when nothing in the basket was taxable.
            'all exempt' => [['E', 'E'], 'E'],
            // An unset code is exempt — TaxContext::mapTaxCode's rule, not a second one here.
            'missing codes count as exempt' => [[null, ''], 'E'],
            'no priced lines at all' => [[], 'E'],
        ];
    }

    public function testTheAmountAndIdentityOfTheLineAreUnchanged(): void
    {
        $line = (new PayUponDeliverySurchargeFeeCalculator())->calculate($this->context(['S']))[0];

        $this->assertSame('pay-upon-delivery-surcharge', $line->slug);
        $this->assertSame('Pay Upon Delivery Surcharge', $line->label);
        $this->assertSame(2.00, $line->amount);
        $this->assertNull($line->feeId);
    }
}
