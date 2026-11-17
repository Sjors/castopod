<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;
use JsonException;
use SimpleXMLElement;

/** Episode video metadata in the existing settings table; no schema changes. */
class EpisodeVideo extends BaseConfig
{
    public const MAX_SOURCES = 5;

    public const MAX_URL_LENGTH = 2048;

    public const MAX_DIMENSION = 8192;

    public string $playerPath = '/video/';

    /**
     * Default for an episode with no contextual setting.
     */
    public ?string $identifier = null;

    /**
     * Default for an episode with no contextual MP4 record (JSON, bound to an identifier).
     */
    public ?string $enclosure = null;

    public function getIdentifier(int $episodeId): ?string
    {
        $value = service('settings')
            ->get('EpisodeVideo.identifier', 'episode:' . $episodeId);

        return is_string($value) && self::isIdentifier($value) ? $value : null;
    }

    public function setIdentifier(int $episodeId, ?string $identifier): void
    {
        $identifier = trim($identifier ?? '');
        if ($identifier !== '' && ! self::isIdentifier($identifier)) {
            throw new InvalidArgumentException('Expected a naddr, nevent or note video identifier.');
        }

        // Never keep an MP4 record that belongs to another (or no) video.
        $stored = $this->getStoredEnclosure($episodeId);
        if ($stored !== null && $stored['identifier'] !== $identifier) {
            service('settings')->forget('EpisodeVideo.enclosure', 'episode:' . $episodeId);
        }

        // A contextual null also prevents a general setting from enabling videos on this episode.
        service('settings')
            ->set('EpisodeVideo.identifier', $identifier === '' ? null : $identifier, 'episode:' . $episodeId);
    }

    /**
     * Sets the identifier and its MP4 alternate enclosure together. Everything is validated before anything is
     * written; the identifier is written first, so an interrupted write never advertises a stale MP4.
     *
     * @param array<string, mixed> $enclosure
     */
    public function setEnclosure(int $episodeId, string $identifier, array $enclosure): void
    {
        $record = self::normalizeEnclosure([
            'identifier' => trim($identifier),
        ] + $enclosure);

        $this->setIdentifier($episodeId, $record['identifier']);
        service('settings')
            ->set(
                'EpisodeVideo.enclosure',
                json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'episode:' . $episodeId,
            );
    }

    /**
     * The MP4 record, only while it is bound to the episode's current valid video identifier.
     *
     * @return array{identifier: string, sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}|null
     */
    public function getEnclosure(int $episodeId): ?array
    {
        $identifier = $this->getIdentifier($episodeId);
        $record = $this->getStoredEnclosure($episodeId);

        return $identifier !== null && $record !== null && $record['identifier'] === $identifier ? $record : null;
    }

    /**
     * Adds <podcast:alternateEnclosure> for a bound MP4; no relay or Blossom lookup.
     */
    public function addAlternateEnclosure(SimpleXMLElement $item, int $episodeId, string $podcastNamespace): void
    {
        $record = $this->getEnclosure($episodeId);
        if ($record !== null) {
            self::renderAlternateEnclosure($item, $record, $podcastNamespace);
        }
    }

    /**
     * @param array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, bitrate: int|null, codecs: string|null} $record
     */
    public static function renderAlternateEnclosure(
        SimpleXMLElement $item,
        array $record,
        string $podcastNamespace
    ): void {
        $element = $item->addChild('alternateEnclosure', null, $podcastNamespace);
        $element->addAttribute('type', $record['type']);
        $element->addAttribute('length', (string) $record['length']);
        if ($record['height'] !== null) {
            $element->addAttribute('height', (string) $record['height']);
        }

        if ($record['bitrate'] !== null) {
            $element->addAttribute('bitrate', (string) $record['bitrate']);
        }

        if ($record['codecs'] !== null) {
            $element->addAttribute('codecs', $record['codecs']);
        }

        $element->addAttribute('title', 'Video');
        foreach ($record['sources'] as $uri) {
            $element->addChild('source', null, $podcastNamespace)
                ->addAttribute('uri', $uri);
        }

        $integrity = $element->addChild('integrity', null, $podcastNamespace);
        $integrity->addAttribute('type', 'sri');
        $integrity->addAttribute('value', 'sha256-' . base64_encode((string) hex2bin($record['sha256'])));
    }

    /**
     * Strictly validates an MP4 record. Throws without side effects.
     *
     * @param array<string, mixed> $input
     * @return array{identifier: string, sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}
     */
    public static function normalizeEnclosure(array $input): array
    {
        $identifier = $input['identifier'] ?? null;
        if (! is_string($identifier) || ! self::isIdentifier($identifier)) {
            throw new InvalidArgumentException('Expected a naddr, nevent or note video identifier.');
        }

        $sha256 = $input['sha256'] ?? null;
        if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new InvalidArgumentException('The MP4 sha256 must be 64 lowercase hexadecimal characters.');
        }

