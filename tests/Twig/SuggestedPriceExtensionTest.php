<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Service\SuggestedPriceCalculator;
use App\Twig\SuggestedPriceExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

final class SuggestedPriceExtensionTest extends TestCase
{
    private function repo(bool $hideBundleActive): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturn($hideBundleActive);

        return $repo;
    }

    private function extension(?EntityManagerInterface $em = null, bool $hideBundleActive = false): SuggestedPriceExtension
    {
        return new SuggestedPriceExtension(
            new SuggestedPriceCalculator(),
            $em ?? $this->createStub(EntityManagerInterface::class),
            $this->repo($hideBundleActive),
        );
    }

    public function testRenderReturnsEmptyStringForNonProductValue(): void
    {
        self::assertSame('', $this->extension()->render('not a product'));
        self::assertSame('', $this->extension()->render(null));
    }

    public function testRenderReturnsEmptyStringForArrayWithoutPositiveId(): void
    {
        self::assertSame('', $this->extension()->render([]));
        self::assertSame('', $this->extension()->render(['id' => 0]));
        self::assertSame('', $this->extension()->render(['id' => -1]));
    }

    public function testRenderReturnsEmptyStringWhenArrayIdDoesNotResolveToAProduct(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('find')->with(ProductCore::class, 42)->willReturn(null);

        self::assertSame('', $this->extension($em)->render(['id' => 42]));
    }

    public function testRenderReturnsEmptyStringWhenProductHasNoBasePrice(): void
    {
        $product = new ProductCore();

        self::assertSame('', $this->extension()->render($product));
    }

    public function testRenderSuppressesOutputWhenSuggestedPriceMatchesBasePrice(): void
    {
        $product = (new ProductCore())->setDefaultPrice('19.99');

        self::assertSame('', $this->extension()->render($product));
    }

    public function testRenderShowsOverrideAsParagraphForDetailPageProduct(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('19.99')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_DOLLAR)
            ->setSuggestedPriceValue('5');

        $html = $this->extension()->render($product);

        self::assertSame('<p class="customer-product-price-suggested"><strong>Price Suggested:</strong> $24.99</p>', $html);
    }

    public function testRenderShowsOverrideAsSpanForCatalogListingArrayShape(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('19.99')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_DOLLAR)
            ->setSuggestedPriceValue('5');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('find')->with(ProductCore::class, 7)->willReturn($product);

        $html = $this->extension($em)->render(['id' => 7]);

        self::assertSame('<span class="customer-product-price-suggested"><strong>Price Suggested:</strong> $24.99</span>', $html);
    }

    public function testRenderShowsPriceEvenWithoutOverrideWhenHideRealPriceBundleActive(): void
    {
        $product = (new ProductCore())->setDefaultPrice('19.99');

        $html = $this->extension(hideBundleActive: true)->render($product);

        self::assertSame('<p class="customer-product-price-suggested"><strong>Price Suggested:</strong> $19.99</p>', $html);
    }

    public function testGetFunctionsRegistersSuggestedPriceHtmlAsHtmlSafe(): void
    {
        $functions = $this->extension()->getFunctions();

        self::assertCount(1, $functions);
        self::assertInstanceOf(TwigFunction::class, $functions[0]);
        self::assertSame('suggested_price_html', $functions[0]->getName());
        self::assertSame(['html'], $functions[0]->getSafe(new \Twig\Node\Expression\ConstantExpression(1, 0)));
    }
}
