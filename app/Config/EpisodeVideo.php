<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/** Local episode-to-video links; no database or public video lookup is needed. */
class EpisodeVideo extends BaseConfig
{
    public string $playerPath = '/video/';

    /** JSON under writable/, keyed by podcast handle, then episode slug. */
    public string $mapFile = 'episode-videos.json';

    public function getUrl(string $podcastHandle, string $episodeSlug): ?string
    {
        $path = WRITEPATH . $this->mapFile;
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $videos = json_decode($contents, true);
        $identifier = is_array($videos) ? ($videos[$podcastHandle][$episodeSlug] ?? null) : null;
        if (
            ! is_string($identifier) ||
            preg_match('/\A(?:naddr|nevent|note)1[023456789acdefghjklmnpqrstuvwxyz]+\z/', $identifier) !== 1
        ) {
            return null;
        }

        return $this->playerPath . '?' . http_build_query([
            'v'        => $identifier,
            'branding' => 0,
            'zaps'     => 0,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
