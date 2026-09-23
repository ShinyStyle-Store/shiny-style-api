<?php

namespace Tests\Unit\Services;

use App\Services\ExternalVideoUrlNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExternalVideoUrlNormalizerTest extends TestCase
{
    #[DataProvider('validUrls')]
    public function test_it_normalizes_supported_video_urls(string $input, string $expected): void
    {
        self::assertSame($expected, (new ExternalVideoUrlNormalizer())->normalize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function validUrls(): iterable
    {
        yield 'youtube watch' => [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=20',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        yield 'youtube short link' => [
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        yield 'youtube shorts' => [
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        yield 'youtube nocookie embed' => [
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ#fragment',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        yield 'vimeo' => [
            'https://www.vimeo.com/123456789',
            'https://player.vimeo.com/video/123456789',
        ];
        yield 'vimeo player' => [
            'https://player.vimeo.com/video/123456789?autoplay=1',
            'https://player.vimeo.com/video/123456789',
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_it_rejects_unsafe_or_unsupported_urls(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ExternalVideoUrlNormalizer())->normalize($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUrls(): iterable
    {
        yield 'http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'arbitrary host' => ['https://youtube.com.attacker.example/embed/dQw4w9WgXcQ'];
        yield 'credentials' => ['https://user:pass@youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'missing id' => ['https://www.youtube.com/watch?v='];
        yield 'bad youtube id' => ['https://www.youtube.com/embed/not-valid'];
        yield 'vimeo non numeric' => ['https://vimeo.com/abc'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'relative' => ['/watch?v=dQw4w9WgXcQ'];
        yield 'iframe' => ['<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>'];
    }
}
