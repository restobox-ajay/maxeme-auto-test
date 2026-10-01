<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\FormData;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Config › Settings list (Tax Classes, Payment Types, Technicians): every row on one page, with
 * add and edit modals, a unique name or code, and a delete that asks first and is refused while
 * the record is in use. A subclass names the record, its form and its screen, and routes its
 * actions here. Every record has a getName().
 *
 * @template T of object
 */
abstract class AbstractSettingsListController extends AbstractMaxemeController
{
    public function __construct(
        protected readonly RecordWriter $records,
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<T> every row, in list order */
    abstract protected function all(): array;

    /** @return T */
    abstract protected function newRecord(): object;

    abstract protected function formData(Request $request): FormData;

    /** The route-name prefix, e.g. "maxeme_settings_technician_". */
    abstract protected function routePrefix(): string;

    abstract protected function template(): string;

    /**
     * Errors the form's constraints cannot see (a code or name already used, an id that does not
     * exist), as property => message; may also prepare $record for saving.
     *
     * @param T $record
     *
     * @return array<string, string>
     */
    abstract protected function check(object $record, FormData $data): array;

    /**
     * Why $record cannot be deleted, or null when it can.
     *
     * @param T $record
     */
    abstract protected function inUse(object $record): ?string;

    /** @param array<string, mixed> $extra more template variables */
    protected function renderList(array $extra = []): Response
    {
        return $this->render($this->template(), ['records' => $this->all(), ...$extra]);
    }

    protected function create(Request $request): RedirectResponse
    {
        return $this->save($this->newRecord(), $request, '%s added.');
    }

    /** @param T $record */
    protected function update(object $record, Request $request): RedirectResponse
    {
        return $this->save($record, $request, '%s saved.');
    }

    /** @param T $record */
    protected function remove(object $record): JsonResponse
    {
        $reason = $this->inUse($record);
        if ($reason !== null) {
            return $this->json(['message' => $reason], Response::HTTP_CONFLICT);
        }

        $this->entityManager->remove($record);
        $this->entityManager->flush();

        return $this->json(['message' => sprintf('%s deleted.', $record->getName())]);
    }

    /** @param T $record */
    private function save(object $record, Request $request, string $message): RedirectResponse
    {
        $data = $this->formData($request);
        $errors = $this->records->validate($data);
        if ($errors === []) {
            $errors = $this->check($record, $data);
        }

        if ($errors !== []) {
            $this->flashErrors($errors);
        } else {
            $this->records->save($record, $data);
            $this->addFlash('success', sprintf($message, $record->getName()));
        }

        return $this->redirectBack($request, $this->routePrefix() . 'index');
    }
}
