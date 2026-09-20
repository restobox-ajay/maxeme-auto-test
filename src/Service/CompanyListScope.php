<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;

/**
 * Which customer a document list is scoped to, when the query names one by id.
 *
 * The document grids have always had a company-NAME filter, and a name is not an identity: "Acme"
 * matches "Acme Holdings" too, so a LIKE cannot mean one specific customer and a drill-through from
 * a customer record has no way to say "this one". An id can. The name filter stays — it is what
 * somebody typing into the Company column wants — and this is the precise one beside it.
 *
 * Three states, and the third is the reason this is a type rather than a nullable Company:
 *
 *   - NOT REQUESTED   the query named no customer. The list is unscoped.
 *   - RESOLVED        the query named one and it exists. The list is that customer's.
 *   - UNRESOLVED      the query named one and it does not exist, or is not a number at all.
 *
 * A nullable Company collapses the last two into "no scope", which is the failure that matters
 * here: somebody who asked for one customer's invoices would be shown every customer's, with a
 * grid that looks exactly like the one they asked for and totals that are not theirs. So UNRESOLVED
 * is carried separately and the callers fail CLOSED on it — nothing listed, and the screen says
 * why. That is a deliberate difference from `/admin/order`, which flashes and then lists everything
 * (OrderController::orders()); the flash is a toast that a reader can miss, and the grid under it
 * is wrong in a way that reads as right.
 *
 * An id is only ever read as an id: `12abc` is UNRESOLVED, not customer 12. A cast would have
 * silently answered 12, which is the same class of mistake one level down.
 */
final class CompanyListScope
{
    private function __construct(
        private readonly ?Company $company,
        private readonly ?string $requestedId,
    ) {
    }

    /** The query named no customer at all. */
    public static function none(): self
    {
        return new self(null, null);
    }

    /** The query named this customer and it exists. */
    public static function of(Company $company, string $requestedId): self
    {
        return new self($company, $requestedId);
    }

    /** The query named a customer that could not be found, or a value that is not an id. */
    public static function unresolved(string $requestedId): self
    {
        return new self(null, $requestedId);
    }

    /**
     * The customer id a request asks a list to scope to, exactly as it was typed, or null when it
     * asks for none.
     *
     * Two spellings, both accepted:
     *
     *   - `InvoiceSearch[company_id]` — the shape every other document grid already uses for this
     *     (`OrderSearch`, `CreditMemoSearch`, `SalesReturnSearch`), and the one the screen's own
     *     links generate, so a scoped grid keeps its scope through its filters, tabs and paging.
     *   - `company_id` — the bare form, so a hand-written or hand-edited drill-through link works.
     *
     * `$bareKeys` is that second list, in precedence order, and it is a parameter because one screen
     * spells it differently: the sales return grid has always also answered `?company=`, read there
     * through `getInt()`. Dropping that spelling would quietly UNSCOPE every link using it, which is
     * the failure this class exists to prevent — so it is passed in rather than deleted. The nested
     * shape is still read first, whatever the bare keys are.
     *
     * `$query` is the whole of `$request->query->all()` rather than `all('InvoiceSearch')`, which
     * throws a BadRequestException when the parameter arrives as a scalar. Reading the bag once and
     * indexing it cannot throw whatever shape the URL is, which is half of "must not 500".
     *
     * An empty value is NOT a request for a scope: the company picker posts `company_id=""` when
     * nobody has been chosen yet, and on a list that means "no customer named", not "no customer
     * matches". Null is that case — nothing was asked for.
     *
     * The empty STRING is the third answer and it is not the same one: something was asked for and
     * it cannot be shown as typed, because it did not arrive as a value at all (`?company_id[]=1`).
     * It is still a request for a scope, so it still fails closed; only the notice differs.
     *
     * @param array<string, mixed> $query
     * @param list<string>          $bareKeys the un-nested parameter names to read, in precedence order
     */
    public static function requestedIdIn(array $query, string $searchKey, array $bareKeys = ['company_id']): ?string
    {
        $search = $query[$searchKey] ?? null;
        $raw = is_array($search) ? ($search['company_id'] ?? null) : null;

        foreach ($bareKeys as $bareKey) {
            // `??=` so the first key that is present at all wins, including when its value is the
            // empty string: `?company=` is somebody having cleared the box, not a reason to go
            // looking for another spelling of the same question.
            $raw ??= $query[$bareKey] ?? null;
        }

        if ($raw === null) {
            return null;
        }

        if (!is_scalar($raw)) {
            return '';
        }

        return trim((string) $raw) === '' ? null : trim((string) $raw);
    }

    /** Whether a raw request value can be an id at all — digits only, and a real row can never be 0. */
    public static function isIdShaped(string $raw): bool
    {
        return preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0;
    }

    /** The customer this list is scoped to, or null when it is not scoped to one. */
    public function company(): ?Company
    {
        return $this->company;
    }

    /** The id as the query gave it, for the links that carry the scope onward and the notice that names it. */
    public function requestedId(): ?string
    {
        return $this->requestedId;
    }

    public function isRequested(): bool
    {
        return $this->requestedId !== null;
    }

    /** Asked for a customer, got nothing. The callers list nothing rather than listing everything. */
    public function isUnresolved(): bool
    {
        return $this->requestedId !== null && !$this->company instanceof Company;
    }
}
