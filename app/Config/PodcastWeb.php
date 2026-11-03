<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

/** Optional web-only domain selection. Does not change stored federation identities. */
class PodcastWeb extends BaseConfig
{
    public string $hubURL = '';

    /**
     * JSON object mapping podcast handles to their dedicated root URLs.
     */
    public string $domains = '{}';

    public string $hubName = '';

    public string $hubTitle = '';

    public string $hubDescription = '';

    public string $hubAlternateName = '';

    public function isHub(string $baseURL): bool
    {
        return $this->hubURL === '' || $this->rootURL($this->hubURL) === $this->rootURL($baseURL);
    }

    public function servesPodcast(string $handle, string $baseURL): bool
    {
        if ($this->hubURL === '') {
            return true;
        }

        $domains = json_decode($this->domains, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($domains) || ($domains !== [] && array_is_list($domains))) {
            throw new InvalidArgumentException('PodcastWeb.domains must be a JSON object.');
        }

        $url = $domains[$handle] ?? $this->hubURL;
        if (! is_string($url)) {
            throw new InvalidArgumentException('PodcastWeb domain URLs must be strings.');
        }

        return $this->rootURL($url) === $this->rootURL($baseURL);
    }

    private function rootURL(string $url): string
    {
        $parts = parse_url($url);
        if (
            $parts === false || ! isset($parts['scheme'], $parts['host']) ||
            ! in_array($parts['scheme'], ['http', 'https'], true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            ! in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw new InvalidArgumentException('PodcastWeb URLs must be absolute HTTP(S) root URLs.');
        }

        return rtrim($url, '/') . '/';
    }
}
