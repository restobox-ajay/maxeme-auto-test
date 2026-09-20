<?php

declare(strict_types=1);

namespace WooCommerceBundle\Entity;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per WooCommerce order this connection has ever seen (#739) — the idempotency record for
 * the webhook receiver (a redelivered webhook must not mint a second SalesOrder/Invoice for the
 * same Woo order) and the backing table for the "All orders" admin screen: Woo order #, our own
 * invoice #, and whether it imported cleanly or needs attention.
 *
 * A row is written on the first delivery attempt for a (connection, woo_order_id), success, failure,
 * or skip. A row with status Error or Skipped carries no SalesOrder/Invoice — nothing partial is
 * left behind (see WooCommerceOrderImportService, which wraps the whole build in one transaction).
 * An Error row is deliberately NOT auto-retried: the row stays put until an admin acts on it, same
 * as a flagged product mapping. A Skipped row (the Woo order's own status isn't invoiceable yet —
 * e.g. pending/on-hold/failed) is the one status a later redelivery of the same Woo order IS allowed
 * to re-evaluate: once the order reaches an invoiceable status, that later delivery converts the row
 * to Imported instead of being silently short-circuited by the idempotency check.
 *
 * An Imported row is also re-examined on a later delivery, but only for one thing: Woo reporting the
 * order fully refunded, which raises and issues a credit note against $invoice (see
 * WooCommerceOrderImportService::handleUpdateToImportedOrder()) and records the result on
 * $creditMemo. Nothing else about an Imported row is ever revisited.
 */
#[ORM\Entity(repositoryClass: \WooCommerceBundle\Repository\WooCommerceOrderImportRepository::class)]
#[ORM\Table(name: 'woocommerce_order_import')]
#[ORM\UniqueConstraint(name: 'uniq_woo_order_import_connection_order', columns: ['connection_id', 'woo_order_id'])]
class WooCommerceOrderImport
{
    public const STATUS_IMPORTED = 'Imported';
    public const STATUS_ERROR = 'Error';
    public const STATUS_SKIPPED = 'Skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WooCommerceConnection::class)]
    #[ORM\JoinColumn(name: 'connection_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?WooCommerceConnection $connection = null;

    #[ORM\Column(name: 'woo_order_id')]
    private int $wooOrderId = 0;

    /** Woo's human-facing order number — usually the same as the id, but not guaranteed to be. */
    #[ORM\Column(name: 'woo_order_number', length: 40)]
    private string $wooOrderNumber = '';

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'sales_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SalesOrder $salesOrder = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    /**
     * Set once Woo reports this order fully refunded and this row's invoice has been credited for
     * it — see WooCommerceOrderImportService. Orthogonal to $status: an Imported row stays Imported
     * (the sale genuinely happened) and this is the fact bolted on afterward that it was later given
     * back. Its presence is also the idempotency guard against crediting the same refund twice on a
     * redelivered order.updated webhook.
     */
    #[ORM\ManyToOne(targetEntity: CreditMemo::class)]
    #[ORM\JoinColumn(name: 'credit_memo_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?CreditMemo $creditMemo = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_IMPORTED;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $importedAt;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConnection(): ?WooCommerceConnection
    {
        return $this->connection;
    }

    public function setConnection(WooCommerceConnection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function getWooOrderId(): int
    {
        return $this->wooOrderId;
    }

    public function setWooOrderId(int $wooOrderId): self
    {
        $this->wooOrderId = $wooOrderId;

        return $this;
    }

    public function getWooOrderNumber(): string
    {
        return $this->wooOrderNumber;
    }

    public function setWooOrderNumber(string $wooOrderNumber): self
    {
        $this->wooOrderNumber = $wooOrderNumber;

        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getSalesOrder(): ?SalesOrder
    {
        return $this->salesOrder;
    }

    public function setSalesOrder(?SalesOrder $salesOrder): self
    {
        $this->salesOrder = $salesOrder;

        return $this;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function markImported(Company $company, SalesOrder $salesOrder, Invoice $invoice): self
    {
        $this->company = $company;
        $this->salesOrder = $salesOrder;
        $this->invoice = $invoice;
        $this->status = self::STATUS_IMPORTED;
        $this->errorMessage = null;

        return $this;
    }

    public function markError(string $message): self
    {
        $this->status = self::STATUS_ERROR;
        $this->errorMessage = $message;

        return $this;
    }

    /** Recorded, not invoiced: the Woo order's own status isn't one that means "paid" yet (see WooCommerceOrderImportService). */
    public function markSkipped(string $reason): self
    {
        $this->status = self::STATUS_SKIPPED;
        $this->errorMessage = $reason;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreditMemo(): ?CreditMemo
    {
        return $this->creditMemo;
    }

    public function attachCreditMemo(CreditMemo $creditMemo): self
    {
        $this->creditMemo = $creditMemo;

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }
}
