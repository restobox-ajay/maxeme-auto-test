<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Document\DocPrefixSettings;
use App\Maxeme\Dto\TaxRateData;
use App\Maxeme\Repository\TaxRateRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Config › Settings, its first two tabs: Tax Rates (GST, PST: what invoices charge) and Doc Prefixes.
 * The other tabs are TaxClassController, PaymentTypeController and TechnicianController.
 */
#[Route('/admin/shop-settings', name: 'maxeme_settings')]
final class SettingsController extends AbstractMaxemeController
{
    #[Route('', name: '', methods: ['GET'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function taxRates(TaxRateRepository $taxRates): Response
    {
        return $this->render('maxeme/settings/tax_rates.html.twig', ['rates' => $taxRates->findAllOrdered()]);
    }

    /** Every rate at once (`rates[id][name|rate]`); one bad row saves none. */
    #[Route('/tax-rates', name: '_tax_rates_save', methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function saveTaxRates(Request $request, TaxRateRepository $taxRates, RecordWriter $records, EntityManagerInterface $entityManager): RedirectResponse
    {
        $posted = $request->request->all('rates');
        $rows = [];
        foreach ($taxRates->findAllOrdered() as $rate) {
            $row = $posted[$rate->getId()] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $data = TaxRateData::fromArray($row);
            if (($errors = $records->validate($data)) !== []) {
                $this->flashErrors($errors);

                return $this->redirectToRoute('maxeme_settings');
            }
            $rows[] = [$rate, $data];
        }

        foreach ($rows as [$rate, $data]) {
            $data->applyTo($rate);
        }
        $entityManager->flush();
        $this->addFlash('success', 'Tax rates saved.');

        return $this->redirectToRoute('maxeme_settings');
    }

    #[Route('/doc-prefixes', name: '_prefixes', methods: ['GET'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function docPrefixes(DocPrefixSettings $prefixes): Response
    {
        return $this->render('maxeme/settings/doc_prefixes.html.twig', [
            'prefixes' => $prefixes->definitions(),
            'values' => $prefixes->values(),
        ]);
    }

    #[Route('/doc-prefixes', name: '_prefixes_save', methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function saveDocPrefixes(Request $request, DocPrefixSettings $prefixes): RedirectResponse
    {
        $error = $prefixes->save(array_map('strval', $request->request->all('prefixes')));
        $this->addFlash($error === null ? 'success' : 'error', $error ?? 'Document prefixes saved.');

        return $this->redirectToRoute('maxeme_settings_prefixes');
    }
}
