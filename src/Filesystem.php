<?php
declare(strict_types=1);

namespace lesheng98\filesystem;

use InvalidArgumentException;
use think\helper\Arr;
use think\helper\Str;
use think\Manager;

class Filesystem extends Manager
{
    protected array $customCreators = [];

    protected $namespace = '\\filesystem\\driver\\';

    public function disk(string $name = null): Driver
    {
        return $this->driver($name);
    }

    public function cloud(string $name = null): Driver
    {
        return $this->driver($name);
    }

    /**
     * 调用自定义驱动
     */
    protected function callCustomCreator(array $config): mixed
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    protected function resolveType(string $name): mixed
    {
        return $this->getDiskConfig($name, 'type', 'local');
    }

    protected function resolveConfig(string $name): array
    {
        return $this->getDiskConfig($name);
    }

    protected function createDriver(string $name): Driver
    {
        $type = $this->resolveType($name);


        if (isset($this->customCreators[$name])) {
            return $this->callCustomCreator($type);
        }

        $method = 'create' . Str::studly($type) . 'Driver';

        $params = $this->resolveParams($name);


        if (method_exists($this, $method)) {
            return $this->$method(...$params);
        }

        $class = $this->resolveClass($type);

        return $this->app->invokeClass($class, $params);
    }

    /**
     * 获取配置
     */
    public function getConfig(string $name = null, mixed $default = null): mixed
    {
        if (!is_null($name)) {
            return $this->app->config->get('filesystem.' . $name, $default);
        }

        return $this->app->config->get('filesystem');
    }

    /**
     * 获取磁盘配置
     */
    public function getDiskConfig(string $disk, string $name = null, mixed $default = null): mixed
    {
        if ($config = $this->getConfig("disks.{$disk}")) {
            return Arr::get($config, $name, $default);
        }

        throw new InvalidArgumentException("Disk [$disk] not found.");
    }

    /**
     * 获取默认驱动
     */
    public function getDefaultDriver(): ?string
    {
        return $this->getConfig('default');
    }

    public function extend(string $driver, \Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * 动态调用
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->driver()->$method(...$parameters);
    }
}
