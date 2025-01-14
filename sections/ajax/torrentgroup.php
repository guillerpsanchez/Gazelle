<?php

$json     = new Gazelle\Json\TGroup;
$tgMan    = new Gazelle\Manager\TGroup;
$groupId  = (int)$_GET['id'] ?? 0;
$infohash = $_GET['hash'] ?? null;

if ($groupId && $infohash) {
    $json->failure('bad parameters');
    exit;
}

$tgroup = $infohash
    ? $tgMan->findByTorrentInfohash($infohash)
    : $tgMan->findById($groupId);

if (is_null($tgroup)) {
    $json->failure('bad parameters');
    exit;
}

$json->setVersion(2)
    ->setTGroup($tgroup)
    ->setViewer($Viewer)
    ->emit();
