<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Dto\ServiceItemData;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Repository\ServiceCategoryRepository;
use App\Maxeme\Repository\ServiceItemRepository;
use App\Maxeme\Repository\TaxClassRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Parts & Services › Services (legacy CNSServiceBundle manageController). Deleting asks for confirmation. */
#[Route('/admin/services', name: 'maxeme_service_')]
final class ServiceItemController extends AbstractMaxemeController
{
    public function __construct(
        private readonly RecordWriter $records,
        private readonly ServiceCategoryRepository $categories,
        private readonly TaxClassRepository $taxClasses,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function index(Request $request, ServiceItemRepository $repository): Response
    {
        return $this->render('maxeme/service/index.html.twig', [
            'page' => $repository->findPage(ListQuery::fromRequest($request, array_keys(ServiceItemRepository::SORTS))),
            'categories' => $this->categories->findTree(),
            'taxClasses' => $this->taxClasses->findAllOrdered(),
        ]);
    }

    /** Services › Export CSV: every service of the current view (search boxes and sort), not just the page shown. */
    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function export(Request $request, ServiceItemRepository $repository, ActivityRecorder $activity, #[Autowire(param: 'maxeme.timezone')] string $timezone): Response
    {
        $list = ListQuery::fromRequest($request, array_keys(ServiceItemRepository::SORTS));
        $services = $repository->findAllInView($list);
        $filename = sprintf('services-%s.csv', (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d'));
        $activity->exported('service', 'ServiceItem', $filename, count($services), $list->describe());

        return CsvExport::response($filename, ['Name', 'Label', 'Default Price', 'Tax Class', 'Colour'], (static function () use ($services): \Generator {
            foreach ($services as $service) {
                yield [$service->getName(), $service->getPreferredName(), $service->getPrice(), $service->getTaxClass()?->getLabel(), $service->getColour()];
            }
        })());
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function create(Request $request): RedirectResponse
    {
        return $this->save(new ServiceItem(), $request, '%s added.');
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function update(#[MapEntity] ServiceItem $service, Request $request): RedirectResponse
    {
        return $this->save($service, $request, '%s saved.');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function delete(#[MapEntity] ServiceItem $service): JsonResponse
    {
        $this->records->delete($service);

        return $this->json(['message' => sprintf('%s deleted.', $service->getName())]);
    }

    private function save(ServiceItem $service, Request $request, string $message): RedirectResponse
    {
        $data = ServiceItemData::fromRequest($request);
        $category = $data->categoryId !== null ? $this->categories->find((int) $data->categoryId) : null;
        $taxClass = $data->taxClassId !== null ? $this->taxClasses->find((int) $data->taxClassId) : null;
        $errors = $this->records->validate($data);
        if ($data->categoryId !== null && $category === null) {
            $errors['categoryId'] = 'Choose a category from the list.';
        }
        if ($data->taxClassId !== null && $taxClass === null) {
            $errors['taxClassId'] = 'Choose a tax class from the list.';
        }
        if ($errors !== []) {
            $this->flashErrors($errors);
        } else {
            $service->setCategory($category)->setTaxClass($taxClass);
            $this->records->save($service, $data);
            $this->addFlash('success', sprintf($message, $service->getName()));
        }

        return $this->redirectBack($request, 'maxeme_service_index');
    }
}
