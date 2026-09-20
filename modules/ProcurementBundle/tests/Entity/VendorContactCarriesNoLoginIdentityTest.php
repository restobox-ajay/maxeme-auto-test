<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Entity;

use PHPUnit\Framework\TestCase;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorContact;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * `vendor_contact` is a record of a person and NEVER an account (#605).
 *
 * This is the issue's one explicit exclusion and a security boundary rather than a preference, so it
 * is asserted rather than reviewed. A vendor contact that grew a password column, a roles array or a
 * `UserInterface` implementation would be a login for a third party's staff into an application
 * whose entire admin surface sits behind `ROLE_ADMIN` — created by whoever typed the contact in, and
 * noticed by nobody, because nothing else in the codebase would have changed shape.
 *
 * The checks are deliberately structural (what the class IS) rather than behavioural (what one call
 * happens to return): a behavioural test passes right up until somebody adds the column, and the
 * point is to fail at the moment the column appears.
 */
final class VendorContactCarriesNoLoginIdentityTest extends TestCase
{
    /** Every property `customer_user` carries for the sake of authentication and this must not. */
    private const FORBIDDEN_PROPERTIES = [
        'password',
        'plainPassword',
        'roles',
        'resetToken',
        'resetTokenExpiresAt',
        'lastLoginAt',
        'apiEnabled',
        'salt',
    ];

    /** Every method a Symfony user provider or password hasher would reach for. */
    private const FORBIDDEN_METHODS = [
        'getPassword',
        'setPassword',
        'getRoles',
        'setRoles',
        'getUserIdentifier',
        'getUsername',
        'eraseCredentials',
        'getSalt',
        'setResetToken',
        'setLastLoginAt',
        'setApiEnabled',
    ];

    public function testAVendorContactIsNotASecurityIdentity(): void
    {
        self::assertFalse(
            is_subclass_of(VendorContact::class, UserInterface::class),
            'VendorContact must not implement UserInterface — a supplier\'s staff have no account here.',
        );
        self::assertFalse(
            is_subclass_of(VendorContact::class, PasswordAuthenticatedUserInterface::class),
            'VendorContact must not implement PasswordAuthenticatedUserInterface — there is no credential to authenticate.',
        );
    }

    public function testAVendorContactHasNoAuthenticationColumns(): void
    {
        $reflection = new \ReflectionClass(VendorContact::class);

        foreach (self::FORBIDDEN_PROPERTIES as $property) {
            self::assertFalse(
                $reflection->hasProperty($property),
                sprintf('VendorContact::$%s exists. A vendor contact must carry no authentication state.', $property),
            );
        }
    }

    public function testAVendorContactExposesNoAuthenticationMethods(): void
    {
        $reflection = new \ReflectionClass(VendorContact::class);

        foreach (self::FORBIDDEN_METHODS as $method) {
            self::assertFalse(
                $reflection->hasMethod($method),
                sprintf('VendorContact::%s() exists. Nothing about a vendor contact may look like a user to Symfony.', $method),
            );
        }
    }

    /**
     * The firewall's providers are the other half of the boundary: a class can be inert and still be
     * reachable if somebody wires it into security.yaml as an entity provider.
     */
    public function testVendorContactIsNotWiredIntoTheSecurityConfiguration(): void
    {
        $securityYaml = (string) file_get_contents(\dirname(__DIR__, 4) . '/config/packages/security.yaml');

        self::assertStringNotContainsString(
            'VendorContact',
            $securityYaml,
            'VendorContact appears in security.yaml. It must never be a user provider, in any firewall.',
        );
        self::assertStringNotContainsString(
            'vendor_contact',
            $securityYaml,
            'vendor_contact appears in security.yaml. Nothing may authenticate against that table.',
        );
    }

    /**
     * What it DOES carry, so that "minus login identity" cannot quietly become "minus everything".
     *
     * The mirror is only worth having if the record is usable: a job title is what distinguishes the
     * orders desk from accounts payable, and the primary flag is what decides which of three people
     * a purchase order is emailed to.
     */
    public function testAVendorContactStillRecordsThePersonAndTheirJob(): void
    {
        $contact = (new VendorContact())
            ->setFirstName('Dana')
            ->setLastName('Okafor')
            ->setJobTitle('Accounts payable')
            ->setEmail('ap@supplier.example')
            ->setPhone('604-555-0143')
            ->setIsPrimary(true);

        self::assertSame('Dana Okafor', $contact->getName());
        self::assertSame('Accounts payable', $contact->getJobTitle());
        self::assertTrue($contact->isPrimary());
        self::assertSame(VendorContact::STATUS_ACTIVE, $contact->getStatus());
        self::assertTrue($contact->isActive());
    }

    /** An orders desk with an address and no named person is a real contact, and must have a label. */
    public function testAnUnnamedContactStillHasSomethingToDisplay(): void
    {
        $contact = (new VendorContact())->setEmail('orders@supplier.example');

        self::assertSame('orders@supplier.example', $contact->getName());
    }

    public function testStatusIsCoercedToAKnownValue(): void
    {
        $contact = (new VendorContact())->setStatus('Banned');

        self::assertSame(VendorContact::STATUS_ACTIVE, $contact->getStatus());

        $contact->setStatus(VendorContact::STATUS_INACTIVE);

        self::assertFalse($contact->isActive());
    }

    /**
     * `vendor.email` first, then a contact — the fallback #605 exists for.
     *
     * 118 of 124 vendors on the old dev data had no `vendor.email`, so sending a purchase order was
     * refused for 95% of the file. It still prefers the vendor's own address where somebody set one:
     * that is the address a human deliberately put on the record.
     */
    public function testThePurchaseOrderRecipientFallsBackToAContact(): void
    {
        $vendor = (new Vendor())->setName('Harbour Supply');

        self::assertNull($vendor->getOrderEmail(), 'A vendor with no email and no contacts has nobody to send to.');

        $desk = (new VendorContact())->setJobTitle('Orders desk')->setEmail('orders@harbour.example');
        $vendor->addContact($desk);

        self::assertSame('orders@harbour.example', $vendor->getOrderEmail());

        $vendor->setEmail('mail@harbour.example');

        self::assertSame('mail@harbour.example', $vendor->getOrderEmail(), 'vendor.email must still win where it is set.');
    }

    /** An Inactive contact is somebody who left. They are not who a purchase order goes to. */
    public function testAnInactiveContactIsNotUsedAsTheRecipient(): void
    {
        $vendor = (new Vendor())->setName('Harbour Supply');
        $vendor->addContact((new VendorContact())->setEmail('left@harbour.example')->setStatus(VendorContact::STATUS_INACTIVE));

        self::assertNull($vendor->getOrderEmail());
        self::assertNull($vendor->getPrimaryContact());
    }

    public function testThePrimaryContactWinsOverTheOthers(): void
    {
        $vendor = (new Vendor())->setName('Harbour Supply');
        $vendor->addContact((new VendorContact())->setEmail('rep@harbour.example')->setJobTitle('Rep'));
        $vendor->addContact((new VendorContact())->setEmail('orders@harbour.example')->setJobTitle('Orders desk')->setIsPrimary(true));

        self::assertSame('orders@harbour.example', $vendor->getPrimaryContact()?->getEmail());
        self::assertSame('orders@harbour.example', $vendor->getOrderEmail());
    }
}
