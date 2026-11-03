<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\PodcastWeb;
use InvalidArgumentException;

/** @internal */
final class PodcastWebTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('url');
    }

    public function testUnconfiguredInstallKeepsEveryPodcast(): void
    {
        $web = new PodcastWeb();
        $this->assertTrue($web->isHub('https://example.com/'));
        $this->assertTrue($web->servesPodcast('anything', 'https://example.com/'));
    }

    public function testDedicatedShowAndDefaultHubAreSelectedSeparately(): void
    {
        $web = new PodcastWeb();
        $web->hubURL = 'https://hub.example/';
        $web->domains = '{"main":"https://show.example/"}';
        $this->assertTrue($web->isHub('https://hub.example/'));
        $this->assertFalse($web->isHub('https://show.example/'));
        $this->assertTrue($web->servesPodcast('main', 'https://show.example/'));
        $this->assertFalse($web->servesPodcast('main', 'https://hub.example/'));
        $this->assertTrue($web->servesPodcast('future-show', 'https://hub.example/'));
        $this->assertFalse($web->servesPodcast('future-show', 'https://show.example/'));
    }

    public function testNonRootDomainConfigurationIsRejected(): void
    {
        $web = new PodcastWeb();
        $web->hubURL = 'https://hub.example/path';
        $this->expectException(InvalidArgumentException::class);
        $web->isHub('https://hub.example/');
    }
}
