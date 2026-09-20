<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * What a save quietly did to a line the admin typed, carried back to the form so the admin can see
 * it.
 *
 * The order and quote forms do not refuse a save over a line's numbers. Zoho lets a quantity be 0
 * (placeholder and soft-note rows depend on it) and a price be negative ("we have our reasons"),
 * and both documents are edited over and over before they are anything — so a refusal costs the
 * admin the whole form for a figure the business considers legal. Two figures are not:
 * a NEGATIVE quantity, which is saved as 0 rather than refused, and a price that was TYPED but
 * cannot be read as a number, which is saved as 0 for the same reason.
 *
 * Those coercions are the reason this class exists. Saving a typed -5 as 0 and saying nothing is
 * the dangerous half of the old behaviour, not the safe half: the row reads as a deliberate 0
 * forever after, on a document that becomes an order and then an invoice. So every coercion records
 * the row it happened to, and the form paints that row's cell red and states what happened, naming
 * the line.
 *
 * The messages and the coerced rows travel by flash because every save path redirects (order:
 * save_mode=draft_recalc back to the edit form; quote: always). FLASH_QTY_ROWS and FLASH_PRICE_ROWS
 * are data for the form rather than messages — base.html.twig skips both when it paints flashes as
 * toasts.
 */
final class SalesDocumentLineWarnings
{
    /** Flash type holding one human-readable message per coerced line. */
    public const FLASH_MESSAGES = 'line_warning';

    /**
     * Flash type holding the coerced lines' zero-based positions in the saved document, comma
     * joined — the row indexes the form paints red.
     */
    public const FLASH_QTY_ROWS = 'line_warning_qty_rows';

    /** FLASH_QTY_ROWS' twin for the rows whose price cell is painted red. */
    public const FLASH_PRICE_ROWS = 'line_warning_price_rows';

    /** @var list<int> zero-based positions of the saved lines whose quantity was coerced to 0 */
    private array $qtyRows = [];

    /** @var list<int> zero-based positions of the saved lines whose price was coerced to 0 */
    private array $priceRows = [];

    /**
     * Returns the quantity to save, recording a warning against $row when the typed one was
     * negative. $row is the line's position among the lines the save actually writes (blank rows
     * skipped), which is the position the form re-renders it at.
     */
    public function quantityForRow(float $qty, int $row): float
    {
        if ($qty < 0) {
            $this->qtyRows[] = $row;

            return 0.0;
        }

        return $qty;
    }

    /**
     * Returns the price to save for a price the admin actually TYPED, recording a warning against
     * $row when it cannot be read as a number.
     *
     * Callers must not hand a blank string here. Blank is not a bad price, it is the absence of
     * one, and the two documents answer it differently on purpose — a quote stores null and reads
     * "No pricing", an order backfills the catalog default. Only a value that was entered and could
     * not be parsed reaches this method (#253).
     *
     * The coercion is to 0 rather than to a best guess. `1,234.56` pasted from a spreadsheet is not
     * numeric, and the two flows each mangled it their own silent way: the quote dropped it to "No
     * pricing", while the order's `(float)` cast read it as 1.00 — a $1,234.56 line invoiced at a
     * dollar, with a successful save reported. Guessing at the separators would fix that particular
     * paste and quietly invent a figure for `12.5O` or `$99`, so nothing is guessed: the row is
     * zeroed and named, and the admin retypes it.
     */
    public function priceForRow(string $priceRaw, int $row): float
    {
        if (!is_numeric($priceRaw)) {
            $this->priceRows[] = $row;

            return 0.0;
        }

        return (float) $priceRaw;
    }

    public function isEmpty(): bool
    {
        return $this->qtyRows === [] && $this->priceRows === [];
    }

    /** @return list<int> */
    public function qtyRows(): array
    {
        return $this->qtyRows;
    }

    /** @return list<int> */
    public function priceRows(): array
    {
        return $this->priceRows;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_merge(
            array_map(
                static fn (int $row): string => sprintf('Line %d: negative quantity was saved as 0.', $row + 1),
                $this->qtyRows,
            ),
            array_map(
                static fn (int $row): string => sprintf('Line %d: the price was not a number and was saved as 0.', $row + 1),
                $this->priceRows,
            ),
        );
    }

    /**
     * Hands the warnings to the page the save is about to redirect to. Nothing is written when
     * there is nothing to say, so an ordinary save leaves no trace in the flash bag.
     */
    public function flashOnto(Request $request): void
    {
        $flashBag = self::flashBag($request);
        if ($flashBag === null || $this->isEmpty()) {
            return;
        }

        foreach ($this->messages() as $message) {
            $flashBag->add(self::FLASH_MESSAGES, $message);
        }
        // Each row list is written only when it has something in it, so a save that coerced a
        // quantity does not also hand the form an empty price-row list to read.
        if ($this->qtyRows !== []) {
            $flashBag->add(self::FLASH_QTY_ROWS, implode(',', $this->qtyRows));
        }
        if ($this->priceRows !== []) {
            $flashBag->add(self::FLASH_PRICE_ROWS, implode(',', $this->priceRows));
        }
    }

    /**
     * Reads them back on the form's next GET and clears them, so they are shown once. Returned in
     * the shape the two form templates render: the messages as banners, the rows as red cells.
     *
     * @return array{messages: list<string>, qtyRows: list<int>, priceRows: list<int>}
     */
    public static function takeFromFlash(Request $request): array
    {
        $flashBag = self::flashBag($request);
        if ($flashBag === null) {
            return ['messages' => [], 'qtyRows' => [], 'priceRows' => []];
        }

        $messages = array_values(array_map(
            static fn (mixed $message): string => (string) $message,
            $flashBag->get(self::FLASH_MESSAGES),
        ));

        return [
            'messages' => $messages,
            'qtyRows' => self::rowsFromFlash($flashBag, self::FLASH_QTY_ROWS),
            'priceRows' => self::rowsFromFlash($flashBag, self::FLASH_PRICE_ROWS),
        ];
    }

    /** @return list<int> */
    private static function rowsFromFlash(FlashBagInterface $flashBag, string $type): array
    {
        $rows = [];
        foreach ($flashBag->get($type) as $joined) {
            foreach (explode(',', (string) $joined) as $row) {
                if (trim($row) !== '') {
                    $rows[] = (int) $row;
                }
            }
        }

        return $rows;
    }

    private static function flashBag(Request $request): ?FlashBagInterface
    {
        if (!$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();

        return $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : null;
    }
}
