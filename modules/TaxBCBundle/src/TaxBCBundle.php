<?php

declare(strict_types=1);

namespace TaxBCBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class TaxBCBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
