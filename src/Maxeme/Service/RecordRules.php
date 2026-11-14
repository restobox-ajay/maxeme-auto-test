<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Dto\ClientData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ServiceCategory;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\ServiceReminderTemplate;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Validation\ContactFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The "no two of the same" rules of the people and set-up screens (audit fixes 8b30420 / 34181b0):
 * each returns why a save is refused, or null when it may go ahead.
 */
final class RecordRules
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * A client the form would duplicate: the same email, the same phone number (its last ten digits),
     * or, when adding, the same first and last name with no email or phone to tell them apart. What the
     * client already holds is never a new clash.
     */
    public function clientDuplicate(ClientData $data, ?Client $self = null): ?string
    {
        $email = mb_strtolower(trim((string) $data->email));
        if ($self !== null && $email === mb_strtolower(trim((string) $self->getEmail()))) {
            $email = '';
        }
        $had = $self !== null ? array_map(ContactFormat::phoneKey(...), $self->getPhones()) : [];
        $phones = array_values(array_filter(array_unique(array_map(ContactFormat::phoneKey(...), [$data->phone1, $data->phone2, $data->phone3, $data->phone4])), static fn (string $key): bool => $key !== '' && !in_array($key, $had, true)));
        $name = $data->firstName !== null && $data->lastName !== null ? mb_strtolower(trim($data->firstName) . ' ' . trim($data->lastName)) : '';
        if ($email === '' && $phones === [] && ($self !== null || $name === '')) {
            return null;
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.id', 'c.firstName', 'c.lastName', 'c.preferredName', 'c.email', 'c.phone1', 'c.phone2', 'c.phone3', 'c.phone4', 'c.active')
            ->from(Client::class, 'c')
            ->getQuery()->getArrayResult();
        $found = static function (string $by, callable $match) use ($rows, $self): ?string {
            foreach ($rows as $row) {
                if (($self === null || $row['id'] !== $self->getId()) && $match($row)) {
                    $label = trim($row['firstName'] . ' ' . $row['lastName']) ?: ($row['preferredName'] ?: 'Client #' . $row['id']);

                    return sprintf('%s already exists with the same %s: open that client instead%s.', $label, $by, $row['active'] ? '' : ' (it is inactive: open its profile to reactivate it)');
                }
            }

            return null;
        };

        return ($email !== '' ? $found('email', static fn (array $row): bool => mb_strtolower(trim((string) $row['email'])) === $email) : null)
            ?? ($phones !== [] ? $found('phone number', static fn (array $row): bool => array_intersect($phones, array_map(ContactFormat::phoneKey(...), [$row['phone1'], $row['phone2'], $row['phone3'], $row['phone4']])) !== []) : null)
            ?? ($self === null && $email === '' && $phones === [] && $name !== '' ? $found('name (and no email or phone to tell them apart)', static fn (array $row): bool => mb_strtolower(trim((string) $row['firstName']) . ' ' . trim((string) $row['lastName'])) === $name) : null);
    }

    /** One VIN, one vehicle (a vehicle without a VIN is fine; the VIN the vehicle already holds is left alone). */
    public function vinTaken(?string $vin, ?Vehicle $self = null): ?string
    {
        $vin = strtoupper(trim((string) $vin));
        if ($vin === '' || ($self !== null && strtoupper(trim((string) $self->getVin())) === $vin)) {
            return null;
        }
        $other = $this->entityManager->getRepository(Vehicle::class)->createQueryBuilder('v')
            ->andWhere('UPPER(TRIM(v.vin)) = :vin')->setParameter('vin', $vin)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($other === null || $other === $self) {
            return null;
        }

        return sprintf('The VIN %s is already on %s (%s): open that vehicle instead of adding another.', $vin, $other->getFullName() ?: 'vehicle #' . $other->getId(), $other->getClient()->getFullName());
    }

    public function serviceNameTaken(?string $name, ?ServiceItem $self): ?string
    {
        return $this->nameTaken(ServiceItem::class, 's', $name, $self, 'service');
    }

    /** Within the same parent category. */
    public function categoryNameTaken(?string $name, ?ServiceCategory $parent, ?ServiceCategory $self): ?string
    {
        return $this->nameTaken(ServiceCategory::class, 'c', $name, $self, $parent !== null ? 'category under ' . $parent->getName() : 'top-level category', static fn ($query) => $parent !== null
            ? $query->andWhere('c.parent = :parent')->setParameter('parent', $parent)
            : $query->andWhere('c.parent IS NULL'));
    }

    public function templateNameTaken(?string $name, ?ServiceReminderTemplate $self): ?string
    {
        return $this->nameTaken(ServiceReminderTemplate::class, 't', $name, $self, 'reminder template');
    }

    /**
     * @param class-string $class
     * @param (callable(\Doctrine\ORM\QueryBuilder): \Doctrine\ORM\QueryBuilder)|null $scope
     */
    private function nameTaken(string $class, string $alias, ?string $name, ?object $self, string $what, ?callable $scope = null): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $query = $this->entityManager->getRepository($class)->createQueryBuilder($alias)
            ->select('COUNT(' . $alias . '.id)')
            ->andWhere(sprintf('LOWER(TRIM(%s.name)) = :name', $alias))->setParameter('name', mb_strtolower($name));
        if ($self !== null && $self->getId() !== null) {
            $query->andWhere(sprintf('%s.id <> :self', $alias))->setParameter('self', $self->getId());
        }
        if ($scope !== null) {
            $query = $scope($query);
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0 ? sprintf('There is already a %s named "%s".', $what, $name) : null;
    }
}
