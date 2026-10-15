<?php

declare(strict_types=1);

/**
 * ============================================================================
 * Synchronisation des miniatures vidéo
 * ============================================================================
 *
 * Fonctionnalités :
 *
 * - Détecte les vidéos MP4.
 * - Crée les miniatures manquantes.
 * - Actualise les miniatures des vidéos modifiées.
 * - Ignore les miniatures déjà à jour.
 * - Supprime les miniatures orphelines.
 * - Enregistre les caractéristiques des vidéos dans des fichiers JSON.
 * - Enregistre les opérations et les erreurs dans un journal.
 * - Capture les erreurs PHP et les erreurs de FFmpeg.
 * - Retourne un code de sortie permettant de détecter les échecs.
 *
 * ============================================================================
 */

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

$videoDir = '/mnt/video';

$thumbnailDir = __DIR__ . '/thumbnails';

$ffmpeg = '/usr/bin/ffmpeg';

$thumbnailWidth = 640;

$thumbnailPosition = 10;

/*
|--------------------------------------------------------------------------
| Journal de synchronisation
|--------------------------------------------------------------------------
*/

$logFile = $thumbnailDir . '/update_thumbnails.log';

/*
|--------------------------------------------------------------------------
| Configuration des erreurs PHP
|--------------------------------------------------------------------------
*/

ini_set('log_errors', '1');

ini_set('display_errors', 'stderr');

error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| Fonction de journalisation
|--------------------------------------------------------------------------
*/

function journal(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] '
        . $message
        . PHP_EOL;

    // Force l'écriture du message dans la sortie standard.
    if (function_exists('ob_flush')) {
        @ob_flush();
    }

    flush();
}

/*
|--------------------------------------------------------------------------
| Gestion des erreurs PHP
|--------------------------------------------------------------------------
*/

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {

        /*
         * Respecte les erreurs masquées avec @.
         */

        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);

/*
|--------------------------------------------------------------------------
| Gestion des exceptions non interceptées
|--------------------------------------------------------------------------
*/

set_exception_handler(
    static function (Throwable $exception): void {

        journal(
            'ERREUR FATALE : '
            . $exception->getMessage()
        );

        journal(
            'Fichier : '
            . $exception->getFile()
            . ' | Ligne : '
            . $exception->getLine()
        );

        journal(
            'Trace : '
            . str_replace(
                PHP_EOL,
                ' | ',
                $exception->getTraceAsString()
            )
        );

        exit(1);
    }
);

/*
|--------------------------------------------------------------------------
| Capture des erreurs fatales
|--------------------------------------------------------------------------
*/

register_shutdown_function(
    static function (): void {

        $erreur = error_get_last();

        if ($erreur === null) {
            return;
        }

        $typesFatals = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        ];

        if (!in_array(
            $erreur['type'],
            $typesFatals,
            true
        )) {
            return;
        }

        journal(
            'ERREUR PHP FATALE : '
            . $erreur['message']
        );

        journal(
            'Fichier : '
            . $erreur['file']
            . ' | Ligne : '
            . $erreur['line']
        );
    }
);

/*
|--------------------------------------------------------------------------
| Début de la synchronisation
|--------------------------------------------------------------------------
*/

journal('========================================');
journal('DÉBUT DE LA SYNCHRONISATION');
journal('========================================');

journal('Répertoire vidéo : ' . $videoDir);

journal('Répertoire miniatures : ' . $thumbnailDir);

journal('Exécutable FFmpeg : ' . $ffmpeg);

/*
|--------------------------------------------------------------------------
| Vérification de l'environnement
|--------------------------------------------------------------------------
*/

/*
 * Vérification du répertoire vidéo.
 */

if (!is_dir($videoDir)) {

    journal(
        'ERREUR : répertoire vidéo introuvable : '
        . $videoDir
    );

    exit(1);
}

if (!is_readable($videoDir)) {

    journal(
        'ERREUR : répertoire vidéo inaccessible en lecture : '
        . $videoDir
    );

    exit(1);
}

