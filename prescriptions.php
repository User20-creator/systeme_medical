<?php
// prescriptions.php — Gestion des ordonnances (médecin) / vue patient
require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_extended_columns($pdo);

$userType = $_SESSION['user_type'] ?? '';

// Accès multiple : docteur/medecin (créer + voir) ; patient (voir ses ordonnances)
if ($userType === 'patient') {
    $patientId = (int)($_SESSION['user_id'] ?? 0);
    if (!$patientId) { header('Location: connexion1.php'); exit; }
} elseif (in_array($userType, ['docteur','medecin'])) {
    $medecinId = (int)($_SESSION['medecin_id'] ?? 0);
    if (!$medecinId) { header('Location: connexion2.php'); exit; }
} else {
    header('Location: connexion2.php'); exit;
}

$erreur = '';
$ok     = null;

$action    = $_GET['action'] ?? '';
$patientIdParam = (int)($_GET['patient'] ?? $_POST['patient_id'] ?? 0);
$dossierId = (int)($_GET['dossier'] ?? $_POST['dossier_id'] ?? 0);

// ── ACTION : Nouvelle ordonnance (docteur uniquement) ──────
if (in_array($userType, ['docteur','medecin']) && $action === 'new' && $patientIdParam) {
    // Patient
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$patientIdParam]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) { header('Location: mes_patients.php'); exit; }

    // Si pas de dossier, chercher un dossier récent signé par ce docteur pour ce patient.
    // (modifie_par_docteur — la colonne docteur_id a une FK legacy vers infirmiers.)
    if (!$dossierId) {
        $last = $pdo->prepare("
            SELECT id FROM dossiers_medicaux
             WHERE patient_id = ? AND modifie_par_docteur = ?
          ORDER BY date_creation DESC LIMIT 1
        ");
        $last->execute([$patientIdParam, $medecinId]);
        $dossierId = (int)$last->fetchColumn();
    }

    // Traitement POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_prescription']) && !csrf_check()) {
        $erreur = "Jeton de sécurité invalide. Veuillez recharger la page.";
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_prescription'])) {
        $medicaments = $_POST['medicament'] ?? [];
        $dosages     = $_POST['dosage']     ?? [];
        $frequences  = $_POST['frequence']  ?? [];
        $durees      = $_POST['duree']      ?? [];
        $dateDebuts  = $_POST['date_debut'] ?? [];
        $dateFins    = $_POST['date_fin']   ?? [];
        $heuresAll   = $_POST['heures']     ?? []; // tableau de tableaux (par médicament)
        $instructions = trim($_POST['instructions'] ?? '');

        $valids = [];
        foreach ($medicaments as $i => $med) {
            $med = trim($med);
            if (!$med) continue;
            // Heures pour ce médicament : array ["08:00","13:00",...]
            $heuresMed = is_array($heuresAll[$i] ?? null) ? array_values(array_filter($heuresAll[$i], 'strlen')) : [];
            // On valide chaque heure au format HH:MM
            $heuresMed = array_values(array_filter($heuresMed, fn($h) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h)));
            // Date_debut par défaut = aujourd'hui si non saisi
            $debut = $dateDebuts[$i] ?? '';
            $debut = $debut && preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut) ? $debut : date('Y-m-d');
            $fin = $dateFins[$i] ?? '';
            $fin = $fin && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin) ? $fin : null;

            $valids[] = [
                'medicament'   => $med,
                'dosage'       => trim($dosages[$i] ?? ''),
                'frequence'    => trim($frequences[$i] ?? ''),
                'duree'        => trim($durees[$i] ?? ''),
                'date_debut'   => $debut,
                'date_fin'     => $fin,
                'heures_prise' => $heuresMed ? json_encode($heuresMed) : null,
            ];
        }

        if (empty($valids)) {
            $erreur = "Ajoutez au moins un médicament.";
        } else {
            try {
                $pdo->beginTransaction();

                // Si toujours pas de dossier, créer un dossier "ordonnance".
                // On fournit explicitement TOUS les champs NOT NULL du schéma.
                if (!$dossierId) {
                    $description = $instructions
                        ? "Ordonnance — instructions :\n" . $instructions
                        : 'Ordonnance médicale.';
                    $contenuJson = json_encode([
                        'type'       => 'ordonnance',
                        'patient_id' => $patientIdParam,
                        'medecin_id' => $medecinId,
                        'created_at' => date('c'),
                    ], JSON_UNESCAPED_UNICODE);
                    $hashContenu     = hash('sha256', $contenuJson);
                    $transactionHash = '0x' . substr(hash('sha256', $hashContenu . random_bytes(16)), 0, 64);
                    $signature       = HashChain::sign($contenuJson . $medecinId . time());
                    $signatureStr    = is_string($signature) ? $signature : json_encode($signature);

                    $stmt = $pdo->prepare("
                        INSERT INTO dossiers_medicaux
                            (transaction_hash, patient_id, hopital_id, type_document, titre,
                             description, date_creation, signature_medecin, hash_contenu,
                             confidentialite, motif_visite, modifie_par_docteur)
                        VALUES
                            (:tx, :pid, :hopital, 'ordonnance', 'Ordonnance',
                             :description, NOW(), :signature, :hash,
                             'medecin', 'Ordonnance', :docteur)
                    ");
                    $stmt->execute([
                        ':tx'          => $transactionHash,
                        ':pid'         => $patientIdParam,
                        ':hopital'     => $patient['hopital_reference'] ?? null,
                        ':description' => $description,
                        ':signature'   => $signatureStr,
                        ':hash'        => $hashContenu,
                        ':docteur'     => $medecinId,
                    ]);
                    $dossierId = (int)$pdo->lastInsertId();
                }

                // Insérer chaque prescription. Schéma : transaction_hash NOT NULL UNIQUE.
                // Note : la colonne `instructions` n'existe pas dans prescriptions ;
                // les instructions globales sont stockées dans dossier.description.
                $createdIds = [];
                foreach ($valids as $v) {
                    $prTx = '0x' . substr(hash('sha256', $dossierId . $v['medicament'] . random_bytes(16)), 0, 64);
                    $stmt = $pdo->prepare("
                        INSERT INTO prescriptions
                            (transaction_hash, dossier_medical_id, medicament,
                             dosage, frequence, duree, date_prescription,
                             date_debut, date_fin, heures_prise,
                             valide_jusquau, statut)
                        VALUES
                            (:tx, :did, :med, :dos, :freq, :dur, :date,
                             :ddebut, :dfin, :hrs,
                             :validt, 'active')
                    ");
                    $stmt->execute([
                        ':tx'     => $prTx,
                        ':did'    => $dossierId,
                        ':med'    => $v['medicament'],
                        ':dos'    => $v['dosage'] ?: '—',
                        ':freq'   => $v['frequence'] ?: null,
                        ':dur'    => $v['duree'] ?: null,
                        ':date'   => date('Y-m-d'),
                        ':ddebut' => $v['date_debut'],
                        ':dfin'   => $v['date_fin'],
                        ':hrs'    => $v['heures_prise'],
                        ':validt' => $v['date_fin'],
                    ]);
                    $createdIds[] = (int)$pdo->lastInsertId();
                }

                HashChain::addBlock('CREATE_PRESCRIPTION', $createdIds[0] ?? 0, $medecinId, $userType, [
                    'patient_id'     => $patientIdParam,
                    'patient'        => $patient['prenom'] . ' ' . $patient['nom'],
                    'dossier_id'     => $dossierId,
                    'nb_medicaments' => count($valids),
                    'medicaments'    => array_column($valids, 'medicament'),
                ], 'prescriptions');

                $pdo->commit();

                $ok = [
                    'count'      => count($valids),
                    'patient'    => $patient,
                    'dossier_id' => $dossierId,
                    'meds'       => $valids,
                ];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('prescriptions: ' . $e->getMessage());
                $erreur = "Erreur lors de l'enregistrement de l'ordonnance.";
            }
        }
    }

    // AFFICHAGE FORMULAIRE NOUVELLE PRESCRIPTION
    $pageTitle = 'Nouvelle ordonnance';
    $pageActive = 'prescriptions';
    $breadcrumb = ['Soins', 'Prescriptions', 'Nouvelle'];
    require_once 'includes/header_dashboard.php';
    ?>

    <!-- HERO -->
    <section class="dash-hero reveal-up" style="background:linear-gradient(135deg,#064e3b 0%,#0d9488 50%,#0ea5e9 100%)">
      <div class="dash-hero-content">
        <div class="dash-hero-greet">
          <span class="dash-hero-dot"></span>
          Prescription médicale en cours
        </div>
        <h1>Ordonnance pour <span><?= htmlspecialchars($patient['prenom'] . ' ' . $patient['nom']) ?></span></h1>
        <p>Chaque médicament prescrit est signé de votre clé et inscrit dans le registre national.</p>
      </div>
      <div class="dash-hero-card">
        <div class="dash-hero-card-head">
          <span class="dash-hero-card-label">Patient</span>
          <i class="fas fa-prescription-bottle-medical"></i>
        </div>
        <div class="dash-hero-card-hash" style="font-size:16px;"><?= htmlspecialchars($patient['prenom'] . ' ' . $patient['nom']) ?></div>
        <div class="dash-hero-card-meta">
          <?php if ($patient['groupe_sanguin']): ?><span><i class="fas fa-tint"></i> <?= htmlspecialchars($patient['groupe_sanguin']) ?></span><?php endif; ?>
          <span>·</span>
          <span>Allergies ?</span>
        </div>
      </div>
    </section>

    <?php if ($ok): ?>
      <div class="alert-box success reveal-up">
        <div class="ab-icon"><i class="fas fa-check-circle"></i></div>
        <div>
          <strong>Ordonnance enregistrée et signée</strong>
          <p style="margin:4px 0 0;font-size:13px;"><?= $ok['count'] ?> médicament<?= $ok['count'] > 1 ? 's' : '' ?> prescrit<?= $ok['count'] > 1 ? 's' : '' ?> pour <?= htmlspecialchars($ok['patient']['prenom'] . ' ' . $ok['patient']['nom']) ?>.</p>
        </div>
        <div class="alert-actions">
          <a href="dossier_patient.php?id=<?= $patientIdParam ?>" class="btn btn-primary"><i class="fas fa-folder-open"></i> Dossier</a>
          <a href="prescriptions.php" class="btn btn-outline"><i class="fas fa-list"></i> Toutes les prescriptions</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($erreur): ?>
      <div class="alert-box error reveal-up"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <?php if (!$ok): ?>
    <section class="form-shell reveal-up">
      <form method="POST" class="form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="patient_id" value="<?= $patientIdParam ?>">
        <input type="hidden" name="dossier_id" value="<?= $dossierId ?>">

        <div class="form-head">
          <div class="form-head-icon"><i class="fas fa-prescription"></i></div>
          <div>
            <h2>Rédaction de l'ordonnance</h2>
            <p>Ajoutez autant de médicaments que nécessaire.</p>
          </div>
        </div>

        <div class="form-body">
          <div id="med-list">
            <div class="med-row" data-idx="0">
              <div class="med-row-head">
                <span class="med-num">#1</span>
                <button type="button" class="btn-del" onclick="removeMed(this)"><i class="fas fa-trash"></i></button>
              </div>
              <div class="med-grid">
                <div class="form-group full">
                  <label>Médicament</label>
                  <div class="input-shell">
                    <i class="fas fa-pills"></i>
                    <input type="text" name="medicament[]" placeholder="Ex: Doliprane 1000mg" required>
                  </div>
                </div>
                <div class="form-group">
                  <label>Dosage</label>
                  <div class="input-shell">
                    <i class="fas fa-vial"></i>
                    <input type="text" name="dosage[]" placeholder="1 comprimé">
                  </div>
                </div>
                <div class="form-group">
                  <label>Fréquence</label>
                  <div class="input-shell">
                    <i class="fas fa-clock"></i>
                    <input type="text" name="frequence[]" placeholder="3 fois par jour">
                  </div>
                </div>
                <div class="form-group">
                  <label>Date de début</label>
                  <div class="input-shell">
                    <i class="fas fa-play"></i>
                    <input type="date" name="date_debut[]" value="<?= date('Y-m-d') ?>" required>
                  </div>
                </div>
                <div class="form-group">
                  <label>Date de fin</label>
                  <div class="input-shell">
                    <i class="fas fa-stop"></i>
                    <input type="date" name="date_fin[]" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                  </div>
                </div>
              </div>
              <div class="med-hours-block">
                <label>Heures de prise <small style="color:var(--muted);font-weight:500">(cliquez pour ajouter)</small></label>
                <div class="hours-chips" data-row="0">
                  <?php foreach (['08:00','12:00','16:00','20:00','22:00'] as $h): ?>
                    <button type="button" class="hour-chip" data-hour="<?= $h ?>"><?= $h ?></button>
                  <?php endforeach; ?>
                  <input type="time" class="hour-custom" placeholder="Autre">
                  <button type="button" class="hour-add"><i class="fas fa-plus"></i></button>
                </div>
                <div class="hours-selected" data-row="0"></div>
              </div>
            </div>
          </div>

          <button type="button" class="btn-add-med" onclick="addMed()">
            <i class="fas fa-plus"></i> Ajouter un autre médicament
          </button>

          <div class="section-divider" style="margin-top:24px"><i class="fas fa-comment-dots"></i> Instructions</div>
          <div class="form-group full">
            <label>Remarques pour le patient</label>
            <textarea name="instructions" rows="3" placeholder="Prendre après les repas, éviter alcool..."></textarea>
          </div>

          <div class="signature-strip">
            <div class="sig-left">
              <i class="fas fa-pen-fancy"></i>
              <div>
                <strong>Signature numérique du praticien</strong>
                <span>Votre clé privée signe cette ordonnance — non falsifiable par la pharmacie.</span>
              </div>
            </div>
            <div class="sig-dot"><span></span> Prêt</div>
          </div>
        </div>

        <div class="form-actions">
          <a href="dossier_patient.php?id=<?= $patientIdParam ?>" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Annuler
          </a>
          <button type="submit" name="submit_prescription" value="1" class="btn btn-primary">
            <i class="fas fa-cube"></i> Signer et enregistrer l'ordonnance
          </button>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <style>
      .form-shell { max-width: 960px; margin: 0 auto; }
      .form-card { background: white; border: 1px solid var(--line); border-radius: 20px; overflow: hidden; box-shadow: 0 14px 34px -18px rgba(15,23,42,.12); }
      .form-head { display: flex; align-items: center; gap: 16px; padding: 24px 28px; border-bottom: 1px solid var(--line); background: linear-gradient(135deg, rgba(16,185,129,.06), rgba(14,165,233,.03)); }
      .form-head-icon { width: 52px; height: 52px; border-radius: 14px; background: var(--g-emerald); color: white; display: grid; place-items: center; font-size: 20px; box-shadow: 0 10px 24px -10px rgba(16,185,129,.5); }
      .form-head h2 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 18px; font-weight: 800; color: var(--ink); margin: 0; }
      .form-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }
      .form-body { padding: 28px; }

      .med-row { background: #f8fafc; border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-bottom: 14px; transition: .25s; }
      .med-row:hover { border-color: rgba(16,185,129,.3); }
      .med-row-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
      .med-num { font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 700; color: var(--emerald); background: rgba(16,185,129,.1); padding: 4px 10px; border-radius: 6px; }
      .btn-del { background: none; border: none; color: var(--muted); cursor: pointer; padding: 6px 10px; border-radius: 6px; transition: .2s; }
      .btn-del:hover { background: rgba(239,68,68,.08); color: #dc2626; }
      .med-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

      .btn-add-med { width: 100%; padding: 14px; border: 2px dashed var(--line); background: transparent; border-radius: 14px; color: var(--muted); font-weight: 700; font-size: 13px; cursor: pointer; transition: .25s; font-family: inherit; display: flex; align-items: center; justify-content: center; gap: 8px; }
      .btn-add-med:hover { border-color: var(--emerald); color: var(--emerald); background: rgba(16,185,129,.04); }

      .section-divider { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: var(--emerald); padding: 18px 0 12px; border-bottom: 2px solid rgba(16,185,129,.1); margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }

      .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; }
      .form-group.full { grid-column: 1/-1; }
      .form-group label { font-size: 13px; font-weight: 700; color: var(--ink); }
      .input-shell { display: flex; align-items: center; gap: 10px; background: white; border: 1.5px solid var(--line); border-radius: 12px; padding: 0 14px; transition: .25s; }
      .input-shell:focus-within { border-color: var(--emerald); box-shadow: 0 0 0 3px rgba(16,185,129,.1); }
      .input-shell i { color: var(--muted); width: 14px; font-size: 14px; }
      .input-shell input { flex: 1; border: none; background: transparent; outline: none; padding: 12px 0; font-size: 14px; color: var(--ink); font-family: inherit; }
      textarea { width: 100%; border: 1.5px solid var(--line); background: #f8fafc; border-radius: 12px; padding: 12px 14px; font-size: 14px; color: var(--ink); font-family: inherit; resize: vertical; }
      textarea:focus { outline: none; border-color: var(--emerald); background: white; box-shadow: 0 0 0 3px rgba(16,185,129,.1); }

      .signature-strip { margin-top: 20px; padding: 18px 20px; background: linear-gradient(135deg, rgba(16,185,129,.05), rgba(99,102,241,.03)); border: 1px solid rgba(16,185,129,.2); border-radius: 14px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
      .sig-left { display: flex; align-items: center; gap: 14px; }
      .sig-left > i { width: 44px; height: 44px; display: grid; place-items: center; background: var(--g-emerald); color: white; border-radius: 12px; font-size: 18px; }
      .sig-left strong { display: block; font-size: 13px; color: var(--ink); margin-bottom: 3px; }
      .sig-left span { font-size: 12px; color: var(--muted); }
      .sig-dot { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.3); color: var(--forest); font-size: 12px; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
      .sig-dot span { width: 8px; height: 8px; border-radius: 50%; background: var(--emerald); animation: pulse 1.5s infinite; }
      @keyframes pulse { 0%,100% { opacity: 1; transform: scale(1); } 50% { opacity: .6; transform: scale(1.3); } }

      .form-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 20px 28px; border-top: 1px solid var(--line); background: #f8fafc; }
      .alert-box { display: flex; align-items: center; gap: 14px; padding: 18px; margin-bottom: 20px; border-radius: 16px; font-size: 14px; }
      .alert-box.success { background: linear-gradient(135deg, rgba(16,185,129,.08), rgba(16,185,129,.03)); border: 1px solid rgba(16,185,129,.3); color: #065f46; }
      .alert-box.error { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25); color: #b91c1c; }
      .ab-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--g-emerald); color: white; display: grid; place-items: center; font-size: 18px; flex-shrink: 0; }
      .alert-box > div { flex: 1; }
      .alert-actions { display: flex; gap: 8px; flex-wrap: wrap; }

      .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: .3s; font-family: inherit; }
      .btn-primary { background: var(--g-emerald); color: white; }
      .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -8px rgba(16,185,129,.4); }
      .btn-outline { background: white; border: 1px solid var(--line); color: var(--ink); }
      .btn-outline:hover { border-color: var(--emerald); color: var(--emerald); }

      @media (max-width:720px) { .med-grid { grid-template-columns: 1fr; } }

      /* Chips heures de prise */
      .med-hours-block { margin-top: 14px; }
      .med-hours-block > label { display: block; font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 8px; }
      .hours-chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 10px 12px; background: white; border: 1.5px solid var(--line); border-radius: 12px; }
      .hour-chip {
        padding: 6px 12px;
        background: #f8fafc;
        border: 1px solid var(--line);
        border-radius: 999px;
        font-size: 12px; font-weight: 600;
        color: var(--ink);
        cursor: pointer;
        transition: .2s;
        font-family: 'JetBrains Mono', monospace;
      }
      .hour-chip:hover { border-color: var(--emerald); color: var(--emerald); }
      .hour-chip.active {
        background: var(--g-emerald); color: white; border-color: transparent;
        box-shadow: 0 4px 12px -4px rgba(16,185,129,.5);
      }
      .hour-custom {
        padding: 6px 10px; font-size: 12px; border: 1px dashed var(--line); border-radius: 8px;
        background: transparent; color: var(--ink); font-family: inherit;
      }
      .hour-add {
        width: 28px; height: 28px; border-radius: 50%;
        background: rgba(16,185,129,.1); color: var(--emerald);
        border: none; cursor: pointer; font-size: 11px;
      }
      .hour-add:hover { background: var(--emerald); color: white; }
      .hours-selected { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
      .hour-tag {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 10px; font-size: 12px; font-weight: 700;
        background: rgba(16,185,129,.1); color: var(--forest);
        border-radius: 8px; font-family: 'JetBrains Mono', monospace;
      }
      .hour-tag button {
        background: none; border: none; color: var(--forest);
        cursor: pointer; font-size: 14px; line-height: 1; padding: 0 2px;
      }
      .hour-tag button:hover { color: #dc2626; }
    </style>

    <script>
      let medIdx = 1;
      function addMed() {
        const container = document.getElementById('med-list');
        const clone = container.firstElementChild.cloneNode(true);
        clone.dataset.idx = medIdx;
        clone.querySelector('.med-num').textContent = '#' + (medIdx + 1);
        // Réinit valeurs (texte vide, dates par défaut)
        clone.querySelectorAll('input[type=text], input[type=time]').forEach(inp => inp.value = '');
        // Date début aujourd'hui + fin J+7
        const dStart = clone.querySelector('input[name="date_debut[]"]');
        const dEnd   = clone.querySelector('input[name="date_fin[]"]');
        const today = new Date().toISOString().slice(0, 10);
        const next7 = new Date(Date.now() + 7*86400000).toISOString().slice(0, 10);
        if (dStart) dStart.value = today;
        if (dEnd)   dEnd.value = next7;
        // Réinit chips
        clone.querySelectorAll('.hour-chip').forEach(c => c.classList.remove('active'));
        clone.querySelectorAll('.hours-selected').forEach(s => { s.dataset.row = medIdx; s.innerHTML = ''; });
        clone.querySelectorAll('.hours-chips').forEach(s => s.dataset.row = medIdx);
        container.appendChild(clone);
        bindHourChips(clone);
        medIdx++;
        reorder();
      }
      function removeMed(btn) {
        const rows = document.querySelectorAll('.med-row');
        if (rows.length === 1) return;
        btn.closest('.med-row').remove();
        reorder();
      }
      function reorder() {
        document.querySelectorAll('.med-row').forEach((r, i) => {
          r.querySelector('.med-num').textContent = '#' + (i + 1);
        });
      }

      // ── Gestion des chips heures par ligne ──
      function bindHourChips(row) {
        const chipsContainer = row.querySelector('.hours-chips');
        const selectedContainer = row.querySelector('.hours-selected');
        if (!chipsContainer) return;
        const rowIdx = chipsContainer.dataset.row;
        const selectedSet = new Set();

        function addHidden(hour) {
          if (selectedSet.has(hour)) return;
          selectedSet.add(hour);
          const tag = document.createElement('span');
          tag.className = 'hour-tag';
          tag.innerHTML = '<i class="fas fa-clock"></i> ' + hour +
                          ' <button type="button" aria-label="Retirer">×</button>' +
                          '<input type="hidden" name="heures[' + rowIdx + '][]" value="' + hour + '">';
          tag.querySelector('button').addEventListener('click', () => {
            selectedSet.delete(hour);
            tag.remove();
            const matchingChip = chipsContainer.querySelector('.hour-chip[data-hour="' + hour + '"]');
            if (matchingChip) matchingChip.classList.remove('active');
          });
          selectedContainer.appendChild(tag);
        }

        chipsContainer.querySelectorAll('.hour-chip').forEach(chip => {
          chip.addEventListener('click', () => {
            const h = chip.dataset.hour;
            if (selectedSet.has(h)) {
              selectedSet.delete(h);
              chip.classList.remove('active');
              const tag = selectedContainer.querySelector('input[value="' + h + '"]')?.parentElement;
              if (tag) tag.remove();
            } else {
              chip.classList.add('active');
              addHidden(h);
            }
          });
        });
        const addBtn = chipsContainer.querySelector('.hour-add');
        const customInput = chipsContainer.querySelector('.hour-custom');
        if (addBtn && customInput) {
          addBtn.addEventListener('click', () => {
            if (customInput.value && /^([01]\d|2[0-3]):[0-5]\d$/.test(customInput.value)) {
              addHidden(customInput.value);
              customInput.value = '';
            }
          });
        }
      }
      document.querySelectorAll('.med-row').forEach(bindHourChips);
    </script>

    <?php
    require_once 'includes/footer_dashboard.php';
    exit;
}

// ── MODE LISTE : Toutes les prescriptions ─────────────────────
if (in_array($userType, ['docteur','medecin'])) {
    $stmt = $pdo->prepare("
        SELECT pr.*,
               p.id AS patient_id, p.prenom AS p_prenom, p.nom AS p_nom,
               p.groupe_sanguin, p.identifiant_blockchain AS p_chain,
               dm.motif_visite
        FROM prescriptions pr
        JOIN dossiers_medicaux dm ON dm.id = pr.dossier_medical_id
        JOIN patients p ON p.id = dm.patient_id
        WHERE dm.modifie_par_docteur = ?
        ORDER BY pr.date_prescription DESC
        LIMIT 100
    ");
    $stmt->execute([$medecinId]);
    $prescriptions = $stmt->fetchAll();
} else {
    // patient : voir les siennes — incluant date de consultation + heures de prise
    $stmt = $pdo->prepare("
        SELECT pr.*,
               CONCAT(d.prenom,' ',d.nom) AS docteur_nom, d.specialite,
               dm.motif_visite,
               dm.date_creation AS consultation_date,
               dm.id AS consultation_id
        FROM prescriptions pr
        JOIN dossiers_medicaux dm ON dm.id = pr.dossier_medical_id
        LEFT JOIN docteurs d ON d.id = dm.modifie_par_docteur
        WHERE dm.patient_id = ?
        ORDER BY dm.date_creation DESC, pr.medicament ASC
    ");
    $stmt->execute([$patientId]);
    $prescriptions = $stmt->fetchAll();
}

// Pour la vue patient : regroupement par consultation
$prescriptionsByConsult = [];
if ($userType === 'patient') {
    foreach ($prescriptions as $pr) {
        $key = $pr['consultation_id'] ?? 0;
        if (!isset($prescriptionsByConsult[$key])) {
            $prescriptionsByConsult[$key] = [
                'date'      => $pr['consultation_date'] ?? $pr['date_prescription'],
                'motif'     => $pr['motif_visite'] ?? '—',
                'docteur'   => $pr['docteur_nom'] ?? '—',
                'specialite'=> $pr['specialite'] ?? '',
                'meds'      => [],
            ];
        }
        $prescriptionsByConsult[$key]['meds'][] = $pr;
    }
}

// Compteurs pour la stat-grid (alignée avec dashboard_patient / transferer_patient)
$nbTotal       = count($prescriptions);
$nbActives     = 0; // statut active ET dans la période
$nbAVenir      = 0; // date_debut > maintenant
$nbTerminees   = 0; // date_fin passée OU statut != active
$now = time();
foreach ($prescriptions as $pr) {
    $statut = $pr['statut'] ?? 'active';
    $tsDebut = !empty($pr['date_debut']) ? strtotime($pr['date_debut']) : 0;
    $tsFin   = !empty($pr['date_fin']) ? strtotime($pr['date_fin']) : 0;
    if ($statut !== 'active' || ($tsFin && $now > $tsFin)) {
        $nbTerminees++;
    } elseif ($tsDebut && $now < $tsDebut) {
        $nbAVenir++;
    } else {
        $nbActives++;
    }
}

$pageTitle = 'Prescriptions';
$pageActive = $userType === 'patient' ? 'ordonnances' : 'prescriptions';
$breadcrumb = $userType === 'patient' ? ['Espace patient', 'Mes ordonnances'] : ['Soins', 'Prescriptions'];
require_once 'includes/header_dashboard.php';
?>

<!-- HERO (aligné sur dashboard_patient.php) -->
<section class="dash-hero reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span>
      <?= $userType === 'patient' ? 'Vos ordonnances' : 'Ordonnances émises' ?>
    </div>
    <h1><?= $userType === 'patient' ? 'Vos <span>ordonnances numériques.</span>' : 'Ordonnances <span>prescrites.</span>' ?></h1>
    <p><?= $userType === 'patient'
          ? 'Toutes les prescriptions signées par vos docteurs, disponibles en pharmacie.'
          : 'Toutes les ordonnances que vous avez signées. Chaque médicament prescrit est scellé.' ?></p>

    <div class="dash-hero-actions">
      <?php if (in_array($userType, ['docteur','medecin'])): ?>
        <a href="mes_patients.php" class="btn btn-white"><i class="fas fa-prescription"></i> Nouvelle prescription</a>
        <a href="#liste-presc" class="btn btn-ghost-w"><i class="fas fa-list"></i> Voir la liste</a>
      <?php else: ?>
        <a href="#liste-presc" class="btn btn-white"><i class="fas fa-list"></i> Voir mes ordonnances</a>
        <a href="dashboard_patient.php" class="btn btn-ghost-w"><i class="fas fa-arrow-left"></i> Espace patient</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Total signées</span>
      <i class="fas fa-cube"></i>
    </div>
    <div class="dash-hero-card-hash" style="font-size:32px"><?= $nbTotal ?></div>
    <div class="dash-hero-card-meta">
      <span><i class="fas fa-check-circle"></i> Toutes signées</span>
      <span>·</span>
      <span>Chaîne active</span>
    </div>
  </div>
</section>

<!-- STAT GRID (alignée avec dashboard_patient.php) -->
<section class="stat-grid">
  <div class="stat-card tilt reveal-up">
    <div class="stat-card-icon emerald"><i class="fas fa-prescription"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbTotal ?>">0</div>
      <div class="stat-card-label">Total ordonnances</div>
    </div>
  </div>
  <div class="stat-card tilt reveal-up" style="animation-delay:.05s">
    <div class="stat-card-icon forest"><i class="fas fa-pills"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbActives ?>">0</div>
      <div class="stat-card-label">Actives</div>
    </div>
  </div>
  <div class="stat-card tilt reveal-up" style="animation-delay:.1s">
    <div class="stat-card-icon trust"><i class="fas fa-hourglass-half"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbAVenir ?>">0</div>
      <div class="stat-card-label">À venir</div>
    </div>
  </div>
  <div class="stat-card tilt reveal-up" style="animation-delay:.15s">
    <div class="stat-card-icon blockchain"><i class="fas fa-flag-checkered"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbTerminees ?>">0</div>
      <div class="stat-card-label">Terminées</div>
    </div>
  </div>
</section>

<!-- LISTE -->
<section class="dash-card reveal-up" id="liste-presc">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-prescription" style="color:var(--emerald)"></i> Prescriptions</h3>
      <p>Triées par date, les plus récentes en premier</p>
    </div>
    <span class="pill-count"><?= count($prescriptions) ?></span>
  </div>

  <?php if (empty($prescriptions)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-prescription-bottle"></i></div>
      <h4>Aucune prescription</h4>
      <p><?= $userType === 'patient' ? 'Vos ordonnances apparaîtront ici.' : 'Commencez par prescrire depuis un dossier patient.' ?></p>
    </div>
  <?php elseif ($userType === 'patient'): ?>
    <!-- ── Demande permission notifications ── -->
    <div id="notif-banner" class="notif-banner" style="display:none">
      <div><i class="fas fa-bell"></i> <strong>Activer les rappels</strong><br><small>Recevez une notification à chaque heure de prise de vos médicaments.</small></div>
      <button type="button" id="notif-enable" class="btn btn-primary"><i class="fas fa-bell"></i> Activer</button>
    </div>

    <!-- ── Vue patient : groupée par consultation ── -->
    <?php foreach ($prescriptionsByConsult as $cid => $bloc):
      $cdate = strtotime($bloc['date']);
    ?>
      <div class="consult-bloc">
        <header class="consult-bloc-head">
          <div class="consult-bloc-date">
            <div class="consult-bloc-icon"><i class="fas fa-calendar-check"></i></div>
            <div>
              <strong>Consultation du <?= date('d/m/Y', $cdate) ?></strong>
              <span><?= date('H\hi', $cdate) ?> · Dr. <?= htmlspecialchars($bloc['docteur']) ?>
                <?php if ($bloc['specialite']): ?>· <?= htmlspecialchars($bloc['specialite']) ?><?php endif; ?>
              </span>
            </div>
          </div>
          <span class="badge badge-info"><?= count($bloc['meds']) ?> médicament<?= count($bloc['meds']) > 1 ? 's' : '' ?></span>
        </header>

        <?php if (!empty($bloc['motif'])): ?>
          <p class="consult-bloc-motif"><i class="fas fa-comment-medical"></i> <?= htmlspecialchars($bloc['motif']) ?></p>
        <?php endif; ?>

        <div class="med-cards">
          <?php foreach ($bloc['meds'] as $pr):
            $statut = $pr['statut'] ?? 'active';
            $heures = $pr['heures_prise'] ? json_decode($pr['heures_prise'], true) : [];
            $debut  = $pr['date_debut'] ?? $pr['date_prescription'];
            $fin    = $pr['date_fin']   ?? '';
            $tsDebut = $debut ? strtotime($debut) : 0;
            $tsFin   = $fin ? strtotime($fin) : 0;
            $totalJours = ($tsFin && $tsDebut) ? max(1, (int)round(($tsFin - $tsDebut) / 86400) + 1) : null;
            $now = time();
            $joursRestants = $tsFin ? max(0, (int)ceil(($tsFin - $now) / 86400)) : null;
            $isFini = ($tsFin && $now > $tsFin);
            $isPasCommence = ($tsDebut && $now < $tsDebut);
          ?>
            <article class="med-card"
              data-med="<?= htmlspecialchars($pr['medicament']) ?>"
              data-dosage="<?= htmlspecialchars($pr['dosage'] ?? '') ?>"
              data-debut="<?= htmlspecialchars($debut) ?>"
              data-fin="<?= htmlspecialchars($fin) ?>"
              data-heures='<?= htmlspecialchars(json_encode($heures), ENT_QUOTES, 'UTF-8') ?>'>
              <header class="med-card-head">
                <div class="med-card-icon"><i class="fas fa-pills"></i></div>
                <div class="med-card-title">
                  <h4><?= htmlspecialchars($pr['medicament']) ?></h4>
                  <?php if (!empty($pr['dosage'])): ?>
                    <span><?= htmlspecialchars($pr['dosage']) ?><?= !empty($pr['frequence']) ? ' · ' . htmlspecialchars($pr['frequence']) : '' ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($isFini): ?>
                  <span class="badge badge-neutral">Terminé</span>
                <?php elseif ($isPasCommence): ?>
                  <span class="badge badge-warn">À venir</span>
                <?php else: ?>
                  <span class="badge badge-success">En cours</span>
                <?php endif; ?>
              </header>

              <div class="med-period">
                <div><span>Début</span><strong><?= $tsDebut ? date('d/m/Y', $tsDebut) : '—' ?></strong></div>
                <div><span>Fin</span><strong><?= $tsFin ? date('d/m/Y', $tsFin) : '—' ?></strong></div>
                <?php if ($totalJours !== null): ?>
                  <div><span>Durée</span><strong><?= $totalJours ?> jour<?= $totalJours > 1 ? 's' : '' ?></strong></div>
                <?php endif; ?>
              </div>

              <?php if ($joursRestants !== null && !$isFini): ?>
                <div class="med-countdown <?= $joursRestants <= 2 ? 'urgent' : '' ?>">
                  <i class="fas fa-hourglass-half"></i>
                  <strong><?= $joursRestants ?></strong> jour<?= $joursRestants > 1 ? 's' : '' ?> restant<?= $joursRestants > 1 ? 's' : '' ?>
                  <span class="med-countdown-bar">
                    <span class="bar-fill" style="width: <?= $totalJours ? min(100, max(0, 100 - ($joursRestants / $totalJours * 100))) : 0 ?>%"></span>
                  </span>
                </div>
              <?php endif; ?>

              <?php if (!empty($heures)): ?>
                <div class="med-heures">
                  <span class="med-heures-label"><i class="fas fa-clock"></i> Heures de prise</span>
                  <div class="med-heures-list">
                    <?php foreach ($heures as $h): ?>
                      <span class="hour-pill" data-hour="<?= htmlspecialchars($h) ?>"><?= htmlspecialchars($h) ?></span>
                    <?php endforeach; ?>
                  </div>
                  <div class="med-next-take">
                    <i class="fas fa-bell"></i> <span class="med-next-label">Prochaine prise : —</span>
                  </div>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <!-- ── Vue docteur (inchangée structurellement) ── -->
    <div class="presc-grid">
      <?php foreach ($prescriptions as $pr):
        $date = strtotime($pr['date_prescription'] ?? 'now');
        $statut = $pr['statut'] ?? 'active';
        $statutCls = match($statut) {
          'active' => 'emerald',
          'termine','completed' => 'muted',
          'annule','cancelled'  => 'danger',
          default => 'emerald',
        };
        $heures = !empty($pr['heures_prise']) ? json_decode($pr['heures_prise'], true) : [];
      ?>
        <div class="presc-card">
          <div class="presc-top">
            <div class="presc-icon"><i class="fas fa-pills"></i></div>
            <div class="presc-title">
              <h4><?= htmlspecialchars($pr['medicament']) ?></h4>
              <span class="presc-subtitle">
                <i class="fas fa-user"></i>
                <?= htmlspecialchars(($pr['p_prenom'] ?? '') . ' ' . ($pr['p_nom'] ?? '')) ?>
              </span>
            </div>
            <span class="presc-badge presc-<?= $statutCls ?>"><?= ucfirst($statut) ?></span>
          </div>

          <div class="presc-meta">
            <?php if (!empty($pr['dosage'])): ?>
              <div><span>Dosage</span><strong><?= htmlspecialchars($pr['dosage']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($pr['frequence'])): ?>
              <div><span>Fréquence</span><strong><?= htmlspecialchars($pr['frequence']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($pr['date_debut'])): ?>
              <div><span>Début</span><strong><?= date('d/m/Y', strtotime($pr['date_debut'])) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($pr['date_fin'])): ?>
              <div><span>Fin</span><strong><?= date('d/m/Y', strtotime($pr['date_fin'])) ?></strong></div>
            <?php endif; ?>
          </div>

          <?php if (!empty($heures)): ?>
            <div class="presc-hours">
              <i class="fas fa-clock"></i>
              <?php foreach ($heures as $h): ?>
                <span><?= htmlspecialchars($h) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="presc-footer">
            <span class="presc-date"><i class="fas fa-calendar"></i> <?= date('d/m/Y', $date) ?></span>
            <span class="presc-signed"><i class="fas fa-shield-halved"></i> Signée</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<style>
  /* ── Hero aligné sur dashboard_patient.php ── */
  .dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, var(--forest, #1a472a) 0%, #0d9488 55%, #0ea5e9 100%);
    color: white; border-radius: 24px;
    padding: 40px; margin-bottom: 24px;
    display: grid; grid-template-columns: 1.4fr 1fr;
    gap: 32px; align-items: center;
  }
  .dash-hero::before {
    content: ''; position: absolute; top: -150px; right: -150px;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(16,185,129,.35), transparent 60%);
    filter: blur(80px);
  }
  .dash-hero::after {
    content: ''; position: absolute; bottom: -100px; left: -100px;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(56,189,248,.25), transparent 60%);
    filter: blur(60px);
  }
  .dash-hero-content { position: relative; z-index: 1; }
  .dash-hero-greet {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 12px; background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.15); border-radius: 999px;
    font-size: 12px; font-weight: 600; margin-bottom: 16px;
  }
  .dash-hero-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #10b981; box-shadow: 0 0 12px #10b981;
    animation: pulse 2s infinite;
  }
  .dash-hero h1 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 36px; font-weight: 800; line-height: 1.15; letter-spacing: -.02em; }
  .dash-hero h1 span { display: block; background: linear-gradient(135deg, #34d399, #7dd3fc); -webkit-background-clip: text; background-clip: text; color: transparent; }
  .dash-hero p { font-size: 15px; color: rgba(255,255,255,.78); margin-top: 12px; line-height: 1.55; }
  .dash-hero-actions { display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap; }

  .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: .3s; font-family: inherit; }
  .btn-white { background: white; color: var(--forest); border: none; }
  .btn-white:hover { background: #f1f5f9; transform: translateY(-2px); box-shadow: 0 10px 24px -8px rgba(255,255,255,.3); }
  .btn-ghost-w { background: rgba(255,255,255,.1); color: white; border: 1px solid rgba(255,255,255,.2); }
  .btn-ghost-w:hover { background: rgba(255,255,255,.18); transform: translateY(-2px); }
  .btn-primary { background: var(--g-emerald); color: white; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -8px rgba(16,185,129,.4); }

  .dash-hero-card {
    position: relative; z-index: 1;
    padding: 24px; background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(16px);
    border-radius: 18px;
  }
  .dash-hero-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
  .dash-hero-card-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: rgba(255,255,255,.6); }
  .dash-hero-card-head i { color: #34d399; font-size: 18px; }
  .dash-hero-card-hash {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 800; color: #34d399;
    padding: 12px 14px; background: rgba(16,185,129,.1);
    border: 1px solid rgba(16,185,129,.25); border-radius: 10px;
    text-align: center;
  }
  .dash-hero-card-meta { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 12px; color: rgba(255,255,255,.6); }
  .dash-hero-card-meta i { color: #34d399; }

  /* ── Stat grid (identique au dashboard_patient) ── */
  .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
  .stat-card { display: flex; align-items: center; gap: 16px; padding: 20px; background: white; border: 1px solid var(--line); border-radius: 16px; transition: .3s; position: relative; overflow: hidden; }
  .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--g-emerald); transform: scaleX(0); transform-origin: left; transition: transform .4s; }
  .stat-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px -16px rgba(15,23,42,.15); border-color: rgba(16,185,129,.2); }
  .stat-card:hover::before { transform: scaleX(1); }
  .stat-card-icon { width: 48px; height: 48px; border-radius: 14px; display: grid; place-items: center; color: white; font-size: 20px; flex-shrink: 0; }
  .stat-card-icon.forest    { background: var(--g-forest); }
  .stat-card-icon.emerald   { background: var(--g-emerald); }
  .stat-card-icon.trust     { background: var(--g-trust); }
  .stat-card-icon.blockchain{ background: var(--g-blockchain); }
  .stat-card-body { flex: 1; }
  .stat-card-value { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 28px; font-weight: 800; color: var(--ink); line-height: 1; }
  .stat-card-label { font-size: 13px; color: var(--muted); margin-top: 4px; }

  @keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .6; transform: scale(1.2); } }
  @media (max-width: 1200px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 960px) { .dash-hero { grid-template-columns: 1fr; padding: 32px 24px; } .dash-hero h1 { font-size: 28px; } }
  @media (max-width: 560px) { .stat-grid { grid-template-columns: 1fr; } }

  .presc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
  .presc-card { background: white; border: 1px solid var(--line); border-radius: 16px; padding: 20px; transition: .25s; display: flex; flex-direction: column; gap: 12px; }
  .presc-card:hover { border-color: rgba(16,185,129,.3); box-shadow: 0 12px 28px -14px rgba(15,23,42,.12); transform: translateY(-2px); }
  .presc-top { display: flex; align-items: flex-start; gap: 12px; }
  .presc-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--g-emerald); color: white; display: grid; place-items: center; font-size: 18px; flex-shrink: 0; box-shadow: 0 8px 18px -6px rgba(16,185,129,.5); }
  .presc-title { flex: 1; min-width: 0; }
  .presc-title h4 { font-size: 15px; font-weight: 800; color: var(--ink); margin: 0 0 4px; }
  .presc-subtitle { font-size: 12px; color: var(--muted); display: flex; align-items: center; gap: 6px; }

  .presc-badge { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; padding: 4px 8px; border-radius: 6px; flex-shrink: 0; }
  .presc-emerald { background: rgba(16,185,129,.12); color: var(--forest); }
  .presc-muted   { background: rgba(100,116,139,.12); color: var(--muted); }
  .presc-danger  { background: rgba(239,68,68,.12); color: #dc2626; }

  .presc-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 10px; padding: 12px; background: #f8fafc; border-radius: 10px; }
  .presc-meta > div { display: flex; flex-direction: column; gap: 2px; }
  .presc-meta span { font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: .04em; }
  .presc-meta strong { font-size: 13px; font-weight: 700; color: var(--ink); }

  .presc-instructions { font-size: 12px; color: var(--muted); line-height: 1.5; padding: 10px 12px; background: rgba(245,158,11,.06); border-left: 3px solid #f59e0b; border-radius: 6px; display: flex; gap: 8px; align-items: flex-start; }
  .presc-instructions i { margin-top: 2px; color: #d97706; }

  .presc-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px dashed var(--line); font-size: 11px; color: var(--muted); }
  .presc-signed { color: var(--forest); font-weight: 700; font-family: 'JetBrains Mono', monospace; display: inline-flex; align-items: center; gap: 6px; }

  /* Présentation patient : groupe par consultation + compte à rebours */
  .notif-banner {
    display: flex; gap: 14px; align-items: center; justify-content: space-between;
    padding: 14px 18px; margin-bottom: 18px;
    background: linear-gradient(135deg, rgba(245,158,11,.1), rgba(245,158,11,.04));
    border: 1px solid rgba(245,158,11,.3); border-radius: 14px;
    color: #92400e;
  }
  .notif-banner i { color: #d97706; margin-right: 6px; }
  .notif-banner small { color: #92400e; opacity: .8; }
  .notif-banner .btn { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }

  .consult-bloc {
    background: white;
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 18px;
  }
  .consult-bloc-head {
    display: flex; justify-content: space-between; align-items: center;
    gap: 14px;
    padding-bottom: 16px;
    border-bottom: 1px dashed var(--line);
    margin-bottom: 16px;
  }
  .consult-bloc-date { display: flex; align-items: center; gap: 12px; }
  .consult-bloc-icon {
    width: 42px; height: 42px; border-radius: 12px;
    background: var(--g-emerald); color: white;
    display: grid; place-items: center; font-size: 18px;
    box-shadow: 0 8px 18px -6px rgba(16,185,129,.5);
  }
  .consult-bloc-date strong { display: block; font-size: 15px; font-weight: 700; color: var(--ink); }
  .consult-bloc-date span { display: block; font-size: 12px; color: var(--muted); }
  .consult-bloc-motif {
    font-size: 13px; color: var(--muted);
    padding: 10px 14px; background: rgba(99,102,241,.05);
    border-left: 3px solid var(--blockchain);
    border-radius: 8px; margin-bottom: 14px;
    font-style: italic;
  }
  .consult-bloc-motif i { color: var(--blockchain); margin-right: 6px; }

  .med-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 14px;
  }
  .med-card {
    background: #f8fafc;
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 16px;
    display: flex; flex-direction: column; gap: 12px;
    transition: .2s;
  }
  .med-card:hover { background: white; box-shadow: 0 12px 28px -14px rgba(15,23,42,.12); }
  .med-card-head { display: flex; align-items: flex-start; gap: 12px; }
  .med-card-icon {
    width: 40px; height: 40px; border-radius: 11px;
    background: var(--g-emerald); color: white;
    display: grid; place-items: center; font-size: 16px;
    flex-shrink: 0;
  }
  .med-card-title { flex: 1; min-width: 0; }
  .med-card-title h4 { font-size: 15px; font-weight: 800; color: var(--ink); margin-bottom: 2px; }
  .med-card-title span { font-size: 12px; color: var(--muted); }

  .med-period {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    padding: 10px;
    background: white; border: 1px solid var(--line);
    border-radius: 10px;
  }
  .med-period > div { display: flex; flex-direction: column; gap: 2px; text-align: center; }
  .med-period span { font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: .04em; }
  .med-period strong { font-size: 12px; font-weight: 700; color: var(--ink); }

  .med-countdown {
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
    padding: 10px 12px;
    background: linear-gradient(135deg, rgba(16,185,129,.08), rgba(16,185,129,.02));
    border: 1px solid rgba(16,185,129,.25);
    border-radius: 10px;
    font-size: 13px; color: var(--ink);
  }
  .med-countdown.urgent {
    background: linear-gradient(135deg, rgba(239,68,68,.08), rgba(239,68,68,.02));
    border-color: rgba(239,68,68,.3); color: #991b1b;
  }
  .med-countdown i { color: var(--emerald); }
  .med-countdown.urgent i { color: #dc2626; }
  .med-countdown strong { font-size: 16px; font-weight: 800; font-family: 'Plus Jakarta Sans', sans-serif; }
  .med-countdown-bar {
    width: 100%; height: 6px; background: rgba(15,23,42,.08); border-radius: 999px; overflow: hidden;
    margin-top: 4px;
  }
  .bar-fill {
    display: block; height: 100%;
    background: linear-gradient(90deg, #10b981, #34d399);
    border-radius: 999px;
    transition: width .4s;
  }
  .med-countdown.urgent .bar-fill { background: linear-gradient(90deg, #ef4444, #f87171); }

  .med-heures {
    padding: 10px 12px; background: white;
    border: 1px solid var(--line); border-radius: 10px;
  }
  .med-heures-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted); margin-bottom: 8px;
  }
  .med-heures-label i { color: var(--emerald); }
  .med-heures-list { display: flex; flex-wrap: wrap; gap: 6px; }
  .hour-pill {
    padding: 4px 10px;
    background: rgba(16,185,129,.1);
    color: var(--forest);
    border-radius: 999px;
    font-size: 12px; font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
  }
  .hour-pill.next {
    background: linear-gradient(135deg, #f59e0b, #d97706); color: white;
    box-shadow: 0 4px 12px -4px rgba(245,158,11,.5);
    animation: pulseHour 1.6s ease-in-out infinite;
  }
  @keyframes pulseHour { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.08); } }

  .med-next-take {
    margin-top: 8px; padding-top: 8px;
    border-top: 1px dashed var(--line);
    font-size: 12px; color: var(--muted);
  }
  .med-next-take i { color: #f59e0b; margin-right: 4px; }

  .presc-hours {
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
    padding: 8px 12px;
    background: rgba(16,185,129,.06);
    border-radius: 8px;
    font-size: 12px;
  }
  .presc-hours i { color: var(--emerald); margin-right: 4px; }
  .presc-hours span {
    padding: 3px 8px; background: white;
    border-radius: 999px;
    font-family: 'JetBrains Mono', monospace;
    font-weight: 700; color: var(--forest);
  }

  .badge {
    display: inline-block; padding: 4px 10px;
    border-radius: 999px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
  }
  .badge-success { background: #d1fae5; color: #065f46; }
  .badge-info    { background: #dbeafe; color: #1e40af; }
  .badge-warn    { background: #fef3c7; color: #92400e; }
  .badge-neutral { background: #f1f5f9; color: #475569; }

  @media (max-width:720px) { .med-cards { grid-template-columns: 1fr; } .consult-bloc-head { flex-direction: column; align-items: flex-start; } }
</style>

<?php if ($userType === 'patient' && !empty($prescriptions)): ?>
<script>
(function() {
  // ─── Notifications navigateur pour rappels de prise ───
  const banner = document.getElementById('notif-banner');
  const btn = document.getElementById('notif-enable');

  function updateBannerVisibility() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'default') {
      banner.style.display = 'flex';
    } else {
      banner.style.display = 'none';
    }
  }

  if ('Notification' in window) {
    updateBannerVisibility();
    if (btn) {
      btn.addEventListener('click', async () => {
        const perm = await Notification.requestPermission();
        updateBannerVisibility();
        if (perm === 'granted') {
          new Notification('MedChain — Rappels activés', {
            body: 'Vous recevrez une alerte à chaque heure de prise prévue.',
            icon: '/systeme_medical/images/cnhu.jpeg'
          });
        }
      });
    }
  }

  // ─── Calcul prochaine prise + compte à rebours par médicament ───
  function pad(n) { return String(n).padStart(2, '0'); }

  function getMedSchedule(card) {
    let heures = [];
    try { heures = JSON.parse(card.dataset.heures || '[]') || []; } catch (e) { heures = []; }
    return {
      medicament: card.dataset.med || '',
      dosage:     card.dataset.dosage || '',
      debut:      card.dataset.debut || '',
      fin:        card.dataset.fin || '',
      heures:     heures,
    };
  }

  function isWithinPeriod(schedule, now) {
    if (schedule.debut) {
      const d = new Date(schedule.debut + 'T00:00:00');
      if (now < d) return false;
    }
    if (schedule.fin) {
      const f = new Date(schedule.fin + 'T23:59:59');
      if (now > f) return false;
    }
    return true;
  }

  function computeNext(schedule, now) {
    if (!schedule.heures.length) return null;
    const todayStr = now.toISOString().slice(0,10);
    const sorted = [...schedule.heures].sort();
    for (const h of sorted) {
      const t = new Date(todayStr + 'T' + h + ':00');
      if (t > now && isWithinPeriod(schedule, t)) return t;
    }
    // demain
    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowStr = tomorrow.toISOString().slice(0,10);
    const next = new Date(tomorrowStr + 'T' + sorted[0] + ':00');
    return isWithinPeriod(schedule, next) ? next : null;
  }

  function formatRel(date, now) {
    const diff = date - now;
    const m = Math.round(diff / 60000);
    if (m < 1) return 'maintenant';
    if (m < 60) return 'dans ' + m + ' min';
    const h = Math.floor(m / 60);
    const min = m % 60;
    if (h < 24) return 'dans ' + h + 'h' + (min ? ' ' + min + 'min' : '');
    const d = Math.floor(h / 24);
    return 'dans ' + d + ' jour' + (d > 1 ? 's' : '');
  }

  // État pour éviter les notifications dupliquées
  const fired = new Set();
  const FIRE_WINDOW_MS = 60_000; // marge ±60s autour de l'heure

  function tick() {
    const now = new Date();
    document.querySelectorAll('.med-card').forEach(card => {
      const sched = getMedSchedule(card);
      if (!isWithinPeriod(sched, now)) return;

      // 1) Highlight pill de la prochaine heure
      const next = computeNext(sched, now);
      const label = card.querySelector('.med-next-label');
      if (label) {
        label.textContent = next
          ? 'Prochaine prise : ' + pad(next.getHours()) + ':' + pad(next.getMinutes()) + ' (' + formatRel(next, now) + ')'
          : 'Aucune prise programmée';
      }
      card.querySelectorAll('.hour-pill').forEach(p => p.classList.remove('next'));
      if (next) {
        const nextH = pad(next.getHours()) + ':' + pad(next.getMinutes());
        const matching = card.querySelector('.hour-pill[data-hour="' + nextH + '"]');
        if (matching) matching.classList.add('next');
      }

      // 2) Déclencher notification si on est à l'heure pile (± fenêtre)
      sched.heures.forEach(h => {
        const [hh, mm] = h.split(':').map(Number);
        const target = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hh, mm, 0);
        const diff = Math.abs(target - now);
        if (diff < FIRE_WINDOW_MS) {
          const key = sched.medicament + '|' + now.toDateString() + '|' + h;
          if (!fired.has(key)) {
            fired.add(key);
            if ('Notification' in window && Notification.permission === 'granted') {
              try {
                new Notification('💊 ' + sched.medicament, {
                  body: 'C\'est l\'heure de votre prise (' + h + ')' + (sched.dosage ? ' — ' + sched.dosage : ''),
                  tag: key,
                  requireInteraction: false,
                  icon: '/systeme_medical/images/cnhu.jpeg'
                });
              } catch (e) { /* silent */ }
            }
            if (window.toast) window.toast('💊 ' + sched.medicament + ' — c\'est l\'heure (' + h + ')', 'warning', 6000);
          }
        }
      });
    });
  }

  // Tick immédiat puis toutes les 30s
  tick();
  setInterval(tick, 30_000);
})();
</script>
<?php endif; ?>

<?php require_once 'includes/footer_dashboard.php'; ?>
