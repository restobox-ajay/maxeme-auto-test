<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AdminUser;
use App\Service\ReferenceData\ReferenceDataSeeder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The one trigger for reference data seeding: an admin logs in.
 *
 * ## Why here and not where it used to be
 *
 * It used to be in seven config screens, each seeding its own list as a side effect of RENDERING —
 * `UnitOfMeasureController::index()`, `TrackingPolicyController::index()`,
 * `AdjustmentController::form()`, five fee config screens and the Canadian tax screen. Every one of
 * those was a GET action that wrote, and between installing the system and somebody opening that
 * particular screen the list did not exist, so anything reading it behaved as though the concept
 * did not exist either — silently. A login is the first moment an administrator is definitely
 * present, it is not a read path, and it happens exactly once per person per session rather than on
 * every render of one page.
 *
 * A listener rather than a controller because no route should own this: the data belongs to the
 * installation, not to a screen, and putting it behind a screen is what created the defect.
 *
 * ## Admin side only
 *
 * `LoginSuccessEvent` fires for the customer firewall too. A customer logging into the storefront
 * must not write reference rows — this is the admin's system to set up, and seeding from a public
 * login would put the write back on a path anybody can reach. The `AdminUser` test below is the
 * whole of that gate; the firewall name is not consulted because the user class is the fact that
 * matters and it cannot be spoofed by a route.
 *
 * ## What it costs on every login after the first
 *
 * One SELECT of `reference_data_seed_mark` (one short row per registered seeder, ten-ish today),
 * compared against the registered keys in PHP. No transaction is opened, no seeder body runs and
 * the EntityManager is not touched. See {@see ReferenceDataSeeder::run()}.
 *
 * ## What it costs when something is broken
 *
 * Nothing, to the person logging in. Every failure mode — a seeder throwing, a lost race, the
 * database refusing the write — is contained by {@see ReferenceDataSeeder}, and this subscriber
 * wraps the whole call once more as a backstop. **Login must succeed even if seeding cannot**: a
 * reference list is worth strictly less than an administrator's ability to get into the system and
 * fix whatever is wrong with it, and an exception thrown from a `LoginSuccessEvent` listener is a
 * failed authentication, not a warning.
 */
final class AdminLoginReferenceDataSeedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ReferenceDataSeeder $seeder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$event->getUser() instanceof AdminUser) {
            return;
        }

        try {
            $this->seeder->run();
        } catch (\Throwable $e) {
            // ReferenceDataSeeder already contains every per-seeder failure, so reaching this means
            // something structural went wrong (the marks table is missing on an un-migrated
            // database, say). Logged and swallowed: the admin gets their session, and the next
            // login tries again.
            $this->logger->error('Reference data seeding failed during admin login: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
