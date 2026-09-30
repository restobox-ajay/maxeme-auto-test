<?php

declare(strict_types=1);

namespace App\Maxeme\Security;

use App\Maxeme\Entity\Invoice;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may change an invoice (legacy invoiceViewAction / invoiceSaveAction):
 *  - a Super Admin (and Tech Support), always, a paid invoice included;
 *  - a role with Accounting edit, while its appointment is not complete;
 *  - anyone else, never (they see the printable invoice or the work order).
 *
 * Whether the builder or the printable invoice opens by default is a separate question (a paid
 * invoice opens printed), answered by the controller.
 *
 * @extends Voter<string, Invoice>
 */
final class InvoiceVoter extends Voter
{
    public const EDIT = 'MAXEME_INVOICE_EDIT';

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT && $subject instanceof Invoice;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($this->decisions->decide($token, [StaffRole::SuperAdmin->value])) {
            return true;
        }

        $appointment = $subject->getAppointment();

        return $this->decisions->decide($token, [Permission::ACCOUNTING_EDIT])
            && ($appointment === null || $appointment->getStatus()->isPending());
    }
}
