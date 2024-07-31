<?php
declare(strict_types=1);

namespace lesheng98\filesystem;

use think\Service;

class Service extends Service
{
    public function register()
    {
        $this->app->bind('filesystem', Filesystem::class);
    }
}
