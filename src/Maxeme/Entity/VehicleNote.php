<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\AbstractPartyNote;
use Doctrine\ORM\Mapping as ORM;

/** One note on a vehicle: author, message, timestamp (the same note as a client's). */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_vehicle_note')]
#[ORM\Index(name: 'idx_maxeme_vehicle_note_vehicle', fields: ['vehicle'])]
class VehicleNote extends AbstractPartyNote
{
    #[ORM\ManyToOne(targetEntity: Vehicle::class, inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Vehicle $vehicle;

    public function __construct(Vehicle $vehicle)
    {
        parent::__construct();
        $this->vehicle = $vehicle;
    }

    public function getVehicle(): Vehicle { return $this->vehicle; }
}
