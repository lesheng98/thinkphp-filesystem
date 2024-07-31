<?php
declare(strict_types=1);

namespace lesheng98\filesystem;

use Closure;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use think\Cache;
use think\File;
use think\file\UploadedFile;
use think\helper\Arr;

/**
 * Class Driver
 */
abstract class Driver
{

    protected Cache $cache;
    protected Filesystem $filesystem;


    protected FilesystemAdapter $adapter;

    protected PathPrefixer $prefixer;

    protected array $config = [];

    public function __construct(Cache $cache, array $config)
    {
        $this->cache = $cache;
        $this->config = array_merge($this->config, $config);

        $separator = $config['directory_separator'] ?? DIRECTORY_SEPARATOR;
        $this->prefixer = new PathPrefixer($config['root'] ?? '', $separator);

        if (isset($config['prefix'])) {
            $this->prefixer = new PathPrefixer($this->prefixer->prefixPath($config['prefix']), $separator);
        }

        $this->adapter = $this->createAdapter();
        $this->filesystem = $this->createFilesystem($this->adapter, $this->config);
    }

    abstract protected function createAdapter();

    protected function createFilesystem(FilesystemAdapter $adapter, array $config): Filesystem
    {
        if ($config['read-only'] ?? false === true) {
            $adapter = new ReadOnlyFilesystemAdapter($adapter);
        }

        if (!empty($config['prefix'])) {
            $adapter = new PathPrefixedAdapter($adapter, $config['prefix']);
        }

        return new Filesystem($adapter, Arr::only($config, [
            'directory_visibility',
            'disable_asserts',
            'temporary_url',
            'url',
            'visibility',
        ]));
    }

    /**
     * 获取文件完整路径
     */
    public function path(string $path): string
    {
        return $this->prefixer->prefixPath($path);
    }

    /**
     * 拼接文件路径跟url
     */
    protected function concatPathToUrl(string $url, string $path): string
    {
        return rtrim($url, '/') . '/' . ltrim($path, '/');
    }

    /**
     * 检查文件或目录是否存在
     */
    public function exists(string $path): bool
    {
        return $this->filesystem->has($path);
    }

    /**
     * 检查文件或目录是否缺失
     */
    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    /**
     * 检查文件是否存在
     */
    public function fileExists(string $path): bool
    {
        return $this->filesystem->fileExists($path);
    }

    /**
     * 检查文件是否缺失
     */
    public function fileMissing(string $path): bool
    {
        return !$this->fileExists($path);
    }

    /**
     * 检查目录是否存在
     */
    public function directoryExists(string $path): bool
    {
        return $this->filesystem->directoryExists($path);
    }

    /**
     * 检查目录是否缺失
     */
    public function directoryMissing(string $path): bool
    {
        return !$this->directoryExists($path);
    }

    /**
     * 读取文件内容
     */
    public function get(string $path): string|null
    {
        try {
            return $this->filesystem->read($path);
        } catch (UnableToReadFile $e) {
            throw_if($this->throwsExceptions(), $e);
        }
        return null;
    }

    /**
     * 将文件转为文件流形式返回
     */
    public function response(string $path, string|null $name = null, array $headers = [], string $disposition = 'inline'): StreamedResponse
    {
        $response = new StreamedResponse;

        if (!array_key_exists('Content-Type', $headers)) {
            $headers['Content-Type'] = $this->mimeType($path);
        }

        if (!array_key_exists('Content-Length', $headers)) {
            $headers['Content-Length'] = $this->size($path);
        }

        if (!array_key_exists('Content-Disposition', $headers)) {
            $filename = $name ?? basename($path);

            $disposition = $response->headers->makeDisposition(
                $disposition, $filename, $this->fallbackName($filename)
            );

            $headers['Content-Disposition'] = $disposition;
        }

        $response->headers->replace($headers);

        $response->setCallback(function () use ($path) {
            $stream = $this->readStream($path);
            fpassthru($stream);
            fclose($stream);
        });

        return $response;
    }

    /**
     * 下载文件
     */
    public function download(string $path, string|null $name = null, array $headers = []): StreamedResponse
    {
        return $this->response($path, $name, $headers, 'attachment');
    }

    /**
     * 将文件名转为ascii码
     */
    protected function fallbackName(string $name): string
    {
        $ascii = '';
        for ($i = 0; $i < strlen($name); $i++) {
            $ascii .= ord($name[$i]);
        }
        return str_replace('%', '', $ascii);
    }

