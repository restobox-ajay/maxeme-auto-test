<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A document whose jurisdiction no installed calculator covers says so, instead of printing $0.00
 * (queue item 66) — conducted per #624, anchored per #627.
 *
 * ## What was wrong
 *
 * After item 61 a US warehouse is perfectly legal: country US, province CA resolves cleanly to
 * California, and it passes every province gate the purchase order and the bill have. Then the two
 * installed calculators — `TaxBCBundle\Tax\BCTaxCalculator`, which claims exactly 'BC', and
 * `TaxCanadaSimpleBundle\Tax\CanadaSimpleTaxCalculator`, which claims nine other Canadian provinces
 * — claim neither California nor anything else in the United States.
 * `TaxCalculatorResolver::calculate()` threw, `PurchaseTaxBreakdown::safeCalculate()` and
 * `OrderTaxBreakdownService::safeCalculateTax()` caught it, logged a warning nobody reads and
 * returned no tax lines, and the document printed **0.00 — indistinguishable from a genuine zero**.
 *
 * ## What it does now, and what these tests pin
 *
 * The zero stands; the silence does not. The breakdown carries one zero-amount line naming the
 * jurisdiction, so the figure is still right, the total is unchanged, and the document SAYS why.
 * Asserted on the stored `tax_lines` column — the frozen snapshot the document keeps, not a
 * rendering of it — and then on the screen, anchored to the row's own slug-keyed id.
 *
 * Every absence assertion here is paired with a positive control on the SAME element: a BC document
 * built the same way, which carries real GST/PST rows and no notice.
 */
final class UncoveredTaxJurisdictionIsStatedCest
{
    private const NOT_COVERED_SLUG = 'tax-jurisdiction-not-covered';

    private const CALIFORNIA = 'No tax rule for California (United States)';

    /** Codeception reuses one Cest instance across methods, so nothing is cached on $this. */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('tax-coverage-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * THE case from the item: a purchase order delivered to a fully addressed Californian warehouse.
     *
     * The tax is still 0.00 and that is correct — the app has no US rule and the state may well levy
     * nothing. What must not happen is that the document says nothing about it.
     */
    public function aFullyAddressedUsWarehouseStatesThatNoRuleCoversIt(FunctionalTester $I): void
    {
        $tag = strtoupper(substr(uniqid(), -6));
        $vendor = $this->vendorAndProduct($I, $tag);

        $california = $this->createWarehouse($I, 'Tax Coverage LA DC ' . $tag, [
            'address_line1' => '1 Alameda St',
            'city' => 'Los Angeles',
            'province' => 'CA',
            'postal_code' => '90021',
            'country' => 'US',
        ]);
        $surrey = $this->createWarehouse($I, 'Tax Coverage Surrey DC ' . $tag, [
            'address_line1' => '12345 Bridgeview Dr',
            'city' => 'Surrey',
            'province' => 'BC',
            'postal_code' => 'V3S 0A1',
            'country' => 'CA',
        ]);

        $usOrder = $this->raiseOrder($I, $vendor['id'], $california, $vendor['name'], 'S');
        // The row that must not change: a covered-province order raised the same way.
        $bcOrder = $this->raiseOrder($I, $vendor['id'], $surrey, $vendor['name'], 'S');

        // --- The columns -------------------------------------------------------------------------
        $row = $this->orderRow($I, $usOrder);
        $I->assertSame('CA', (string) $row['tax_province'], 'guard: the US state resolved and was frozen onto the order');
        $I->assertSame(0.0, (float) $row['tax'], 'the tax figure is still zero, which is the honest answer');
        $I->assertSame(
            self::CALIFORNIA,
            $this->taxLineLabel($I, (string) $row['tax_lines'], self::NOT_COVERED_SLUG),
            'purchase_order.tax_lines carries no statement about the jurisdiction',
        );
        $I->assertSame(
            0.0,
            $this->taxLineAmount($I, (string) $row['tax_lines'], self::NOT_COVERED_SLUG),
            'and the statement must add nothing to the total',
        );

        // --- The positive control, on the same column ---------------------------------------------
        $bcRow = $this->orderRow($I, $bcOrder);
        $I->assertSame('BC', (string) $bcRow['tax_province']);
        $I->assertGreaterThan(0.0, (float) $bcRow['tax'], 'guard: a covered province really does charge tax');
        $I->assertNull(
            $this->taxLineLabel($I, (string) $bcRow['tax_lines'], self::NOT_COVERED_SLUG),
            'a covered province must not be told there is no rule for it',
        );

        // --- The screens --------------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $usOrder);
        $I->seeResponseCodeIsSuccessful();
        $I->see(self::CALIFORNIA, '#po-tax-line-' . self::NOT_COVERED_SLUG);

        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $bcOrder);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#po-tax-line-' . self::NOT_COVERED_SLUG);
        // Paired positive control on the same kind of element: the BC order DOES list a tax row.
        $I->seeElement('#po-tax-line-gst');
    }

