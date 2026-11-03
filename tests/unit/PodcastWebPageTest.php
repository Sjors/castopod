<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Config\Services;
use CodeIgniter\Settings\Config\Settings as SettingsConfig;
use CodeIgniter\Settings\Settings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniterVite\Vite;

/**
 * @internal
 */
final class PodcastWebPageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

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
     * @var string
     */
    protected $basePath = 'app/Database';

    protected function setUp(): void
    {
        parent::setUp();

        $config = new SettingsConfig();
        $config->handlers = ['array'];
        Services::injectMock('settings', new Settings($config));
        service('settings')
            ->set('App.siteName', 'Example Podcasts');

        $vite = $this->createMock(Vite::class);
        $vite->method('asset')
            ->willReturn('');
        Services::injectMock('vite', $vite);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('vite');
        parent::tearDown();
    }

    public function testHubIdentityDoesNotChangeDedicatedSiteSettings(): void
    {
        $app = config('App');
        $web = config('PodcastWeb');
        $originalBaseURL = $app->baseURL;
        service('settings')
            ->set('App.siteName', 'Dedicated podcast');
        service('settings')
            ->set('App.siteDescription', 'Dedicated podcast introduction.');

        $web->hubURL = 'https://hub.example/';
        $web->hubName = 'Example Audio';
        $web->hubTitle = 'Example Audio — Podcasts';
        $web->hubDescription = 'Conversations and transcripts.';
        try {
            $app->baseURL = 'https://hub.example/';
            $result = $this->get('/');
            $result->assertStatus(200);
            $html = (string) $result->response()
                ->getBody();
            $this->assertStringContainsString('Example Audio', $html);
            $this->assertStringContainsString('Conversations and transcripts.', $html);
            $this->assertStringNotContainsString('Dedicated podcast introduction.', $html);
            // The descriptive title is an optional homepage-preview feature.
            if (function_exists('site_identity_setting')) {
                $this->assertStringContainsString('Example Audio — Podcasts', $html);
            }

            $manifest = $this->get('manifest.webmanifest');
            $manifest->assertStatus(200);
            $this->assertSame('Example Audio', json_decode((string) $manifest->response()->getBody(), true)['name']);
            $app->baseURL = 'https://show.example/';
            $result = $this->get('/');
            $result->assertStatus(200);
            $this->assertStringContainsString(
                'Dedicated podcast introduction.',
                (string) $result->response()
                    ->getBody()
            );
            $this->assertSame('Dedicated podcast', service('settings')->get('App.siteName'));
        } finally {
            $app->baseURL = $originalBaseURL;
            $web->hubURL = '';
            $web->hubName = '';
            $web->hubTitle = '';
            $web->hubDescription = '';
        }
    }
}
