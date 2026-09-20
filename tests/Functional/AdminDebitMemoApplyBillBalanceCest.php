<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * POST /admin/bundles/procurement/debit-memos/{id}/apply (#771) — the buy-side mirror of the
 * AdminCreditMemoCest coverage added for #770.
 *
 * Before this, VendorBill::getBalance() never counted a debit memo's own applications, so the
 * bill's Balance never moved after an apply, and DebitMemo::applyTo() capped an application only
 * at the MEMO's own remaining balance — never the BILL's — so a second memo could be booked
 * against a bill already fully covered.
 */
final class AdminDebitMemoApplyBillBalanceCest
{
    public function applyingAMemoReducesTheBillsBalance(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);
        $bill = $this->seedBill($I, $vendor, '100.00');
        $memoId = $this->issuedMemo($I, $vendor, '100.00');

        $this->apply($I, $memoId, $bill->getId(), '40.00');

        $em = $I->grabService(EntityManagerInterface::class);
        $reloadedBill = $em->getRepository(VendorBill::class)->find($bill->getId());
        $I->assertSame('60.00', $reloadedBill->getBalance(), 'forty of a hundred debited leaves sixty owed');
    }

    /** #771's second symptom: a second memo could still be booked against an already-covered bill. */
    public function applyingMoreThanTheBillsBalanceIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->seedVendor($I);
        $bill = $this->seedBill($I, $vendor, '50.00');
        $memoId = $this->issuedMemo($I, $vendor, '1000.00');

        $this->apply($I, $memoId, $bill->getId(), '100.00');

        $em = $I->grabService(EntityManagerInterface::class);
        $reloadedBill = $em->getRepository(VendorBill::class)->find($bill->getId());
        $I->assertSame('50.00', $reloadedBill->getBalance(), 'the refused application left the bill untouched');

        $applied = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM debit_memo_application WHERE vendor_bill_id = ?',
            [$bill->getId()],
        );
        $I->assertSame(0, $applied, 'the bill took nothing');
    }

    private function apply(FunctionalTester $I, int $memoId, int $billId, string $amount): void
    {
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form[action$="/apply"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/apply', [
            '_token' => $token,
            'vendor_bill_id' => (string) $billId,
            'applied_at' => '2026-09-01',
            'amount' => $amount,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    private function seedVendor(FunctionalTester $I): Vendor
    {
        $vendor = (new Vendor())->setName('Debit Memo Apply Vendor ' . uniqid())->setPaymentTerm('Net 30');
        $I->haveInRepository($vendor);

        return $vendor;
    }

    private function seedBill(FunctionalTester $I, Vendor $vendor, string $total): VendorBill
    {
        $bill = (new VendorBill())
            ->setBillNumber('DMA-BILL-' . uniqid())
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setDocumentDate('2026-08-01')
            ->setTotal($total);
        $I->haveInRepository($bill);

        return $bill;
    }

    private function issuedMemo(FunctionalTester $I, Vendor $vendor, string $total): int
    {
        $memo = (new DebitMemo())
            ->setDocumentNumber('DM-' . uniqid())
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setDocumentDate('2026-08-01')
            ->setTotal($total);
        $memo->issue();
        $I->haveInRepository($memo);

        return (int) $memo->getId();
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('debit-memo-apply-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }
}
