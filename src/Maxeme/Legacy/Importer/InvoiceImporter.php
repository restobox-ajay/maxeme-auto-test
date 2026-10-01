<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use App\Maxeme\Repository\PaymentTypeRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Legacy `invoice` → maxeme_invoice, ids and keys kept (after clients, vehicles and appointments).
 * Amounts were free-text strings (blank = none); the GST / PST rates were a PHP-serialized
 * `taxes` array; a missing status meant unpaid; the payment method code becomes the Payment Type
 * of that name (LEGACY_PAYMENT_TYPES, added by the migration). The totals are copied as they were billed, not
 * recalculated.
 */
final class InvoiceImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
        private readonly PaymentTypeRepository $paymentTypes,
    ) {
    }

    /** Legacy payment_method => Payment Type name. */
    private const LEGACY_PAYMENT_TYPES = [
        'cash' => 'Cash',
        'visa' => 'Visa',
        'master' => 'Master',
        'debit' => 'Debit',
        'cheque' => 'Cheque',
    ];

    public static function name(): string
    {
        return 'invoices';
    }

    public static function order(): int
    {
        return 70;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        $rows = $legacy->iterateAssociative(
            "SELECT id, invoice_key, appointment_id, client_id, vehicle_id,
                    client_first_name, client_last_name, client_preferred_name, client_address, client_home_number,
                    client_cell_number, client_note, vehicle_year, vehicle_manufacture AS vehicle_manufacturer, vehicle_model,
                    vehicle_license, vehicle_vin, vehicle_mileage, note, recommendations,
                    COALESCE(status, 'unpaid') AS status, NULLIF(payment_method, '') AS payment_method,
                    NULLIF(payment_amount, '') AS payment_amount,
                    COALESCE(NULLIF(discount_amount, ''), 0) AS discount_amount, COALESCE(NULLIF(subtotal, ''), 0) AS subtotal,
                    COALESCE(NULLIF(sales_tax, ''), 0) AS sales_tax, COALESCE(NULLIF(total_price, ''), 0) AS total_price,
                    taxes, COALESCE(created_on, last_modified, NOW()) AS created_on, last_modified
               FROM invoice ORDER BY id",
        );

        $paymentTypeIds = [];
        foreach (self::LEGACY_PAYMENT_TYPES as $code => $name) {
            $paymentTypeIds[$code] = $this->paymentTypes->findOneByName($name)?->getId();
        }

        return $this->copier->upsert('maxeme_invoice', (static function () use ($rows, $paymentTypeIds): \Generator {
            foreach ($rows as $row) {
                $taxes = @unserialize((string) $row['taxes'], ['allowed_classes' => false]);
                unset($row['taxes']);
                $row['gst_rate'] = (int) (is_array($taxes) ? ($taxes['gst'] ?? 0) : 0);
                $row['pst_rate'] = (int) (is_array($taxes) ? ($taxes['pst'] ?? 0) : 0);
                $row['payment_type_id'] = $paymentTypeIds[(string) $row['payment_method']] ?? null;
                unset($row['payment_method']);
                yield $row;
            }
        })(), ['created_on', 'last_modified']);
    }
}
