<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\FakeSinglePodcastApiSeeder;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Seeder;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use SimpleXMLElement;

class SitemapTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

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

        cache()
            ->deleteMatching('sitemap*');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        cache()
            ->deleteMatching('sitemap*');
    }

    public function testSitemapIsValidXml(): void
    {
        $result = $this->call('GET', 'sitemap.xml');
        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $urlset = new SimpleXMLElement((string) $result->response()->getBody());
        $this->assertSame('urlset', $urlset->getName());
        $this->assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $urlset->getNamespaces()['']);
    }

    public function testSitemapListsStaticRoutes(): void
    {
        $locs = $this->getSitemapLocs();

        $this->assertContains(url_to('home'), $locs);
        $this->assertContains(url_to('credits'), $locs);
        $this->assertContains(url_to('map'), $locs);
    }

    public function testSitemapListsPages(): void
    {
        $this->db->table('pages')
            ->insert([
                'title'            => 'Legal notice',
                'slug'             => 'legal-notice',
                'content_markdown' => 'content',
                'content_html'     => '<p>content</p>',
                'created_at'       => '2024-01-01 00:00:00',
                'updated_at'       => '2024-01-02 00:00:00',
            ]);

        $urls = $this->getSitemapUrls();

        $this->assertArrayHasKey(url_to('page', 'legal-notice'), $urls);
        $this->assertSame(
            Time::parse('2024-01-02 00:00:00')->format(DATE_W3C),
            $urls[url_to('page', 'legal-notice')],
        );
    }

    public function testSitemapIgnoresUnpublishedPodcast(): void
    {
        // the seeded podcast has no publication date
        $locs = $this->getSitemapLocs();

        $this->assertNotContains(url_to('podcast-activity', 'Handle'), $locs);
        $this->assertNotContains(url_to('episode', 'Handle', 'episode-slug'), $locs);
    }

    public function testSitemapListsPublishedPodcastAndEpisodes(): void
    {
        $this->publishPodcast();
        $this->db->table('episodes')
            ->where('id', 1)
            ->update([
                'published_at' => '2023-06-01 00:00:00',
            ]);

        $urls = $this->getSitemapUrls();

        $this->assertArrayHasKey(url_to('podcast-activity', 'Handle'), $urls);
        $this->assertArrayHasKey(url_to('podcast-episodes', 'Handle'), $urls);
        $this->assertArrayHasKey(url_to('podcast-about', 'Handle'), $urls);
        $this->assertArrayHasKey(url_to('episode', 'Handle', 'episode-slug'), $urls);
        $this->assertArrayNotHasKey(url_to('episode-transcript', 'Handle', 'episode-slug'), $urls);
        $this->assertSame(
            Time::parse(FakeSinglePodcastApiSeeder::episode()['updated_at'])->format(DATE_W3C),
            $urls[url_to('episode', 'Handle', 'episode-slug')],
        );
    }

    public function testSitemapListsPublishedEpisodeTranscript(): void
    {
        $this->publishPodcast();
        $this->attachTranscript();
        $this->db->table('episodes')
            ->where('id', 1)
            ->update([
                'published_at' => '2023-06-01 00:00:00',
            ]);

        $urls = $this->getSitemapUrls();
        $transcriptUrl = url_to('episode-transcript', 'Handle', 'episode-slug');

        $this->assertArrayHasKey($transcriptUrl, $urls);
        $this->assertSame(
            Time::parse(FakeSinglePodcastApiSeeder::episode()['updated_at'])->format(DATE_W3C),
            $urls[$transcriptUrl],
        );
    }

    public function testSitemapIgnoresScheduledEpisodes(): void
    {
        $this->publishPodcast();
        $this->attachTranscript();
        $this->db->table('episodes')
            ->where('id', 1)
            ->update([
                'published_at' => Time::now('UTC')->addDays(1)->format('Y-m-d H:i:s'),
            ]);

        $locs = $this->getSitemapLocs();

        $this->assertContains(url_to('podcast-activity', 'Handle'), $locs);
        $this->assertNotContains(url_to('episode', 'Handle', 'episode-slug'), $locs);
        $this->assertNotContains(url_to('episode-transcript', 'Handle', 'episode-slug'), $locs);
    }

    public function testSitemapCacheSeparatesOriginsWithoutDomainConfiguration(): void
    {
        $this->publishPodcast();
        $app = config('App');
        $originalBaseURL = $app->baseURL;
        try {
            $app->baseURL = 'https://first.example/';
            $first = $this->getSitemapLocs();
            $this->assertContains('https://first.example/@Handle', $first);
            $app->baseURL = 'https://second.example/';
            $second = $this->getSitemapLocs();
            $this->assertContains('https://second.example/@Handle', $second);
            $this->assertNotContains('https://first.example/@Handle', $second);
            $app->baseURL = 'https://first.example/';
            $this->assertSame($first, $this->getSitemapLocs());
        } finally {
            $app->baseURL = $originalBaseURL;
        }
    }

    public function testDomainSitemapsSelectOnlyLocalShowsWithoutMixingCachedOrigins(): void
    {
        $web = Factories::get('config', 'PodcastWeb');
        if ($web === null || ! property_exists($web, 'hubURL') || ! property_exists($web, 'domains')) {
            $this->markTestSkipped('Optional podcast-domains integration is not installed.');
        }

        $this->publishPodcast();
        $this->attachTranscript();
        $this->db->table('episodes')
            ->where('id', 1)
            ->update([
                'published_at' => '2023-06-01 00:00:00',
            ]);
        $other = $this->db->table('podcasts')
            ->where('id', 1)
            ->get()
            ->getRowArray();
        unset($other['id']);
        $other['handle'] = 'Other';
        $other['guid'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $other['title'] = 'Other podcast';
        $actor = FakeSinglePodcastApiSeeder::actor();
        unset($actor['id']);
        $actor['uri'] = 'https://hub.example/@Other';
        $actor['username'] = 'Other';
        $this->db->table('fediverse_actors')
            ->insert($actor);
        $other['actor_id'] = $this->db->insertID();
        $this->db->table('podcasts')
            ->insert($other);
        $app = config('App');
        $originalBaseURL = $app->baseURL;
        $web->hubURL = 'https://hub.example/';
        $web->domains = '{"Handle":"https://show.example/"}';
        try {
            $app->baseURL = 'https://show.example/';
            $show = $this->getSitemapLocs();
            $this->assertContains('https://show.example/@Handle', $show);
            $this->assertContains('https://show.example/@Handle/episodes/episode-slug/transcript', $show);
            $this->assertNotContains('https://show.example/', $show);
            $this->assertNotContains('https://show.example/@Other', $show);
            $this->assertNotContains('https://show.example/credits', $show);
            $app->baseURL = 'https://hub.example/';
            $hub = $this->getSitemapLocs();
            $this->assertContains('https://hub.example/', $hub);
            $this->assertContains('https://hub.example/@Other', $hub);
            $this->assertNotContains('https://hub.example/@Handle', $hub);
            foreach ($hub as $url) {
                $this->assertStringStartsWith('https://hub.example/', $url);
            }

            // Re-read without clearing caches to check both origins coexist.
            $app->baseURL = 'https://show.example/';
            $this->assertSame($show, $this->getSitemapLocs());
        } finally {
            $app->baseURL = $originalBaseURL;
            $web->hubURL = '';
            $web->domains = '{}';
        }
    }

    private function publishPodcast(): void
    {
        $this->db->table('podcasts')
            ->where('id', 1)
            ->update([
                'published_at' => '2022-06-13 08:00:00',
            ]);
    }

    private function attachTranscript(): void
    {
        $transcript = FakeSinglePodcastApiSeeder::cover();
        $transcript['id'] = 4;
        $transcript['file_key'] = 'podcasts/Handle/episode-slug-transcript.vtt';
        $transcript['file_size'] = 123;
        $transcript['file_mimetype'] = 'text/vtt';
        $transcript['file_metadata'] = null;
        $transcript['type'] = 'transcript';
        $transcript['language_code'] = 'en';

        $this->db->table('media')
            ->insert($transcript);
        $this->db->table('episodes')
            ->where('id', 1)
            ->update([
                'transcript_id' => 4,
            ]);
    }

    /**
     * @return array<string, string|null> lastmod indexed by loc
     */
    private function getSitemapUrls(): array
    {
        $result = $this->call('GET', 'sitemap.xml');
        $result->assertStatus(200);

        $urlset = new SimpleXMLElement((string) $result->response()->getBody());

        $urls = [];
        foreach ($urlset->url as $url) {
            $urls[(string) $url->loc] = $url->lastmod->count() > 0 ? (string) $url->lastmod : null;
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function getSitemapLocs(): array
    {
        return array_keys($this->getSitemapUrls());
    }
}
