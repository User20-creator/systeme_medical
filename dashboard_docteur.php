<?php
// dashboard_docteur.php — Alias canonique vers dashboard_medecin.php
// (les sessions utilisent user_type='docteur' mais l'écran historique
//  s'appelle dashboard_medecin.php).
require_once 'config.php';

if (isset($_SESSION['medecin_id']) && in_array($_SESSION['user_type'] ?? '', ['docteur', 'medecin'], true)) {
    header('Location: dashboard_medecin.php');
    exit;
}

header('Location: connexion2.php');
exit;
