<div class="mx-4 md:mx-8">
    <div style="position:relative;width:100%;padding-top:56.25%;overflow:hidden;border-radius:0.5rem;background:#000">
        <iframe
            src="<?= esc($videoUrl, 'attr') ?>"
            title="<?= esc(lang('Episode.video') . ' — ' . $episode->title, 'attr') ?>"
            style="position:absolute;top:0;left:0;width:100%;height:100%;border:0"
            allow="autoplay; fullscreen; picture-in-picture"
            allowfullscreen></iframe>
    </div>
    <p class="mt-2 text-xs text-skin-muted">(powered by <a href="https://castopod.org/" class="underline">Castopod</a> and <a href="https://nostu.be/" class="underline">Nostube</a>)</p>
</div>
