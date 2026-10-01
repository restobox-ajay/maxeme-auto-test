<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\FormData;
use App\Maxeme\Dto\TaxClassData;
use App\Maxeme\Entity\TaxClass;
use App\Maxeme\Repository\TaxClassRepository;
use App\Maxeme\Repository\TaxRateRepository;
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
 * Config › Settings › Tax Classes: code, name and the taxes charged (e.g. S = GST + PST). Labour and
 * government fees pick one; a class in use cannot be deleted.
 *
 * @extends AbstractSettingsListController<TaxClass>
 */
#[Route('/admin/shop-settings/tax-classes', name: 'maxeme_settings_tax_class_')]
final class TaxClassController extends AbstractSettingsListController
{
    public function __construct(
        RecordWriter $records,
        EntityManagerInterface $entityManager,
        private readonly TaxClassRepository $taxClasses,
        private readonly TaxRateRepository $taxRates,
    ) {
        parent::__construct($records, $entityManager);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function index(): Response
    {
        return $this->renderList(['rates' => $this->taxRates->findAllOrdered()]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function createTaxClass(Request $request): RedirectResponse
    {
        return $this->create($request);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function updateTaxClass(#[MapEntity] TaxClass $taxClass, Request $request): RedirectResponse
    {
        return $this->update($taxClass, $request);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function deleteTaxClass(#[MapEntity] TaxClass $taxClass): JsonResponse
    {
        return $this->remove($taxClass);
    }

    protected function all(): array { return $this->taxClasses->findAllOrdered(); }
    protected function newRecord(): object { return new TaxClass(); }
    protected function formData(Request $request): FormData { return TaxClassData::fromRequest($request); }
    protected function routePrefix(): string { return 'maxeme_settings_tax_class_'; }
    protected function template(): string { return 'maxeme/settings/tax_classes.html.twig'; }

    /** @param TaxClassData $data */
    protected function check(object $record, FormData $data): array
    {
        $taken = $this->taxClasses->findOneByCode((string) $data->code);
        if ($taken !== null && $taken !== $record) {
            return ['code' => sprintf('The code %s is already used by %s.', strtoupper((string) $data->code), $taken->getName())];
        }

        $rates = $data->rateIds !== [] ? $this->taxRates->findBy(['id' => $data->rateIds]) : [];
        if (count($rates) !== count(array_unique($data->rateIds))) {
            return ['rateIds' => 'Choose the taxes from the list.'];
        }
        $record->setRates($rates);

        return [];
    }

    protected function inUse(object $record): ?string
    {
        $uses = $this->taxClasses->countUses($record);

        return $uses > 0
            ? sprintf('%s is used by %d labour rate%s or government fee%2$s. Give them another tax class first.', $record->getName(), $uses, $uses === 1 ? '' : 's')
            : null;
    }
}
