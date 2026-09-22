<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class MediaStorageCheck extends Command
{
    protected $signature = 'media:storage-check {--disk= : Filesystem disk to probe}';

    protected $description = 'Verify media storage upload, read, URL, existence, and deletion.';

    public function handle(): int
    {
        $disk = (string) ($this->option('disk') ?: config('media.disk'));
        $keys = [
            'images/'.Str::ulid(),
            'videos/'.Str::ulid(),
        ];
        $storage = null;
        $written = [];

        try {
            $storage = Storage::disk($disk);
            foreach ($keys as $index => $key) {
                $contents = $index === 0 ? $this->probeImage() : $this->probeVideo();
                if (! $storage->put($key, $contents, ['visibility' => 'public'])) {
                    $this->error('upload failed');

                    return self::FAILURE;
                }
                $written[] = $key;
                $this->line('upload ok');

                if (! $storage->exists($key)) {
                    $this->error('exists failed');

                    return self::FAILURE;
                }
                $this->line('exists ok');

                $url = $storage->url($key);
                if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                    $this->error('url failed');

                    return self::FAILURE;
                }
                $this->line('url ok');

                $stream = $storage->readStream($key);
                if (! is_resource($stream)) {
                    $this->error('read failed');

                    return self::FAILURE;
                }
                fclose($stream);
                $this->line('read ok');
            }

            foreach ($written as $key) {
                $storage->delete($key);
                if ($storage->exists($key)) {
                    $this->error('delete failed');

                    return self::FAILURE;
                }
                $this->line('delete ok');
                $written = array_values(array_diff($written, [$key]));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($this->safeFailureOutput('storage check failed', $exception));

            return self::FAILURE;
        } finally {
            if ($storage !== null) {
                foreach ($written as $key) {
                    try {
                        if ($storage->exists($key)) {
                            $storage->delete($key);
                        }
                    } catch (Throwable $exception) {
                        $this->warn($this->safeFailureOutput('cleanup failed', $exception));
                    }
                }
            }
        }
    }

    private function safeFailureOutput(string $prefix, Throwable $exception): string
    {
        $previous = $exception->getPrevious();
        $suffix = $previous === null ? '' : '; caused by: '.$previous::class;

        return $prefix.': '.$exception::class.$suffix;
    }

    private function probeImage(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=',
            true,
        ) ?: '';
    }

    private function probeVideo(): string
    {
        $contents = file_get_contents(resource_path('cloudinary/probe.mp4'));

        return is_string($contents)
            && strlen($contents) > 0
            && substr($contents, 4, 4) === 'ftyp'
            && str_contains($contents, 'mdat')
            && str_contains($contents, 'moov')
            ? $contents
            : '';
    }
}
