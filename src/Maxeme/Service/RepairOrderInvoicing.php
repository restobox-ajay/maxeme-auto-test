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

    /** @throws \DomainException when the repair order has nothing to bill */
    public function issue(RepairOrder $repairOrder): Invoice
    {
        if ($repairOrder->getJobs() === [] && $repairOrder->getCharges() === []) {
            throw new \DomainException('Add a service to the repair order before issuing an invoice.');
        }

        $invoice = Invoice::forRepairOrder($repairOrder);
        foreach ($repairOrder->getJobs() as $job) {
            $serviceTax = RepairOrderCalculator::serviceTax($job);
            $service = (new InvoiceServiceLine($invoice, PrintedDocuments::billedName($job), '1', $job->getPrice(), $job->getService()))->setTax($serviceTax);
            $invoice->addServiceLine($service);
            foreach ($job->getChargeThroughLines() as $line) {
                $invoice->addServiceLine((new InvoiceServiceLine($invoice, mb_substr($line->getItemLabel(), 0, 255), $line->getQuantity(), $line->getUnitPrice(), null, $service))->setTax(LineTax::ofLine($line, $serviceTax, $this->partTaxes)));
            }
        }
        foreach ($repairOrder->getCharges() as $charge) {
            $invoice->addCharge((new InvoiceCharge($invoice, $charge->getKind()))->setLabel($charge->getLabel())->setAmount($charge->getAmount()));
        }
        $this->recalculate($invoice);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

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
            if ($row['name'] === null) {
                $errors[sprintf('lines.%d.name', $n)] = sprintf('Line %d: enter its name.', $n + 1);
            }
            if ($row['quantity'] === null || preg_match(InputRule::Quantity->regex(), $row['quantity']) !== 1 || (float) $row['quantity'] <= 0) {
                $errors[sprintf('lines.%d.quantity', $n)] = sprintf('Line %d: %s', $n + 1, InputRule::Quantity->message());
            }
            if ($row['price'] !== null && preg_match(InputRule::SignedMoney->regex(), $row['price']) !== 1) {
                $errors[sprintf('lines.%d.price', $n)] = sprintf('Line %d: %s', $n + 1, InputRule::SignedMoney->message());
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
        return !$repairOrder->getStatus()->isWorkDone() && $repairOrder->getStatus() !== RepairOrderStatus::Cancelled && $this->hasPaidInvoice($repairOrder);
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
