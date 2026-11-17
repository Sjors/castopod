<?= $this->extend('episode/_layout') ?>

<?= $this->section('content') ?>
<?php // The audio player is in the episode header; without federation there is no comment thread to show.?>
<?= $this->endSection() ?>
