<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration de session
|--------------------------------------------------------------------------
*/

$isHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

/*
|--------------------------------------------------------------------------
| Chargement des dépendances
|--------------------------------------------------------------------------
*/

require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

/*
|--------------------------------------------------------------------------
| Vérification de la connexion
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['utilisateur_id'])) {
    http_response_code(401);
    exit('Vous devez être connecté.');
}

/*
|--------------------------------------------------------------------------
| Vérification des autorisations
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
        'Erreur de vérification des droits vidéo : '
        . $exception->getMessage()
    );

    http_response_code(500);
    exit('Impossible de vérifier vos autorisations.');
}

if (!$autorise) {
    http_response_code(403);
    exit('Accès non autorisé.');
}

/*
|--------------------------------------------------------------------------
| Libération du verrou de session
|--------------------------------------------------------------------------
|
| IMPORTANT :
| On libère le verrou après les vérifications d'authentification
| et d'autorisation, mais avant le transfert de la vidéo.
|
| Cela évite qu'une lecture longue bloque les autres requêtes PHP
| utilisant la même session, notamment index.php.
|
*/

session_write_close();

/*
|--------------------------------------------------------------------------
| Vérification du nom de fichier
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

$typesAutorises = [
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'ogg' => 'video/ogg',
];

$extension = strtolower(
    pathinfo($nomFichier, PATHINFO_EXTENSION)
);

if (!isset($typesAutorises[$extension])) {
    http_response_code(403);
    exit('Format vidéo non autorisé.');
}

/*
|--------------------------------------------------------------------------
| Vérification du répertoire vidéo sur le montage NFS
|--------------------------------------------------------------------------
*/

$repertoire = realpath('/mnt/video');

if ($repertoire === false || !is_dir($repertoire)) {
    error_log(
        'Le répertoire /mnt/video est indisponible.'
    );

    http_response_code(500);
    exit('Le répertoire vidéo est indisponible.');
}

/*
|--------------------------------------------------------------------------
| Vérification du fichier vidéo
|--------------------------------------------------------------------------
*/

$chemin = realpath(
    $repertoire . DIRECTORY_SEPARATOR . $nomFichier
);

if (
    $chemin === false
    || dirname($chemin) !== $repertoire
    || !is_file($chemin)
    || !is_readable($chemin)
) {
    http_response_code(404);
    exit('Vidéo introuvable.');
}

/*
|--------------------------------------------------------------------------
| Informations sur le fichier
|--------------------------------------------------------------------------
*/

$taille = filesize($chemin);

if ($taille === false || $taille <= 0) {
    http_response_code(500);
    exit('Impossible de déterminer la taille de la vidéo.');
}

$typeMime = $typesAutorises[$extension];

/*
|--------------------------------------------------------------------------
| Configuration des en-têtes HTTP
|--------------------------------------------------------------------------
*/

header('Content-Type: ' . $typeMime);
header('Content-Disposition: inline');
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');

header(
    'Cache-Control: private, no-cache, no-store, must-revalidate'
);

header('X-Accel-Buffering: no');

