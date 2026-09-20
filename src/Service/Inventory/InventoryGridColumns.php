<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\ProductInventory;

/**
 * Which columns `/admin/inventory` can show, what each one reads, and what it does to Available
 * (#36).
 *
 * ## Why this is a registry and not markup
 *
 * The grid showed seven of the fourteen quantities `product_inventory` carries. The defect was
 * never "columns are missing" — it was that AVAILABLE COULD NOT BE RECONCILED. On the data the
 * screen was built against the arithmetic worked (100 − 4 − 4 = 92), but only because every hidden
 * bucket happened to be zero. The moment one is not, the page states a figure that adds up from
 * nothing on it.
 *
 * So availability is not re-derived here. {@see ProductInventory::getAvailableQuantity()} is the
 * one definition; this table records, per column, the SIGN that term carries in it. A column with
 * a sign of +1 or −1 is a term in the sum; a column with 0 is a quantity that is deliberately not.
 * `InventoryGridBucketCoverageCest` adds the signed cells up off the rendered page and asserts the
 * total is the Available cell, so the two cannot drift: a bucket added to the formula and not to
 * this table makes the page stop balancing, and the test says which one.
 *
 * ## Why the gates are per row and not per column
 *
 * Three terms only count while the bundle that WRITES them is Active — see
 * {@see \App\EventSubscriber\BundleBucketAvailabilityGate}, which stamps that decision onto every
 * row as it loads. Nothing is zeroed when a bundle goes off; the term simply leaves the sum. So the
 * effective sign is a property of the loaded ROW, and {@see self::cellsFor()} takes it from the row
 * rather than from the bundle statuses again — one source for the flag means the page cannot
 * disagree with the number it is explaining.
 *
 * ## Why a chooser exists at all
 *
 * Fourteen buckets plus Available plus four backorder settings is nineteen columns PER WAREHOUSE.
 * Showing all of them by default would answer the reconciliation defect by making the screen
 * unreadable, which is not an improvement. The `default` flag below is the answer: every term in
 * the availability sum, so the page balances the day you open it, plus Reserved — and nothing else
 * until somebody asks for it.
 */
final class InventoryGridColumns
{
    /**
     * The `admin_column_preference.view_key` this grid's saved selection lives under.
     *
     * The same table and the same repository the Product Detail grid has used since #120. A saved
     * preference rather than the query string, deliberately: this screen already builds four sets
     * of links (three sort headers, the per-warehouse sort headers, the pager and the per-page
     * select) and a column choice carried in the URL would have to be threaded through every one of
     * them, to be lost the first time somebody followed a link that forgot it. A choice about how
     * you like to work belongs to the person, not to the address — which is the same line the
     * warehouse FILTER falls on the other side of: what you are looking at right now is in the
     * query string, where it can be linked, bookmarked and backed out of.
     *
     * No migration: `admin_column_preference` already exists, keyed by (admin, view_key).
     */
    public const VIEW_KEY = 'inventory_grid';

    /** A quantity `product_inventory` holds. Every one of them has a column here. */
    public const KIND_BUCKET = 'bucket';

    /** Computed, never stored: Available itself. */
    public const KIND_DERIVED = 'derived';

    /** A backorder control. Configuration about the row, not a quantity in it. */
    public const KIND_SETTING = 'setting';

    /** `received` and `incoming` are ProcurementBundle's. */
    public const GATE_POSITIVE = 'positive';

    /** `transfer_in` and `transfer_out` are WarehouseOpsBundle's. */
    public const GATE_TRANSFER = 'transfer';

    /** `write_off` and `quarantine` are InventoryDepthBundle's. */
    public const GATE_DEPTH = 'depth';

