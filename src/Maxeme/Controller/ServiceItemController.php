<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Entity\ProductCore;
use App\Maxeme\Accounting\Money;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Dto\ServiceItemData;
use App\Maxeme\Dto\ServiceLineData;
use App\Maxeme\Entity\AbstractCharge;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\ServiceLine;
use App\Maxeme\Enum\ServiceLineType;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Listing\ItemLabel;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Repository\GovtFeeRepository;
use App\Maxeme\Repository\LabourRepository;
use App\Maxeme\Repository\ServiceCategoryRepository;
use App\Maxeme\Repository\ServiceItemRepository;
use App\Maxeme\Repository\TaxClassRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use App\Maxeme\Service\ServiceLineBuilder;
use App\Service\Product\ProductPicker;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Parts & Services › Services (legacy CNSServiceBundle manageController). A service is edited on its
 * own page with its lines (labour, parts, sublet, government fees, discount). Deleting asks first.
 */
#[Route('/admin/services', name: 'maxeme_service_')]
final class ServiceItemController extends AbstractMaxemeController
{
    public function __construct(
        private readonly RecordWriter $records,
        private readonly ServiceCategoryRepository $categories,
        private readonly TaxClassRepository $taxClasses,
        private readonly LabourRepository $labour,
        private readonly GovtFeeRepository $govtFees,
        private readonly ServiceLineBuilder $lineBuilder,
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

    /** Services › Export CSV: every service of the current view (search boxes and sort), not just the page shown, with its lines in one cell. */
    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function export(Request $request, ServiceItemRepository $repository, ActivityRecorder $activity, #[Autowire(param: 'maxeme.timezone')] string $timezone): Response
    {
        $list = ListQuery::fromRequest($request, array_keys(ServiceItemRepository::SORTS));
        $services = $repository->findAllInView($list);
        $filename = sprintf('services-%s.csv', (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d'));
        $activity->exported('service', 'ServiceItem', $filename, count($services), $list->describe());

        return CsvExport::response($filename, ['Name', 'Label', 'Default Price', 'Tax Class', 'Colour', 'Service Lines'], (static function () use ($services): \Generator {
            foreach ($services as $service) {
                $lines = implode('; ', array_map(static fn (ServiceLine $line): string => $line->describe(), $service->getLines()));
                yield [$service->getName(), $service->getPreferredName(), $service->getPrice(), $service->getTaxClass()?->getLabel(), $service->getColour(), $lines];
            }
        })());
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function new(Request $request): Response
    {
        return $this->form(new ServiceItem(), $request, 'Add New Service', '%s added.');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function edit(#[MapEntity] ServiceItem $service, Request $request): Response
    {
        return $this->form($service, $request, sprintf('Edit Service: %s', $service->getName()), '%s saved.');
    }

    /** The service page's line item search: `type` (a ServiceLineType) and the text typed, `q`. */
    #[Route('/line-items', name: 'line_items', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function lineItems(Request $request, ProductPicker $products): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        $charge = static fn (AbstractCharge $charge): array => ['label' => ItemLabel::charge($charge), 'value' => $charge->getId(), 'price' => $charge->getPrice()];

        $items = match (ServiceLineType::tryFrom((string) $request->query->get('type', ''))) {
            ServiceLineType::Labour => array_map($charge, $this->labour->searchActive($term, ['sublet' => false])),
            ServiceLineType::Sublet => array_map($charge, $this->labour->searchActive($term, ['sublet' => true])),
            ServiceLineType::GovtFee => array_map($charge, $this->govtFees->searchActive($term)),
            ServiceLineType::Part => array_map(
                static fn (ProductCore $product): array => ['label' => ItemLabel::product($product), 'value' => $product->getId(), 'price' => Money::rounded($product->getDefaultPrice())],
                $products->searchPage($term)['products'],
            ),
            default => [],
        };

        return $this->json($items);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function delete(#[MapEntity] ServiceItem $service): JsonResponse
    {
        $this->records->delete($service);

        return $this->json(['message' => sprintf('%s deleted.', $service->getName())]);
    }

    /** The service page: its own fields and its lines, saved together. */
    private function form(ServiceItem $service, Request $request, string $title, string $saved): Response
    {
        $data = ServiceItemData::fromEntity($service);
        $lines = array_map(ServiceLineData::fromEntity(...), $service->getLines());
        $errors = [];

        if ($request->isMethod('POST')) {
            $data = ServiceItemData::fromRequest($request);
            $lines = ServiceLineData::listFromRequest($request->request->all()['lines'] ?? []);
            $category = $data->categoryId !== null ? $this->categories->find((int) $data->categoryId) : null;
            $taxClass = $data->taxClassId !== null ? $this->taxClasses->find((int) $data->taxClassId) : null;
            $errors = $this->records->validate($data);
            if ($data->categoryId !== null && $category === null) {
                $errors['categoryId'] = 'Choose a category from the list.';
            }
            if ($data->taxClassId !== null && $taxClass === null) {
                $errors['taxClassId'] = 'Choose a tax class from the list.';
            }
            $built = $this->lineBuilder->build($service->getLines(), $lines, static fn (ServiceLineType $type): ServiceLine => new ServiceLine($service, $type));
            $errors += $built['errors'];

            if ($errors === []) {
                $service->setCategory($category)->setTaxClass($taxClass);
                $service->replaceLines($built['lines']);
                $this->records->save($service, $data);
                $this->addFlash('success', sprintf($saved, $service->getName()));

                return $this->redirectBack($request, 'maxeme_service_index');
            }
        }

        return $this->render('maxeme/service/form.html.twig', [
            'title' => $title,
            'service' => $service,
            'data' => $data,
            'lines' => $lines,
            'errors' => $errors,
            'categories' => $this->categories->findTree(),
            'taxClasses' => $this->taxClasses->findAllOrdered(),
            'lineTypes' => ServiceLineType::cases(),
        ], new Response('', $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
