<div class="mx-4 md:mx-8">
    <play-episode-button
        id="<?= $episode->id ?>"
        imageSrc="<?= $episode->cover->thumbnail_url ?>"
        title="<?= esc($episode->title) ?>"
        podcast="<?= esc($episode->podcast->title) ?>"
        src="<?= $episode->audio_web_url ?>"
        mediaType="<?= $episode->audio->file_mimetype ?>"
        playLabel="<?= lang('Common.play_episode_button.play') ?>"
        playingLabel="<?= lang('Common.play_episode_button.playing') ?>"></play-episode-button>
</div>
