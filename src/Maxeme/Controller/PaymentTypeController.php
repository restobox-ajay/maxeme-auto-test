<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\FormData;
use App\Maxeme\Dto\PaymentTypeData;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Repository\PaymentTypeRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Config › Settings › Payment Types: the invoice builder's payment methods, in list order. One
 * that invoices were paid with cannot be deleted; switch it off (Active) instead.
 *
 * @extends AbstractSettingsListController<PaymentType>
 */
#[Route('/admin/shop-settings/payment-types', name: 'maxeme_settings_payment_type_')]
final class PaymentTypeController extends AbstractSettingsListController
{
    public function __construct(RecordWriter $records, EntityManagerInterface $entityManager, private readonly PaymentTypeRepository $paymentTypes)
    {
        parent::__construct($records, $entityManager);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function index(): Response
    {
        return $this->renderList();
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function createPaymentType(Request $request): RedirectResponse
    {
        return $this->create($request);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function updatePaymentType(#[MapEntity] PaymentType $paymentType, Request $request): RedirectResponse
    {
        return $this->update($paymentType, $request);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function deletePaymentType(#[MapEntity] PaymentType $paymentType): JsonResponse
    {
        return $this->remove($paymentType);
    }

    protected function all(): array { return $this->paymentTypes->findAllOrdered(); }
    protected function newRecord(): object { return new PaymentType(); }
    protected function formData(Request $request): FormData { return PaymentTypeData::fromRequest($request); }
    protected function routePrefix(): string { return 'maxeme_settings_payment_type_'; }
    protected function template(): string { return 'maxeme/settings/payment_types.html.twig'; }

    /** @param PaymentTypeData $data */
    protected function check(object $record, FormData $data): array
    {
        $taken = $this->paymentTypes->findOneByName((string) $data->name);
        if ($taken !== null && $taken !== $record) {
            return ['name' => sprintf('There is already a payment type named %s.', $taken->getName())];
        }

        if ($data->position === null && $record->getId() === null) {
            $record->setPosition($this->paymentTypes->nextPosition());
        }

        return [];
    }

    protected function inUse(object $record): ?string
    {
        $invoices = $this->paymentTypes->countInvoices($record);

        return $invoices > 0
            ? sprintf('%d invoice%s were paid by %s. Switch it off (untick Active) instead.', $invoices, $invoices === 1 ? '' : 's', $record->getName())
            : null;
    }
}
