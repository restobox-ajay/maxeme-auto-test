<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Entity\TaxClass;
use App\Maxeme\Entity\TaxRate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/** The tax class form: code, name, and the taxes it charges (`rates[]`, tax rate ids; none = exempt). */
final class TaxClassData extends FormData
{
    public const FIELDS = [
        'code' => 'code',
        'name' => 'name',
    ];

    #[Assert\NotBlank(message: 'Code is required.')]
    #[Assert\Length(max: 8)]
    #[Assert\Regex('/^[A-Za-z0-9]+$/', message: 'Code can have letters and digits only.')]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    /** @var list<string> */
    #[Assert\All([new Assert\Regex('/^\d+$/', message: 'Choose the taxes from the list.')])]
    public array $rateIds = [];

    public static function fromRequest(Request $request): static
    {
        $data = parent::fromRequest($request);
        $data->rateIds = array_values(array_map('strval', $request->request->all('rates')));

        return $data;
    }

    public static function fromEntity(object $entity): static
    {
        $data = parent::fromEntity($entity);
        if ($entity instanceof TaxClass) {
            $data->rateIds = $entity->getRates()->map(static fn (TaxRate $rate): string => (string) $rate->getId())->getValues();
        }

        return $data;
    }

    public function toFormValues(): array
    {
        return parent::toFormValues() + ['rates[]' => $this->rateIds];
    }
}
