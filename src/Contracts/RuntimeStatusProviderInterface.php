<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

use Apntalk\EslReact\Runner\RuntimeStatusSnapshot;

interface RuntimeStatusProviderInterface
{
    public function statusSnapshot(): RuntimeStatusSnapshot;
}
