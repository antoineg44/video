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
| Fonctions utilitaires
|--------------------------------------------------------------------------
*/

function e(string $valeur): string
{
    return htmlspecialchars(
        $valeur,
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Retourne une réponse JSON et termine la requête.
 */
function repondreJson(
    array $donnees,
    int $codeHttp = 200
): never {
    http_response_code($codeHttp);

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo json_encode(
        $donnees,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Écrit l'état du découpage dans un fichier temporaire.
 *
 * Ce fichier permet au navigateur de récupérer la progression
 * pendant que FFmpeg continue son traitement.
 */
function enregistrerProgression(
    string $fichier,
    array $donnees
): void {
    $json = json_encode(
        $donnees,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return;
    }

    $temporaire = $fichier . '.tmp';

    if (@file_put_contents(
        $temporaire,
        $json,
        LOCK_EX
    ) === false) {
        return;
    }

    if (!@rename($temporaire, $fichier)) {
        @unlink($temporaire);
    }
}

/**
 * Vérifie réellement si PHP peut créer un fichier
 * dans le répertoire de destination.
 *
 * Cette vérification est adaptée à notre montage NFS :
 * on ne dépend pas uniquement de is_writable().
 */
function testerEcritureRepertoire(
    string $repertoire
): bool {
    try {
        $nom = '.test_ecriture_'
            . bin2hex(random_bytes(12));

    } catch (Throwable $exception) {
        return false;
    }

    $chemin = rtrim($repertoire, '/')
        . '/'
        . $nom;

    $handle = @fopen($chemin, 'x');

    if ($handle === false) {
        return false;
    }

    $fermeture = fclose($handle);

    $suppression = @unlink($chemin);

    return $fermeture && $suppression;
}

/**
 * Retourne le chemin du fichier de progression.
 *
 * Le chemin dépend de la session et de l'identifiant du travail.
 */
function cheminProgression(
    string $identifiant
): string {
    return sys_get_temp_dir()
        . '/couper_video_'
        . hash(
            'sha256',
            session_id() . ':' . $identifiant
        )
        . '.json';
}

/*
|--------------------------------------------------------------------------
| Vérification des droits d'accès
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
        'Erreur droits vidéo : '
        . $exception->getMessage()
    );

    http_response_code(500);

    exit(
        'Erreur lors de la vérification des autorisations.'
    );
}

if (!$autorise) {
    http_response_code(403);
    exit('Accès non autorisé.');
}

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

$repertoireVideos = '/mnt/video';

$ffmpeg = '/usr/bin/ffmpeg';

$urlLecture = 'video.php';

/*
|--------------------------------------------------------------------------
| Récupération du nom de la vidéo
|--------------------------------------------------------------------------
*/

$nomVideo = $_GET['f'] ?? $_POST['f'] ?? '';

if (
    !is_string($nomVideo)
    || $nomVideo === ''
) {
    http_response_code(400);

    exit(
        'Aucune vidéo n\'a été spécifiée dans l\'URL.'
    );
}

/*
|--------------------------------------------------------------------------
| Vérification du nom du fichier
|--------------------------------------------------------------------------
*/

if (
    $nomVideo !== basename($nomVideo)
    || str_contains($nomVideo, '/')
    || str_contains($nomVideo, '\\')
    || str_contains($nomVideo, "\0")
) {
    http_response_code(400);
    exit('Nom de fichier invalide.');
}

/*
|--------------------------------------------------------------------------
| Vérification de l'extension
|--------------------------------------------------------------------------
*/

$extensionsAutorisees = [
    'mp4',
    'webm',
    'ogg',
];

$extension = strtolower(
    pathinfo(
        $nomVideo,
        PATHINFO_EXTENSION
    )
);

if (!in_array(
    $extension,
    $extensionsAutorisees,
    true
)) {
    http_response_code(400);
    exit('Format vidéo non autorisé.');
}

/*
|--------------------------------------------------------------------------
| Vérification du fichier vidéo
|--------------------------------------------------------------------------
*/

$repertoireReel = realpath($repertoireVideos);

$videoReelle = realpath(
    $repertoireVideos . '/' . $nomVideo
);

if (
    $repertoireReel === false
    || $videoReelle === false
    || dirname($videoReelle) !== $repertoireReel
    || !is_file($videoReelle)
    || !is_readable($videoReelle)
) {
    http_response_code(404);

    exit(
        'Vidéo introuvable ou inaccessible.'
    );
}

$cheminVideo = $videoReelle;

/*
|--------------------------------------------------------------------------
| Protection CSRF
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['couper_video_csrf'])
    || !is_string($_SESSION['couper_video_csrf'])
) {
    $_SESSION['couper_video_csrf'] = bin2hex(
        random_bytes(32)
    );
}

/*
|--------------------------------------------------------------------------
| Gestion de la progression : requête AJAX GET
|--------------------------------------------------------------------------
|
| Le navigateur appelle cette partie régulièrement pour
| récupérer l'avancement de FFmpeg.
|
*/

$actionGet = $_GET['action'] ?? '';

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $actionGet === 'progress'
) {
    $identifiant = $_GET['job_id'] ?? '';

    if (
        !is_string($identifiant)
        || !preg_match(
            '/\A[a-f0-9]{32}\z/',
            $identifiant
        )
    ) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'Identifiant invalide.',
            ],
            400
        );
    }

    /*
     * Le traitement peut démarrer juste après la première
     * requête de progression.
     *
     * Tant que le travail n'est pas enregistré en session,
     * le navigateur reçoit l'état "attente".
     */

    $travailSession = $_SESSION['couper_video_job'] ?? null;

    if ($travailSession === null) {
        repondreJson([
            'etat' => 'attente',
            'pourcentage' => 0,
            'message' => 'Préparation du découpage...',
        ]);
    }

    if (
        !is_array($travailSession)
        || ($travailSession['id'] ?? '') !== $identifiant
        || ($travailSession['video'] ?? '') !== $nomVideo
    ) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'Travail introuvable ou non autorisé.',
            ],
            403
        );
    }

    /*
     * Libération du verrou de session.
     *
     * Cela évite de bloquer les autres requêtes PHP
     * pendant l'exécution de FFmpeg.
     */

    session_write_close();

    $fichierProgression = cheminProgression(
        $identifiant
    );

    if (!is_file($fichierProgression)) {
        repondreJson([
            'etat' => 'attente',
            'pourcentage' => 0,
            'message' => 'Initialisation de FFmpeg...',
        ]);
    }

    $contenu = @file_get_contents(
        $fichierProgression
    );

    if ($contenu === false) {
        repondreJson([
            'etat' => 'attente',
            'pourcentage' => 0,
            'message' => 'Lecture de la progression...',
        ]);
    }

    $progression = json_decode(
        $contenu,
        true
    );

    if (!is_array($progression)) {
        repondreJson([
            'etat' => 'attente',
            'pourcentage' => 0,
            'message' => 'Lecture de la progression...',
        ]);
    }

    repondreJson($progression);
}

