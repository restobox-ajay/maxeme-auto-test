<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Accounting\RepairOrderCalculator;
use App\Maxeme\Accounting\LineTax;
use App\Maxeme\Accounting\InvoiceCalculator;
use App\Maxeme\Accounting\Money;
use App\Maxeme\Accounting\PartTaxes;
use App\Maxeme\Dto\IssuedInvoiceForm;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Schedule\ScheduleSettings;
use App\Maxeme\Validation\InputRule;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Maxeme\Document\PrintedDocuments;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\InvoiceCharge;
use App\Maxeme\Entity\InvoiceServiceLine;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Enum\DocumentChargeKind;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A repair order's financial documents: issuing an invoice (a frozen copy of the repair order's
 * services, their charge-through lines and its custom fees and discounts, so later changes to the
 * repair order do not alter it), deleting one, the quote being sent, and the repair order's status
 * following them (Invoiced while a paid invoice is there; Pending on Estimate once the quote is sent).
 */
final class RepairOrderInvoicing
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceCalculator $calculator,
        private readonly InvoiceRepository $invoices,
        private readonly ValidatorInterface $validator,
        private readonly ScheduleSettings $schedule,
        private readonly PartTaxes $partTaxes,
    ) {
    }

    /**
     * Issues an invoice: a copy of the repair order as it is now; with $pick (billing key => quantity,
     * partial invoicing, mockup 6611dd0) only those quantities.
     *
     * @param array<string, float>|null $pick
     *
     * @throws \DomainException when there is nothing to bill
     */
    public function issue(RepairOrder $repairOrder, ?array $pick = null): Invoice
    {
        if ($repairOrder->getJobs() === [] && $repairOrder->getCharges() === []) {
            throw new \DomainException('Add a service to the repair order before issuing an invoice.');
        }

        $invoice = Invoice::forRepairOrder($repairOrder);
        if (!$this->writeRows($invoice, $repairOrder, $pick)) {
            throw new \DomainException('Nothing was picked to invoice: enter a quantity for at least one line.');
        }
        $this->recalculate($invoice);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    /**
     * What has been charged so far on a repair order (mockup 6611dd0): every line (service,
     * charge-through line, fee / discount) with what was ordered, what is charged on its invoices that
     * are not cancelled, and what remains. Lines are matched by name: a service by its name, a line by
     * its service and name, a fee by its kind and label.
     *
     * @return list<array{key: string, kind: string, label: string, parent: ?string, ordered: float, charged: float, remaining: float, unit: string}>
     */
    public function billing(RepairOrder $repairOrder): array
    {
        $charged = [];
        $add = static function (string $key, float $quantity) use (&$charged): void {
            $charged[$key] = ($charged[$key] ?? 0) + $quantity;
        };
        foreach ($this->invoices->findForRepairOrder($repairOrder) as $invoice) {
            if ($invoice->getStatus() === InvoiceStatus::Cancelled) {
                continue;
            }
            foreach ($invoice->getTopServiceLines() as $service) {
                if (!$service->isContinued()) {
                    $add(self::serviceKey((string) $service->getName()), 1);
                }
                foreach ($invoice->getChargeThroughLines($service) as $line) {
                    $add(self::lineKey((string) $service->getName(), (string) $line->getName()), (float) $line->getQuantity());
                }
            }
            foreach ($invoice->getCharges() as $charge) {
                $add(self::chargeKey($charge->getKind()->value, $charge->getLabel()), 1);
            }
        }

        $rows = [];
        $push = static function (string $key, array $row) use (&$rows): void {
            if (isset($rows[$key])) {
                $rows[$key]['ordered'] += $row['ordered'];

                return;
            }
            $rows[$key] = ['key' => $key] + $row;
        };
        foreach ($repairOrder->getJobs() as $job) {
            $name = PrintedDocuments::billedName($job);
            $push(self::serviceKey($name), ['kind' => 'service', 'label' => $name, 'parent' => null, 'ordered' => 1.0, 'unit' => $job->getPrice()]);
            foreach ($job->getChargeThroughLines() as $line) {
                $label = mb_substr($line->getItemLabel(), 0, 255);
                $push(self::lineKey($name, $label), ['kind' => 'line', 'label' => $label, 'parent' => $name, 'ordered' => (float) $line->getQuantity(), 'unit' => $line->getUnitPrice()]);
            }
        }
        foreach ($repairOrder->getCharges() as $charge) {
            $push(self::chargeKey($charge->getKind()->value, $charge->getLabel()), ['kind' => 'charge', 'label' => $charge->getLabel(), 'parent' => null, 'ordered' => 1.0, 'unit' => $charge->getAmount()]);
        }

        return array_values(array_map(static function (array $row) use ($charged): array {
            $row['charged'] = round($charged[$row['key']] ?? 0, 2);
            $row['remaining'] = max(0, round($row['ordered'] - $row['charged'], 2));

            return $row;
        }, $rows));
    }

    /**
     * An unpaid invoice issued from a repair order is out of date (mockup 0a4baef) when the repair order
     * would now give other lines, fees or totals; with several invoices each is a share of the repair
     * order, not a copy of it, so none is.
     */
    public function isOutOfDate(Invoice $invoice): bool
    {
        $repairOrder = $invoice->getRepairOrder();
        if ($repairOrder === null || !$invoice->isIssuedFromRepairOrder() || $invoice->getStatus() !== InvoiceStatus::Unpaid) {
            return false;
        }
        if (count(array_filter($this->invoices->findForRepairOrder($repairOrder), static fn (Invoice $other): bool => $other->getStatus() !== InvoiceStatus::Cancelled)) > 1) {
            return false;
        }
        $shape = static fn (Invoice $source): string => (string) json_encode([
            array_map(static fn (InvoiceServiceLine $service): array => [$service->getName(), Money::toCents((string) $service->getSalePrice()), array_map(static fn (InvoiceServiceLine $line): array => [$line->getName(), (float) $line->getQuantity(), Money::toCents((string) $line->getSalePrice())], $source->getChargeThroughLines($service))], $source->getTopServiceLines()),
            array_map(static fn (InvoiceCharge $charge): array => [$charge->getKind()->value, $charge->getLabel(), Money::toCents($charge->getAmount())], $source->getCharges()),
        ]);
        $fresh = Invoice::forRepairOrder($repairOrder);
        $this->writeRows($fresh, $repairOrder, null);
        $this->recalculate($fresh);
        $same = $shape($fresh) === $shape($invoice) && Money::toCents($fresh->getTotalPrice()) === Money::toCents($invoice->getTotalPrice());
        // The comparison copy is never saved.
        foreach ([...$fresh->getServiceLines()->toArray(), ...$fresh->getCharges()] as $row) {
            if ($this->entityManager->contains($row)) {
                $this->entityManager->detach($row);
            }
        }

        return !$same;
    }

    /**
     * Sync Repair Order (mockup 0a4baef): the unpaid invoice takes the repair order's current lines,
     * fees, totals, rates, concern and recommendations; its number, date and payments stay. The caller
     * then lets the payments re-check it (a deposit may now cover the whole total).
     */
    public function syncFromRepairOrder(Invoice $invoice): void
    {
        $repairOrder = $invoice->getRepairOrder();
        if ($repairOrder === null || $invoice->getStatus() !== InvoiceStatus::Unpaid) {
            throw new \DomainException('Only an unpaid invoice issued from a repair order can be synced.');
        }
        foreach ($invoice->getTopServiceLines() as $service) {
            $invoice->removeServiceLine($service);
        }
        $invoice->replaceCharges([]);
        $invoice->setNote($repairOrder->getConcern());
        $invoice->setRecommendations($repairOrder->getRecommendations());
        $invoice->applyTotals($this->calculator->calculate($invoice, $invoice->getDiscountAmount(), $repairOrder->getGstRate(), $repairOrder->getPstRate()), $invoice->getDiscountAmount(), $repairOrder->getGstRate(), $repairOrder->getPstRate());
        $this->writeRows($invoice, $repairOrder, null);
        $this->recalculate($invoice);
        $this->entityManager->flush();
    }

    /**
     * The invoice rows the repair order gives (all of it, or $pick's quantities): each service with
     * its charge-through lines, then each fee / discount. A service already charged with lines left
     * comes along at 0, marked continued.
     *
     * @param array<string, float>|null $pick
     *
     * @return bool whether anything was written
     */
    private function writeRows(Invoice $invoice, RepairOrder $repairOrder, ?array $pick): bool
    {
        $want = static fn (string $key, float $full): float => $pick === null ? $full : (float) ($pick[$key] ?? 0);
        $used = [];
        $wrote = false;
        foreach ($repairOrder->getJobs() as $job) {
            $name = PrintedDocuments::billedName($job);
            $serviceTax = RepairOrderCalculator::serviceTax($job);
            $children = [];
            foreach ($job->getChargeThroughLines() as $line) {
                $label = mb_substr($line->getItemLabel(), 0, 255);
                $key = self::lineKey($name, $label);
                $quantity = isset($used[$key]) ? 0.0 : $want($key, (float) $line->getQuantity());
                $used[$key] = true;
                if ($quantity > 0) {
                    $children[] = [$line, $label, $quantity];
                }
            }
            $serviceKey = self::serviceKey($name);
            $takeService = !isset($used[$serviceKey]) && $want($serviceKey, 1) > 0;
            $used[$serviceKey] = true;
            if (!$takeService && $children === []) {
                continue;
            }
            $service = (new InvoiceServiceLine($invoice, $name, '1', $takeService ? $job->getPrice() : '0.00', $job->getService()))->setTax($serviceTax);
            if (!$takeService) {
                $service->markContinued();
            }
            $invoice->addServiceLine($service);
            foreach ($children as [$line, $label, $quantity]) {
                $invoice->addServiceLine((new InvoiceServiceLine($invoice, $label, Money::rounded((string) $quantity), $line->getUnitPrice(), null, $service))->setTax(LineTax::ofLine($line, $serviceTax, $this->partTaxes)));
            }
            $wrote = true;
        }
        foreach ($repairOrder->getCharges() as $charge) {
            $key = self::chargeKey($charge->getKind()->value, $charge->getLabel());
            $take = !isset($used[$key]) && $want($key, 1) > 0;
            $used[$key] = true;
            if ($take) {
                $invoice->addCharge((new InvoiceCharge($invoice, $charge->getKind()))->setLabel($charge->getLabel())->setAmount($charge->getAmount()));
                $wrote = true;
            }
        }

        return $wrote;
    }

    private static function serviceKey(string $name): string { return 'S|' . $name; }
    private static function lineKey(string $service, string $line): string { return 'L|' . $service . '|' . $line; }
    private static function chargeKey(string $kind, string $label): string { return 'C|' . $kind . '|' . $label; }

    /** Totals from the invoice's own lines and charges (after issuing or editing it). */
    public function recalculate(Invoice $invoice): void
    {
        $invoice->applyTotals($this->calculator->calculate($invoice, $invoice->getDiscountAmount(), $invoice->getGstRate(), $invoice->getPstRate()), $invoice->getDiscountAmount(), $invoice->getGstRate(), $invoice->getPstRate());
    }

    /**
     * Saves an issued invoice's edit page; nothing changes when anything is refused.
     *
     * @return array<string, string> field => message
     */
    public function update(Invoice $invoice, IssuedInvoiceForm $form): array
    {
        // Paid, so locked (mockup 6611dd0): change its payments first. Its Remark saves on its own.
        if ($invoice->isPaid()) {
            return ['status' => 'This invoice is paid, so it is locked. To change it, edit or remove its payments first.'];
        }
        $errors = FieldErrors::from($this->validator->validate($form));

        $lines = [];
        foreach ($invoice->getServiceLines() as $line) {
            $lines[(string) $line->getId()] = $line;
        }
        $kept = [];
        foreach ($form->lines as $n => $row) {
            $line = $lines[$row['id']] ?? null;
            if ($line === null) {
                continue;
            }
            // An issued line keeps its name and price; its quantity can only be lowered (mockup 6611dd0):
            // to charge more, change the repair order and issue another invoice.
            $row['name'] = $line->getName();
            $row['price'] = $line->getSalePrice();
            if ($row['quantity'] === null || preg_match(InputRule::Quantity->regex(), $row['quantity']) !== 1 || (float) $row['quantity'] <= 0) {
                $errors[sprintf('lines.%d.quantity', $n)] = sprintf('Line %d: %s', $n + 1, InputRule::Quantity->message());
            } elseif ((float) $row['quantity'] > (float) $line->getQuantity() + 0.00001) {
                $errors[sprintf('lines.%d.quantity', $n)] = sprintf('Line %d: a quantity can only be lowered, to at most %s. To charge more, change the repair order and issue another invoice.', $n + 1, (string) (float) $line->getQuantity());
            }
            $kept[$row['id']] = [$line, $row];
        }

        $charges = [];
        $existingCharges = [];
        foreach ($invoice->getCharges() as $charge) {
            $existingCharges[(string) $charge->getId()] = $charge;
        }
        foreach ($form->charges as $n => $row) {
            foreach (FieldErrors::from($this->validator->validate($row)) as $property => $message) {
                $errors[sprintf('charges.%d.%s', $n, $property)] = sprintf('Fee / discount %d: %s', $n + 1, $message);
            }
            $kind = $row->getKind();
            if ($kind !== null) {
                $charges[] = [$existingCharges[(string) $row->id] ?? new InvoiceCharge($invoice, $kind), $row, $kind];
            }
        }

        // A discount can't take the total below zero (mockup 8b30420): items + fees must cover the discounts.
        if ($errors === []) {
            $cents = 0;
            foreach ($kept as [$line, $row]) {
                $cents += Money::times($row['quantity'], (string) $row['price']);
            }
            foreach ($invoice->getPartLines() as $part) {
                $cents += $part->totalCents();
            }
            $cents += Money::toCents($invoice->getDiscountAmount());
            foreach ($form->charges as $row) {
                $amount = abs(Money::toCents((string) Money::rounded($row->amount)));
                $cents += $row->getKind() === DocumentChargeKind::Discount ? -$amount : $amount;
            }
            if ($cents < 0) {
                $errors['charges'] = "A discount can't be more than the subtotal plus fees: it would take the total below zero.";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        foreach ($lines as $id => $line) {
            if (!isset($kept[$id]) && $invoice->getServiceLines()->contains($line)) {
                $invoice->removeServiceLine($line);
            }
        }
        foreach ($kept as [$line, $row]) {
            $line->change((string) $row['name'], (string) Money::rounded($row['quantity']), Money::rounded($row['price']));
        }
        $invoice->replaceCharges(array_map(static fn (array $charge): InvoiceCharge => $charge[0]->setKind($charge[2])->setLabel((string) $charge[1]->label)->setAmount((string) Money::rounded($charge[1]->amount)), $charges));

        // Cancelled or not; Paid / Unpaid follow its payments (InvoicePayments::sync, run by the caller).
        if ($form->status === InvoiceStatus::Cancelled->value) {
            $invoice->setStatus(InvoiceStatus::Cancelled);
        } elseif ($invoice->getStatus() === InvoiceStatus::Cancelled) {
            $invoice->setStatus(InvoiceStatus::Unpaid);
        }
        $invoice->setRecommendations($form->recommendations);
        $invoice->setAdminNote($form->adminNote);
        $invoice->setLastModified($form->date !== null ? $this->schedule->toUtc($form->date) : $invoice->getLastModified());
        $this->recalculate($invoice);
        $this->entityManager->flush();

        if ($invoice->getRepairOrder() !== null) {
            $this->followInvoices($invoice->getRepairOrder());
        }

        return [];
    }

    /** Only an invoice issued from a repair order is deleted here; a paid one is cancelled, not deleted. */
    public function delete(Invoice $invoice): void
    {
        if (!$invoice->isIssuedFromRepairOrder()) {
            throw new \DomainException('Only an invoice issued from a repair order can be deleted here.');
        }
        if ($invoice->isPaid()) {
            throw new \DomainException('A paid invoice cannot be deleted; set it to Cancelled instead.');
        }
        $repairOrder = $invoice->getRepairOrder();
        $this->entityManager->remove($invoice);
        $this->entityManager->flush();
        if ($repairOrder !== null) {
            $this->followInvoices($repairOrder);
        }
    }

    /**
     * The repair order follows its invoices (RepairOrder::followPaidInvoice, mockup 8b30420): Invoiced
     * while it has a paid invoice and its work is done; back to Completed (or Picked Up) when none is
     * left. A paid invoice on a repair order still open (paid ahead of time) does not close it: the
     * operator is asked (paidWhileOpen()).
     */
    public function followInvoices(RepairOrder $repairOrder): void
    {
        if ($repairOrder->followPaidInvoice($this->hasPaidInvoice($repairOrder))) {
            $this->entityManager->flush();
        }
    }

    /** It has a paid invoice but is still open (before Completed): ask whether to complete and lock it (mockup 84d136d). */
    public function paidWhileOpen(RepairOrder $repairOrder): bool
    {
        return $repairOrder->getStatus()->isOpen() && $this->hasPaidInvoice($repairOrder);
    }

    /** "Complete and lock": the work is done and paid, so it is Completed, then Invoiced (its appointments completed). */
    public function completeAndLock(RepairOrder $repairOrder): void
    {
        if (!$this->paidWhileOpen($repairOrder)) {
            throw new \DomainException('Only an open repair order with a paid invoice can be completed and locked here.');
        }
        $repairOrder->setStatus(RepairOrderStatus::Completed);
        $repairOrder->followPaidInvoice(true);
        $this->entityManager->flush();
    }

    /** It has invoices that are not cancelled. */
    public function hasLiveInvoice(RepairOrder $repairOrder): bool
    {
        return array_filter($this->invoices->findForRepairOrder($repairOrder), static fn (Invoice $invoice): bool => $invoice->getStatus() !== InvoiceStatus::Cancelled) !== [];
    }

    private function hasPaidInvoice(RepairOrder $repairOrder): bool
    {
        return array_filter($this->invoices->findForRepairOrder($repairOrder), static fn (Invoice $invoice): bool => $invoice->isPaid()) !== [];
    }

    /** The quote was emailed: an estimate still being built now waits on the customer. */
    public function quoteSent(RepairOrder $repairOrder): void
    {
        if ($repairOrder->getStatus() === RepairOrderStatus::EstimateBeingBuilt) {
            $repairOrder->setStatus(RepairOrderStatus::EstimateApproval);
            $repairOrder->touch();
            $this->entityManager->flush();
        }
    }
}
