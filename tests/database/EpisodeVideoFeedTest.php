<?php

declare(strict_types=1);

use App\Database\Seeds\FakeSinglePodcastApiSeeder;
use App\Entities\Podcast;
use App\Models\PodcastModel;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;

/**
 * @internal
 */
final class EpisodeVideoFeedTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    private const SHA = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const HLS_SHA = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

    private const IDENTIFIER = 'naddr1qqxnzd3cxqmrzv3exgmr2wfeqgsxu35yyt0mwjjh8pcz4zprhxegz69t4wr9t74vk6zne58wzh0waycrqsqqqa28pccpzu';

    private const OTHER_IDENTIFIER = 'nevent1qqsrhuxx8l9ex335q7he0f09aej04zpazpl0ne2cgukyawd24mayt8gpp4mhxue69uhkummn9ekx7mqzyqalp';

    private const NS = 'https://podcastindex.org/namespace/1.0';

    private const COMMAND = 'episodes:video --podcast Handle --slug episode-slug';

    /**
     * @var bool
     */
    protected $migrate = true;

    /**
     * @var bool
     */
    protected $migrateOnce = false;

    /**
     * @var string|null
     */
    protected $namespace;

    /**
     * @var class-string<Seeder>|list<class-string<Seeder>>
     */
    protected $seed = FakeSinglePodcastApiSeeder::class;

    /**
     * @var string
     */
    protected $basePath = 'app/Database';

    protected function setUp(): void
    {
        parent::setUp();

        // The settings service keeps values in memory; the database is rebuilt for each test.
        Services::resetSingle('settings');
        foreach (['podcasts', 'episodes'] as $table) {
            $this->db->table($table)
                ->where('id', 1)
                ->update([
                    'published_at' => '2023-05-31 11:16:05',
                ]);
        }

        cache()
            ->clean();
        helper('rss');
    }

    public function testFeedWithoutVideoHasNoAlternateEnclosure(): void
    {
        $this->assertSame(0, $this->alternates()->length);

        config('EpisodeVideo')
            ->setIdentifier(1, self::IDENTIFIER);
        $this->assertSame(0, $this->alternates()->length);
    }

    public function testFeedAdvertisesBoundMp4AfterTheAudioEnclosure(): void
    {
        config('EpisodeVideo')->setEnclosure(1, self::IDENTIFIER, $this->mp4());

        [$xpath, $alternates] = $this->query();
        $this->assertSame(1, $alternates->length);
        $this->assertSame('enclosure', $xpath->query('//item/*[2]')->item(0)?->nodeName);
        $this->assertSame('audio/mpeg', $xpath->evaluate('string(//item/enclosure/@type)'));

        $alternate = $alternates->item(0);
        $this->assertInstanceOf(DOMElement::class, $alternate);
        $this->assertSame(
            'alternateEnclosure',
            $xpath->query('//item/enclosure/following-sibling::*[1]')
                ->item(0)?->localName
        );
        $this->assertSame('987654321', $alternate->getAttribute('length'));
        $this->assertSame('720', $alternate->getAttribute('height'));
        $uris = array_map(
            static fn (DOMElement $source): string => $source->getAttribute('uri'),
            iterator_to_array($xpath->query('podcast:source', $alternate)),
        );
        $this->assertSame([
            'https://blossom.example/' . self::SHA . '.mp4',
            'https://mirror.example/' . self::SHA . '.mp4',
        ], $uris);
        $this->assertSame(
            'sha256-47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=',
            $xpath->evaluate('string(podcast:integrity/@value)', $alternate),
        );
    }

    public function testSameIdentifierKeepsAndOtherIdentifierDropsTheMp4(): void
    {
        $video = config('EpisodeVideo');
        $video->setEnclosure(1, self::IDENTIFIER, $this->mp4());

        $video->setIdentifier(1, self::IDENTIFIER);
        $this->assertNotNull($video->getEnclosure(1));
        $this->assertSame(1, $this->alternates()->length);

        $video->setIdentifier(1, self::OTHER_IDENTIFIER);
        $this->assertNull($video->getEnclosure(1));
        $this->assertNull(service('settings')->get('EpisodeVideo.enclosure', 'episode:1'));
        $this->assertSame(0, $this->alternates()->length);

        // Restoring the old identifier does not resurrect the dropped MP4.
        $video->setIdentifier(1, self::IDENTIFIER);
        $this->assertSame(0, $this->alternates()->length);
    }

    public function testFeedAdvertisesHlsThenMp4AfterTheAudioEnclosure(): void
    {
        config('EpisodeVideo')->setEnclosure(1, self::IDENTIFIER, [
            'hls' => $this->hls(),
            'mp4' => $this->mp4(),
        ]);

        [$xpath, $alternates] = $this->query();
        $this->assertSame(2, $alternates->length);
        $this->assertSame(
            ['alternateEnclosure', 'alternateEnclosure'],
            array_map(
                static fn (DOMNode $node): string => (string) $node->localName,
                iterator_to_array($xpath->query('//item/enclosure/following-sibling::*[position() <= 2]')),
            ),
        );
        $this->assertSame(['application/x-mpegURL', 'video/mp4'], $this->types());

        $hls = $alternates->item(0);
        $this->assertInstanceOf(DOMElement::class, $hls);
        $this->assertSame('639', $hls->getAttribute('length'));
        $this->assertSame('720', $hls->getAttribute('height'));
        $this->assertSame('av01.0.05M.08,mp4a.40.2', $hls->getAttribute('codecs'));
        $this->assertSame('Video (HLS)', $hls->getAttribute('title'));
        $this->assertSame(
            'https://blossom.example/' . self::HLS_SHA . '.m3u8',
            $xpath->evaluate('string(podcast:source/@uri)', $hls),
        );
        $this->assertSame(
            'sha256-ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0=',
            $xpath->evaluate('string(podcast:integrity/@value)', $hls),
        );
    }

    public function testIdentifierChangeDropsHlsAndMp4(): void
    {
        $video = config('EpisodeVideo');
        $video->setEnclosure(1, self::IDENTIFIER, [
            'hls' => $this->hls(),
            'mp4' => $this->mp4(),
        ]);

        $video->setIdentifier(1, self::IDENTIFIER);
        $this->assertSame(['application/x-mpegURL', 'video/mp4'], $this->types());

        $video->setIdentifier(1, self::OTHER_IDENTIFIER);
        $this->assertNull($video->getEnclosure(1));
        $this->assertNull(service('settings')->get('EpisodeVideo.enclosure', 'episode:1'));
        $this->assertSame([], $this->types());

        $video->setIdentifier(1, self::IDENTIFIER);
        $this->assertSame([], $this->types());
    }

    public function testOriginalMp4OnlyRecordIsStillRead(): void
    {
        $settings = service('settings');
        $settings->set('EpisodeVideo.identifier', self::IDENTIFIER, 'episode:1');
        // The shape stored before HLS support: flat MP4 fields next to the identifier.
        $settings->set('EpisodeVideo.enclosure', json_encode([
            'identifier' => self::IDENTIFIER,
            'sources'    => ['https://blossom.example/' . self::SHA . '.mp4'],
            'sha256'     => self::SHA,
            'length'     => 987654321,
            'type'       => 'video/mp4',
            'height'     => 720,
            'width'      => 1280,
            'bitrate'    => null,
            'codecs'     => null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'episode:1');

        $record = config('EpisodeVideo')
            ->getEnclosure(1);
        $this->assertNull($record['hls'] ?? null);
        $this->assertSame(987654321, $record['mp4']['length'] ?? null);
        $this->assertSame(['video/mp4'], $this->types());

        // An invalid stored record is ignored, not rendered.
        $settings->set('EpisodeVideo.enclosure', json_encode([
            'identifier' => self::IDENTIFIER,
            'hls'        => null,
            'mp4'        => null,
        ], JSON_THROW_ON_ERROR), 'episode:1');
        $this->assertNull(config('EpisodeVideo')->getEnclosure(1));
        $this->assertSame([], $this->types());
    }

    public function testCommandReplacesTheEnclosureSet(): void
    {
        $base = self::COMMAND . ' --identifier ' . self::IDENTIFIER . ' ';
        command($base . $this->hlsOptions() . ' --hls-type application/vnd.apple.mpegurl ' . $this->mp4Options());
        $this->assertSame(['application/x-mpegURL', 'video/mp4'], $this->types());

        $this->resetStreamFilterBuffer();
        command(self::COMMAND . ' --show');
        $shown = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('"type": "application/x-mpegURL"', $shown);
        $this->assertStringContainsString('"sha256": "' . self::HLS_SHA . '"', $shown);
        $this->assertStringContainsString('"sha256": "' . self::SHA . '"', $shown);

        // Only MP4 options: the previous HLS stream is removed, not merged.
        command($base . $this->mp4Options());
        $this->assertSame(['video/mp4'], $this->types());

        // Only HLS options: the previous MP4 is removed.
        command($base . $this->hlsOptions());
        $this->assertSame(['application/x-mpegURL'], $this->types());

        // The identifier alone keeps the set saved for it.
        command($base);
        $this->assertSame(['application/x-mpegURL'], $this->types());

        command(self::COMMAND . ' --clear');
        $this->assertNull(service('settings')->get('EpisodeVideo.enclosure', 'episode:1'));
        $this->assertSame([], $this->types());
    }

    public function testCommandRejectsInvalidHlsWithoutWriting(): void
    {
        $base = self::COMMAND . ' --identifier ' . self::IDENTIFIER . ' ';
        $hls = $this->hlsOptions();
        foreach ([
            str_replace('https://blossom', 'http://blossom', $hls),
            str_replace('--hls-size 639', '', $hls),
            str_replace('--hls-sha256 ' . self::HLS_SHA, '', $hls),
            '--hls-sha256 ' . self::HLS_SHA . ' --hls-size 639',
            $hls . ' --hls-type video/mp4',
            $hls . ' --hls-type application/x-mpegurl',
            str_replace('--hls-height 720', '--hls-height 9000', $hls),
            $hls . ' --hls-default 1',
            // A valid HLS stream does not save an invalid MP4.
            $hls . ' ' . str_replace('--mp4-size 987654321', '--mp4-size 0', $this->mp4Options()),
        ] as $options) {
            command($base . $options);
            $this->assertNull(service('settings')->get('EpisodeVideo.identifier', 'episode:1'), $options);
            $this->assertNull(service('settings')->get('EpisodeVideo.enclosure', 'episode:1'), $options);
        }

        // HLS options require an explicit identifier.
        command(self::COMMAND . ' ' . $hls);
        $this->assertNull(service('settings')->get('EpisodeVideo.enclosure', 'episode:1'));
    }

    public function testRecordBoundToAnotherIdentifierIsNeverEmitted(): void
    {
        config('EpisodeVideo')->setEnclosure(1, self::IDENTIFIER, $this->mp4());
        // Simulates an identifier changed without going through setIdentifier().
        service('settings')
            ->set('EpisodeVideo.identifier', self::OTHER_IDENTIFIER, 'episode:1');

        $this->assertNull(config('EpisodeVideo')->getEnclosure(1));
        $this->assertSame(0, $this->alternates()->length);
    }

    public function testCommandRejectsInvalidInputWithoutWriting(): void
    {
        $valid = $this->mp4Options();
        foreach ([
            str_replace('https://blossom', 'http://blossom', $valid),
            str_replace(self::SHA . '.mp4,', 'video.mp4,', $valid),
            str_replace('--mp4-sha256 ' . self::SHA, '--mp4-sha256 ' . strtoupper(self::SHA), $valid),
            $valid . ' --mp4-type video/webm',
            str_replace('--mp4-height 720', '--mp4-height 9000', $valid),
            str_replace('--mp4-size 987654321', '--mp4-size 0', $valid),
            $valid . ' --mp4-bitrat 5',
        ] as $options) {
            command(self::COMMAND . ' --identifier ' . self::IDENTIFIER . ' ' . $options);
            $this->assertNull(service('settings')->get('EpisodeVideo.identifier', 'episode:1'), $options);
            $this->assertNull(service('settings')->get('EpisodeVideo.enclosure', 'episode:1'), $options);
        }

        // MP4 options require an explicit identifier.
        command(self::COMMAND . ' ' . $valid);
        $this->assertNull(service('settings')->get('EpisodeVideo.enclosure', 'episode:1'));
    }

    public function testCommandSetsShowsAndClears(): void
    {
        command(self::COMMAND . ' --identifier ' . self::IDENTIFIER . ' ' . $this->mp4Options());
        $this->assertSame(self::IDENTIFIER, config('EpisodeVideo')->getEnclosure(1)['identifier'] ?? null);
        $this->assertSame(1, $this->alternates()->length);

        $this->resetStreamFilterBuffer();
        command(self::COMMAND . ' --show');
        $this->assertStringContainsString('"sha256": "' . self::SHA . '"', $this->getStreamFilterBuffer());

        command(self::COMMAND . ' --identifier ' . self::OTHER_IDENTIFIER);
        $this->assertSame(self::OTHER_IDENTIFIER, config('EpisodeVideo')->getIdentifier(1));
        $this->assertSame(0, $this->alternates()->length);

        command(self::COMMAND . ' --identifier ' . self::OTHER_IDENTIFIER . ' ' . $this->mp4Options());
        $this->assertSame(1, $this->alternates()->length);
        command(self::COMMAND . ' --clear');
        $this->assertNull(config('EpisodeVideo')->getIdentifier(1));
        $this->assertNull(service('settings')->get('EpisodeVideo.enclosure', 'episode:1'));
        $this->assertSame(0, $this->alternates()->length);
    }

    /**
     * @return array{DOMXPath, DOMNodeList<DOMNode>}
     */
    private function query(): array
    {
        cache()
            ->clean();
        $podcast = (new PodcastModel())->getPodcastById(1);
        $this->assertInstanceOf(Podcast::class, $podcast);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML(get_rss_feed($podcast)));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('podcast', self::NS);
        $this->assertSame(1, $xpath->query('//item')->length);

        return [$xpath, $xpath->query('//item/podcast:alternateEnclosure')];
    }

    /**
     * @return DOMNodeList<DOMNode>
     */
    private function alternates(): DOMNodeList
    {
        return $this->query()[1];
    }

    /**
     * @return list<string>
     */
    private function types(): array
    {
        return array_map(
            static fn (DOMNode $node): string => $node instanceof DOMElement ? $node->getAttribute('type') : '',
            iterator_to_array($this->alternates()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hls(): array
    {
        return [
            'sources' => ['https://blossom.example/' . self::HLS_SHA . '.m3u8'],
            'sha256'  => self::HLS_SHA,
            'length'  => 639,
            'type'    => 'application/x-mpegURL',
            'height'  => 720,
            'codecs'  => 'av01.0.05M.08,mp4a.40.2',
        ];
    }

    private function hlsOptions(): string
    {
        return '--hls-url https://blossom.example/' . self::HLS_SHA . '.m3u8 --hls-sha256 ' . self::HLS_SHA
            . ' --hls-size 639 --hls-height 720 --hls-codecs av01.0.05M.08,mp4a.40.2';
    }

    /**
     * @return array<string, mixed>
     */
    private function mp4(): array
    {
        return [
            'sources' => [
                'https://blossom.example/' . self::SHA . '.mp4',
                'https://mirror.example/' . self::SHA . '.mp4',
            ],
            'sha256' => self::SHA,
            'length' => 987654321,
            'type'   => 'video/mp4',
            'height' => 720,
            'width'  => 1280,
        ];
    }

    private function mp4Options(): string
    {
        return '--mp4-url https://blossom.example/' . self::SHA . '.mp4,https://mirror.example/' . self::SHA . '.mp4'
            . ' --mp4-sha256 ' . self::SHA . ' --mp4-size 987654321 --mp4-height 720 --mp4-width 1280'
            . ' --mp4-bitrate 1500000 --mp4-codecs avc1.64001f,mp4a.40.2';
    }
}
