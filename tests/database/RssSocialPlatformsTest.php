<?php

declare(strict_types=1);

use App\Database\Seeds\FakeSinglePodcastApiSeeder;
use App\Entities\Podcast;
use App\Models\PodcastModel;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class RssSocialPlatformsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const NS = 'https://podcastindex.org/namespace/1.0';

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

    public function testMastodonPlatformRendersWithoutTheCastopodAccount(): void
    {
        $this->assertFalse(config('Fediverse')->isEnabled());
        $this->db->table('platforms')
            ->insert([
                'podcast_id' => 1,
                'type'       => 'social',
                'slug'       => 'mastodon',
                'link_url'   => 'https://mastodon.example/@podcast',
                'account_id' => '@podcast@mastodon.example',
                'is_visible' => 1,
            ]);
        cache()
            ->clean();

        $podcast = (new PodcastModel())->getPodcastById(1);
        $this->assertInstanceOf(Podcast::class, $podcast);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML(get_rss_feed($podcast)));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('podcast', self::NS);

        $this->assertSame(1, $xpath->query('/rss/channel/podcast:social')->length);
        $this->assertSame(0, $xpath->query('/rss/channel/podcast:social[@platform="castopod"]')->length);
        $this->assertSame(
            'https://mastodon.example/@podcast',
            $xpath->evaluate('string(/rss/channel/podcast:social[@platform="mastodon"]/@accountUrl)'),
        );
        $this->assertSame(
            'https://mastodon.example/auth/sign_up',
            $xpath->evaluate(
                'string(/rss/channel/podcast:social[@platform="mastodon"]/podcast:socialSignUp/@signUpUrl)'
            ),
        );
    }
}
