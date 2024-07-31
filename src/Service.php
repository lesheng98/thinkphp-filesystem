<?php
declare(strict_types=1);

namespace lesheng98\filesystem;

use think\Service as ThinkService;

class Service extends ThinkService
{
    public function register()
    {
        $this->app->bind('filesystem', Filesystem::class);
    }
}
