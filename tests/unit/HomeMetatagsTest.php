<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Config\Services;
use CodeIgniter\Settings\Settings;
use CodeIgniter\Test\CIUnitTestCase;
use DOMDocument;
use DOMXPath;

/**
 * @internal
 */
final class HomeMetatagsTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['seo', 'misc']);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        parent::tearDown();
    }

    public function testDefaultsKeepSiteNameAndAddWebsiteIdentity(): void
    {
        $this->setSettings([]);
        $html = get_home_metatags();
        $xpath = $this->getXPath($html);

        $this->assertSame('Example Podcasts', $xpath->evaluate('string(//title)'));
        $this->assertSame(base_url(), $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame('website', $xpath->evaluate('string(//meta[@property="og:type"]/@content)'));

        $schema = $this->getSchema($xpath);
        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('WebSite', $schema['@type']);
        $this->assertSame('Example Podcasts', $schema['name']);
        $this->assertSame(base_url(), $schema['url']);
        $this->assertArrayNotHasKey('alternateName', $schema);
    }

    public function testDescriptiveTitleKeepsShortSiteName(): void
    {
        $this->setSettings([
            'App.homeTitle'         => 'Example Podcasts — Technology conversations',
            'App.siteAlternateName' => 'Example Audio',
        ]);
        $xpath = $this->getXPath(get_home_metatags());

        $this->assertSame('Example Podcasts — Technology conversations', $xpath->evaluate('string(//title)'));
        $this->assertSame('Example Podcasts', $xpath->evaluate('string(//meta[@property="og:site_name"]/@content)'));
        $this->assertSame('Example Audio', $this->getSchema($xpath)['alternateName']);
        $this->assertSame(base_url('icon-512.png'), $xpath->evaluate('string(//meta[@property="og:image"]/@content)'));
    }

    public function testWhitespaceTitleFallsBackAndDuplicateAlternateNameIsOmitted(): void
    {
        $this->setSettings([
            'App.homeTitle'         => '  ',
            'App.siteAlternateName' => ' Example Podcasts ',
        ]);
        $xpath = $this->getXPath(get_home_metatags());

        $this->assertSame('Example Podcasts', $xpath->evaluate('string(//title)'));
        $this->assertArrayNotHasKey('alternateName', $this->getSchema($xpath));
    }

    public function testTextIsEscapedOnceAndSchemaCannotCloseItsScript(): void
    {
        $name = 'Audio & "Friends" </script><script>alert(1)</script>';
        $description = 'People & ideas: "why?" <not markup>';
        $this->setSettings([
            'App.siteName'        => $name,
            'App.siteDescription' => $description,
        ]);
        $xpath = $this->getXPath(get_home_metatags());

        $this->assertSame($name, $xpath->evaluate('string(//title)'));
        $this->assertSame($name, $xpath->evaluate('string(//meta[@property="og:site_name"]/@content)'));
        $this->assertSame($description, $xpath->evaluate('string(//meta[@name="description"]/@content)'));
        $this->assertSame($description, $xpath->evaluate('string(//meta[@property="og:description"]/@content)'));
        $this->assertSame(1, $xpath->query('//script')->length);
        $this->assertSame($name, $this->getSchema($xpath)['name']);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function setSettings(array $overrides): void
    {
        $values = array_replace([
            'App.siteName'          => 'Example Podcasts',
            'App.siteDescription'   => 'Conversations about technology.',
            'App.homeTitle'         => '',
            'App.siteAlternateName' => '',
            'App.siteIcon'          => config('App')
                ->siteIcon,
        ], $overrides);
        $settings = $this->createMock(Settings::class);
        $settings->method('get')
            ->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? null);
        Services::injectMock('settings', $settings);
    }

    private function getXPath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8">' . $html . '</head><body></body></html>'
        );

        return new DOMXPath($document);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSchema(DOMXPath $xpath): array
    {
        return json_decode(
            (string) $xpath->evaluate('string(//script[@type="application/ld+json"])'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }
}
