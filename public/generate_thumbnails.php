<?php

declare(strict_types=1);

// Configuration
$videoDir = '/mnt/video';
$thumbnailDir = '/var/www/video/public/thumbnails';

$ffmpeg = '/usr/bin/ffmpeg';
$timePosition = 3; // Image extraite à 3 secondes
$width = 640;

// Vérification des dossiers
if (!is_dir($videoDir) || !is_dir($thumbnailDir)) {
    exit("Erreur : dossier vidéo ou miniature introuvable.\n");
}

if (!is_executable($ffmpeg)) {
    exit("Erreur : FFmpeg est introuvable ou inaccessible.\n");
}

// Recherche des fichiers MP4
$videos = glob($videoDir . '/*.mp4');

if ($videos === false || count($videos) === 0) {
    exit("Aucune vidéo MP4 trouvée.\n");
}

foreach ($videos as $video) {

    $filename = pathinfo($video, PATHINFO_FILENAME);
    $thumbnail = $thumbnailDir . '/' . $filename . '.jpg';

    // Ne pas régénérer une miniature existante
    if (is_file($thumbnail)) {
        echo "Miniature déjà présente : $filename.jpg\n";
        continue;
    }

    // Extraction de l'image avec FFmpeg
    $command = [
        $ffmpeg,
        '-hide_banner',
        '-loglevel', 'error',
        '-nostdin',
        '-y',
        '-ss', (string) $timePosition,
        '-i', $video,
        '-frames:v', '1',
        '-vf', "scale={$width}:-2",
        '-q:v', '3',
        $thumbnail
    ];

    
$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
];

$process = proc_open($command, $descriptors, $pipes);

if (!is_resource($process)) {
    echo "Erreur : impossible de lancer FFmpeg pour $filename.mp4\n";
    continue;
}

// Lecture sécurisée de la sortie standard
$stdout = '';

if (isset($pipes[1]) && is_resource($pipes[1])) {
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
}

// Lecture sécurisée des erreurs
$stderr = '';

if (isset($pipes[2]) && is_resource($pipes[2])) {
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
}

$exitCode = proc_close($process);

    if ($exitCode === 0 && is_file($thumbnail) && filesize($thumbnail) > 0) {
        echo "Miniature créée : $filename.jpg\n";
    } else {
        // Suppression d'une éventuelle image incomplète
        if (is_file($thumbnail)) {
            unlink($thumbnail);
        }

        echo "Échec pour $filename.mp4 : $stderr\n";
    }
}

echo "Traitement terminé.\n";
