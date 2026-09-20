<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Contract\Party\PartyContact;
use App\Entity\AbstractPartyAddress;
use App\Entity\AbstractPartyNote;
use App\Entity\CompanyAddress;
use App\Entity\CompanyNote;
use App\Entity\CustomerUser;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Entity\VendorContact;
use ProcurementBundle\Entity\VendorNote;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The shared party model (#635): what is shared, and — for people — what must never be.
 *
 * Addresses and notes collapsed into mapped superclasses. People did not, and the difference is the
 * whole point of this file. `CustomerUser` IS an identity the firewall authenticates: it carries
 * `password`, `roles`, `resetToken`, `resetTokenExpiresAt`, `lastLoginAt` and `apiEnabled`. A shared
 * SUPERCLASS would put every one of those exactly one inheritance edge from `vendor_contact`, so
 * that a column added for the sell side's benefit would silently arrive on a table where nobody
 * signs in and nothing may. What they share instead is an interface describing SHAPE.
 *
 * The assertions are structural rather than behavioural on purpose. A behavioural test passes right
 * up until somebody adds the column; these fail at the moment the shape changes.
 *
 * `ProcurementBundle\Tests\Entity\VendorContactCarriesNoLoginIdentityTest` guards the other end of
 * the same boundary — the entity's own columns and the firewall's providers. This one guards the
 * thing #635 introduced: the shared abstraction itself.
 */
final class PartyContactSharesShapeAndNoCredentialsTest extends TestCase
{
    /** Exactly what a record of a human being needs, and not one method more. */
    private const EXPECTED_METHODS = [
        'getFirstName',
        'getLastName',
        'getContactName',
        'getEmail',
        'getPhone',
        'getJobTitle',
        'isPrimaryContact',
    ];

    /** Anything a user provider, a password hasher or a token flow would reach for. */
    private const FORBIDDEN_METHODS = [
        'getPassword',
        'setPassword',
        'getRoles',
        'setRoles',
        'getUserIdentifier',
        'getUsername',
        'eraseCredentials',
        'getSalt',
        'getResetToken',
        'setResetToken',
        'getResetTokenExpiresAt',
        'getLastLoginAt',
        'setLastLoginAt',
        'isApiEnabled',
        'setApiEnabled',
    ];

    public function testBothSidesImplementTheSharedContactShape(): void
    {
        self::assertTrue(is_subclass_of(CustomerUser::class, PartyContact::class));
        self::assertTrue(is_subclass_of(VendorContact::class, PartyContact::class));
    }

    public function testPeopleShareAnInterfaceAndNeverASuperclass(): void
    {
        self::assertTrue(
            (new \ReflectionClass(PartyContact::class))->isInterface(),
            'PartyContact must stay an interface. A shared parent class would put credential columns one edge from vendor_contact.',
        );

        $customerParents = class_parents(CustomerUser::class) ?: [];
        $vendorParents = class_parents(VendorContact::class) ?: [];

        self::assertSame(
            [],
            array_intersect(array_keys($customerParents), array_keys($vendorParents)),
            'CustomerUser and VendorContact must share no ancestor class at all.',
        );
    }

    public function testTheSharedShapeExposesNoCredentialSurface(): void
    {
        $reflection = new \ReflectionClass(PartyContact::class);

        foreach (self::FORBIDDEN_METHODS as $method) {
            self::assertFalse(
                $reflection->hasMethod($method),
                sprintf('PartyContact::%s() exists. The shared shape must give a security provider nothing it could use.', $method),
            );
        }

        self::assertFalse(is_subclass_of(PartyContact::class, UserInterface::class));
        self::assertFalse(is_subclass_of(PartyContact::class, PasswordAuthenticatedUserInterface::class));
    }

    /**
     * Read-only, and exactly the five concepts #635 named plus the two name parts they are built
     * from. A setter here would be a write path into two very differently governed tables.
     */
    public function testTheSharedShapeIsReadOnlyAndNoWiderThanItNeedsToBe(): void
    {
        $declared = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(PartyContact::class))->getMethods(),
        );
        sort($declared);

        $expected = self::EXPECTED_METHODS;
        sort($expected);

        self::assertSame($expected, $declared);

        foreach ($declared as $method) {
            self::assertStringStartsNotWith('set', $method, 'PartyContact must expose no setter.');
        }
    }

    /** The interface is only worth having if both sides can actually answer it. */
    public function testACustomerUserAnswersTheSharedQuestions(): void
    {
        $user = (new CustomerUser())
            ->setEmail('buyer@example.test')
            ->setFirstName('Rosa')
            ->setLastName('Nkemelu')
            ->setPhoneNumber('604-555-0177');

        self::assertSame('Rosa Nkemelu', $user->getContactName());
        self::assertSame('buyer@example.test', $user->getEmail());
        self::assertSame('604-555-0177', $user->getPhone());
        self::assertNull($user->getJobTitle(), 'The sell side records no job title.');
        self::assertFalse($user->isPrimaryContact(), 'The sell side records no primary contact.');
    }

    /** A portal user with no name still has to be displayable — the email is the fallback. */
    public function testAnUnnamedCustomerUserFallsBackToItsEmail(): void
    {
        $user = (new CustomerUser())->setEmail('ap@example.test');

        self::assertSame('ap@example.test', $user->getContactName());
    }

    public function testAVendorContactAnswersTheSharedQuestions(): void
    {
        $contact = (new VendorContact())
            ->setFirstName('Dana')
            ->setLastName('Okafor')
            ->setEmail('ap@supplier.example')
            ->setPhone('604-555-0143')
            ->setJobTitle('Accounts payable')
            ->setIsPrimary(true);

        self::assertSame('Dana Okafor', $contact->getContactName());
        self::assertSame('ap@supplier.example', $contact->getEmail());
        self::assertSame('604-555-0143', $contact->getPhone());
        self::assertSame('Accounts payable', $contact->getJobTitle());
        self::assertTrue($contact->isPrimaryContact());
    }

    /**
     * `getName()` is deliberately NOT the shared spelling.
     *
     * `AuditLogSubscriber` resolves an entity's audit label by trying `getName()` before
     * `getEmail()`, so giving `CustomerUser` a `getName()` would have relabelled every existing
     * customer-user entry in the audit log. Sharing a vocabulary must not move data that exists.
     */
    public function testTheSharedNameGetterDoesNotHijackAuditLabels(): void
    {
        self::assertFalse(
            method_exists(CustomerUser::class, 'getName'),
            'CustomerUser::getName() would change how AuditLogSubscriber labels every customer-user entry.',
        );
        self::assertFalse(
            method_exists(CustomerUser::class, 'getLabel'),
            'CustomerUser::getLabel() would change how AuditLogSubscriber labels every customer-user entry.',
        );
    }

    // ------------------------------------------------------------------ addresses and notes

    public function testBothAddressBooksExtendTheSharedParent(): void
    {
        self::assertTrue(is_subclass_of(CompanyAddress::class, AbstractPartyAddress::class));
        self::assertTrue(is_subclass_of(VendorAddress::class, AbstractPartyAddress::class));
    }

    public function testBothNoteBooksExtendTheSharedParent(): void
    {
        self::assertTrue(is_subclass_of(CompanyNote::class, AbstractPartyNote::class));
        self::assertTrue(is_subclass_of(VendorNote::class, AbstractPartyNote::class));
    }

    /**
     * The shared parent holds what both sides share and NOTHING that answers "what is this address
     * for" — the one question the two sides genuinely answer differently. Flattening that would be
     * inventing a shared concept rather than finding one.
     */
    public function testThePurposeFlagsStayedOnTheirOwnSide(): void
    {
        $parent = new \ReflectionClass(AbstractPartyAddress::class);

        foreach ([
            'isDefault', 'isOrderTo', 'isShipFrom', 'isRemitTo', 'isReturnTo',
            'isDefaultBilling', 'isDefaultShipping', 'company', 'vendor',
        ] as $property) {
            self::assertFalse(
                $parent->hasProperty($property),
                sprintf('AbstractPartyAddress::$%s belongs to one side only.', $property),
            );
        }

        self::assertTrue((new \ReflectionClass(CompanyAddress::class))->hasProperty('isDefaultBilling'));
        self::assertTrue((new \ReflectionClass(VendorAddress::class))->hasProperty('isOrderTo'));
    }

    /**
     * The vendor side's narrower storage survived the move to the shared parent.
     *
     * #605 argued at length that `vendor_address.province` holds 'BC' and `.country` holds 'CA',
     * codes rather than names, and refused to widen them to `company_address`'s free text. A
     * refactor about vocabulary does not get to overturn a decision about data as a side effect.
     */
    public function testTheVendorSideKeptItsOwnColumnWidths(): void
    {
        $overrides = (new \ReflectionClass(VendorAddress::class))
            ->getAttributes(\Doctrine\ORM\Mapping\AttributeOverrides::class);

        self::assertCount(1, $overrides, 'VendorAddress must keep its own column definitions.');

        $widths = [];
        foreach ($overrides[0]->newInstance()->overrides as $override) {
            $widths[$override->name] = [$override->column->length, $override->column->nullable];
        }

        self::assertSame([8, true], $widths['province'] ?? null, 'vendor_address.province holds a code.');
        self::assertSame([2, false], $widths['country'] ?? null, 'vendor_address.country holds an ISO alpha-2 code.');
        self::assertSame([200, false], $widths['addressLine1'] ?? null);
        self::assertSame([200, true], $widths['addressLine2'] ?? null);
        self::assertSame([120, false], $widths['city'] ?? null);
    }
}
