<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

/** Episode video metadata in the existing settings table; no schema changes. */
class EpisodeVideo extends BaseConfig
{
    public string $playerPath = '/video/';

    /** Default for an episode with no contextual setting. */
    public ?string $identifier = null;

    public function getIdentifier(int $episodeId): ?string
    {
        $value = service('settings')->get('EpisodeVideo.identifier', 'episode:' . $episodeId);

        return is_string($value) && self::isIdentifier($value) ? $value : null;
    }

    public function setIdentifier(int $episodeId, ?string $identifier): void
    {
        $identifier = trim($identifier ?? '');
        if ($identifier !== '' && ! self::isIdentifier($identifier)) {
            throw new InvalidArgumentException('Expected a naddr, nevent or note video identifier.');
        }

        // A contextual null also prevents a general setting from enabling videos on this episode.
        service('settings')->set(
            'EpisodeVideo.identifier',
            $identifier === '' ? null : $identifier,
            'episode:' . $episodeId,
        );
    }

    public static function isIdentifier(string $identifier): bool
    {
        return strlen($identifier) <= 4096 &&
            preg_match('/\A(?:naddr|nevent|note)1[023456789acdefghjklmnpqrstuvwxyz]+\z/', $identifier) === 1;
    }

    public function getUrl(int $episodeId, string $podcastHandle, string $episodeSlug): ?string
    {
        $identifier = $this->getIdentifier($episodeId);
        if ($identifier === null) {
            return null;
        }

        return $this->playerPath . '?' . http_build_query([
            'v'        => $identifier,
            'branding' => 0,
            'zaps'     => 0,
            'back'     => route_to('episode-discussion', $podcastHandle, $episodeSlug),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
