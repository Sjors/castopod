<?php

declare(strict_types=1);

use App\Libraries\SimpleRSSElement;
use CodeIgniter\Test\CIUnitTestCase;
use Config\EpisodeVideo;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class EpisodeVideoTest extends CIUnitTestCase
{
    /**
     * sha256 of the empty string, so the SRI value is well known.
     */
    private const SHA = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const SRI = 'sha256-47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=';

    private const IDENTIFIER = 'naddr1qqxnzd3cxqmrzv3exgmr2wfeqgsxu35yyt0mwjjh8pcz4zprhxegz69t4wr9t74vk6zne58wzh0waycrqsqqqa28pccpzu';

    private const NS = 'https://podcastindex.org/namespace/1.0';

    public function testNormalizeAcceptsCompleteRecord(): void
    {
        $record = EpisodeVideo::normalizeEnclosure($this->input());

        $this->assertSame([
            'identifier' => self::IDENTIFIER,
            'sources'    => [
                'https://blossom.example/' . self::SHA . '.mp4',
                'https://mirror.example/blobs/' . self::SHA,
            ],
            'sha256'  => self::SHA,
            'length'  => 123456789,
            'type'    => 'video/mp4',
            'height'  => 720,
            'width'   => 1280,
            'bitrate' => 1500000,
            'codecs'  => 'avc1.64001f,mp4a.40.2',
        ], $record);
    }

    public function testOptionalFieldsMayBeOmitted(): void
    {
        $record = EpisodeVideo::normalizeEnclosure([
            'identifier' => self::IDENTIFIER,
            'sources'    => ['https://blossom.example/' . self::SHA . '.mp4'],
            'sha256'     => self::SHA,
            'length'     => 10,
        ]);

        $this->assertSame('video/mp4', $record['type']);
        $this->assertNull($record['height']);
        $this->assertNull($record['bitrate']);
        $this->assertNull($record['codecs']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidProvider(): array
    {
        $url = 'https://blossom.example/' . self::SHA . '.mp4';

        return [
            'http url' => [[
                'sources' => ['http://blossom.example/' . self::SHA . '.mp4'],
            ]],
            'scheme case' => [[
                'sources' => ['HTTPS://blossom.example/' . self::SHA . '.mp4'],
            ]],
            'credentials' => [[
                'sources' => ['https://user:pw@blossom.example/' . self::SHA . '.mp4'],
            ]],
            'fragment' => [[
                'sources' => [$url . '#t=1'],
            ]],
            'query' => [[
                'sources' => [$url . '?x=1'],
            ]],
            'whitespace' => [[
                'sources' => ['https://blossom.example/ ' . self::SHA . '.mp4'],
            ]],
            'sha not in path' => [[
                'sources' => ['https://blossom.example/video.mp4'],
            ]],
            'sha only in host' => [[
                'sources' => ['https://' . self::SHA . '.example/video.mp4'],
            ]],
            'other sha in path' => [[
                'sources' => ['https://blossom.example/' . str_repeat('a', 64) . '.mp4'],
            ]],
            'too long url' => [[
                'sources' => ['https://blossom.example/' . str_repeat('a/', 1100) . self::SHA],
            ]],
            'no sources' => [[
                'sources' => [],
            ]],
            'too many sources' => [[
                'sources' => array_map(static fn (int $i): string => "https://m{$i}.example/" . self::SHA, range(1, 6)),
            ]],
            'duplicate' => [[
                'sources' => [$url, $url],
            ]],
            'uppercase sha' => [[
                'sha256' => strtoupper(self::SHA),
            ]],
            'short sha' => [[
                'sha256' => substr(self::SHA, 1),
            ]],
            'bad mime' => [[
                'type' => 'video/webm',
            ]],
            'mime with params' => [[
                'type' => 'video/mp4; codecs="avc1"',
            ]],
            'zero size' => [[
                'length' => '0',
            ]],
            'negative size' => [[
                'length' => '-5',
            ]],
            'missing size' => [[
                'length' => null,
            ]],
            'non-numeric size' => [[
                'length' => '12abc',
            ]],
            'oversize height' => [[
                'height' => '8193',
            ]],
            'oversize width' => [[
                'width' => '10000',
            ]],
            'zero bitrate' => [[
                'bitrate' => '0',
            ]],
            'bad codecs' => [[
                'codecs' => 'avc1"><x',
            ]],
            'bad identifier' => [[
                'identifier' => 'npub1abc',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidProvider')]
    public function testNormalizeRejects(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        EpisodeVideo::normalizeEnclosure(array_merge($this->input(), $override));
    }

    public function testRenderAlternateEnclosure(): void
    {
        $rss = new SimpleRSSElement(
            "<?xml version='1.0' encoding='utf-8'?><rss version='2.0' xmlns:podcast='" . self::NS . "'><channel/></rss>",
        );
        $item = $rss->channel->addChild('item');
        $item->addChild('enclosure')
            ->addAttribute('url', 'https://castopod.example/audio.mp3');

        EpisodeVideo::renderAlternateEnclosure($item, EpisodeVideo::normalizeEnclosure($this->input()), self::NS);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML((string) $rss->asXML()));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('podcast', self::NS);

        $this->assertSame('enclosure', $xpath->query('//item/*[1]')->item(0)?->nodeName);
        $alternates = $xpath->query('//item/podcast:alternateEnclosure');
        $this->assertSame(1, $alternates->length);
        $alternate = $alternates->item(0);
        $this->assertInstanceOf(DOMElement::class, $alternate);
        $this->assertSame('video/mp4', $alternate->getAttribute('type'));
        $this->assertSame('123456789', $alternate->getAttribute('length'));
        $this->assertSame('720', $alternate->getAttribute('height'));
        $this->assertSame('1500000', $alternate->getAttribute('bitrate'));
        $this->assertSame('avc1.64001f,mp4a.40.2', $alternate->getAttribute('codecs'));
        $this->assertSame('Video', $alternate->getAttribute('title'));
        $this->assertFalse($alternate->hasAttribute('default'));
        $this->assertFalse($alternate->hasAttribute('width'));

        $sources = [];
        foreach ($xpath->query('podcast:source', $alternate) as $source) {
            $this->assertInstanceOf(DOMElement::class, $source);
            $sources[] = $source->getAttribute('uri');
        }

        $this->assertSame([
            'https://blossom.example/' . self::SHA . '.mp4',
            'https://mirror.example/blobs/' . self::SHA,
        ], $sources);

        $integrity = $xpath->query('podcast:integrity', $alternate)
            ->item(0);
        $this->assertInstanceOf(DOMElement::class, $integrity);
        $this->assertSame('sri', $integrity->getAttribute('type'));
        $this->assertSame(self::SRI, $integrity->getAttribute('value'));
    }

    public function testRenderOmitsAbsentOptionalAttributes(): void
    {
        $rss = new SimpleRSSElement("<rss xmlns:podcast='" . self::NS . "'><item/></rss>");
        $record = EpisodeVideo::normalizeEnclosure([
            'identifier' => self::IDENTIFIER,
            'sources'    => ['https://blossom.example/' . self::SHA],
            'sha256'     => self::SHA,
            'length'     => 10,
        ]);
        EpisodeVideo::renderAlternateEnclosure($rss->item, $record, self::NS);

        $attributes = [];
        foreach ($rss->item->children(self::NS)->alternateEnclosure->attributes() as $name => $value) {
            $attributes[$name] = (string) $value;
        }

        $this->assertSame([
            'type'   => 'video/mp4',
            'length' => '10',
            'title'  => 'Video',
        ], $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return [
            'identifier' => self::IDENTIFIER,
            'sources'    => [
                'https://blossom.example/' . self::SHA . '.mp4',
                'https://mirror.example/blobs/' . self::SHA,
            ],
            'sha256'  => self::SHA,
            'length'  => '123456789',
            'type'    => 'video/mp4',
            'height'  => '720',
            'width'   => '1280',
            'bitrate' => '1500000',
            'codecs'  => 'avc1.64001f,mp4a.40.2',
        ];
    }
}
