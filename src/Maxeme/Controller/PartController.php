<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\PartData;
use App\Maxeme\Dto\RestockData;
use App\Maxeme\Entity\Part;
use App\Maxeme\Enum\PartType;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Repository\PartRepository;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\PartService;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Parts & Services › Parts Inventory (legacy CNSInventoryBundle manageController).
 *
 * Differences from the legacy app, on purpose: cells are editable in place (the legacy route and
 * library existed but were never wired up), deleting asks for confirmation, and every change of
 * the quantity is recorded in the stock history.
 */
#[Route('/admin/parts', name: 'maxeme_part_')]
#[IsGranted(StaffRole::MANAGER)]
final class PartController extends AbstractMaxemeController
{
    public function __construct(
        private readonly PartService $parts,
        private readonly RecordWriter $records,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, PartRepository $repository): Response
    {
        return $this->render('maxeme/part/index.html.twig', [
            'page' => $repository->findPage(ListQuery::fromRequest($request, array_keys(PartRepository::SORTS))),
            'types' => PartType::cases(),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        return $this->save(new Part(), $request, '%s added.');
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(#[MapEntity] Part $part, Request $request): RedirectResponse
    {
        return $this->save($part, $request, '%s saved.');
    }

    /** One grid cell: `field` (a part form field name) and `value`. Answers the saved value. */
    #[Route('/{id}/field', name: 'field', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function field(#[MapEntity] Part $part, Request $request): JsonResponse
    {
        $field = (string) $request->request->get('field', '');
        if (!isset(PartData::FIELDS[$field])) {
            return $this->json(['message' => 'That column cannot be edited.'], Response::HTTP_BAD_REQUEST);
        }

        if (($errors = $this->parts->saveField($part, $field, (string) $request->request->get('value', ''))) !== []) {
            return $this->json(['message' => implode(' ', $errors)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['value' => PartData::fromEntity($part)->toFormValues()[$field] ?? '', 'message' => 'Saved.']);
    }

    #[Route('/{id}/restock', name: 'restock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restock(#[MapEntity] Part $part, Request $request): RedirectResponse
    {
        $data = RestockData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            $this->flashErrors($errors);
        } else {
            $this->parts->restock($part, $data);
            $this->addFlash('success', sprintf('%s: stock is now %d.', $part->getDisplayName(), $part->getQuantity()));
        }

        return $this->redirectBack($request, 'maxeme_part_index');
    }

    /**
     * The invoice builder's "Order a part": the part fields plus `quantity`, `po_number` and `note`.
     * Answers the new part (it is not added to the invoice, as in the legacy app).
     */
    #[Route('/order', name: 'order', methods: ['POST'])]
    public function order(Request $request): JsonResponse
    {
        $part = PartData::fromRequest($request);
        $part->quantity = null; // the ordered quantity is received by the restock below, not typed as stock
        $part->type = PartType::tryFrom((string) $part->type)?->value ?? PartType::Unit->value;
        $part->notes = $part->notes ?? (trim((string) $request->request->get('note', '')) ?: null);
        $order = RestockData::fromRequest($request);
        $order->unitPrice = $part->unitPrice;
        $order->salePrice = $part->salePrice;

        if (($errors = $this->records->validate($part) + $this->records->validate($order)) !== []) {
            return $this->json(['message' => implode(' ', $errors)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $created = $this->parts->order($part, $order);

        return $this->json(['id' => $created->getId(), 'message' => sprintf('%s ordered: %d in stock.', $created->getDisplayName(), $created->getQuantity())]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(#[MapEntity] Part $part): JsonResponse
    {
        $this->records->delete($part);

        return $this->json(['message' => sprintf('%s deleted.', $part->getDisplayName())]);
    }

    private function save(Part $part, Request $request, string $message): RedirectResponse
    {
        $data = PartData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            $this->flashErrors($errors);
        } else {
            $this->parts->save($part, $data);
            $this->addFlash('success', sprintf($message, $part->getDisplayName()));
        }

        return $this->redirectBack($request, 'maxeme_part_index');
    }
}