    /**
     * 获取路径的可见性
     */
    public function getVisibility(string $path): string
    {
        if ($this->filesystem->visibility($path) == Visibility::PUBLIC) {
            return 'public';
        }

        return 'private';
    }

    /**
     * 设置路径的可见性
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        try {
            $this->filesystem->setVisibility($path, $visibility);
        } catch (UnableToSetVisibility $e) {
            $this->throw_if($this->throwsExceptions(), $e);

            return false;
        }

        return true;
    }

    /**
     * 添加内容到文件头
     */
    public function prepend(string $path, string $data, string $separator = PHP_EOL): bool
    {
        if ($this->fileExists($path)) {
            return $this->put($path, $data . $separator . $this->get($path));
        }

        return $this->put($path, $data);
    }

    /**
     * 添加内容到文件尾
     */
    public function append(string $path, string $data, string $separator = PHP_EOL): bool
    {
        if ($this->fileExists($path)) {
            return $this->put($path, $this->get($path) . $separator . $data);
        }

        return $this->put($path, $data);
    }


    /**
     * 删除指定路径的文件
     */
    public function delete(string|array $paths): bool
    {
        $paths = is_array($paths) ? $paths : func_get_args();

        $success = true;

        foreach ($paths as $path) {
            try {
                $this->filesystem->delete($path);
            } catch (UnableToDeleteFile $e) {
                throw_if($this->throwsExceptions(), $e);

                $success = false;
            }
        }

        return $success;
    }

    /**
     * 复制文件
     */
    public function copy(string $from, string $to): bool
    {
        try {
            $this->filesystem->copy($from, $to);
        } catch (UnableToCopyFile $e) {
            throw_if($this->throwsExceptions(), $e);

            return false;
        }

        return true;
    }

    /**
     * 移动文件
     */
    public function move(string $from, string $to): bool
    {
        try {
            $this->filesystem->move($from, $to);
        } catch (UnableToMoveFile $e) {
            throw_if($this->throwsExceptions(), $e);

            return false;
        }

        return true;
    }

    /**
     * 获取文件大小
     *
     * @throws FilesystemException
     */
    public function size(string $path): int
    {
        return $this->filesystem->fileSize($path);
    }

    /**
     * 获取文件类型
     */
    public function mimeType(string $path): string|false
    {
        try {
            return $this->filesystem->mimeType($path);
        } catch (UnableToRetrieveMetadata $e) {
            throw_if($this->throwsExceptions(), $e);
        }

        return false;
    }

    /**
     * 获取文件最后修改时间
     */
    public function lastModified(string $path): int
    {
        return $this->filesystem->lastModified($path);
    }


    /**
     * 读取文件流
     */
    public function readStream(string $path)
    {
        try {
            return $this->filesystem->readStream($path);
        } catch (UnableToReadFile $e) {
            throw_if($this->throwsExceptions(), $e);
        }
    }

    /**
     * 写入文件流
     */
    public function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        try {
            $this->filesystem->writeStream($path, $resource, $options);
        } catch (UnableToWriteFile|UnableToSetVisibility $e) {
            throw_if($this->throwsExceptions(), $e);
            return false;
        }