/*
 * Vérification de FFmpeg.
 */

if (!is_file($ffmpeg) || !is_executable($ffmpeg)) {

    journal(
        'ERREUR : FFmpeg est introuvable ou inexécutable : '
        . $ffmpeg
    );

    exit(1);
}

/*
|--------------------------------------------------------------------------
| Création du répertoire des miniatures
|--------------------------------------------------------------------------
*/

if (!is_dir($thumbnailDir)) {

    if (!mkdir($thumbnailDir, 0755, true)) {

        journal(
            'ERREUR : impossible de créer le répertoire : '
            . $thumbnailDir
        );

        exit(1);
    }

    journal(
        'Répertoire des miniatures créé.'
    );
}

if (!is_writable($thumbnailDir)) {

    journal(
        'ERREUR : répertoire des miniatures inaccessible en écriture.'
    );

    exit(1);
}

/*
|--------------------------------------------------------------------------
| Configuration du fichier journal PHP
|--------------------------------------------------------------------------
*/

if (ini_set('error_log', $logFile) === false) {

    journal(
        'ATTENTION : impossible de configurer le journal des erreurs PHP.'
    );
}

/*
|--------------------------------------------------------------------------
| Protection contre les exécutions simultanées
|--------------------------------------------------------------------------
*/

$lockFile = $thumbnailDir . '/update_thumbnails.lock';

$lockHandle = fopen($lockFile, 'c');

