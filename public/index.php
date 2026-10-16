<?php
declare(strict_types=1);

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

require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}

function e(string $valeur): string
{
    return htmlspecialchars(
        $valeur,
        ENT_QUOTES,
        'UTF-8'
    );
}

$identifiant = (string) (
    $_SESSION['utilisateur_identifiant'] ?? ''
);

$csrfToken = $_SESSION['csrf_token'] ?? '';

if (!is_string($csrfToken) || $csrfToken === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['csrf_token'];
}

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
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?= $autorise ? 'Accueil' : 'Accès refusé' ?>
        | Partitions Vidéo
    </title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #f3f5f9;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 20px 6%;
            background: white;
            border-bottom: 1px solid #e5e7eb;
        }

        header strong {
            font-size: 20px;
        }

        main {
            margin: 50px auto;
        }

        .carte {
            padding: 32px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(20, 35, 65, .05);
            position: absolute;
            width: 100%;
            height: 100%;
        }

        h1 {
            margin-top: 0;
        }

        p {
            color: #6b7280;
            line-height: 1.7;
        }

        .statut {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 6px;
            background: #dcfce7;
            color: #166534;
            font-size: 13px;
        }

        .avertissement {
            padding: 15px;
            border-radius: 9px;
            background: #fef2f2;
            color: #991b1b;
            line-height: 1.6;
        }

        .bouton {
            display: inline-block;
            padding: 11px 15px;
            border: 1px solid #d9deea;
            border-radius: 8px;
            background: white;
            color: #172033;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .bouton:hover {
            background: #f8fafc;
        }

        .bouton-deconnexion {
            border-color: #fecaca;
            color: #dc2626;
        }

        @media (max-width: 480px) {
            header {
                padding: 16px 5%;
            }

            .carte {
                padding: 24px;
            }
        }
    </style>
</head>

<body>

<header>
    <strong>Partitions Vidéo</strong>

    <?php if ($autorise): ?>

    <span class="statut">Accès autorisé</span>

            <p>
                Bienvenue <?= e($identifiant) ?> dans votre bibliothèque vidéo !
            </p>

    <?php endif; ?>


    <form method="POST" action="deconnexion.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <button
            class="bouton bouton-deconnexion"
            type="submit"
        >
            Se déconnecter
        </button>
    </form>
</header>

<main>

    <section class="carte">

        <?php if ($autorise): ?>
<iframe
    src="videos.php"
    title="Bibliothèque vidéo"
    width="100%"
    height="500"
    loading="lazy"
    style="
        display: block;
        border: 0;
        border-radius: 12px;
        background: #f3f5f9;
        height: 90%;
    "
></iframe>

        <?php else: ?>

            <h1>Accès non autorisé</h1>

            <div class="avertissement">
                Votre compte est bien connecté, mais
                l'accès au service vidéo ne vous a pas
                été accordé.

                Contactez un administrateur pour demander
                l'autorisation nécessaire.
            </div>

            <p>
                Votre compte et vos identifiants restent
                valides pour les autres services auxquels
                vous avez accès.
            </p>

            <p>
                <a href="https://compte.partitions.ovh/">
                    Retourner au site des comptes
                </a>
            </p>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
