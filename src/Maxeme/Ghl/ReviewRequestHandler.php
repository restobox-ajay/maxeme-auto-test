<?php

declare(strict_types=1);

namespace App\Maxeme\Ghl;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\RepairOrder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Webhook to GHL (spec "Completed Order"): the customer of a completed repair order is looked up in
 * GoHighLevel by email and created (email, name, phone) when missing; then the review tag
 * ("send-review-request") is added unless the contact has it. GoHighLevel's own automation sends
 * the review email when the tag appears.
 *
 * Runs in the Messenger worker. A failure is logged (so it reaches the Error Log) and rethrown, so
 * Messenger retries it; every step is idempotent. Without a GHL token and location nothing happens.
 */
#[AsMessageHandler]
final class ReviewRequestHandler
{
    public function __construct(
        private readonly GhlClient $ghl,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityRecorder $activity,
        private readonly LoggerInterface $logger,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    public function __invoke(ReviewRequestMessage $message): void
    {
        $repairOrder = $this->entityManager->find(RepairOrder::class, $message->repairOrderId);
        $client = $repairOrder?->getClient();
        $email = trim((string) $client?->getEmail());
        if (!$this->ghl->isConfigured() || $client === null || $email === '') {
            return;
        }

        try {
            $steps = [];
            $contact = $this->ghl->findContactByEmail($email);
            if ($contact === null) {
                $contact = $this->ghl->createContact($email, $client->getFirstName(), $client->getLastName(), self::phone(array_values($client->getPhones())[0] ?? null));
                $steps[] = 'created the contact';
            }
            $tag = $this->ghl->reviewTag();
            if (!in_array(mb_strtolower($tag), array_map('mb_strtolower', $contact['tags']), true)) {
                $this->ghl->addTag($contact['id'], $tag);
                $steps[] = sprintf('added the "%s" tag', $tag);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('GoHighLevel review request failed for repair order {id}: {message}', ['id' => $message->repairOrderId, 'message' => $exception->getMessage(), 'exception' => $exception]);

            throw $exception;
        }

        $this->activity->ghlReviewRequest($client, $steps === []
            ? sprintf('GoHighLevel: %s already has the "%s" tag.', $email, $tag)
            : sprintf('GoHighLevel: %s for %s (%s).', ucfirst(implode(' and ', $steps)), $email, $this->numbers->repairOrderNumber($repairOrder)));
    }

    /** A 10-digit North American number gets +1 (GoHighLevel stores E.164); anything else goes as typed. */
    private static function phone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return match (true) {
            strlen($digits) === 10 => '+1' . $digits,
            strlen($digits) === 11 && $digits[0] === '1' => '+' . $digits,
            default => trim($phone),
        };
    }
}
