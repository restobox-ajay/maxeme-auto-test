<?php

declare(strict_types=1);

namespace App\Payment;

use App\Contract\Payment\PayableDocument;

/**
 * What counts as a legal claim of a payment against a document (#708, queue item 34's
 * multi-invoice/multi-bill follow-up — replaces `PaymentMoveGuard`, whose "move" question stopped
 * making sense once a payment could be applied to several documents at once instead of living on
 * exactly one).
 *
 * ## Written once against {@see PayableDocument}, called from both pools
 *
 * `InvoicePayment::applyTo()` and `VendorBillPayment::applyTo()` both call this before writing an
 * application row, passing in what THEY know about the payment (its counterparty, its currency) and
 * asking this what THE DOCUMENT allows. Neither pool implements a shared interface — see
 * `InvoicePayment`'s own docblock for why applying is typed concretely to `Invoice`/`VendorBill`
 * rather than to `PayableDocument` — so the guard takes the two facts as plain values instead of an
 * object, which is also what makes "moving" a payment (withdraw one application, apply a new one on
 * the same pool) go through the exact same two checks a fresh application does.
 *
 * ## The rules, and what each is protecting
 *
 * 1. **The target can accept a payment at all.** Asked of the document, which answers with its own
 *    sentence — {@see PayableDocument::paymentRefusal()}. On the buy side that rules out a **Void**
 *    bill: it is withdrawn and owed nothing. On the sell side it rules out a **Cancelled** invoice
 *    for the same reason, and a **Draft** for a different one.
 * 2. **Same counterparty.** A payment received from customer A cannot be claimed against customer
 *    B's invoice. This is not a misallocation being corrected — it is money fabricated to somebody
 *    who never paid it, and it would make both parties' balances wrong at once. The buy-side reading
 *    is the same sentence with "vendor" in it.
 * 3. **Same currency.** Refused, never converted — see below.
 *
 * ## Currency: refused, never converted
 *
 * There is no rate table in this application and no column to put a converted figure in, so the
 * three things a currency mismatch COULD do are: carry the number across unchanged (simply false),
 * convert it at a rate nobody stored (invents the rate), or refuse and say so. Rule 2 already
 * catches most of this, because currency is copied off the counterparty. It does not catch all of
 * it — a vendor's own currency can change between two bills being raised — so both checks exist.
 *
 * ## Overpayment: allowed, and said out loud
 *
 * NOT refused, for the same reason `PaymentMoveGuard` never refused it: recording money against a
 * document already allows overpaying it — `getBalance()` calls a negative balance "information" on
 * both sides — and a guard that refused what recording already accepts would just push the admin
 * onto a worse path to the same state. {@see self::overpaymentNotice()} is the sentence to show
 * afterwards, unchanged from the move-era guard: this rule did not need to change when moving
 * became applying.
 */
final class PaymentApplicationGuard
{
    /**
     * @throws \DomainException on the first rule the application breaks
     */
    public static function assertApplicable(
        PayableDocument $target,
        string $paymentCounterpartyKey,
        string $paymentCounterpartyName,
        ?string $paymentCurrency,
    ): void {
        // Rule 1. The document's own sentence, prefixed with what was being attempted so the person
        // is not left reading a general statement about a document they did not name.
        $refusal = $target->paymentRefusal();
        if ($refusal !== null) {
            throw new \DomainException(sprintf(
                'This payment cannot be applied to %s. %s',
                $target->getDocumentLabel(),
                $refusal,
            ));
        }

        // Rule 2.
        if ($paymentCounterpartyKey !== $target->getPaymentCounterpartyKey()) {
            throw new \DomainException(sprintf(
                'This payment cannot be applied to %s. It was received from %s and %s belongs to %s —'
                . ' applying it there would leave both parties\' balances wrong.',
                $target->getDocumentLabel(),
                $paymentCounterpartyName,
                $target->getDocumentLabel(),
                $target->getCounterpartyName(),
            ));
        }

        // Rule 3.
        if ($paymentCurrency !== $target->getCurrency()) {
            throw new \DomainException(sprintf(
                'This payment cannot be applied to %s. It is in %s and %s is in %s, and this'
                . ' application does not convert between currencies — an applied figure would state'
                . ' an amount that was never paid.',
                $target->getDocumentLabel(),
                self::currencyLabel($paymentCurrency),
                $target->getDocumentLabel(),
                self::currencyLabel($target->getCurrency()),
            ));
        }
    }

    /**
     * The sentence to show after an application that leaves the target overpaid, or null when it
     * does not.
     *
     * Called AFTER the application, not before, and that is the point: the figures it quotes are the
     * ones now in the database, so the person is told what is true rather than what was predicted.
     */
    public static function overpaymentNotice(PayableDocument $target): ?string
    {
        $balance = self::cents($target->getBalance());
        if ($balance >= 0) {
            return null;
        }

        return sprintf(
            '%s is now OVERPAID by %s %s — %s %s applied against a total of %s %s.',
            $target->getDocumentLabel(),
            self::currencyLabel($target->getCurrency()),
            self::money(-$balance),
            self::currencyLabel($target->getCurrency()),
            $target->getAmountPaid(),
            self::currencyLabel($target->getCurrency()),
            self::money(self::cents($target->getTotal() ?? '0')),
        );
    }

    /**
     * 'CAD', or the honest placeholder when a document states no currency of its own.
     *
     * `CommercialDocument::getCurrency()` is nullable for the sell side's sake — the base currency
     * lives in an AppSetting an entity cannot read. A refusal must still be a sentence, so null
     * renders as the words rather than as a blank the reader has to interpret.
     */
    private static function currencyLabel(?string $currency): string
    {
        return $currency ?? 'the base currency';
    }

    /** Whole cents, the way both documents compare money. */
    private static function cents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** Two decimals with no thousands separator, matching what the documents format. */
    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
