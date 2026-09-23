<?php

namespace Tests\Feature\Cloudinary;

use App\Infrastructure\Cloudinary\CloudinaryFilesystemAdapter;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
use GuzzleHttp\ClientInterface;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use League\Flysystem\Config;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CloudinaryFilesystemAdapterTest extends TestCase
{
    /** @var list<string> */
    private array $ownedStagingDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedStagingDirectories as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        $this->ownedStagingDirectories = [];
        parent::tearDown();
    }

    public function test_missing_cloud_name_is_rejected_before_any_provider_call(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cloudinary configuration is incomplete.');

        new CloudinaryFilesystemAdapter(
            Mockery::mock(Cloudinary::class),
            Mockery::mock(ClientInterface::class),
            '',
            true,
            null,
            30,
            $this->stagingDirectory(),
        );
    }

    public function test_diagnostics_are_disabled_for_a_bare_container(): void
    {
        $previous = Container::getInstance();
        Container::setInstance(new Container);

        try {
            $method = new \ReflectionMethod($this->adapter(), 'shouldLogDiagnostics');
            $this->assertFalse($method->invoke($this->adapter()));
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_image_and_video_keys_map_to_explicit_resource_types(): void
    {
        $this->assertSame(['01IMAGE', 'image'], CloudinaryFilesystemAdapter::parseKey('images/01IMAGE'));
        $this->assertSame(['01VIDEO', 'video'], CloudinaryFilesystemAdapter::parseKey('videos/01VIDEO'));
    }

    public function test_cloudinary_logical_media_keys_are_never_directories(): void
    {
        $this->assertFalse($this->adapter()->directoryExists('images/01IMAGE'));
        $this->assertFalse($this->adapter()->directoryExists('videos/01VIDEO'));
    }

    public function test_unknown_or_malformed_prefixes_are_rejected(): void
    {
        foreach (['media/01', 'image/01', 'images/', 'videos/', 'images/../01', 'videos/01?x=1'] as $key) {
            try {
                CloudinaryFilesystemAdapter::parseKey($key);
                $this->fail('Malformed key was accepted: '.$key);
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid Cloudinary media key.', $exception->getMessage());
            }
        }
    }

    public function test_server_generated_key_shape_is_collision_safe(): void
    {
        $image = 'images/'.(string) Str::ulid();
        $video = 'videos/'.(string) Str::ulid();

        $this->assertNotSame($image, $video);
        $this->assertSame('image', CloudinaryFilesystemAdapter::parseKey($image)[1]);
        $this->assertSame('video', CloudinaryFilesystemAdapter::parseKey($video)[1]);
    }

    public function test_binary_and_seekable_sources_are_spooled_and_removed_after_success(): void
    {
        $adapter = $this->adapter();
        $method = new \ReflectionMethod($adapter, 'withTemporarySource');

        foreach ([
            'binary contents',
            fopen('php://temp', 'w+b'),
        ] as $source) {
            if (is_resource($source)) {
                fwrite($source, 'stream contents');
                rewind($source);
            }

            $temporaryPath = null;
            $result = $method->invoke($adapter, $source, function (string $path) use (&$temporaryPath): string {
                $temporaryPath = $path;

                return file_get_contents($path) ?: '';
            });

            $this->assertSame(is_resource($source) ? 'stream contents' : 'binary contents', $result);
            $this->assertNotNull($temporaryPath);
            $this->assertFileDoesNotExist($temporaryPath);

            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    public function test_staging_directory_is_created_before_the_file_is_spooled(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cloudinary-staging-'.(string) Str::ulid();
        $adapter = $this->adapter($directory);
        $method = new \ReflectionMethod($adapter, 'withTemporarySource');
        $temporaryPath = null;

        try {
            $method->invoke($adapter, 'binary contents', function (string $path) use (&$temporaryPath): string {
                $temporaryPath = $path;

                return file_get_contents($path) ?: '';
            });

            $this->assertDirectoryExists($directory);
            $this->assertFileDoesNotExist($temporaryPath);
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_unusable_staging_path_fails_before_upload_staging(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cloudinary-staging-file-'.(string) Str::ulid();
        file_put_contents($path, 'not a directory');

        try {
        $adapter = $this->adapter($path);
            $method = new \ReflectionMethod($adapter, 'withTemporarySource');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cloudinary upload staging path is not a directory.');
            $method->invoke($adapter, 'binary contents', static fn (string $temporaryPath): string => $temporaryPath);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_non_seekable_sources_are_spooled_and_removed_after_failure(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('stream_socket_pair() is unsupported on Windows.');
        }

        if (! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('stream_socket_pair() is unavailable on this platform.');
        }

        try {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        } catch (\Throwable) {
            $pair = false;
        }
        if ($pair === false) {
            $this->markTestSkipped('The platform does not provide socket pairs.');
        }

        fwrite($pair[0], 'non-seekable contents');
        fclose($pair[0]);
        $adapter = $this->adapter();
        $method = new \ReflectionMethod($adapter, 'withTemporarySource');
        $temporaryPath = null;
        $failure = new RuntimeException('provider failure');

        try {
            $method->invoke($adapter, $pair[1], function (string $path) use (&$temporaryPath, $failure): void {
                $temporaryPath = $path;
                throw $failure;
            });
            $this->fail('The provider failure should be preserved.');
        } catch (\Throwable $exception) {
            $this->assertSame($failure, $exception->getPrevious() ?? $exception);
        } finally {
            fclose($pair[1]);
        }

        $this->assertNotNull($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);
    }

    public function test_write_calls_the_sdk_method_and_keeps_the_previous_failure(): void
    {
        $temporaryPath = null;
        $options = null;
        $failure = new RuntimeException('provider failure');
        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('upload')->once()->withArgs(function (mixed $path, array $receivedOptions) use (&$temporaryPath, &$options): bool {
            $temporaryPath = $path;
            $options = $receivedOptions;

            return is_string($path) && is_file($path) && is_readable($path) && filesize($path) > 0;
        })->andThrow($failure);
        $cloudinary = Mockery::mock(Cloudinary::class);
        $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);
        $adapter = new CloudinaryFilesystemAdapter(
            $cloudinary,
            Mockery::mock(ClientInterface::class),
            'test-cloud',
            true,
            null,
            30,
            $this->stagingDirectory(),
        );

        try {
            $adapter->write('images/01IMAGE', 'valid image bytes', new Config);
            $this->fail('The upload failure should be mapped.');
        } catch (UnableToWriteFile $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }

        $this->assertIsString($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);
        $this->assertSame('image', $options['resource_type']);
        $this->assertFalse($options['overwrite']);
        $this->assertIsString($options['public_id']);
    }

    public function test_image_and_video_deletion_use_matching_ids_and_resource_types(): void
    {
        foreach ([
            ['images/IMAGE', 'folder/IMAGE', 'image'],
            ['videos/VIDEO', 'folder/VIDEO', 'video'],
        ] as [$key, $providerId, $resourceType]) {
            $capturedId = null;
            $capturedType = null;
            $uploadApi = Mockery::mock(UploadApi::class);
            $uploadApi->shouldReceive('destroy')->once()->withArgs(
                function (string $actualId, array $options) use (&$capturedId, &$capturedType): bool {
                    $capturedId = $actualId;
                    $capturedType = $options['resource_type'] ?? null;

                    return $options['type'] === 'upload' && $options['invalidate'] === true;
                },
            )->andReturn($this->apiResponse('ok'));
            $cloudinary = Mockery::mock(Cloudinary::class);
            $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);
            $adapter = new CloudinaryFilesystemAdapter(
                $cloudinary,
                Mockery::mock(ClientInterface::class),
                'test-cloud',
                true,
                'folder',
                30,
                $this->stagingDirectory(),
            );

            $adapter->delete($key);
            $this->assertSame($providerId, $capturedId);
            $this->assertSame($resourceType, $capturedType);
        }
    }

    public function test_not_found_destroy_result_is_idempotent(): void
    {
        $capturedId = null;
        $capturedType = null;
        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('destroy')->once()->withArgs(
            function (string $actualId, array $options) use (&$capturedId, &$capturedType): bool {
                $capturedId = $actualId;
                $capturedType = $options['resource_type'] ?? null;

                return $options['type'] === 'upload' && $options['invalidate'] === true;
            },
        )->andReturn($this->apiResponse('not found'));
        $cloudinary = Mockery::mock(Cloudinary::class);
        $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);

        (new CloudinaryFilesystemAdapter(
            $cloudinary,
            Mockery::mock(ClientInterface::class),
            'test-cloud',
            true,
            null,
            30,
            $this->stagingDirectory(),
        ))
            ->delete('images/IMAGE');

        $this->assertSame('IMAGE', $capturedId);
        $this->assertSame('image', $capturedType);
    }

    public function test_not_found_asset_is_reported_as_missing(): void
    {
        $adminApi = Mockery::mock(AdminApi::class);
        $adminApi->shouldReceive('asset')->once()->andThrow(new NotFound('resource unavailable'));
        $cloudinary = Mockery::mock(Cloudinary::class);
        $cloudinary->shouldReceive('adminApi')->once()->andReturn($adminApi);

        $this->assertFalse(
            (new CloudinaryFilesystemAdapter(
                $cloudinary,
                Mockery::mock(ClientInterface::class),
                'test-cloud',
                true,
                null,
                30,
                $this->stagingDirectory(),
            ))
                ->fileExists('videos/VIDEO'),
        );
    }

    public function test_unexpected_destroy_result_and_provider_errors_are_mapped(): void
    {
        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('destroy')->once()->andReturn($this->apiResponse('pending'));
        $cloudinary = Mockery::mock(Cloudinary::class);
        $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);

        $this->expectException(UnableToDeleteFile::class);
        (new CloudinaryFilesystemAdapter(
            $cloudinary,
            Mockery::mock(ClientInterface::class),
            'test-cloud',
            true,
            null,
            30,
            $this->stagingDirectory(),
        ))
            ->delete('videos/VIDEO');
    }

    public function test_destroy_exception_is_wrapped_with_the_original_throwable(): void
    {
        $failure = new RuntimeException('provider failure');
        $uploadApi = Mockery::mock(UploadApi::class);
        $uploadApi->shouldReceive('destroy')->once()->andThrow($failure);
        $cloudinary = Mockery::mock(Cloudinary::class);
        $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);

        try {
            (new CloudinaryFilesystemAdapter(
                $cloudinary,
                Mockery::mock(ClientInterface::class),
                'test-cloud',
                true,
                null,
                30,
                $this->stagingDirectory(),
            ))
                ->delete('images/IMAGE');
            $this->fail('The delete failure should be mapped.');
        } catch (UnableToDeleteFile $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    private function adapter(?string $stagingDirectory = null): CloudinaryFilesystemAdapter
    {
        return new CloudinaryFilesystemAdapter(
            Mockery::mock(Cloudinary::class),
            Mockery::mock(ClientInterface::class),
            'test-cloud',
            true,
            null,
            30,
            $stagingDirectory ?? $this->stagingDirectory(),
        );
    }

    private function stagingDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cloudinary-test-'.(string) Str::ulid();
        $this->ownedStagingDirectories[] = $directory;

        return $directory;
    }

    private function apiResponse(string $result): ApiResponse
    {
        return new ApiResponse(
            ['result' => $result],
            [
                'x-featuratelimit-reset' => ['2026-01-01 00:00:00'],
                'x-featuratelimit-limit' => ['500'],
                'x-featuratelimit-remaining' => ['499'],
            ],
        );
    }
}