    /**
     * Every column, in render order.
     *
     * `field` names the ProductInventory property the column reads, and is what
     * `InventoryGridBucketCoverageCest` matches against Doctrine's metadata — so a bundle adding a
     * fifteenth quantity to the entity fails that test until it appears here. `sign` is that
     * field's coefficient in getAvailableQuantity(); 0 means the quantity exists and availability
     * does not look at it, which is a fact worth showing rather than hiding.
     *
     * @var array<string, array{label: string, kind: string, field: ?string, sign: int, gate: ?string, default: bool, note: string}>
     */
    private const COLUMNS = [
        // ---- the availability sum, in the order the row is read -------------------------------
        'starting' => [
            'label' => 'Starting',
            'kind' => self::KIND_BUCKET,
            'field' => 'quantity',
            'sign' => 1,
            'gate' => null,
            'default' => true,
            'note' => 'The count imported from whatever system owns it. The only editable figure here.',
        ],
        'received' => [
            'label' => 'Received',
            'kind' => self::KIND_BUCKET,
            'field' => 'receivedQuantity',
            'sign' => 1,
            'gate' => self::GATE_POSITIVE,
            'default' => true,
            'note' => 'Arrived from a vendor since that count was taken, and sellable.',
        ],
        'transfer_in' => [
            'label' => 'Transfer In',
            'kind' => self::KIND_BUCKET,
            'field' => 'transferInQuantity',
            'sign' => 1,
            'gate' => self::GATE_TRANSFER,
            'default' => true,
            'note' => 'Everything that has ever arrived here on an internal transfer. Cumulative.',
        ],
        'transfer_out' => [
            'label' => 'Transfer Out',
            'kind' => self::KIND_BUCKET,
            'field' => 'transferOutQuantity',
            'sign' => -1,
            'gate' => self::GATE_TRANSFER,
            'default' => true,
            'note' => 'Everything that has ever left here on an internal transfer. Cumulative, and'
                . ' never reduced — the departure is permanent.',
        ],
        'quarantine' => [
            'label' => 'Quarantine',
            'kind' => self::KIND_BUCKET,
            'field' => 'quarantineQuantity',
            'sign' => -1,
            'gate' => self::GATE_DEPTH,
            'default' => true,
            'note' => 'On the shelf but withheld: received-not-inspected, damaged, or returned and'
                . ' not yet passed fit to sell.',
        ],
        'write_off' => [
            'label' => 'Write-off',
            'kind' => self::KIND_BUCKET,
            'field' => 'writeOffQuantity',
            'sign' => -1,
            'gate' => self::GATE_DEPTH,
            'default' => true,
            'note' => 'Damaged, expired, scrapped or lost, and not coming back.',
        ],
        'hold' => [
            'label' => 'Hold',
            'kind' => self::KIND_BUCKET,
            'field' => 'cartHoldQuantity',
            'sign' => -1,
            'gate' => null,
            'default' => true,
            'note' => 'Sitting in a customer cart, not yet an order.',
        ],
        'sales_hold' => [
            'label' => 'Sales Hold',
            'kind' => self::KIND_BUCKET,
            'field' => 'salesHoldQuantity',
            'sign' => -1,
            'gate' => null,
            'default' => true,
            'note' => 'Committed to accepted orders and not yet billed.',
        ],
        'pending' => [
            'label' => 'Pending',
            'kind' => self::KIND_BUCKET,
            'field' => 'pendingQuantity',
            'sign' => -1,
            'gate' => null,
            'default' => true,
            'note' => 'Committed to invoices awaiting fulfilment.',
        ],
        'approved' => [
            'label' => 'Approved',
            'kind' => self::KIND_BUCKET,
            'field' => 'approvedQuantity',
            'sign' => -1,
            'gate' => null,
            'default' => true,
            'note' => 'Committed to invoices in Processing.',
        ],
        // Completed invoices, which stopped landing in `approved` on 2026-09-15
        // (docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md:
        // InvoiceInventoryBucketResolver maps Completed => BUCKET_SHIPPED and
        // InventoryReservationReconciler calls adjustShipped()). The column was missing here while
        // ProductInventory::getAvailableQuantity() was already subtracting the field, so the units
        // came off Available and appeared under no heading — and because offPageTotal() can only
        // report what is in $cells, not even as "not shown". workingFor() printed a working that
        // did not reach its own total. Ungated, exactly as the subtraction is.
        'shipped' => [
            'label' => 'Shipped',
            'kind' => self::KIND_BUCKET,
            'field' => 'shippedQuantity',
            'sign' => -1,
            'gate' => null,
            'default' => true,
            'note' => 'Committed to invoices in Completed. Held indefinitely, since nothing decrements Starting.',
        ],
        'backordered' => [
            'label' => 'Backordered',
            'kind' => self::KIND_BUCKET,
            'field' => 'backorderedQuantity',
            'sign' => -1,
            'gate' => null,
            'default' => true,
            'note' => 'Promised beyond what is here. Carved out of the holds above, not added to them.',
        ],

        // ---- quantities availability deliberately does not look at ----------------------------
        'reserved' => [
            'label' => 'Reserved',
            'kind' => self::KIND_BUCKET,
            'field' => 'reservedQuantity',
            'sign' => 0,
            'gate' => null,
            'default' => true,
            // Default-visible although it is permanently 0 in this application, and that is the argument for
            // showing it rather than against. Anyone who believes stock committed to orders is
            // sitting in `reserved` will read Available as short by that much and go looking for
            // units that are not missing. What actually holds committed stock is Sales Hold,
            // Pending and Approved, three columns to its right, each of which IS subtracted. A
            // column reading 0 with "not counted" beside it says both of those things at once;
            // an absent column says neither.
            'note' => 'Not counted. No code in this application has ever written this column —'
                . ' stock committed to orders is in Sales Hold, Pending and Approved, which are.',
        ],
        'incoming' => [
            'label' => 'Incoming',
            'kind' => self::KIND_BUCKET,
            'field' => 'incomingQuantity',
            'sign' => 0,
            'gate' => self::GATE_POSITIVE,
            'default' => false,
            'note' => 'Not counted. On a purchase order and not yet arrived — a forecast, not stock.',
        ],
        'manual_adjustment' => [
            'label' => 'Manual adjustment',
            'kind' => self::KIND_BUCKET,
            'field' => 'manualAdjustment',
            'sign' => 0,
            'gate' => null,
            'default' => false,
            'note' => 'Not counted. Stage 1 of #425 shipped the column and nothing that reads it.',
        ],

        // ---- the answer -----------------------------------------------------------------------
        'available' => [
            'label' => 'Available',
            'kind' => self::KIND_DERIVED,
            'field' => null,
            'sign' => 0,
            'gate' => null,
            'default' => true,
            'note' => 'Starting, plus every + column, less every − column.',
        ],

        // ---- the backorder cell, broken into the four controls it crammed together ------------
        //
        // One cell held all four. A cell is not a column: it cannot be sorted, it cannot be hidden
        // on its own, and three checkboxes and a number box stacked in a grid square read as one
        // setting with decoration rather than four independent ones. Off by default because they
        // are configuration about the row and not stock in it — setting up backordering for a SKU
        // is a different visit from reading its stock, and the chooser is what makes that
        // separation cost one click instead of a wider table for everybody.
        'allow_backorder' => [
            'label' => 'Allow backorder',
            'kind' => self::KIND_SETTING,
            'field' => 'allowBackorder',
            'sign' => 0,
            'gate' => null,
            'default' => false,
            'note' => 'Whether this product may be ordered beyond stock here.',
        ],
        'max_backorder' => [
            'label' => 'Max backorder',
            'kind' => self::KIND_SETTING,
            'field' => 'maxBackorderQuantity',
            'sign' => 0,
            'gate' => null,
            'default' => false,
            'note' => 'The ceiling on Backordered. Blank means no ceiling.',
        ],
        'backorder_cap_shrinks' => [
            'label' => 'Shrink cap on restock',
            'kind' => self::KIND_SETTING,
            'field' => 'backorderCapShrinksOnRestock',
            'sign' => 0,
            'gate' => null,
            'default' => false,
            'note' => 'Preorder mode: each restock lowers the cap by what arrived.',
        ],
        'auto_release_on_restock' => [
            'label' => 'Auto-release on restock',
            'kind' => self::KIND_SETTING,
            'field' => 'autoReleaseOnRestock',
            'sign' => 0,
            'gate' => null,
            'default' => false,
            'note' => 'Assign arriving stock to the waiting queue, FIFO, without an admin doing it.',
        ],
    ];

