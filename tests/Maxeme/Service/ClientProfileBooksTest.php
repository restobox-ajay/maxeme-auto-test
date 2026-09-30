<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Maxeme\Dto\ClientAddressData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ClientAddress;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\ClientAddressBook;
use App\Maxeme\Service\NoteBook;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Client Profile: the pickup-address book (in the client's order) and notes. */
final class ClientProfileBooksTest extends DoctrineIntegrationTestCase
{
    private Client $client;
    private ClientAddressBook $book;
    private NoteBook $notes;

    protected function setUp(): void
    {
        parent::setUp();

        $staff = (new AdminUser())->setEmail('reception@example.invalid')->setPassword('x')->setRoles([StaffRole::Receptionist->value])->setFirstName('Rita')->setLastName('Reception');
        $this->client = (new Client())->setFirstName('Ada')->setLastName('Lovelace')->setPhone1('604 555 0101')->setPhone3('604 555 0303');
        $this->em->persist($staff);
        $this->em->persist($this->client);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($staff, 'admin', $staff->getRoles()));

        $this->book = self::getContainer()->get(ClientAddressBook::class);
        $this->notes = self::getContainer()->get(NoteBook::class);
    }

    public function testNewAddressesGoLastAndTheOrderCanBeChanged(): void
    {
        [$home, $work, $cabin] = [$this->add('Home'), $this->add('Work'), $this->add('Cabin')];
        self::assertSame(['Home', 'Work', 'Cabin'], $this->labels());

        $this->book->reorder($this->client, [$cabin->getId(), $home->getId(), 999]);
        self::assertSame(['Cabin', 'Home', 'Work'], $this->labels(), 'unknown ids are ignored, left-out addresses keep their order last');
        self::assertSame([0, 1, 2], array_map(static fn (ClientAddress $a): int => $a->getPosition(), $this->client->getAddresses()->getValues()));

        $this->book->remove($home);
        self::assertSame(['Cabin', 'Work'], $this->labels());
        self::assertSame([0, 1], array_map(static fn (ClientAddress $a): int => $a->getPosition(), $this->client->getAddresses()->getValues()));
        self::assertSame('Cabin', $this->client->getPrimaryAddress()?->getLabel());
        self::assertNotNull($work->getId());
    }

    public function testTheOrderSurvivesAReload(): void
    {
        [$home, $work] = [$this->add('Home'), $this->add('Work')];
        $this->book->reorder($this->client, [$work->getId(), $home->getId()]);
        $this->em->clear();

        $client = $this->em->find(Client::class, $this->client->getId());
        self::assertSame(['Work', 'Home'], array_map(static fn (ClientAddress $a): ?string => $a->getLabel(), $client->getAddresses()->getValues()));
    }

    public function testARepairOrderTakesThePickedAddressOrTheFirst(): void
    {
        $this->add('Home', '12 Main St');
        $work = $this->add('Work', '99 Office Rd');

        self::assertSame('12 Main St, Vancouver BC', Invoice::forClient($this->client, null, 5, 7)->getClientAddress());
        self::assertSame('99 Office Rd, Vancouver BC', Invoice::forClient($this->client, null, 5, 7, $work)->getClientAddress());
        self::assertSame('604 555 0101', Invoice::forClient($this->client, null, 5, 7)->getClientHomeNumber(), 'phone 1 on the document');
    }

    public function testNotesKeepAuthorMessageAndTimestampNewestFirst(): void
    {
        $first = $this->notes->add($this->client, 'Prefers mornings');
        $first->setCreatedAt(new \DateTimeImmutable('-1 hour'));
        $second = $this->notes->add($this->client, 'Call before pickup');
        self::assertSame('Rita Reception', $first->getUserName());

        $this->em->clear();
        $client = $this->em->find(Client::class, $this->client->getId());
        self::assertSame(['Call before pickup', 'Prefers mornings'], $this->texts($client), 'newest first');
        $first = $this->notes->find($client, (int) $first->getId());
        $second = $this->notes->find($client, (int) $second->getId());
        $this->client = $client;

        $written = $first->getCreatedAt();
        $this->notes->update($first, 'Prefers afternoons');
        self::assertSame('Prefers afternoons', $first->getText());
        self::assertEquals($written, $first->getCreatedAt(), 'an edit does not re-date the note');

        $secondId = (int) $second->getId();
        $this->notes->remove($this->client, $second);
        self::assertSame(['Prefers afternoons'], $this->texts($this->client));
        self::assertNull($this->notes->find($this->client, $secondId));
    }

    public function testANoteOfAnotherClientIsNotFound(): void
    {
        $other = (new Client())->setFirstName('Other');
        $this->em->persist($other);
        $note = $this->notes->add($other, 'Not yours');

        self::assertNull($this->notes->find($this->client, (int) $note->getId()));
        self::assertSame('Write the note first.', NoteBook::problemWith(''));
        self::assertNull(NoteBook::problemWith('ok'));
    }

    public function testAddressesAndNotesAreInTheActivityLogUnderPeople(): void
    {
        $this->add('Home');
        $this->notes->add($this->client, 'Prefers mornings');

        foreach (['ClientAddress', 'ClientNote'] as $type) {
            $log = $this->em->getRepository(AuditLog::class)->findOneBy(['entityType' => $type]);
            self::assertNotNull($log, $type . ' changes are logged');
            self::assertSame('People', $log->getArea());
            self::assertSame('Receptionist', $log->getActorRole());
        }
    }

    private function add(string $label, string $line = '1 Test St'): ClientAddress
    {
        $data = new ClientAddressData();
        $data->label = $label;
        $data->addressLine1 = $line;
        $data->city = 'Vancouver';
        $data->province = 'BC';

        return $this->book->add($this->client, $data);
    }

    /** @return list<string> */
    private function texts(Client $client): array
    {
        return array_map(static fn ($note): string => $note->getText(), $client->getNotes()->getValues());
    }

    /** @return list<?string> */
    private function labels(): array
    {
        return array_map(static fn (ClientAddress $a): ?string => $a->getLabel(), $this->client->getAddresses()->getValues());
    }
}
