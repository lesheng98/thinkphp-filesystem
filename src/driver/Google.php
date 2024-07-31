<?php
declare(strict_types=1);

namespace lesheng98\filesystem\driver;

use Google\Cloud\Storage\StorageClient;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use lesheng98\filesystem\Driver;

class Google extends Driver
{
    protected function createAdapter()
    {
        $storageClient = new StorageClient([
            'projectId' => $this->config['project_id'],
        ]);
        $bucket = $storageClient->bucket($this->config['bucket']);

        return new GoogleCloudStorageAdapter($bucket, $this->config['prefix'] ?? '');
    }
}