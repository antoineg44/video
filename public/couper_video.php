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
| Fonction d'échappement HTML
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

    exit('Erreur lors de la vérification des autorisations.');
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
|
| Exemple :
| couper_video.php?f=film.mp4
|
*/

$nomVideo = $_GET['f'] ?? $_POST['f'] ?? '';

if (!is_string($nomVideo) || $nomVideo === '') {
    http_response_code(400);
    exit('Aucune vidéo n\'a été spécifiée dans l\'URL.');
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

$cheminVideo = $repertoireVideos . '/' . $nomVideo;

$repertoireReel = realpath($repertoireVideos);

$videoReelle = realpath($cheminVideo);

if (
    $repertoireReel === false
    || $videoReelle === false
    || dirname($videoReelle) !== $repertoireReel
    || !is_file($videoReelle)
    || !is_readable($videoReelle)
) {
    http_response_code(404);
    exit('Vidéo introuvable ou inaccessible.');
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
| Variables de traitement
|--------------------------------------------------------------------------
*/

$message = '';
$typeMessage = 'info';

$fichierResultat = '';

$debut = 0.0;
$fin = 0.0;

/*
|--------------------------------------------------------------------------
| Traitement de la demande de découpage
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
        http_response_code(403);
        exit('Requête invalide. Veuillez recharger la page.');
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
        $message = 'Les positions de début et de fin sont invalides.';
        $typeMessage = 'erreur';

    } else {

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
            $message = 'Les positions de coupe sont incorrectes. '
                . 'La fin doit être supérieure au début.';

            $typeMessage = 'erreur';

        } elseif (!is_executable($ffmpeg)) {

            $message = 'FFmpeg est introuvable ou inexécutable : '
                . $ffmpeg;

            $typeMessage = 'erreur';

        } elseif (!is_writable($repertoireVideos)) {

            $message = 'Le répertoire vidéo n\'est pas accessible en écriture.';

            $typeMessage = 'erreur';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Préparation du nom de sortie
            |--------------------------------------------------------------------------
            |
            | La vidéo originale n'est jamais écrasée.
            |
            */

            $nomSansExtension = pathinfo(
                $nomVideo,
                PATHINFO_FILENAME
            );

            try {

                $identifiantUnique = bin2hex(
                    random_bytes(4)
                );

            } catch (Throwable $exception) {

                $identifiantUnique = uniqid();

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
            | Fichier temporaire
            |--------------------------------------------------------------------------
            */

            $cheminTemporaire = $repertoireVideos
                . '/.'
                . bin2hex(random_bytes(12))
                . '.part.mp4';

            $fichierErreur = tempnam(
                sys_get_temp_dir(),
                'ffmpeg_coupe_'
            );

            if ($fichierErreur === false) {

                $message = 'Impossible de créer le fichier temporaire '
                    . 'pour les erreurs FFmpeg.';

                $typeMessage = 'erreur';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Calcul de la durée à conserver
                |--------------------------------------------------------------------------
                */

                $duree = $fin - $debut;

                /*
                |--------------------------------------------------------------------------
                | Construction de la commande FFmpeg
                |--------------------------------------------------------------------------
                |
                | Les arguments sont transmis séparément.
                | Aucun passage par le shell n'est nécessaire.
                |
                | La vidéo est réencodée pour obtenir une coupe
                | précise, indépendamment des images clés d'origine.
                |
                */

                $commande = [
                    $ffmpeg,

                    '-hide_banner',
                    '-loglevel', 'error',
                    '-nostdin',
                    '-y',

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
                        'file',
                        '/dev/null',
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
                | Exécution de FFmpeg
                |--------------------------------------------------------------------------
                */

                @set_time_limit(0);

                $processus = false;

                $codeRetour = -1;

                try {

                    $processus = proc_open(
                        $commande,
                        $descripteurs,
                        $tuyaux
                    );

                    if (is_resource($processus)) {

                        $codeRetour = proc_close(
                            $processus
                        );
                    }

                } catch (Throwable $exception) {

                    $message = 'Erreur pendant l\'exécution de FFmpeg : '
                        . $exception->getMessage();

                    $typeMessage = 'erreur';

                    error_log(
                        'Découpage vidéo : '
                        . $exception->getMessage()
                    );
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

                $resultatValide = (
                    $codeRetour === 0
                    && is_file($cheminTemporaire)
                    && filesize($cheminTemporaire) !== false
                    && filesize($cheminTemporaire) > 0
                );

                /*
                |--------------------------------------------------------------------------
                | Récupération des erreurs FFmpeg
                |--------------------------------------------------------------------------
                */

                $detailsErreur = '';

                if (is_file($fichierErreur)) {

                    $contenuErreur = file_get_contents(
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
                | Finalisation
                |--------------------------------------------------------------------------
                */

                if ($resultatValide) {

                    /*
                     * Déplacement du fichier temporaire vers
                     * son nom définitif.
                     */

                    if (rename(
                        $cheminTemporaire,
                        $cheminResultat
                    )) {

                        $fichierResultat = $nomResultat;

                        $message = 'La vidéo a été découpée avec succès.'
                            . "\n\n"
                            . 'Début : '
                            . number_format(
                                $debut,
                                2,
                                ',',
                                ' '
                            )
                            . ' s'
                            . "\n"
                            . 'Fin : '
                            . number_format(
                                $fin,
                                2,
                                ',',
                                ' '
                            )
                            . ' s'
                            . "\n"
                            . 'Durée conservée : '
                            . number_format(
                                $duree,
                                2,
                                ',',
                                ' '
                            )
                            . ' s';

                        $typeMessage = 'succes';

                        /*
                         * Nouveau jeton pour empêcher une
                         * nouvelle soumission accidentelle.
                         */

                        $_SESSION['couper_video_csrf'] = bin2hex(
                            random_bytes(32)
                        );

                    } else {

                        @unlink($cheminTemporaire);

                        $message = 'La vidéo a été générée, '
                            . 'mais impossible de déplacer le fichier '
                            . 'vers sa destination définitive.';

                        $typeMessage = 'erreur';
                    }

                } else {

                    @unlink($cheminTemporaire);

                    $message = 'FFmpeg n\'a pas réussi à découper la vidéo.'
                        . "\n\n"
                        . 'Code de retour : '
                        . $codeRetour;

                    if ($detailsErreur !== '') {

                        /*
                         * Limitation de la taille du message
                         * affiché sur la page.
                         */

                        $message .= "\n\nDétails FFmpeg :\n"
                            . substr(
                                $detailsErreur,
                                -6000
                            );
                    }

                    $typeMessage = 'erreur';

                    error_log(
                        'Erreur de découpage vidéo : '
                        . $nomVideo
                        . ' | Code : '
                        . $codeRetour
                        . ' | '
                        . $detailsErreur
                    );
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| URL de lecture
|--------------------------------------------------------------------------
*/

$urlVideo = $urlLecture
    . '?f='
    . rawurlencode($nomVideo);

$urlResultat = '';

if ($fichierResultat !== '') {

    $urlResultat = $urlLecture
        . '?f='
        . rawurlencode($fichierResultat);
}

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

        /*
        |--------------------------------------------------------------------------
        | Lecteur vidéo
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Positions de coupe
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Boutons
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Résumé de la sélection
        |--------------------------------------------------------------------------
        */

        .resume {
            margin: 18px 0;
            padding: 15px;
            border-radius: 8px;
            background: #eff6ff;
            color: #1e40af;
            line-height: 1.7;
        }

        /*
        |--------------------------------------------------------------------------
        | Messages
        |--------------------------------------------------------------------------
        */

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
        | Résultat
        |--------------------------------------------------------------------------
        */

        .resultat {
            margin-top: 18px;
            padding: 18px;
            border-radius: 8px;
            background: #f0fdf4;
        }

        .resultat h3 {
            margin: 0 0 12px;
            color: #166534;
        }

        /*
        |--------------------------------------------------------------------------
        | Aide
        |--------------------------------------------------------------------------
        */

        .aide {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.7;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

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
        }

    </style>

</head>

<body>

<div class="conteneur">

    <!-- En-tête -->

    <header class="entete">

        <h1>✂️ Couper une vidéo</h1>

        <a
            class="retour"
            href="videos.php"
        >
            ← Retour à la bibliothèque
        </a>

    </header>

    <!-- Messages -->

    <?php if ($message !== ''): ?>

        <div
            class="message <?= e($typeMessage) ?>"
            role="status"
        >
            <?= e($message) ?>
        </div>

    <?php endif; ?>

    <!-- Lecteur -->

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
            puis utilise les boutons pour enregistrer le début et la fin
            du passage à conserver.
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
                value="<?= e($_SESSION['couper_video_csrf']) ?>"
            >

            <input
                type="hidden"
                name="debut"
                id="champDebut"
                value="<?= e((string) $debut) ?>"
            >

            <input
                type="hidden"
                name="fin"
                id="champFin"
                value="<?= e((string) $fin) ?>"
            >

            <div class="positions">

                <!-- Début -->

                <div class="position">

                    <h3>🟢 Début du passage</h3>

                    <div
                        class="temps"
                        id="affichageDebut"
                    >
                        00:00:00.000
                    </div>

                    <button
                        type="button"
                        class="bouton bouton-debut"
                        id="boutonDebut"
                    >
                        Définir le début ici
                    </button>

                </div>

                <!-- Fin -->

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

            <!-- Résumé -->

            <div
                class="resume"
                id="resumeSelection"
                aria-live="polite"
            >
                Sélectionne un début et une fin pour afficher
                la durée du passage à conserver.
            </div>

            <!-- Actions -->

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

        <!-- Aide complémentaire -->

        <p class="aide">
            La vidéo originale sera conservée.
            Le résultat sera enregistré dans le répertoire vidéo
            sous un nouveau nom se terminant par
            <strong>_coupee</strong>.
        </p>

    </section>

    <!-- Résultat généré -->

    <?php if ($fichierResultat !== ''): ?>

        <section class="carte resultat">

            <h3>✅ Vidéo créée</h3>

            <p>
                Fichier :
                <strong><?= e($fichierResultat) ?></strong>
            </p>

            <div class="boutons">

                <a
                    class="bouton bouton-principal"
                    href="<?= e($urlResultat) ?>"
                    target="_blank"
                    rel="noopener"
                >
                    ▶ Lire la vidéo coupée
                </a>

                <a
                    class="bouton bouton-secondaire"
                    href="couper_video.php?f=<?= rawurlencode($fichierResultat) ?>"
                >
                    Couper cette vidéo
                </a>

            </div>

        </section>

    <?php endif; ?>

</div>

<script>
(function () {

    'use strict';

    const lecteur = document.getElementById('lecteurVideo');

    const champDebut = document.getElementById('champDebut');

    const champFin = document.getElementById('champFin');

    const affichageDebut = document.getElementById('affichageDebut');

    const affichageFin = document.getElementById('affichageFin');

    const positionActuelle = document.getElementById('positionActuelle');

    const dureeTotale = document.getElementById('dureeTotale');

    const resumeSelection = document.getElementById('resumeSelection');

    const boutonDebut = document.getElementById('boutonDebut');

    const boutonFin = document.getElementById('boutonFin');

    const boutonReinitialiser = document.getElementById('boutonReinitialiser');

    const boutonCouper = document.getElementById('boutonCouper');

    const formulaire = document.getElementById('formulaireCoupe');

    /*
    |--------------------------------------------------------------------------
    | Formatage d'une durée
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

        const heuresTexte = String(heures).padStart(2, '0');

        const minutesTexte = String(minutes).padStart(2, '0');

        const secondesTexte = String(secondesEntieres).padStart(2, '0');

        let resultat = heuresTexte
            + ':'
            + minutesTexte
            + ':'
            + secondesTexte;

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
    | Mise à jour de l'affichage
    |--------------------------------------------------------------------------
    */

    function actualiserPosition() {

        positionActuelle.textContent = formaterTemps(
            lecteur.currentTime || 0
        );
    }

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

        if (
            debut !== null
            && fin !== null
            && Number.isFinite(debut)
            && Number.isFinite(fin)
            && fin > debut
        ) {

            const duree = fin - debut;

            resumeSelection.textContent =
                'Début : '
                + formaterTemps(debut)
                + ' | Fin : '
                + formaterTemps(fin)
                + ' | Durée conservée : '
                + formaterTemps(duree);

            boutonCouper.disabled = false;

        } else {

            resumeSelection.textContent =
                'Sélectionne un début et une fin valides. '
                + 'La fin doit être postérieure au début.';

            boutonCouper.disabled = true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Chargement des métadonnées
    |--------------------------------------------------------------------------
    */

    lecteur.addEventListener('loadedmetadata', function () {

        if (Number.isFinite(lecteur.duration)) {

            dureeTotale.textContent = formaterTemps(
                lecteur.duration,
                false
            );

        } else {

            dureeTotale.textContent = 'Indisponible';
        }

        actualiserPosition();
    });

    lecteur.addEventListener(
        'timeupdate',
        actualiserPosition
    );

    lecteur.addEventListener(
        'seeked',
        actualiserPosition
    );

    /*
    |--------------------------------------------------------------------------
    | Définir le début
    |--------------------------------------------------------------------------
    */

    boutonDebut.addEventListener('click', function () {

        const position = lecteur.currentTime;

        if (!Number.isFinite(position)) {
            return;
        }

        champDebut.value = position.toFixed(3);

        /*
         * Si le début dépasse la fin déjà définie,
         * la fin est réinitialisée.
         */

        if (
            champFin.value !== ''
            && Number(champFin.value) <= position
        ) {

            champFin.value = '';
        }

        actualiserSelection();
    });

    /*
    |--------------------------------------------------------------------------
    | Définir la fin
    |--------------------------------------------------------------------------
    */

    boutonFin.addEventListener('click', function () {

        const position = lecteur.currentTime;

        if (!Number.isFinite(position)) {
            return;
        }

        champFin.value = position.toFixed(3);

        actualiserSelection();
    });

    /*
    |--------------------------------------------------------------------------
    | Réinitialiser les points de coupe
    |--------------------------------------------------------------------------
    */

    boutonReinitialiser.addEventListener('click', function () {

        champDebut.value = '';

        champFin.value = '';

        actualiserSelection();
    });

    /*
    |--------------------------------------------------------------------------
    | Validation avant envoi
    |--------------------------------------------------------------------------
    */

    formulaire.addEventListener('submit', function (event) {

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

            event.preventDefault();

            alert(
                'Définis un début et une fin valides avant de couper la vidéo.'
            );

            return;
        }

        if (
            Number.isFinite(lecteur.duration)
            && fin > lecteur.duration
        ) {

            event.preventDefault();

            alert(
                'La fin sélectionnée dépasse la durée de la vidéo.'
            );

            return;
        }

        const confirmation = confirm(
            'Créer une nouvelle vidéo avec cette sélection ?\n\n'
            + 'Début : ' + formaterTemps(debut) + '\n'
            + 'Fin : ' + formaterTemps(fin) + '\n'
            + 'Durée conservée : ' + formaterTemps(fin - debut)
            + '\n\nLa vidéo originale sera conservée.'
        );

        if (!confirmation) {

            event.preventDefault();

            return;
        }

        boutonCouper.disabled = true;

        boutonCouper.textContent = '⏳ Découpage en cours...';
    });

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
