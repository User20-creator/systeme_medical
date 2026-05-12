<?php
// seed_data.php — Génère les données de test :
// 30 patients, 6 docteurs et 20 infirmiers par hôpital actif.
// Lancement : http://localhost/systeme_medical/seed_data.php?confirm=1
require_once 'config.php';
require_once 'includes/hash_chain.php';

set_time_limit(300);
ini_set('memory_limit', '256M');

if (!isset($_GET['confirm'])) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Seed</title>';
    echo '<style>body{font-family:system-ui;padding:40px;background:#f8fafc;color:#0f172a;}';
    echo '.box{max-width:680px;margin:auto;background:white;padding:32px;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08);}';
    echo 'a.btn{display:inline-block;margin-top:16px;padding:11px 18px;background:#1a472a;color:white;border-radius:10px;text-decoration:none;font-weight:700;}</style></head><body>';
    echo '<div class="box"><h2>Génération de données de test</h2>';
    echo '<p>Pour chaque hôpital actif, ce script va créer :</p>';
    echo '<ul><li><strong>6 docteurs</strong></li><li><strong>20 infirmiers</strong></li><li><strong>30 patients</strong></li></ul>';
    echo '<p style="color:#92400e;background:#fef3c7;padding:10px;border-radius:8px;">⚠ Cette opération va insérer beaucoup de données. Mots de passe par défaut : <code>passe1234</code></p>';
    echo '<a class="btn" href="seed_data.php?confirm=1">Lancer la génération</a>';
    echo '</div></body></html>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

// Données d'identité
$NOMS = ['HOUNGBO','AGOSSOU','DOSSOU','TCHEGNON','GOMES','KOUTON','AMEGNRAN','SOUMANOU','GBEDEVI','DJEGUI','ALIOU','BOKO','MENSAH','ADEYEMI','ZINSOU','SOSSOU','KPADE','GANGBO','NOUNAGNON','CAKPO','DA-COSTA','HOUEDJI','GBEDJI','LOKOSSOU','AKPOVI','TOSSOU','KOTCHOFA','AVODAGBE','GAHOU','AGBANGLA'];
$PRENOMS_M = ['Kossi','Yao','Mawuli','Komlan','Ayédjo','Élie','Sylvain','Jean','Étienne','Pascal','Olivier','Fabrice','Maxime','Bernard','Patrick','Jérôme','Antoine','Marc','Eric','Théo','Daniel','Charles','Léon','Joël','Bruno'];
$PRENOMS_F = ['Aïcha','Fatou','Adjoa','Akossiwa','Edwige','Florence','Estelle','Marie','Béatrice','Sandra','Inès','Marina','Alice','Carole','Esther','Lydia','Pamela','Christine','Hélène','Joëlle','Pauline','Léa','Diane','Évelyne','Sylvia'];
$SPECIALITES_DR = ['Cardiologie','Pédiatrie','Médecine générale','Gynécologie','Dermatologie','Chirurgie viscérale','Orthopédie','Endocrinologie','Néphrologie','Urologie','Ophtalmologie','Pneumologie'];
$SPECIALITES_INF = ['Soins généraux','Pédiatrie','Bloc opératoire','Réanimation','Maternité','Urgences','Oncologie','Cardiologie','Psychiatrie'];
$FONCTIONS = ['accueil','soins','urgence','pediatrie','bloc'];
$GROUPES = ['A+','A-','B+','B-','O+','O-','AB+','AB-'];

function pick(array $arr) { return $arr[array_rand($arr)]; }
function suffix() { return mt_rand(1000, 9999); }
function blockchainId(string $prefix) {
    return strtoupper($prefix) . '-0x' . substr(hash('sha256', $prefix . microtime(true) . mt_rand()), 0, 16);
}

// Récupérer rôles
$rolePatient   = (int)$pdo->query("SELECT id FROM roles WHERE code='patient'")->fetchColumn();
$roleInfirmier = (int)$pdo->query("SELECT id FROM roles WHERE code='infirmier'")->fetchColumn();
$roleDocteur   = (int)$pdo->query("SELECT id FROM roles WHERE code IN ('docteur','medecin') ORDER BY id LIMIT 1")->fetchColumn();

if (!$rolePatient || !$roleInfirmier || !$roleDocteur) {
    die("Rôles introuvables — vérifiez la table 'roles' (codes : patient, infirmier, docteur).");
}

// Hôpitaux
$hopitaux = $pdo->query("SELECT id, nom FROM hopitaux WHERE statut='actif' ORDER BY id")->fetchAll();
if (!$hopitaux) {
    die("Aucun hôpital actif. Créez au moins un hôpital avant de lancer le seed.");
}

$pwdHash = password_hash('passe1234', PASSWORD_DEFAULT);
$totalDocteurs = 0;
$totalInfirmiers = 0;
$totalPatients = 0;
$skipped = 0;

echo "═══════════════════════════════════════════════════\n";
echo "  GÉNÉRATION DES DONNÉES DE TEST — MedChain\n";
echo "═══════════════════════════════════════════════════\n\n";
echo "Hôpitaux ciblés : " . count($hopitaux) . "\n\n";

