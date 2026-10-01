<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\AbstractChargeData;
use App\Maxeme\Entity\AbstractCharge;
use App\Maxeme\Enum\TaxClass;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Repository\AbstractChargeRepository;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The list / add / edit / delete of a charge (Labour, Government Fees): one list with add and
 * edit modals, a code that must be unique, and a delete that asks first. A subclass names the
 * entity, its form and its screen, and routes its actions here.
 *
 * @template T of AbstractCharge
 */
abstract class AbstractChargeController extends AbstractMaxemeController
{
    public function __construct(
        protected readonly RecordWriter $records,
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return AbstractChargeRepository<T> */
    abstract protected function repository(): AbstractChargeRepository;

    /** @return T */
    abstract protected function newCharge(): AbstractCharge;

    abstract protected function formData(Request $request): AbstractChargeData;

    /** The route-name prefix, e.g. "maxeme_labour_". */
    abstract protected function routePrefix(): string;

    abstract protected function template(): string;

    /** @param array<string, mixed> $extra more template variables */
    protected function renderList(Request $request, array $extra = []): Response
    {
        $repository = $this->repository();

        return $this->render($this->template(), [
            'page' => $repository->findPage(ListQuery::fromRequest($request, array_keys($repository::SORTS))),
            'taxClasses' => TaxClass::cases(),
            ...$extra,
        ]);
    }

    protected function create(Request $request): RedirectResponse
    {
        return $this->save($this->newCharge(), $request, '%s added.');
    }

    /** @param T $charge */
    protected function update(AbstractCharge $charge, Request $request): RedirectResponse
    {
        return $this->save($charge, $request, '%s saved.');
    }

    /** @param T $charge */
    protected function remove(AbstractCharge $charge): JsonResponse
    {
        $this->entityManager->remove($charge);
        $this->entityManager->flush();

        return $this->json(['message' => sprintf('%s deleted.', $charge->getName())]);
    }

    /** @param T $charge */
    private function save(AbstractCharge $charge, Request $request, string $message): RedirectResponse
    {
        $data = $this->formData($request);
        $errors = $this->records->validate($data);
        if (!isset($errors['code']) && $data->code !== null) {
            $taken = $this->repository()->findOneByCode($data->code);
            if ($taken !== null && $taken !== $charge) {
                $errors['code'] = sprintf('The code %s is already used by %s.', strtoupper($data->code), $taken->getName());
            }
        }

        if ($errors !== []) {
            $this->flashErrors($errors);
        } else {
            $this->records->save($charge, $data);
            $this->addFlash('success', sprintf($message, $charge->getName()));
        }

        return $this->redirectBack($request, $this->routePrefix() . 'index');
    }
}
