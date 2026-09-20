<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use App\Service\CustomField\CustomFieldObjectTypeCatalogue;
use App\Validation\Constraint\ValidCustomFieldRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin/custom-fields')]
final class CustomFieldController extends AbstractAdminController
{
    public function __construct(private readonly CustomFieldObjectTypeCatalogue $objectTypes)
    {
    }

    private const FIELD_TYPES = [
        CustomFieldDefinition::FIELD_TYPE_TEXT => 'Text',
        CustomFieldDefinition::FIELD_TYPE_TEXTAREA => 'Textarea',
        CustomFieldDefinition::FIELD_TYPE_NUMBER => 'Number',
        CustomFieldDefinition::FIELD_TYPE_CHECKBOX => 'Checkbox',
        CustomFieldDefinition::FIELD_TYPE_SELECT => 'Select (dropdown)',
    ];

    /**
     * The definition grid (#621's list-screen conventions).
     *
     * ## One table, not five
     *
     * This screen used to render one `.panel > table.data-table` per object type, stacked down the
     * page — five tables, each unpaged, none of them with a filter. It read as a list to
     * `AdminListScreenConventionsCest` the day Invoice and Estimate joined Product, Customer and
     * Order, because the two new groups had no rows and so rendered the empty state that identifies
     * a loop over rows the database did not have.
     *
     * It is a list, and the honest answer was to make it one rather than to name an exemption. The
     * grid model in this application is `.content-frame > .table-card` and `app.css:7800` clamps
     * such a frame to `calc(100vh - 3rem)`, handing the height to the card inside it: that is a
     * model for ONE grid per page, and five cards under one clamp would each be given a fifth of
     * the viewport with a 220px scroll-region floor apiece, which is the overflow the tracking
     * policy screen documents. So the five groups became one table with an Object Type column, and
     * the grouping became a filter over it — which is what the grouping always was.
     *
     * A custom field definition is master data of exactly the kind this application already grids:
     * a tracking policy, a unit of measure, a credit memo type. Its rows are worked — Edit and
     * Delete are their own routes, Add is its own screen — and nothing else is on the page, so the
     * grid IS the page. None of the arguments that keep a tabulating page OUT of the grid model
     * applies: no cell here is an input writing coordinates back, no row is a checkbox feeding one
     * submit, nothing is edited in place beneath the form that writes it.
     *
     * ## What the object-type bar keeps
     *
     * The one thing the stacked tables did that a bare grid would not is name every object type
     * that CAN carry a field, including the ones carrying none — "No custom fields defined for
     * orders yet" was a whole empty table saying so. The filter bar says it instead, with a count
     * beside every type, so a type with nothing on it is still on the screen and is still one click
     * from its own empty state.
     */
    #[Route('', name: 'admin_custom_field_index', methods: ['GET'])]
    public function index(Request $request, CustomFieldDefinitionRepository $definitionRepo): Response
    {
        $filters = $this->filters($request);
        $objectTypes = array_keys($this->objectTypes->labels());
        $counts = $definitionRepo->countByObjectType($objectTypes);

        return $this->render('admin/custom_field/index.html.twig', [
            'rows' => $definitionRepo->searchForAdmin($objectTypes, $filters),
            'filters' => $filters,
            'counts' => $counts,
            // The All tab's own count, summed here rather than counted again: it is by definition
            // the five type counts added up, and a second query could disagree with them.
            'total' => array_sum($counts),
            'objectTypeLabels' => $this->objectTypes->labels(),
            'fieldTypeLabels' => self::FIELD_TYPES,
        ]);
    }

    /**
     * The filter row and the object-type bar, read off the query string.
     *
     * `objectType` and `fieldType` are checked against the catalogue's selectable labels and
     * `self::FIELD_TYPES` rather than passed through: they address a select whose options are those
     * two lists, so a value outside them is a hand-written URL, and narrowing the grid to a type
     * that cannot exist would answer it with an empty table that looks like a fact about the data.
     *
     * @return array{objectType: string, label: string, slug: string, fieldType: string, source: string}
     */
    private function filters(Request $request): array
    {
        $raw = $request->query->all('filters');

        $filters = [];
        foreach (['objectType', 'label', 'slug', 'fieldType', 'source'] as $key) {
            $value = $raw[$key] ?? null;
            $filters[$key] = \is_scalar($value) ? trim((string) $value) : '';
        }

        if (!isset($this->objectTypes->labels()[$filters['objectType']])) {
            $filters['objectType'] = '';
        }

        if (!isset(self::FIELD_TYPES[$filters['fieldType']])) {
            $filters['fieldType'] = '';
        }

        /** @var array{objectType: string, label: string, slug: string, fieldType: string, source: string} $filters */
        return $filters;
    }