/*
|--------------------------------------------------------------------------
| Variables d'affichage
|--------------------------------------------------------------------------
*/

$message = '';

$typeMessage = 'info';

/*
|--------------------------------------------------------------------------
| Traitement du découpage : requête AJAX POST
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'couper_video'
) {
    /*
    |--------------------------------------------------------------------------
    | Vérification CSRF
    |--------------------------------------------------------------------------
    */

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token)
        || !hash_equals(
            $_SESSION['couper_video_csrf'],
            $token
        )
    ) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'Requête invalide. Rechargez la page.',
            ],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Vérification de l'identifiant du travail
    |--------------------------------------------------------------------------
    */

    $identifiant = $_POST['job_id'] ?? '';

    if (
        !is_string($identifiant)
        || !preg_match(
            '/\A[a-f0-9]{32}\z/',
            $identifiant
        )
    ) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'Identifiant de découpage invalide.',
            ],
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Récupération des positions
    |--------------------------------------------------------------------------
    */

    $debutBrut = $_POST['debut'] ?? '';

    $finBrut = $_POST['fin'] ?? '';

    if (
        !is_string($debutBrut)
        || !is_string($finBrut)
        || !is_numeric($debutBrut)
        || !is_numeric($finBrut)
    ) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'Les positions sont invalides.',
            ],
            400
        );
    }

    $debut = (float) $debutBrut;

    $fin = (float) $finBrut;

    /*
    |--------------------------------------------------------------------------
    | Validation des positions
    |--------------------------------------------------------------------------
    */

    if (
        !is_finite($debut)
        || !is_finite($fin)
        || $debut < 0
        || $fin <= $debut
    ) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'La fin doit être supérieure au début.',
            ],
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Vérification de FFmpeg
    |--------------------------------------------------------------------------
    */

    if (!is_executable($ffmpeg)) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'FFmpeg est introuvable ou inexécutable.',
            ],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Vérification du répertoire NFS
    |--------------------------------------------------------------------------
    */

    if (
        !is_dir($repertoireVideos)
        || !is_executable($repertoireVideos)
    ) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'Le répertoire vidéo est inaccessible.',
            ],
            500
        );
    }

    /*
     * Nous testons une véritable création de fichier.
     * Nous ne dépendons pas de is_writable(), qui renvoie
     * false dans ton environnement NFS.
     */

    if (!testerEcritureRepertoire($repertoireVideos)) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'Impossible de créer un fichier dans /mnt/video.',
            ],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Préparation du fichier de sortie
    |--------------------------------------------------------------------------
    */

    $nomSansExtension = pathinfo(
        $nomVideo,
        PATHINFO_FILENAME
    );

    try {
        $identifiantUnique = bin2hex(
            random_bytes(8)
        );

    } catch (Throwable $exception) {

        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'Impossible de générer un identifiant.',
            ],
            500
        );
    }

    $nomResultat = $nomSansExtension
        . '_coupee_'
        . date('Ymd_His')
        . '_'
        . $identifiantUnique
        . '.mp4';

    $cheminResultat = $repertoireVideos
        . '/'
        . $nomResultat;

    /*
    |--------------------------------------------------------------------------
    | Fichier temporaire vidéo
    |--------------------------------------------------------------------------
    */

    $cheminTemporaire = $repertoireVideos
        . '/.'
        . bin2hex(random_bytes(16))
        . '.part.mp4';

    /*
    |--------------------------------------------------------------------------
    | Fichier de journalisation FFmpeg
    |--------------------------------------------------------------------------
    */

    $fichierErreur = tempnam(
        sys_get_temp_dir(),
        'ffmpeg_coupe_'
    );

    if ($fichierErreur === false) {
        repondreJson(
            [
                'etat' => 'erreur',
                'message' => 'Impossible de créer le journal FFmpeg.',
            ],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Durée du passage
    |--------------------------------------------------------------------------
    */

    $duree = $fin - $debut;

    /*
    |--------------------------------------------------------------------------
    | Enregistrement du travail en session
    |--------------------------------------------------------------------------
    */

    $_SESSION['couper_video_job'] = [
        'id' => $identifiant,
        'video' => $nomVideo,
    ];

    $fichierProgression = cheminProgression(
        $identifiant
    );

    enregistrerProgression(
        $fichierProgression,
        [
            'etat' => 'en_cours',
            'pourcentage' => 0,
            'temps' => 0,
            'duree' => $duree,
            'message' => 'Démarrage de FFmpeg...',
        ]
    );

    /*
     * Important : nous libérons la session avant le traitement.
     *
     * Les requêtes AJAX de progression pourront ainsi accéder
     * à la session pendant que FFmpeg travaille.
     */

    session_write_close();

    /*
    |--------------------------------------------------------------------------
    | Construction de la commande FFmpeg
    |--------------------------------------------------------------------------
    |
    | proc_open() reçoit un tableau d'arguments.
    | Aucun passage par le shell n'est nécessaire.
    |
    | -progress pipe:1 permet de récupérer la progression réelle.
    |
    */

    $commande = [
        $ffmpeg,

        '-hide_banner',
        '-loglevel', 'error',
        '-nostdin',
        '-nostats',
        '-y',

        '-progress', 'pipe:1',

        '-ss', sprintf('%.6F', $debut),

        '-i', $cheminVideo,

        '-t', sprintf('%.6F', $duree),

        '-map', '0:v:0',
        '-map', '0:a:0?',

        '-c:v', 'libx264',
        '-preset', 'veryfast',
        '-crf', '20',

        '-c:a', 'aac',
        '-b:a', '192k',

        '-movflags', '+faststart',

        $cheminTemporaire,
    ];

    /*
    |--------------------------------------------------------------------------
    | Descripteurs du processus
    |--------------------------------------------------------------------------
    */

    $descripteurs = [
        0 => [
            'file',
            '/dev/null',
            'r',
        ],

        1 => [
            'pipe',
            'w',
        ],

        2 => [
            'file',
            $fichierErreur,
            'w',
        ],
    ];

    /*
    |--------------------------------------------------------------------------
    | Lancement de FFmpeg
    |--------------------------------------------------------------------------
    */

    @set_time_limit(0);

    $processus = false;

    $codeRetour = -1;

    $codeObserve = -1;

    $erreurExecution = '';

    try {
        $processus = proc_open(
            $commande,
            $descripteurs,
            $tuyaux
        );

        if (!is_resource($processus)) {
            throw new RuntimeException(
                'Impossible de démarrer FFmpeg.'
            );
        }

        /*
         * Le flux de progression est lu sans bloquer PHP.
         */

        $sortie = $tuyaux[1];

        stream_set_blocking(
            $sortie,
            false
        );

        $tampon = '';

        $tempsDerniereMiseAJour = 0.0;

        $dernierPourcentage = -1;

        $dernierTemps = 0.0;

        /*
        |--------------------------------------------------------------------------
        | Lecture de la progression FFmpeg
        |--------------------------------------------------------------------------
        */

        while (true) {

            $lecture = [
                $sortie,
            ];

            $ecriture = null;

            $exceptions = null;

            $selection = @stream_select(
                $lecture,
                $ecriture,
                $exceptions,
                0,
                200000
            );

            if (
                $selection !== false
                && $selection > 0
            ) {
                $bloc = fread(
                    $sortie,
                    8192
                );

                if (
                    is_string($bloc)
                    && $bloc !== ''
                ) {
                    $tampon .= $bloc;

                    while (
                        ($position = strpos(
                            $tampon,
                            "\n"
                        )) !== false
                    ) {
                        $ligne = trim(
                            substr(
                                $tampon,
                                0,
                                $position
                            )
                        );

                        $tampon = substr(
                            $tampon,
                            $position + 1
                        );

                        /*
                         * FFmpeg fournit notamment :
                         *
                         * out_time_us=...
                         * progress=continue
                         * progress=end
                         */

                        if (
                            preg_match(
                                '/^out_time_(?:us|ms)=(\d+)$/',
                                $ligne,
                                $correspondance
                            )
                        ) {
                            /*
                             * Ces valeurs sont exprimées
                             * en microsecondes.
                             */

                            $dernierTemps =
                                (float) $correspondance[1]
                                / 1000000;

                            $dernierTemps = max(
                                0.0,
                                min(
                                    $dernierTemps,
                                    $duree
                                )
                            );
                        }

                        if ($ligne === 'progress=end') {
                            $dernierTemps = $duree;
                        }

                        if (
                            str_starts_with(
                                $ligne,
                                'progress='
                            )
                        ) {
                            if ($duree > 0) {
                                $pourcentage = (int) floor(
                                    (
                                        $dernierTemps
                                        / $duree
                                    ) * 100
                                );

                                $pourcentage = max(
                                    0,
                                    min(
                                        $pourcentage,
                                        99
                                    )
                                );

                            } else {
                                $pourcentage = 0;
                            }

                            $maintenant = microtime(true);

                            /*
                             * Écriture du statut uniquement
                             * si nécessaire, pour limiter
                             * les accès au disque.
                             */

                            if (
                                $pourcentage !== $dernierPourcentage
                                || (
                                    $maintenant
                                    - $tempsDerniereMiseAJour
                                ) >= 0.5
                            ) {
                                enregistrerProgression(
                                    $fichierProgression,
                                    [
                                        'etat' => 'en_cours',

                                        'pourcentage' => $pourcentage,

                                        'temps' => $dernierTemps,

                                        'duree' => $duree,

                                        'message' => 'Découpage de la vidéo...',
                                    ]
                                );

                                $dernierPourcentage =
                                    $pourcentage;

                                $tempsDerniereMiseAJour =
                                    $maintenant;
                            }
                        }
                    }
                }
            }

            /*
             * Vérification de l'état du processus.
             */

            $etatProcessus = proc_get_status(
                $processus
            );

            if (!$etatProcessus['running']) {

                if ($etatProcessus['exitcode'] >= 0) {
                    $codeObserve = $etatProcessus['exitcode'];
                }

                /*
                 * Lecture des éventuelles données restantes.
                 */

                $reste = stream_get_contents(
                    $sortie
                );

                if (
                    is_string($reste)
                    && $reste !== ''
                ) {
                    $tampon .= $reste;
                }

                if (feof($sortie)) {
                    break;
                }
            }
        }

        fclose($sortie);

        $codeFermeture = proc_close(
            $processus
        );

        if ($codeFermeture >= 0) {
            $codeRetour = $codeFermeture;

        } elseif ($codeObserve >= 0) {
            $codeRetour = $codeObserve;
        }

    } catch (Throwable $exception) {

        $erreurExecution = $exception->getMessage();

        error_log(
            'Découpage vidéo : '
            . $erreurExecution
        );

        if (is_resource($processus)) {

            @proc_terminate($processus);

            @proc_close($processus);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Lecture du journal FFmpeg
    |--------------------------------------------------------------------------
    */

    $detailsErreur = '';

    if (is_file($fichierErreur)) {

        $contenuErreur = @file_get_contents(
            $fichierErreur
        );

        if ($contenuErreur !== false) {

            $detailsErreur = trim(
                $contenuErreur
            );
        }

        @unlink($fichierErreur);
    }

    /*
    |--------------------------------------------------------------------------
    | Vérification du résultat
    |--------------------------------------------------------------------------
    */

    clearstatcache(
        true,
        $cheminTemporaire
    );

    $tailleFichier = is_file($cheminTemporaire)
        ? @filesize($cheminTemporaire)
        : false;

    $resultatValide = (
        $erreurExecution === ''
        && $codeRetour === 0
        && $tailleFichier !== false
        && $tailleFichier > 0
    );

    /*
    |--------------------------------------------------------------------------
    | Finalisation
    |--------------------------------------------------------------------------
    */

    if (
        $resultatValide
        && @rename(
            $cheminTemporaire,
            $cheminResultat
        )
    ) {
        /*
         * Découpage réussi.
         */

        enregistrerProgression(
            $fichierProgression,
            [
                'etat' => 'termine',

                'pourcentage' => 100,

                'temps' => $duree,

                'duree' => $duree,

                'message' => 'La vidéo a été découpée avec succès.',

                'fichier' => $nomResultat,

                'url' => $urlLecture
                    . '?f='
                    . rawurlencode($nomResultat),
            ]
        );

        /*
         * Rotation du jeton CSRF.
         */

        session_start();

        $_SESSION['couper_video_csrf'] = bin2hex(
            random_bytes(32)
        );

        session_write_close();

        /*
         * Réponse finale AJAX.
         */

        repondreJson([
            'etat' => 'termine',

            'pourcentage' => 100,

            'temps' => $duree,

            'duree' => $duree,

            'message' => 'La vidéo a été découpée avec succès.',

            'fichier' => $nomResultat,

            'url' => $urlLecture
                . '?f='
                . rawurlencode($nomResultat),
        ]);

    } else {

        /*
         * Échec : suppression du fichier temporaire.
         */

        if (is_file($cheminTemporaire)) {
            @unlink($cheminTemporaire);
        }

        $messageErreur = $erreurExecution !== ''
            ? $erreurExecution
            : 'FFmpeg n\'a pas réussi à découper la vidéo.';

        if ($codeRetour !== -1) {
            $messageErreur .= "\nCode de retour : "
                . $codeRetour;
        }

        if ($detailsErreur !== '') {
            $messageErreur .= "\n\nDétails FFmpeg :\n"
                . substr(
                    $detailsErreur,
                    -4000
                );
        }

        error_log(
            'Erreur de découpage vidéo : '
            . $nomVideo
            . ' | Code : '
            . $codeRetour
            . ' | '
            . $messageErreur
        );

        enregistrerProgression(
            $fichierProgression,
            [
                'etat' => 'erreur',

                'pourcentage' => 0,

                'message' => $messageErreur,
            ]
        );

        repondreJson(
            [
                'etat' => 'erreur',
                'message' => $messageErreur,
            ],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Préparation de la page HTML
|--------------------------------------------------------------------------
*/

$urlVideo = $urlLecture
    . '?f='
    . rawurlencode($nomVideo);

$csrfToken = $_SESSION['couper_video_csrf'];

?>
<!DOCTYPE html>
<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Couper une vidéo</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            background: #f3f5f9;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }

        .conteneur {
            max-width: 1000px;
            margin: 0 auto;
        }

        .entete {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .retour {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .retour:hover {
            text-decoration: underline;
        }

        .carte {
            padding: 22px;
            margin-bottom: 20px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(20, 35, 65, .06);
        }

        .nom-video {
            margin: 0 0 16px;
            font-size: 17px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .lecteur {
            display: block;
            width: 100%;
            max-height: 600px;
            background: #000;
            border-radius: 8px;
        }

        .informations-lecture {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 12px;
            color: #6b7280;
            font-size: 13px;
        }

        .titre-section {
            margin: 0 0 18px;
            font-size: 19px;
        }

        .positions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .position {
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f9fafb;
        }

        .position h3 {
            margin: 0 0 10px;
            font-size: 15px;
        }

        .temps {
            margin-bottom: 14px;
            color: #2563eb;
            font-size: 22px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .boutons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .bouton {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            border: none;
            border-radius: 7px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background .2s;
        }

        .bouton:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .bouton-debut {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .bouton-debut:hover:not(:disabled) {
            background: #bfdbfe;
        }

        .bouton-fin {
            background: #ffedd5;
            color: #9a3412;
        }

        .bouton-fin:hover:not(:disabled) {
            background: #fed7aa;
        }

        .bouton-principal {
            background: #2563eb;
            color: #fff;
        }

        .bouton-principal:hover:not(:disabled) {
            background: #1d4ed8;
        }

        .bouton-secondaire {
            background: #e5e7eb;
            color: #374151;
        }

        .bouton-secondaire:hover:not(:disabled) {
            background: #d1d5db;
        }

        .resume {
            margin: 18px 0;
            padding: 15px;
            border-radius: 8px;
            background: #eff6ff;
            color: #1e40af;
            line-height: 1.7;
        }

        .message {
            padding: 16px 18px;
            margin-bottom: 20px;
            border-radius: 8px;
            background: #fff;
            white-space: pre-line;
            overflow-wrap: anywhere;
            line-height: 1.6;
        }

        .message.succes {
            border-left: 4px solid #16a34a;
            color: #166534;
        }

        .message.erreur {
            border-left: 4px solid #dc2626;
            color: #991b1b;
        }

        .message.info {
            border-left: 4px solid #2563eb;
            color: #1e40af;
        }

        /*
        |--------------------------------------------------------------------------
        | Barre de progression
        |--------------------------------------------------------------------------
        */

        .progression {
            display: none;
            margin: 24px 0;
            padding: 20px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }

        .progression.visible {
            display: block;
        }

        .progression-entete {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .progression-titre {
            font-weight: 700;
            color: #1e293b;
        }

        .progression-pourcentage {
            color: #2563eb;
            font-size: 23px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .progression-fond {
            width: 100%;
            height: 24px;
            overflow: hidden;
            background: #e5e7eb;
            border-radius: 20px;
        }

        .progression-barre {
            width: 0%;
            height: 100%;
            border-radius: 20px;
            background: linear-gradient(
                90deg,
                #2563eb,
                #3b82f6
            );
            transition: width .3s ease;
        }

        .progression-barre.terminee {
            background: #16a34a;
        }

        .progression-barre.erreur {
            background: #dc2626;
        }

        .progression-informations {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
            color: #64748b;
            font-size: 13px;
        }

        .progression-message {
            margin-top: 12px;
            color: #334155;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .resultat {
            display: none;
            margin-top: 18px;
            padding: 18px;
            border-radius: 8px;
            background: #f0fdf4;
        }

        .resultat.visible {
            display: block;
        }

        .resultat h3 {
            margin: 0 0 12px;
            color: #166534;
        }

        .aide {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.7;
        }

        @media (max-width: 600px) {

            body {
                padding: 14px;
            }

            h1 {
                font-size: 23px;
            }

            .carte {
                padding: 16px;
            }

            .positions {
                grid-template-columns: 1fr;
            }

            .boutons {
                flex-direction: column;
            }

            .bouton {
                width: 100%;
            }

            .temps {
                font-size: 20px;
            }

            .progression-pourcentage {
                font-size: 20px;
            }
        }

    </style>

</head>

<body>

<div class="conteneur">

    <header class="entete">

        <h1>✂️ Couper une vidéo</h1>

        <a
            class="retour"
            href="videos.php"
        >
            ← Retour à la bibliothèque
        </a>

    </header>

    <div
        id="messageGeneral"
        class="message"
        role="status"
        hidden
    ></div>

    <!-- Lecteur vidéo -->

    <section class="carte">

        <p class="nom-video">
            <?= e($nomVideo) ?>
        </p>

        <video
            id="lecteurVideo"
            class="lecteur"
            controls
            preload="metadata"
            playsinline
        >
            <source
                src="<?= e($urlVideo) ?>"
                type="<?=
                    $extension === 'mp4'
                        ? 'video/mp4'
                        : (
                            $extension === 'webm'
                                ? 'video/webm'
                                : 'video/ogg'
                        )
                ?>"
            >

            Ton navigateur ne prend pas en charge la lecture vidéo.
        </video>

        <div class="informations-lecture">

            <span>
                Position actuelle :
                <strong id="positionActuelle">00:00:00.000</strong>
            </span>

            <span>
                Durée totale :
                <strong id="dureeTotale">--:--:--</strong>
            </span>

        </div>

    </section>

    <!-- Commandes de coupe -->

    <section class="carte">

        <h2 class="titre-section">
            Définir les points de coupe
        </h2>

        <p class="aide">
            Lance la lecture, mets la vidéo en pause au moment souhaité,
            puis définis le début et la fin du passage à conserver.
        </p>

        <form
            method="post"
            action="couper_video.php?f=<?= rawurlencode($nomVideo) ?>"
            id="formulaireCoupe"
        >

            <input
                type="hidden"
                name="action"
                value="couper_video"
            >

            <input
                type="hidden"
                name="f"
                value="<?= e($nomVideo) ?>"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="debut"
                id="champDebut"
                value=""
            >

            <input
                type="hidden"
                name="fin"
                id="champFin"
                value=""
            >

            <input
                type="hidden"
                name="job_id"
                id="champJobId"
                value=""
            >

            <div class="positions">

                <div class="position">

                    <h3>🟢 Début du passage</h3>

                    <div
                        class="temps"
                        id="affichageDebut"
                    >
                        Non défini
                    </div>

                    <button
                        type="button"
                        class="bouton bouton-debut"
                        id="boutonDebut"
                    >
                        Définir le début ici
                    </button>

                </div>

                <div class="position">

                    <h3>🔴 Fin du passage</h3>

                    <div
                        class="temps"
                        id="affichageFin"
                    >
                        Non définie
                    </div>

                    <button
                        type="button"
                        class="bouton bouton-fin"
                        id="boutonFin"
                    >
                        Définir la fin ici
                    </button>

                </div>

            </div>

            <div
                class="resume"
                id="resumeSelection"
                aria-live="polite"
            >
                Sélectionne un début et une fin pour afficher
                la durée du passage à conserver.
            </div>

            <!-- Barre de progression -->

            <div
                class="progression"
                id="blocProgression"
                role="region"
                aria-label="Progression du découpage"
                aria-live="polite"
            >

                <div class="progression-entete">

                    <span class="progression-titre">
                        Découpage de la vidéo
                    </span>

                    <span
                        class="progression-pourcentage"
                        id="progressionPourcentage"
                    >
                        0 %
                    </span>

                </div>

                <div
                    class="progression-fond"
                    id="progressionFond"
                    role="progressbar"
                    aria-label="Avancement du découpage"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="0"
                >

                    <div
                        class="progression-barre"
                        id="progressionBarre"
                    ></div>

                </div>

                <div class="progression-informations">

                    <span>
                        Temps traité :
                        <strong id="progressionTemps">00:00:00</strong>
                    </span>

                    <span>
                        Durée à traiter :
                        <strong id="progressionDuree">00:00:00</strong>
                    </span>

                </div>

                <div
                    class="progression-message"
                    id="progressionMessage"
                >
                    En attente du découpage...
                </div>

            </div>

            <div class="boutons">

                <button
                    type="button"
                    class="bouton bouton-secondaire"
                    id="boutonReinitialiser"
                >
                    Réinitialiser les points
                </button>

                <button
                    type="submit"
                    class="bouton bouton-principal"
                    id="boutonCouper"
                    disabled
                >
                    ✂️ Créer la vidéo coupée
                </button>

            </div>

        </form>

        <p class="aide">
            La vidéo originale sera conservée.
            Le fichier découpé sera enregistré dans
            <strong>/mnt/video</strong> au format MP4.
        </p>

        <!-- Résultat -->

        <div
            class="resultat"
            id="blocResultat"
        >

            <h3>✅ Vidéo créée</h3>

            <p>
                Fichier :
                <strong id="nomResultat"></strong>
            </p>

            <div class="boutons">

                <a
                    class="bouton bouton-principal"
                    id="lienResultat"
                    href="#"
                    target="_blank"
                    rel="noopener"
                >
                    ▶ Lire la vidéo coupée
                </a>

                <a
                    class="bouton bouton-secondaire"
                    id="lienRecouper"
                    href="#"
                >
                    Couper cette vidéo
                </a>

            </div>

        </div>

    </section>

</div>

<script>
(function () {

    'use strict';

    const lecteur = document.getElementById('lecteurVideo');

    const formulaire = document.getElementById('formulaireCoupe');

    const champDebut = document.getElementById('champDebut');

    const champFin = document.getElementById('champFin');

    const champJobId = document.getElementById('champJobId');

    const affichageDebut = document.getElementById('affichageDebut');

    const affichageFin = document.getElementById('affichageFin');

    const positionActuelle = document.getElementById('positionActuelle');

    const dureeTotale = document.getElementById('dureeTotale');

    const resumeSelection = document.getElementById('resumeSelection');

    const boutonDebut = document.getElementById('boutonDebut');

    const boutonFin = document.getElementById('boutonFin');

    const boutonReinitialiser = document.getElementById('boutonReinitialiser');

    const boutonCouper = document.getElementById('boutonCouper');

    const messageGeneral = document.getElementById('messageGeneral');

    const blocProgression = document.getElementById('blocProgression');

    const progressionBarre = document.getElementById('progressionBarre');

    const progressionFond = document.getElementById('progressionFond');

    const progressionPourcentage = document.getElementById('progressionPourcentage');

    const progressionTemps = document.getElementById('progressionTemps');

    const progressionDuree = document.getElementById('progressionDuree');

    const progressionMessage = document.getElementById('progressionMessage');

    const blocResultat = document.getElementById('blocResultat');

    const nomResultat = document.getElementById('nomResultat');

    const lienResultat = document.getElementById('lienResultat');

    const lienRecouper = document.getElementById('lienRecouper');

    const urlBase = 'couper_video.php';

    const nomVideo = <?= json_encode(
        $nomVideo,
        JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_HEX_AMP
    ) ?>;

    let intervalleProgression = null;

    let traitementEnCours = false;

    /*
    |--------------------------------------------------------------------------
    | Formatage des durées
    |--------------------------------------------------------------------------
    */

    function formaterTemps(secondes, millisecondes = true) {

        if (!Number.isFinite(secondes) || secondes < 0) {
            secondes = 0;
        }

        const heures = Math.floor(secondes / 3600);

        const minutes = Math.floor((secondes % 3600) / 60);

        const reste = secondes % 60;

        const secondesEntieres = Math.floor(reste);

        let resultat =
            String(heures).padStart(2, '0')
            + ':'
            + String(minutes).padStart(2, '0')
            + ':'
            + String(secondesEntieres).padStart(2, '0');

        if (millisecondes) {

            const ms = Math.floor(
                (reste - secondesEntieres) * 1000
            );

            resultat += '.'
                + String(ms).padStart(3, '0');
        }

        return resultat;
    }

    /*
    |--------------------------------------------------------------------------
    | Affichage des messages
    |--------------------------------------------------------------------------
    */

    function afficherMessage(texte, type) {

        messageGeneral.textContent = texte;

        messageGeneral.className = 'message ' + type;

        messageGeneral.hidden = false;
    }

    function masquerMessage() {

        messageGeneral.hidden = true;

        messageGeneral.textContent = '';
    }

    /*
    |--------------------------------------------------------------------------
    | Position actuelle du lecteur
    |--------------------------------------------------------------------------
    */

    function actualiserPosition() {

        positionActuelle.textContent = formaterTemps(
            lecteur.currentTime || 0
        );
    }

    lecteur.addEventListener(
        'timeupdate',
        actualiserPosition
    );

    lecteur.addEventListener(
        'seeked',
        actualiserPosition
    );

    lecteur.addEventListener(
        'loadedmetadata',
        function () {

            if (Number.isFinite(lecteur.duration)) {

                dureeTotale.textContent = formaterTemps(
                    lecteur.duration,
                    false
                );

            } else {

                dureeTotale.textContent = 'Indisponible';
            }

            actualiserPosition();
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Actualisation de la sélection
    |--------------------------------------------------------------------------
    */

    function actualiserSelection() {

        const debut = champDebut.value === ''
            ? null
            : Number(champDebut.value);

        const fin = champFin.value === ''
            ? null
            : Number(champFin.value);

        affichageDebut.textContent = debut === null
            ? 'Non défini'
            : formaterTemps(debut);

        affichageFin.textContent = fin === null
            ? 'Non définie'
            : formaterTemps(fin);

        const selectionValide = (
            debut !== null
            && fin !== null
            && Number.isFinite(debut)
            && Number.isFinite(fin)
            && debut >= 0
            && fin > debut
        );

        boutonCouper.disabled = (
            !selectionValide
            || traitementEnCours
        );

        if (selectionValide) {

            resumeSelection.textContent =
                'Début : '
                + formaterTemps(debut)
                + ' | Fin : '
                + formaterTemps(fin)
                + ' | Durée conservée : '
                + formaterTemps(fin - debut);

        } else {

            resumeSelection.textContent =
                'Sélectionne un début et une fin valides. '
                + 'La fin doit être postérieure au début.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Définir le début
    |--------------------------------------------------------------------------
    */

    boutonDebut.addEventListener(
        'click',
        function () {

            if (traitementEnCours) {
                return;
            }

            const position = lecteur.currentTime;

            if (!Number.isFinite(position)) {
                return;
            }

            champDebut.value = position.toFixed(3);

            if (
                champFin.value !== ''
                && Number(champFin.value) <= position
            ) {
                champFin.value = '';
            }

            actualiserSelection();
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Définir la fin
    |--------------------------------------------------------------------------
    */

    boutonFin.addEventListener(
        'click',
        function () {

            if (traitementEnCours) {
                return;
            }

            const position = lecteur.currentTime;

            if (!Number.isFinite(position)) {
                return;
            }

            champFin.value = position.toFixed(3);

            actualiserSelection();
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Réinitialisation
    |--------------------------------------------------------------------------
    */

    boutonReinitialiser.addEventListener(
        'click',
        function () {

            if (traitementEnCours) {
                return;
            }

            champDebut.value = '';

            champFin.value = '';

            actualiserSelection();
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Mise à jour visuelle de la progression
    |--------------------------------------------------------------------------
    */

    function actualiserProgression(donnees) {

        let pourcentage = Number(
            donnees.pourcentage ?? 0
        );

        if (!Number.isFinite(pourcentage)) {
            pourcentage = 0;
        }

        pourcentage = Math.max(
            0,
            Math.min(
                pourcentage,
                100
            )
        );

        progressionBarre.style.width =
            pourcentage + '%';

        progressionPourcentage.textContent =
            Math.round(pourcentage) + ' %';

        progressionFond.setAttribute(
            'aria-valuenow',
            String(Math.round(pourcentage))
        );

        progressionTemps.textContent = formaterTemps(
            Number(donnees.temps ?? 0),
            false
        );

        progressionDuree.textContent = formaterTemps(
            Number(donnees.duree ?? 0),
            false
        );

        if (typeof donnees.message === 'string') {

            progressionMessage.textContent =
                donnees.message;
        }

        progressionBarre.classList.toggle(
            'terminee',
            donnees.etat === 'termine'
        );

        progressionBarre.classList.toggle(
            'erreur',
            donnees.etat === 'erreur'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Génération d'un identifiant de travail
    |--------------------------------------------------------------------------
    */

    function genererIdentifiant() {

        const octets = new Uint8Array(16);

        if (
            !window.crypto
            || !window.crypto.getRandomValues
        ) {
            throw new Error(
                'Le navigateur ne permet pas de générer un identifiant sécurisé.'
            );
        }

        window.crypto.getRandomValues(octets);

        return Array.from(octets)
            .map(function (octet) {
                return octet.toString(16).padStart(2, '0');
            })
            .join('');
    }

    /*
    |--------------------------------------------------------------------------
    | Interrogation du serveur
    |--------------------------------------------------------------------------
    */

    async function recupererProgression(jobId) {

        const url = urlBase
            + '?f='
            + encodeURIComponent(nomVideo)
            + '&action=progress'
            + '&job_id='
            + encodeURIComponent(jobId);

        const reponse = await fetch(
            url,
            {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            }
        );

        const donnees = await reponse.json();

        if (!reponse.ok) {

            throw new Error(
                donnees.message
                || 'Impossible de récupérer la progression.'
            );
        }

        return donnees;
    }

    /*
    |--------------------------------------------------------------------------
    | Affichage du résultat final
    |--------------------------------------------------------------------------
    */

    function afficherResultat(donnees) {

        if (
            typeof donnees.fichier !== 'string'
            || typeof donnees.url !== 'string'
        ) {
            throw new Error(
                'Le résultat retourné par le serveur est incomplet.'
            );
        }

        nomResultat.textContent = donnees.fichier;

        lienResultat.href = donnees.url;

        lienRecouper.href = urlBase
            + '?f='
            + encodeURIComponent(donnees.fichier);

        blocResultat.classList.add('visible');
    }

    /*
    |--------------------------------------------------------------------------
    | Arrêt du suivi de progression
    |--------------------------------------------------------------------------
    */

    function arreterSuiviProgression() {

        if (intervalleProgression !== null) {

            clearInterval(intervalleProgression);

            intervalleProgression = null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Suivi périodique de FFmpeg
    |--------------------------------------------------------------------------
    */

    function demarrerSuiviProgression(jobId) {

        arreterSuiviProgression();

        let requeteEnCours = false;

        intervalleProgression = setInterval(
            async function () {

                if (requeteEnCours) {
                    return;
                }

                requeteEnCours = true;

                try {

                    const donnees =
                        await recupererProgression(jobId);

                    actualiserProgression(donnees);

                    if (donnees.etat === 'termine') {

                        arreterSuiviProgression();

                    } else if (donnees.etat === 'erreur') {

                        arreterSuiviProgression();
                    }

                } catch (erreur) {

                    /*
                     * Une erreur réseau ponctuelle ne doit pas
                     * arrêter immédiatement le suivi.
                     *
                     * La réponse finale de la requête POST
                     * reste également surveillée.
                     */

                    console.error(
                        'Erreur de suivi :',
                        erreur
                    );

                } finally {

                    requeteEnCours = false;
                }

            },
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Soumission du découpage
    |--------------------------------------------------------------------------
    */

    formulaire.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();

            if (traitementEnCours) {
                return;
            }

            masquerMessage();

            const debut = Number(champDebut.value);

            const fin = Number(champFin.value);

            if (
                champDebut.value === ''
                || champFin.value === ''
                || !Number.isFinite(debut)
                || !Number.isFinite(fin)
                || debut < 0
                || fin <= debut
            ) {

                afficherMessage(
                    'Définis un début et une fin valides.',
                    'erreur'
                );

                return;
            }

            if (
                Number.isFinite(lecteur.duration)
                && fin > lecteur.duration
            ) {

                afficherMessage(
                    'La fin sélectionnée dépasse la durée de la vidéo.',
                    'erreur'
                );

                return;
            }

            const confirmation = confirm(
                'Créer une nouvelle vidéo avec cette sélection ?\n\n'
                + 'Début : ' + formaterTemps(debut) + '\n'
                + 'Fin : ' + formaterTemps(fin) + '\n'
                + 'Durée conservée : '
                + formaterTemps(fin - debut)
                + '\n\nLa vidéo originale sera conservée.'
            );

            if (!confirmation) {
                return;
            }

            let jobId;

            try {

                jobId = genererIdentifiant();

            } catch (erreur) {

                afficherMessage(
                    erreur.message,
                    'erreur'
                );

                return;
            }

            traitementEnCours = true;

            boutonCouper.disabled = true;

            boutonCouper.textContent =
                '⏳ Découpage en cours...';

            boutonDebut.disabled = true;

            boutonFin.disabled = true;

            boutonReinitialiser.disabled = true;

            lecteur.pause();

            blocResultat.classList.remove('visible');

            progressionBarre.style.width = '0%';

            progressionBarre.classList.remove(
                'terminee',
                'erreur'
            );

            progressionPourcentage.textContent = '0 %';

            progressionTemps.textContent = '00:00:00';

            progressionDuree.textContent =
                formaterTemps(fin - debut, false);

            progressionMessage.textContent =
                'Démarrage du découpage...';

            blocProgression.classList.add('visible');

            champJobId.value = jobId;

            /*
             * Démarrage du suivi avant la requête POST.
             *
             * Les premières réponses peuvent indiquer
             * que FFmpeg est en cours d'initialisation.
             */

            demarrerSuiviProgression(jobId);

            try {

                const donneesFormulaire =
                    new FormData(formulaire);

                const reponse = await fetch(
                    formulaire.action,
                    {
                        method: 'POST',
                        body: donneesFormulaire,
                        cache: 'no-store',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json'
                        }
                    }
                );

                const donnees = await reponse.json();

                if (!reponse.ok || donnees.etat === 'erreur') {

                    throw new Error(
                        donnees.message
                        || 'Une erreur est survenue pendant le découpage.'
                    );
                }

                actualiserProgression(donnees);

                if (donnees.etat !== 'termine') {

                    throw new Error(
                        'Le serveur n’a pas confirmé la fin du découpage.'
                    );
                }

                arreterSuiviProgression();

                afficherResultat(donnees);

                afficherMessage(
                    'La vidéo a été découpée avec succès.',
                    'succes'
                );

            } catch (erreur) {

                arreterSuiviProgression();

                progressionBarre.classList.add('erreur');

                progressionMessage.textContent =
                    erreur.message;

                afficherMessage(
                    erreur.message,
                    'erreur'
                );

            } finally {

                traitementEnCours = false;

                boutonCouper.textContent =
                    '✂️ Créer la vidéo coupée';

                boutonDebut.disabled = false;

                boutonFin.disabled = false;

                boutonReinitialiser.disabled = false;

                actualiserSelection();
            }
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Initialisation
    |--------------------------------------------------------------------------
    */

    actualiserPosition();

    actualiserSelection();

})();
</script>

</body>
</html>