    /**
     * @return array<string, array{label: string, kind: string, field: ?string, sign: int, gate: ?string, default: bool, note: string}>
     */
    public function all(): array
    {
        return self::COLUMNS;
    }

    /**
     * The columns shown to somebody who has never opened the chooser.
     *
     * Every term in the availability sum plus Available plus Reserved — thirteen, against the nine
     * that were here before. NOT all nineteen: a default that showed everything would trade a
     * screen that cannot be reconciled for one that cannot be read, and the owner asked for neither.
     *
     * @return list<string>
     */
    public function defaultKeys(): array
    {
        return array_keys(array_filter(self::COLUMNS, static fn (array $c): bool => $c['default']));
    }

    /**
     * The ProductInventory field each BUCKET column reads, keyed by field.
     *
     * The half of the conformance test that can be answered without rendering anything: every
     * quantity-typed field on the entity must be a key here, or be an argued exception.
     *
     * @return array<string, string> entity field => column key
     */
    public function bucketFields(): array
    {
        $fields = [];
        foreach (self::COLUMNS as $key => $column) {
            if ($column['kind'] === self::KIND_BUCKET && $column['field'] !== null) {
                $fields[$column['field']] = $key;
            }
        }

        return $fields;
    }

    /**
     * Every ProductInventory field any column reads, buckets and settings alike.
     *
     * @return list<string>
     */
    public function mappedFields(): array
    {
        return array_values(array_filter(array_column(self::COLUMNS, 'field')));
    }

