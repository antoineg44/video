<?php

declare(strict_types=1);

/**
 * Synchronisation des miniatures avec les vidéos MP4.
 *
 * - Crée les miniatures manquantes.
 * - Actualise celles dont les vidéos ont été modifiées.
 * - Supprime les miniatures orphelines.
 */

// Configuration
$videoDir = '/mnt/video';
$thumbnailDir = '/var/www/video/public/thumbnails';
$ffmpeg = '/usr/bin/ffmpeg';

$thumbnailWidth = 640;
$thumbnailPosition = 10;

// Vérification des dossiers
if (!is_dir($videoDir)) {
    exit("Erreur : dossier vidéo introuvable : $videoDir\n");
}

if (!is_dir($thumbnailDir)) {
    if (!mkdir($thumbnailDir, 0755, true)) {
        exit("Erreur : impossible de créer le dossier des miniatures.\n");
    }
}

if (!is_executable($ffmpeg)) {
    exit("Erreur : FFmpeg est introuvable : $ffmpeg\n");
}

// Recherche des vidéos
$videos = glob($videoDir . '/*.mp4');

if ($videos === false) {
    exit("Erreur lors de la recherche des vidéos.\n");
}

$expectedThumbnails = [];
$created = 0;
$updated = 0;
$unchanged = 0;
$errors = 0;
$deleted = 0;

foreach ($videos as $video) {
    $filename = pathinfo($video, PATHINFO_FILENAME);

    $thumbnail = $thumbnailDir . '/' . $filename . '.jpg';
    $expectedThumbnails[] = $thumbnail;

    $videoMtime = filemtime($video);
    $videoSize = filesize($video);

    if ($videoMtime === false || $videoSize === false) {
        echo "Erreur : impossible de lire les informations de $video\n";
        $errors++;
        continue;
    }

    /*
     * Un fichier JSON mémorise les caractéristiques
     * de la vidéo lors de la dernière génération.
     */
    $metadataFile = $thumbnailDir . '/' . $filename . '.json';

    $metadata = null;

    if (is_file($metadataFile)) {
        $json = file_get_contents($metadataFile);

        if ($json !== false) {
            $metadata = json_decode($json, true);
        }
    }

    $thumbnailExists = is_file($thumbnail) && filesize($thumbnail) > 0;

    $needsUpdate = !$thumbnailExists
        || !is_array($metadata)
        || ($metadata['mtime'] ?? null) !== $videoMtime
        || ($metadata['size'] ?? null) !== $videoSize;

    if (!$needsUpdate) {
        echo "Inchangée : $filename.jpg\n";
        $unchanged++;
        continue;
    }

    $isUpdate = $thumbnailExists;

    // Génération dans un fichier temporaire
    $temporaryThumbnail = $thumbnailDir . '/.'
        . bin2hex(random_bytes(8)) . '.jpg';

    $command = [
        $ffmpeg,
        '-hide_banner',
        '-loglevel', 'error',
        '-nostdin',
        '-y',
        '-ss', (string) $thumbnailPosition,
        '-i', $video,
        '-map', '0:v:0',
        '-frames:v', '1',
        '-vf', "scale={$thumbnailWidth}:-2",
        '-q:v', '3',
        $temporaryThumbnail
    ];

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        echo "Erreur : impossible de lancer FFmpeg pour $filename.mp4\n";
        $errors++;
        continue;
    }

    // Lecture des sorties sans fermer de flux inexistant
    $stdout = '';
    $stderr = '';

    if (isset($pipes[1]) && is_resource($pipes[1])) {
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }

    if (isset($pipes[2]) && is_resource($pipes[2])) {
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
    }

    $exitCode = proc_close($process);

    if (
        $exitCode !== 0
        || !is_file($temporaryThumbnail)
        || filesize($temporaryThumbnail) === 0
    ) {
        @unlink($temporaryThumbnail);

        echo "Échec : $filename.mp4\n";

        if ($stderr !== '') {
            echo $stderr . "\n";
        }

        $errors++;
        continue;
    }

    // Remplacement atomique de la miniature
    if (!rename($temporaryThumbnail, $thumbnail)) {
        @unlink($temporaryThumbnail);

        echo "Erreur : impossible de remplacer $filename.jpg\n";
        $errors++;
        continue;
    }

    // Enregistrement des informations de la vidéo
    $metadataContent = json_encode([
        'mtime' => $videoMtime,
        'size' => $videoSize,
        'generated_at' => date(DATE_ATOM)
    ], JSON_PRETTY_PRINT);

    if (
        $metadataContent === false
        || file_put_contents($metadataFile, $metadataContent . "\n", LOCK_EX) === false
    ) {
        echo "Attention : miniature créée, mais métadonnées non enregistrées : $filename.jpg\n";
        $errors++;
        continue;
    }

    if ($isUpdate) {
        echo "Mise à jour : $filename.jpg\n";
        $updated++;
    } else {
        echo "Création : $filename.jpg\n";
        $created++;
    }
}

// Suppression des miniatures et métadonnées orphelines
$expectedSet = array_fill_keys($expectedThumbnails, true);

foreach (glob($thumbnailDir . '/*.jpg') ?: [] as $thumbnail) {
    if (!isset($expectedSet[$thumbnail])) {
        if (@unlink($thumbnail)) {
            echo "Suppression : " . basename($thumbnail) . "\n";
            $deleted++;
        } else {
            echo "Erreur : impossible de supprimer $thumbnail\n";
            $errors++;
        }
    }
}

foreach (glob($thumbnailDir . '/*.json') ?: [] as $metadataFile) {
    $correspondingThumbnail = substr($metadataFile, 0, -5) . '.jpg';

    if (!is_file($correspondingThumbnail)) {
        if (!@unlink($metadataFile)) {
            echo "Erreur : impossible de supprimer $metadataFile\n";
            $errors++;
        }
    }
}

// Résumé
echo "\n";
echo "===== SYNCHRONISATION TERMINÉE =====\n";
echo "Vidéos détectées : " . count($videos) . "\n";
echo "Miniatures créées : $created\n";
echo "Miniatures mises à jour : $updated\n";
echo "Miniatures inchangées : $unchanged\n";
echo "Miniatures supprimées : $deleted\n";
echo "Erreurs : $errors\n";
