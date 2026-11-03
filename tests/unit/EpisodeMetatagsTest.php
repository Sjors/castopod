<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\FakeSinglePodcastApiSeeder;
use App\Entities\Episode;
use App\Models\EpisodeModel;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use DOMDocument;
use DOMXPath;

class EpisodeMetatagsTest extends CIUnitTestCase
{
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

        helper('seo');

        $this->db->table('podcasts')
            ->where('id', 1)
            ->update([
                'published_at' => '2022-06-13 08:00:00',
            ]);
        $this->db->table('episodes')
            ->where('id', 1)
            ->update([
                'published_at' => '2023-06-01 00:00:00',
            ]);
    }

    public function testEpisodeMetatagsUseEpisodeCanonical(): void
    {
        $metatags = get_episode_metatags($this->getEpisode());

        $this->assertStringContainsString('<title>Episode title</title>', $metatags);
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://example.com/@Handle/episodes/episode-slug" />',
            $metatags,
        );

        $schema = $this->getSchema($metatags);
        $this->assertSame('PodcastEpisode', $schema['@type']);
        $this->assertSame('https://example.com/@Handle/episodes/episode-slug', $schema['url']);
    }

    public function testTranscriptMetatagsUseTranscriptCanonical(): void
    {
        $metatags = get_episode_metatags($this->getEpisode(), 'transcript');

        $this->assertStringContainsString('<title>Transcript: Episode title</title>', $metatags);
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://example.com/@Handle/episodes/episode-slug/transcript" />',
            $metatags,
        );

        $schema = $this->getSchema($metatags);
        $this->assertSame('WebPage', $schema['@type']);
        $this->assertSame('https://example.com/@Handle/episodes/episode-slug/transcript', $schema['url']);
        $this->assertSame('PodcastEpisode', $schema['mainEntity']['@type']);
        $this->assertSame('https://example.com/@Handle/episodes/episode-slug', $schema['mainEntity']['url']);
    }

    public function testEpisodeAndTranscriptTextIsEscapedOnce(): void
    {
        $this->db->table('episodes')
            ->where('id', 1)
            ->update([
                'description_html' => '<p>Keys &amp; &quot;quotes&quot; &lt;script&gt;example&lt;/script&gt;</p>',
            ]);
        service('settings')
            ->set('App.siteName', 'Audio & Friends');

        foreach (['episode', 'transcript'] as $page) {
            $html = get_episode_metatags($this->getEpisode(), $page);
            $xpath = $this->getXPath($html);
            $description = 'Keys & "quotes" <script>example</script>';
            $expected = $page === 'transcript' ? 'Transcript: ' . $description : $description;

            $this->assertSame($expected, $xpath->evaluate('string(//meta[@name="description"]/@content)'));
            $this->assertSame($expected, $xpath->evaluate('string(//meta[@property="og:description"]/@content)'));
            $this->assertSame('Audio & Friends', $xpath->evaluate('string(//meta[@property="og:site_name"]/@content)'));
            $this->assertSame(1, $xpath->query('//script')->length);
            $schema = $this->getSchema($html);
            $this->assertSame($expected, $schema['description']);
            if ($page === 'transcript') {
                $this->assertSame($description, $schema['mainEntity']['description']);
            }
        }
    }

    private function getXPath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8">' . $html . '</head><body></body></html>'
        );

        return new DOMXPath($document);
    }

    private function getEpisode(): Episode
    {
        $episode = (new EpisodeModel())->find(1);
        $this->assertInstanceOf(Episode::class, $episode);

        return $episode;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSchema(string $metatags): array
    {
        $matched = preg_match(
            '/<script type="application\/ld\+json">(?<schema>.+)<\/script>/',
            $metatags,
            $matches,
        );
        $this->assertSame(1, $matched);

        return json_decode($matches['schema'], true, flags: JSON_THROW_ON_ERROR);
    }
}
