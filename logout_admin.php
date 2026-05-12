<?php
// logout_admin.php
// Appelé automatiquement par navigator.sendBeacon() quand l'admin quitte la page
// Aussi utilisé pour la déconnexion manuelle via logout.php

session_start();

// Vérifier que c'est bien un admin
if (isset($_SESSION['admin_id']) && $_SESSION['user_type'] === 'admin') {

    // Optionnel : inclure config pour logger
    if (file_exists('config.php')) {
        require_once 'config.php';

        try {
            $pdo->prepare("
                INSERT INTO logs_blockchain
                    (transaction_id, type_action, table_concernee, record_id,
                     utilisateur_id, type_utilisateur, timestamp_action,
                     status, details, ip_address)
                VALUES
                    (CONCAT('0x', SHA2(CONCAT(NOW(), ?, RAND()), 256)),
                     'consultation', 'admin', ?,
                     ?, 'admin', NOW(), 'succes',
                     'Déconnexion automatique admin', ?)
            ")->execute([
                $_SESSION['admin_id'],
                $_SESSION['admin_id'],
                $_SESSION['admin_id'],
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
        } catch (Exception $e) {
            // Silencieux
        }
    }
}

// Détruire la session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Si appel direct (pas beacon), rediriger
if (!isset($_SERVER['HTTP_SEC_FETCH_DEST']) || $_SERVER['HTTP_SEC_FETCH_DEST'] !== 'empty') {
    header('Location: connexion2.php');
}
exit;
