<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\EmailTemplate;
use App\Entity\Company;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\AdminUserCsrfTokens;
use Tests\Support\FunctionalTester;

/**
 * Creating a user with "send account email" ticked must produce ONE flash that is true.
 *
 * It used to produce two that disagreed. sendInviteIfRequested() added a `warning` when the
 * invite bounced, and the create action then added its `success` unconditionally, so an admin
 * whose mail server was down was told in the same breath that the send had failed and that
 * everything had worked. Whichever they read first was the wrong one to act on (#448).
 *
 * The count is asserted as well as the wording, and deliberately so: the pair was also what made
 * the toasts visibly overlap, since each flash raises its own notification element. One flash per
 * outcome is the invariant — a future "and here is some extra context" flash on this path would
 * bring the contradiction back in a new costume.
 *
 * The failure is induced through the real code path rather than a stubbed mailer:
 * CustomerInviteMailer::send() catches \Throwable and reports false, and a DB email_template row
 * whose body is not valid Twig makes SandboxedTemplateRenderer throw while the message is being
 * built. That is a genuine misconfiguration — these rows are editable at /admin/email-template —
 * and it is reached before the mailer, so it does not depend on transport behaviour.
 *
 * It used to be induced with an unsendable sender_from_address, relying on the Address constructor
 * throwing. That is no longer a failure at all: an invalid From: is now validated and skipped, and
 * resolution falls through to MAILER_FROM (which .env.test sets), because one bad character in an
 * admin-editable setting must not 500 every outbound email in the application. Keeping the old
 * induction would have quietly turned both of these into tests of the success path — the exact
 * trap the previous version of this comment warned about for support_email.
 */
final class AdminUserCreateInviteFlashCest
{
    use AdminUserCsrfTokens;

    /** Not valid Twig: an unclosed tag makes the sandboxed renderer throw on render. */
    private const BROKEN_TEMPLATE_BODY = '{% for x in %}';

    private function loginAsAdmin(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('invite-flash-actor@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        return $admin;
    }

    /**
     * sender_from_address is the first tier of the From: chain, so setting it decides the outcome
     * no matter what MAILER_FROM or MAILER_DSN happen to hold here.
     */
    /**
     * Installs an email_template row for the invite whose body cannot be rendered.
     *
     * send() prefers the DB row over the shipped file template, so this reaches
     * SandboxedTemplateRenderer::render() and throws inside the try, which is what makes send()
     * report false. `new_user_invited` is the code send() looks for first.
     */
    private function breakTheInviteTemplate(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $row = $entityManager->getRepository(EmailTemplate::class)->findOneBy(['code' => 'new_user_invited']);
        if (!$row instanceof EmailTemplate) {
            $row = (new EmailTemplate())->setCode('new_user_invited');
            $entityManager->persist($row);
        }
        $row->setSubject('Invitation')->setBody(self::BROKEN_TEMPLATE_BODY);
        $entityManager->flush();
    }

    private function createStaff(FunctionalTester $I, string $email, string $sendAccountEmail): void
    {
        $I->sendFormPostRequest('/admin/user/staff/create', [
            '_token' => $this->grabUserFormToken($I, '/admin/user/staff/create'),
            'email' => $email,
            'first_name' => 'Invite',
            'last_name' => 'Flash',
            'status' => 'Active',
            'role' => 'Plant Staff',
            'send_account_email' => $sendAccountEmail,
        ]);
    }

    // ---- staff ---------------------------------------------------------------------------

    public function aFailedStaffInviteReportsTheFailureAndNothingElse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->breakTheInviteTemplate($I);

        $this->createStaff($I, 'staff-invite-failed@example.test', 'yes');
        $I->seeCurrentUrlEquals('/admin/user/staff');

        $I->seeNumberOfElements('.flash-messages .flash-message', 1);
        $I->seeElement('.flash-messages .flash-message[data-type="warning"]');
        $I->see('the invite email could not be sent');
        $I->dontSee('was created successfully');

        // The contradiction was only in the messaging — the user itself is genuinely there, and
        // saying otherwise would be the opposite error.
        $I->seeInRepository(AdminUser::class, ['email' => 'staff-invite-failed@example.test']);
    }

    public function aSentStaffInviteReportsSuccessAndNothingElse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        // Leaves the shipped file template in play, which renders cleanly.

        $this->createStaff($I, 'staff-invite-sent@example.test', 'yes');
        $I->seeCurrentUrlEquals('/admin/user/staff');

        $I->seeNumberOfElements('.flash-messages .flash-message', 1);
        $I->seeElement('.flash-messages .flash-message[data-type="success"]');
        $I->see('was created successfully');
        $I->dontSee('could not be sent');
    }

    /**
     * The no-invite path never had the bug, and is pinned so a fix aimed at the failure branch
     * cannot quietly turn the ordinary create into a warning.
     */
    public function creatingAStaffUserWithoutAnInviteStillReportsSuccess(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->breakTheInviteTemplate($I);

        $this->createStaff($I, 'staff-no-invite@example.test', 'no');
        $I->seeCurrentUrlEquals('/admin/user/staff');

        $I->seeNumberOfElements('.flash-messages .flash-message', 1);
        $I->seeElement('.flash-messages .flash-message[data-type="success"]');
        $I->see('was created successfully');
    }

    // ---- customer ------------------------------------------------------------------------

    /** The customer create action carries its own copy of the same two lines, so it needs its own test. */
    public function aFailedCustomerInviteReportsTheFailureAndNothingElse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->breakTheInviteTemplate($I);

        $company = (new Company())
            ->setName('Invite Flash Co')
            ->setCode(strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8)));
        $I->haveInRepository($company);

        $I->sendFormPostRequest('/admin/user/customer/create', [
            '_token' => $this->grabUserFormToken($I, '/admin/user/customer/create'),
            'email' => 'customer-invite-failed@example.test',
            'first_name' => 'Invite',
            'last_name' => 'Flash',
            'status' => 'Active',
            'role' => 'Company Staff',
            'company' => (string) $company->getId(),
            'send_account_email' => 'yes',
        ]);
        $I->seeCurrentUrlEquals('/admin/user/customer');

        $I->seeNumberOfElements('.flash-messages .flash-message', 1);
        $I->seeElement('.flash-messages .flash-message[data-type="warning"]');
        $I->see('the invite email could not be sent');
        $I->dontSee('was created successfully');

        $I->seeInRepository(CustomerUser::class, ['email' => 'customer-invite-failed@example.test']);
    }
}
