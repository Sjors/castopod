<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/** @internal */
final class WebCacheTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('url');
    }

    protected function tearDown(): void
    {
        cache()->deleteMatching('page_podcast#987654*');
        Services::resetSingle('request');
        Services::resetSingle('uri');
        parent::tearDown();
    }

    public function testRenderedCachesAreIsolatedButWildcardInvalidationStillWorks(): void
    {
        $prefix = 'page_podcast#987654_activity_en';
        $this->setOrigin('https://hub.example/');
        $hubKey = web_cache_key($prefix);
        cache()
            ->save($hubKey, 'hub HTML', 60);
        $this->setOrigin('https://show.example/');
        $showKey = web_cache_key($prefix);
        $this->assertNotSame($hubKey, $showKey);
        $this->assertNull(cache($showKey));
        cache()
            ->save($showKey, 'show HTML', 60);
        $this->assertSame('hub HTML', cache($hubKey));
        $this->assertSame('show HTML', cache($showKey));
        cache()
            ->deleteMatching('page_podcast#987654*');
        $this->assertNull(cache($hubKey));
        $this->assertNull(cache($showKey));
    }

    private function setOrigin(string $origin): void
    {
        $app = new App();
        $app->baseURL = $origin;

        $uri = new SiteURI($app);
        Services::injectMock('uri', $uri);
        Services::injectMock('request', new IncomingRequest($app, $uri, 'php://input', new UserAgent()));
    }
}
