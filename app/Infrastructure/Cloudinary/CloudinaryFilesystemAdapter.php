<?php

namespace App\Infrastructure\Cloudinary;

use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Cloudinary;
use ErrorException;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;
use Throwable;

/**
 * A deliberately narrow Flysystem adapter for Cloudinary image and video assets.
 *
 * The path prefix is the only provider-neutral type marker. The adapter strips
 * it before calling Cloudinary and always supplies an explicit resource_type.
 */
final class CloudinaryFilesystemAdapter implements FilesystemAdapter
{
    public function __construct(
        private readonly Cloudinary $cloudinary,
        private readonly ClientInterface $http,
        private readonly string $cloudName,
        private readonly bool $secure = true,
        private readonly ?string $folder = null,
        private readonly int $timeout = 30,
        private readonly string $stagingDirectory,
    ) {
        if ($this->cloudName === '') {
            throw new RuntimeException('Cloudinary configuration is incomplete.');
        }
    }

    public function fileExists(string $path): bool
    {
        [$publicId, $resourceType] = $this->key($path);

        try {
            $this->cloudinary->adminApi()->asset($this->providerPublicId($publicId), [
                'resource_type' => $resourceType,
                'type' => 'upload',
            ]);

            return true;
        } catch (Throwable $exception) {
            if ($exception instanceof NotFound || str_contains(strtolower($exception->getMessage()), 'not found')) {
                return false;
            }

            $this->logOperationDiagnostic($exception, 'exists', $resourceType);
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (! is_resource($contents)) {
            throw UnableToWriteFile::atLocation($path, 'A stream resource is required.');
        }

        $this->upload($path, $contents, $config);
    }

    public function read(string $path): string
    {
        try {
            return (string) $this->http->request('GET', $this->publicUrl($path), [
                'timeout' => $this->timeout,
            ])->getBody();
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, 'Cloudinary read failed.', $exception);
        }
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'A temporary stream could not be opened.');
        }

        try {
            $response = $this->http->request('GET', $this->publicUrl($path), [
                'stream' => true,
                'timeout' => $this->timeout,
            ]);
            stream_copy_to_stream($response->getBody()->detach(), $stream);
            rewind($stream);

            return $stream;
        } catch (Throwable $exception) {
            fclose($stream);
            throw UnableToReadFile::fromLocation($path, 'Cloudinary stream read failed.', $exception);
        }
    }

    public function delete(string $path): void
    {
        [$publicId, $resourceType] = $this->key($path);

        try {
            $response = $this->cloudinary->uploadApi()->destroy($this->providerPublicId($publicId), [
                'resource_type' => $resourceType,
                'type' => 'upload',
                'invalidate' => true,
            ]);
        } catch (Throwable $exception) {
            $this->logDeleteDiagnostic($exception, $resourceType);
            throw UnableToDeleteFile::atLocation($path, 'Cloudinary delete failed.', $exception);
        }

        $result = is_array($response) || $response instanceof \ArrayAccess
            ? strtolower((string) ($response['result'] ?? ''))
            : '';

        if (! in_array($result, ['ok', 'not found'], true)) {
            $failure = new RuntimeException('Cloudinary delete returned an unexpected result.');
            $this->logDeleteDiagnostic($failure, $resourceType);

            throw UnableToDeleteFile::atLocation($path, 'Cloudinary delete failed.', $failure);
        }
    }

    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'Cloudinary directories are not used.');
    }

    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, 'Cloudinary directories are implicit.');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw InvalidVisibilityProvided::withVisibility($visibility, 'public');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        $details = $this->details($path);

        return new FileAttributes($path, null, null, null, $details['mime_type'] ?? null);
    }

    public function lastModified(string $path): FileAttributes
    {
        $details = $this->details($path);
        $timestamp = isset($details['created_at']) ? strtotime((string) $details['created_at']) : null;

        return new FileAttributes($path, null, null, $timestamp === false ? null : $timestamp);
    }

    public function fileSize(string $path): FileAttributes
    {
        $details = $this->details($path);

        return new FileAttributes($path, isset($details['bytes']) ? (int) $details['bytes'] : null);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }

    public function publicUrl(string $path): string
    {
        [$publicId, $resourceType] = $this->key($path);
        $prefix = $this->folder !== null && $this->folder !== ''
            ? trim($this->folder, '/').'/'
            : '';
        $protocol = $this->secure ? 'https' : 'http';

        return $protocol.'://res.cloudinary.com/'.$this->cloudName.'/'.$resourceType.'/upload/'
            .$prefix.$this->encodePublicId($publicId);
    }

    public function getUrl(string $path): string
    {
        return $this->publicUrl($path);
    }

    /** @return array{string, string} */
    public static function parseKey(string $path): array
    {
        $normalized = ltrim($path, '/');
        if (str_contains($normalized, '\\') || str_contains($normalized, '..')) {
            throw new RuntimeException('Invalid Cloudinary media key.');
        }

        foreach (['images/' => 'image', 'videos/' => 'video'] as $prefix => $resourceType) {
            if (str_starts_with($normalized, $prefix) && strlen($normalized) > strlen($prefix)) {
                $publicId = substr($normalized, strlen($prefix));
                if ($publicId !== '' && ! str_contains($publicId, '?')) {
                    return [$publicId, $resourceType];
                }
            }
        }

        throw new RuntimeException('Invalid Cloudinary media key.');
    }

    private function key(string $path): array
    {
        return self::parseKey($path);
    }

    private function upload(string $path, mixed $source, Config $config): void
    {
        [$publicId, $resourceType] = $this->key($path);
        try {
            $options = array_merge((array) $config->get('cloudinary', []), [
                'resource_type' => $resourceType,
                'public_id' => $this->providerPublicId($publicId),
                'overwrite' => false,
                'unique_filename' => false,
                'use_filename' => false,
                'type' => 'upload',
            ]);
            $this->withTemporarySource($source, function (string $temporaryPath) use ($options): void {
                $this->logUploadStaging(
                    is_file($temporaryPath),
                    is_readable($temporaryPath),
                    is_file($temporaryPath) ? (filesize($temporaryPath) ?: 0) : 0,
                );
                $this->cloudinary->uploadApi()->upload($temporaryPath, $options);
            });
        } catch (Throwable $exception) {
            $this->logUploadDiagnostic($exception, $resourceType);
            throw UnableToWriteFile::atLocation($path, 'Cloudinary upload failed.', $exception);
        }
    }

    private function withTemporarySource(mixed $source, callable $callback): mixed
    {
        $temporaryPath = $this->spoolSource($source);

        try {
            return $callback($temporaryPath);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function spoolSource(mixed $source): string
    {
        $stagingDirectory = $this->ensureStagingDirectory();
        $temporaryPath = tempnam($stagingDirectory, 'cloudinary-upload-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Cloudinary upload staging file could not be created.');
        }

        try {
            if (is_string($source)) {
                $written = file_put_contents($temporaryPath, $source);
                if ($written !== strlen($source)) {
                    throw new RuntimeException('Cloudinary upload staging failed.');
                }

                return $temporaryPath;
            }

            if (! is_resource($source)) {
                throw new RuntimeException('Cloudinary upload staging failed.');
            }

            $metadata = stream_get_meta_data($source);
            if (($metadata['seekable'] ?? false) === true) {
                rewind($source);
            }

            $target = fopen($temporaryPath, 'wb');
            if ($target === false) {
                throw new RuntimeException('Cloudinary upload staging failed.');
            }

            try {
                $copied = stream_copy_to_stream($source, $target);
            } finally {
                fclose($target);
            }

            if ($copied === false) {
                throw new RuntimeException('Cloudinary upload staging failed.');
            }

            return $temporaryPath;
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw $exception;
        }
    }

    private function ensureStagingDirectory(): string
    {
        $directory = $this->stagingDirectory;

        if (file_exists($directory) && ! is_dir($directory)) {
            throw new RuntimeException('Cloudinary upload staging path is not a directory.');
        }

        if (! is_dir($directory)) {
            try {
                $created = mkdir($directory, 0775, true);
            } catch (Throwable $exception) {
                if (! is_dir($directory)) {
                    throw new RuntimeException('Cloudinary upload staging directory could not be initialized.', previous: $exception);
                }

                $created = true;
            }

            if (! $created && ! is_dir($directory)) {
                throw new RuntimeException('Cloudinary upload staging directory could not be initialized.');
            }
        }

        if (! is_dir($directory)) {
            throw new RuntimeException('Cloudinary upload staging path is not a directory.');
        }

        if (! is_writable($directory)) {
            throw new RuntimeException('Cloudinary upload staging directory is not writable.');
        }

        return $directory;
    }

    private function logUploadDiagnostic(Throwable $exception, string $resourceType): void
    {
        if (! $this->shouldLogDiagnostics()) {
            return;
        }

        Log::debug('Cloudinary upload failed.', [
            'operation' => 'upload',
            'resource_type' => $resourceType,
            'exception_class' => $exception::class,
            'error_code' => $this->safeErrorCode($exception),
            'error_severity' => $exception instanceof ErrorException ? $exception->getSeverity() : null,
            'source_file' => basename($exception->getFile()),
            'source_line' => $exception->getLine(),
            'stack' => $this->safeStack($exception),
        ]);
    }

    private function logDeleteDiagnostic(Throwable $exception, string $resourceType): void
    {
        $this->logOperationDiagnostic($exception, 'delete', $resourceType);
    }

    private function logOperationDiagnostic(Throwable $exception, string $operation, string $resourceType): void
    {
        if (! $this->shouldLogDiagnostics()) {
            return;
        }

        Log::debug('Cloudinary storage operation failed.', [
            'operation' => $operation,
            'resource_type' => $resourceType,
            'exception_class' => $exception::class,
            'error_code' => $this->safeErrorCode($exception),
            'source_file' => basename($exception->getFile()),
            'source_line' => $exception->getLine(),
            'stack' => $this->safeStack($exception),
        ]);
    }

    private function logUploadStaging(bool $exists, bool $readable, int $bytes): void
    {
        if (! $this->shouldLogDiagnostics()) {
            return;
        }

        Log::debug('Cloudinary upload staging checked.', [
            'operation' => 'upload',
            'exists' => $exists,
            'readable' => $readable,
            'bytes' => $bytes,
        ]);
    }

    private function shouldLogDiagnostics(): bool
    {
        if (! function_exists('app')) {
            return false;
        }

        try {
            $application = app();

            return method_exists($application, 'environment')
                && $application->environment(['local', 'testing']);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<array{class: ?string, method: ?string}> */
    private function safeStack(Throwable $exception): array
    {
        $stack = [];
        foreach (array_slice($exception->getTrace(), 0, 8) as $frame) {
            $stack[] = [
                'class' => isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null,
                'method' => isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null,
            ];
        }

        return $stack;
    }

    private function safeErrorCode(Throwable $exception): int|string|null
    {
        foreach (['getHttpStatusCode', 'getErrorCode', 'getCode'] as $method) {
            if (! method_exists($exception, $method)) {
                continue;
            }

            try {
                $value = $exception->{$method}();
            } catch (Throwable) {
                continue;
            }
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function details(string $path): array
    {
        [$publicId, $resourceType] = $this->key($path);

        try {
            $response = $this->cloudinary->adminApi()->asset($this->providerPublicId($publicId), [
                'resource_type' => $resourceType,
                'type' => 'upload',
            ]);

            return method_exists($response, 'getArrayCopy') ? $response->getArrayCopy() : (array) $response;
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, StorageAttributes::ATTRIBUTE_EXTRA_METADATA, 'Cloudinary metadata lookup failed.', $exception);
        }
    }

    private function encodePublicId(string $publicId): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $publicId)));
    }

    private function providerPublicId(string $publicId): string
    {
        return $this->folder === null || $this->folder === ''
            ? $publicId
            : trim($this->folder, '/').'/'.$publicId;
    }
}
