<?php

declare(strict_types=1);

namespace CustomFieldExampleBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class CustomFieldExampleBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
