<?php

namespace App\EventSubscriber;

use App\Service\AppSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Puts the configured Reply-To on every outbound email (#474).
 *
 * Since the From: header is now pinned to the sending domain, it is very often a no-reply@ mailbox
 * that nobody reads. Replying to a password reset or an order confirmation and hearing nothing back
 * is a worse experience than the misaligned sender we removed, so sender_replyto_address exists to
 * point those replies somewhere real.
 *
 * It is applied here rather than at each of the sixteen places that build an Email because "every
 * outbound email" is exactly what the setting promises, and a per-call-site version would be one
 * forgotten line away from being a lie. The From: header is still set at the call sites: which
 * category an email belongs to is a decision the caller makes, whereas where replies go is a single
 * store-wide answer.
 *
 * Two things it deliberately does not do:
 *
 *  - It never replaces a Reply-To the message already carries. The contact form sets Reply-To to
 *    whoever filled the form in, so staff can just hit reply and answer the customer; overwriting
 *    that with the store's own address would send the reply back to the store.
 *  - It adds nothing when the setting is blank, which is how it ships. No fallback to support_email,
 *    none to MAILER_FROM. An operator who has not configured a Reply-To gets no Reply-To header,
 *    because guessing one on their behalf is how mail ends up quietly going somewhere nobody
 *    watches.
 */
final class SenderReplyToSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AppSettings $appSettings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => ['onMessage', -100],
        ];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();

        // RawMessage and friends have no addressable headers to speak of, and a message that has
        // already been told who to reply to keeps that answer.
        if (!$message instanceof Email || $message->getReplyTo() !== []) {
            return;
        }

        $replyTo = $this->appSettings->replyToAddress();
        if ($replyTo === null) {
            return;
        }

        $message->replyTo($replyTo);
    }
}
