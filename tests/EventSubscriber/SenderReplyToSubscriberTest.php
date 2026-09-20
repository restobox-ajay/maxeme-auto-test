<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AppSetting;
use App\EventSubscriber\SenderReplyToSubscriber;
use App\Service\AppSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * sender_replyto_address is applied to every outbound message here rather than at each sending
 * site, so this is where "every" and "none" are pinned down (#474).
 */
final class SenderReplyToSubscriberTest extends TestCase
{
    /** @param array<string, string|null> $settings */
    private function subscriber(array $settings): SenderReplyToSubscriber
    {
        $rows = [];
        foreach ($settings as $key => $value) {
            $rows[] = (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
        }

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));

        return new SenderReplyToSubscriber(new AppSettings($em, new ArrayAdapter()));
    }

    private function dispatch(SenderReplyToSubscriber $subscriber, RawMessage $message): void
    {
        $subscriber->onMessage(new MessageEvent(
            $message,
            new Envelope(new Address('no-reply@mail.acme.example'), [new Address('customer@acme.test')]),
            'main',
        ));
    }

    private function message(): Email
    {
        return (new Email())
            ->from(new Address('no-reply@mail.acme.example', 'Acme Wholesale'))
            ->to('customer@acme.test')
            ->subject('Your password reset link')
            ->text('Here you go.');
    }

    public function testTheConfiguredAddressIsAddedToEveryMessage(): void
    {
        $subscriber = $this->subscriber([
            'app_name' => 'Acme Wholesale',
            AppSettings::SENDER_REPLYTO_ADDRESS_KEY => 'replies@acme.example',
        ]);

        $email = $this->message();
        $this->dispatch($subscriber, $email);

        self::assertSame('replies@acme.example', $email->getReplyTo()[0]->getAddress());
        self::assertSame('Acme Wholesale', $email->getReplyTo()[0]->getName());
        // And it is its own answer, not an echo of the sender.
        self::assertNotSame($email->getFrom()[0]->getAddress(), $email->getReplyTo()[0]->getAddress());
    }

    /**
     * Absence is asserted as absence: no header, not a header holding some inferred address. Every
     * candidate a well-meaning fallback might have reached for is configured here, so a fallback
     * that gets reintroduced later fails this rather than passing it with a different value.
     *
     * @param array<string, string|null> $settings
     */
    #[DataProvider('noReplyToProvider')]
    public function testABlankSettingLeavesNoReplyToHeaderAtAll(array $settings): void
    {
        $email = $this->message();
        $this->dispatch($this->subscriber($settings), $email);

        self::assertSame([], $email->getReplyTo());
        self::assertFalse($email->getHeaders()->has('Reply-To'));
    }

    /** @return iterable<string, array{array<string, string|null>}> */
    public static function noReplyToProvider(): iterable
    {
        $contactAddresses = [
            'support_email' => 'help@acme.test',
            'sales_email' => 'sales@acme.test',
            'app_email' => 'hello@acme.test',
            AppSettings::SENDER_FROM_ADDRESS_KEY => 'no-reply@mail.acme.example',
        ];

        yield 'no row at all' => [$contactAddresses];
        yield 'seeded empty' => [[...$contactAddresses, AppSettings::SENDER_REPLYTO_ADDRESS_KEY => '']];
        yield 'whitespace only' => [[...$contactAddresses, AppSettings::SENDER_REPLYTO_ADDRESS_KEY => '   ']];
    }

    /**
     * The contact form sets Reply-To to the customer who filled it in, so staff can answer by
     * hitting reply. Overwriting that with the store's own address would send the reply to the
     * store — which is where it came from — so a message that already has one keeps it.
     */
    public function testAMessageThatAlreadyHasAReplyToKeepsIt(): void
    {
        $subscriber = $this->subscriber([
            AppSettings::SENDER_REPLYTO_ADDRESS_KEY => 'replies@acme.example',
        ]);

        $email = $this->message()->replyTo('jane@customer.test');
        $this->dispatch($subscriber, $email);

        self::assertSame(['jane@customer.test'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $email->getReplyTo(),
        ));
    }

    /** A message that is not an Email has no addressable headers to add one to. */
    public function testARawMessageIsLeftAlone(): void
    {
        $subscriber = $this->subscriber([
            AppSettings::SENDER_REPLYTO_ADDRESS_KEY => 'replies@acme.example',
        ]);

        $raw = new RawMessage('Subject: hello');
        $this->dispatch($subscriber, $raw);

        self::assertSame('Subject: hello', $raw->toString());
    }

    /** Late, so anything a call site decided has already been decided by the time this runs. */
    public function testItSubscribesToMessageEvent(): void
    {
        self::assertSame(
            [MessageEvent::class => ['onMessage', -100]],
            SenderReplyToSubscriber::getSubscribedEvents(),
        );
    }
}
