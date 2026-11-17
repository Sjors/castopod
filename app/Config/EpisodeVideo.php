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

    public const HLS_TYPE = 'application/x-mpegURL';

    public string $playerPath = '/video/';

    /**
     * Default for an episode with no contextual setting.
     */
    public ?string $identifier = null;

    /**
     * Default for an episode with no contextual HLS/MP4 record (JSON, bound to an identifier).
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

        // Never keep an HLS/MP4 record that belongs to another (or no) video.
        $stored = $this->getStoredEnclosure($episodeId);
        if ($stored !== null && $stored['identifier'] !== $identifier) {
            service('settings')->forget('EpisodeVideo.enclosure', 'episode:' . $episodeId);
        }

        // A contextual null also prevents a general setting from enabling videos on this episode.
        service('settings')
            ->set('EpisodeVideo.identifier', $identifier === '' ? null : $identifier, 'episode:' . $episodeId);
    }

    /**
     * Sets the identifier and its alternate enclosures together, replacing any previous HLS and MP4 record. Everything
     * is validated before anything is written; the identifier is written first, so an interrupted write never
     * advertises a stale video.
     *
     * @param array<string, mixed> $enclosure ['hls' => fields|null, 'mp4' => fields|null]; a flat MP4 record is accepted as MP4-only
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
     * The HLS/MP4 record, only while it is bound to the episode's current valid video identifier.
     *
     * @return array{identifier: string, hls: array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}|null, mp4: array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}|null}|null
     */
    public function getEnclosure(int $episodeId): ?array
    {
        $identifier = $this->getIdentifier($episodeId);
        $record = $this->getStoredEnclosure($episodeId);

        return $identifier !== null && $record !== null && $record['identifier'] === $identifier ? $record : null;
    }

    /**
     * Adds the bound HLS and MP4 <podcast:alternateEnclosure> elements, HLS first; no relay or Blossom lookup.
     */
    public function addAlternateEnclosure(SimpleXMLElement $item, int $episodeId, string $podcastNamespace): void
    {
        $record = $this->getEnclosure($episodeId);
        if ($record !== null) {
            self::renderAlternateEnclosures($item, $record, $podcastNamespace);
        }
    }

    /**
     * @param array{hls: array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, bitrate: int|null, codecs: string|null}|null, mp4: array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, bitrate: int|null, codecs: string|null}|null} $record
     */
    public static function renderAlternateEnclosures(
        SimpleXMLElement $item,
        array $record,
        string $podcastNamespace
    ): void {
        foreach (['hls', 'mp4'] as $kind) {
            if ($record[$kind] !== null) {
                self::renderAlternateEnclosure($item, $record[$kind], $podcastNamespace);
            }
        }
    }

    /**
     * @param array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, bitrate: int|null, codecs: string|null} $media
     */
    public static function renderAlternateEnclosure(
        SimpleXMLElement $item,
        array $media,
        string $podcastNamespace
    ): void {
        $element = $item->addChild('alternateEnclosure', null, $podcastNamespace);
        $element->addAttribute('type', $media['type']);
        $element->addAttribute('length', (string) $media['length']);
        if ($media['height'] !== null) {
            $element->addAttribute('height', (string) $media['height']);
        }

        if ($media['bitrate'] !== null) {
            $element->addAttribute('bitrate', (string) $media['bitrate']);
        }

        if ($media['codecs'] !== null) {
            $element->addAttribute('codecs', $media['codecs']);
        }

        $element->addAttribute('title', $media['type'] === self::HLS_TYPE ? 'Video (HLS)' : 'Video');
        foreach ($media['sources'] as $uri) {
            $element->addChild('source', null, $podcastNamespace)
                ->addAttribute('uri', $uri);
        }

        $integrity = $element->addChild('integrity', null, $podcastNamespace);
        $integrity->addAttribute('type', 'sri');
        $integrity->addAttribute('value', 'sha256-' . base64_encode((string) hex2bin($media['sha256'])));
    }

    /**
     * Strictly validates an enclosure record: an identifier with an HLS stream, an MP4 or both. A flat record (the
     * original MP4-only shape) is read as MP4-only. Throws without side effects.
     *
     * @param array<string, mixed> $input
     * @return array{identifier: string, hls: array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}|null, mp4: array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}|null}
     */
    public static function normalizeEnclosure(array $input): array
    {
        $identifier = $input['identifier'] ?? null;
        if (! is_string($identifier) || ! self::isIdentifier($identifier)) {
            throw new InvalidArgumentException('Expected a naddr, nevent or note video identifier.');
        }

        unset($input['identifier']);
        if (! array_key_exists('hls', $input) && ! array_key_exists('mp4', $input)) {
            $input = [
                'hls' => null,
                'mp4' => $input,
            ];
        }

        $unexpected = array_diff(array_keys($input), ['hls', 'mp4']);
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Unexpected enclosure field: ' . implode(', ', $unexpected) . '.');
        }

        $media = [];
        foreach ([
            'hls' => 'HLS',
            'mp4' => 'MP4',
        ] as $kind => $label) {
            $value = $input[$kind] ?? null;
            if ($value !== null && ! is_array($value)) {
                throw new InvalidArgumentException("Invalid {$label} record.");
            }

            $media[$kind] = $value === null ? null : self::normalizeMedia($value, $kind === 'hls');
        }

        if ($media['hls'] === null && $media['mp4'] === null) {
            throw new InvalidArgumentException('An HLS stream, an MP4 or both are required.');
        }

        if ($media['hls'] !== null && $media['mp4'] !== null && $media['hls']['sha256'] === $media['mp4']['sha256']) {
            throw new InvalidArgumentException('The HLS and MP4 sha256 must differ.');
        }

        return [
            'identifier' => $identifier,
            'hls'        => $media['hls'],
            'mp4'        => $media['mp4'],
        ];
    }

    /**
     * Strictly validates one HLS master playlist or MP4 file. Throws without side effects.
     *
     * @param array<mixed> $input
     * @return array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}
     */
    public static function normalizeMedia(array $input, bool $hls): array
    {
        $label = $hls ? 'HLS' : 'MP4';
        $unexpected = array_diff(
            array_keys($input),
            ['sources', 'sha256', 'length', 'type', 'height', 'width', 'bitrate', 'codecs'],
        );
        if ($unexpected !== []) {
            throw new InvalidArgumentException("Unexpected {$label} field: " . implode(', ', $unexpected) . '.');
        }

        $sha256 = $input['sha256'] ?? null;
        if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new InvalidArgumentException("The {$label} sha256 must be 64 lowercase hexadecimal characters.");
        }

        $sources = $input['sources'] ?? null;
        if (! is_array($sources) || ! array_is_list($sources) || $sources === []) {
            throw new InvalidArgumentException("At least one {$label} URL is required.");
        }

        if (count($sources) > self::MAX_SOURCES) {
            throw new InvalidArgumentException('At most ' . self::MAX_SOURCES . " {$label} URLs are allowed.");
        }

        foreach ($sources as $uri) {
            if (! is_string($uri)) {
                throw new InvalidArgumentException("Invalid {$label} URL.");
            }

            self::checkSource($uri, $sha256, $label);
        }

        if (count(array_unique($sources)) !== count($sources)) {
            throw new InvalidArgumentException("Duplicate {$label} URL.");
        }

        $type = $input['type'] ?? ($hls ? self::HLS_TYPE : 'video/mp4');
        if ($hls) {
            if ($type !== self::HLS_TYPE && $type !== 'application/vnd.apple.mpegurl') {
                throw new InvalidArgumentException(
                    'The HLS MIME type must be exactly ' . self::HLS_TYPE . ' or application/vnd.apple.mpegurl.',
                );
            }

            $type = self::HLS_TYPE;
        } elseif ($type !== 'video/mp4') {
            throw new InvalidArgumentException('The MP4 MIME type must be exactly video/mp4.');
        }

        $codecs = $input['codecs'] ?? null;
        if ($codecs !== null && (! is_string($codecs) || preg_match('/\A[A-Za-z0-9.,+\- ]{1,128}\z/', $codecs) !== 1)) {
            throw new InvalidArgumentException("The {$label} codecs may only contain letters, digits, spaces and .,+-");
        }

        return [
            'sources' => $sources,
            'sha256'  => $sha256,
            'length'  => self::positiveInt($input['length'] ?? null, $label, 'size', PHP_INT_MAX, true),
            'type'    => $type,
            'height'  => self::positiveInt($input['height'] ?? null, $label, 'height', self::MAX_DIMENSION),
            'width'   => self::positiveInt($input['width'] ?? null, $label, 'width', self::MAX_DIMENSION),
            'bitrate' => self::positiveInt($input['bitrate'] ?? null, $label, 'bitrate', 1_000_000_000),
            'codecs'  => $codecs,
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
     * @return array{identifier: string, hls: array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}|null, mp4: array{sources: list<string>, sha256: string, length: int, type: string, height: int|null, width: int|null, bitrate: int|null, codecs: string|null}|null}|null
     */
    private function getStoredEnclosure(int $episodeId): ?array
    {
        $value = service('settings')
            ->get('EpisodeVideo.enclosure', 'episode:' . $episodeId);
        if (! is_string($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 5, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? self::normalizeEnclosure($decoded) : null;
        } catch (InvalidArgumentException | JsonException) {
            return null;
        }
    }

    private static function checkSource(string $uri, string $sha256, string $label): void
    {
        if (
            strlen($uri) > self::MAX_URL_LENGTH ||
            preg_match('/\Ahttps:\/\/[\x21-\x7E]+\z/', $uri) !== 1 ||
            filter_var($uri, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException(
                "Each {$label} URL must be a valid https:// URL of at most " . self::MAX_URL_LENGTH . ' characters.',
            );
        }

        $parts = parse_url($uri);
        if (
            ! is_array($parts) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass']) ||
            str_contains($uri, '?') || str_contains($uri, '#')
        ) {
            throw new InvalidArgumentException("{$label} URLs must not contain credentials, a query or a fragment.");
        }

        // Blossom addressing: the last path segment is the blob's sha256, optionally with an extension.
        if (preg_match('/\/' . $sha256 . '(?:\.[A-Za-z0-9]{1,10})?\z/', $parts['path'] ?? '') !== 1) {
            throw new InvalidArgumentException("Each {$label} URL path must end with the sha256 (Blossom address).");
        }
    }

    private static function positiveInt(
        mixed $value,
        string $label,
        string $name,
        int $max,
        bool $required = false
    ): ?int {
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidArgumentException("The {$label} {$name} is required.");
            }

            return null;
        }

        $string = is_int($value) ? (string) $value : $value;
        if (! is_string($string) || preg_match('/\A[1-9]\d{0,17}\z/', $string) !== 1 || (int) $string > $max) {
            throw new InvalidArgumentException("The {$label} {$name} must be a positive integer of at most {$max}.");
        }

        return (int) $string;
    }
}
