<?php

declare(strict_types=1);

namespace App\Commands;

use App\Entities\Episode;
use App\Entities\Podcast;
use App\Models\EpisodeModel;
use App\Models\PodcastModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/** Set metadata after publishing a video, without changing the audio episode. */
class EpisodesVideo extends BaseCommand
{
    /**
     * Option suffix => record field, for both the --hls-* and the --mp4-* options.
     *
     * @var array<string, string>
     */
    private const MEDIA_OPTIONS = [
        'url'     => 'sources',
        'sha256'  => 'sha256',
        'size'    => 'length',
        'type'    => 'type',
        'height'  => 'height',
        'width'   => 'width',
        'bitrate' => 'bitrate',
        'codecs'  => 'codecs',
    ];

    protected $group = 'Episodes';

    protected $name = 'episodes:video';

    protected $description = 'Set, clear or show the Nostr video identifier and HLS/MP4 alternate enclosures of an existing episode.';

    protected $usage = 'episodes:video --podcast HANDLE --slug SLUG (--identifier NADDR [--hls-url URL[,MIRROR...] --hls-sha256 HEX --hls-size BYTES [--hls-type application/x-mpegURL] [--hls-height PX] [--hls-width PX] [--hls-bitrate BPS] [--hls-codecs CODECS]] [--mp4-url URL[,MIRROR...] --mp4-sha256 HEX --mp4-size BYTES [--mp4-type video/mp4] [--mp4-height PX] [--mp4-width PX] [--mp4-bitrate BPS] [--mp4-codecs CODECS]] | --clear | --show)';

    protected $options = [
        '--podcast'     => 'Podcast handle (required).',
        '--slug'        => 'Existing episode slug (required).',
        '--identifier'  => 'Published naddr, nevent or note video identifier. With --hls-* and/or --mp4-* options, they replace the stored HLS and MP4 enclosures; without them, the enclosures are kept only if they were saved for this same identifier.',
        '--clear'       => 'Remove the video link and HLS/MP4 enclosures and restore the audio landing page.',
        '--show'        => 'Print the stored identifier and bound HLS/MP4 enclosures as JSON; writes nothing.',
        '--hls-url'     => 'Comma-separated https:// Blossom URLs of the HLS master playlist, primary first, max 5.',
        '--hls-sha256'  => 'Lowercase hex sha256 of the master playlist; each URL path must end with it.',
        '--hls-size'    => 'Master playlist size in bytes.',
        '--hls-type'    => 'HLS MIME type: application/x-mpegURL (the default) or application/vnd.apple.mpegurl, stored as application/x-mpegURL.',
        '--hls-height'  => 'Height of the highest rendition in pixels (optional).',
        '--hls-width'   => 'Width of the highest rendition in pixels (optional; stored, not emitted).',
        '--hls-bitrate' => 'Bitrate in bits per second (optional).',
        '--hls-codecs'  => 'RFC 6381 codecs, e.g. "av01.0.05M.08,mp4a.40.2" (optional).',
        '--mp4-url'     => 'Comma-separated https:// Blossom URLs of the MP4, primary first, max 5.',
        '--mp4-sha256'  => 'Lowercase hex sha256 of the MP4; each URL path must end with it.',
        '--mp4-size'    => 'MP4 size in bytes.',
        '--mp4-type'    => 'MP4 MIME type; only video/mp4 (the default) is accepted.',
        '--mp4-height'  => 'Video height in pixels (optional).',
        '--mp4-width'   => 'Video width in pixels (optional; stored, not emitted).',
        '--mp4-bitrate' => 'Average bitrate in bits per second (optional).',
        '--mp4-codecs'  => 'RFC 6381 codecs, e.g. "avc1.64001f,mp4a.40.2" (optional).',
    ];

