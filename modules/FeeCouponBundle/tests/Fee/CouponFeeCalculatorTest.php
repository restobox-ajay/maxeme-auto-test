<?php

declare(strict_types=1);

namespace FeeCouponBundle\Tests\Fee;

use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeLine;
use App\Entity\AppSetting;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use FeeCouponBundle\Fee\CouponFeeCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CouponFeeCalculatorTest extends TestCase
{
    private const COUPONS = <<<'JSON'
    [
        {"code": "TENOFF",   "type": "percent", "value": 10},
        {"code": "FIVER",    "type": "fixed",   "value": 5},
        {"code": "BIGSPEND", "type": "percent", "value": 20, "minSubtotal": 500},
        {"code": "ALLOFF",   "type": "percent", "value": 100},
        {"code": "JUNK",     "type": "bogus",   "value": 10},
        {"code": "ZERO",     "type": "percent", "value": 0}
    ]
    JSON;

    /**
     * AppSettings is final, so it is built for real over a stubbed repository rather than doubled —
     * the same approach tests/Twig/AppSettingsExtensionTest.php takes.
     */
    private function calculator(string $couponsJson = self::COUPONS, string $enabled = 'Yes'): CouponFeeCalculator
    {
        $rows = [
            (new AppSetting())->setSettingKey('checkout_coupons')->setName('Coupons')->setSettingValue($couponsJson),
            (new AppSetting())->setSettingKey('checkout_coupons_enabled')->setName('Coupons Enabled')->setSettingValue($enabled),
        ];

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new CouponFeeCalculator(new AppSettings($em, new ArrayAdapter()));
    }

    /** @param list<string> $codes */
    private function context(array $codes, float $subtotal, string $taxCode = 'S'): FeeContext
    {
        $product = (new ProductCore())->setSku('SKU-1')->setName('Widget')->setSalesTaxCode($taxCode);

        return new FeeContext(
            'BC',
            [['product' => $product, 'qty' => 1]],
            subtotal: $subtotal,
            couponCodes: $codes,
        );
    }

    public function testSupportsOnlyWhenCodesArePresentAndCouponsAreEnabled(): void
    {
        $this->assertTrue($this->calculator()->supports($this->context(['TENOFF'], 100.0)));
        $this->assertFalse($this->calculator()->supports($this->context([], 100.0)));
        // The admin kill switch must win even with a valid code applied.
        $this->assertFalse($this->calculator(self::COUPONS, 'No')->supports($this->context(['TENOFF'], 100.0)));
    }

    public function testPercentCouponBecomesOneNegativeFeeLine(): void
    {
        $lines = $this->calculator()->calculate($this->context(['TENOFF'], 200.0));

        $this->assertCount(1, $lines);
        $this->assertSame(-20.0, $lines[0]->amount);
        $this->assertSame('coupon-TENOFF', $lines[0]->slug);
        $this->assertSame('Coupon TENOFF', $lines[0]->label);
        $this->assertSame('main_line', $lines[0]->placement);
        // A coupon is a discount, not a fee — the distinction is what lets discounts be
        // totalled and reported separately from surcharges.
        $this->assertSame(FeeLine::TYPE_DISCOUNT, $lines[0]->type);
        $this->assertSame(FeeLine::SOURCE_AUTO_CALC, $lines[0]->source);
    }

    public function testFixedCouponUsesItsValueOutright(): void
    {
        $lines = $this->calculator()->calculate($this->context(['FIVER'], 200.0));

        $this->assertCount(1, $lines);
        $this->assertSame(-5.0, $lines[0]->amount);
    }

    public function testCouponsStackAsSeparateLines(): void
    {
        $lines = $this->calculator()->calculate($this->context(['TENOFF', 'FIVER'], 200.0));

        $this->assertCount(2, $lines);
        // Percentages come off the full subtotal, not off the running remainder: 10% of 200, not
        // 10% of 195.
        $this->assertSame(-20.0, $lines[0]->amount);
        $this->assertSame(-5.0, $lines[1]->amount);
    }

    public function testCombinedDiscountIsCappedAtSubtotal(): void
    {
        // Two 100% coupons must discount the subtotal once, not twice.
        $lines = $this->calculator()->calculate($this->context(['ALLOFF', 'TENOFF'], 100.0));

        $this->assertCount(1, $lines);
        $this->assertSame(-100.0, $lines[0]->amount);
        $this->assertSame(-100.0, array_sum(array_map(static fn ($l) => $l->amount, $lines)));
    }

    public function testCapIsConsumedInAppliedOrder(): void
    {
        // Same pair, reversed: the 10% takes its share first and the 100% takes only what is left.
        $lines = $this->calculator()->calculate($this->context(['TENOFF', 'ALLOFF'], 100.0));

        $this->assertCount(2, $lines);
        $this->assertSame(-10.0, $lines[0]->amount);
        $this->assertSame(-90.0, $lines[1]->amount);
        $this->assertSame(-100.0, array_sum(array_map(static fn ($l) => $l->amount, $lines)));
    }

    public function testDiscountNeverExceedsSubtotalSoTheOrderCannotGoNegative(): void
    {
        // A $5 fixed coupon against a $2 order gives back $2, not $5.
        $lines = $this->calculator()->calculate($this->context(['FIVER'], 2.0));

        $this->assertCount(1, $lines);
        $this->assertSame(-2.0, $lines[0]->amount);
    }

    public function testMinimumSubtotalIsEnforced(): void
    {
        $this->assertSame([], $this->calculator()->calculate($this->context(['BIGSPEND'], 499.0)));

        $lines = $this->calculator()->calculate($this->context(['BIGSPEND'], 500.0));
        $this->assertCount(1, $lines);
        $this->assertSame(-100.0, $lines[0]->amount);
    }

    public function testUnknownMalformedAndZeroValueCodesProduceNothing(): void
    {
        $this->assertSame([], $this->calculator()->calculate($this->context(['NOPE'], 100.0)));
        $this->assertSame([], $this->calculator()->calculate($this->context(['JUNK'], 100.0)));
        $this->assertSame([], $this->calculator()->calculate($this->context(['ZERO'], 100.0)));
    }

    public function testAZeroSubtotalProducesNoLines(): void
    {
        $this->assertSame([], $this->calculator()->calculate($this->context(['TENOFF'], 0.0)));
        $this->assertSame([], $this->calculator()->calculate($this->context(['FIVER'], 0.0)));
    }

    public function testUnparseableCouponSettingIsTreatedAsNoCoupons(): void
    {
        $this->assertSame([], $this->calculator('{not json')->calculate($this->context(['TENOFF'], 100.0)));
        $this->assertSame([], $this->calculator('')->calculate($this->context(['TENOFF'], 100.0)));
    }

    public function testTaxClassIsTheHighestInTheCart(): void
    {
        // KNOWN SIMPLIFICATION, pinned here deliberately: the discount is reversed entirely at the
        // cart's highest class rather than apportioned across the classes it actually reduces.
        $this->assertSame('S', $this->calculator()->calculate($this->context(['TENOFF'], 100.0, 'S'))[0]->taxClass);
        $this->assertSame('G', $this->calculator()->calculate($this->context(['TENOFF'], 100.0, 'G'))[0]->taxClass);
        $this->assertSame('E', $this->calculator()->calculate($this->context(['TENOFF'], 100.0, 'E'))[0]->taxClass);
    }
}
