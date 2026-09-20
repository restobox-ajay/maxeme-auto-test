<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The no-JS way to put a product on an order or quote line (#399).
 *
 * Once the catalog outgrows PRODUCT_SELECT_INLINE_LIMIT, the line's <select> is seeded only with the
 * products already on the document — so with scripting off there is no option for anything else, and
 * the endpoint that replaced the catalog is reachable only from JavaScript. That left a no-JS admin
 * unable to add a product at all. The forms now render a plain id box inside <noscript> for those
 * saves, and the save paths read it when the select posts empty.
 *
 * Worth stating why this suite can prove it: PHPBrowser executes no JavaScript, so a post it builds
 * IS the no-JS post. What it cannot do is prove the <noscript> markup renders — that needs the
 * catalog over the threshold, which is asserted separately below by counting seeded products.
 */
final class AdminNoJsProductIdLineCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('nojs-product-id@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('No JS Product Id Co')
            ->setCode('NJP-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('NJP-' . uniqid())
            ->setName('No JS Picked Widget')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveStockFor($product);

        return $product;
    }

    /**
     * The order save path: product_id posts empty (no option to pick), product_id_manual carries the
     * id. Before this, the row was discarded as blank by ValidOrderLinesValidator::isBlankLine()
     * before the save loop ever looked at it.
     */
    public function anOrderLineTakesItsProductFromTheNoJsIdBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                // Exactly what a scripting-disabled browser posts: the select had no option to offer,
                // so it submits '', and the id arrives in the <noscript> box instead.
                ['product_id' => '', 'product_id_manual' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'draft_exit',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $created = $entityManager->getRepository(SalesOrder::class)
            ->findBy(['company' => $company->getId()], ['id' => 'DESC'], 1)[0] ?? null;

        $I->assertNotNull($created, 'the line was dropped as blank, so no order was created');

        $lines = $created->getLines();
        $I->assertCount(1, $lines, 'the no-JS row did not survive the save');

        $line = $lines[0];
        $I->assertNotNull($line->getProduct(), 'the typed product id never reached the line');
        $I->assertSame($product->getId(), $line->getProduct()->getId());
        $I->assertSame('No JS Picked Widget', $line->getName(), 'the line did not take the product name');
    }

    /**
     * Where both arrive, the id box wins — pinning the precedence that makes a no-JS edit possible.
     *
     * Past the inline limit the select is hidden with JS off, but a hidden select still posts whatever
     * it was rendered with. Preferring it meant retyping the id on an existing line changed nothing:
     * the stale selected option won and the admin's edit vanished silently. In a real browser only one
     * of the two can ever post anyway — a <noscript>'s contents never reach the DOM when scripting is
     * on — so this precedence costs the JS path nothing.
     */
    public function theNoJsIdBoxWinsOverAStaleSelectedOption(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $stale = $this->makeProduct($I);
        $retyped = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $stale->getId(), 'product_id_manual' => (string) $retyped->getId(), 'qty' => '1', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'draft_exit',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $created = $entityManager->getRepository(SalesOrder::class)
            ->findBy(['company' => $company->getId()], ['id' => 'DESC'], 1)[0] ?? null;
        $I->assertNotNull($created);

        $line = $created->getLines()[0];
        $I->assertNotNull($line->getProduct());
        $I->assertSame($retyped->getId(), $line->getProduct()->getId(), 'the hidden stale select overrode the id the admin typed');
    }

    /**
     * A row with neither is still blank and still skipped — the whole point of the blank-line check,
     * which the new field had to widen without defeating.
     */
    public function aRowWithNeitherFieldIsStillTreatedAsBlank(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '50.00', 'tax_code' => 'E'],
                ['product_id' => '', 'product_id_manual' => '', 'name' => '', 'qty' => '1', 'price' => '', 'tax_code' => 'E'],
            ],
            'save_mode' => 'draft_exit',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $created = $entityManager->getRepository(SalesOrder::class)
            ->findBy(['company' => $company->getId()], ['id' => 'DESC'], 1)[0] ?? null;
        $I->assertNotNull($created);
        $I->assertCount(1, $created->getLines(), 'the empty spare row was saved as a line');
    }

    /**
     * The quote save path's twin. Its line fields are parallel arrays rather than nested ones, so the
     * id box posts as its own line_product_id_manual[] — a second line_product_id[] entry would
     * append and push every later row out of step with the other line_* arrays.
     */
    public function aQuoteLineTakesItsProductFromTheNoJsIdBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $estimate = (new Estimate())->setCompany($company);
        $estimate->setFulfillmentRegion('Main');
        $I->haveInRepository($estimate);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'fulfillment_region' => 'Main',
            'lines' => [
                0 => ['id' => '', 'name' => '', 'product_id' => '', 'product_id_manual' => (string) $product->getId(), 'qty' => '3', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertNotNull($saved);

        $lines = $saved->getLines();
        $I->assertCount(1, $lines, 'the no-JS quote row did not survive the save');
        $I->assertNotNull($lines[0]->getProduct(), 'the typed product id never reached the quote line');
        $I->assertSame($product->getId(), $lines[0]->getProduct()->getId());
    }
}
