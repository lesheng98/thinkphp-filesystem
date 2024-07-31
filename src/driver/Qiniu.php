<?php
declare(strict_types=1);

namespace lesheng98\filesystem\driver;

use Overtrue\Flysystem\Qiniu\QiniuAdapter;
use lesheng98\filesystem\Driver;

class Qiniu extends Driver
{
    protected function createAdapter()
    {
        return new QiniuAdapter(
            $this->config['access_key'],
            $this->config['secret_key'],
            $this->config['bucket'],
            $this->config['domain']
        );
    }
}
