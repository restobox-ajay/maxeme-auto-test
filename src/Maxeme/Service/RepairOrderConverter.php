<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Accounting\InvoiceSettings;
use App\Maxeme\Accounting\Money;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Enum\DocumentChargeKind;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Enum\ServiceLineType;
use Doctrine\DBAL\Connection;

/**
 * Turns the work recorded before repair orders existed into repair orders, so every visit has one:
 *
 *  - each invoice (and the appointment it was made from) becomes a repair order of the same
 *    customer, vehicle, mileage and tax rates, Invoiced when paid and Completed otherwise; its
 *    service lines become services (a quantity above 1 is kept in the name, "× 2", as a service has
 *    no quantity), a service's parts become its part lines, a part billed on its own becomes a
 *    service with that part as its line, and its discount a custom discount;
 *  - each appointment without an invoice becomes a repair order with nothing on it yet.
 *
 * Written in SQL in one transaction (tens of thousands of rows), with one Activity Log line for
 * the run; safe to re-run, as converted invoices and appointments point at their repair order.
 */
final class RepairOrderConverter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InvoiceSettings $taxRates,
        private readonly ActivityRecorder $activity,
    ) {
    }

    /** @return array{invoices: int, appointments: int} how many became repair orders */
    public function convert(): array
    {
        $result = $this->connection->transactional(fn (): array => [
            'invoices' => $this->convertInvoices(),
            'appointments' => $this->convertAppointments(),
        ]);

        if ($result['invoices'] + $result['appointments'] > 0) {
            $this->activity->converted('work-order', 'RepairOrder', sprintf('Converted %d invoice(s) and %d appointment(s) without one into repair orders.', $result['invoices'], $result['appointments']));
        }

        return $result;
    }

    private function convertInvoices(): int
    {
        $invoices = $this->connection->fetchAllAssociative('SELECT * FROM maxeme_invoice WHERE repair_order_id IS NULL ORDER BY id');
        if ($invoices === []) {
            return 0;
        }

        $services = $this->grouped('SELECT s.* FROM maxeme_invoice_service s JOIN maxeme_invoice i ON i.id = s.invoice_id WHERE i.repair_order_id IS NULL ORDER BY s.id', 'invoice_id');
        $parts = $this->connection->fetchAllAssociative('SELECT p.* FROM maxeme_invoice_part p JOIN maxeme_invoice i ON i.id = p.invoice_id WHERE i.repair_order_id IS NULL ORDER BY p.id');
        $partsOfService = [];
        $standaloneParts = [];
        foreach ($parts as $part) {
            if ($part['service_line_id'] !== null) {
                $partsOfService[$part['service_line_id']][] = $part;
            } else {
                $standaloneParts[$part['invoice_id']][] = $part;
            }
        }
        $serviceIds = array_flip(array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM maxeme_service')));
        $productIds = array_flip(array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM product_core')));

        foreach ($invoices as $invoice) {
            // A service per invoice service line, with its parts; a service per part billed on its own.
            $jobs = [];
            foreach ($services[$invoice['id']] ?? [] as $line) {
                $jobs[] = [
                    'name' => self::withQuantity($line['name'] ?? 'Service', (int) $line['quantity']),
                    'cents' => Money::toCents($line['sale_price']) * max(1, (int) $line['quantity']),
                    'service_id' => $line['service_id'] !== null && isset($serviceIds[(int) $line['service_id']]) ? (int) $line['service_id'] : null,
                    'parts' => $partsOfService[$line['id']] ?? [],
                ];
            }
            foreach ($standaloneParts[$invoice['id']] ?? [] as $part) {
                $jobs[] = [
                    'name' => self::withQuantity($part['name'] ?? 'Part', (int) $part['quantity']),
                    'cents' => Money::toCents($part['sale_price']) * max(1, (int) $part['quantity']),
                    'service_id' => null,
                    'parts' => [$part],
                ];
            }

            $subtotal = array_sum(array_column($jobs, 'cents'));
            $discount = -abs(Money::toCents($invoice['discount_amount']));
            $taxable = $subtotal + $discount;
            $gst = Money::percentOf($taxable, (int) $invoice['gst_rate']);
            $pst = Money::percentOf($taxable, (int) $invoice['pst_rate']);

            $this->connection->insert('maxeme_repair_order', [
                'client_id' => $invoice['client_id'],
                'vehicle_id' => $invoice['vehicle_id'],
                'status' => ($invoice['status'] === InvoiceStatus::Paid->value ? RepairOrderStatus::Invoiced : RepairOrderStatus::Completed)->value,
                'mileage' => $invoice['vehicle_mileage'] !== null ? mb_substr((string) $invoice['vehicle_mileage'], 0, 20) : null,
                'name' => $jobs !== [] ? mb_substr(implode(', ', array_column($jobs, 'name')), 0, 255) : null,
                'concern' => $invoice['note'],
                'gst_rate' => (int) $invoice['gst_rate'],
                'pst_rate' => (int) $invoice['pst_rate'],
                'subtotal' => Money::fromCents($subtotal),
                'charge_total' => Money::fromCents($discount),
                'gst' => Money::fromCents($gst),
                'pst' => Money::fromCents($pst),
                'total' => Money::fromCents($taxable + $gst + $pst),
                'created_on' => $invoice['created_on'],
                'last_updated' => $invoice['last_modified'] ?? $invoice['created_on'],
            ]);
            $repairOrderId = (int) $this->connection->lastInsertId();

            foreach ($jobs as $position => $job) {
                $this->connection->insert('maxeme_repair_order_job', [
                    'repair_order_id' => $repairOrderId,
                    'service_id' => $job['service_id'],
                    'name' => mb_substr($job['name'], 0, 255),
                    'price' => Money::fromCents($job['cents']),
                    'position' => $position,
                ]);
                $jobId = (int) $this->connection->lastInsertId();
                foreach ($job['parts'] as $linePosition => $part) {
                    $this->connection->insert('maxeme_repair_order_job_line', [
                        'job_id' => $jobId,
                        'repair_order_id' => $repairOrderId,
                        'product_id' => $part['product_id'] !== null && isset($productIds[(int) $part['product_id']]) ? (int) $part['product_id'] : null,
                        'type' => ServiceLineType::Part->value,
                        'quantity' => Money::fromCents(max(1, (int) $part['quantity']) * 100),
                        'unit_price' => Money::fromCents(Money::toCents($part['sale_price'])),
                        'charge_through' => 0,
                        'position' => $linePosition,
                        'item_name' => $part['name'] !== null ? mb_substr((string) $part['name'], 0, 255) : null,
                    ]);
                }
            }

            if ($discount !== 0) {
                $this->connection->insert('maxeme_repair_order_charge', [
                    'repair_order_id' => $repairOrderId,
                    'kind' => DocumentChargeKind::Discount->value,
                    'label' => 'Discount',
                    'amount' => Money::fromCents(-$discount),
                    'position' => 0,
                ]);
            }

            $this->connection->update('maxeme_invoice', ['repair_order_id' => $repairOrderId], ['id' => $invoice['id']]);
            if ($invoice['appointment_id'] !== null) {
                $this->connection->update('maxeme_appointment', ['repair_order_id' => $repairOrderId], ['id' => $invoice['appointment_id']]);
            }
        }

        return count($invoices);
    }

    private function convertAppointments(): int
    {
        $appointments = $this->connection->fetchAllAssociative(
            'SELECT a.* FROM maxeme_appointment a WHERE a.repair_order_id IS NULL AND NOT EXISTS (SELECT 1 FROM maxeme_invoice i WHERE i.appointment_id = a.id) ORDER BY a.id',
        );

        foreach ($appointments as $appointment) {
            $this->connection->insert('maxeme_repair_order', [
                'client_id' => $appointment['client_id'],
                'vehicle_id' => $appointment['vehicle_id'],
                'status' => (match ($appointment['status']) {
                    AppointmentStatus::InProgress->value => RepairOrderStatus::InProgress,
                    AppointmentStatus::Complete->value => RepairOrderStatus::Completed,
                    default => RepairOrderStatus::EstimateBeingBuilt,
                })->value,
                'concern' => $appointment['note'],
                'gst_rate' => $this->taxRates->gstRate(),
                'pst_rate' => $this->taxRates->pstRate(),
                'created_on' => $appointment['last_updated'],
                'last_updated' => $appointment['last_updated'],
            ]);
            $this->connection->update('maxeme_appointment', ['repair_order_id' => (int) $this->connection->lastInsertId()], ['id' => $appointment['id']]);
        }

        return count($appointments);
    }

    /** @return array<int|string, list<array<string, mixed>>> the rows of $sql by $key */
    private function grouped(string $sql, string $key): array
    {
        $grouped = [];
        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            $grouped[$row[$key]][] = $row;
        }

        return $grouped;
    }

    /** "Oil change × 2" for a quantity above 1 (a repair order service has no quantity). */
    private static function withQuantity(string $name, int $quantity): string
    {
        return $quantity > 1 ? sprintf('%s × %d', $name, $quantity) : $name;
    }
}
