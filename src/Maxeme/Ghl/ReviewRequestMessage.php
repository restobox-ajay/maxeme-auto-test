<?php

declare(strict_types=1);

namespace App\Maxeme\Ghl;

/** A completed repair order whose customer should get the GoHighLevel review request (ReviewRequestHandler). */
final class ReviewRequestMessage
{
    public function __construct(
        public readonly int $repairOrderId,
    ) {
    }
}