        $sources = $input['sources'] ?? null;
        if (! is_array($sources) || ! array_is_list($sources) || $sources === []) {
            throw new InvalidArgumentException('At least one MP4 URL is required.');
        }

        if (count($sources) > self::MAX_SOURCES) {
            throw new InvalidArgumentException('At most ' . self::MAX_SOURCES . ' MP4 URLs are allowed.');
        }

        foreach ($sources as $uri) {
            if (! is_string($uri)) {
                throw new InvalidArgumentException('Invalid MP4 URL.');
            }

            self::checkSource($uri, $sha256);
        }

        if (count(array_unique($sources)) !== count($sources)) {
            throw new InvalidArgumentException('Duplicate MP4 URL.');
        }

        $type = $input['type'] ?? 'video/mp4';
        if ($type !== 'video/mp4') {
            throw new InvalidArgumentException('The MP4 MIME type must be exactly video/mp4.');
        }

        $codecs = $input['codecs'] ?? null;
        if ($codecs !== null && (! is_string($codecs) || preg_match('/\A[A-Za-z0-9.,+\- ]{1,128}\z/', $codecs) !== 1)) {
            throw new InvalidArgumentException('The MP4 codecs may only contain letters, digits, spaces and .,+-');
        }

        return [
            'identifier' => $identifier,
            'sources'    => $sources,
            'sha256'     => $sha256,
            'length'     => self::positiveInt($input['length'] ?? null, 'size', PHP_INT_MAX, true),
            'type'       => $type,
            'height'     => self::positiveInt($input['height'] ?? null, 'height', self::MAX_DIMENSION),
            'width'      => self::positiveInt($input['width'] ?? null, 'width', self::MAX_DIMENSION),
            'bitrate'    => self::positiveInt($input['bitrate'] ?? null, 'bitrate', 1_000_000_000),
            'codecs'     => $codecs,
        ];
    }

    public static function isIdentifier(string $identifier): bool
    {
        return strlen($identifier) <= 4096 &&
            preg_match('/\A(?:naddr|nevent|note)1[023456789acdefghjklmnpqrstuvwxyz]+\z/', $identifier) === 1;
    }

    public function getUrl(int $episodeId): ?string
    {
        $identifier = $this->getIdentifier($episodeId);
        if ($identifier === null) {
            return null;
        }

        return $this->playerPath . '?' . http_build_query([
            'v'        => $identifier,
            'branding' => 0,
            'zaps'     => 0,
            'title'    => 0,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{identifier: string, sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}|null
     */
    private function getStoredEnclosure(int $episodeId): ?array
    {
        $value = service('settings')
            ->get('EpisodeVideo.enclosure', 'episode:' . $episodeId);
        if (! is_string($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 4, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? self::normalizeEnclosure($decoded) : null;
        } catch (InvalidArgumentException | JsonException) {
            return null;
        }
    }

    private static function checkSource(string $uri, string $sha256): void
    {
        if (
            strlen($uri) > self::MAX_URL_LENGTH ||
            preg_match('/\Ahttps:\/\/[\x21-\x7E]+\z/', $uri) !== 1 ||
            filter_var($uri, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException(
                'Each MP4 URL must be a valid https:// URL of at most ' . self::MAX_URL_LENGTH . ' characters.',
            );
        }

        $parts = parse_url($uri);
        if (
            ! is_array($parts) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass']) ||
            str_contains($uri, '?') || str_contains($uri, '#')
        ) {
            throw new InvalidArgumentException('MP4 URLs must not contain credentials, a query or a fragment.');
        }

        // Blossom addressing: the last path segment is the blob's sha256, optionally with an extension.
        if (preg_match('/\/' . $sha256 . '(?:\.[A-Za-z0-9]{1,10})?\z/', $parts['path'] ?? '') !== 1) {
            throw new InvalidArgumentException('Each MP4 URL path must end with the sha256 (Blossom address).');
        }
    }

    private static function positiveInt(mixed $value, string $name, int $max, bool $required = false): ?int
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidArgumentException("The MP4 {$name} is required.");
            }

            return null;
        }

        $string = is_int($value) ? (string) $value : $value;
        if (! is_string($string) || preg_match('/\A[1-9]\d{0,17}\z/', $string) !== 1 || (int) $string > $max) {
            throw new InvalidArgumentException("The MP4 {$name} must be a positive integer of at most {$max}.");
        }

        return (int) $string;
    }
}
