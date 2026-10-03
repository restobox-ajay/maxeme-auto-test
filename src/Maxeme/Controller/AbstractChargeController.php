<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Dto\AbstractChargeData;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Entity\AbstractCharge;
use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Entity\ServiceLine;
use App\Maxeme\Entity\TaxClass;
use App\Maxeme\Repository\TaxClassRepository;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Repository\AbstractChargeRepository;
use App\Maxeme\Repository\ServiceLineRepository;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The list / add / edit / delete of a charge (Labour, Government Fees): one list with add and
 * edit modals, a code that must be unique, and a delete that asks first (and is refused while a
 * service line uses the charge). A subclass names the
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
            'taxClasses' => $this->taxClasses()->findAllOrdered(),
            ...$extra,
        ]);
    }

    /** Export CSV: every charge of the list's current view (search boxes, sort), not just the page. */
    protected function exportList(Request $request, ActivityRecorder $activity, string $timezone, string $name, string $entityType): Response
    {
        $repository = $this->repository();
        $list = ListQuery::fromRequest($request, array_keys($repository::SORTS));
        $charges = $repository->findAllInView($list);
        $filename = sprintf('%s-%s.csv', $name, (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d'));
        $activity->exported('service', $entityType, $filename, count($charges), $list->describe());

        return CsvExport::response($filename, ['Code', 'Name', 'Price', 'Tax Class', 'Active'], (static function () use ($charges): \Generator {
            foreach ($charges as $charge) {
                yield [$charge->getCode(), $charge->getName(), $charge->getPrice(), $charge->getTaxClass()?->getLabel(), $charge->isActive() ? 'Yes' : 'No'];
            }
        })());
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
        /** @var ServiceLineRepository $lines */
        $lines = $this->entityManager->getRepository(ServiceLine::class);
        $services = $charge instanceof Labour || $charge instanceof GovtFee ? $lines->serviceNamesUsing($charge) : [];
        if ($services !== []) {
            return $this->json(['message' => sprintf('%s is on the lines of %s: %s. Remove it from them first, or switch it off.', $charge->getName(), count($services) === 1 ? 'a service' : count($services) . ' services', implode(', ', $services))], Response::HTTP_CONFLICT);
        }

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

        $taxClass = isset($errors['taxClassId']) ? null : $this->taxClasses()->find((int) $data->taxClassId);
        if ($taxClass === null) {
            $errors['taxClassId'] ??= 'Choose a tax class from the list.';
        }

        if ($errors !== []) {
            $this->flashErrors($errors);
        } else {
            $charge->setTaxClass($taxClass);
            $this->records->save($charge, $data);
            $this->addFlash('success', sprintf($message, $charge->getName()));
        }

        return $this->redirectBack($request, $this->routePrefix() . 'index');
    }

    private function taxClasses(): TaxClassRepository
    {
        /** @var TaxClassRepository */
        return $this->entityManager->getRepository(TaxClass::class);
    }
}
