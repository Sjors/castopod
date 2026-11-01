<?= $this->extend('episode/_layout') ?>

<?= $this->section('content') ?>

<?= view('episode/_partials/video_player', ['episode' => $episode, 'videoUrl' => $videoUrl]) ?>

<?= $this->endSection() ?>
