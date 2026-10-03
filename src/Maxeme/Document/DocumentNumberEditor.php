<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Change Invoice # / Change Work Order # (spec item 19): a number typed in replaces the automatic
 * "INV-00001482" / "WO-00000042" wherever the document is shown, printed or emailed (DocumentNumbers);
 * blank goes back to the automatic one. A number must be unique among invoices (or work orders),
 * typed in or automatic. The change is in the record's Activity Log like any other field.
 */
final class DocumentNumberEditor
{
    public const MAX_LENGTH = 40;
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._\/-]*$/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    /** @return string|null why the number can't be used; null once it is saved */
    public function changeInvoiceNumber(Invoice $invoice, ?string $number): ?string
    {
        $number = self::clean($number);
        $error = $number !== null ? (self::invalid($number) ?? $this->invoiceClash($invoice, $number)) : null;
        if ($error === null) {
            $invoice->setCustomNumber($number);
            $this->entityManager->flush();
        }

        return $error;
    }

    /** @return string|null why the number can't be used; null once it is saved */
    public function changeWorkOrderNumber(RepairOrder $repairOrder, ?string $number): ?string
    {
        $number = self::clean($number);
        $error = $number !== null ? (self::invalid($number) ?? $this->workOrderClash($repairOrder, $number)) : null;
        if ($error === null) {
            $repairOrder->setWorkOrderNumber($number);
            $this->entityManager->flush();
        }

        return $error;
    }

    private function invoiceClash(Invoice $invoice, string $number): ?string
    {
        $other = $this->entityManager->createQueryBuilder()
            ->select('i')->from(Invoice::class, 'i')
            ->andWhere('LOWER(i.customNumber) = :number')->setParameter('number', mb_strtolower($number))
            ->andWhere('i != :self')->setParameter('self', $invoice)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($other === null) {
            $id = self::automaticId($number, $this->numbers->prefix(DocumentKind::Invoice->prefixKey()));
            $other = $id !== null && $id !== $invoice->getId() ? $this->entityManager->find(Invoice::class, $id) : null;
            $other = $other?->getCustomNumber() === null ? $other : null;
        }

        return $other !== null ? sprintf('%s is already the number of another invoice.', $number) : null;
    }

    private function workOrderClash(RepairOrder $repairOrder, string $number): ?string
    {
        $other = $this->entityManager->createQueryBuilder()
            ->select('r')->from(RepairOrder::class, 'r')
            ->andWhere('LOWER(r.workOrderNumber) = :number')->setParameter('number', mb_strtolower($number))
            ->andWhere('r != :self')->setParameter('self', $repairOrder)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($other === null) {
            $id = self::automaticId($number, $this->numbers->prefix(DocumentKind::WorkOrder->prefixKey()));
            $other = $id !== null && $id !== $repairOrder->getId() ? $this->entityManager->find(RepairOrder::class, $id) : null;
            $other = $other?->getWorkOrderNumber() === null ? $other : null;
        }

        return $other !== null ? sprintf('%s is already the number of another work order.', $number) : null;
    }

    /** The id an automatic number ("INV-00001482", or just "1482") stands for, if it looks like one. */
    private static function automaticId(string $number, string $prefix): ?int
    {
        $rest = $prefix !== '' && stripos($number, $prefix) === 0 ? substr($number, strlen($prefix)) : $number;

        return preg_match('/^\d{1,9}$/', $rest) === 1 ? (int) $rest : null;
    }

    private static function invalid(string $number): ?string
    {
        if (mb_strlen($number) > self::MAX_LENGTH) {
            return sprintf('Keep the number to %d characters.', self::MAX_LENGTH);
        }

        return preg_match(self::PATTERN, $number) === 1 ? null : 'Use letters, digits, spaces and . _ / - only, starting with a letter or digit.';
    }

    private static function clean(?string $number): ?string
    {
        $number = trim((string) $number);

        return $number !== '' ? $number : null;
    }
}
