<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The one place that turns "who is signed in" into a DocumentActor (#539 stage 2).
 *
 * This resolution existed twice before this class: AuditLogger::resolveActor(), which had Security
 * injected and returned [type, id, name], and CheckoutController::documentActorName(), which rolled
 * its own from the CustomerUser it already held. Both now come from here, so an admin and a
 * customer are identified the same way whichever side of the app wrote the row.
 *
 * Actions take the DocumentActor this returns rather than calling it themselves. That is the whole
 * point: an action that reaches for the ambient user cannot be called by a console command, and the
 * stale-unpaid sweep needs exactly that — the same Invoice::cancel() an admin's button calls,
 * attributed to a cron job.
 */
final class DocumentActorResolver
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function resolve(): DocumentActor
    {
        $user = $this->security->getUser();

        if ($user instanceof AdminUser) {
            return DocumentActor::forAdmin($user);
        }

        if ($user instanceof CustomerUser) {
            return DocumentActor::forCustomer($user);
        }

        return DocumentActor::system();
    }
}
