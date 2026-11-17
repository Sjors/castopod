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
     * @var array<string, string>
     */
    private const MP4_OPTIONS = [
        'mp4-url'     => 'sources',
        'mp4-sha256'  => 'sha256',
        'mp4-size'    => 'length',
        'mp4-type'    => 'type',
        'mp4-height'  => 'height',
        'mp4-width'   => 'width',
        'mp4-bitrate' => 'bitrate',
        'mp4-codecs'  => 'codecs',
    ];

    protected $group = 'Episodes';

    protected $name = 'episodes:video';

    protected $description = 'Set, clear or show the Nostr video identifier and MP4 alternate enclosure of an existing episode.';

    protected $usage = 'episodes:video --podcast HANDLE --slug SLUG (--identifier NADDR [--mp4-url URL[,MIRROR...] --mp4-sha256 HEX --mp4-size BYTES [--mp4-type video/mp4] [--mp4-height PX] [--mp4-width PX] [--mp4-bitrate BPS] [--mp4-codecs CODECS]] | --clear | --show)';

    protected $options = [
        '--podcast'     => 'Podcast handle (required).',
        '--slug'        => 'Existing episode slug (required).',
        '--identifier'  => 'Published naddr, nevent or note video identifier. Without --mp4-* options, an MP4 enclosure is kept only if it was saved for this same identifier.',
        '--clear'       => 'Remove the video link and MP4 enclosure and restore the audio landing page.',
        '--show'        => 'Print the stored identifier and bound MP4 enclosure as JSON; writes nothing.',
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
        $known = ['podcast', 'slug', 'identifier', 'clear', 'show', ...array_keys(self::MP4_OPTIONS)];
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
        $mp4 = [];
        foreach (self::MP4_OPTIONS as $option => $field) {
            if (array_key_exists($option, $params)) {
                $value = $params[$option];
                if (! is_string($value) || $value === '') {
                    CLI::error("--{$option} requires a value.");
                    return EXIT_ERROR;
                }

                $mp4[$field] = $field === 'sources' ? explode(',', $value) : $value;
            }
        }

        if (
            ! is_string($handle) || $handle === '' || ! is_string($slug) || $slug === '' ||
            ((int) $clear + (int) $show + (int) array_key_exists('identifier', $params)) !== 1 ||
            (array_key_exists('identifier', $params) && ! is_string($identifier)) ||
            ($mp4 !== [] && ! is_string($identifier))
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
            if ($mp4 !== []) {
                // Validates the identifier and every MP4 field before writing anything.
                $config->setEnclosure($episode->id, (string) $identifier, $mp4);
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
        CLI::write(match (true) {
            $clear                                       => 'Video link and MP4 enclosure cleared.',
            $mp4 !== []                                  => 'Video link and MP4 enclosure saved.',
            $config->getEnclosure($episode->id) !== null => 'Video link saved; MP4 enclosure kept for this identifier.',
            default                                      => 'Video link saved; no MP4 enclosure.',
        });

        return EXIT_SUCCESS;
    }
}