    /**
     * The keys to render, given whatever was saved for this admin.
     *
     * Null (never chose) and empty (chose nothing, or chose only keys that no longer exist) both
     * fall back to the defaults. A stored selection can never blank the grid — the same rule
     * ProductController applies to the Product Detail chooser, and for the same reason: a table
     * with no columns is indistinguishable from a broken page.
     *
     * Order comes from COLUMNS, never from the submission, so the grid reads the same way for
     * everybody and the availability sum runs left to right.
     *
     * @param list<string>|null $saved
     * @return list<string>
     */
    public function resolve(?array $saved): array
    {
        if ($saved === null) {
            return $this->defaultKeys();
        }

        $keys = $this->sanitize($saved);

        return $keys === [] ? $this->defaultKeys() : $keys;
    }

    /**
     * A submitted selection reduced to known keys, in render order.
     *
     * @param array<mixed> $submitted
     * @return list<string>
     */
    public function sanitize(array $submitted): array
    {
        $wanted = array_map(static fn (mixed $v): string => (string) $v, array_values($submitted));

        return array_values(array_filter(
            array_keys(self::COLUMNS),
            static fn (string $key): bool => \in_array($key, $wanted, true),
        ));
    }

    /**
     * Everything one (product, warehouse) square needs, per column key.
     *
     * `sign` is the EFFECTIVE coefficient for this row: the nominal sign from the table above
     * unless the bundle that writes the bucket is inactive, in which case the term is not in
     * getAvailableQuantity() and must not be in the arithmetic the page shows either. `counts` is
     * that same fact said in a way a template can put a class on.
     *
     * Reading the flags off the ROW and not off BundleStatusRepository is what guarantees the page
     * and the figure it explains cannot disagree — the row carries exactly the flags its own
     * getAvailableQuantity() used.
     *
     * @return array<string, array{value: int|bool|null, sign: int, counts: bool}>
     */
    public function cellsFor(ProductInventory $row): array
    {
        return $this->cells(
            [
                self::GATE_POSITIVE => $row->positiveBucketsCount(),
                self::GATE_TRANSFER => $row->transferBucketsCount(),
                self::GATE_DEPTH => $row->depthBucketsCount(),
            ],
            [
                'quantity' => $row->getQuantity(),
                'receivedQuantity' => $row->getReceivedQuantity(),
                'transferInQuantity' => $row->getTransferInQuantity(),
                'transferOutQuantity' => $row->getTransferOutQuantity(),
                'quarantineQuantity' => $row->getQuarantineQuantity(),
                'writeOffQuantity' => $row->getWriteOffQuantity(),
                'cartHoldQuantity' => $row->getCartHoldQuantity(),
                'salesHoldQuantity' => $row->getSalesHoldQuantity(),
                'pendingQuantity' => $row->getPendingQuantity(),
                'approvedQuantity' => $row->getApprovedQuantity(),
                'shippedQuantity' => $row->getShippedQuantity(),
                'backorderedQuantity' => $row->getBackorderedQuantity(),
                'reservedQuantity' => $row->getReservedQuantity(),
                'incomingQuantity' => $row->getIncomingQuantity(),
                'manualAdjustment' => $row->getManualAdjustment(),
                'allowBackorder' => $row->isAllowBackorder(),
                'maxBackorderQuantity' => $row->getMaxBackorderQuantity(),
                'backorderCapShrinksOnRestock' => $row->isBackorderCapShrinksOnRestock(),
                'autoReleaseOnRestock' => $row->isAutoReleaseOnRestock(),
            ],
        );
    }

    /**
     * The same squares for a (product, warehouse) pair that has no `product_inventory` row at all.
     *
     * Every quantity is 0 and every setting is off — the "absent means zero, not unlimited" reading
     * AdminOrderStockValidator makes of the same missing row. The gate flags are the entity's own
     * defaults (all true), which changes nothing: a sign only ever multiplies a zero here.
     *
     * @return array<string, array{value: int|bool|null, sign: int, counts: bool}>
     */
    public function emptyCells(): array
    {
        return $this->cells(
            [self::GATE_POSITIVE => true, self::GATE_TRANSFER => true, self::GATE_DEPTH => true],
            [
                'allowBackorder' => false,
                'maxBackorderQuantity' => null,
                'backorderCapShrinksOnRestock' => false,
                'autoReleaseOnRestock' => false,
            ],
        );
    }

