<?php
declare(strict_types=1);

namespace lesheng98\filesystem\facade;

use think\Facade;

class Filesystem extends Facade
{
    protected static function getFacadeClass()
    {
        return 'filesystem\Filesystem';
    }
}
