<?php

namespace Tests\Feature\Cloudinary;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @group cloudinary-integration */
class CloudinaryStorageIntegrationTest extends TestCase
{
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CLOUDINARY_TESTS') !== '1'
            || getenv('CLOUDINARY_CLOUD_NAME') === false
            || getenv('CLOUDINARY_API_KEY') === false
            || getenv('CLOUDINARY_API_SECRET') === false) {
            $this->markTestSkipped('Cloudinary integration tests require explicit test credentials.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->keys !== []) {
            $disk = Storage::disk('cloudinary');
            foreach ($this->keys as $key) {
                try {
                    if ($disk->exists($key)) {
                        $disk->delete($key);
                    }
                } catch (\Throwable) {
                    // Preserve the original test failure; cleanup is operationally retryable.
                }
            }
        }

        parent::tearDown();
    }

    public function test_image_and_video_filesystem_operations_are_typed_and_non_overwriting(): void
    {
        $disk = Storage::disk('cloudinary');
        $image = 'images/'.Str::ulid();
        $video = 'videos/'.Str::ulid();
        $this->keys = [$image, $video];

        $this->assertTrue($disk->put($image, $this->imageBytes()));
        $this->assertTrue($disk->put($video, $this->videoBytes()));
        $this->assertTrue($disk->exists($image));
        $this->assertTrue($disk->exists($video));
        $this->assertStringStartsWith('https://', $disk->url($image));
        $this->assertStringStartsWith('https://', $disk->url($video));
        $this->assertNotSame('', $disk->get($image));
        $videoStream = $disk->readStream($video);
        $this->assertIsResource($videoStream);
        fclose($videoStream);

        $originalHash = hash('sha256', $this->imageBytes());
        $replacement = $this->replacementImageBytes();
        $this->assertNotSame($this->imageBytes(), $replacement);
        $this->assertNotSame($originalHash, hash('sha256', $replacement));
        $this->assertNotFalse(getimagesizefromstring($replacement));
        $this->assertTrue($disk->put($image, $replacement));
        $this->assertSame($originalHash, hash('sha256', (string) $disk->get($image)));
    }

    private function imageBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=', true) ?: '';
    }

    private function replacementImageBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgYPgPAAEDAQAIicLsAAAAAElFTkSuQmCC', true) ?: '';
    }

    private function videoBytes(): string
    {
        $path = resource_path('cloudinary/probe.mp4');
        $this->assertFileExists($path);
        $this->assertIsReadable($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertNotSame('', $contents);

        return $contents;
    }
}
