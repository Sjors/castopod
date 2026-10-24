<?php

declare(strict_types=1);

use App\Controllers\FeedController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class FeedResponseTest extends CIUnitTestCase
{
    private const XML = '<?xml version="1.0"?><rss>café</rss>';

    /**
     * @return iterable<string, array{string, string, string, int, string, string}>
     */
    public static function rangeRequests(): iterable
    {
        $xml = self::XML;
        $length = strlen($xml);
        $last = $length - 1;

        yield 'full feed' => ['GET', '', '', 200, $xml, ''];
        yield 'first two bytes' => ['GET', 'bytes=0-1', '', 206, '<?', "bytes 0-1/{$length}"];
        yield 'open ended' => ['GET', 'bytes=2-', '', 206, substr($xml, 2), "bytes 2-{$last}/{$length}"];
        yield 'suffix' => ['GET', 'bytes=-6', '', 206, '</rss>', 'bytes ' . ($length - 6) . "-{$last}/{$length}"];
        yield 'oversized end' => ['GET', 'bytes=0-999999999999999999999', '', 206, $xml, "bytes 0-{$last}/{$length}"];
        yield 'oversized suffix' => ['GET', 'bytes=-999999999999999999999', '', 206, $xml, "bytes 0-{$last}/{$length}"];
        yield 'past end' => ['GET', 'bytes=999999999999999999999-', '', 416, '', "bytes */{$length}"];
        yield 'reversed' => ['GET', 'bytes=4-2', '', 416, '', "bytes */{$length}"];
        yield 'zero suffix' => ['GET', 'bytes=-0', '', 416, '', "bytes */{$length}"];
        yield 'missing bounds' => ['GET', 'bytes=-', '', 200, $xml, ''];
        yield 'multiple ranges' => ['GET', 'bytes=0-1,4-5', '', 200, $xml, ''];
        yield 'invalid range' => ['GET', 'bytes=abc-def', '', 200, $xml, ''];
        yield 'If-Range without a validator' => ['GET', 'bytes=0-1', '"old-etag"', 200, $xml, ''];
        yield 'HEAD ignores Range' => ['HEAD', 'bytes=0-1', '', 200, $xml, ''];
        $offset = strpos($xml, 'é');
        yield 'byte within UTF-8 character' => [
            'GET',
            "bytes={$offset}-{$offset}",
            '',
            206,
            "\xc3",
            "bytes {$offset}-{$offset}/{$length}",
        ];
    }

    #[DataProvider('rangeRequests')]
    public function testFeedResponse(
        string $method,
        string $range,
        string $ifRange,
        int $status,
        string $body,
        string $contentRange,
    ): void {
        $request = new IncomingRequest(config('App'), new URI('https://example.com/feed.xml'), null, new UserAgent());
        $request->setMethod($method);
        if ($range !== '') {
            $request->setHeader('Range', $range);
        }

        if ($ifRange !== '') {
            $request->setHeader('If-Range', $ifRange);
        }

        $controller = new FeedController();
        self::setPrivateProperty($controller, 'request', $request);
        self::setPrivateProperty($controller, 'response', new Response(config('App')));
        $invoke = self::getPrivateMethodInvoker($controller, 'feedResponse');
        /** @var ResponseInterface $response */
        $response = $invoke(self::XML);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame($body, $response->getBody());
        $this->assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
        $this->assertSame($contentRange, $response->getHeaderLine('Content-Range'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        $this->assertStringStartsWith('application/xml', $response->getHeaderLine('Content-Type'));
    }
}
