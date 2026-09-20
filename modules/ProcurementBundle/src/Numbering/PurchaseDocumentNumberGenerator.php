<?php

declare(strict_types=1);

namespace ProcurementBundle\Numbering;

use App\Service\AppSettings;
use App\Service\DocumentNumberAllocator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PO, receipt and bill numbers (#555) — the same shape as OrderNumberGenerator,
 * EstimateNumberGenerator and InvoiceNumberGenerator, through the same allocator.
 *
 * `DocumentNumberAllocator` is a core service and using it is not a coupling this bundle has to
 * apologise for: core reads nothing here, and allocating from the shared per-(kind, prefix) counter
 * is what stops two concurrent receivers being handed the same receipt number. See
 * migrations/Version20260802110000.php (#304) for why a counter table replaced `SELECT MAX(...)`,
 * and DocumentNumberAllocator's own docblock for why it issues `BEGIN IMMEDIATE`.
 *
 * One class for three kinds rather than three near-identical classes: on the sales side each
 * generator predates the allocator and kept its own file, but there is nothing to preserve here and
 * three copies of eleven lines is three places for a default to drift.
 *
 * Each kind gets its own counter, because each prefix is separately configurable and two sequences
 * sharing a counter would leave holes in both.
 *
 * A cancelled PO and a voided bill keep their numbers, and the allocator only ever increments, so
 * neither sequence has a hole an auditor would have to ask about.
 */
final class PurchaseDocumentNumberGenerator
{
    public const KIND_PURCHASE_ORDER = 'purchase_order';

    /**
     * Still `purchase_receipt` after #642 renamed the table to `goods_receipt`, and deliberately so:
     * this value is not a table name, it is the STORED key in `document_number_counter.kind`. Every
     * instance that has ever booked a receipt already has a row under it. Changing the constant
     * would strand that row and start a second counter beside it — recoverable, because `seed()`
     * re-derives from `MAX(receipt_number)`, but it would be a write to existing data to avoid it,
     * and there is nothing to gain: nobody reads this string outside the allocator.
     */
    public const KIND_RECEIPT = 'purchase_receipt';

    public const KIND_BILL = 'vendor_bill';
    /** #637 */
    public const KIND_RFQ = 'rfq';
    public const KIND_RFQ_REPLY = 'rfq_vendor_reply';
    /** #638 */
    public const KIND_VENDOR_RETURN = 'vendor_return';
    public const KIND_DEBIT_MEMO = 'debit_memo';

    /**
     * Setting key, default prefix, table and column per kind — the four things the allocator needs.
     *
     * The table/column pair is only ever used to SEED a counter that does not exist yet, by taking
     * `MAX(CAST(SUBSTR(...)))` over what is already there. That matters for an instance that
     * imported historical purchase orders before this bundle was installed: the sequence picks up
     * after them rather than colliding with them on its first allocation.
     *
     * @var array<string, array{setting: string, default: string, table: string, column: string}>
     */
    private const KINDS = [
        self::KIND_PURCHASE_ORDER => ['setting' => 'purchase_order_number_prefix', 'default' => 'PO-', 'table' => 'purchase_order', 'column' => 'po_number'],
        // `setting` is likewise a stored key (`app_setting.setting_key`) and keeps its old name for
        // the same reason as KIND_RECEIPT; `table` is a real table name and follows #642's rename.
        self::KIND_RECEIPT => ['setting' => 'purchase_receipt_number_prefix', 'default' => 'RC-', 'table' => 'goods_receipt', 'column' => 'receipt_number'],
        self::KIND_BILL => ['setting' => 'vendor_bill_number_prefix', 'default' => 'BILL-', 'table' => 'vendor_bill', 'column' => 'bill_number'],
        self::KIND_RFQ => ['setting' => 'rfq_number_prefix', 'default' => 'RFQ-', 'table' => 'rfq', 'column' => 'document_number'],
        self::KIND_RFQ_REPLY => ['setting' => 'rfq_vendor_reply_number_prefix', 'default' => 'RFQR-', 'table' => 'rfq_vendor_reply', 'column' => 'reply_number'],
        self::KIND_VENDOR_RETURN => ['setting' => 'vendor_return_number_prefix', 'default' => 'VR-', 'table' => 'vendor_return', 'column' => 'document_number'],
        self::KIND_DEBIT_MEMO => ['setting' => 'debit_memo_number_prefix', 'default' => 'DM-', 'table' => 'debit_memo', 'column' => 'document_number'],
    ];

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly DocumentNumberAllocator $allocator,
    ) {
    }

    public function next(EntityManagerInterface $entityManager, string $kind): string
    {
        $config = self::KINDS[$kind] ?? throw new \InvalidArgumentException(sprintf('Unknown purchase document kind "%s".', $kind));

        $prefix = $this->prefixFor($kind);

        $next = $this->allocator->next($entityManager, $kind, $prefix, $config['table'], $config['column']);

        return sprintf('%s%d', $prefix, $next);
    }

    /**
     * The prefix in force for a kind, falling back to its default.
     *
     * Public because the settings screen displays it, and it must display the same value the next
     * allocation will use. On the sales side that agreement is maintained by hand — InvoiceNumber
     * Generator's docblock warns that ConfigController repeats its default — and repeating a
     * constant in two files is how the two eventually disagree in front of an admin.
     */
    public function prefixFor(string $kind): string
    {
        $config = self::KINDS[$kind] ?? throw new \InvalidArgumentException(sprintf('Unknown purchase document kind "%s".', $kind));

        $prefix = trim((string) $this->appSettings->get($config['setting'], $config['default']));

        return $prefix !== '' ? $prefix : $config['default'];
    }

    public function settingKeyFor(string $kind): string
    {
        return (self::KINDS[$kind] ?? throw new \InvalidArgumentException(sprintf('Unknown purchase document kind "%s".', $kind)))['setting'];
    }

    /**
     * The prefix used when no setting row exists.
     *
     * Public for the same reason as prefixFor(): the Document Prefixes screen shows this as the
     * placeholder and as the value before anything is saved, and it must be the one the next
     * allocation would actually use rather than a second copy that can drift (#615).
     */
    public function defaultFor(string $kind): string
    {
        return (self::KINDS[$kind] ?? throw new \InvalidArgumentException(sprintf('Unknown purchase document kind "%s".', $kind)))['default'];
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }
}
