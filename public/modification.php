<?php

declare(strict_types=1);

session_start();

require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

$repertoireVideos = '/mnt/video';

$fichierJSON = $repertoireVideos . '/description.json';

$extensionsAutorisees = [
    'mp4',
    'webm',
    'ogg',
];

$pageCouperVideo = 'couper_video.php';

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
| Authentification
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['utilisateur_id'])) {
    http_response_code(401);
    exit('Vous devez être connecté.');
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
| Vérification du fichier vidéo
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
| Lecture du fichier JSON
|--------------------------------------------------------------------------
|
| Le fichier est verrouillé pendant la lecture.
|
*/

function lireDescriptions(string $fichier): array
{
    if (!file_exists($fichier)) {
        return [];
    }

    $contenu = file_get_contents($fichier);

    if ($contenu === false) {
        throw new RuntimeException(
            'Impossible de lire description.json.'
        );
    }

    if (trim($contenu) === '') {
        return [];
    }

    $donnees = json_decode(
        $contenu,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($donnees)) {
        throw new RuntimeException(
            'Structure du fichier JSON invalide.'
        );
    }

    return $donnees;
}

/*
|--------------------------------------------------------------------------
| Enregistrement sécurisé du fichier JSON
|--------------------------------------------------------------------------
|
| Écriture temporaire puis remplacement atomique.
|
*/

function enregistrerDescriptions(
    string $fichier,
    array $donnees
): void {

    $json = json_encode(
        $donnees,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );

    $repertoire = dirname($fichier);

    $temporaire = tempnam(
        $repertoire,
        '.description_'
    );

    if ($temporaire === false) {
        throw new RuntimeException(
            'Impossible de créer le fichier temporaire.'
        );
    }

    try {

        if (file_put_contents(
            $temporaire,
            $json . PHP_EOL,
            LOCK_EX
        ) === false) {

            throw new RuntimeException(
                'Impossible d\'écrire le fichier temporaire.'
            );
        }

        if (!rename($temporaire, $fichier)) {

            throw new RuntimeException(
                'Impossible de remplacer description.json.'
            );
        }

    } finally {

        if (file_exists($temporaire)) {
            unlink($temporaire);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Modification sécurisée du JSON
|--------------------------------------------------------------------------
|
| Un fichier de verrouillage indépendant protège
| toutes les opérations de lecture et écriture.
|
*/

function modifierDescription(
    string $fichier,
    string $nomVideo,
    string $statut,
    string $description
): void {

    $verrou = $fichier . '.lock';

    $handle = fopen($verrou, 'c');

    if ($handle === false) {
        throw new RuntimeException(
            'Impossible de créer le verrou JSON.'
        );
    }

    try {

        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(
                'Impossible de verrouiller le fichier JSON.'
            );
        }

        $donnees = lireDescriptions($fichier);

        $donnees[$nomVideo] = [
            'statut' => $statut,
            'description' => $description
        ];

        enregistrerDescriptions(
            $fichier,
            $donnees
        );

        flock($handle, LOCK_UN);

    } finally {

        fclose($handle);
    }
}

/*
|--------------------------------------------------------------------------
| Récupération des informations initiales
|--------------------------------------------------------------------------
*/

$message = '';

$typeMessage = 'info';

$statutVideo = '';

$informationsVideo = '';

/*
|--------------------------------------------------------------------------
| Date actuelle du fichier
|--------------------------------------------------------------------------
*/

$dateModification = filemtime($cheminVideo);

if ($dateModification === false) {
    $dateModification = time();
}

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
        || !hash_equals($csrfToken, $token)
    ) {

        http_response_code(403);

        exit('Jeton de sécurité invalide. Rechargez la page.');
    }

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Action : modifier les informations JSON
    |--------------------------------------------------------------------------
    */

    if ($action === 'modifier_metadonnees') {

        $nouveauStatut = $_POST['statut_video'] ?? '';

        $nouvellesInformations = $_POST['informations_video'] ?? '';

        if (
            !is_string($nouveauStatut)
            || mb_strlen($nouveauStatut, 'UTF-8') > 255
        ) {

            $message = 'Le statut est invalide ou dépasse 255 caractères.';

            $typeMessage = 'erreur';

        } elseif (
            !is_string($nouvellesInformations)
            || mb_strlen($nouvellesInformations, 'UTF-8') > 5000
        ) {

            $message = 'Les informations sont invalides ou dépassent 5000 caractères.';

            $typeMessage = 'erreur';

        } else {

            try {

                modifierDescription(
                    $fichierJSON,
                    $nomVideo,
                    $nouveauStatut,
                    $nouvellesInformations
                );

                $statutVideo = $nouveauStatut;

                $informationsVideo = $nouvellesInformations;

                $message = 'Les informations de la vidéo ont été enregistrées avec succès.';

                $typeMessage = 'succes';

            } catch (Throwable $exception) {

                error_log(
                    'Erreur description.json : '
                    . $exception->getMessage()
                );

                $message = 'Impossible d\'enregistrer les informations. Vérifiez les permissions du répertoire NFS.';

                $typeMessage = 'erreur';

                $statutVideo = $nouveauStatut;

                $informationsVideo = $nouvellesInformations;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Action : modifier la date
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'modifier_date') {

        $dateSaisie = $_POST['date_creation'] ?? '';

        if (
            !is_string($dateSaisie)
            || $dateSaisie === ''
        ) {

            $message = 'Veuillez saisir une date.';

            $typeMessage = 'erreur';

        } else {

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

                $datePourTouch = $dateObjet->format(
                    'Y-m-d H:i:s'
                );

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

                    clearstatcache(true, $cheminVideo);

                    $nouvelleDate = filemtime($cheminVideo);

                    if ($nouvelleDate !== false) {

                        $dateModification = $nouvelleDate;

                        $dateFormulaire = date(
                            'Y-m-d\TH:i',
                            $nouvelleDate
                        );

                        $message = 'La date de modification de la vidéo a été mise à jour.';

                        $typeMessage = 'succes';

                    } else {

                        $message = 'La nouvelle date est illisible.';

                        $typeMessage = 'erreur';
                    }

                } else {

                    $detailsErreur = implode("\n", $sortie);

                    $message = 'Impossible de modifier la date de la vidéo.';

                    $typeMessage = 'erreur';

                    error_log(
                        'Erreur touch : '
                        . $detailsErreur
                    );
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Lecture des informations JSON
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || (
        isset($action)
        && $action !== 'modifier_metadonnees'
    )
) {

    try {

        $donneesJSON = lireDescriptions($fichierJSON);

        $informationsExistantes = $donneesJSON[$nomVideo] ?? [];

        if (is_array($informationsExistantes)) {

            $statutVideo = $informationsExistantes['statut'] ?? '';

            $informationsVideo = $informationsExistantes['description'] ?? '';
        }

    } catch (Throwable $exception) {

        error_log(
            'Erreur lecture JSON : '
            . $exception->getMessage()
        );

        $message = 'Impossible de lire description.json.';

        $typeMessage = 'erreur';
    }
}

/*
|--------------------------------------------------------------------------
| Informations affichées
|--------------------------------------------------------------------------
*/

$nomAffiche = $nomVideo;

clearstatcache(true, $cheminVideo);

$dateModificationActuelle = filemtime($cheminVideo);

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

        .champ-date,
        .champ-statut,
        .champ-texte {
            display: block;
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #172033;
            font: inherit;
        }

        .champ-date,
        .champ-statut {
            min-height: 45px;
        }

        .champ-texte {
            min-height: 150px;
            resize: vertical;
            line-height: 1.6;
        }

        .champ-date:focus,
        .champ-statut:focus,
        .champ-texte:focus {
            outline: 3px solid rgba(37, 99, 235, .15);
            border-color: #2563eb;
        }

        .compteur-caracteres {
            margin-top: 6px;
            color: #6b7280;
            font-size: 12px;
        }

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

    <!-- Informations de la vidéo -->

    <section class="carte">

        <h2>📋 Informations de la vidéo</h2>

        <p class="description">
            Le statut et les informations complémentaires
            sont enregistrés dans description.json.
            Le fichier vidéo n'est jamais modifié.
        </p>
        <p class="description">
            Pour afficher en vert (Statut de la vidéo => Fini),
            pour afficher en rouge (Statut de la vidéo => Problème),
            pour afficher en jaune (Statut de la vidéo => A revoir)
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
                value="modifier_metadonnees"
            >

            <div class="groupe-champ">

                <label for="statut_video">
                    Statut de la vidéo
                </label>

                <input
                    type="text"
                    id="statut_video"
                    name="statut_video"
                    class="champ-statut"
                    maxlength="255"
                    value="<?= e($statutVideo) ?>"
                    placeholder="Saisir le statut de la vidéo..."
                >

            </div>

            <div class="groupe-champ">

                <label for="informations_video">
                    Informations complémentaires
                </label>

                <textarea
                    id="informations_video"
                    name="informations_video"
                    class="champ-texte"
                    maxlength="5000"
                    placeholder="Ajouter des informations complémentaires..."
                ><?= e($informationsVideo) ?></textarea>

                <div class="compteur-caracteres">

                    <span id="compteur">
                        <?= e((string) mb_strlen($informationsVideo, 'UTF-8')) ?>
                    </span>

                    / 5000 caractères

                </div>

            </div>

            <div class="actions">

                <button
                    type="submit"
                    class="bouton bouton-principal"
                >
                    💾 Enregistrer les informations
                </button>

            </div>

        </form>

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

<script>

document.addEventListener('DOMContentLoaded', function () {

    const champ = document.getElementById('informations_video');

    const compteur = document.getElementById('compteur');

    if (!champ || !compteur) {
        return;
    }

    champ.addEventListener('input', function () {

        compteur.textContent = champ.value.length;

    });

});

</script>

</body>
</html>
