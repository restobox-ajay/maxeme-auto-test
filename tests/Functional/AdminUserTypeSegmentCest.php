<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The {type} segment on the user routes accepts exactly "admin" or "customer".
 *
 * It used to accept anything: `$class = strtolower($type) === 'admin' ? AdminUser::class :
 * CustomerUser::class` sent every unrecognised value down the customer branch — the *more permissive*
 * of the two, where the email is editable. `/admin/user/update/banana/13` was served as a customer
 * edit.
 *
 * That was not exploitable, because $class and $group were derived from the same variable and so
 * stayed in lockstep: you could not load an AdminUser through the customer branch. But the safety of
 * the whole email-immutability rule rested on that coincidence rather than on anything enforced, which
 * is the same shape as the bug it was guarding (see #89). Now an unknown type 404s at routing, and the
 * class lookup throws rather than guessing.
 */
final class AdminUserTypeSegmentCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('type-segment-actor@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function anUnknownTypeIsNotFoundRatherThanTreatedAsCustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/user/update/banana/1');
        $I->seeResponseCodeIs(404);
    }

    public function theKnownTypesStillRoute(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $staff = (new AdminUser())->setEmail('type-segment-target@example.test');
        $staff->setPassword($hasher->hashPassword($staff, 'test-password-123'));
        $I->haveInRepository($staff);

        $I->amOnPage('/admin/user/update/admin/' . $staff->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('type-segment-target@example.test');
    }

    public function aNonNumericIdIsNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        // The id requirement came with the same change; without it the segment reached the controller
        // and was cast to 0.
        $I->amOnPage('/admin/user/update/admin/not-a-number');
        $I->seeResponseCodeIs(404);
    }

    /**
     * The destructive routes carry the same segment and had the same fall-through, so they are
     * constrained too — guarding only the one route this was noticed on would have been arbitrary.
     */
    public function theOtherTypedRoutesRejectAnUnknownTypeToo(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        foreach (['/admin/user/status/banana/1', '/admin/user/delete/banana/1', '/admin/user/reset-password/banana/1', '/admin/user/resend-invite/banana/1'] as $url) {
            $I->sendAjaxPostRequest($url, [
                '_token' => $I->csrfToken(),]);
            $I->seeResponseCodeIs(404);
        }
    }
}
