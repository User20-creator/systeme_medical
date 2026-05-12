<?php
// logout.php
// Déconnecte l'utilisateur connecté et redirige vers la bonne page
session_start();

$userType = $_SESSION['user_type'] ?? '';

// Logger la déconnexion si config disponible
if (file_exists('config.php')) {
    require_once 'config.php';
    try {
        $userId   = $_SESSION['admin_id']   ??
                    $_SESSION['medecin_id'] ??
                    $_SESSION['user_id']    ?? 0;

        if ($userId) {
            $pdo->prepare("
                INSERT INTO logs_blockchain
                    (transaction_id, type_action, table_concernee,
                     record_id, utilisateur_id, type_utilisateur,
                     timestamp_action, status, details, ip_address)
                VALUES
                    (CONCAT('0x', SHA2(CONCAT(NOW(), ?, RAND()), 256)),
                     'consultation', 'session',
                     ?, ?, ?,
                     NOW(), 'succes', 'Déconnexion', ?)
            ")->execute([
                $userId, $userId, $userId,
                $userType ?: 'inconnu',
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
        }
    } catch (Exception $e) {
        // Silencieux — ne pas bloquer la déconnexion
    }
}

// Détruire la session complètement
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Rediriger selon le type d'utilisateur
switch ($userType) {
    case 'patient':
        header('Location: connexion1.php');
        break;
    default:
        // infirmier, docteur, admin → page personnel
        header('Location: connexion2.php');
        break;
}
exit;