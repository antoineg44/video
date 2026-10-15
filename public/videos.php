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

// Répertoire contenant les vidéos.
$repertoireVideos = '/mnt/video';

// Répertoire contenant les miniatures.
$repertoireMiniatures = __DIR__ . '/thumbnails';

// URL publique du répertoire des miniatures.
$urlMiniatures = '/thumbnails';

// Script de synchronisation des miniatures.
$scriptMiseAJour = __DIR__ . '/update_thumbnails.php';

// Page de modification d'une vidéo.
$pageModification = 'modification.php';

/*
|--------------------------------------------------------------------------
| Protection CSRF
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['videos_csrf_token'])
    || !is_string($_SESSION['videos_csrf_token'])
) {
    $_SESSION['videos_csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

$message = '';
$typeMessage = 'info';

/*
|--------------------------------------------------------------------------
| Mode debug
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'toggle_debug'
) {
    $_SESSION['videos_mode_debug'] = (
        ($_POST['mode_debug'] ?? '0') === '1'
    );
}

$modeDebug = (
    $_SESSION['videos_mode_debug'] ?? false
) === true;

/*
|--------------------------------------------------------------------------
| Mise à jour des miniatures
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'update_thumbnails'
) {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token)
        || !hash_equals(
            $_SESSION['videos_csrf_token'],
            $token
        )
    ) {
        http_response_code(403);
        exit('Requête invalide. Veuillez recharger la page.');
    }

    if (!is_file($scriptMiseAJour)) {

        $message = 'Le script de mise à jour est introuvable.';
        $typeMessage = 'erreur';

        error_log(
            'Script introuvable : '
            . $scriptMiseAJour
        );

    } elseif (!is_dir($repertoireMiniatures)) {

        $message = 'Le répertoire des miniatures est introuvable.';
        $typeMessage = 'erreur';

    } elseif (!is_writable($repertoireMiniatures)) {

        $message = 'Le répertoire des miniatures n\'est pas accessible en écriture.';
        $typeMessage = 'erreur';

    } elseif (!function_exists('exec')) {

        $message = 'La fonction exec() est désactivée dans PHP.';
        $typeMessage = 'erreur';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Journal de synchronisation
        |--------------------------------------------------------------------------
        */

        $fichierJournal = $repertoireMiniatures
            . '/update_thumbnails.log';

        if (
            !is_dir($repertoireMiniatures)
            || !is_writable($repertoireMiniatures)
        ) {

            $message = 'Le répertoire des miniatures ne permet pas '
                . 'de créer le journal de synchronisation.';

            $typeMessage = 'erreur';

        } elseif (
            file_put_contents(
                $fichierJournal,
                '[' . date('Y-m-d H:i:s') . "] Début de la synchronisation.\n",
                FILE_APPEND
            ) === false
        ) {

            $message = 'Impossible de créer le journal de synchronisation.';
            $typeMessage = 'erreur';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Configuration de PHP CLI
            |--------------------------------------------------------------------------
            */

            $phpCli = '/usr/bin/php';

            if (
                !is_file($phpCli)
                || !is_executable($phpCli)
            ) {

                $message = 'PHP CLI est introuvable ou inexécutable : '
                    . $phpCli;

                $typeMessage = 'erreur';

                error_log($message);

            } elseif (!is_writable($fichierJournal)) {

                $message = 'Le journal de synchronisation '
                    . 'n\'est pas accessible en écriture.';

                $typeMessage = 'erreur';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Construction de la commande
                |--------------------------------------------------------------------------
                */

                $commande = 'nohup '
                    . escapeshellarg($phpCli)
                    . ' '
                    . escapeshellarg($scriptMiseAJour)
                    . ' >> '
                    . escapeshellarg($fichierJournal)
                    . ' 2>&1 < /dev/null &';

                /*
                |--------------------------------------------------------------------------
                | Lancement de la synchronisation
                |--------------------------------------------------------------------------
                */

                $sortie = [];
                $codeRetour = 0;

                exec(
                    $commande,
                    $sortie,
                    $codeRetour
                );

                if ($codeRetour === 0) {

                    $message = 'La commande de synchronisation a été envoyée.'
                        . "\n\n"
                        . 'Consulte le journal ci-dessous pour vérifier '
                        . 'que le script démarre et termine correctement.';

                    $typeMessage = 'succes';

                } else {

                    $message = 'Impossible de lancer la synchronisation.'
                        . "\n\n"
                        . 'Code de retour : '
                        . $codeRetour;

                    $typeMessage = 'erreur';

                    error_log(
                        'Échec du lancement des miniatures. '
                        . 'Code : '
                        . $codeRetour
                    );
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Vérification du répertoire vidéo
|--------------------------------------------------------------------------
*/

if (!is_dir($repertoireVideos)) {

    error_log(
        'Le répertoire vidéo est introuvable : '
        . $repertoireVideos
    );

    http_response_code(500);

    exit('Le répertoire vidéo est introuvable.');
}

if (!is_readable($repertoireVideos)) {

    error_log(
        'Le répertoire vidéo est inaccessible à PHP.'
    );

    http_response_code(500);

    exit('Le répertoire vidéo est inaccessible.');
}

/*
|--------------------------------------------------------------------------
| Paramètres de tri
|--------------------------------------------------------------------------
|
| tri :
|   - nom
|   - date
|
| ordre :
|   - asc
|   - desc
|
*/

$triAutorise = [
    'nom',
    'date',
];

$ordreAutorise = [
    'asc',
    'desc',
];

$tri = $_GET['tri'] ?? 'nom';

$ordre = $_GET['ordre'] ?? 'asc';

if (
    !is_string($tri)
    || !in_array($tri, $triAutorise, true)
) {
    $tri = 'nom';
}

if (
    !is_string($ordre)
    || !in_array($ordre, $ordreAutorise, true)
) {
    $ordre = 'asc';
}

/*
|--------------------------------------------------------------------------
| Lecture des vidéos
|--------------------------------------------------------------------------
*/

$videos = [];

$fichiers = scandir($repertoireVideos);

if ($fichiers === false) {

    error_log(
        'Impossible de parcourir le répertoire vidéo.'
    );

    http_response_code(500);

    exit('Impossible de lire la bibliothèque vidéo.');
}

$extensionsAutorisees = [
    'mp4',
    'webm',
    'ogg',
];

foreach ($fichiers as $fichier) {

    if (
        $fichier === '.'
        || $fichier === '..'
    ) {
        continue;
    }

    $chemin = $repertoireVideos . '/' . $fichier;

    /*
    |--------------------------------------------------------------------------
    | Vérification du fichier
    |--------------------------------------------------------------------------
    */

    if (
        !is_file($chemin)
        || !is_readable($chemin)
    ) {
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Vérification de l'extension
    |--------------------------------------------------------------------------
    */

    $extension = strtolower(
        pathinfo(
            $fichier,
            PATHINFO_EXTENSION
        )
    );

    if (!in_array(
        $extension,
        $extensionsAutorisees,
        true
    )) {
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Date de référence de la vidéo
    |--------------------------------------------------------------------------
    |
    | filemtime() retourne la date de dernière modification
    | du fichier.
    |
    */

    clearstatcache(true, $chemin);

    $dateCreation = filemtime($chemin);

    if ($dateCreation === false) {
        $dateCreation = 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Ajout de la vidéo au tableau
    |--------------------------------------------------------------------------
    */

    $videos[] = [
        'nom' => $fichier,
        'chemin' => $chemin,
        'extension' => $extension,
        'date_creation' => $dateCreation,
    ];
}

/*
|--------------------------------------------------------------------------
| Paramètres de tri
|--------------------------------------------------------------------------
*/

$triAutorise = [
    'nom',
    'date',
];

$ordreAutorise = [
    'asc',
    'desc',
];

$tri = $_GET['tri'] ?? 'nom';

$ordre = $_GET['ordre'] ?? 'asc';

if (
    !is_string($tri)
    || !in_array($tri, $triAutorise, true)
) {
    $tri = 'nom';
}

if (
    !is_string($ordre)
    || !in_array($ordre, $ordreAutorise, true)
) {
    $ordre = 'asc';
}

/*
|--------------------------------------------------------------------------
| Tri des vidéos
|--------------------------------------------------------------------------
*/

usort(
    $videos,
    static function (
        array $a,
        array $b
    ) use ($tri, $ordre): int {

        /*
        |--------------------------------------------------------------------------
        | Tri par nom
        |--------------------------------------------------------------------------
        */

        if ($tri === 'nom') {

            $comparaison = strnatcasecmp(
                $a['nom'],
                $b['nom']
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | Tri par date
            |--------------------------------------------------------------------------
            */

            $comparaison = (
                $a['date_creation']
                <=>
                $b['date_creation']
            );

            /*
             * En cas d'égalité, départage par nom.
             */

            if ($comparaison === 0) {

                $comparaison = strnatcasecmp(
                    $a['nom'],
                    $b['nom']
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Ordre ascendant ou descendant
        |--------------------------------------------------------------------------
        */

        if ($ordre === 'desc') {
            $comparaison *= -1;
        }

        return $comparaison;
    }
);

?>
<!DOCTYPE html>
<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Bibliothèque vidéo</title>

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

        button,
        input,
        select {
            font: inherit;
        }

        .conteneur {
            max-width: 1600px;
            margin: 0 auto;
        }

        /*
        |--------------------------------------------------------------------------
        | En-tête principal
        |--------------------------------------------------------------------------
        */

        .entete {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .compteur {
            color: #6b7280;
            margin: 12px 0 24px;
        }

        /*
        |--------------------------------------------------------------------------
        | Outils de la bibliothèque
        |--------------------------------------------------------------------------
        */

        .outils-video {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 18px;
            flex-wrap: wrap;
        }

        .formulaire-debug,
        .formulaire-mise-a-jour {
            margin: 0;
        }

        .label-debug {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #4b5563;
            font-size: 14px;
            cursor: pointer;
            user-select: none;
        }

        .label-debug input {
            width: 17px;
            height: 17px;
            margin: 0;
            accent-color: #2563eb;
            cursor: pointer;
        }

        /*
        |--------------------------------------------------------------------------
        | Bouton de mise à jour
        |--------------------------------------------------------------------------
        */

        .bouton-mise-a-jour {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 18px;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition:
                background .2s,
                transform .2s;
        }

        .bouton-mise-a-jour:hover {
            background: #1d4ed8;
        }

        .bouton-mise-a-jour:active {
            transform: scale(.98);
        }

        .bouton-mise-a-jour:disabled {
            opacity: .6;
            cursor: wait;
        }

        /*
        |--------------------------------------------------------------------------
        | Barre de tri
        |--------------------------------------------------------------------------
        */

        .barre-tri {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 24px;
            padding: 16px 18px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(20, 35, 65, .04);
        }

        .barre-tri-titre {
            font-size: 14px;
            font-weight: 700;
            color: #374151;
        }

        .formulaire-tri {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin: 0;
        }

        .formulaire-tri label {
            font-size: 13px;
            color: #6b7280;
        }

        .formulaire-tri select {
            min-height: 38px;
            padding: 8px 30px 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            background: #fff;
            color: #172033;
            cursor: pointer;
        }

        .bouton-tri {
            min-height: 38px;
            padding: 8px 14px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }

        .bouton-tri:hover {
            background: #1d4ed8;
        }

        /*
        |--------------------------------------------------------------------------
        | Messages
        |--------------------------------------------------------------------------
        */

        .message {
            white-space: pre-line;
            overflow-wrap: anywhere;
            padding: 15px 18px;
            margin: 20px 0;
            border-radius: 8px;
            background: #fff;
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
        | Grille des vidéos
        |--------------------------------------------------------------------------
        */

        .liste-videos {
            display: grid;
            grid-template-columns:
                repeat(auto-fill, minmax(240px, 1fr));
            gap: 22px;
        }

        /*
        |--------------------------------------------------------------------------
        | Carte vidéo
        |--------------------------------------------------------------------------
        */

        .video {
            min-width: 0;
            overflow: hidden;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(20, 35, 65, .06);
            transition:
                transform .2s,
                box-shadow .2s;
        }

        .video:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(20, 35, 65, .12);
        }

        /*
        |--------------------------------------------------------------------------
        | En-tête de chaque vidéo
        |--------------------------------------------------------------------------
        */

        .entete-video {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            padding: 13px 13px 12px;
            border-bottom: 1px solid #edf0f5;
            background: #fff;
        }

        .entete-video-informations {
            flex: 1;
            min-width: 0;
        }

        .titre-video {
            display: block;
            margin: 0 0 7px;
            color: #172033;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .date-video {
            display: block;
            color: #6b7280;
            font-size: 11px;
            line-height: 1.5;
        }

        /*
        |--------------------------------------------------------------------------
        | Bouton crayon
        |--------------------------------------------------------------------------
        */

        .bouton-modifier {
            display: inline-flex;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            padding: 0;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #f8fafc;
            color: #2563eb;
            cursor: pointer;
            transition:
                background .2s,
                color .2s,
                border-color .2s,
                transform .2s;
        }

        .bouton-modifier:hover {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
            transform: scale(1.05);
        }

        .bouton-modifier:focus-visible {
            outline: 3px solid rgba(37, 99, 235, .3);
            outline-offset: 2px;
        }

        .bouton-modifier svg {
            width: 17px;
            height: 17px;
            display: block;
        }

        /*
        |--------------------------------------------------------------------------
        | Miniature
        |--------------------------------------------------------------------------
        */

        .lien-miniature {
            display: block;
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            overflow: hidden;
            background: #e5e7eb;
        }

        .miniature {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform .25s;
        }

        .video:hover .miniature {
            transform: scale(1.04);
        }

        .miniature-indisponible {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 8px;
            width: 100%;
            height: 100%;
            color: #6b7280;
            font-size: 14px;
            background: #e5e7eb;
        }

        .icone-video {
            font-size: 36px;
        }

        .icone-lecture {
            position: absolute;
            top: 50%;
            left: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(0, 0, 0, .65);
            color: #fff;
            font-size: 20px;
            transform: translate(-50%, -50%);
            transition:
                background .2s,
                transform .2s;
        }

        .video:hover .icone-lecture {
            background: #2563eb;
            transform: translate(-50%, -50%) scale(1.08);
        }

        /*
        |--------------------------------------------------------------------------
        | Informations de la vidéo
        |--------------------------------------------------------------------------
        */

        .video-informations {
            padding: 13px;
        }

        .nom-video {
            display: block;
            color: #2563eb;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.5;
            text-decoration: none;
            overflow-wrap: anywhere;
        }

        .nom-video:hover {
            text-decoration: underline;
        }

        .extension-video {
            display: inline-block;
            margin-top: 9px;
            padding: 4px 7px;
            border-radius: 4px;
            background: #f3f4f6;
            color: #6b7280;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        /*
        |--------------------------------------------------------------------------
        | Bibliothèque vide
        |--------------------------------------------------------------------------
        */

        .bibliotheque-vide {
            padding: 35px 20px;
            background: #fff;
            border-radius: 12px;
            color: #6b7280;
            text-align: center;
        }

        /*
        |--------------------------------------------------------------------------
        | Journal de synchronisation
        |--------------------------------------------------------------------------
        */

        .journal-synchronisation {
            margin: 20px 0;
            padding: 20px;
            background: #111827;
            border-radius: 10px;
            color: #e5e7eb;
        }

        .journal-synchronisation h2 {
            margin: 0 0 15px;
            font-size: 18px;
            color: #fff;
        }

        .journal-synchronisation pre {
            margin: 0 0 15px;
            padding: 15px;
            background: #030712;
            border-radius: 6px;
            color: #d1fae5;
            font-family: Consolas, Monaco, monospace;
            font-size: 12px;
            line-height: 1.7;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            overflow-x: auto;
            max-height: 450px;
            overflow-y: auto;
        }

        .bouton-journal {
            padding: 9px 14px;
            border: none;
            border-radius: 6px;
            background: #374151;
            color: #fff;
            cursor: pointer;
        }

        .bouton-journal:hover {
            background: #4b5563;
        }

        /*
        |--------------------------------------------------------------------------
        | Fenêtre modale de modification
        |--------------------------------------------------------------------------
        */

        .modale {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, .72);
            backdrop-filter: blur(3px);
        }

        .modale.ouverte {
            display: flex;
        }

        .modale-contenu {
            display: flex;
            flex-direction: column;
            width: min(100%, 1000px);
            height: min(90vh, 800px);
            overflow: hidden;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .3);
            animation: apparition-modale .18s ease-out;
        }

        .modale-entete {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            min-height: 58px;
            padding: 12px 18px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .modale-titre {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: #172033;
        }

        .bouton-fermer-modale {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 8px;
            background: #e5e7eb;
            color: #374151;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
        }

        .bouton-fermer-modale:hover {
            background: #d1d5db;
            color: #111827;
        }

        .modale-iframe {
            display: block;
            flex: 1;
            width: 100%;
            min-height: 0;
            border: none;
            background: #fff;
        }

        @keyframes apparition-modale {
            from {
                opacity: 0;
                transform: translateY(8px) scale(.99);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 600px) {

            body {
                padding: 15px;
            }

            .entete {
                align-items: stretch;
                flex-direction: column;
            }

            h1 {
                font-size: 23px;
            }

            .outils-video {
                align-items: stretch;
                flex-direction: column;
                gap: 14px;
            }

            .bouton-mise-a-jour {
                width: 100%;
            }

            .barre-tri {
                align-items: stretch;
                flex-direction: column;
            }

            .formulaire-tri {
                display: grid;
                grid-template-columns: 1fr;
                align-items: stretch;
            }

            .formulaire-tri select,
            .bouton-tri {
                width: 100%;
            }

            .liste-videos {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .entete-video {
                padding: 10px;
                gap: 6px;
            }

            .titre-video {
                font-size: 12px;
            }

            .date-video {
                font-size: 10px;
            }

            .bouton-modifier {
                width: 30px;
                height: 30px;
            }

            .bouton-modifier svg {
                width: 15px;
                height: 15px;
            }

            .video-informations {
                padding: 10px;
            }

            .nom-video {
                font-size: 12px;
            }

            .icone-lecture {
                width: 38px;
                height: 38px;
                font-size: 16px;
            }

            .modale {
                padding: 8px;
            }

            .modale-contenu {
                height: 94vh;
                border-radius: 8px;
            }

            .modale-entete {
                padding: 10px 12px;
            }

            .modale-titre {
                font-size: 15px;
            }
        }

        @media (max-width: 360px) {

            .liste-videos {
                grid-template-columns: 1fr;
            }
        }

    </style>

</head>

<body>

<div class="conteneur">

    <!-- En-tête principal -->

    <header class="entete">

        <h1>Bibliothèque vidéo</h1>

        <div class="outils-video">

            <!-- Activation du mode debug -->

            <form
                method="post"
                action=""
                class="formulaire-debug"
            >

                <input
                    type="hidden"
                    name="action"
                    value="toggle_debug"
                >

                <label class="label-debug">

                    <input
                        type="checkbox"
                        name="mode_debug"
                        value="1"
                        <?= $modeDebug ? 'checked' : '' ?>
                        onchange="activerModeDebug(this)"
                    >

                    <span>Mode debug</span>

                </label>

            </form>

            <!-- Bouton de mise à jour visible uniquement en mode debug -->

            <?php if ($modeDebug): ?>

                <form
                    method="post"
                    action=""
                    class="formulaire-mise-a-jour"
                    onsubmit="return confirmerMiseAJour(this);"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="update_thumbnails"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['videos_csrf_token']) ?>"
                    >

                    <input
                        type="hidden"
                        name="mode_debug"
                        value="1"
                    >

                    <button
                        type="submit"
                        class="bouton-mise-a-jour"
                        id="boutonMiseAJour"
                    >
                        🔄 Mettre à jour les miniatures
                    </button>

                </form>

            <?php endif; ?>

        </div>

    </header>

    <!-- Compteur -->

    <p class="compteur">

        <?= count($videos) ?>
        vidéo(s) disponible(s)

    </p>

    <!-- Résultat de la synchronisation -->

    <?php if ($message !== ''): ?>

        <div
            class="message <?= e($typeMessage) ?>"
            role="status"
        >
            <?= e($message) ?>
        </div>

    <?php endif; ?>

    <!-- Barre de tri -->

    <section class="barre-tri">

        <div class="barre-tri-titre">
            Trier les vidéos
        </div>

        <form
            method="get"
            action=""
            class="formulaire-tri"
        >

            <label for="tri">
                Critère :
            </label>

            <select
                name="tri"
                id="tri"
            >

                <option
                    value="nom"
                    <?= $tri === 'nom' ? 'selected' : '' ?>
                >
                    Nom
                </option>

                <option
                    value="date"
                    <?= $tri === 'date' ? 'selected' : '' ?>
                >
                    Date de création
                </option>

            </select>

            <label for="ordre">
                Ordre :
            </label>

            <select
                name="ordre"
                id="ordre"
            >

                <option
                    value="asc"
                    <?= $ordre === 'asc' ? 'selected' : '' ?>
                >
                    Ascendant
                </option>

                <option
                    value="desc"
                    <?= $ordre === 'desc' ? 'selected' : '' ?>
                >
                    Descendant
                </option>

            </select>

            <button
                type="submit"
                class="bouton-tri"
            >
                Appliquer
            </button>

        </form>

    </section>

    <?php

    /*
    |--------------------------------------------------------------------------
    | Lecture du journal de synchronisation
    |--------------------------------------------------------------------------
    */

    $contenuJournal = '';

    $fichierJournal = $repertoireMiniatures
        . '/update_thumbnails.log';

    if (
        is_file($fichierJournal)
        && is_readable($fichierJournal)
    ) {

        $contenu = file_get_contents(
            $fichierJournal
        );

        if ($contenu !== false) {

            /*
             * Limite l'affichage aux 50 dernières lignes.
             */

            $lignes = preg_split(
                '/\r\n|\r|\n/',
                trim($contenu)
            );

            if ($lignes !== false) {

                $lignes = array_slice(
                    $lignes,
                    -50
                );

                $contenuJournal = implode(
                    "\n",
                    $lignes
                );
            }
        }
    }

    ?>

    <!-- Journal de synchronisation -->

    <?php if ($modeDebug && $contenuJournal !== ''): ?>

        <section class="journal-synchronisation">

            <h2>Journal de synchronisation</h2>

            <pre><?= e($contenuJournal) ?></pre>

            <form method="get" action="">

                <input
                    type="hidden"
                    name="tri"
                    value="<?= e($tri) ?>"
                >

                <input
                    type="hidden"
                    name="ordre"
                    value="<?= e($ordre) ?>"
                >

                <button
                    type="submit"
                    class="bouton-journal"
                >
                    🔄 Actualiser le journal
                </button>

            </form>

        </section>

    <?php endif; ?>

    <!-- Bibliothèque vidéo -->

    <?php if (count($videos) === 0): ?>

        <div class="bibliotheque-vide">

            <p>
                Aucune vidéo disponible pour le moment.
            </p>

        </div>

    <?php else: ?>

        <div class="liste-videos">

            <?php foreach ($videos as $video): ?>

                <?php

                /*
                |--------------------------------------------------------------------------
                | Informations sur la vidéo
                |--------------------------------------------------------------------------
                */

                $nomVideo = $video['nom'];

                $cheminVideo = $video['chemin'];

                $extension = $video['extension'];

                $dateCreation = $video['date_creation'];

                /*
                |--------------------------------------------------------------------------
                | Nom de la miniature
                |--------------------------------------------------------------------------
                |
                | Exemple :
                | film.mp4 -> film.jpg
                |
                */

                $nomSansExtension = pathinfo(
                    $nomVideo,
                    PATHINFO_FILENAME
                );

                $nomMiniature = $nomSansExtension . '.jpg';

                $cheminMiniature = (
                    $repertoireMiniatures
                    . '/'
                    . $nomMiniature
                );

                $urlMiniature = (
                    $urlMiniatures
                    . '/'
                    . rawurlencode($nomMiniature)
                );

                $miniatureExiste = (
                    is_file($cheminMiniature)
                    && is_readable($cheminMiniature)
                    && filesize($cheminMiniature) > 0
                );

                /*
                |--------------------------------------------------------------------------
                | Version de la miniature
                |--------------------------------------------------------------------------
                */

                if ($miniatureExiste) {

                    $dateMiniature = filemtime(
                        $cheminMiniature
                    );

                    if ($dateMiniature !== false) {

                        $urlMiniature .= '?v='
                            . $dateMiniature;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Date formatée
                |--------------------------------------------------------------------------
                */

                $dateFormatee = date(
                    'd/m/Y H:i',
                    $dateCreation
                );

                /*
                |--------------------------------------------------------------------------
                | URL de modification
                |--------------------------------------------------------------------------
                |
                | Le nom du fichier est transmis à modification.php.
                |
                | Le script modification.php doit vérifier lui-même
                | les droits d'accès et valider le nom du fichier.
                |
                */

                $urlModification = $pageModification
                    . '?f='
                    . rawurlencode($nomVideo);

                ?>

                <article class="video">

                    <!-- En-tête de la vidéo -->

                    <header class="entete-video">

                        <div class="entete-video-informations">
                    
                            <time
                                class="date-video"
                                datetime="<?= e(date('c', $dateCreation)) ?>"
                                title="Date de modification du fichier"
                            >
                                📅 <?= e($dateFormatee) ?>
                            </time>
                    
                        </div>
                    
                        <button
                            type="button"
                            class="bouton-modifier"
                            title="Modifier cette vidéo"
                            aria-label="Modifier la vidéo <?= e($nomVideo) ?>"
                            onclick="ouvrirModification(
                                <?= e(json_encode(
                                    $urlModification,
                                    JSON_HEX_TAG
                                    | JSON_HEX_APOS
                                    | JSON_HEX_QUOT
                                    | JSON_HEX_AMP
                                )) ?>
                            )"
                        >
                    
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M12 20h9"/>
                                <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4Z"/>
                            </svg>
                    
                        </button>
                    
                    </header>

                    <!-- Miniature cliquable -->

                    <a
                        class="lien-miniature"
                        href="video.php?f=<?= rawurlencode($nomVideo) ?>"
                        target="_blank"
                        rel="noopener"
                        aria-label="Lire la vidéo <?= e($nomVideo) ?>"
                    >

                        <?php if ($miniatureExiste): ?>

                            <img
                                class="miniature"
                                src="<?= e($urlMiniature) ?>"
                                alt="Miniature de <?= e($nomVideo) ?>"
                                loading="lazy"
                            >

                        <?php else: ?>

                            <div class="miniature-indisponible">

                                <span class="icone-video">
                                    🎬
                                </span>

                                <span>
                                    Miniature indisponible
                                </span>

                            </div>

                        <?php endif; ?>

                        <span
                            class="icone-lecture"
                            aria-hidden="true"
                        >
                            ▶
                        </span>

                    </a>

                    <!-- Informations complémentaires -->

                    <div class="video-informations">

                        <a
                            class="nom-video"
                            href="video.php?f=<?= rawurlencode($nomVideo) ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            <?= e($nomVideo) ?>
                        </a>

                        <span class="extension-video">
                            <?= e($extension) ?>
                        </span>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<!-- Fenêtre modale de modification -->

<div
    class="modale"
    id="modaleModification"
    role="dialog"
    aria-modal="true"
    aria-labelledby="titreModaleModification"
    aria-hidden="true"
>

    <div class="modale-contenu">

        <header class="modale-entete">

            <h2
                class="modale-titre"
                id="titreModaleModification"
            >
                Modifier la vidéo
            </h2>

            <button
                type="button"
                class="bouton-fermer-modale"
                onclick="fermerModification()"
                aria-label="Fermer la fenêtre"
            >
                &times;
            </button>

        </header>

        <iframe
            id="iframeModification"
            class="modale-iframe"
            src="about:blank"
            title="Modification de la vidéo"
            loading="eager"
        ></iframe>

    </div>

</div>

<script>

/*
|--------------------------------------------------------------------------
| Activation du mode debug
|--------------------------------------------------------------------------
*/

function activerModeDebug(checkbox) {

    const formulaire = checkbox.form;

    /*
     * Le choix est mémorisé dans la session PHP.
     */

    const input = document.createElement('input');

    input.type = 'hidden';
    input.name = 'mode_debug';
    input.value = checkbox.checked ? '1' : '0';

    formulaire.appendChild(input);

    formulaire.submit();
}

/*
|--------------------------------------------------------------------------
| Confirmation de la mise à jour des miniatures
|--------------------------------------------------------------------------
*/

function confirmerMiseAJour(formulaire) {

    const confirmation = confirm(
        'Voulez-vous synchroniser les miniatures avec les vidéos présentes sur le serveur ?\n\n'
        + 'Les miniatures manquantes seront créées, les miniatures '
        + 'obsolètes seront actualisées et les miniatures orphelines '
        + 'seront supprimées.'
    );

    if (!confirmation) {
        return false;
    }

    const bouton = document.getElementById(
        'boutonMiseAJour'
    );

    if (bouton) {

        bouton.disabled = true;

        bouton.textContent = '⏳ Mise à jour en cours...';
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Ouverture de la fenêtre de modification
|--------------------------------------------------------------------------
*/

function ouvrirModification(url) {

    const modale = document.getElementById(
        'modaleModification'
    );

    const iframe = document.getElementById(
        'iframeModification'
    );

    const titre = document.getElementById(
        'titreModaleModification'
    );

    /*
     * Charge modification.php dans l'iframe.
     */

    iframe.src = url;

    titre.textContent = 'Modifier la vidéo';

    modale.classList.add('ouverte');

    modale.setAttribute(
        'aria-hidden',
        'false'
    );

    /*
     * Empêche le défilement de la page en arrière-plan.
     */

    document.body.style.overflow = 'hidden';

    /*
     * Place le focus sur le bouton de fermeture.
     */

    const boutonFermer = modale.querySelector(
        '.bouton-fermer-modale'
    );

    if (boutonFermer) {
        boutonFermer.focus();
    }
}

/*
|--------------------------------------------------------------------------
| Fermeture de la fenêtre de modification
|--------------------------------------------------------------------------
*/

function fermerModification() {

    const modale = document.getElementById(
        'modaleModification'
    );

    const iframe = document.getElementById(
        'iframeModification'
    );

    modale.classList.remove('ouverte');

    modale.setAttribute(
        'aria-hidden',
        'true'
    );

    /*
     * Décharge la page de modification.
     */

    iframe.src = 'about:blank';

    /*
     * Réactive le défilement de la page.
     */

    document.body.style.overflow = '';

}

/*
|--------------------------------------------------------------------------
| Fermeture en cliquant sur le fond de la fenêtre
|--------------------------------------------------------------------------
*/

document.getElementById(
    'modaleModification'
).addEventListener(
    'click',
    function (evenement) {

        if (evenement.target === this) {
            fermerModification();
        }

    }
);

/*
|--------------------------------------------------------------------------
| Fermeture avec la touche Échap
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'keydown',
    function (evenement) {

        if (evenement.key === 'Escape') {

            const modale = document.getElementById(
                'modaleModification'
            );

            if (modale.classList.contains('ouverte')) {
                fermerModification();
            }
        }

    }
);

</script>

</body>

</html>
