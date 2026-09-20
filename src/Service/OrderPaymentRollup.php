<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Enum\InvoicePaymentStatus;
use Doctrine\ORM\QueryBuilder;

/**
 * An order's payment column, rolled up from its invoices (#539 stage 4).
 *
 * View-only, with no entity and no column of its own. Payment is the invoice's now, so an order's
 * payment status is an aggregate over its invoices — and an aggregate that is stored is an aggregate
 * that drifts, the first time an invoice is paid by something that forgot to update the order.
 *
 * ## One subquery, not a lookup per row
 *
 * The grids page orders, so anything computed per rendered row is a query per row, and anything
 * computed after the page has been fetched cannot be sorted or filtered on — the column would sort
 * within the page and disagree with the filter box. So the rollup goes INTO the list query, as one
 * grouped subquery correlated to the order, selected for display and reused in ORDER BY and WHERE.
 *
 * ## Why an average, and not a MIN and a MAX
 *
 * The rollup needs both ends of the invoice set: Paid only when every counting invoice is settled,
 * Not Paid only when none of them has received anything, Partially Paid for everything in between —
 * including the mixed case where one invoice is settled and another has not been touched, which a
 * MIN alone would call Not Paid and a MAX alone would call Paid.
 *
 * The average of InvoicePaymentStatus::settledScore() answers all three from one aggregate: it is 1
 * exactly when every invoice scores 1, 0 exactly when every invoice scores 0, and strictly between
 * for every mix. As a sort key it reads as "how settled is this order", which is what someone
 * sorting the column is asking. A second correlated subquery would buy nothing but a second thing
 * that has to be kept in step with the first.
 *
 * Draft and cancelled invoices are excluded, matching SalesOrder::getCountingInvoices(): a draft is
 * inert and a cancellation is owed nothing, so neither should drag an order's payment column down.
 * An order with no counting invoice at all yields NULL, which reads as Not Paid — nothing has been
 * billed, so nothing has been paid.
 *
 * ## The PHP twin
 *
 * forOrder() is the same rule over an already-loaded order, for the single-document pages where
 * there is no list query to join anything into and one order's invoices are already in memory. It
 * is deliberately the same average over the same scores rather than a second set of thresholds.
 */
final class OrderPaymentRollup
{
    /** The alias the score is selected under, and the one to sort by. */
    public const SELECT_ALIAS = 'paymentRollup';

    /**
     * Slack around the 0 and 1 comparisons. AVG returns a float, and while both ends are exact for
     * the small rationals this averages, a label is not the place to bet on that.
     */
    private const EPSILON = 0.0001;

    /** Numbers the subquery aliases so two rollups in one statement do not collide. */
    private int $aliasSequence = 0;

    /**
     * The correlated subquery, as DQL, for an order aliased $orderAlias in the outer query.
     *
     * Returned as a string rather than applied to a builder because it is used three ways — in
     * SELECT, in ORDER BY and in WHERE — and all three have to be the same expression.
     *
     * Each call gets its own subquery alias. DQL refuses a second declaration of an identifier
     * already defined anywhere in the statement, and one query legitimately asks this twice: the
     * customer order list filters its Pending tab on the rollup and sorts on it in the same
     * statement. Numbering them is what keeps that from being a parse error.
     */
    public function scoreExpression(string $orderAlias = 'o'): string
    {
        $alias = 'rollupInvoice' . ++$this->aliasSequence;

        return sprintf(
            '(SELECT AVG(CASE'
            . ' WHEN %1$s.paymentStatus = \'%2$s\' THEN %3$s'
            . ' WHEN %1$s.paymentStatus = \'%4$s\' THEN %5$s'
            . ' ELSE %6$s END)'
            . ' FROM %7$s %1$s'
            . ' WHERE %1$s.salesOrder = %8$s'
            . ' AND %1$s.status NOT IN (\'%9$s\', \'%10$s\'))',
            $alias,
            InvoicePaymentStatus::Paid->value,
            $this->score(InvoicePaymentStatus::Paid),
            InvoicePaymentStatus::PartiallyPaid->value,
            $this->score(InvoicePaymentStatus::PartiallyPaid),
            $this->score(InvoicePaymentStatus::NotPaid),
            Invoice::class,
            $orderAlias,
            'Draft',
            'Cancelled',
        );
    }

    /** Adds the score to the select list under SELECT_ALIAS, so rows come back with their label. */
    public function addSelect(QueryBuilder $qb, string $orderAlias = 'o'): QueryBuilder
    {
        return $qb->addSelect(sprintf('%s AS %s', $this->scoreExpression($orderAlias), self::SELECT_ALIAS));
    }

