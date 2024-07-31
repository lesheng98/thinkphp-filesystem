<?php
declare(strict_types=1);

namespace lesheng98\filesystem\driver;

use yzh52521\Flysystem\Oss\OssAdapter;
use lesheng98\filesystem\Driver;

class Oss extends Driver
{
    protected function createAdapter()
    {
        return new OssAdapter($this->config);
    }
}
