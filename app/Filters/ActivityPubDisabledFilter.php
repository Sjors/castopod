<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Runs before page-cache lookup, for all visitors including administrators. */
class ActivityPubDisabledFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (config('Fediverse')->isEnabled()) {
            return;
        }

        $path = '/' . ltrim($request->getUri()->getPath(), '/');
        for ($i = 0; $i < 5; $i++) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }
            $path = $decoded;
        }
        $path = preg_replace('#^/index[.]php(?=/|$)#i', '', $path);
        $media = $request->getHeaderLine('Accept') . ',' . $request->getHeaderLine('Content-Type');
        $admin = preg_quote(trim(config('Admin')->gateway, '/'), '#');
        if (
            preg_match('#application/(?:activity[+]json|podcast-activity[+]json|ld[+]json)#i', $media) ||
            preg_match('#^/(?:[.]well-known/(?:webfinger|nodeinfo|x-nodeinfo2)|nodeinfo|inbox|outbox|actors|users|posts|fediverse-[^/]+)(?:/|$)#i', $path) ||
            preg_match('#^/@[^/]+/(?:inbox|outbox|followers|following|follow|activities|posts)(?:/|$)#i', $path) ||
            preg_match('#^/@[^/]+/episodes/[^/]+/(?:activity|discussion|comments)(?:/|$)#i', $path) ||
            preg_match('#^/p/[^/]+/activity(?:/|$)#i', $path) ||
            preg_match('#^/' . $admin . '/(?:fediverse(?:/|$)|podcasts/[^/]+/notifications(?:/|$))#i', $path)
        ) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