    #[Route('/create', name: 'admin_custom_field_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, CustomFieldDefinitionRepository $definitionRepo): Response
    {
        if ($request->isMethod('POST')) {
            $errors = $this->validateRequest($request, $definitionRepo, null);
            if ($errors === []) {
                $definition = new CustomFieldDefinition();
                $this->applyRequest($definition, $request);
                $entityManager->persist($definition);
                $entityManager->flush();

                $this->addFlash('success', sprintf('Custom field "%s" was created successfully.', $definition->getLabel()));
                return $this->redirectToRoute('admin_custom_field_index');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('admin/custom_field/form.html.twig', [
                'mode' => 'Create',
                'definition' => null,
                'formData' => $request->request->all(),
                'objectTypes' => $this->objectTypes->labels(),
                'fieldTypes' => self::FIELD_TYPES,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/custom_field/form.html.twig', [
            'mode' => 'Create',
            'definition' => null,
            'formData' => [],
            'objectTypes' => $this->objectTypes->labels(),
            'fieldTypes' => self::FIELD_TYPES,
        ]);
    }

    #[Route('/{id}/update', name: 'admin_custom_field_update', methods: ['GET', 'POST'])]
    public function update(int $id, Request $request, EntityManagerInterface $entityManager, CustomFieldDefinitionRepository $definitionRepo): Response
    {
        $definition = $entityManager->find(CustomFieldDefinition::class, $id);
        if (!$definition instanceof CustomFieldDefinition) {
            $this->addFlash('error', 'Custom field could not be found.');
            return $this->redirectToRoute('admin_custom_field_index');
        }

        if ($request->isMethod('POST')) {
            $errors = $this->validateRequest($request, $definitionRepo, $definition);
            if ($errors === []) {
                $this->applyRequest($definition, $request, updateOnly: true);
                $entityManager->flush();

                $this->addFlash('success', sprintf('Custom field "%s" was updated successfully.', $definition->getLabel()));
                return $this->redirectToRoute('admin_custom_field_index');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('admin/custom_field/form.html.twig', [
                'mode' => 'Update',
                'definition' => $definition,
                'formData' => $request->request->all(),
                'objectTypes' => $this->objectTypes->labels(),
                'fieldTypes' => self::FIELD_TYPES,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/custom_field/form.html.twig', [
            'mode' => 'Update',
            'definition' => $definition,
            'formData' => [],
            'objectTypes' => $this->objectTypes->labels(),
            'fieldTypes' => self::FIELD_TYPES,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_custom_field_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $definition = $entityManager->find(CustomFieldDefinition::class, $id);
        if (!$definition instanceof CustomFieldDefinition) {
            $this->addFlash('error', 'Custom field could not be found.');
            return $this->redirectToRoute('admin_custom_field_index');
        }

        if ($definition->getSource() !== null) {
            $this->addFlash('error', sprintf('"%s" is registered by %s and cannot be deleted from here.', $definition->getLabel(), $definition->getSource()));
            return $this->redirectToRoute('admin_custom_field_index');
        }

        $label = $definition->getLabel();
        $entityManager->remove($definition);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Custom field "%s" was deleted.', $label));
        return $this->redirectToRoute('admin_custom_field_index');
    }

    /** @return string[] */
    private function validateRequest(Request $request, CustomFieldDefinitionRepository $definitionRepo, ?CustomFieldDefinition $existing): array
    {
        $label = trim((string) $request->request->get('label', ''));

        $violations = Validation::createValidator()->validate($label, new ValidCustomFieldRequest(
            fieldType: (string) $request->request->get('field_type', ''),
            fieldTypes: self::FIELD_TYPES,
            objectType: (string) $request->request->get('object_type', ''),
            objectTypes: $this->objectTypes->labels(),
            slug: trim((string) $request->request->get('slug', '')),
            definitionRepo: $definitionRepo,
            isCreate: $existing === null,
        ));

        return array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    }

    private function applyRequest(CustomFieldDefinition $definition, Request $request, bool $updateOnly = false): void
    {
        if (!$updateOnly) {
            $definition
                ->setObjectType((string) $request->request->get('object_type'))
                ->setSlug(trim((string) $request->request->get('slug')))
                ->setSource(null);
        }

        $optionsRaw = trim((string) $request->request->get('options', ''));
        $options = null;
        if ($optionsRaw !== '') {
            $options = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $optionsRaw))));
        }

        $definition
            ->setLabel(trim((string) $request->request->get('label')))
            ->setFieldType((string) $request->request->get('field_type'))
            ->setOptions($options)
            ->setVisibleOnAdd($request->request->getBoolean('visible_on_add'))
            ->setVisibleOnEdit($request->request->getBoolean('visible_on_edit'))
            ->setVisibleOnListing($request->request->getBoolean('visible_on_listing'))
            ->setSearchable($request->request->getBoolean('searchable'))
            ->setSortOrder($request->request->getInt('sort_order'));
    }
}
