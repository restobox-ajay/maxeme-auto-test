<?php

declare(strict_types=1);

namespace PaymentManualBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class PaymentManualBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
