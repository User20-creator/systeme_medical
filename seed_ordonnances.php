<?php
// seed_ordonnances.php — Génère 1 à 3 ordonnances par patient,
// signées par leur médecin traitant. Lancement :
//   http://localhost/systeme_medical/seed_ordonnances.php?confirm=1
require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_medecin_traitant_column($pdo);

set_time_limit(600);
ini_set('memory_limit', '256M');

if (!isset($_GET['confirm'])) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Seed ordonnances</title>';
    echo '<style>body{font-family:system-ui;padding:40px;background:#f8fafc;color:#0f172a;}';
    echo '.box{max-width:680px;margin:auto;background:white;padding:32px;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08);}';
    echo 'a.btn{display:inline-block;margin-top:16px;padding:11px 18px;background:#10b981;color:white;border-radius:10px;text-decoration:none;font-weight:700;}</style></head><body>';
    echo '<div class="box"><h2>Génération d\'ordonnances de test</h2>';
    echo '<p>Pour chaque patient ayant un médecin traitant, ce script va créer :</p>';
    echo '<ul><li><strong>1 dossier médical</strong> (type ordonnance) signé par son médecin</li>';
    echo '<li><strong>1 à 3 prescriptions</strong> de médicaments réalistes par dossier</li></ul>';
    echo '<p>Le patient pourra voir ses ordonnances dans <em>Mes ordonnances</em>, et le docteur les retrouvera dans son onglet <em>Prescriptions</em>.</p>';
    echo '<a class="btn" href="seed_ordonnances.php?confirm=1">Lancer la génération</a>';
    echo '</div></body></html>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

// Catalogue de médicaments réalistes (Bénin / Afrique de l'Ouest)
$MEDICAMENTS = [
    ['Paracétamol 1000mg', '1 comprimé', '3 fois par jour', '5 jours'],
    ['Amoxicilline 500mg', '1 gélule', '3 fois par jour', '7 jours'],
    ['Ibuprofène 400mg', '1 comprimé', '2 fois par jour', '5 jours'],
    ['Doliprane 500mg', '2 comprimés', 'matin et soir', '3 jours'],
    ['Coartem (Artéméther/Luméfantrine)', '4 comprimés', '2 fois par jour', '3 jours'],
    ['Métronidazole 250mg', '2 comprimés', '3 fois par jour', '7 jours'],
    ['Oméprazole 20mg', '1 gélule', 'avant le petit déjeuner', '14 jours'],
    ['Loratadine 10mg', '1 comprimé', 'le matin', '10 jours'],
    ['Salbutamol (inhalateur)', '2 bouffées', 'en cas de crise', 'au besoin'],
    ['Ciprofloxacine 500mg', '1 comprimé', '2 fois par jour', '7 jours'],
    ['Captopril 25mg', '1 comprimé', '2 fois par jour', 'traitement chronique'],
    ['Metformine 500mg', '1 comprimé', 'aux 3 repas', 'traitement chronique'],
    ['Furosémide 40mg', '1 comprimé', 'le matin', '15 jours'],
    ['Clopidogrel 75mg', '1 comprimé', 'le matin', 'traitement chronique'],
    ['Prednisolone 5mg', '2 comprimés', 'le matin', '7 jours puis dégressif'],
    ['Vitamine C 500mg', '1 comprimé', 'le matin', '30 jours'],
    ['Fer + Acide folique', '1 comprimé', 'le matin', '60 jours'],
    ['Mébendazole 100mg', '1 comprimé', '2 fois par jour', '3 jours'],
    ['Cetirizine 10mg', '1 comprimé', 'le soir', '7 jours'],
    ['Diclofenac 50mg', '1 comprimé', '2 fois par jour', '5 jours'],
];

$MOTIFS = [
    'Consultation de routine',
    'Suivi de l\'hypertension',
    'Suivi du diabète',
    'Contrôle annuel',
    'Symptômes grippaux',
    'Suivi post-opératoire',
    'Allergie saisonnière',
    'Infection respiratoire',
    'Bilan sanguin',
    'Suivi cardiologique',
    'Douleurs articulaires',
    'Carence en fer',
    'Suivi gynécologique',
    'Contrôle pédiatrique',
    'Paludisme suspecté',
];

function pick(array $arr) { return $arr[array_rand($arr)]; }

