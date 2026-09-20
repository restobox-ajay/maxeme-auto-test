<?php

declare(strict_types=1);

namespace TaxCanadaSimpleBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class TaxCanadaSimpleBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