    /**
     * Exempt goods get no notice, and that is deliberate rather than a gap.
     *
     * With a tax class of 'E' there is no rate to get wrong and the answer is $0 whatever the
     * jurisdiction says — the same line `PurchaseOrder::assertTaxProvinceKnownIfTaxable()` draws
     * when it lets an exempt order through with no province at all.
     */
    public function exemptGoodsInAnUncoveredJurisdictionAreNotAnnounced(FunctionalTester $I): void
    {
        $tag = strtoupper(substr(uniqid(), -6));
        $vendor = $this->vendorAndProduct($I, $tag);

        $california = $this->createWarehouse($I, 'Tax Coverage Exempt DC ' . $tag, [
            'address_line1' => '1 Alameda St',
            'city' => 'Los Angeles',
            'province' => 'CA',
            'postal_code' => '90021',
            'country' => 'US',
        ]);

        $exemptOrder = $this->raiseOrder($I, $vendor['id'], $california, $vendor['name'], 'E');
        $taxableOrder = $this->raiseOrder($I, $vendor['id'], $california, $vendor['name'], 'S');

        $I->assertNull(
            $this->taxLineLabel($I, (string) $this->orderRow($I, $exemptOrder)['tax_lines'], self::NOT_COVERED_SLUG),
            'an exempt order has no calculation to be missing a rule for',
        );
        // The positive control on the same column: the same warehouse, taxable goods, notice present.
        $I->assertSame(
            self::CALIFORNIA,
            $this->taxLineLabel($I, (string) $this->orderRow($I, $taxableOrder)['tax_lines'], self::NOT_COVERED_SLUG),
        );

        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $exemptOrder);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#po-tax-line-' . self::NOT_COVERED_SLUG);

        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $taxableOrder);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#po-tax-line-' . self::NOT_COVERED_SLUG);
    }

    /**
     * The jurisdiction is named, never left as a bare code.
     *
     * `CA` is the country code for Canada AND the state code for California, and
     * `RegionSeedData::resolveProvinceAnyCountry('CA')` resolves it to California. "No rule for CA"
     * printed by a Canadian-tax application is the single most misleading sentence this could
     * produce, so the country is spelled out beside the name and this pins that it is.
     */
    public function theNoticeSpellsOutTheCountrySoCaIsNeverAmbiguous(FunctionalTester $I): void
    {
        $tag = strtoupper(substr(uniqid(), -6));
        $vendor = $this->vendorAndProduct($I, $tag);
        $california = $this->createWarehouse($I, 'Tax Coverage Ambiguity DC ' . $tag, [
            'address_line1' => '1 Alameda St',
            'city' => 'Los Angeles',
            'province' => 'CA',
            'postal_code' => '90021',
            'country' => 'US',
        ]);

        $orderId = $this->raiseOrder($I, $vendor['id'], $california, $vendor['name'], 'S');

        $label = (string) $this->taxLineLabel($I, (string) $this->orderRow($I, $orderId)['tax_lines'], self::NOT_COVERED_SLUG);
        $I->assertStringContainsString('California', $label);
        $I->assertStringContainsString('United States', $label);
        $I->assertStringNotContainsString('Canada', $label);
    }

    /**
     * The SELL side of the same silence, reached the way a sell document reaches it: a customer
     * whose goods ship to California.
     *
     * A sales document takes its tax province from the SHIPPING ADDRESS
     * (`AbstractSalesDocument::getProvince()`), not from a warehouse — so item 66's warehouse is the
     * buy-side door and this is the sell-side one. Both go through `TaxCalculatorResolver`, and both
     * used to swallow the same exception into the same silent zero. The notice is worded once, in
     * `OrderTaxBreakdownService::notCoveredLine()`, and this is what pins that the sell side gets
     * the identical sentence rather than a second one that drifts.
     */
    public function aSellDocumentShippingToAnUncoveredStateSaysSoToo(FunctionalTester $I): void
    {
        $californian = $this->orderShippingTo($I, 'CA', 'Los Angeles');
        $canadian = $this->orderShippingTo($I, 'BC', 'Surrey');

        $I->amOnPage('/admin/order/detail/' . $californian);
        $I->seeResponseCodeIsSuccessful();
        $I->see(self::CALIFORNIA, '#tax-line-' . self::NOT_COVERED_SLUG);

        // The row that must not change, and the positive control on the same element: a covered
        // province lists a real tax row and no notice.
        $I->amOnPage('/admin/order/detail/' . $canadian);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#tax-line-' . self::NOT_COVERED_SLUG);
        $I->seeElement('#tax-line-gst');
    }

    // -------------------------------------------------------------------------------- the plumbing

    /**
     * An approved order for one taxable line, shipping to $province — built as a fixture because
     * what is under test is the tax breakdown the DETAIL SCREEN computes from it, not the address
     * form that other Cests already conduct.
     */
    private function orderShippingTo(FunctionalTester $I, string $province, string $city): int
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $tag = strtoupper(substr(uniqid(), -6));

        $company = (new \App\Entity\Company())
            ->setName('Tax Coverage Customer ' . $tag)
            ->setCode('TCC-' . $tag);
        $em->persist($company);

        $product = (new ProductCore())
            ->setSku('TCS-' . $tag)
            ->setName('Shipped Widget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $product->setSalesTaxCode('S');
        $em->persist($product);

        $order = (new \App\Entity\SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('TCS-' . $tag)
            ->setDocumentDate('2026-09-10')
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addLine(
            (new \App\Entity\SalesOrderLine())
                ->setProduct($product)
                ->setName((string) $product->getName())
                ->setSku((string) $product->getSku())
                ->setQuantity('4.00')
                ->setPrice('25.00')
                ->setSubtotal('100.00')
                ->setTaxCode('S'),
        );
        $em->persist($order);
        $em->flush();

        // A sales document's tax province comes off its own frozen shipping snapshot, which is what
        // addressForWriting() hands back — the same object the order form writes through.
        $address = $order->addressForWriting(\App\Entity\AbstractDocumentAddress::TYPE_SHIPPING);
        $address->setCity($city);
        $address->setProvince($province);
        $address->setCountry($province === 'CA' ? 'US' : 'CA');
        $em->flush();

        return (int) $order->getId();
    }


    /** @param array<string, string> $address */
    private function createWarehouse(FunctionalTester $I, string $name, array $address): int
    {
        $I->amOnPage('/admin/warehouse/create');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form.config-form-grid input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/warehouse/create', array_merge([
            '_token' => $token,
            'name' => $name,
            'status' => 'Active',
        ], $address));

        $id = (int) $this->connection($I)->fetchOne('SELECT id FROM warehouse WHERE name = ?', [$name]);
        $I->assertGreaterThan(0, $id, sprintf('the warehouse "%s" was not created', $name));

        return $id;
    }

    /** @return array{id: int, name: string} */
    private function vendorAndProduct(FunctionalTester $I, string $tag): array
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $vendor = (new Vendor())->setName('Tax Coverage Vendor ' . $tag)->setCurrency('CAD')->setStatus('Active');
        $em->persist($vendor);

        $product = (new ProductCore())
            ->setSku('TCV-' . $tag)
            ->setName('Coverage Widget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        return ['id' => (int) $vendor->getId(), 'name' => (string) $product->getName()];
    }

    private function raiseOrder(FunctionalTester $I, int $vendorId, int $warehouseId, string $productName, string $taxCode): int
    {
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/new');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form[action$="/purchase-orders/save"] input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/save', [
            '_token' => $token,
            'id' => '0',
            'charge_lines_present' => '1',
            'vendor_id' => (string) $vendorId,
            'warehouse_id' => (string) $warehouseId,
            'document_date' => '2026-09-10',
            'lines' => [
                0 => ['name' => $productName, 'qty' => '2', 'unit_cost' => '10.0000', 'tax_code' => $taxCode],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $id = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM purchase_order WHERE warehouse_id = ? AND vendor_id = ? ORDER BY id DESC LIMIT 1',
            [$warehouseId, $vendorId],
        );
        $I->assertGreaterThan(0, $id, 'the purchase order was not raised');

        return $id;
    }

    /** @return array<string, mixed> */
    private function orderRow(FunctionalTester $I, int $id): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT status, tax_province, subtotal, tax, total, tax_lines FROM purchase_order WHERE id = ?',
            [$id],
        );
        $I->assertIsArray($row, 'purchase_order row ' . $id . ' is missing');

        return $row;
    }

    /** The label of the stored tax line carrying $slug, or null when the snapshot has no such row. */
    private function taxLineLabel(FunctionalTester $I, string $taxLinesJson, string $slug): ?string
    {
        $line = $this->taxLine($taxLinesJson, $slug);

        return $line === null ? null : (string) ($line['label'] ?? '');
    }

    private function taxLineAmount(FunctionalTester $I, string $taxLinesJson, string $slug): ?float
    {
        $line = $this->taxLine($taxLinesJson, $slug);

        return $line === null ? null : (float) ($line['amount'] ?? 0);
    }

    /**
     * One row out of a document's frozen `tax_lines` snapshot, by slug.
     *
     * The column holds the whole breakdown — `{lines, total, perLineTax, perLineTaxLabel}` — not a
     * bare list, which is what lets the per-line labels be frozen alongside the document-level rows.
     *
     * @return array<string, mixed>|null
     */
    private function taxLine(string $taxLinesJson, string $slug): ?array
    {
        $decoded = json_decode($taxLinesJson === '' ? '{}' : $taxLinesJson, true);
        $lines = \is_array($decoded) ? ($decoded['lines'] ?? []) : [];

        foreach (\is_array($lines) ? $lines : [] as $line) {
            if (\is_array($line) && (string) ($line['slug'] ?? '') === $slug) {
                return $line;
            }
        }

        return null;
    }

    /** Every per-line tax LABEL the document froze, in row order. */
    private function perLineTaxLabels(string $taxLinesJson): array
    {
        $decoded = json_decode($taxLinesJson === '' ? '{}' : $taxLinesJson, true);
        $labels = \is_array($decoded) ? ($decoded['perLineTaxLabel'] ?? []) : [];

        return array_map('strval', \is_array($labels) ? $labels : []);
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
