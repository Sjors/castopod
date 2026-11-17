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

    /**
     * sha256 of "abc", for the HLS master playlist.
     */
    private const HLS_SHA = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

    private const HLS_SRI = 'sha256-ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0=';

    private const IDENTIFIER = 'naddr1qqxnzd3cxqmrzv3exgmr2wfeqgsxu35yyt0mwjjh8pcz4zprhxegz69t4wr9t74vk6zne58wzh0waycrqsqqqa28pccpzu';

    private const NS = 'https://podcastindex.org/namespace/1.0';

    public function testNormalizeAcceptsCompleteRecord(): void
    {
        $record = EpisodeVideo::normalizeEnclosure(self::input());

        $this->assertSame([
            'identifier' => self::IDENTIFIER,
            'hls'        => null,
            'mp4'        => [
                'sources' => [
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
            ],
        ], $record);

        // The original flat MP4-only shape and the nested shape normalize to the same record.
        $this->assertSame($record, EpisodeVideo::normalizeEnclosure([
            'identifier' => self::IDENTIFIER,
            'mp4'        => self::mp4(),
        ]));
        $this->assertSame($record, EpisodeVideo::normalizeEnclosure([
            'identifier' => self::IDENTIFIER,
            'hls'        => null,
            'mp4'        => self::mp4(),
        ]));
    }

    public function testNormalizeAcceptsHlsAndMp4(): void
    {
        $record = EpisodeVideo::normalizeEnclosure([
            'identifier' => self::IDENTIFIER,
            'hls'        => self::hls(),
            'mp4'        => self::mp4(),
        ]);

        $this->assertSame([
            'sources' => ['https://blossom.example/' . self::HLS_SHA . '.m3u8'],
            'sha256'  => self::HLS_SHA,
            'length'  => 639,
            'type'    => 'application/x-mpegURL',
            'height'  => 720,
            'width'   => 1280,
            'bitrate' => null,
            'codecs'  => 'av01.0.05M.08,mp4a.40.2',
        ], $record['hls']);
        $this->assertSame(self::SHA, $record['mp4']['sha256'] ?? null);
    }

    public function testNormalizeAcceptsHlsOnly(): void
    {
        $record = EpisodeVideo::normalizeEnclosure([
            'identifier' => self::IDENTIFIER,
            'hls'        => self::hls(),
        ]);

        $this->assertNull($record['mp4']);
        $this->assertSame(self::HLS_SHA, $record['hls']['sha256'] ?? null);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function hlsTypeProvider(): array
    {
        return [
            'default'   => [null, 'application/x-mpegURL'],
            'x-mpegURL' => ['application/x-mpegURL', 'application/x-mpegURL'],
            'vnd.apple' => ['application/vnd.apple.mpegurl', 'application/x-mpegURL'],
        ];
    }

    #[DataProvider('hlsTypeProvider')]
    public function testHlsTypeIsNormalized(mixed $type, string $expected): void
    {
        $hls = self::hls();
        if ($type === null) {
            unset($hls['type']);
        } else {
            $hls['type'] = $type;
        }

        $this->assertSame($expected, EpisodeVideo::normalizeMedia($hls, true)['type']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidHlsProvider(): array
    {
        $sha = self::HLS_SHA;
        $url = 'https://blossom.example/' . $sha . '.m3u8';

        return [
            'http url' => [[
                'sources' => ['http://blossom.example/' . $sha . '.m3u8'],
            ]],
            'credentials' => [[
                'sources' => ['https://user:pw@blossom.example/' . $sha . '.m3u8'],
            ]],
            'query' => [[
                'sources' => [$url . '?x=1'],
            ]],
            'fragment' => [[
                'sources' => [$url . '#x'],
            ]],
            'sha not in path' => [[
                'sources' => ['https://blossom.example/master.m3u8'],
            ]],
            'too many sources' => [[
                'sources' => array_map(static fn (int $i): string => "https://m{$i}.example/{$sha}.m3u8", range(1, 6)),
            ]],
            'duplicate' => [[
                'sources' => [$url, $url],
            ]],
            'uppercase sha' => [[
                'sha256' => strtoupper($sha),
            ]],
            'mp4 mime' => [[
                'type' => 'video/mp4',
            ]],
            'mime case' => [[
                'type' => 'application/x-mpegurl',
            ]],
            'audio mpegurl' => [[
                'type' => 'audio/mpegurl',
            ]],
            'missing size' => [[
                'length' => null,
            ]],
            'zero size' => [[
                'length' => 0,
            ]],
            'oversize height' => [[
                'height' => 8193,
            ]],
            'oversize width' => [[
                'width' => 9000,
            ]],
            'zero bitrate' => [[
                'bitrate' => '0',
            ]],
            'bad codecs' => [[
                'codecs' => 'av01"><x',
            ]],
            'unknown field' => [[
                'default' => 'true',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidHlsProvider')]
    public function testNormalizeRejectsHls(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        EpisodeVideo::normalizeEnclosure([
            'identifier' => self::IDENTIFIER,
            'hls'        => array_merge(self::hls(), $override),
            'mp4'        => self::mp4(),
        ]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidRecordProvider(): array
    {
        return [
            'neither'   => [[]],
            'both null' => [[
                'hls' => null,
                'mp4' => null,
            ]],
            'hls not an object' => [[
                'hls' => 'https://blossom.example/x.m3u8',
                'mp4' => self::mp4(),
            ]],
            'unknown top-level key' => [[
                'mp4'   => self::mp4(),
                'video' => self::mp4(),
            ]],
            'mixed flat and nested' => [[
                'mp4'    => self::mp4(),
                'sha256' => self::SHA,
            ]],
            'same sha for hls and mp4' => [[
                'hls' => [
                    'sources' => ['https://blossom.example/' . self::SHA . '.m3u8'],
                    'sha256'  => self::SHA,
                    'length'  => 639,
                ],
                'mp4' => self::mp4(),
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    #[DataProvider('invalidRecordProvider')]
    public function testNormalizeRejectsRecord(array $record): void
    {
        $this->expectException(InvalidArgumentException::class);
        EpisodeVideo::normalizeEnclosure([
            'identifier' => self::IDENTIFIER,
        ] + $record);
    }

    public function testOptionalFieldsMayBeOmitted(): void
    {
        $record = EpisodeVideo::normalizeEnclosure([
            'identifier' => self::IDENTIFIER,
            'sources'    => ['https://blossom.example/' . self::SHA . '.mp4'],
            'sha256'     => self::SHA,
            'length'     => 10,
        ]);

        $this->assertNull($record['hls']);
        $this->assertSame('video/mp4', $record['mp4']['type'] ?? null);
        $this->assertNull($record['mp4']['height'] ?? null);
        $this->assertNull($record['mp4']['bitrate'] ?? null);
        $this->assertNull($record['mp4']['codecs'] ?? null);
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
        EpisodeVideo::normalizeEnclosure(array_merge(self::input(), $override));
    }

    public function testRenderAlternateEnclosure(): void
    {
        $rss = new SimpleRSSElement(
            "<?xml version='1.0' encoding='utf-8'?><rss version='2.0' xmlns:podcast='" . self::NS . "'><channel/></rss>",
        );
        $item = $rss->channel->addChild('item');
        $item->addChild('enclosure')
            ->addAttribute('url', 'https://castopod.example/audio.mp3');

        EpisodeVideo::renderAlternateEnclosures($item, EpisodeVideo::normalizeEnclosure(self::input()), self::NS);

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
        EpisodeVideo::renderAlternateEnclosures($rss->item, $record, self::NS);

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

    public function testRenderHlsBeforeMp4(): void
    {
        $xpath = $this->render([
            'identifier' => self::IDENTIFIER,
            'mp4'        => self::mp4(),
            'hls'        => self::hls(),
        ]);

        $this->assertSame(
            ['enclosure', 'alternateEnclosure', 'alternateEnclosure'],
            array_map(static fn (DOMNode $node): string => (string) $node->localName, iterator_to_array(
                $xpath->query('//item/*')
            )),
        );
        $alternates = $xpath->query('//item/podcast:alternateEnclosure');
        $hls = $alternates->item(0);
        $mp4 = $alternates->item(1);
        $this->assertInstanceOf(DOMElement::class, $hls);
        $this->assertInstanceOf(DOMElement::class, $mp4);

        $this->assertSame([
            'type'   => 'application/x-mpegURL',
            'length' => '639',
            'height' => '720',
            'codecs' => 'av01.0.05M.08,mp4a.40.2',
            'title'  => 'Video (HLS)',
        ], $this->attributes($hls));
        $this->assertSame(
            'https://blossom.example/' . self::HLS_SHA . '.m3u8',
            $xpath->evaluate('string(podcast:source/@uri)', $hls)
        );
        $this->assertSame('sri', $xpath->evaluate('string(podcast:integrity/@type)', $hls));
        $this->assertSame(self::HLS_SRI, $xpath->evaluate('string(podcast:integrity/@value)', $hls));

        $this->assertSame('video/mp4', $mp4->getAttribute('type'));
        $this->assertSame('Video', $mp4->getAttribute('title'));
        $this->assertSame(self::SRI, $xpath->evaluate('string(podcast:integrity/@value)', $mp4));
    }

    public function testRenderHlsOnly(): void
    {
        $xpath = $this->render([
            'identifier' => self::IDENTIFIER,
            'hls'        => self::hls(),
        ]);

        $alternates = $xpath->query('//item/podcast:alternateEnclosure');
        $this->assertSame(1, $alternates->length);
        $hls = $alternates->item(0);
        $this->assertInstanceOf(DOMElement::class, $hls);
        $this->assertSame('application/x-mpegURL', $hls->getAttribute('type'));
        $this->assertFalse($hls->hasAttribute('default'));
        $this->assertFalse($hls->hasAttribute('width'));
        $this->assertFalse($hls->hasAttribute('bitrate'));
        $this->assertSame(self::HLS_SRI, $xpath->evaluate('string(podcast:integrity/@value)', $hls));
    }

    public function testRenderMp4OnlyFromTheOriginalShape(): void
    {
        $xpath = $this->render(self::input());

        $alternates = $xpath->query('//item/podcast:alternateEnclosure');
        $this->assertSame(1, $alternates->length);
        $this->assertSame('video/mp4', $xpath->evaluate('string(//item/podcast:alternateEnclosure/@type)'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function render(array $input): DOMXPath
    {
        $rss = new SimpleRSSElement("<rss xmlns:podcast='" . self::NS . "'><channel><item/></channel></rss>");
        $item = $rss->channel->item;
        $item->addChild('enclosure')
            ->addAttribute('url', 'https://castopod.example/audio.mp3');
        EpisodeVideo::renderAlternateEnclosures($item, EpisodeVideo::normalizeEnclosure($input), self::NS);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML((string) $rss->asXML()));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('podcast', self::NS);

        return $xpath;
    }

    /**
     * @return array<string, string>
     */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function hls(): array
    {
        return [
            'sources' => ['https://blossom.example/' . self::HLS_SHA . '.m3u8'],
            'sha256'  => self::HLS_SHA,
            'length'  => '639',
            'type'    => 'application/vnd.apple.mpegurl',
            'height'  => '720',
            'width'   => '1280',
            'codecs'  => 'av01.0.05M.08,mp4a.40.2',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mp4(): array
    {
        return array_diff_key(self::input(), [
            'identifier' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function input(): array
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
