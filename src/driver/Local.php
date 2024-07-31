<?php
declare(strict_types=1);

namespace lesheng98\filesystem\driver;

use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use lesheng98\filesystem\Driver;

class Local extends Driver
{
    protected $config = [
        'root' => '',
    ];


    protected function createAdapter()
    {
        $visibility = PortableVisibilityConverter::fromArray(
            $this->config['permissions'] ?? [],
            $this->config['visibility'] ?? Visibility::PRIVATE
        );

        $links = ($this->config['links'] ?? null) === 'skip'
            ? LocalFilesystemAdapter::SKIP_LINKS
            : LocalFilesystemAdapter::DISALLOW_LINKS;

        return new LocalFilesystemAdapter(
            $this->config['root'],
            $visibility,
            $this->config['lock'] ?? LOCK_EX,
            $links
        );
    }
}
