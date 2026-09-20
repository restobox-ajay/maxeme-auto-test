<?php

declare(strict_types=1);

namespace Number1CustomerImportBundle\Service;

use App\Service\RegionSeedData;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Service\CompanyDirectory;
use App\Service\CustomerAccountDirectory;
use App\Service\CustomerInviteMailer;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Column format matches the customer-import CSV used by the legacy Number1 Inventory
 * system: First Name, Last Name, Main Email, Company, Main Phone, Active Status,
 * Invoice To 1-5, Ship To 1-5. Unlike that system, this importer updates an existing
 * customer on a repeat email match instead of silently skipping the row, and it parses
 * the 5 "Invoice To"/"Ship To" cells into a real structured CompanyAddress rather than
 * storing them as 5 opaque strings.
 */
final class CustomerImportService
{
    public function __construct(
        private readonly CompanyDirectory $companyDirectory,
        private readonly CustomerAccountDirectory $customerAccounts,
        private readonly CustomerInviteMailer $inviteMailer,
    ) {
    }

    public function import(UploadedFile $csv, EntityManagerInterface $entityManager, bool $sendInvite): CustomerImportResult
    {
        $result = new CustomerImportResult();

        $file = new \SplFileObject($csv->getPathname());
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',');

        $header = null;
        $rowNumber = 0;

        /** @var array<string, Company> $companyCache */
        $companyCache = [];

        foreach ($file as $row) {
            $rowNumber++;
            if (!is_array($row) || $row === [null]) {
                continue;
            }

            if ($header === null) {
                $header = $this->normalizeHeader($row);
                continue;
            }

            $data = $this->rowToMap($header, $row);
            if ($data === []) {
                continue;
            }

            $result->totalRows++;

            $issues = [];
            $notes = [];

            $email = trim($data['main_email'] ?? '');
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $message = 'Missing or invalid "Main Email".';
                $result->errors[] = sprintf('Row %d: %s', $rowNumber, $message);
                $this->pushRowResult($result, $rowNumber, $email, trim($data['company'] ?? ''), 'error', 'Skipped', [$message], []);
                $result->skipped++;
                continue;
            }

            $companyName = trim($data['company'] ?? '');
            if ($companyName === '') {
                $message = 'Missing "Company" name.';
                $result->errors[] = sprintf('Row %d (%s): %s', $rowNumber, $email, $message);
                $this->pushRowResult($result, $rowNumber, $email, '', 'error', 'Skipped', [$message], []);
                $result->skipped++;
                continue;
            }

            $company = $this->resolveCompany($entityManager, $companyName, $companyCache, $result, $notes);

            $existing = $this->customerAccounts->findByEmail($email);
            $isNew = !$existing instanceof CustomerUser;
            $activeStatus = trim($data['active_status'] ?? '');
            $status = strcasecmp($activeStatus, 'Active') === 0 ? 'Active' : 'Inactive';

            if ($isNew) {
                $user = $this->customerAccounts->create($entityManager, $email, $company, $status);
            } else {
                $user = $existing;
                if ($existing->getCompany()?->getId() !== $company->getId()) {
                    $user->setCompany($company);
                    $notes[] = sprintf('Company reassigned to "%s".', $companyName);
                }
                if ($activeStatus !== '') {
                    $user->setStatus($status, DocumentActor::system());
                }
            }

            if (($data['first_name'] ?? '') !== '') {
                $user->setFirstName($data['first_name']);
            }
            if (($data['last_name'] ?? '') !== '') {
                $user->setLastName($data['last_name']);
            }
            if (($data['main_phone'] ?? '') !== '') {
                $user->setPhoneNumber($data['main_phone']);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            $this->applyAddress($company, $data, 'invoice_to', true, false, $issues);
            $this->applyAddress($company, $data, 'ship_to', false, true, $issues);

            $entityManager->flush();

            if ($isNew && $sendInvite) {
                if ($this->inviteMailer->send($user, $entityManager)) {
                    $result->invitesSent++;
                } else {
                    $notes[] = 'Account created, but the invite email could not be sent.';
                }
            }

            if ($isNew) {
                $result->created++;
                $this->pushRowResult($result, $rowNumber, $email, $companyName, $issues === [] ? 'success' : 'warning', 'Created', $issues, $notes);
            } else {
                $result->updated++;
                $this->pushRowResult($result, $rowNumber, $email, $companyName, $issues === [] ? 'success' : 'warning', 'Updated', $issues, $notes);
            }
        }

