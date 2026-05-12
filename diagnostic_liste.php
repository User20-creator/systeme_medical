<?php
// diagnostic_liste.php — Page de diagnostic pour comprendre le souci de la liste
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'config.php';
require_once 'includes/migrations.php';
ensure_medecin_traitant_column($pdo);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Diagnostic</title>';
echo '<style>body{font-family:system-ui;padding:30px;background:#0f172a;color:#e2e8f0;line-height:1.6;}h2{color:#10b981;}pre{background:#1e293b;padding:14px;border-radius:8px;overflow:auto;}.ok{color:#10b981}.ko{color:#ef4444}</style>';
echo '</head><body>';

echo '<h2>1. Session</h2><pre>';
echo 'user_type : ' . htmlspecialchars($_SESSION['user_type'] ?? '(aucun)') . "\n";
echo 'admin_id  : ' . ($_SESSION['admin_id']  ?? '(aucun)') . "\n";
echo 'medecin_id: ' . ($_SESSION['medecin_id']?? '(aucun)') . "\n";
echo 'user_id   : ' . ($_SESSION['user_id']   ?? '(aucun)') . "\n";
echo '</pre>';

if (empty($_SESSION['user_type'])) {
    echo '<p class="ko">⚠ Aucune session active. <a href="connexion2.php" style="color:#10b981">Connectez-vous d\'abord</a> puis revenez ici.</p>';
    echo '</body></html>';
    exit;
}

echo '<h2>2. Comptes en base</h2><pre>';
echo 'Patients   : ' . (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn() . "\n";
echo 'Infirmiers : ' . (int)$pdo->query("SELECT COUNT(*) FROM infirmiers")->fetchColumn() . "\n";
echo 'Docteurs   : ' . (int)$pdo->query("SELECT COUNT(*) FROM docteurs")->fetchColumn() . "\n";
echo '</pre>';

echo '<h2>3. Test requête patients (exactement comme la page)</h2><pre>';
try {
    $sql = "
        SELECT p.id, 'patient' AS role,
               p.prenom, p.nom, p.email, p.telephone, p.identifiant_blockchain,
               p.date_inscription AS d, p.statut,
               p.date_naissance, p.sexe, p.groupe_sanguin, p.NPI, p.adresse,
               p.hopital_reference,
               p.medecin_traitant_id,
               h.nom AS hopital_nom, h.ville AS hopital_ville,
               CONCAT(d.prenom, ' ', d.nom) AS medecin_traitant_nom,
               d.specialite AS medecin_traitant_specialite,
               NULL AS specialite, NULL AS numero_licence, NULL AS fonction
        FROM patients p
        LEFT JOIN hopitaux h ON h.id = p.hopital_reference
        LEFT JOIN docteurs d ON d.id = p.medecin_traitant_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    echo '<span class="ok">✓ Requête réussie</span> — ' . count($rows) . " lignes retournées\n";
    if ($rows) {
        echo "Premier patient :\n";
        echo "  " . htmlspecialchars($rows[0]['prenom'] . ' ' . $rows[0]['nom']) . "\n";
        echo "  email = " . htmlspecialchars($rows[0]['email'] ?? '—') . "\n";
        echo "  hopital_nom = " . htmlspecialchars($rows[0]['hopital_nom'] ?? '—') . "\n";
        echo "  medecin_traitant_nom = " . htmlspecialchars($rows[0]['medecin_traitant_nom'] ?? '—') . "\n";
    }
} catch (PDOException $e) {
    echo '<span class="ko">✗ ERREUR SQL : ' . htmlspecialchars($e->getMessage()) . "</span>\n";
}
echo '</pre>';

echo '<h2>4. Mémoire PHP</h2><pre>';
echo 'memory_limit       : ' . ini_get('memory_limit') . "\n";
echo 'max_execution_time : ' . ini_get('max_execution_time') . "\n";
echo 'memory_get_usage   : ' . round(memory_get_usage()/1024/1024, 2) . " MB\n";
echo '</pre>';

echo '<h2>5. Inclure liste_utilisateurs.php avec role=patient ?</h2>';
echo '<p><a href="liste_utilisateurs.php?role=patient" style="color:#10b981">→ Charger la page</a> ';
echo '(les erreurs PHP s\'afficheront en haut de la page si la page reste blanche).</p>';

echo '<h2>6. Vérification des hooks pageshow / popstate</h2>';
echo '<p>Si tu cliques sur le lien ci-dessus puis fais "back", tu reviens à cette page (et non un logout).</p>';

echo '</body></html>';
