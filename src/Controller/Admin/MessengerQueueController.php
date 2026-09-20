<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tech-Support-only view onto the raw `messenger_messages` table (see #337's Doctrine async
 * transport). Read-mostly: the two write actions (retry/remove) shell out to Symfony's own
 * `messenger:failed:*` commands rather than hand-rolling envelope/redelivery-stamp manipulation
 * here, the same "delegate to the tested command" choice ProductImportController's
 * spawnImportProcess() makes for its own subprocess.
 *
 * A queued message's body is an admin's own application data (an Email, in practice), not
 * attacker-controlled markup — but it is still opaque serialized PHP, so the body-viewing route
 * renders it as a bare, fully-escaped document (never through the main Twig layout, which has
 * scripts) and the list page only ever embeds that route inside a `sandbox=""` iframe: no
 * scripts, no same-origin, no forms, no popups. Defense in depth over a field this controller
 * does not control the shape of.
 */
#[Route('/admin/messenger')]
final class MessengerQueueController extends AbstractAdminController
{
    #[Route('', name: 'admin_messenger_queue', methods: ['GET'])]
    public function list(Connection $connection, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        if (!$connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
            $this->addFlash('error', 'The messenger_messages table is missing. Run migrations to create it (php bin/console doctrine:migrations:migrate).');

            return $this->render('admin/messenger/queue.html.twig', ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => 100]);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', 100));
        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }

        $sortMap = [
            'id' => 'id',
            'queue' => 'queue_name',
            'createdAt' => 'created_at',
            'availableAt' => 'available_at',
            'deliveredAt' => 'delivered_at',
        ];
        $sortColumn = $sortMap[$sort] ?? 'id';

        $where = [];
        $params = [];

        $filterQueue = trim((string) ($filters['queue'] ?? ''));
        if ($filterQueue !== '') {
            $where[] = 'queue_name = :queue';
            $params['queue'] = $filterQueue;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages{$whereSql}", $params);

        $records = $connection->fetchAllAssociative(
            "SELECT id, queue_name, created_at, available_at, delivered_at, headers, body
             FROM messenger_messages{$whereSql}
             ORDER BY {$sortColumn} {$dir}
             LIMIT :limit OFFSET :offset",
            [...$params, 'limit' => $limit, 'offset' => ($page - 1) * $limit],
            [...array_fill_keys(array_keys($params), ParameterType::STRING), 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $rows = array_map(function (array $r): array {
            $headers = json_decode((string) $r['headers'], true);
            $type = is_array($headers) ? (string) ($headers['type'] ?? '') : '';

            return [
                'id' => (int) $r['id'],
                'queue' => (string) $r['queue_name'],
                // The DSN's queue_name option is only set explicitly for the failure transport
                // (doctrine://default?queue_name=failed); the async transport's DSN sets none,
                // so Doctrine's own default applies and pending rows are stored as "default" —
                // that's a Doctrine transport implementation detail, not a status, so it's
                // relabeled here rather than shown verbatim.
                'queueLabel' => $r['queue_name'] === 'failed' ? 'failed' : 'pending',
                'createdAt' => $r['created_at'],
                'availableAt' => $r['available_at'],
                'deliveredAt' => $r['delivered_at'],
                'messageType' => $type !== '' ? (substr((string) strrchr($type, '\\') ?: $type, 1) ?: $type) : '(unknown)',
                'bodyPreview' => mb_strimwidth((string) $r['body'], 0, 200, '…'),
            ];
        }, $records);

        return $this->render('admin/messenger/queue.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'currentSort' => $sort,
            'currentDir' => strtolower($dir),
            'filters' => $filters,
        ]);
    }

    /**
     * Standalone document, deliberately NOT extending the admin layout (no shared scripts/styles
     * to carry into an iframe meant to be inert). Every value is escaped by Twig's default
     * autoescape — nothing here is marked |raw.
     */
    #[Route('/{id}/body', name: 'admin_messenger_body', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function body(int $id, Connection $connection): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $record = $connection->fetchAssociative('SELECT * FROM messenger_messages WHERE id = :id', ['id' => $id]);
        if ($record === false) {
            return new Response('Message not found.', 404);
        }

        $headersJson = json_decode((string) $record['headers'], true);
        $headersPretty = is_array($headersJson) ? json_encode($headersJson, JSON_PRETTY_PRINT) : (string) $record['headers'];

        $summary = null;
        try {
            $envelope = (new PhpSerializer())->decode(['body' => (string) $record['body'], 'headers' => is_array($headersJson) ? $headersJson : []]);
            if ($envelope instanceof Envelope) {
                $summary = $this->summarizeEnvelope($envelope);
            }
        } catch (\Throwable) {
            // Fall through to the raw body dump below — a malformed/legacy row must not 500 the viewer.
        }

        return $this->render('admin/messenger/_body.html.twig', [
            'id' => $id,
            'queue' => (string) $record['queue_name'],
            'summary' => $summary,
            'headersPretty' => (string) $headersPretty,
            'rawBody' => (string) $record['body'],
        ]);
    }

