<?php

declare(strict_types=1);

namespace App\Contract\Payment;

interface PaymentMenuItemInterface
{
    public function getLabel(): string;

    public function getRoute(): string;
}
