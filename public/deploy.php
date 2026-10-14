
<?php

// Script de deploiement simple: checkout/pull d'une branche cible sur le serveur.

if(!isset($_GET['branch_name'])) {
    die("Erreur : Veuillez indiquer un nom de branche.\n");
}

// Nom de la branche
$branch_name = (String) trim($_GET['branch_name']);
//$branch_name = "test";

// Détermination robuste du chemin de destination à partir de l'emplacement du script.
// Cela évite les problèmes lorsque PHP est lancé depuis un autre répertoire.
$repoRoot = realpath(dirname(__DIR__, 1));
$parentDir = dirname($repoRoot);

if ($branch_name === "main") {
    $path = $repoRoot;
} else {
    $path = $parentDir . "/" . $branch_name;
}
$path = rtrim($path, "/");

// Configuration Git temporaire pour éviter les erreurs de 'dubious ownership' lorsqu'exécuté par PHP/Apache.
$gitConfigPath = sys_get_temp_dir() . "/music-deploy-gitconfig";
file_put_contents($gitConfigPath, "[safe]\n\tdirectory = $path\n");
putenv('GIT_CONFIG_GLOBAL=' . $gitConfigPath);
putenv('GIT_TERMINAL_PROMPT=0');

// Repo
$repoUrl = "https://github.com/antoineg44/video.git"; // Remplacez par l'URL de votre dépôt

// Fonction utilitaire pour exécuter une commande shell
function runCommand($command, &$output = null, &$returnVar = null) {
    // Execute une commande shell et arrete le script en cas d'erreur.
    echo "Exécution de la commande : $command\n";
    exec($command . " 2>&1", $output, $returnVar);
    $outputText = implode("\n", $output);
    echo $outputText . "\n";
    if ($returnVar !== 0) {
        $errorMessage = "Erreur : La commande a échoué avec le code $returnVar\n";
        if ($returnVar === 128 && (strpos($outputText, 'permission denied') !== false || strpos($outputText, 'could not create work tree dir') !== false || strpos($outputText, 'unable to create file') !== false || strpos($outputText, 'unable to append to') !== false)) {
            $errorMessage .= "Le dépôt Git n'est peut-être pas accessible en écriture pour l'utilisateur du serveur web. Vérifiez les permissions du dossier et du dépôt Git, notamment .git/logs/HEAD et .git/HEAD.\n";
        }
        die($errorMessage);
    }
}

function verifyGitWriteAccess($path) {
    $pathsToCheck = [
        $path,
        "$path/.git",
        "$path/.git/objects",
        "$path/.git/objects/pack",
        "$path/.git/logs",
        "$path/.git/logs/HEAD",
        "$path/.git/HEAD",
        "$path/.git/index",
        "$path/.git/refs",
        "$path/.git/refs/heads"
    ];

    foreach ($pathsToCheck as $checkPath) {
        if (is_dir($checkPath) || is_file($checkPath)) {
            if (!is_writable($checkPath)) {
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    $command = 'chown -R www-data:www-data ' . escapeshellarg($path) . ' && chmod -R u+rwX ' . escapeshellarg($path);
                    exec($command . ' 2>&1', $output, $returnVar);
                    if ($returnVar === 0) {
                        return null;
                    }

                    return "Impossible de corriger automatiquement les permissions : " . implode("\n", $output);
                }

                return "Le chemin '$checkPath' n'est pas accessible en écriture. Vérifiez les permissions du dépôt Git ou exécutez le script en tant que root pour le corriger automatiquement.";
            }
        } else {
            $parent = dirname($checkPath);
            if (!is_dir($parent) || !is_writable($parent)) {
                return "Le chemin '$checkPath' n'est pas accessible en écriture (parent non accessible).";
            }
        }
    }

    return null;
}

echo "Déploiement de la branche '$branch_name' dans '$path'\n";

/*
//At the first execution run:
chdir("../../");
runCommand("git clone ".$repoUrl);
// And rename the folder from serveur_chant to $branch_name
*/

// Vérifiez si le dossier existe et contient un dépôt Git
if (!is_dir($path) || !is_dir("$path/.git")) {
    die("Erreur : Le dossier '$path' n'existe pas ou n'est pas un dépôt Git valide.\n");
}

$writeAccessError = verifyGitWriteAccess($path);
if ($writeAccessError !== null) {
    die("Erreur : $writeAccessError\n");
}

// Aller dans le dossier
chdir($path);

// Mettre à jour la branche spécifique
runCommand("git checkout " . escapeshellarg($branch_name));
runCommand("git pull origin " . escapeshellarg($branch_name));

echo "Déploiement terminé avec succès.\n";


// sudo chown -R www-data:www-data /var/www/html && sudo chmod -R u+w /var/www/html
