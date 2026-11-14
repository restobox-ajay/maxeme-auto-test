<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\Money;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\InvoicePayment;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Repository\PaymentTypeRepository;
use App\Maxeme\Schedule\ScheduleSettings;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\InvoicePayments;
use App\Maxeme\Service\RepairOrderInvoicing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An invoice's payments (mockup 345c037): the Payments page (total, paid, balance due, each payment),
 * Add / Edit payment, Delete, and Mark Paid (the whole balance, from the invoice's Payment menu). The
 * invoice follows them (InvoicePayments). A payment that makes the invoice Paid while its repair order
 * is still open returns to the invoice, which asks whether to complete and lock it (mockup 84d136d).
 */
final class InvoicePaymentController extends AbstractMaxemeController
{
    public function __construct(
        private readonly InvoicePayments $payments,
        private readonly PaymentTypeRepository $paymentTypes,
        private readonly DocumentNumbers $numbers,
        private readonly ScheduleSettings $schedule,
        private readonly RepairOrderInvoicing $invoicing,
    ) {
    }

    #[Route('/admin/invoices/{invoiceKey}/payments', name: 'maxeme_invoice_payments', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function index(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice): Response
    {
        return $this->render('maxeme/invoice/payments.html.twig', [
            'invoice' => $invoice,
            'payments' => $this->payments->of($invoice),
            'paid' => Money::fromCents($this->payments->paidCents($invoice)),
            'balance' => Money::fromCents($this->payments->balanceCents($invoice)),
        ]);
    }

    #[Route('/admin/invoices/{invoiceKey}/payments/new', name: 'maxeme_invoice_payment_new', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function new(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request): Response
    {
        return $this->form($invoice, null, $request);
    }

    #[Route('/admin/invoice-payments/{id}/edit', name: 'maxeme_invoice_payment_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function edit(#[MapEntity] InvoicePayment $payment, Request $request): Response
    {
        return $this->form($payment->getInvoice(), $payment, $request);
    }

    #[Route('/admin/invoice-payments/{id}/delete', name: 'maxeme_invoice_payment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function delete(#[MapEntity] InvoicePayment $payment): JsonResponse
    {
        $message = sprintf('Payment of $%s deleted from %s. The balance goes back up.', $payment->getAmount(), $this->numbers->number($payment->getInvoice()));
        $this->payments->delete($payment);

        return $this->json(['message' => $message]);
    }

    /** Payment › Mark Paid: one payment of the whole balance, by the chosen method. */
    #[Route('/admin/invoices/{invoiceKey}/mark-paid', name: 'maxeme_invoice_mark_paid', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function markPaid(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request, EntityManagerInterface $entityManager): Response
    {
        $type = $entityManager->find(PaymentType::class, $request->request->getInt('payment_type_id'));
        try {
            $this->payments->markPaid($invoice, $type instanceof PaymentType ? $type : null);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
        }
        $this->addFlash('success', sprintf('%s marked paid.', $this->numbers->number($invoice)));

        return $this->afterPayment($invoice, true);
    }

    private function form(Invoice $invoice, ?InvoicePayment $payment, Request $request): Response
    {
        $balance = $this->payments->balanceCents($invoice);
        $room = $balance + ($payment !== null ? Money::toCents($payment->getAmount()) : 0);
        $last = $this->payments->of($invoice);
        $last = $last !== [] ? $last[count($last) - 1] : null;
        $values = [
            'amount' => $payment !== null ? $payment->getAmount() : Money::fromCents($room),
            'payment_type_id' => (string) ($payment?->getPaymentType()?->getId() ?? $last?->getPaymentType()?->getId() ?? ''),
            'paid_at' => $this->schedule->toLocal($payment?->getPaidAt() ?? new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'note' => $payment?->getNote() ?? '',
        ];
        $error = null;
        if ($request->isMethod('POST')) {
            $values = [
                'amount' => str_replace(',', '', trim((string) $request->request->get('amount', ''))),
                'payment_type_id' => (string) $request->request->get('payment_type_id', ''),
                'paid_at' => trim((string) $request->request->get('paid_at', '')),
                'note' => trim((string) $request->request->get('note', '')),
            ];
            $type = $values['payment_type_id'] !== '' ? $this->paymentTypes->find((int) $values['payment_type_id']) : null;
            try {
                if (preg_match('/^-?\d+(\.\d{1,2})?$/', $values['amount']) !== 1) {
                    throw new \DomainException('Enter the amount as dollars and cents, e.g. 120.00.');
                }
                $paidAt = $values['paid_at'] !== '' ? $this->schedule->toUtc(str_replace('T', ' ', $values['paid_at']) . ':00') : new \DateTimeImmutable();
                $this->payments->save($invoice, $payment, $type, $values['amount'], $paidAt, mb_substr($values['note'], 0, 255));
                $this->addFlash('success', $payment !== null ? 'Payment saved.' : 'Payment added.');

                return $this->afterPayment($invoice, false);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('maxeme/invoice/payment_form.html.twig', [
            'invoice' => $invoice,
            'payment' => $payment,
            'values' => $values,
            'error' => $error,
            'paid' => Money::fromCents($this->payments->paidCents($invoice)),
            'balance' => Money::fromCents($balance),
            'types' => $this->paymentTypes->findActiveOrOwn($payment?->getPaymentType()),
        ], new Response(status: $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Paid while its repair order is still open: back to the invoice, which offers Complete and lock; else the payments (or the invoice). */
    private function afterPayment(Invoice $invoice, bool $toInvoice): Response
    {
        $repairOrder = $invoice->getRepairOrder();
        if ($repairOrder !== null && $this->invoicing->paidWhileOpen($repairOrder)) {
            return $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey(), 'offerClose' => 1]);
        }

        return $toInvoice
            ? $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()])
            : $this->redirectToRoute('maxeme_invoice_payments', ['invoiceKey' => $invoice->getInvoiceKey()]);
    }
}
