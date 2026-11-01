<?= $this->extend('episode/_layout') ?>

<?= $this->section('content') ?>

<?= view('episode/_partials/audio_player', ['episode' => $episode]) ?>

<?= $this->endSection() ?>
