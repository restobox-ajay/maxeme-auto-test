<?php

declare(strict_types=1);

namespace BarcodeBundle\Entity;

use App\Entity\ProductCore;
use BarcodeBundle\Repository\ProductBarcodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One scannable identifier for one product (#607).
 *
 * ## Why a table and not three columns on the product
 *
 * A product carries MANY identifiers in real life and they are not a fixed set: the manufacturer's
 * UPC on the retail pack, an EAN-13 on the European carton, a GTIN-14 on the case, and a different
 * part number at each of three suppliers who all sell the same tyre. Three fixed columns named
 * `upc`, `ean` and `barcode` cannot hold three suppliers' part numbers for one product, which is
 * the requirement a distributor hits daily — so this is one row per identifier, keyed to
 * `product_core.id`, and a fourth supplier is a row rather than a migration.
 *
 * This is the correct model wherever it lives, and it is worth saying why it is not the same
 * judgement #601 made about units of measure. A product has ONE selling unit, so a side table for
 * it would be a workaround for not being able to add a column. A product has MANY barcodes, so a
 * side table is the model — it would be the right shape even with `product_core` wide open.
 *
 * ## Not globally unique, on purpose
 *
 * Two different vendors legitimately use the same part number for different things, so there is no
 * unique index on `code` alone. What IS enforced is `(product_id, code)`: the same string twice on
 * one product is a typo, never a fact.
 *
 * The consequence is that one scanned string can match two products, and the answer to that is the
 * answer ScanResolver already gives for a value that is both a bin code and a SKU — name the
 * collision and refuse. A guess at goods-in books stock against the wrong product, silently, and is
 * wrong about half the time.
 *
 * ## `party` is free text, and that is a decision
 *
 * #607 sketched `vendor_id` and `company_id` foreign keys. Neither is here. `vendor` belongs to
 * ProcurementBundle and `company` to core, and a foreign key from a product's identifier into a
 * bundle that can be switched off would make catalogue data depend on that bundle surviving.
 * `party` records whose code it is as the name a person reads off the carton, which is what the
 * receiving desk and the label actually need. Promoting it to a real association later needs no
 * change to what is stored here.
 */
#[ORM\Entity(repositoryClass: ProductBarcodeRepository::class)]
#[ORM\Table(name: 'product_barcode')]
#[ORM\UniqueConstraint(name: 'uniq_product_barcode_product_code', columns: ['product_id', 'code'])]
#[ORM\Index(name: 'idx_product_barcode_code', columns: ['code'])]
#[ORM\Index(name: 'idx_product_barcode_product', columns: ['product_id'])]
class ProductBarcode
{
    /** A 12-digit UPC-A, the North American retail pack code. */
    public const KIND_UPC = 'upc';

    /** A 13-digit EAN-13, the same thing everywhere else. */
    public const KIND_EAN = 'ean';

    /** A 14-digit GTIN-14 — the case or pallet code, a different number from the pack's. */
    public const KIND_GTIN = 'gtin';

    /** A supplier's own part number for this product. `party` says which supplier. */
    public const KIND_VENDOR_PART = 'vendor_part';

    /** A customer's own part number. A fleet customer ordering by their code is ordinary wholesale. */
    public const KIND_CUSTOMER_PART = 'customer_part';

    /** One this application minted, because the goods arrived carrying no barcode at all. */
    public const KIND_INTERNAL = 'internal';

    /** @return list<string> */
    public static function kinds(): array
    {
        return [
            self::KIND_UPC,
            self::KIND_EAN,
            self::KIND_GTIN,
            self::KIND_VENDOR_PART,
            self::KIND_CUSTOMER_PART,
            self::KIND_INTERNAL,
        ];
    }

    /** @return array<string, string> */
    public static function kindLabels(): array
    {
        return [
            self::KIND_UPC => 'UPC-A — 12 digits, the retail pack',
            self::KIND_EAN => 'EAN-13 — 13 digits, the retail pack',
            self::KIND_GTIN => 'GTIN-14 — the case or pallet code',
            self::KIND_VENDOR_PART => "Vendor part — a supplier's own number for this",
            self::KIND_CUSTOMER_PART => "Customer part — a buyer's own number for this",
            self::KIND_INTERNAL => 'Internal — minted here, because it arrived with none',
        ];
    }

    public static function describeKind(string $kind): string
    {
        return self::kindLabels()[$kind] ?? $kind;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * ON DELETE CASCADE points one way only: deleting a product takes its barcodes with it, and
     * nothing here reaches back into `product_core`. Dropping this table drops barcodes and no
     * product row notices — which is what "deleting the bundle must not take product data with it"
     * has to mean in a database.
     */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ProductCore $product = null;

    /** The scannable string, exactly as it is printed on the box. */
    #[ORM\Column(length: 64)]
    private string $code = '';

    #[ORM\Column(length: 24)]
    private string $kind = self::KIND_INTERNAL;

    /** Whose code this is — a supplier or a customer name, as a person reads it off the carton. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $party = null;

    /**
     * The one a label encodes by default. At most one per product, enforced by the service that
     * writes it rather than by a partial index: "at most one true per group" is not a constraint
     * SQLite and Doctrine express portably, and a half-enforced constraint is worse than a named
     * method that is the only way in.
     */
    #[ORM\Column(name: 'is_primary', options: ['default' => false])]
    private bool $primary = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?ProductCore
    {
        return $this->product;
    }

    public function setProduct(ProductCore $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Trimmed, and otherwise stored exactly as typed.
     *
     * NOT upper-cased and NOT stripped of punctuation: a supplier's part number can be
     * case-significant and can contain a hyphen that is part of it, and a barcode that does not
     * match what is printed on the carton is not a barcode. `product_core.sku` is matched the same
     * way — exactly — and this deliberately behaves no differently.
     */
    public function setCode(string $code): self
    {
        $this->code = trim($code);

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = \in_array($kind, self::kinds(), true) ? $kind : self::KIND_INTERNAL;

        return $this;
    }

    public function getParty(): ?string
    {
        return $this->party;
    }

    public function setParty(?string $party): self
    {
        $party = $party === null ? null : trim($party);
        $this->party = $party === '' ? null : $party;

        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function setPrimary(bool $primary): self
    {
        $this->primary = $primary;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $note = $note === null ? null : trim($note);
        $this->note = $note === '' ? null : $note;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** What a scan reports back: the code, its kind, and whose it is when anybody said. */
    public function getLabel(): string
    {
        return $this->party === null
            ? sprintf('%s (%s)', $this->code, $this->kind)
            : sprintf('%s (%s, %s)', $this->code, $this->kind, $this->party);
    }
}