    /**
     * Narrows $qb to orders whose rollup reads as $status.
     *
     * Silently does nothing for a value that is not one of the three, which is what a grid filter
     * box needs: an unrecognised query string is not a request to show nothing.
     */
    public function applyFilter(QueryBuilder $qb, string $status, string $orderAlias = 'o'): void
    {
        $wanted = InvoicePaymentStatus::tryFrom(trim($status));
        if ($wanted === null) {
            return;
        }

        $qb->andWhere($this->condition($wanted, $orderAlias));
    }

    /**
     * The DQL condition for one rollup value.
     *
     * ## The NULL, and where it has to be said out loud
     *
     * An order with no counting invoice averages nothing, and NULL fails every comparison. For Paid
     * that is exactly right — an order nothing has been billed against is not paid — and for
     * Partially Paid too. For Not Paid it is the opposite of right: it would hide precisely the
     * orders the filter most obviously describes. So that one case adds "or nothing has been billed
     * yet" explicitly.
     *
     * It cannot be said with COALESCE, which is where this started: DQL admits a subselect as an
     * operand of a comparison and nowhere else, so neither `COALESCE((SELECT ...), 0)` nor
     * `(SELECT ...) BETWEEN x AND y` parses. Both bounds of the middle case are spelled out for the
     * same reason. Each call to scoreExpression() takes its own subquery alias, which is what lets
     * one appear twice in a single statement.
     */
    public function condition(InvoicePaymentStatus $status, string $orderAlias = 'o'): string
    {
        return match ($status) {
            InvoicePaymentStatus::Paid => sprintf(
                '%s >= %s',
                $this->scoreExpression($orderAlias),
                1 - self::EPSILON,
            ),
            InvoicePaymentStatus::NotPaid => sprintf(
                '(%s <= %s OR NOT %s)',
                $this->scoreExpression($orderAlias),
                self::EPSILON,
                $this->hasCountingInvoicesExpression($orderAlias),
            ),
            InvoicePaymentStatus::PartiallyPaid => sprintf(
                '(%s > %s AND %s < %s)',
                $this->scoreExpression($orderAlias),
                self::EPSILON,
                $this->scoreExpression($orderAlias),
                1 - self::EPSILON,
            ),
        };
    }

    /**
     * Orders that are not settled in full — Not Paid and Partially Paid together, plus the ones
     * nothing has been billed against.
     *
     * Spelled out rather than written as NOT condition(Paid), because that is not its complement:
     * an uninvoiced order scores NULL, NULL >= 1 is NULL, and NOT NULL is still NULL — so a plain
     * negation would quietly drop every order awaiting its first invoice out of a list of orders
     * awaiting payment.
     */
    public function unsettledCondition(string $orderAlias = 'o'): string
    {
        return sprintf(
            '(%s < %s OR NOT %s)',
            $this->scoreExpression($orderAlias),
            1 - self::EPSILON,
            $this->hasCountingInvoicesExpression($orderAlias),
        );
    }

    /** Has anything been billed against this order at all — the same invoice set the score averages. */
    private function hasCountingInvoicesExpression(string $orderAlias): string
    {
        $alias = 'rollupCounted' . ++$this->aliasSequence;

        return sprintf(
            'EXISTS (SELECT %1$s.id FROM %2$s %1$s WHERE %1$s.salesOrder = %3$s'
            . ' AND %1$s.status NOT IN (\'%4$s\', \'%5$s\'))',
            $alias,
            Invoice::class,
            $orderAlias,
            'Draft',
            'Cancelled',
        );
    }

    /** The label a selected score reads as. Null — no counting invoices — is Not Paid. */
    public function labelFor(mixed $score): InvoicePaymentStatus
    {
        if ($score === null || $score === '') {
            return InvoicePaymentStatus::NotPaid;
        }

        $value = (float) $score;

        if ($value >= 1 - self::EPSILON) {
            return InvoicePaymentStatus::Paid;
        }

        return $value <= self::EPSILON ? InvoicePaymentStatus::NotPaid : InvoicePaymentStatus::PartiallyPaid;
    }

    /** The same rollup over an order already in memory, for the single-document pages. */
    public function forOrder(SalesOrder $order): InvoicePaymentStatus
    {
        $invoices = $order->getCountingInvoices();
        if ($invoices === []) {
            return InvoicePaymentStatus::NotPaid;
        }

        $score = 0.0;
        foreach ($invoices as $invoice) {
            $score += $invoice->getPaymentStatus()->settledScore();
        }

        return $this->labelFor($score / count($invoices));
    }

    /** Formatted so the DQL carries '1.0' rather than '1', which SQLite would divide as an integer. */
    private function score(InvoicePaymentStatus $status): string
    {
        return number_format($status->settledScore(), 1, '.', '');
    }
}
