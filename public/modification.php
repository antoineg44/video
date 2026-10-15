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
        'Erreur droits modification vidéo : '
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

$extensionsAutorisees = [
    'mp4',
    'webm',
    'ogg',
];

/*
 * Page permettant de découper la vidéo.
 */

$pageCouperVideo = 'couper_video.php';

/*
|--------------------------------------------------------------------------
| Vérification du répertoire vidéo
|--------------------------------------------------------------------------
*/

if (
    !is_dir($repertoireVideos)
    || !is_readable($repertoireVideos)
) {
    http_response_code(500);
    exit('Le répertoire vidéo est inaccessible.');
}

/*
|--------------------------------------------------------------------------
| Récupération du nom de la vidéo
|--------------------------------------------------------------------------
*/

$nomVideo = $_GET['f'] ?? '';

if (
    !is_string($nomVideo)
    || $nomVideo === ''
    || basename($nomVideo) !== $nomVideo
    || str_contains($nomVideo, "\0")
) {
    http_response_code(400);
    exit('Nom de vidéo invalide.');
}

/*
|--------------------------------------------------------------------------
| Vérification de l'extension
|--------------------------------------------------------------------------
*/

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
| Vérification du fichier
|--------------------------------------------------------------------------
*/

$cheminVideo = $repertoireVideos . '/' . $nomVideo;

if (
    !is_file($cheminVideo)
    || !is_readable($cheminVideo)
) {
    http_response_code(404);
    exit('Vidéo introuvable.');
}

/*
|--------------------------------------------------------------------------
| Protection CSRF
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['modification_video_csrf'])
    || !is_string($_SESSION['modification_video_csrf'])
) {
    $_SESSION['modification_video_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['modification_video_csrf'];

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$message = '';
$typeMessage = 'info';

/*
|--------------------------------------------------------------------------
| Date actuelle du fichier
|--------------------------------------------------------------------------
*/

$dateModification = filemtime($cheminVideo);

if ($dateModification === false) {
    $dateModification = time();
}

/*
|--------------------------------------------------------------------------
| Valeur initiale du champ date
|--------------------------------------------------------------------------
*/

$dateFormulaire = date(
    'Y-m-d\TH:i',
    $dateModification
);

