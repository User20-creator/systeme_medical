<?php
// envoyer_au_docteur.php — Endpoint POST utilisé par dashboard_infirmier
// et le modal succès de creer_patient. Trouve le docteur en service de
// l'hôpital, crée ou met à jour le dossier médical, redirige avec flash.

require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_extended_columns($pdo);

if (($_SESSION['user_type'] ?? '') !== 'infirmier' || empty($_SESSION['medecin_id'])) {
    header('Location: connexion2.php'); exit;
}

$infirmier_id = (int)$_SESSION['medecin_id'];
$hopitalId    = (int)($_SESSION['medecin_hopital_principal_id'] ?? 0);
$patientCible = (int)($_POST['patient_id'] ?? 0);
$redirect     = $_POST['redirect'] ?? 'dashboard_infirmier.php';

// Whitelist des redirections pour éviter open-redirect
$allowedRedirects = ['dashboard_infirmier.php', 'creer_patient.php', 'liste_utilisateurs.php'];
if (!in_array($redirect, $allowedRedirects, true)) $redirect = 'dashboard_infirmier.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect); exit;
}

if (!csrf_check()) {
    $_SESSION['flash_error'] = "Jeton de sécurité invalide. Veuillez recharger la page.";
    header('Location: ' . $redirect); exit;
}

if (!$patientCible || !$hopitalId) {
    $_SESSION['flash_error'] = "Patient ou hôpital introuvable.";
    header('Location: ' . $redirect); exit;
}

try {
    // 1) Vérifier que le patient existe
    $stmt = $pdo->prepare("SELECT id, prenom, nom, hopital_reference FROM patients WHERE id = ? AND statut = 'actif'");
    $stmt->execute([$patientCible]);
    $patient = $stmt->fetch();
    if (!$patient) {
        $_SESSION['flash_error'] = "Patient introuvable ou inactif.";
        header('Location: ' . $redirect); exit;
    }

    // L'hôpital cible : celui du patient si renseigné, sinon celui de l'infirmier
    $targetHopital = (int)($patient['hopital_reference'] ?? 0) ?: $hopitalId;

    // 2) Trouver un docteur en service dans cet hôpital
    $stmt = $pdo->prepare("
        SELECT id, prenom, nom FROM docteurs
        WHERE hopital_id = ? AND statut = 'actif' AND en_service = 1
        ORDER BY RAND() LIMIT 1
    ");
    $stmt->execute([$targetHopital]);
    $docteur = $stmt->fetch();
    if (!$docteur) {
        $_SESSION['flash_error'] = "Aucun docteur en service actuellement dans l'hôpital du patient. Demandez à un docteur d'activer son statut « En service ».";
        header('Location: ' . $redirect); exit;
    }

    // 3) Récupérer le dernier dossier de ce patient créé par cet infirmier, OU en créer un minimal
    $stmt = $pdo->prepare("
        SELECT id FROM dossiers_medicaux
        WHERE patient_id = ? AND cree_par_infirmier = ?
        ORDER BY date_creation DESC LIMIT 1
    ");
    $stmt->execute([$patientCible, $infirmier_id]);
    $dossierId = (int)$stmt->fetchColumn();

    if (!$dossierId) {
        // créer un dossier minimal pour transmettre
        $contenu = json_encode([
            'type' => 'transmission',
            'patient_id' => $patientCible,
            'infirmier_id' => $infirmier_id,
            'created_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE);
        $hashContenu = hash('sha256', $contenu);
        $txHash = '0x' . substr(hash('sha256', $hashContenu . random_bytes(16)), 0, 64);
        $sig = HashChain::sign($contenu . $infirmier_id . time());
        $sigStr = is_string($sig) ? $sig : json_encode($sig);

        $ins = $pdo->prepare("
            INSERT INTO dossiers_medicaux
                (transaction_hash, patient_id, hopital_id, type_document, titre,
                 description, date_creation, signature_medecin, hash_contenu,
                 confidentialite, motif_visite, cree_par_infirmier,
                 envoye_au_docteur_id, date_envoi_docteur, statut_prise_en_charge)
            VALUES
                (:tx, :pid, :hop, 'consultation', 'Transmission infirmier',
                 'Patient transmis au docteur en service', NOW(), :sig, :hash,
                 'medecin', 'Transmission accueil', :inf,
                 :doc, NOW(), 'envoye')
        ");
        $ins->execute([
            ':tx' => $txHash, ':pid' => $patientCible, ':hop' => $targetHopital,
            ':sig' => $sigStr, ':hash' => $hashContenu,
            ':inf' => $infirmier_id, ':doc' => $docteur['id'],
        ]);
        $dossierId = (int)$pdo->lastInsertId();
    } else {
        $upd = $pdo->prepare("
            UPDATE dossiers_medicaux
            SET envoye_au_docteur_id = ?, date_envoi_docteur = NOW(),
                statut_prise_en_charge = 'envoye'
            WHERE id = ?
        ");
        $upd->execute([$docteur['id'], $dossierId]);
    }

    HashChain::addBlock('ENVOI_DOCTEUR', $dossierId, $infirmier_id, 'infirmier', [
        'patient_id' => $patientCible,
        'patient'    => $patient['prenom'] . ' ' . $patient['nom'],
        'docteur_id' => $docteur['id'],
        'docteur'    => $docteur['prenom'] . ' ' . $docteur['nom'],
    ], 'dossiers_medicaux');

    $_SESSION['flash_success'] =
        $patient['prenom'] . ' ' . $patient['nom'] .
        " a été envoyé au Dr. " . $docteur['prenom'] . ' ' . $docteur['nom'] .
        ". Le docteur a été notifié.";
} catch (PDOException $e) {
    error_log('envoyer_au_docteur: ' . $e->getMessage());
    $_SESSION['flash_error'] = "Erreur lors de l'envoi au docteur.";
}

header('Location: ' . $redirect);
exit;