    /**
     * The part of Available that the chosen columns do NOT account for.
     *
     * The chooser is what makes fourteen buckets bearable, and it is also the one way the
     * reconciliation this issue exists to restore can be broken again: hide Quarantine on a row
     * holding 12 quarantined units and Available is 12 short of what the visible columns say. So
     * the page states the gap rather than leaving somebody to find it — `amount` is the signed
     * total of every availability term that is not on screen, and `labels` names the ones actually
     * carrying a figure.
     *
     * By construction, (sum of visible signed cells) + amount === getAvailableQuantity(). That is
     * the invariant `InventoryGridBucketCoverageCest` asserts off the rendered page.
     *
     * @param array<string, array{value: int|bool|null, sign: int, counts: bool}> $cells
     * @param list<string> $visibleKeys
     * @return array{amount: int, labels: list<string>}
     */
    public function offPageTotal(array $cells, array $visibleKeys): array
    {
        $amount = 0;
        $labels = [];

        foreach ($cells as $key => $cell) {
            if ($cell['sign'] === 0 || \in_array($key, $visibleKeys, true)) {
                continue;
            }

            $value = (int) $cell['value'];
            $amount += $cell['sign'] * $value;

            if ($value !== 0) {
                $labels[] = self::COLUMNS[$key]['label'];
            }
        }

        return ['amount' => $amount, 'labels' => $labels];
    }

    /**
     * The row's own arithmetic, written out: `100 Starting − 4 Hold − 4 Sales Hold = 92`.
     *
     * Goes on the Available cell as its title. The columns already carry the signs, but a person
     * checking one row should not have to read thirteen headings to do it — and on a row where part
     * of the sum is off page, this is where the missing piece is named. Terms of zero are left out;
     * see the loop.
     *
     * Built here rather than in Twig because it is arithmetic: the template's job is to put it on
     * the page, and a `{% for %}` computing a running sum in a title attribute is where a second,
     * subtly different version of getAvailableQuantity() would end up living.
     *
     * $available is never arithmetic here — only interpolated into the trailing '= …' — so it
     * takes whatever ProductInventory::getAvailableQuantity() actually returns (a decimal
     * string) as readily as the empty-row caller's literal 0. The old `int` hint fatal-errored
     * on the string case as soon as a real row (rather than the empty placeholder) reached it.
     *
     * @param array<string, array{value: int|bool|null, sign: int, counts: bool}> $cells
     * @param list<string> $visibleKeys
     */
    public function workingFor(array $cells, int|string $available, array $visibleKeys): string
    {
        $parts = [];

        foreach ($visibleKeys as $key) {
            $cell = $cells[$key] ?? null;
            if ($cell === null || $cell['sign'] === 0) {
                continue;
            }

            $value = (int) $cell['value'];
            // A term of zero is left out. Thirteen columns of which nine are empty is the ordinary
            // case, and "+ 0 Received + 0 Transfer In − 0 Quarantine" between the two numbers that
            // matter is how a working that was meant to make the row readable stops being read.
            // Nothing is hidden by it: a zero term changes no total, and the one thing an omission
            // could disguise — a term that is off the page entirely — is stated separately below.
            if ($value === 0) {
                continue;
            }

            $label = self::COLUMNS[$key]['label'];
            $parts[] = $parts === [] && $cell['sign'] > 0
                ? $value . ' ' . $label
                : ($cell['sign'] > 0 ? '+ ' : '− ') . $value . ' ' . $label;
        }

        $offPage = $this->offPageTotal($cells, $visibleKeys);
        if ($offPage['amount'] !== 0) {
            $parts[] = ($offPage['amount'] > 0 ? '+ ' : '− ') . abs($offPage['amount'])
                . ' not shown (' . implode(', ', $offPage['labels']) . ')';
        }

        return ($parts === [] ? '0' : implode(' ', $parts)) . ' = ' . (int) $available;
    }

    /**
     * @param array<string, bool> $gates
     * @param array<string, int|bool|null> $values
     * @return array<string, array{value: int|bool|null, sign: int, counts: bool}>
     */
    private function cells(array $gates, array $values): array
    {
        $cells = [];

        foreach (self::COLUMNS as $key => $column) {
            $counts = $column['gate'] === null || ($gates[$column['gate']] ?? true);
            $field = $column['field'];

            $cells[$key] = [
                'value' => $field === null
                    ? null
                    : ($values[$field] ?? ($column['kind'] === self::KIND_SETTING ? false : 0)),
                'sign' => $counts ? $column['sign'] : 0,
                'counts' => $counts,
            ];
        }

        return $cells;
    }
}
