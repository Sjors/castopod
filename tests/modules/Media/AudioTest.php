<?php

declare(strict_types=1);

namespace Tests\Modules\Media;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Modules\Media\Entities\Audio;
use Modules\Media\FileManagers\FileManagerInterface;
use Modules\Media\FileManagers\FS;

/**
 * @internal
 */
final class AudioTest extends CIUnitTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'castopod-audio-');
        $this->assertNotFalse($path);
        $this->path = $path;

        $manager = $this->createMock(FS::class);
        $manager->method('getFileInput')
            ->willReturn($this->path);
        Services::injectMock('file_manager', $manager);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function testFileSizeUsesTheStoredFileInsteadOfStaleMetadata(): void
    {
        file_put_contents($this->path, 'audio-data');
        $audio = new Audio([
            'file_key'  => 'episode.mp3',
            'file_size' => 1,
        ]);

        $this->assertSame(10, $audio->file_size);
    }

    public function testFileSizeReflectsLaterTagWritesOnTheSameEntity(): void
    {
        file_put_contents($this->path, 'audio');
        $audio = new Audio([
            'file_key'  => 'episode.mp3',
            'file_size' => 5,
        ]);
        $this->assertSame(5, $audio->file_size);

        file_put_contents($this->path, 'audio-with-new-tags');

        $this->assertSame(19, $audio->file_size);
    }

    public function testEmptyFileDoesNotFallBackToStaleMetadata(): void
    {
        $audio = new Audio([
            'file_key'  => 'episode.mp3',
            'file_size' => 123,
        ]);

        $this->assertSame(0, $audio->file_size);
    }

    public function testMissingLocalFileRetainsTheStoredSize(): void
    {
        unlink($this->path);
        $audio = new Audio([
            'file_key'  => 'episode.mp3',
            'file_size' => 123,
        ]);

        $this->assertSame(123, $audio->file_size);
    }

    public function testOtherStorageBackendsRetainMetadataWithoutFetchingTheObject(): void
    {
        $manager = $this->createMock(FileManagerInterface::class);
        $manager->expects($this->never())
            ->method('getFileInput');
        Services::injectMock('file_manager', $manager);
        $audio = new Audio([
            'file_key'  => 'episode.mp3',
            'file_size' => 123,
        ]);

        $this->assertSame(123, $audio->file_size);
    }
}
