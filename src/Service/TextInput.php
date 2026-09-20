<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Single source of truth for "blank-as-null, trimmed" text field normalization (issue #303).
 *
 * Before this, three separate private nullableString() methods (App\Controller\Admin\
 * AbstractAdminController, App\Controller\Customer\CheckoutController, App\Controller\Customer\
 * OrderController) each did their own trim-then-null-if-empty, none of which stripped control
 * characters. A raw NUL (or other C0 byte) submitted in a text field was stored verbatim and later
 * written into served HTML, PDFs (Dompdf treats an embedded NUL as end-of-string in places),
 * audit-trail snapshots and exports — invalid HTML at best, silent truncation downstream at worst.
 *
 * Tab, newline and carriage return are kept: they are legitimate content in a textarea (special
 * instructions, delivery instructions) and trim() already strips them from the ends on its own.
 * Everything else in \x00-\x1F plus \x7F (DEL) is removed outright — there is no legitimate reason
 * for a name, PO number, or address line to carry one.
 */
final class TextInput
{
    /** C0 controls and DEL, except tab (\x09), LF (\x0A) and CR (\x0D). */
    private const DISALLOWED_CONTROL_CHARS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /**
     * Cap for delivery instructions, wherever they are written.
     *
     * Every other capped field passes nullableStringMax() its own column's declared `length:`, so
     * the number is a schema fact and needs no name. Delivery instructions have no such fact to
     * quote: both columns holding them are `type: 'text'` (SQLite CLOB), which is why they were the
     * one address field #302/#334 left uncapped — SQLite stores whatever it is handed, and the
     * value then renders on packing slips, invoice PDFs and picking screens, where a multi-kilobyte
     * paste is not a long instruction but a broken document.
     *
     * 500 is therefore a product decision rather than a column width, and it is named here because
     * it has to hold across TWO different entities on two different tables:
     * CompanyAddress::$deliveryInstructions (the customer's address book — written by registration,
     * the customer address-book form and the admin company-address form) and
     * AbstractDocumentAddress::$deliveryInstructions (the frozen per-document snapshot on an order
     * or quote — written by the admin order/quote address cards, and by the copyFrom()/
     * copyFromSnapshot() that carry a value forward from one row into another).
     *
     * Anchoring it to either entity would make the other side's writers reach across into an
     * unrelated aggregate for a rule that is not about that table, so it lives with the helper that
     * enforces it — the one class all of those call sites already import. A bound that holds on the
     * address book but not on the document it turns into would be worse than none at all: it makes
     * the limit depend on which screen you saved from, which is the exact drift these paths have
     * already produced once.
     *
     * Truncating, not refusing (see nullableStringMax()): an instruction losing its tail past 500
     * characters is degraded, not misleading, and blocking a whole registration or order save over
     * it costs more than it saves.
     */
    public const DELIVERY_INSTRUCTIONS_MAX_LENGTH = 500;

    public static function nullableString(mixed $value): ?string
    {
        // A field this reads is expected to arrive as a scalar. An array does too when a form's
        // repeated `name[]` is tampered with into `name[key][]` — e.g. an order/quote line's
        // `sku`/`weight`/`unit`/`batch` posted as `lines[0][sku][]=x` — and casting THAT to string
        // is a PHP warning, not a value; some environments promote it to an uncaught error and a
        // stack-trace 500 (#395). Treated as absent, the same as a field that never arrived.
        if ($value !== null && !is_scalar($value)) {
            return null;
        }

        $normalized = trim(self::stripControlCharacters((string) ($value ?? '')));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Same as nullableString(), plus a hard cap on length (issue #302). $maxLength should match the
     * target column's own declared length — nothing enforced it before this, so SQLite (this app's
     * only real deployment target) stored a value of any size verbatim.
     *
     * Truncates rather than refusing: a customer pasting an oversized value into checkout should not
     * have the whole order blocked over one field. Silent truncation is an accepted tradeoff here,
     * not a general validation strategy — a field where losing characters past the cap would be
     * actively misleading (as opposed to merely long) should refuse instead, via the validator
     * infrastructure from #222.
     */
    public static function nullableStringMax(mixed $value, int $maxLength): ?string
    {
        $normalized = self::nullableString($value);

        return $normalized === null ? null : substr($normalized, 0, $maxLength);
    }

    public static function stripControlCharacters(string $value): string
    {
        return preg_replace(self::DISALLOWED_CONTROL_CHARS, '', $value) ?? $value;
    }

    /**
     * Same as nullableStringMax(), except CR and LF are stripped rather than kept.
     *
     * nullableStringMax() deliberately keeps them for textarea-style fields (special instructions,
     * delivery instructions), where an embedded newline is legitimate content. A single-line
     * reference field like a PO number has no such case: a newline in the middle of one is a paste
     * artifact, not a second line, and trim() only ever catches one at the very edges — a value
     * like "PO\n123" keeps its embedded break all the way to storage and every later render.
     */
    public static function oneLineStringMax(mixed $value, int $maxLength): ?string
    {
        $normalized = self::nullableString($value);

        return $normalized === null ? null : substr(str_replace(["\r", "\n"], '', $normalized), 0, $maxLength);
    }

    /**
     * A submitted calendar date, normalized to a 'Y-m-d' string, or null if it isn't one.
     *
     * Calendar dates (a document's PO/quote date) are stored as plain strings rather than as a
     * date column, because they have no instant: "the order is dated the 6th" is true in every
     * timezone at once. Round-tripping one through a DateTimeImmutable gave it a midnight it never
     * had, and anything that later converted that midnight — Doctrine writing UTC, Twig's |date
     * filter reading back in the display zone — could move it a day. Keeping it a string means
     * there is no conversion to get wrong.
     *
     * That makes the format the app's own invariant, so it is enforced here, at the door, on the
     * way in. The `|` in the format resets the parsed time and, with the round-trip comparison
     * below, makes this strict: createFromFormat() alone accepts '2026-02-31' and quietly rolls it
     * forward to March 3rd, so a typo would be stored as a real but wrong date.
     */
    public static function calendarDate(mixed $value): ?string
    {
        $normalized = self::nullableString($value);
        if ($normalized === null) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d|', $normalized, new \DateTimeZone('UTC'));

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $normalized
            ? $normalized
            : null;
    }
}