    /** @return array<string, string>|null */
    private function summarizeEnvelope(Envelope $envelope): ?array
    {
        $message = $envelope->getMessage();

        if (!method_exists($message, 'getMessage')) {
            return ['class' => $message::class];
        }

        // Symfony\Component\Mailer\Messenger\SendEmailMessage — the only message type this app
        // routes to the async transport (config/packages/messenger.yaml).
        $inner = $message->getMessage();
        if (!$inner instanceof Email) {
            return ['class' => $message::class];
        }

        return [
            'class' => $message::class,
            'from' => implode(', ', array_map(static fn ($a) => $a->toString(), $inner->getFrom())),
            'to' => implode(', ', array_map(static fn ($a) => $a->toString(), $inner->getTo())),
            'subject' => (string) $inner->getSubject(),
            'htmlBody' => (string) ($inner->getHtmlBody() ?? ''),
            'textBody' => (string) ($inner->getTextBody() ?? ''),
        ];
    }

    #[Route('/{id}/retry', name: 'admin_messenger_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(int $id, Connection $connection): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $queue = $connection->fetchOne('SELECT queue_name FROM messenger_messages WHERE id = :id', ['id' => $id]);
        if ($queue === false) {
            return $this->json(['message' => 'Message not found.'], 404);
        }
        if ($queue !== 'failed') {
            return $this->json(['message' => 'Only failed messages can be retried.'], 400);
        }

        [$ok, $output] = $this->runConsole(['messenger:failed:retry', (string) $id, '--force', '--no-interaction', '--transport=failed']);
        if (!$ok) {
            return $this->json(['message' => 'Retry failed: ' . $output], 500);
        }

        return $this->json(['message' => 'Message requeued.']);
    }

    #[Route('/{id}/remove', name: 'admin_messenger_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(int $id, Connection $connection): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $queue = $connection->fetchOne('SELECT queue_name FROM messenger_messages WHERE id = :id', ['id' => $id]);
        if ($queue === false) {
            return $this->json(['message' => 'Message not found.'], 404);
        }

        if ($queue === 'failed') {
            [$ok, $output] = $this->runConsole(['messenger:failed:remove', (string) $id, '--force', '--no-interaction', '--transport=failed']);
            if (!$ok) {
                return $this->json(['message' => 'Remove failed: ' . $output], 500);
            }

            return $this->json(['message' => 'Message removed.']);
        }

        // Not in the failure transport — messenger:failed:remove only reads the failure
        // transport, so a stuck-pending row is deleted directly, scoped to its own id.
        $connection->executeStatement('DELETE FROM messenger_messages WHERE id = :id', ['id' => $id]);

        return $this->json(['message' => 'Message removed.']);
    }

    /** @param list<string> $args @return array{0: bool, 1: string} */
    private function runConsole(array $args): array
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process(
            [$phpBinary, $projectDir . '/bin/console', ...$args],
            $projectDir,
            [
                'APP_ENV' => (string) $this->getParameter('kernel.environment'),
                'APP_DEBUG' => $this->getParameter('kernel.debug') ? '1' : '0',
            ],
        );

        try {
            $process->run();
        } catch (ProcessExceptionInterface $e) {
            return [false, $e->getMessage()];
        }

        return [$process->isSuccessful(), trim($process->getErrorOutput() ?: $process->getOutput())];
    }
}
