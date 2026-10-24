<?php

declare(strict_types=1);

namespace Tests\Modules\Media;

use CodeIgniter\Files\File;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Modules\Media\Config\Media;
use Modules\Media\Entities\Image;
use Modules\Media\FileManagers\FS;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class ImageTest extends CIUnitTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        helper(['filesystem', 'media']);
        $path = tempnam(sys_get_temp_dir(), 'castopod-artwork-');
        $this->assertNotFalse($path);
        unlink($path);
        mkdir($path);
        $this->directory = $path;

        /** @var Media $config */
        $config = config('Media');
        $config->storage = $this->directory;
        Services::injectMock('file_manager', new FS($config));
    }

    protected function tearDown(): void
    {
        delete_files($this->directory, true);
        rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function feedFormats(): iterable
    {
        yield 'PNG' => ['png', IMAGETYPE_PNG];
        yield 'JPEG' => ['jpeg', IMAGETYPE_JPEG];
        yield 'JPG' => ['jpg', IMAGETYPE_JPEG];
    }

    #[DataProvider('feedFormats')]
    public function testFeedArtworkHasMatchingEncodingAndNoAlpha(string $extension, int $type): void
    {
        $source = $this->createTransparentSource();
        $image = new Image([
            'file_key' => 'cover.' . $extension,
            'sizes'    => [
                'feed' => [
                    'width'  => 32,
                    'height' => 32,
                ],
            ],
        ]);
        $image->setFile(new File($source));
        $image->saveFile();

        $path = $this->directory . '/media/cover_feed.' . $extension;
        $info = getimagesize($path);
        $this->assertNotFalse($info);
        $this->assertSame([32, 32, $type], array_slice($info, 0, 3));
        $bytes = file_get_contents($path);
        $this->assertNotFalse($bytes);
        if ($type === IMAGETYPE_PNG) {
            // PNG IHDR color type 2 is truecolor RGB without an alpha channel.
            $this->assertSame(2, ord($bytes[25]));
        } else {
            $this->assertSame(3, $info['channels']);
        }

        $decoded = imagecreatefromstring($bytes);
        $this->assertNotFalse($decoded);
        $this->assertSame([72, 72], array_values(imageresolution($decoded)));
        $background = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
        $this->assertGreaterThan(245, $background['red']);
        $this->assertGreaterThan(245, $background['green']);
        $this->assertGreaterThan(245, $background['blue']);
        $foreground = imagecolorsforindex($decoded, imagecolorat($decoded, 16, 16));
        $this->assertGreaterThan(240, $foreground['blue']);
        $this->assertLessThan(15, $foreground['red']);
        imagedestroy($decoded);
    }

    public function testOtherImageSizesAndTheOriginalRetainTransparency(): void
    {
        $source = $this->createTransparentSource();
        $original = file_get_contents($source);
        $image = new Image([
            'file_key' => 'cover.png',
            'sizes'    => [
                'feed' => [
                    'width'  => 32,
                    'height' => 32,
                ],
                'thumbnail' => [
                    'width'  => 16,
                    'height' => 16,
                ],
            ],
        ]);
        $image->setFile(new File($source));
        $image->saveFile();

        $this->assertSame($original, file_get_contents($this->directory . '/media/cover.png'));
        $thumbnail = file_get_contents($this->directory . '/media/cover_thumbnail.png');
        $this->assertNotFalse($thumbnail);
        $this->assertSame(6, ord($thumbnail[25]));
        $decoded = imagecreatefromstring($thumbnail);
        $this->assertNotFalse($decoded);
        $background = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
        $this->assertSame(127, $background['alpha']);
        imagedestroy($decoded);
    }

    private function createTransparentSource(): string
    {
        $source = imagecreatetruecolor(64, 64);
        $this->assertNotFalse($source);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
        imagefilledrectangle($source, 10, 10, 50, 50, imagecolorallocatealpha($source, 0, 0, 255, 0));
        imageresolution($source, 96, 96);
        $path = $this->directory . '/input.png';
        imagepng($source, $path);
        imagedestroy($source);

        return $path;
    }
}