if ($lockHandle === false) {

    journal(
        'ERREUR : impossible de créer le fichier de verrouillage.'
    );

    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {

    journal(
        'ERREUR : une synchronisation est déjà en cours.'
    );

    fclose($lockHandle);

    exit(1);
}

/*
|--------------------------------------------------------------------------
| Recherche des vidéos
|--------------------------------------------------------------------------
*/

journal('Recherche des vidéos MP4...');

$videos = glob($videoDir . '/*.mp4');

if ($videos === false) {

    journal(
        'ERREUR : impossible de rechercher les vidéos.'
    );

    exit(1);
}

/*
 * Évite de supprimer toutes les miniatures si aucune vidéo
 * n'est détectée.
 */

if (count($videos) === 0) {

    journal(
        'ATTENTION : aucune vidéo MP4 détectée.'
    );

    journal(
        'Aucune miniature ne sera supprimée.'
    );

    journal(
        'Vérifie le répertoire vidéo et les extensions des fichiers.'
    );

    journal('FIN DE LA SYNCHRONISATION.');

    exit(0);
}

/*
|--------------------------------------------------------------------------
| Initialisation des compteurs
|--------------------------------------------------------------------------
*/

$expectedThumbnails = [];

$created = 0;

$updated = 0;

$unchanged = 0;

$errors = 0;

$deleted = 0;

$videoCount = count($videos);

/*
|--------------------------------------------------------------------------
| Traitement des vidéos
|--------------------------------------------------------------------------
*/

journal(
    $videoCount . ' vidéo(s) détectée(s).'
);

journal('Début du traitement des miniatures.');

foreach ($videos as $index => $video) {

    /*
    |--------------------------------------------------------------------------
    | Informations sur la vidéo
    |--------------------------------------------------------------------------
    */

    $filename = pathinfo(
        $video,
        PATHINFO_FILENAME
    );

    $thumbnail = $thumbnailDir
        . '/'
        . $filename
        . '.jpg';

    $metadataFile = $thumbnailDir
        . '/'
        . $filename
        . '.json';

    /*
     * La miniature est considérée comme attendue, même si
     * le traitement de la vidéo échoue.
     */

    $expectedThumbnails[] = $thumbnail;

    journal(
        '['
        . ($index + 1)
        . '/'
        . $videoCount
        . '] Traitement : '
        . basename($video)
    );

    /*
    |--------------------------------------------------------------------------
    | Vérification des caractéristiques de la vidéo
    |--------------------------------------------------------------------------
    */

    clearstatcache(true, $video);

    $videoMtime = filemtime($video);

    $videoSize = filesize($video);

    if (
        $videoMtime === false
        || $videoSize === false
    ) {

        journal(
            'ERREUR : impossible de lire les informations de la vidéo : '
            . $video
        );

        $errors++;

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Lecture des métadonnées existantes
    |--------------------------------------------------------------------------
    */

    $metadata = null;

    if (is_file($metadataFile)) {

        $json = file_get_contents($metadataFile);

        if ($json === false) {

            journal(
                'ATTENTION : impossible de lire les métadonnées : '
                . basename($metadataFile)
            );

        } else {

            $metadata = json_decode(
                $json,
                true
            );

            if (!is_array($metadata)) {

                journal(
                    'ATTENTION : métadonnées invalides : '
                    . basename($metadataFile)
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Vérification de la miniature existante
    |--------------------------------------------------------------------------
    */

    clearstatcache(true, $thumbnail);

    $thumbnailExists = (
        is_file($thumbnail)
        && filesize($thumbnail) !== false
        && filesize($thumbnail) > 0
    );

    /*
    |--------------------------------------------------------------------------
    | Détermination de la nécessité d'une mise à jour
    |--------------------------------------------------------------------------
    */

    $metadataMtime = $metadata['mtime'] ?? null;

    $metadataSize = $metadata['size'] ?? null;

    /*
     * Conversion en entier pour éviter les différences
     * entre les types numériques du JSON et de PHP.
     */

    $metadataValide = (
        is_array($metadata)
        && is_numeric($metadataMtime)
        && is_numeric($metadataSize)
    );

    $needsUpdate = (
        !$thumbnailExists
        || !$metadataValide
        || (int) $metadataMtime !== $videoMtime
        || (int) $metadataSize !== $videoSize
    );

    /*
    |--------------------------------------------------------------------------
    | Miniature déjà à jour
    |--------------------------------------------------------------------------
    */

    if (!$needsUpdate) {

        journal(
            'INCHANGÉE : '
            . basename($thumbnail)
        );

        $unchanged++;

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Détermination du type de traitement
    |--------------------------------------------------------------------------
    */

    $isUpdate = $thumbnailExists;

    if ($isUpdate) {

        journal(
            'Actualisation de la miniature : '
            . basename($thumbnail)
        );

    } else {

        journal(
            'Création de la miniature : '
            . basename($thumbnail)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fichier temporaire
    |--------------------------------------------------------------------------
    */

    try {

        $temporaryName = bin2hex(
            random_bytes(16)
        );

    } catch (Throwable $exception) {

        journal(
            'ERREUR : impossible de générer un nom temporaire : '
            . $exception->getMessage()
        );

        $errors++;

        continue;
    }

    $temporaryThumbnail = $thumbnailDir
        . '/.'
        . $temporaryName
        . '.jpg';

    /*
    |--------------------------------------------------------------------------
    | Construction de la commande FFmpeg
    |--------------------------------------------------------------------------
    */

    $command = [
        $ffmpeg,
        '-hide_banner',
        '-loglevel',
        'error',
        '-nostdin',
        '-y',
        '-ss',
        (string) $thumbnailPosition,
        '-i',
        $video,
        '-map',
        '0:v:0',
        '-frames:v',
        '1',
        '-vf',
        'scale=' . $thumbnailWidth . ':-2',
        '-q:v',
        '3',
        $temporaryThumbnail,
    ];

    /*
    |--------------------------------------------------------------------------
    | Descripteurs du processus
    |--------------------------------------------------------------------------
    */

    $descriptors = [
        0 => [
            'file',
            '/dev/null',
            'r',
        ],

        1 => [
            'file',
            '/dev/null',
            'w',
        ],

        2 => [
            'pipe',
            'w',
        ],
    ];

    /*
    |--------------------------------------------------------------------------
    | Lancement de FFmpeg
    |--------------------------------------------------------------------------
    */

    try {

        $process = proc_open(
            $command,
            $descriptors,
            $pipes
        );

    } catch (Throwable $exception) {

        @unlink($temporaryThumbnail);

        journal(
            'ERREUR : impossible de démarrer FFmpeg pour '
            . basename($video)
            . ' : '
            . $exception->getMessage()
        );

        $errors++;

        continue;
    }

    if (!is_resource($process)) {

        @unlink($temporaryThumbnail);

        journal(
            'ERREUR : impossible de lancer FFmpeg pour '
            . basename($video)
        );

        $errors++;

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Récupération des erreurs FFmpeg
    |--------------------------------------------------------------------------
    |
    | La sortie d'erreur est lue progressivement pour éviter
    | de bloquer FFmpeg si son tampon de sortie est rempli.
    |
    */

    $stderr = '';

    if (
        isset($pipes[2])
        && is_resource($pipes[2])
    ) {

        while (!feof($pipes[2])) {

            $lecture = [
                $pipes[2],
            ];

            $ecriture = null;

            $exceptions = null;

            $nombre = @stream_select(
                $lecture,
                $ecriture,
                $exceptions,
                1
            );

            if ($nombre === false) {

                $stderr .= stream_get_contents(
                    $pipes[2]
                );

                break;
            }

            if ($nombre === 0) {
                continue;
            }

            $morceau = fread(
                $pipes[2],
                8192
            );

            if ($morceau === false) {
                break;
            }

            $stderr .= $morceau;
        }

        fclose($pipes[2]);
    }

    /*
    |--------------------------------------------------------------------------
    | Récupération du code de sortie
    |--------------------------------------------------------------------------
    */

    $exitCode = proc_close($process);

    /*
    |--------------------------------------------------------------------------
    | Vérification du résultat de FFmpeg
    |--------------------------------------------------------------------------
    */

    clearstatcache(
        true,
        $temporaryThumbnail
    );

    $temporaryExists = (
        is_file($temporaryThumbnail)
        && filesize($temporaryThumbnail) !== false
        && filesize($temporaryThumbnail) > 0
    );

    if (
        $exitCode !== 0
        || !$temporaryExists
    ) {

        @unlink($temporaryThumbnail);

        journal(
            'ERREUR FFmpeg : échec du traitement de '
            . basename($video)
        );

        journal(
            'Code de retour FFmpeg : '
            . $exitCode
        );

        if (trim($stderr) !== '') {

            journal(
                'Détails de FFmpeg :'
            );

            foreach (
                preg_split(
                    '/\r\n|\r|\n/',
                    trim($stderr)
                ) ?: [] as $ligne
            ) {

                journal(
                    'FFmpeg : ' . $ligne
                );
            }

        } else {

            journal(
                'FFmpeg n\'a fourni aucun détail supplémentaire.'
            );
        }

        $errors++;

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Remplacement de la miniature
    |--------------------------------------------------------------------------
    |
    | Le fichier temporaire se trouve dans le même répertoire
    | que la miniature définitive.
    |
    */

    if (!rename(
        $temporaryThumbnail,
        $thumbnail
    )) {

        @unlink($temporaryThumbnail);

        journal(
            'ERREUR : impossible de remplacer la miniature : '
            . $thumbnail
        );

        $errors++;

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Enregistrement des métadonnées
    |--------------------------------------------------------------------------
    */

    $metadataContent = json_encode(
        [
            'mtime' => $videoMtime,
            'size' => $videoSize,
            'generated_at' => date(DATE_ATOM),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if ($metadataContent === false) {

        journal(
            'ERREUR : impossible de générer les métadonnées JSON : '
            . basename($metadataFile)
        );

        $errors++;

        continue;
    }

    $metadataWritten = file_put_contents(
        $metadataFile,
        $metadataContent . PHP_EOL,
        LOCK_EX
    );

    if ($metadataWritten === false) {

        journal(
            'ERREUR : miniature créée, mais impossible '
            . 'd\'enregistrer les métadonnées : '
            . basename($metadataFile)
        );

        $errors++;

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Comptabilisation du résultat
    |--------------------------------------------------------------------------
    */

    if ($isUpdate) {

        journal(
            'MISE À JOUR RÉUSSIE : '
            . basename($thumbnail)
        );

        $updated++;

    } else {

        journal(
            'CRÉATION RÉUSSIE : '
            . basename($thumbnail)
        );

        $created++;
    }
}

/*
|--------------------------------------------------------------------------
| Suppression des miniatures orphelines
|--------------------------------------------------------------------------
*/

journal('Vérification des miniatures orphelines...');

/*
 * Tableau permettant de vérifier rapidement si une miniature
 * correspond à une vidéo existante.
 */

$expectedSet = array_fill_keys(
    $expectedThumbnails,
    true
);

/*
|--------------------------------------------------------------------------
| Suppression des fichiers JPG orphelins
|--------------------------------------------------------------------------
*/

$existingThumbnails = glob(
    $thumbnailDir . '/*.jpg'
);

if ($existingThumbnails === false) {

    journal(
        'ERREUR : impossible de rechercher les miniatures JPG.'
    );

    $errors++;

    $existingThumbnails = [];
}

foreach ($existingThumbnails as $thumbnail) {

    if (isset($expectedSet[$thumbnail])) {
        continue;
    }

    /*
     * Évite de supprimer une miniature si le fichier correspondant
     * est temporairement inaccessible ou a été supprimé pendant
     * la synchronisation.
     */

    if (@unlink($thumbnail)) {

        journal(
            'SUPPRESSION : '
            . basename($thumbnail)
        );

        $deleted++;

    } else {

        journal(
            'ERREUR : impossible de supprimer la miniature : '
            . $thumbnail
        );

        $errors++;
    }
}

/*
|--------------------------------------------------------------------------
| Suppression des métadonnées orphelines
|--------------------------------------------------------------------------
*/

$existingMetadata = glob(
    $thumbnailDir . '/*.json'
);

if ($existingMetadata === false) {

    journal(
        'ERREUR : impossible de rechercher les fichiers JSON.'
    );

    $errors++;

    $existingMetadata = [];
}

foreach ($existingMetadata as $metadataFile) {

    $correspondingThumbnail = substr(
        $metadataFile,
        0,
        -5
    ) . '.jpg';

    if (is_file($correspondingThumbnail)) {
        continue;
    }

    if (@unlink($metadataFile)) {

        journal(
            'MÉTADONNÉES ORPHELINES SUPPRIMÉES : '
            . basename($metadataFile)
        );

    } else {

        journal(
            'ERREUR : impossible de supprimer les métadonnées : '
            . $metadataFile
        );

        $errors++;
    }
}

/*
|--------------------------------------------------------------------------
| Résumé final
|--------------------------------------------------------------------------
*/

journal('');
journal('========================================');
journal('RÉSUMÉ DE LA SYNCHRONISATION');
journal('========================================');

journal(
    'Vidéos détectées : '
    . $videoCount
);

journal(
    'Miniatures créées : '
    . $created
);

journal(
    'Miniatures mises à jour : '
    . $updated
);

journal(
    'Miniatures inchangées : '
    . $unchanged
);

journal(
    'Miniatures supprimées : '
    . $deleted
);

journal(
    'Erreurs : '
    . $errors
);

journal('========================================');

/*
|--------------------------------------------------------------------------
| Résultat final
|--------------------------------------------------------------------------
*/

if ($errors > 0) {

    journal(
        'SYNCHRONISATION TERMINÉE AVEC DES ERREURS.'
    );

    journal(
        'Consulte le journal pour obtenir les détails.'
    );

    flock(
        $lockHandle,
        LOCK_UN
    );

    fclose($lockHandle);

    exit(1);
}

journal(
    'SYNCHRONISATION TERMINÉE AVEC SUCCÈS.'
);

flock(
    $lockHandle,
    LOCK_UN
);

fclose($lockHandle);

exit(0);
