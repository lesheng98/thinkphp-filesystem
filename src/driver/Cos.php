<?php
declare(strict_types=1);

namespace lesheng98\filesystem\driver;

use Overtrue\Flysystem\Cos\CosAdapter;
use lesheng98\filesystem\Driver;

class Cos extends Driver
{
    protected function createAdapter()
    {
        return new CosAdapter($this->config);
    }
}
