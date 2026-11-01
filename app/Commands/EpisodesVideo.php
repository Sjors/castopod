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
    protected $group = 'Episodes';

    protected $name = 'episodes:video';

    protected $description = 'Set or clear the Nostr video identifier for an existing episode.';

    protected $usage = 'episodes:video --podcast HANDLE --slug SLUG (--identifier NADDR | --clear)';

    protected $options = [
        '--podcast'    => 'Podcast handle (required).',
        '--slug'       => 'Existing episode slug (required).',
        '--identifier' => 'Published naddr, nevent or note video identifier.',
        '--clear'      => 'Remove the video link and restore the audio landing page.',
    ];

    public function run(array $params): int
    {
        $handle = CLI::getOption('podcast');
        $slug = CLI::getOption('slug');
        $identifier = CLI::getOption('identifier');
        $clear = CLI::getOption('clear') !== null;
        if (
            ! is_string($handle) || $handle === '' || ! is_string($slug) || $slug === '' ||
            ($clear === is_string($identifier))
        ) {
            CLI::error($this->usage);
            return EXIT_ERROR;
        }

        $podcast = (new PodcastModel())->getPodcastByHandle($handle);
        if (! $podcast instanceof Podcast) {
            CLI::error('Podcast not found.');
            return EXIT_ERROR;
        }

        $episode = (new EpisodeModel())->where('podcast_id', $podcast->id)->where('slug', $slug)->first();
        if (! $episode instanceof Episode) {
            CLI::error('Episode not found in this podcast.');
            return EXIT_ERROR;
        }

        try {
            config('EpisodeVideo')->setIdentifier($episode->id, $clear ? null : $identifier);
        } catch (InvalidArgumentException $error) {
            CLI::error($error->getMessage());
            return EXIT_ERROR;
        }
        (new EpisodeModel())->clearCache(['id' => $episode->id]);
        CLI::write($clear ? 'Video link cleared.' : 'Video link saved.');

        return EXIT_SUCCESS;
    }
}