        return $result;
    }

    public function templateCsv(): string
    {
        $columns = [
            'First Name', 'Last Name', 'Main Email', 'Company', 'Main Phone', 'Active Status',
            'Invoice To 1', 'Invoice To 2', 'Invoice To 3', 'Invoice To 4', 'Invoice To 5',
            'Ship To 1', 'Ship To 2', 'Ship To 3', 'Ship To 4', 'Ship To 5',
        ];
        $example = [
            'John', 'Smith', 'john.smith@example.com', 'ABC Foods', '5551001', 'Active',
            'John Smith', '101 Main St', 'Toronto, ON', 'M5V 1A1', '',
            'John Smith', '101 Main St', 'Toronto, ON', 'M5V 1A1', '',
        ];

        return $this->csvLine($columns) . $this->csvLine($example);
    }

    /**
     * @param array<string, Company> $companyCache
     * @param list<string> $notes
     */
    private function resolveCompany(EntityManagerInterface $entityManager, string $companyName, array &$companyCache, CustomerImportResult $result, array &$notes): Company
    {
        $cacheKey = strtolower($companyName);
        if (isset($companyCache[$cacheKey])) {
            return $companyCache[$cacheKey];
        }

        $existing = $this->companyDirectory->findByName($companyName);
        if ($existing instanceof Company) {
            $companyCache[$cacheKey] = $existing;

            return $existing;
        }

        $company = $this->companyDirectory->create($entityManager, $companyName);

        $result->companiesCreated++;
        $notes[] = sprintf('Created new company "%s".', $companyName);
        $companyCache[$cacheKey] = $company;

        return $company;
    }

    /**
     * @param array<string, string> $data
     * @param list<string> $issues
     */
    private function applyAddress(Company $company, array $data, string $prefix, bool $isBilling, bool $isShipping, array &$issues): void
    {
        $slots = [];
        for ($i = 1; $i <= 5; $i++) {
            $slots[$i] = trim($data[$prefix . '_' . $i] ?? '');
        }

        if ($slots[1] === '' && $slots[2] === '' && $slots[3] === '' && $slots[4] === '' && $slots[5] === '') {
            return;
        }

        $label = $prefix === 'invoice_to' ? 'Invoice To' : 'Ship To';
        $address = null;
        foreach ($company->getAddresses() as $candidate) {
            if (($isBilling && $candidate->isDefaultBilling()) || ($isShipping && $candidate->isDefaultShipping())) {
                $address = $candidate;
                break;
            }
        }

        if (!$address instanceof CompanyAddress) {
            $address = (new CompanyAddress())
                ->setCompany($company)
                ->setCountry('Canada')
                ->setLabel($label)
                ->setIsDefaultBilling($isBilling)
                ->setIsDefaultShipping($isShipping);
            $company->addAddress($address);
        }

        if ($slots[1] !== '') {
            [$firstName, $lastName] = $this->splitContactName($slots[1]);
            $address->setFirstName($firstName);
            $address->setLastName($lastName);
        }
        if ($slots[2] !== '') {
            $address->setAddressLine1($slots[2]);
        }
        if ($slots[3] !== '') {
            [$city, $province, $parsed] = $this->splitCityProvince($slots[3]);
            $address->setCity($city);
            if ($province !== null) {
                $address->setProvince($province);
            }
            if (!$parsed) {
                $issues[] = sprintf('Could not split "%s" into city/province for %s — stored as-is in city.', $slots[3], $label);
            }
        }
        if ($slots[4] !== '') {
            $address->setPostalCode($slots[4]);
        }
        if ($slots[5] !== '') {
            $address->setAddressLine2($slots[5]);
        }
    }

    /** @return array{0:string,1:?string} */
    private function splitContactName(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        if (count($parts) < 2) {
            return [$value, null];
        }

        $lastName = array_pop($parts);

        return [implode(' ', $parts), $lastName];
    }

    /** @return array{0:string,1:?string,2:bool} */
    private function splitCityProvince(string $value): array
    {
        if (str_contains($value, ',')) {
            [$city, $province] = array_map('trim', explode(',', $value, 2));

            return [$city, $province !== '' ? strtoupper($province) : null, true];
        }

        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $last = strtoupper((string) end($parts));
        // Canadian codes only, deliberately: this splits imported "City PROV" strings, and
        // accepting US state codes here would read "Vancouver WA" as a province. Sourced from
        // RegionSeedData so the list is no longer a fourth private copy.
        if (count($parts) >= 2 && isset(RegionSeedData::PROVINCES['CA'][$last])) {
            array_pop($parts);

            return [implode(' ', $parts), $last, true];
        }

        return [$value, null, false];
    }

    /** @param list<mixed> $row @return list<string> */
    private function normalizeHeader(array $row): array
    {
        $header = [];
        foreach ($row as $cell) {
            $header[] = $this->normalizeHeaderKey((string) $cell);
        }

        return $header;
    }

    private function normalizeHeaderKey(string $value): string
    {
        $value = trim($value);
        $value = strtolower($value);
        $value = str_replace(['-', ' '], '_', $value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
        $value = preg_replace('/_+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    /**
     * @param list<string> $header
     * @param list<mixed> $row
     * @return array<string, string>
     */
    private function rowToMap(array $header, array $row): array
    {
        $map = [];
        foreach ($header as $i => $key) {
            if ($key === '') {
                continue;
            }
            $map[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
        }

        foreach ($map as $value) {
            if ($value !== '') {
                return $map;
            }
        }

        return [];
    }

    /**
     * @param list<string> $issues
     * @param list<string> $notes
     */
    private function pushRowResult(CustomerImportResult $result, int $row, string $email, string $company, string $status, string $action, array $issues, array $notes): void
    {
        $result->rowResults[] = [
            'row' => $row,
            'email' => $email,
            'company' => $company,
            'status' => $status,
            'action' => $action,
            'issues' => array_values(array_unique(array_filter($issues, static fn (string $issue): bool => trim($issue) !== ''))),
            'notes' => array_values(array_unique(array_filter($notes, static fn (string $note): bool => trim($note) !== ''))),
        ];

        if ($status === 'error') {
            $result->errorRows++;

            return;
        }

        if ($status === 'warning') {
            $result->warningRows++;
        }

        $result->validRows++;
    }

    /** @param list<string> $cells */
    private function csvLine(array $cells): string
    {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $cells);
        rewind($fp);
        $line = stream_get_contents($fp) ?: '';
        fclose($fp);

        return $line;
    }
}
