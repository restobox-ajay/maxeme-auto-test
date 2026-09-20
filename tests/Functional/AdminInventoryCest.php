<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Warehouse;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers the admin Inventory page redesign (§4/§7 of the feature): the per-warehouse bucket
 *  layout actually renders over real HTTP, with a real logged-in admin session — not just the
 *  controller's row-building logic in isolation.
 *
 *  The columns are per WAREHOUSE since #546: stock is counted in a building, and the admin grid
 *  follows the entity it edits. Since #36 they are per warehouse AND per chosen column, and the
 *  default set is every term in getAvailableQuantity() plus Available plus Reserved — which is what
 *  this file asserts. That every BUCKET reaches a column at all, chosen or not, is
 *  InventoryGridBucketCoverageCest's job; this one is about what an admin sees on opening the page.
 *
 * @group bundle-agnostic
 *
 * Tagged for the bundles-off run (#562): these assertions must hold identically with the
 * optional inventory bundles Inactive. If a change here can only pass with them Active, the
 * change has leaked out of its bundle.
 */
final class AdminInventoryCest
{
    /**
     * Authenticates via the security token storage directly (Codeception's Symfony module
     * `amLoggedInAs()`) rather than POSTing the real login form: this app's admin/customer
     * split is host-based (AdminHostSubscriber), and the in-process HttpKernel browser this
     * module uses treats a login redirect to a different host as an external URL it refuses
     * to follow. Skipping the login route itself is an accepted trade-off — what these tests
     * verify is authorized-page behavior (the actual feature under test), not the login form.
     */
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    public function inventoryPageShowsEveryBucketColumnPerWarehouse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $warehouse = (new Warehouse())->setName('West Warehouse')->setStatus('Active');
        $I->haveInRepository($warehouse);

        $product = (new ProductCore())->setSku('FUNC-SKU-1')->setName('Functional Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $inventory = (new ProductInventory())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setQuantity(50);
        $inventory->adjustCartHold(3)->adjustSalesHold(2)->adjustPending(4)->adjustApproved(5);
        $I->haveInRepository($inventory);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/inventory');
        $I->seeResponseCodeIsSuccessful();

        $I->see('West Warehouse');

        // The bucket headings, in order, read out of the header row rather than looked for on the
        // page (#627 — the sibling of the cell rule below). One see() per label proved only that
        // each word was somewhere in the document: 'Hold' is a substring of 'Sales Hold', so the
        // Hold column could vanish entirely and see('Hold') would still be satisfied by its
        // neighbour, and the nine columns could arrive in any order.
        //
        // Sales Hold is the fourth bucket (#539 stage 3): a sales order's uninvoiced remainder,
        // shown beside the other three so the row's arithmetic is checkable by eye — a bucket
        // subtracted from Available but not displayed would make the page look wrong rather than
        // incomplete. Received is the warehouse-side term (#581): what this app has seen arrive
        // since the client's count was imported, shown for the same reason. It no longer moves on
        // a transfer — since #584 a transfer writes `transfer_out`/`transfer_in` and nothing else.
        // The bucket headings, in order, read out of the header row rather than looked for on the
        // page (#627 — the sibling of the cell rule below). One see() per label proved only that
        // each word was somewhere in the document: 'Hold' is a substring of 'Sales Hold', so the
        // Hold column could vanish entirely and see('Hold') would still be satisfied by its
        // neighbour, and the columns could arrive in any order.
        //
        // Thirteen since #36, against nine before it. What changed is not "six more columns" but
        // which set: every term in getAvailableQuantity() is now here, so the row's arithmetic is
        // checkable by eye on any data rather than only on data where the missing terms happen to
        // be zero. Transfer In, Transfer Out, Quarantine and Write-off are the four that were
        // subtracted from Available and shown nowhere. Reserved joins them for the opposite reason:
        // it is NOT in the sum and never has been, and a column reading 0 with "not counted" beside
        // it is the only way to say so to somebody who assumes committed stock is sitting in it.
        //
        // Incoming, Manual adjustment and the four backorder controls are off by default and one
        // tick away in the chooser — see InventoryGridColumns::defaultKeys() for why the default is
        // the reconciliation set and not everything.
        //
        // The label is grabbed from its own span because the +/− beside it is a note about the
        // formula, not part of the column's name.
        $bucketHeadings = array_map(trim(...), $I->grabMultiple('table.wide-price-table thead tr:nth-child(2) th .inventory-bucket-label'));
        $I->assertSame(
            [
                'Starting', 'Received', 'Transfer In', 'Transfer Out', 'Quarantine', 'Write-off',
                'Hold', 'Sales Hold', 'Pending', 'Approved', 'Shipped', 'Backordered', 'Reserved', 'Available',
            ],
            $bucketHeadings,
            'every term subtracted from or added to Available, then the quantity that is deliberately not, then the answer',
        );

        // Starting 50, Hold 3, Sales Hold 2, Pending 4, Approved 5, everything else 0
        // => Available 36.
        //
        // Every figure is read out of the cell that carries it, by the `data-label` the cell is
        // built with, and not with see() (#594). A page-wide see('50') matches any '50' anywhere in
        // the document — a pagination control, an id, a price — and, because see() is a substring
        // match, a cell rendering 1050 satisfies see('50') and a cell rendering 1036 satisfies
        // see('36'). Proved by mutation: rendering `bucket.salesHold` in the Hold column and
        // `bucket.hold` in the Sales Hold column, `bucket.starting + 1000` in the quantity box and
        // `bucket.available + 1000` in Available left all three tests in this file green. Every
        // number on the inventory grid could have been wrong — including the transfer bucket that
        // read 50 at a warehouse holding 40 — and this test would not have moved.
        $cell = static fn (string $bucket): string => 'td[data-label="West Warehouse — ' . $bucket . '"]';

        $I->assertSame('50', trim($I->grabTextFrom($cell('Starting'))), 'product_inventory.quantity');
        $I->assertSame(
            '50',
            $I->grabAttributeFrom($cell('Starting') . ' input.js-inventory-input', 'value'),
            'the editable box has to open on the stored count, or a save posts back a number nobody typed',
        );
        $I->assertSame('0', trim($I->grabTextFrom($cell('Received'))), 'product_inventory.received_quantity');
        $I->assertSame('0', trim($I->grabTextFrom($cell('Transfer In'))), 'product_inventory.transfer_in_quantity');
        $I->assertSame('0', trim($I->grabTextFrom($cell('Transfer Out'))), 'product_inventory.transfer_out_quantity');
        $I->assertSame('0', trim($I->grabTextFrom($cell('Quarantine'))), 'product_inventory.quarantine_quantity');
        $I->assertSame('0', trim($I->grabTextFrom($cell('Write-off'))), 'product_inventory.write_off_quantity');
        $I->assertSame('3', trim($I->grabTextFrom($cell('Hold'))), 'product_inventory.cart_hold_quantity');
        $I->assertSame('2', trim($I->grabTextFrom($cell('Sales Hold'))), 'product_inventory.sales_hold_quantity');
        $I->assertSame('4', trim($I->grabTextFrom($cell('Pending'))), 'product_inventory.pending_quantity');
        $I->assertSame('5', trim($I->grabTextFrom($cell('Approved'))), 'product_inventory.approved_quantity');
        $I->assertSame('0', trim($I->grabTextFrom($cell('Backordered'))), 'product_inventory.backordered_quantity');
        $I->assertSame('0', trim($I->grabTextFrom($cell('Reserved'))), 'product_inventory.reserved_quantity');
        $I->assertSame(
            '36',
            trim($I->grabTextFrom($cell('Available') . ' strong')),
            '50 less 3 hold, 2 sales hold, 4 pending and 5 approved — the arithmetic the row exists to show',
        );

        // The working, spelled out on the cell. Without it a person checking one row has to read
        // thirteen headings to know which way each column went. Terms of zero are left out — nine
        // empty ones between the two numbers that matter is how a working stops being read.
        $I->assertSame(
            '50 Starting − 3 Hold − 2 Sales Hold − 4 Pending − 5 Approved = 36',
            $I->grabAttributeFrom($cell('Available'), 'title'),
        );

        // Three numbers written a hundred lines apart that all have to agree: the bucket columns,
        // the per-warehouse group header spanning them, and the filter row spanning them again.
        // Adding a column and forgetting either colspan shears every warehouse group off by one,
        // which reads as a CSS problem and is not one. All three now come from one resolved list in
        // the controller, and this asserts they still do — against each other, never a literal.
        //
        // Matched on th[colspan] and not on a class: the first `.is-sortable` in the header row is
        // the ID column, which carries a rowspan and no colspan at all.
        $columns = count($bucketHeadings);
        $I->assertSame(14, $columns, 'the default column set');
        $I->assertSame(
            (string) $columns,
            $I->grabAttributeFrom('table.wide-price-table thead tr:nth-child(1) th[colspan]', 'colspan'),
            'the warehouse group header must span exactly the columns under it',
        );
        $I->assertSame(
            (string) $columns,
            $I->grabAttributeFrom('table.wide-price-table thead tr.filter-row th[colspan]', 'colspan'),
            'and so must the filter row, or every filter box sits under the wrong column',
        );
    }

    public function updatingAQuantityWithTheRenderedTokenSaves(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        [$product, $warehouse] = $this->makeStockedProduct($I, 'FUNC-SKU-SAVE', 'Inventory Save Product');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/inventory');
        $token = (string) $I->grabAttributeFrom('table.wide-price-table', 'data-inventory-update-token');

        $I->sendAjaxPostRequest('/admin/inventory/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'warehouse_id' => (string) $warehouse->getId(),
            'quantity' => '77',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertSame('77', $response['quantity']);

        $I->seeInRepository(ProductInventory::class, [
            'product' => $product->getId(),
            'warehouse' => $warehouse->getId(),
            'quantity' => 77,
        ]);
    }

    public function updatingAQuantityWithoutAValidTokenIsRejectedAndLeavesTheCountAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        [$product, $warehouse] = $this->makeStockedProduct($I, 'FUNC-SKU-FORGED', 'Inventory Forged Product');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/inventory/update', [
            '_token' => 'forged',
            'product_id' => (string) $product->getId(),
            'warehouse_id' => (string) $warehouse->getId(),
            'quantity' => '77',
        ]);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(ProductInventory::class, [
            'product' => $product->getId(),
            'warehouse' => $warehouse->getId(),
            'quantity' => 50,
        ]);
    }

    /** @return array{0: ProductCore, 1: Warehouse} */
    private function makeStockedProduct(FunctionalTester $I, string $sku, string $name): array
    {
        $warehouse = (new Warehouse())->setName($name . ' Region')->setStatus('Active');
        $I->haveInRepository($warehouse);

        $product = (new ProductCore())->setSku($sku)->setName($name)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveInRepository((new ProductInventory())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setQuantity(50));

        return [$product, $warehouse];
    }
}
