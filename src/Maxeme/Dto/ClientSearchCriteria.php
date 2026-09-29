<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\HttpFoundation\Request;

/**
 * The sidebar "Client Information" search (legacy _side_menu.html.twig), with the legacy field
 * names. Matching, as in the legacy ClientRepository::getByParams():
 *  - first / last / preferred name: starts with;
 *  - phone number: exact, against home, work or cell;
 *  - vin, license plate, manufacturer, model: exact, on any of the client's vehicles;
 *  - invoice number: one of the client's invoices (leading zeros optional).
 * Blank fields are ignored and the rest are ANDed. Matching is case-insensitive, as MySQL's
 * collation made it. (The legacy license-plate criterion never matched because of a field-name
 * typo; here it works.)
 */
final class ClientSearchCriteria
{
    /** request field => property */
    private const FIELDS = [
        'firstName' => 'firstName',
        'lastName' => 'lastName',
        'preferredName' => 'preferredName',
        'phoneNumber' => 'phoneNumber',
        'vin' => 'vin',
        'license_plate' => 'licensePlate',
        'manufacturer' => 'manufacturer',
        'model' => 'model',
        'invoiceNumber' => 'invoiceNumber',
    ];

    public string $firstName = '';
    public string $lastName = '';
    public string $preferredName = '';
    public string $phoneNumber = '';
    public string $vin = '';
    public string $licensePlate = '';
    public string $manufacturer = '';
    public string $model = '';
    public string $invoiceNumber = '';

    public static function fromRequest(Request $request): self
    {
        $criteria = new self();
        foreach (self::FIELDS as $field => $property) {
            $criteria->{$property} = trim((string) $request->query->get($field, ''));
        }

        return $criteria;
    }
}
