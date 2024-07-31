<?php
declare(strict_types=1);

namespace lesheng98\filesystem\driver;

use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use lesheng98\filesystem\Driver;

class Ftp extends Driver
{
    protected function createAdapter()
    {
        if (!isset($this->config['root'])) {
            $this->config['root'] = '';
        }

        return new FtpAdapter(FtpConnectionOptions::fromArray($this->config));
    }
}
