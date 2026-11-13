<?php

declare(strict_types=1);

session_start();

require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

/*
|--------------------------------------------------------------------------
| Authentification
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['utilisateur_id'])) {
    http_response_code(401);
    exit('Vous devez être connecté.');
}

/*
|--------------------------------------------------------------------------
| Vérification des droits
|--------------------------------------------------------------------------
*/

try {

    $autorise = utilisateurAAccesService(
        $pdo,
        (int) $_SESSION['utilisateur_id'],
        'video'
    );

} catch (Throwable $exception) {

    error_log(
        'Erreur droits téléchargement vidéo : '
        . $exception->getMessage()
    );

    http_response_code(500);

    exit('Erreur lors de la vérification des autorisations.');
}

if (!$autorise) {
    http_response_code(403);
    exit('Accès non autorisé.');
}

/*
|--------------------------------------------------------------------------
| Libération immédiate de la session
|--------------------------------------------------------------------------
|
| IMPORTANT :
| Empêche le téléchargement de bloquer les autres pages
| ouvertes par le même utilisateur.
|
*/

session_write_close();

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

$repertoireVideos = '/mnt/video';

$extensionsAutorisees = [
    'mp4',
    'webm',
    'ogg',
];

/*
|--------------------------------------------------------------------------
| Récupération du nom du fichier
|--------------------------------------------------------------------------
*/

$nomFichier = $_GET['f'] ?? '';

if (
    !is_string($nomFichier)
    || $nomFichier === ''
    || basename($nomFichier) !== $nomFichier
    || str_contains($nomFichier, "\0")
) {
    http_response_code(400);
    exit('Nom de fichier invalide.');
}

/*
|--------------------------------------------------------------------------
| Vérification de l'extension
|--------------------------------------------------------------------------
*/

$extension = strtolower(
    pathinfo(
        $nomFichier,
        PATHINFO_EXTENSION
    )
);

if (!in_array(
    $extension,
    $extensionsAutorisees,
    true
)) {
    http_response_code(403);
    exit('Type de fichier non autorisé.');
}

/*
|--------------------------------------------------------------------------
| Vérification du fichier
|--------------------------------------------------------------------------
*/

$chemin = $repertoireVideos . '/' . $nomFichier;

if (
    !is_file($chemin)
    || !is_readable($chemin)
) {
    http_response_code(404);
    exit('Vidéo introuvable.');
}

/*
|--------------------------------------------------------------------------
| Ouverture du fichier
|--------------------------------------------------------------------------
*/

$handle = fopen($chemin, 'rb');

if ($handle === false) {

    http_response_code(500);

    exit('Impossible d\'ouvrir la vidéo.');
}

/*
|--------------------------------------------------------------------------
| Récupération de la taille
|--------------------------------------------------------------------------
*/

$taille = fstat($handle);

if (
    $taille === false
    || !isset($taille['size'])
) {

    fclose($handle);

    http_response_code(500);

    exit('Impossible de lire la taille du fichier.');
}

$tailleFichier = (int) $taille['size'];

/*
|--------------------------------------------------------------------------
| Configuration du transfert
|--------------------------------------------------------------------------
*/

// Évite une limite de temps PHP pendant le téléchargement.
set_time_limit(0);

// Permet de terminer proprement après une déconnexion.
ignore_user_abort(true);

// Taille des blocs : 1 Mo.
$tailleBloc = 1024 * 1024;

/*
|--------------------------------------------------------------------------
| Nettoyage des buffers PHP
|--------------------------------------------------------------------------
*/

while (ob_get_level() > 0) {

    if (!ob_end_clean()) {
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Préparation des en-têtes HTTP
|--------------------------------------------------------------------------
*/

header('Content-Type: application/octet-stream');

header(
    'Content-Disposition: attachment; filename="'
    . addcslashes($nomFichier, "\\\"")
    . '"; filename*=UTF-8\'\''
    . rawurlencode($nomFichier)
);

header('Content-Length: ' . $tailleFichier);

header('Cache-Control: private, no-store');

header('X-Content-Type-Options: nosniff');

header('X-Accel-Buffering: no');

/*
|--------------------------------------------------------------------------
| Transmission du fichier par blocs
|--------------------------------------------------------------------------
*/

try {

    while (!feof($handle)) {

        /*
        |--------------------------------------------------------------------------
        | Vérification de la connexion
        |--------------------------------------------------------------------------
        */

        if (connection_aborted()) {
            break;
        }

        /*
        |--------------------------------------------------------------------------
        | Lecture d'un bloc
        |--------------------------------------------------------------------------
        */

        $bloc = fread(
            $handle,
            $tailleBloc
        );

        if ($bloc === false) {

            error_log(
                'Erreur lecture téléchargement : '
                . $nomFichier
            );

            break;
        }

        if ($bloc === '') {

            if (feof($handle)) {
                break;
            }

            error_log(
                'Lecture interrompue : '
                . $nomFichier
            );

            break;
        }

        /*
        |--------------------------------------------------------------------------
        | Envoi au navigateur
        |--------------------------------------------------------------------------
        */

        echo $bloc;

        flush();

        /*
        |--------------------------------------------------------------------------
        | Vérification après transmission
        |--------------------------------------------------------------------------
        */

        if (connection_aborted()) {
            break;
        }
    }

} catch (Throwable $exception) {

    error_log(
        'Erreur transmission vidéo : '
        . $exception->getMessage()
    );

} finally {

    /*
    |--------------------------------------------------------------------------
    | Libération des ressources
    |--------------------------------------------------------------------------
    */

    if (is_resource($handle)) {
        fclose($handle);
    }
}

exit;