/*
|--------------------------------------------------------------------------
| Traitement du formulaire
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | Vérification CSRF
    |--------------------------------------------------------------------------
    */

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token)
        || !hash_equals(
            $csrfToken,
            $token
        )
    ) {
        http_response_code(403);
        exit('Jeton de sécurité invalide. Rechargez la page.');
    }

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Action : modifier la date
    |--------------------------------------------------------------------------
    */

    if ($action === 'modifier_date') {

        $dateSaisie = $_POST['date_creation'] ?? '';

        if (
            !is_string($dateSaisie)
            || $dateSaisie === ''
        ) {

            $message = 'Veuillez saisir une date.';
            $typeMessage = 'erreur';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Validation stricte de la date
            |--------------------------------------------------------------------------
            */

            $dateObjet = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                $dateSaisie
            );

            $erreursDate = DateTimeImmutable::getLastErrors();

            $dateValide = (
                $dateObjet !== false
                && (
                    $erreursDate === false
                    || (
                        $erreursDate['warning_count'] === 0
                        && $erreursDate['error_count'] === 0
                    )
                )
                && $dateObjet->format('Y-m-d\TH:i') === $dateSaisie
            );

            if (!$dateValide) {

                $message = 'La date saisie est invalide.';
                $typeMessage = 'erreur';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Format de la date pour touch
                |--------------------------------------------------------------------------
                |
                | La date saisie est interprétée dans le fuseau
                | horaire configuré sur le serveur PHP.
                |
                */

                $datePourTouch = $dateObjet->format(
                    'Y-m-d H:i:s'
                );

                /*
                |--------------------------------------------------------------------------
                | Vérification des permissions
                |--------------------------------------------------------------------------
                */

                if (!is_writable($cheminVideo)) {

                    $message = 'PHP ne dispose pas des permissions '
                        . 'nécessaires pour modifier la date de cette vidéo.';

                    $typeMessage = 'erreur';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Modification de la date avec touch
                    |--------------------------------------------------------------------------
                    |
                    | On modifie uniquement mtime.
                    | Le contenu de la vidéo n'est pas modifié.
                    |
                    */

                    $commande = '/usr/bin/touch -m -d '
                        . escapeshellarg($datePourTouch)
                        . ' '
                        . escapeshellarg($cheminVideo)
                        . ' 2>&1';

                    $sortie = [];

                    $codeRetour = 0;

                    exec(
                        $commande,
                        $sortie,
                        $codeRetour
                    );

                    if ($codeRetour === 0) {

                        clearstatcache(
                            true,
                            $cheminVideo
                        );

                        $nouvelleDate = filemtime(
                            $cheminVideo
                        );

                        if ($nouvelleDate !== false) {

                            $dateModification = $nouvelleDate;

                            $dateFormulaire = date(
                                'Y-m-d\TH:i',
                                $nouvelleDate
                            );

                            $message = 'La date de modification '
                                . 'de la vidéo a été mise à jour.';

                            $typeMessage = 'succes';

                        } else {

                            $message = 'La commande a été exécutée, '
                                . 'mais la nouvelle date est illisible.';

                            $typeMessage = 'erreur';
                        }

                    } else {

                        $detailsErreur = implode(
                            "\n",
                            $sortie
                        );

                        $message = 'Impossible de modifier la date '
                            . 'de la vidéo.';

                        if ($detailsErreur !== '') {
                            $message .= "\n" . $detailsErreur;
                        }

                        $typeMessage = 'erreur';

                        error_log(
                            'Erreur touch sur '
                            . $cheminVideo
                            . ' : '
                            . $detailsErreur
                        );
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Informations affichées
|--------------------------------------------------------------------------
*/

$nomAffiche = $nomVideo;

clearstatcache(
    true,
    $cheminVideo
);

$dateModificationActuelle = filemtime(
    $cheminVideo
);

if ($dateModificationActuelle === false) {
    $dateModificationActuelle = $dateModification;
}

$dateLisible = date(
    'd/m/Y à H:i:s',
    $dateModificationActuelle
);

/*
|--------------------------------------------------------------------------
| Lien vers couper_video.php
|--------------------------------------------------------------------------
*/

$urlCouperVideo = $pageCouperVideo
    . '?f='
    . rawurlencode($nomVideo);

?>
<!DOCTYPE html>
<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Modifier <?= e($nomAffiche) ?>
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            background: #f8fafc;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }

        .conteneur {
            max-width: 800px;
            margin: 0 auto;
        }

        /*
        |--------------------------------------------------------------------------
        | En-tête
        |--------------------------------------------------------------------------
        */

        .entete {
            margin-bottom: 25px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 24px;
            overflow-wrap: anywhere;
        }

        .nom-video {
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            color: #374151;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        /*
        |--------------------------------------------------------------------------
        | Cartes
        |--------------------------------------------------------------------------
        */

        .carte {
            margin-bottom: 20px;
            padding: 22px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 3px 12px rgba(15, 23, 42, .04);
        }

        .carte h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .description {
            margin: 0 0 20px;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.7;
        }

        /*
        |--------------------------------------------------------------------------
        | Formulaire
        |--------------------------------------------------------------------------
        */

        .groupe-champ {
            margin-bottom: 18px;
        }

        .groupe-champ label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
        }

        .champ-date {
            display: block;
            width: 100%;
            min-height: 45px;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #172033;
            font: inherit;
        }

        .champ-date:focus {
            outline: 3px solid rgba(37, 99, 235, .15);
            border-color: #2563eb;
        }

        /*
        |--------------------------------------------------------------------------
        | Boutons
        |--------------------------------------------------------------------------
        */

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .bouton {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 43px;
            padding: 11px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background .2s;
        }

        .bouton-principal {
            background: #2563eb;
            color: #fff;
        }

        .bouton-principal:hover {
            background: #1d4ed8;
        }

        .bouton-couper {
            background: #dc2626;
            color: #fff;
        }

        .bouton-couper:hover {
            background: #b91c1c;
        }

        /*
        |--------------------------------------------------------------------------
        | Messages
        |--------------------------------------------------------------------------
        */

        .message {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
            white-space: pre-line;
        }

        .message.succes {
            border-left: 4px solid #16a34a;
            background: #f0fdf4;
            color: #166534;
        }

        .message.erreur {
            border-left: 4px solid #dc2626;
            background: #fef2f2;
            color: #991b1b;
        }

        .message.info {
            border-left: 4px solid #2563eb;
            background: #eff6ff;
            color: #1e40af;
        }

        /*
        |--------------------------------------------------------------------------
        | Date actuelle
        |--------------------------------------------------------------------------
        */

        .information-date {
            margin-top: 18px;
            padding: 14px;
            border-radius: 8px;
            background: #f3f4f6;
            color: #4b5563;
            font-size: 13px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .date-valeur {
            color: #111827;
            font-weight: 700;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 500px) {

            body {
                padding: 14px;
            }

            h1 {
                font-size: 20px;
            }

            .carte {
                padding: 16px;
            }

            .actions {
                flex-direction: column;
            }

            .bouton {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<div class="conteneur">

    <!-- En-tête -->

    <header class="entete">

        <h1>✏️ Modifier la vidéo</h1>

        <div class="nom-video">
            <?= e($nomAffiche) ?>
        </div>

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

    <!-- Modification de la date -->

    <section class="carte">

        <h2>📅 Date de modification</h2>

        <p class="description">
            Choisis la date et l'heure à appliquer directement
            au fichier vidéo stocké sur le Synology.
            Le contenu de la vidéo ne sera pas modifié.
        </p>

        <form
            method="post"
            action="<?= e(
                basename(__FILE__)
                . '?f='
                . rawurlencode($nomVideo)
            ) ?>"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="modifier_date"
            >

            <div class="groupe-champ">

                <label for="date_creation">
                    Nouvelle date et heure
                </label>

                <input
                    type="datetime-local"
                    id="date_creation"
                    name="date_creation"
                    class="champ-date"
                    value="<?= e($dateFormulaire) ?>"
                    required
                >

            </div>

            <div class="actions">

                <button
                    type="submit"
                    class="bouton bouton-principal"
                >
                    💾 Enregistrer la date
                </button>

            </div>

        </form>

        <div class="information-date">

            <strong>Date actuellement enregistrée :</strong>

            <br>

            <span class="date-valeur">
                <?= e($dateLisible) ?>
            </span>

            <br><br>

            Cette date correspond à la date de dernière modification
            du fichier (<code>mtime</code>), et non à sa véritable
            date de création.

        </div>

    </section>

    <!-- Découpage de la vidéo -->

    <section class="carte">

        <h2>✂️ Découper la vidéo</h2>

        <p class="description">
            Ouvre l'outil de découpage pour sélectionner
            une partie de cette vidéo.
        </p>

        <div class="actions">

            <a
                class="bouton bouton-couper"
                href="<?= e($urlCouperVideo) ?>"
                target="_blank"
                rel="noopener"
            >
                ✂️ Ouvrir couper_video.php
            </a>

        </div>

    </section>

</div>

</body>
</html>
