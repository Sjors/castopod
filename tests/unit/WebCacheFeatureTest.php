<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\FakeSinglePodcastApiSeeder;
use App\Models\EpisodeModel;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use SimpleXMLElement;

class WebCacheFeatureTest extends CIUnitTestCase
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

    public function testFeedCacheSeparatesHostsAndPreservesEpisodeGuid(): void
    {
        $this->publishPodcast();
        $this->db->table('episodes')
            ->where('id', 1)
            ->update([
                'published_at' => '2023-06-01 00:00:00',
            ]);
        $app = config('App');
        $originalBaseURL = $app->baseURL;
        try {
            $app->baseURL = 'https://show.example/';
            $show = $this->call('GET', '@Handle/feed.xml');
            $show->assertStatus(200);
            $showBody = (string) $show->response()
                ->getBody();
            $this->assertStringContainsString('https://show.example/@Handle/feed.xml', $showBody);
            $showXml = new SimpleXMLElement($showBody);
            $guid = (string) $showXml->channel->item->guid;
            $this->assertNotSame('', $guid);
            $app->baseURL = 'https://hub.example/';
            $hub = $this->call('GET', '@Handle/feed.xml');
            $hub->assertStatus(200);
            $hubBody = (string) $hub->response()
                ->getBody();
            $this->assertStringContainsString('https://hub.example/@Handle/feed.xml', $hubBody);
            $this->assertStringNotContainsString('https://show.example/', $hubBody);
            $hubXml = new SimpleXMLElement($hubBody);
            $this->assertSame($guid, (string) $hubXml->channel->item->guid);
        } finally {
            $app->baseURL = $originalBaseURL;
        }
    }

    public function testEpisodeUpdateInvalidatesRenderedOutputForBothHosts(): void
    {
        $keys = [];
        foreach (['hub', 'show'] as $host) {
            foreach (['page_podcast#1_episode#1_comments_en', 'podcast#1_feed', 'episodes_markers'] as $prefix) {
                $key = $prefix . '_web#' . md5('https://' . $host . '.example/');
                $keys[] = $key;
                cache()
                    ->save($key, 'stale output', 60);
                $this->assertSame('stale output', cache($key));
            }
        }

        (new EpisodeModel())->clearCache([
            'id' => 1,
        ]);
        foreach ($keys as $key) {
            $this->assertNull(cache($key));
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
}
