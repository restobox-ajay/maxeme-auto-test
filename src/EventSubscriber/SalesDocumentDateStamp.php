<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AbstractSalesDocument;
use App\Entity\CreditMemo;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Service\BusinessDate;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Dates a new order or quote with today's calendar date in the configured display timezone.
 *
 * AbstractSalesDocument used to stamp this in its constructor, with new \DateTimeImmutable('today').
 * That reads PHP's ambient default timezone, which Kernel pins to UTC — so for any shop whose
 * display timezone is behind UTC, an order raised during that offset window was dated tomorrow from
 * the admin's point of view. The date the admin was about to see on the screen and the date being
 * written were simply different days.
 *
 * "Today" is a question about the display timezone, and only BusinessDate can answer it. An entity
 * constructor cannot reach a service, so the stamp moves to the one place that can — persist time.
 * A listener rather than a call at each creation site for the same reason ProductSyncSourceGuard is
 * one: a per-site rule is defeated by exactly what it guards against, a creation path written later
 * that forgets. sales_order.document_date and estimate.document_date are both NOT NULL, so forgetting is not a
 * subtly wrong value, it is a failed flush.
 *
 * Only fills a blank. A date the caller set — the admin order form's Order Date, the quote form's
 * Quote Date, the date an accepted quote carries onto the order it becomes — is what the document
 * is dated, and is never overwritten here.
 *
 * Entity-scoped to the four classes that have the column NOT NULL. Cart shares the mapped
 * superclass but wants no date: nobody raises a purchase order against a basket, and it takes one
 * only if it becomes an order, from the path that converts it.
 *
 * Invoice joined them with #539. It is normally raised from an order and copies that order's date,
 * so the stamp rarely has anything to do — but invoice.document_date is NOT NULL exactly like the
 * other two, and the whole argument for a listener over a call at each creation site is that a path
 * written later must not be able to forget.
 *
 * CreditMemo joined with #586, and its own AttributeOverride narrows document_date to NOT NULL the
 * same way. Its admin create form has always submitted a `document_date` field, which is why this
 * absence went unnoticed for so long — but CreditMemo::class was never actually added here, despite
 * the entity's own class docblock claiming it was, until a programmatic caller (the WooCommerce
 * connector's refund-to-credit-note path) that raises one with no form behind it hit the NOT NULL
 * constraint directly. This is the fix, not a workaround in the caller: the whole point of this
 * listener existing at all is that a path written later must not have to remember to set the date
 * itself.
 */
#[AsEntityListener(event: Events::prePersist, entity: SalesOrder::class)]
#[AsEntityListener(event: Events::prePersist, entity: Estimate::class)]
#[AsEntityListener(event: Events::prePersist, entity: Invoice::class)]
#[AsEntityListener(event: Events::prePersist, entity: CreditMemo::class)]
final class SalesDocumentDateStamp
{
    public function __construct(private readonly BusinessDate $businessDate)
    {
    }

    public function prePersist(AbstractSalesDocument $document, PrePersistEventArgs $event): void
    {
        if (trim((string) $document->getDocumentDate()) === '') {
            $document->setDocumentDate($this->businessDate->today());
        }
    }
}
