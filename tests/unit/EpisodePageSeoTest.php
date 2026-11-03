<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\FakeSinglePodcastApiSeeder;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Seeder;
use CodeIgniter\HTTP\Header;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Settings\Config\Settings as SettingsConfig;
use CodeIgniter\Settings\Settings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniterVite\Vite;
use DOMDocument;
use DOMXPath;

/**
 * @internal
 */
final class EpisodePageSeoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait {
        setupRequest as private setupFeatureRequest;
    }

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

        $this->db->table('podcasts')
            ->where('id', 1)
            ->update([
                'published_at'  => '2022-06-13 08:00:00',
                'language_code' => 'en',
            ]);
        $this->db->table('episodes')
            ->where('id', 1)
            ->update([
                'published_at' => '2023-06-01 00:00:00',
            ]);

        $settingsConfig = new SettingsConfig();
        $settingsConfig->handlers = ['array'];
        Services::injectMock('settings', new Settings($settingsConfig));

        $vite = $this->createMock(Vite::class);
        $vite->method('asset')
            ->willReturn('');
        Services::injectMock('vite', $vite);

        $this->withHeaders([
            'Accept' => new Header('Accept', 'text/html'),
        ]);
        $this->withSession([
            'denyListIp' => true,
        ]);
        service('superglobals')
            ->setServer('REMOTE_ADDR', '127.0.0.1');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('vite');
        parent::tearDown();
    }

    public function testEpisodeUsesPodcastLanguageAndKeepsIndexableCanonical(): void
    {
        $result = $this->get('/@Handle/episodes/episode-slug');
        $result->assertStatus(200);
        $this->assertSame('fr', service('request')->getLocale());

        $xpath = $this->getXPath($result->getBody());

        $this->assertSame('en', $xpath->evaluate('string(/html/@lang)'));
        $this->assertSame('Episode title • Title', $xpath->evaluate('string(//title)'));
        $this->assertSame(
            'https://example.com/@Handle/episodes/episode-slug',
            $xpath->evaluate('string(//link[@rel="canonical"]/@href)')
        );
        $this->assertSame(0, $xpath->query('//meta[@name="robots" and contains(@content,"noindex")]')->length);
    }

    public function testEmbedHasIndexingControlsOnFreshAndCachedResponses(): void
    {
        foreach ([1, 2] as $requestNumber) {
            $result = $this->get('/@Handle/episodes/episode-slug/embed');
            $result->assertStatus(200);
            $result->assertHeader('X-Robots-Tag', 'noindex, indexifembedded');
            $xpath = $this->getXPath($result->getBody());

            $this->assertSame('en', $xpath->evaluate('string(/html/@lang)'), 'Request ' . $requestNumber);
            $this->assertSame(
                'noindex, indexifembedded',
                $xpath->evaluate('string(//meta[@name="robots"]/@content)')
            );
        }
    }

    protected function setupRequest(string $method, ?string $path = null): IncomingRequest
    {
        $request = $this->setupFeatureRequest($method, $path);
        $request->setLocale('fr');

        return $request;
    }

    private function getXPath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