/*
|--------------------------------------------------------------------------
| Gestion de la requête HEAD
|--------------------------------------------------------------------------
*/

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($methode === 'HEAD') {
    header('Content-Length: ' . $taille);

    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| Vérification de la méthode HTTP
|--------------------------------------------------------------------------
*/

if ($methode !== 'GET') {
    header('Allow: GET, HEAD');

    http_response_code(405);
    exit('Méthode non autorisée.');
}

/*
|--------------------------------------------------------------------------
| Analyse de la requête HTTP Range
|--------------------------------------------------------------------------
|
| Une seule plage d'octets est prise en charge.
|
| Exemples :
| Range: bytes=0-1023
| Range: bytes=1000-
| Range: bytes=-500
|
|--------------------------------------------------------------------------
*/

$debut = 0;
$fin = $taille - 1;

$range = $_SERVER['HTTP_RANGE'] ?? '';

if ($range !== '') {
    if (
        !preg_match(
            '/^bytes=(\d*)-(\d*)$/',
            trim($range),
            $correspondance
        )
    ) {
        header('Content-Range: bytes */' . $taille);

        http_response_code(416);
        exit;
    }

    $debutTexte = $correspondance[1];
    $finTexte = $correspondance[2];

    /*
    |--------------------------------------------------------------------------
    | Plage suffixe : bytes=-500
    |--------------------------------------------------------------------------
    */

    if ($debutTexte === '' && $finTexte !== '') {
        $suffixe = (int) $finTexte;

        if ($suffixe <= 0) {
            header('Content-Range: bytes */' . $taille);

            http_response_code(416);
            exit;
        }

        $debut = max(0, $taille - $suffixe);
        $fin = $taille - 1;

    } else {
        /*
        |--------------------------------------------------------------------------
        | Plage classique : bytes=1000-2000 ou bytes=1000-
        |--------------------------------------------------------------------------
        */

        if ($debutTexte === '') {
            header('Content-Range: bytes */' . $taille);

            http_response_code(416);
            exit;
        }

        $debut = (int) $debutTexte;

        if ($finTexte !== '') {
            $fin = (int) $finTexte;
        }

        if ($debut >= $taille || $fin < $debut) {
            header('Content-Range: bytes */' . $taille);

            http_response_code(416);
            exit;
        }

        $fin = min($fin, $taille - 1);
    }

    http_response_code(206);

    header(
        'Content-Range: bytes '
        . $debut
        . '-'
        . $fin
        . '/'
        . $taille
    );

} else {
    http_response_code(200);
}

/*
|--------------------------------------------------------------------------
| Calcul de la quantité de données à transmettre
|--------------------------------------------------------------------------
*/

$longueur = $fin - $debut + 1;

header('Content-Length: ' . $longueur);

/*
|--------------------------------------------------------------------------
| Ouverture du fichier vidéo
|--------------------------------------------------------------------------
*/

$fichier = @fopen($chemin, 'rb');

if ($fichier === false) {
    error_log(
        'Impossible d’ouvrir la vidéo : '
        . $chemin
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Positionnement dans le fichier
|--------------------------------------------------------------------------
*/

if ($debut > 0 && fseek($fichier, $debut) !== 0) {
    fclose($fichier);

    error_log(
        'Impossible de positionner le lecteur dans la vidéo : '
        . $chemin
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Préparation du transfert
|--------------------------------------------------------------------------
*/

$tailleBloc = 1024 * 1024; // 1 Mio
$reste = $longueur;

set_time_limit(0);

/*
|--------------------------------------------------------------------------
| Transmission de la vidéo par blocs
|--------------------------------------------------------------------------
|
| La vidéo n'est jamais chargée intégralement en mémoire.
| Seule la plage demandée par le navigateur est transmise.
|
|--------------------------------------------------------------------------
*/

while ($reste > 0 && !feof($fichier)) {

    /*
    | Arrêter le transfert si le client a interrompu la connexion.
    */

    if (connection_aborted()) {
        break;
    }

    /*
    | Taille du prochain bloc.
    */

    $aLire = min($tailleBloc, $reste);

    /*
    | Lecture depuis le montage NFS.
    */

    $bloc = fread($fichier, $aLire);

    if ($bloc === false || $bloc === '') {
        error_log(
            'Lecture interrompue pendant le transfert vidéo : '
            . $chemin
        );

        break;
    }

    /*
    | Envoi du bloc au navigateur.
    */

    echo $bloc;

    $lu = strlen($bloc);

    $reste -= $lu;

    /*
    | Demander à PHP de transmettre les données disponibles.
    */

    flush();
}

/*
|--------------------------------------------------------------------------
| Fermeture du fichier
|--------------------------------------------------------------------------
*/

fclose($fichier);

exit;