// Récupérer tous les patients avec un médecin traitant
$stmt = $pdo->query("
    SELECT p.id AS patient_id,
           p.prenom AS p_prenom, p.nom AS p_nom,
           p.hopital_reference,
           p.medecin_traitant_id,
           d.prenom AS d_prenom, d.nom AS d_nom
    FROM patients p
    JOIN docteurs d ON d.id = p.medecin_traitant_id
    WHERE p.statut = 'actif'
");
$patients = $stmt->fetchAll();

echo "═══════════════════════════════════════════════════\n";
echo "  GÉNÉRATION D'ORDONNANCES — MedChain\n";
echo "═══════════════════════════════════════════════════\n\n";
echo "Patients ciblés : " . count($patients) . "\n\n";

$totalDossiers = 0;
$totalPresc = 0;
$skipped = 0;

foreach ($patients as $p) {
    $patientId = (int)$p['patient_id'];
    $docteurId = (int)$p['medecin_traitant_id'];
    $hopitalId = (int)($p['hopital_reference'] ?? 0) ?: null;

    // 1 à 3 ordonnances par patient
    $nbOrd = mt_rand(1, 3);

    for ($k = 0; $k < $nbOrd; $k++) {
        $motif = pick($MOTIFS);
        $datePrescription = date('Y-m-d', strtotime('-' . mt_rand(0, 180) . ' days'));

        try {
            $pdo->beginTransaction();

            // 1) Créer le dossier médical (type ordonnance)
            $contenuJson = json_encode([
                'type'       => 'ordonnance',
                'patient_id' => $patientId,
                'medecin_id' => $docteurId,
                'motif'      => $motif,
                'date'       => $datePrescription,
                'random'     => mt_rand(1, 1000000),
            ], JSON_UNESCAPED_UNICODE);

            $hashContenu = hash('sha256', $contenuJson);
            $txDossier   = '0x' . substr(hash('sha256', $hashContenu . random_bytes(16)), 0, 64);
            $signature   = HashChain::sign($contenuJson . $docteurId . microtime(true));
            $signatureStr = is_string($signature) ? $signature : json_encode($signature);

            $insDossier = $pdo->prepare("
                INSERT INTO dossiers_medicaux
                    (transaction_hash, patient_id, hopital_id, type_document, titre,
                     description, date_creation, signature_medecin, hash_contenu,
                     confidentialite, motif_visite, modifie_par_docteur)
                VALUES
                    (?, ?, ?, 'ordonnance', ?, ?, ?, ?, ?, 'medecin', ?, ?)
            ");
            $insDossier->execute([
                $txDossier, $patientId, $hopitalId,
                'Ordonnance — ' . mb_substr($motif, 0, 100),
                "Ordonnance émise pour : $motif",
                $datePrescription . ' ' . sprintf('%02d:%02d:%02d', mt_rand(8, 18), mt_rand(0, 59), mt_rand(0, 59)),
                $signatureStr, $hashContenu,
                $motif,
                $docteurId,
            ]);
            $dossierId = (int)$pdo->lastInsertId();

            // 2) Créer 1 à 4 prescriptions sur ce dossier
            $nbMed = mt_rand(1, 4);
            $usedMeds = [];
            for ($m = 0; $m < $nbMed; $m++) {
                // Éviter les doublons sur le même dossier
                $tries = 0;
                do {
                    $med = pick($MEDICAMENTS);
                    $tries++;
                } while (in_array($med[0], $usedMeds, true) && $tries < 10);
                $usedMeds[] = $med[0];

                $prTx = '0x' . substr(hash('sha256', $dossierId . $med[0] . random_bytes(16)), 0, 64);

                $insPr = $pdo->prepare("
                    INSERT INTO prescriptions
                        (transaction_hash, dossier_medical_id, medicament, dosage,
                         frequence, duree, date_prescription, statut)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $insPr->execute([
                    $prTx, $dossierId, $med[0], $med[1], $med[2], $med[3], $datePrescription,
                ]);
                $totalPresc++;
            }

            $pdo->commit();
            $totalDossiers++;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $skipped++;
            error_log('seed_ordonnances: ' . $e->getMessage());
        }
    }

    if ($totalDossiers % 50 === 0) {
        echo "   ... $totalDossiers dossiers créés\n";
    }
}

echo "\n═══════════════════════════════════════════════════\n";
echo "  RÉSUMÉ\n";
echo "═══════════════════════════════════════════════════\n";
echo "  Dossiers ordonnance créés : $totalDossiers\n";
echo "  Prescriptions créées      : $totalPresc\n";
echo "  Erreurs ignorées          : $skipped\n";
echo "═══════════════════════════════════════════════════\n";
echo "\n→ Patients : voir 'Mes ordonnances'\n";
echo "→ Docteurs : voir l'onglet 'Prescriptions'\n";
