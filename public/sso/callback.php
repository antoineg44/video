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

function callbackErreur(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

function callbackBase64Url(string $valeur): string
{
    return rtrim(strtr(base64_encode($valeur), '+/', '-_'), '=');
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$pending = $_SESSION['sso_video_pending'] ?? null;

if (
    !is_array($pending)
    || !isset($pending['verifier'], $pending['state'])
    || !is_string($pending['verifier'])
    || !is_string($pending['state'])
    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code)
    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $state)
    || !hash_equals($pending['state'], $state)
) {
    callbackErreur(400, 'Réponse SSO invalide ou expirée. Veuillez réessayer.');
}

$challengeAttendu = callbackBase64Url(
    hash('sha256', $pending['verifier'], true)
);

try {
    $pdo->beginTransaction();

    // Verrouiller le code pendant sa validation pour empêcher sa réutilisation.
    $requete = $pdo->prepare(
        'SELECT
            utilisateur_id,
            code_challenge
         FROM sso_codes
         WHERE code_hash = :code_hash
           AND client_id = :client_id
           AND date_expiration > NOW()
           AND date_utilisation IS NULL
         LIMIT 1
         FOR UPDATE'
    );

    $requete->execute([
        'code_hash' => hash('sha256', $code),
        'client_id' => 'video',
    ]);

    $autorisation = $requete->fetch();

    if (
        !$autorisation
        || !hash_equals(
            (string) $autorisation['code_challenge'],
            $challengeAttendu
        )
    ) {
        $pdo->rollBack();
        unset($_SESSION['sso_video_pending']);

        callbackErreur(400, 'Code SSO invalide, expiré ou déjà utilisé.');
    }

    $utilisateurId = (int) $autorisation['utilisateur_id'];

    // Le droit doit toujours être présent au moment du callback.
    if (!utilisateurAAccesService($pdo, $utilisateurId, 'video')) {
        $pdo->rollBack();
        unset($_SESSION['sso_video_pending']);

        callbackErreur(403, 'Accès refusé : ce compte ne dispose pas des droits vidéo.');
    }

    $requete = $pdo->prepare(
        'SELECT id, identifiant, email, role
         FROM utilisateurs
         WHERE id = :id
         LIMIT 1'
    );

    $requete->execute(['id' => $utilisateurId]);
    $utilisateur = $requete->fetch();

    if (!$utilisateur) {
        $pdo->rollBack();
        unset($_SESSION['sso_video_pending']);

        callbackErreur(403, 'Le compte utilisateur n’existe plus.');
    }

    // Marquer le code comme utilisé avant de terminer la transaction.
    $requete = $pdo->prepare(
        'UPDATE sso_codes
         SET date_utilisation = NOW()
         WHERE code_hash = :code_hash
           AND date_utilisation IS NULL'
    );

    $requete->execute([
        'code_hash' => hash('sha256', $code),
    ]);

    if ($requete->rowCount() !== 1) {
        $pdo->rollBack();
        unset($_SESSION['sso_video_pending']);

        callbackErreur(400, 'Ce code SSO a déjà été utilisé.');
    }

    $pdo->commit();

    // Renouveler l'identifiant de session après authentification.
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Impossible de renouveler la session vidéo.');
    }

    unset($_SESSION['sso_video_pending']);

    $_SESSION['utilisateur_id'] = (int) $utilisateur['id'];
    $_SESSION['utilisateur_identifiant'] = $utilisateur['identifiant'];
    $_SESSION['utilisateur_email'] = $utilisateur['email'];
    $_SESSION['utilisateur_role'] = $utilisateur['role'];
    $_SESSION['utilisateur_admin'] = ($utilisateur['role'] === 'admin');
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: /index.php');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erreur callback SSO vidéo : ' . $e->getMessage());

    http_response_code(500);
    exit('Une erreur technique est survenue pendant la connexion.');
}