        return true;
    }

    /**
     * 获取本地文件url
     */
    protected function getLocalUrl($path)
    {
        if (isset($this->config['url'])) {
            return $this->concatPathToUrl($this->config['url'], $path);
        }

        return $path;
    }

    /**
     * 获取文件url
     */
    public function url(string $path): string
    {
        $adapter = $this->adapter;

        if (method_exists($adapter, 'getUrl')) {
            return $adapter->getUrl($path);
        } elseif (method_exists($this->filesystem, 'getUrl')) {
            return $this->filesystem->getUrl($path);
        } elseif ($adapter instanceof SftpAdapter || $adapter instanceof FtpAdapter) {
            return $this->getFtpUrl( $path );
        } elseif ($adapter instanceof LocalFilesystemAdapter) {
            return $this->getLocalUrl($path);
        } else {
            throw new \RuntimeException('This driver does not support retrieving URLs.');
        }
    }


    /**
     * 获取ftp文件url
     */
    protected function getFtpUrl(string $path): string
    {
        return isset($this->config['url'])
            ? $this->concatPathToUrl($this->config['url'], $path)
            : $path;
    }

    /**
     * Replace the scheme, host and port of the given UriInterface with values from the given URL.
     */
    protected function replaceBaseUrl(UriInterface $uri, string $url): UriInterface
    {
        $parsed = parse_url($url);

        return $uri
            ->withScheme($parsed['scheme'])
            ->withHost($parsed['host'])
            ->withPort($parsed['port'] ?? null);
    }

    /**
     * 获取Filesystem驱动
     */
    public function getDriver(): FilesystemOperator
    {
        return $this->filesystem;
    }

    /**
     * 获取Filesystem适配器
     */
    public function getAdapter(): FilesystemAdapter
    {
        return $this->adapter;
    }

    /**
     * 保存文件
     */
    public function putFile(string $path, File|string $file, null|string|Closure $rule = null, array $options = []): bool|string
    {
        $file = is_string($file) ? new File($file) : $file;
        return $this->putFileAs($path, $file, $file->hashName($rule), $options);
    }

    /**
     * 指定文件名保存文件
     */
    public function putFileAs(string $path, File $file, string $name, array $options = []): bool|string
    {
        $stream = fopen($file->getRealPath(), 'r');
        $path = trim($path . '/' . $name, '/');

        $result = $this->put($path, $stream, $options);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $result ? $path : false;
    }

    /**
     * 写入文件
     */
    public function put(string $path, $contents, $options = []): bool
    {
        $options = is_string($options)
            ? ['visibility' => $options]
            : (array)$options;

        // If the given contents is actually a file or uploaded file instance than we will
        // automatically store the file using a stream. This provides a convenient path
        // for the developer to store streams without managing them manually in code.
        if ($contents instanceof File ||
            $contents instanceof UploadedFile) {
            return $this->putFile($path, $contents, $options);
        }

        try {
            if ($contents instanceof StreamInterface) {
                $this->writeStream($path, $contents->detach(), $options);
                return true;
            }

            is_resource($contents)
                ? $this->writeStream($path, $contents, $options)
                : $this->write($path, $contents, $options);
        } catch (UnableToWriteFile|UnableToSetVisibility $e) {
            throw_if($this->throwsExceptions(), $e);

            return false;
        }

        return true;
    }

    /**
     * 获取指定目录中的所有文件
     */
    public function files(string|null $directory = null, bool $recursive = false): array
    {
        return $this->filesystem->listContents($directory ?? '', $recursive)
            ->filter(function (StorageAttributes $attributes) {
                return $attributes->isFile();
            })
            ->sortByPath()
            ->map(function (StorageAttributes $attributes) {
                return $attributes->path();
            })
            ->toArray();
    }

    /**
     * 递归获取指定目录及其子目录中的所有文件
     */
    public function allFiles(string|null $directory = null): array
    {
        return $this->files($directory, true);
    }

    /**
     * 获取指定目录中的所有目录
     */
    public function directories(string|null $directory = null, bool $recursive = false): array
    {
        return $this->filesystem->listContents($directory ?? '', $recursive)
            ->filter(function (StorageAttributes $attributes) {
                return $attributes->isDir();
            })
            ->map(function (StorageAttributes $attributes) {
                return $attributes->path();
            })
            ->toArray();
    }

    /**
     * 递归获取指定目录及其子目录中的所有目录
     */
    public function allDirectories(string|null $directory = null): array
    {
        return $this->directories($directory, true);
    }

    /**
     * 创建目录
     */
    public function makeDirectory(string $path): bool
    {
        try {
            $this->filesystem->createDirectory($path);
        } catch (UnableToCreateDirectory|UnableToSetVisibility $e) {
            throw_if($this->throwsExceptions(), $e);

            return false;
        }

        return true;
    }

    /**
     * 删除目录
     */
    public function deleteDirectory(string $directory): bool
    {
        try {
            $this->filesystem->deleteDirectory($directory);
        } catch (UnableToDeleteDirectory $e) {
            throw_if($this->throwsExceptions(), $e);

            return false;
        }

        return true;
    }

    /**
     * 是否抛出异常
     */
    protected function throwsExceptions(): bool
    {
        return (bool)($this->config['throw'] ?? false);
    }

    public function __call($method, $parameters)
    {
        return $this->filesystem->$method(...$parameters);
    }
}
