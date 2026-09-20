<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One product row on a sales document, whatever the document is.
 *
 * Three line entities exist because a mapped superclass cannot parametrize targetEntity — the same
 * reason AbstractSalesDocument::getAddresses() is abstract. This interface is what lets a caller
 * stop caring which of the three it is holding: a calculator reading rows should not be able to
 * tell a cart from an order from an estimate.
 *
 * SalesOrderLine and EstimateLine already had every one of these methods; declaring the interface
 * on them is the whole change. CartItem gains price/subtotal/taxCode as unmapped properties, since
 * a cart's money is live rather than recorded.
 */
interface DocumentLine
{
    public function getProduct(): ?ProductCore;

    /**
     * int on a cart item, a decimal string on a saved document line — the two were always stored
     * differently and widening the interface is cheaper than converting either. Callers doing
     * arithmetic cast; PHP's numeric juggling handles the rest.
     */
    public function getQuantity(): int|string;

    /** Null means "no price resolved", which is not the same as zero. */
    public function getPrice(): ?string;

    public function getSubtotal(): ?string;

    /** 'E', 'G' or 'S'; null/empty counts as exempt — see TaxContext::mapTaxCode(). */
    public function getTaxCode(): ?string;

    /**
     * Where this row sits on the document, so anything indexing rows by position agrees with the
     * order the document is rendered in — see EstimateLine::$sortOrder for what went wrong without
     * it. A cart has no such order (its items are a set keyed by product), so CartItem answers 0
     * for every row and a stable sort leaves them exactly as they were.
     */
    public function getSortOrder(): int;
}
