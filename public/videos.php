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

        error_log('Script introuvable : ' . $scriptMiseAJour);

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
        
        /*
        |--------------------------------------------------------------------------
        | Vérification du journal
        |--------------------------------------------------------------------------
        */
        
        if (
            !is_dir($repertoireMiniatures)
            || !is_writable($repertoireMiniatures)
        ) {
            $message = 'Le répertoire des miniatures ne permet pas '
                . 'de créer le journal de synchronisation.';
        
            $typeMessage = 'erreur';
        
        } else {
        
            /*
            |--------------------------------------------------------------------------
            | Création du journal
            |--------------------------------------------------------------------------
            */
        
            if (file_put_contents(
                $fichierJournal,
                '[' . date('Y-m-d H:i:s') . "] Début de la synchronisation.\n"
            ) === false) {
        
                $message = 'Impossible de créer le journal de synchronisation.';
                $typeMessage = 'erreur';
        
            } else {
        
                /*
                |--------------------------------------------------------------------------
                | Construction de la commande
                |--------------------------------------------------------------------------
                */
        
                $commande = 'nohup '
                    . escapeshellarg(PHP_BINARY)
                    . ' '
                    . escapeshellarg($scriptMiseAJour)
                    . ' >> '
                    . escapeshellarg($fichierJournal)
                    . ' 2>&1 < /dev/null &';
        
                /*
                |--------------------------------------------------------------------------
                | Lancement du script
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
        
                    $message = 'La synchronisation a été lancée.'
                        . "\n\n"
                        . 'Tu peux consulter les dernières informations '
                        . 'ci-dessous pour vérifier son exécution.';
        
                    $typeMessage = 'succes';
        
                } else {
        
                    $message = 'Impossible de lancer la synchronisation.'
                        . "\n\n"
                        . 'Code de retour : '
                        . $codeRetour;
        
                    $typeMessage = 'erreur';
        
                    error_log(
                        'Échec du lancement de la synchronisation des miniatures. '
                        . 'Code : '
                        . $codeRetour
                    );
                }
            }
        }

    }
}
/*
Vérification du répertoire vidéo
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

    if (
        !is_file($chemin)
        || !is_readable($chemin)
    ) {
        continue;
    }

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

    $videos[] = $fichier;
}

/*
|--------------------------------------------------------------------------
| Tri alphabétique naturel
|--------------------------------------------------------------------------
*/

sort(
    $videos,
    SORT_NATURAL | SORT_FLAG_CASE
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

        .conteneur {
            max-width: 1600px;
            margin: 0 auto;
        }

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
        | Bouton de mise à jour
        |--------------------------------------------------------------------------
        */

        .formulaire-mise-a-jour {
            margin: 0;
        }

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
            box-shadow:
                0 4px 15px rgba(20, 35, 65, .06);
            transition:
                transform .2s,
                box-shadow .2s;
        }

        .video:hover {
            transform: translateY(-3px);

            box-shadow:
                0 8px 24px rgba(20, 35, 65, .12);
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
        | Nom du fichier
        |--------------------------------------------------------------------------
        */

        .video-informations {
            padding: 15px;
        }

        .nom-video {
            display: block;
            color: #2563eb;
            font-size: 14px;
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

            .bouton-mise-a-jour {
                width: 100%;
            }

            .liste-videos {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
                gap: 12px;
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
        }

        @media (max-width: 360px) {

            .liste-videos {
                grid-template-columns: 1fr;
            }

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
            color: #ffffff;
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
            color: #ffffff;
            cursor: pointer;
        }
        
        .bouton-journal:hover {
            background: #4b5563;
        }

    </style>

</head>

<body>

<div class="conteneur">

    <!-- En-tête -->

    <header class="entete">

        <h1>Bibliothèque vidéo</h1>

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

            <button
                type="submit"
                class="bouton-mise-a-jour"
                id="boutonMiseAJour"
            >
                🔄 Mettre à jour les miniatures
            </button>

        </form>

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
    $contenu = file_get_contents($fichierJournal);

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

<?php if ($contenuJournal !== ''): ?>

    <section class="journal-synchronisation">

        <h2>Journal de synchronisation</h2>

        <pre><?= e($contenuJournal) ?></pre>

        <form method="get" action="">
            <button type="submit" class="bouton-journal">
                🔄 Actualiser le journal
            </button>
        </form>

    </section>

<?php endif; ?>

    <!-- Bibliothèque -->

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
                 * Nom de la miniature correspondant à la vidéo.
                 *
                 * Exemple :
                 * film.mp4 -> film.jpg
                 */

                $nomSansExtension = pathinfo(
                    $video,
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

                $extension = strtolower(
                    pathinfo(
                        $video,
                        PATHINFO_EXTENSION
                    )
                );

                /*
                 * Ajout d'un paramètre de version basé
                 * sur la date de modification de la miniature.
                 * Le navigateur pourra ainsi charger une
                 * nouvelle image après sa mise à jour.
                 */

                if ($miniatureExiste) {
                    $dateMiniature = filemtime(
                        $cheminMiniature
                    );

                    if ($dateMiniature !== false) {
                        $urlMiniature .= '?v=' . $dateMiniature;
                    }
                }

                ?>

                <article class="video">

                    <!-- Miniature cliquable -->

                    <a
                        class="lien-miniature"
                        href="video.php?f=<?= rawurlencode($video) ?>"
                        target="_blank"
                        rel="noopener"
                        aria-label="Lire la vidéo <?= e($video) ?>"
                    >

                        <?php if ($miniatureExiste): ?>

                            <img
                                class="miniature"
                                src="<?= e($urlMiniature) ?>"
                                alt="Miniature de <?= e($video) ?>"
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

                    <!-- Nom du fichier -->

                    <div class="video-informations">

                        <a
                            class="nom-video"
                            href="video.php?f=<?= rawurlencode($video) ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            <?= e($video) ?>
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

<script>
    function confirmerMiseAJour(formulaire) {

        const confirmation = confirm(
            'Voulez-vous synchroniser les miniatures avec les vidéos présentes sur le serveur ?\n\n' +
            'Les miniatures manquantes seront créées, les miniatures ' +
            'obsolètes seront actualisées et les miniatures orphelines ' +
            'seront supprimées.'
        );

        if (!confirmation) {
            return false;
        }

        const bouton = document.getElementById(
            'boutonMiseAJour'
        );

        bouton.disabled = true;
        bouton.textContent = '⏳ Mise à jour en cours...';

        return true;
    }
</script>

</body>
</html>

