<?php

namespace Tests\Feature\Cloudinary;

use App\Console\Commands\MediaStorageCheck;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaStorageCheckTest extends TestCase
{
    public function test_video_probe_is_a_non_empty_mp4_fixture(): void
    {
        $command = app(MediaStorageCheck::class);
        $method = new \ReflectionMethod($command, 'probeVideo');
        $video = $method->invoke($command);

        $this->assertNotSame('', $video);
        $this->assertGreaterThan(0, strlen($video));
        $this->assertSame('ftyp', substr($video, 4, 4));
        $this->assertStringContainsString('mdat', $video);
        $this->assertStringContainsString('moov', $video);
    }

    public function test_successful_probe_cleans_both_files(): void
    {
        $disk = new class
        {
            public array $files = [];

            public function put(string $key, string $contents, array $options = []): bool
            {
                $this->files[$key] = $contents;

                return true;
            }

            public function exists(string $key): bool
            {
                return array_key_exists($key, $this->files);
            }

            public function url(string $key): string
            {
                return 'https://probe.test/'.$key;
            }

            public function readStream(string $key)
            {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $this->files[$key]);
                rewind($stream);

                return $stream;
            }

            public function delete(string $key): bool
            {
                unset($this->files[$key]);

                return true;
            }
        };
        Storage::shouldReceive('disk')->once()->with('fake')->andReturn($disk);

        $this->artisan('media:storage-check', ['--disk' => 'fake'])->assertSuccessful();
        $this->assertSame([], $disk->files);
    }

    public function test_partial_upload_failure_still_cleans_successful_probe(): void
    {
        $disk = new class
        {
            public array $files = [];

            private int $uploads = 0;

            public function put(string $key, string $contents, array $options = []): bool
            {
                $this->uploads++;
                if ($this->uploads === 2) {
                    return false;
                }

                $this->files[$key] = $contents;

                return true;
            }

            public function exists(string $key): bool
            {
                return array_key_exists($key, $this->files);
            }

            public function delete(string $key): bool
            {
                unset($this->files[$key]);

                return true;
            }
        };
        Storage::shouldReceive('disk')->once()->with('fake')->andReturn($disk);

        $this->artisan('media:storage-check', ['--disk' => 'fake'])
            ->assertFailed();
        $this->assertSame([], $disk->files);
    }
}
