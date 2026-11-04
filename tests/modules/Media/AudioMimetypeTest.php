<?php

declare(strict_types=1);

namespace Tests\Modules\Media;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\Media\Entities\Audio;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class AudioMimetypeTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function mimetypeProvider(): iterable
    {
        yield 'M4A detected as MP4 audio' => ['episode.m4a', 'audio/mp4', 'audio/x-m4a'];
        yield 'M4A audio alias' => ['episode.m4a', 'audio/m4a', 'audio/x-m4a'];
        yield 'uppercase M4A extension' => ['episode.M4A', 'audio/mp4', 'audio/x-m4a'];
        yield 'already canonical M4A' => ['episode.m4a', 'audio/x-m4a', 'audio/x-m4a'];
        yield 'MP3' => ['episode.mp3', 'audio/mpeg', 'audio/mpeg'];
        yield 'MP4 audio extension' => ['episode.mp4', 'audio/mp4', 'audio/mp4'];
        yield 'M4A name with detected video' => ['episode.m4a', 'video/mp4', 'video/mp4'];
        yield 'unknown M4A contents' => ['episode.m4a', 'application/octet-stream', 'application/octet-stream'];
    }

    #[DataProvider('mimetypeProvider')]
    public function testMimetypeNormalizesOnlyKnownM4aAudioAliases(
        string $key,
        string $storedType,
        string $expectedType,
    ): void {
        $audio = new Audio([
            'file_key'      => $key,
            'file_mimetype' => $storedType,
        ]);

        $audio->syncOriginal();

        $this->assertSame($expectedType, $audio->file_mimetype);
        $this->assertSame($storedType, $audio->toRawArray()['file_mimetype']);
        $this->assertFalse($audio->hasChanged());
    }
}
