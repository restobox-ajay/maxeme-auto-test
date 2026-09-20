<?php

namespace App\EventSubscriber;

use App\Entity\Company;
use App\Entity\CustomerUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class CustomerLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
        $user = $event->getUser();
        if (!$user instanceof CustomerUser) {
            return;
        }

        if (!$user->getCompany() instanceof Company) {
            $email = strtolower(trim($user->getEmail()));
            if ($email !== '') {
                $company = $this->entityManager->getRepository(Company::class)
                    ->createQueryBuilder('company')
                    ->andWhere('LOWER(company.primaryEmail) = :email')
                    ->andWhere('company.status = :status')
                    ->setParameter('email', $email)
                    ->setParameter('status', 'Active')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($company instanceof Company) {
                    $user->setCompany($company);
                }
            }
        }

        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
