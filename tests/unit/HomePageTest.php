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
use DOMDocument;
use DOMXPath;

/**
 * @internal
 */
final class HomePageTest extends CIUnitTestCase
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

    public function testDescriptionIsVisiblePlainText(): void
    {
        $description = 'Conversations & ideas <script>alert("hello")</script>';
        service('settings')
            ->set('App.siteDescription', $description);

        $result = $this->get('/');
        $result->assertStatus(200);

        $document = new DOMDocument();
        @$document->loadHTML($result->getBody());
        $xpath = new DOMXPath($document);
        $this->assertSame($description, $xpath->evaluate('string(//main/p)'));
        $this->assertSame(0, $xpath->query('//main//script')->length);
    }

    public function testEmptyDescriptionOmitsIntroduction(): void
    {
        service('settings')->set('App.siteDescription', '  ');

        $result = $this->get('/');
        $result->assertStatus(200);

        $document = new DOMDocument();
        @$document->loadHTML($result->getBody());
        $xpath = new DOMXPath($document);
        $this->assertSame(0, $xpath->query('//main/p')->length);
    }
}
