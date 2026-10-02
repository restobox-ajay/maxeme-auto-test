<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Validation\Formatted;
use App\Maxeme\Validation\InputRule;
use Symfony\Component\Validator\Constraints as Assert;

/** One service on the repair order page (jobs[n][id|service_id|name|price|lines][...]). */
final class RepairOrderJobData
{
    /** The service's id on this repair order when it was saved before; blank for a new one. */
    public ?string $id = null;

    /** The catalogue service it was picked from, or blank for one typed in. */
    #[Assert\Regex('/^\d+$/', message: 'Choose a service from the list.')]
    public ?string $serviceId = null;

    #[Assert\NotBlank(message: 'Enter the service name.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Enter the service price.')]
    #[Formatted(InputRule::SignedMoney)]
    public ?string $price = null;

    /** The service's category path, shown beside it (not saved: it is the catalogue service's). */
    public ?string $category = null;

    /** @var list<ServiceLineData> */
    public array $lines = [];

    /**
     * @param mixed $rows the posted jobs[] array
     *
     * @return list<self> the services as typed, wholly blank ones left out
     */
    public static function listFromRequest(mixed $rows): array
    {
        $jobs = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = static function (string $key) use ($row): ?string {
                $text = is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';

                return $text !== '' ? $text : null;
            };

            $job = new self();
            $job->id = $value('id');
            $job->serviceId = $value('service_id');
            $job->name = $value('name');
            $job->price = $value('price');
            $job->category = $value('category');
            $job->lines = ServiceLineData::listFromRequest($row['lines'] ?? []);
            if ($job->name === null && $job->serviceId === null && $job->price === null && $job->lines === []) {
                continue;
            }
            $jobs[] = $job;
        }

        return $jobs;
    }

    public static function fromEntity(RepairOrderJob $job): self
    {
        $data = new self();
        $data->id = (string) $job->getId();
        $data->serviceId = $job->getService()?->getId() !== null ? (string) $job->getService()->getId() : null;
        $data->name = $job->getName();
        $data->price = $job->getPrice();
        $data->category = $job->getService()?->getCategory()?->getPath();
        $data->lines = array_map(ServiceLineData::fromEntity(...), $job->getLines());

        return $data;
    }
}
