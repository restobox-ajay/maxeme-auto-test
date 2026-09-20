<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A cart's billing or shipping address row.
 *
 * Structurally identical to SalesOrderAddress and EstimateAddress, and separate for the same reason
 * they are separate from each other: each document owns its rows through its own NOT NULL foreign
 * key, so the database enforces "belongs to exactly one document" and gives ON DELETE CASCADE.
 *
 * What differs is what is in it. A cart's row normally carries only source_address_id — see
 * AbstractDocumentAddress::isLinkOnly(). The copied values are written at conversion, because that
 * is when the document becomes a record of something and its identity should stop moving.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_address')]
#[ORM\UniqueConstraint(name: 'uniq_cart_address_type', fields: ['cart', 'type'])]
class CartAddress extends AbstractDocumentAddress
{
    #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'cartAddresses')]
    #[ORM\JoinColumn(name: 'cart_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Cart $cart;

    public function getCart(): Cart { return $this->cart; }
    public function setCart(Cart $cart): self { $this->cart = $cart; return $this; }
}