foreach ($hopitaux as $h) {
    $hopId = (int)$h['id'];
    echo "▶ Hôpital #$hopId — {$h['nom']}\n";

    // ── 6 docteurs ────────────────────────────────────
    $created = 0;
    for ($i = 0; $i < 6; $i++) {
        $sex = mt_rand(0, 1) ? 'M' : 'F';
        $prenom = $sex === 'M' ? pick($PRENOMS_M) : pick($PRENOMS_F);
        $nom    = pick($NOMS);
        $email  = strtolower($prenom . '.' . $nom) . '.' . suffix() . '@hop' . $hopId . '.bj';
        $email  = preg_replace('/[^a-z0-9.@_-]/', '', $email);
        $licence = 'DR-' . $hopId . '-' . sprintf('%05d', mt_rand(10000, 99999));

        try {
            $pdo->prepare("
                INSERT INTO docteurs
                    (identifiant_blockchain, nom, prenom, specialite, numero_licence,
                     telephone, email, mot_de_passe, hopital_id, role_id, statut)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')
            ")->execute([
                blockchainId('DR'), $nom, $prenom, pick($SPECIALITES_DR),
                $licence, '+229 9' . mt_rand(1000000, 9999999),
                $email, $pwdHash, $hopId, $roleDocteur,
            ]);
            $created++;
        } catch (PDOException $e) {
            $skipped++;
            // Conflit unique : on continue
        }
    }
    $totalDocteurs += $created;
    echo "   • $created docteurs créés\n";

    // ── 20 infirmiers ─────────────────────────────────
    $created = 0;
    for ($i = 0; $i < 20; $i++) {
        $sex = mt_rand(0, 1) ? 'M' : 'F';
        $prenom = $sex === 'M' ? pick($PRENOMS_M) : pick($PRENOMS_F);
        $nom    = pick($NOMS);
        $email  = strtolower($prenom . '.' . $nom) . '.' . suffix() . '@hop' . $hopId . '.bj';
        $email  = preg_replace('/[^a-z0-9.@_-]/', '', $email);
        $licence = 'INF-' . $hopId . '-' . sprintf('%05d', mt_rand(10000, 99999));

        try {
            $pdo->prepare("
                INSERT INTO infirmiers
                    (identifiant_blockchain, nom, prenom, specialite, fonction,
                     numero_licence, telephone, email, mot_de_passe,
                     hopital_principal_id, role_id, statut)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')
            ")->execute([
                blockchainId('INF'), $nom, $prenom, pick($SPECIALITES_INF), pick($FONCTIONS),
                $licence, '+229 9' . mt_rand(1000000, 9999999),
                $email, $pwdHash, $hopId, $roleInfirmier,
            ]);
            $created++;
        } catch (PDOException $e) {
            $skipped++;
        }
    }
    $totalInfirmiers += $created;
    echo "   • $created infirmiers créés\n";

    // ── 30 patients ───────────────────────────────────
    $created = 0;
    for ($i = 0; $i < 30; $i++) {
        $sex = mt_rand(0, 1) ? 'M' : 'F';
        $prenom = $sex === 'M' ? pick($PRENOMS_M) : pick($PRENOMS_F);
        $nom    = pick($NOMS);
        $year = mt_rand(1940, 2020);
        $month = sprintf('%02d', mt_rand(1, 12));
        $day = sprintf('%02d', mt_rand(1, 28));
        $dateNaissance = "$year-$month-$day";

        $username = strtolower($prenom . '.' . $nom . '.' . suffix());
        $username = preg_replace('/[^a-z0-9._-]/', '', $username);
        $email = $username . '@patient.bj';
        $npi = 'NPI-' . sprintf('%011d', mt_rand(10000000000, 99999999999));

        try {
            $pdo->prepare("
                INSERT INTO patients
                    (identifiant_blockchain, nom_utilisateur, nom, prenom, date_naissance,
                     sexe, groupe_sanguin, NPI, adresse, telephone, email,
                     mot_de_passe, role_id, hopital_reference, statut)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')
            ")->execute([
                blockchainId('PAT'), $username, $nom, $prenom, $dateNaissance,
                $sex, pick($GROUPES), $npi,
                'Quartier ' . pick(['Cadjèhoun','Akpakpa','Cotonou-Centre','Calavi','Godomey']) . ', Bénin',
                '+229 9' . mt_rand(1000000, 9999999),
                $email, $pwdHash, $rolePatient, $hopId,
            ]);
            $created++;
        } catch (PDOException $e) {
            $skipped++;
        }
    }
    $totalPatients += $created;
    echo "   • $created patients créés\n\n";
}

echo "═══════════════════════════════════════════════════\n";
echo "  RÉSUMÉ\n";
echo "═══════════════════════════════════════════════════\n";
echo "  Docteurs créés    : $totalDocteurs\n";
echo "  Infirmiers créés  : $totalInfirmiers\n";
echo "  Patients créés    : $totalPatients\n";
echo "  Doublons ignorés  : $skipped\n\n";
echo "  Mot de passe par défaut pour tous : passe1234\n";
echo "═══════════════════════════════════════════════════\n";
