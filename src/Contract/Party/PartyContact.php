<?php

declare(strict_types=1);

namespace App\Contract\Party;

/**
 * A person at a party — somebody to phone or email (#635).
 *
 * ## This is an interface, and it will never be a superclass
 *
 * `App\Entity\CustomerUser` and the procurement bundle's `VendorContact` are both "a person at an
 * organisation", and the addresses and notes either side keeps DID collapse into shared mapped
 * superclasses. People do not, and the reason is a security boundary rather than a preference.
 *
 * `customer_user` carries `password`, `roles`, `reset_token`, `reset_token_expires_at`,
 * `last_login_at` and `api_enabled`, because a CustomerUser IS the identity the firewall
 * authenticates — it implements `UserInterface` and `PasswordAuthenticatedUserInterface` and its
 * `email` is the username a person is looked up by. A vendor contact is a record of who to ask for
 * when a delivery is short. Nobody from a supplier signs in here; there is nothing to authorise.
 *
 * A shared MAPPED SUPERCLASS would put every one of those credential columns exactly one
 * inheritance edge away from `vendor_contact`, where adding a field to the parent for the sell
 * side's benefit would silently give the buy side a login column. #605 excluded them deliberately
 * and permanently, VendorContact's docblock says so, and `VendorContactCarriesNoLoginIdentityTest`
 * fails a build rather than a review if it ever stops being true.
 *
 * ## So this describes SHAPE, and only shape
 *
 * Five things, all read-only, all answerable by a record of a human being: what they are called,
 * how to email them, how to phone them, what they do, and whether they are the one to reach first.
 *
 * There is deliberately NO `getPassword()`, NO `getRoles()`, NO `eraseCredentials()`, no token
 * accessor of any kind, and no setter. An implementation cannot be handed to a security provider by
 * accident because this interface gives a provider nothing it could use, and adding any of them
 * here later would be the same mistake as the superclass, one indirection further out.
 *
 * ## The two sides answer differently, and honestly
 *
 * `VendorContact` records a job title and a primary flag, because a supplier's three contacts are
 * three different people doing three different jobs and a purchase order has to go to one of them.
 * `CustomerUser` records neither: its people are portal logins, distinguished by `roles`, and
 * "which of a customer's users is the primary one" is not a question the sell side has ever asked.
 * It answers null and false — which is what "we do not record that" looks like, not a stub.
 */
interface PartyContact
{
    public function getFirstName(): ?string;

    public function getLastName(): ?string;

    /**
     * A display name that is never empty.
     *
     * Named `getContactName()` and not `getName()` on purpose: `AuditLogSubscriber` resolves an
     * entity's audit label by trying `getName()` before `getEmail()`, so giving `CustomerUser` a
     * `getName()` would quietly relabel every customer-user entry in the audit log. Sharing a
     * vocabulary must not move data that already exists.
     */
    public function getContactName(): string;

    public function getEmail(): ?string;

    public function getPhone(): ?string;

    /** "Orders desk", "Accounts payable", "Territory rep" — or null where the side does not record it. */
    public function getJobTitle(): ?string;

    /** The one to reach first — false where the side does not record it. */
    public function isPrimaryContact(): bool;
}
