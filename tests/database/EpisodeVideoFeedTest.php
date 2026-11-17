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
