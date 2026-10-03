<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Accounting\RepairOrderCalculator;
use App\Maxeme\Accounting\LineTax;
use App\Maxeme\Accounting\InvoiceCalculator;
use App\Maxeme\Accounting\Money;
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
 * following them (Invoiced once every invoice is paid; Estimate Approval once the quote is sent).
 */
final class RepairOrderInvoicing
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceCalculator $calculator,
        private readonly InvoiceRepository $invoices,
        private readonly ValidatorInterface $validator,
        private readonly ScheduleSettings $schedule,
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
                $invoice->addServiceLine((new InvoiceServiceLine($invoice, mb_substr($line->getItemLabel(), 0, 255), $line->getQuantity(), $line->getUnitPrice(), null, $service))->setTax(LineTax::ofLine($line, $serviceTax)));
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
        $paymentType = $form->paymentTypeId !== null ? $this->entityManager->find(PaymentType::class, (int) $form->paymentTypeId) : null;
        if ($form->paymentTypeId !== null && $paymentType === null) {
            $errors['paymentTypeId'] = 'Choose a payment type from the list.';
        }

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

        $invoice->setStatus(InvoiceStatus::from((string) $form->status));
        $invoice->setPaymentType($paymentType);
        $invoice->setPaymentAmount(Money::rounded($form->paymentAmount));
        $invoice->setRecommendations($form->recommendations);
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
     * The repair order is Invoiced once it has invoices and every one not cancelled is paid. It is
     * never moved back: an unpaid invoice later leaves its status for the shop to set.
     */
    public function followInvoices(RepairOrder $repairOrder): void
    {
        $billed = array_filter($this->invoices->findBy(['repairOrder' => $repairOrder]), static fn (Invoice $invoice): bool => $invoice->getStatus() !== InvoiceStatus::Cancelled);
        if ($billed !== [] && array_filter($billed, static fn (Invoice $invoice): bool => !$invoice->isPaid()) === [] && $repairOrder->getStatus() !== RepairOrderStatus::Invoiced) {
            $repairOrder->setStatus(RepairOrderStatus::Invoiced);
            $repairOrder->touch();
            $this->entityManager->flush();
        }
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