    public function run(array $params): int
    {
        $known = ['podcast', 'slug', 'identifier', 'clear', 'show'];
        foreach (['hls', 'mp4'] as $kind) {
            foreach (array_keys(self::MEDIA_OPTIONS) as $suffix) {
                $known[] = "{$kind}-{$suffix}";
            }
        }

        $unexpected = array_diff(array_keys($params), $known);
        if ($unexpected !== []) {
            CLI::error('Unexpected argument: ' . implode(', ', array_map(
                static fn (int|string $key): string => is_int($key) ? (string) $params[$key] : '--' . $key,
                $unexpected,
            )));
            CLI::error($this->usage);
            return EXIT_ERROR;
        }

        $handle = $params['podcast'] ?? null;
        $slug = $params['slug'] ?? null;
        $identifier = $params['identifier'] ?? null;
        $clear = array_key_exists('clear', $params);
        $show = array_key_exists('show', $params);
        $media = [
            'hls' => [],
            'mp4' => [],
        ];
        foreach (array_keys($media) as $kind) {
            foreach (self::MEDIA_OPTIONS as $suffix => $field) {
                $option = "{$kind}-{$suffix}";
                if (array_key_exists($option, $params)) {
                    $value = $params[$option];
                    if (! is_string($value) || $value === '') {
                        CLI::error("--{$option} requires a value.");
                        return EXIT_ERROR;
                    }

                    $media[$kind][$field] = $field === 'sources' ? explode(',', $value) : $value;
                }
            }
        }

        $hasMedia = $media['hls'] !== [] || $media['mp4'] !== [];

        if (
            ! is_string($handle) || $handle === '' || ! is_string($slug) || $slug === '' ||
            ((int) $clear + (int) $show + (int) array_key_exists('identifier', $params)) !== 1 ||
            (array_key_exists('identifier', $params) && ! is_string($identifier)) ||
            ($hasMedia && ! is_string($identifier))
        ) {
            CLI::error($this->usage);
            return EXIT_ERROR;
        }

        $podcast = (new PodcastModel())->getPodcastByHandle($handle);
        if (! $podcast instanceof Podcast) {
            CLI::error('Podcast not found.');
            return EXIT_ERROR;
        }

        $episode = (new EpisodeModel())->where('podcast_id', $podcast->id)
            ->where('slug', $slug)
            ->first();
        if (! $episode instanceof Episode) {
            CLI::error('Episode not found in this podcast.');
            return EXIT_ERROR;
        }

        $config = config('EpisodeVideo');
        if ($show) {
            CLI::write((string) json_encode([
                'identifier' => $config->getIdentifier($episode->id),
                'enclosure'  => $config->getEnclosure($episode->id),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return EXIT_SUCCESS;
        }

        try {
            if ($hasMedia) {
                // Validates the identifier and every HLS/MP4 field before writing anything, then replaces both.
                $config->setEnclosure($episode->id, (string) $identifier, [
                    'hls' => $media['hls'] === [] ? null : $media['hls'],
                    'mp4' => $media['mp4'] === [] ? null : $media['mp4'],
                ]);
            } else {
                $config->setIdentifier($episode->id, $clear ? null : $identifier);
            }
        } catch (InvalidArgumentException $invalidArgumentException) {
            CLI::error($invalidArgumentException->getMessage());
            return EXIT_ERROR;
        }

        (new EpisodeModel())->clearCache([
            'id' => $episode->id,
        ]);
        $saved = implode(', ', array_map(strtoupper(...), array_keys(array_filter($media))));
        $kept = ! $clear && ! $hasMedia && $config->getEnclosure($episode->id) !== null;
        CLI::write(match (true) {
            $clear    => 'Video link and alternate enclosures cleared.',
            $hasMedia => "Video link and alternate enclosures saved: {$saved}; any previous set replaced.",
            $kept     => 'Video link saved; alternate enclosures kept for this identifier.',
            default   => 'Video link saved; no alternate enclosure.',
        });

        return EXIT_SUCCESS;
    }
}
