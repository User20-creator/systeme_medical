<?php
// consultation.php — Nouvelle consultation (médecin/docteur)
require_once 'config.php';
require_once 'includes/hash_chain.php';

if (!isset($_SESSION['medecin_id']) || !in_array($_SESSION['user_type'] ?? '', ['docteur','medecin'])) {
    header('Location: connexion2.php'); exit;
}

$medecinId = (int)$_SESSION['medecin_id'];
$erreur = '';
$ok     = null;

// Mode : liste ou nouvelle consultation
$mode = $_GET['mode'] ?? (isset($_GET['patient']) || isset($_GET['rdv']) ? 'new' : 'list');

// ── MODE : Liste des consultations ─────────────────────────────
if ($mode === 'list') {
    $stmt = $pdo->prepare("
        SELECT dm.*,
               p.nom AS p_nom, p.prenom AS p_prenom, p.identifiant_blockchain AS p_chain, p.groupe_sanguin,
               COUNT(DISTINCT pr.id) AS nb_prescriptions
        FROM dossiers_medicaux dm
        JOIN patients p ON p.id = dm.patient_id
        LEFT JOIN prescriptions pr ON pr.dossier_medical_id = dm.id
        WHERE dm.modifie_par_docteur = ?
        GROUP BY dm.id
        ORDER BY dm.date_creation DESC
        LIMIT 100
    ");
    $stmt->execute([$medecinId]);
    $consultations = $stmt->fetchAll();

    $pageTitle = 'Consultations';
    $pageActive = 'consultation';
    $breadcrumb = ['Activité', 'Consultations'];
    require_once 'includes/header_dashboard.php';
    ?>

    <!-- HERO -->
    <section class="dash-hero reveal-up">
      <div class="dash-hero-content">
        <div class="dash-hero-greet">
          <span class="dash-hero-dot"></span>
          Journal des consultations
        </div>
        <h1>Vos <span>consultations récentes.</span></h1>
        <p>Historique complet de toutes vos consultations. Chaque entrée est signée et scellée dans le registre.</p>

        <div class="dash-hero-actions">
          <a href="mes_patients.php" class="btn btn-white">
            <i class="fas fa-user-plus"></i> Démarrer une consultation
          </a>
          <a href="mes_rendezvous.php" class="btn btn-ghost-w">
            <i class="fas fa-calendar"></i> Depuis un rendez-vous
          </a>
        </div>
      </div>

      <div class="dash-hero-card">
        <div class="dash-hero-card-head">
          <span class="dash-hero-card-label">Total consultations</span>
          <i class="fas fa-stethoscope"></i>
        </div>
        <div class="dash-hero-card-hash"><?= count($consultations) ?></div>
        <div class="dash-hero-card-meta">
          <span><i class="fas fa-check-circle"></i> Toutes certifiées</span>
        </div>
      </div>
    </section>

    <!-- LISTE -->
    <section class="dash-card reveal-up">
      <div class="dash-card-head">
        <div>
          <h3><i class="fas fa-clipboard-list" style="color:var(--forest)"></i> Historique</h3>
          <p>Triées par date, les plus récentes en premier</p>
        </div>
        <span class="pill-count"><?= count($consultations) ?></span>
      </div>

      <?php if (empty($consultations)): ?>
        <div class="empty">
          <div class="empty-icon"><i class="fas fa-stethoscope"></i></div>
          <h4>Aucune consultation enregistrée</h4>
          <p>Démarrez une consultation depuis la liste de vos patients ou un rendez-vous.</p>
          <a href="mes_patients.php" class="btn btn-primary" style="margin-top:14px;">
            <i class="fas fa-user-plus"></i> Voir mes patients
          </a>
        </div>
      <?php else: ?>
        <div class="consult-timeline">
          <?php foreach ($consultations as $c):
            $date = strtotime($c['date_creation']);
          ?>
            <div class="consult-row">
              <div class="consult-date">
                <strong><?= date('d', $date) ?></strong>
                <span><?= strtoupper(strftime('%b', $date)) ?></span>
                <small><?= date('H:i', $date) ?></small>
              </div>
              <div class="consult-body">
                <div class="consult-top">
                  <div class="consult-patient">
                    <div class="avatar-sm forest">
                      <?= strtoupper(substr($c['p_prenom'],0,1) . substr($c['p_nom'],0,1)) ?>
                    </div>
                    <div>
                      <strong><?= htmlspecialchars($c['p_prenom'] . ' ' . $c['p_nom']) ?></strong>
                      <?php if ($c['groupe_sanguin']): ?><span class="blood-chip"><?= htmlspecialchars($c['groupe_sanguin']) ?></span><?php endif; ?>
                    </div>
                  </div>
                  <span class="badge badge-success"><?= htmlspecialchars($c['statut'] ?? 'actif') ?></span>
                </div>

                <?php if (!empty($c['motif_visite'])): ?>
                  <p class="consult-motif"><i class="fas fa-comment-medical"></i> <?= htmlspecialchars($c['motif_visite']) ?></p>
                <?php endif; ?>
                <?php if (!empty($c['diagnostic'])): ?>
                  <p class="consult-diag"><i class="fas fa-notes-medical"></i> <strong>Diagnostic :</strong> <?= htmlspecialchars($c['diagnostic']) ?></p>
                <?php endif; ?>

                <div class="consult-footer">
                  <span><i class="fas fa-cube"></i> <?= $c['nb_prescriptions'] ?> prescription(s)</span>
                  <a href="dossier_patient.php?id=<?= $c['patient_id'] ?>" class="consult-link">
                    <i class="fas fa-eye"></i> Voir le dossier
                  </a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <style>
      .dash-hero-card-hash { font-size: 38px; font-weight: 800; font-family: 'Plus Jakarta Sans', sans-serif; }
      .consult-timeline { display: flex; flex-direction: column; gap: 12px; }
      .consult-row { display: grid; grid-template-columns: 80px 1fr; gap: 16px; padding: 16px; background: #f8fafc; border: 1px solid var(--line); border-radius: 14px; transition: .25s; }
      .consult-row:hover { background: white; border-color: rgba(26,71,42,.15); box-shadow: 0 6px 16px -8px rgba(15,23,42,.1); }
      .consult-date { text-align: center; padding: 10px 6px; background: white; border-radius: 10px; border: 1px solid var(--line); }
      .consult-date strong { display: block; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 22px; font-weight: 800; color: var(--forest); line-height: 1; }
      .consult-date span { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); display: block; margin-top: 3px; }
      .consult-date small { font-size: 10px; font-weight: 600; color: var(--muted); display: block; margin-top: 4px; }
      .consult-body { display: flex; flex-direction: column; gap: 8px; }
      .consult-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
      .consult-patient { display: flex; align-items: center; gap: 10px; }
      .consult-patient strong { font-size: 14px; color: var(--ink); }
      .blood-chip { display: inline-block; font-size: 10px; font-weight: 700; background: rgba(239,68,68,.1); color: #dc2626; padding: 2px 8px; border-radius: 6px; margin-left: 6px; }
      .consult-motif, .consult-diag { font-size: 13px; color: var(--muted); margin: 0; display: flex; gap: 8px; align-items: flex-start; line-height: 1.5; }
      .consult-motif i, .consult-diag i { color: var(--forest); margin-top: 3px; }
      .consult-diag strong { color: var(--ink); }
      .consult-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 8px; border-top: 1px dashed var(--line); font-size: 12px; color: var(--muted); }
      .consult-link { color: var(--forest); font-weight: 700; text-decoration: none; transition: .2s; display: inline-flex; align-items: center; gap: 6px; }
      .consult-link:hover { color: var(--emerald); }
      .avatar-sm { width: 40px; height: 40px; border-radius: 10px; color: white; display: grid; place-items: center; font-weight: 700; font-size: 13px; font-family: 'Plus Jakarta Sans', sans-serif; }
      .avatar-sm.forest { background: var(--g-forest); }
    </style>

    <?php
    require_once 'includes/footer_dashboard.php';
    exit;
}

// ── MODE : Nouvelle consultation ───────────────────────────────
// Note : la feature "rendez_vous" n'existe pas en BDD. On accepte
// uniquement ?patient=ID désormais. Le paramètre rdv= est ignoré.
$patientId = (int)($_GET['patient'] ?? $_POST['patient_id'] ?? 0);

if (!$patientId) {
    header('Location: mes_patients.php'); exit;
}

// Patient
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->execute([$patientId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$patient) { header('Location: mes_patients.php'); exit; }

// Hôpital par défaut (clé session correcte : medecin_hopital_principal_id)
$hopitalId = (int)($_SESSION['medecin_hopital_principal_id'] ?? $patient['hopital_reference'] ?? 0);

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_consult']) && !csrf_check()) {
    $erreur = "Jeton de sécurité invalide. Veuillez recharger la page.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_consult'])) {
    $motif     = trim($_POST['motif'] ?? '');
    $symptomes = trim($_POST['symptomes'] ?? '');
    $examen    = trim($_POST['examen'] ?? '');
    $diag      = trim($_POST['diagnostic'] ?? '');
    $observ    = trim($_POST['observations'] ?? '');
    $tension   = trim($_POST['tension'] ?? '');
    $temp      = trim($_POST['temperature'] ?? '');
    $poids     = trim($_POST['poids'] ?? '');
    $pouls     = trim($_POST['pouls'] ?? '');

    if (empty($motif)) {
        $erreur = "Le motif de la consultation est obligatoire.";
    } else {
        try {
            $pdo->beginTransaction();

            // Construire la description agrégée. Les champs symptomes/examen/
            // diagnostic/observations/pouls ne sont PAS des colonnes du schéma.
            // On les concatène dans `description` (TEXT).
            $descriptionParts = [];
            if ($symptomes) $descriptionParts[] = "Symptômes :\n" . $symptomes;
            if ($examen)    $descriptionParts[] = "Examen clinique :\n" . $examen;
            if ($diag)      $descriptionParts[] = "Diagnostic :\n" . $diag;
            if ($observ)    $descriptionParts[] = "Observations :\n" . $observ;
            if ($pouls)     $descriptionParts[] = "Pouls : $pouls bpm";
            $description = implode("\n\n", $descriptionParts);

            // Hash + transaction unique
            $contenuJson = json_encode([
                'patient_id' => $patientId,
                'medecin_id' => $medecinId,
                'motif'      => $motif,
                'description'=> $description,
                'tension'    => $tension,
                'temperature'=> $temp,
                'poids'      => $poids,
                'created_at' => date('c'),
            ], JSON_UNESCAPED_UNICODE);
            $hashContenu     = hash('sha256', $contenuJson);
            $transactionHash = '0x' . substr(hash('sha256', $hashContenu . random_bytes(16)), 0, 64);
            $signature       = HashChain::sign($contenuJson . $medecinId . time());
            $signatureStr    = is_string($signature) ? $signature : json_encode($signature);
            $titre           = mb_substr('Consultation — ' . $motif, 0, 200);

            // IMPORTANT : on laisse docteur_id à NULL parce que sa FK pointe (à tort)
            // vers infirmiers, pas vers docteurs. Le vrai pointeur docteur signataire
            // est modifie_par_docteur. Si on remplissait docteur_id avec un ID docteur,
            // l'INSERT échouerait avec FK constraint failure dès que cet ID
            // n'existe pas comme ID infirmier.
            $stmt = $pdo->prepare("
                INSERT INTO dossiers_medicaux
                    (transaction_hash, patient_id, docteur_id, hopital_id, type_document, titre,
                     description, date_creation, signature_medecin, hash_contenu,
                     confidentialite, tension, poids, temperature, motif_visite,
                     modifie_par_docteur)
                VALUES
                    (:tx, :pid, NULL, :hopital, 'consultation', :titre,
                     :description, NOW(), :signature, :hash,
                     'medecin', :tension, :poids, :temperature, :motif,
                     :docteur)
            ");
            $stmt->execute([
                ':tx'          => $transactionHash,
                ':pid'         => $patientId,
                ':hopital'     => $hopitalId ?: null,
                ':titre'       => $titre,
                ':description' => $description ?: null,
                ':signature'   => $signatureStr,
                ':hash'        => $hashContenu,
                ':tension'     => $tension ?: null,
                ':poids'       => $poids ?: null,
                ':temperature' => $temp ?: null,
                ':motif'       => $motif,
                ':docteur'     => $medecinId,
            ]);

            $dossierId = (int)$pdo->lastInsertId();

            HashChain::addBlock('CREATE_CONSULTATION', $dossierId, $medecinId, $_SESSION['user_type'], [
                'patient_id' => $patientId,
                'patient'    => $patient['prenom'] . ' ' . $patient['nom'],
                'motif'      => mb_substr($motif, 0, 80),
                'diagnostic' => mb_substr($diag, 0, 80),
            ], 'dossiers_medicaux');

            $pdo->commit();

            $ok = [
                'dossier_id' => $dossierId,
                'patient'    => $patient,
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('consultation: ' . $e->getMessage());
            $erreur = "Erreur lors de l'enregistrement de la consultation.";
        }
    }
}

$pageTitle = 'Nouvelle consultation';
$pageActive = 'consultation';
$breadcrumb = ['Activité', 'Consultations', 'Nouvelle'];
require_once 'includes/header_dashboard.php';
?>

<!-- HEADER PATIENT -->
<section class="dash-hero reveal-up" style="background:linear-gradient(135deg,#0e2a1a 0%,#1a472a 50%,#059669 100%)">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span>
      Consultation en cours · <?= date('d/m/Y à H:i') ?>
    </div>
    <h1>Consultation de <span><?= htmlspecialchars($patient['prenom'] . ' ' . $patient['nom']) ?></span></h1>
    <p>Toutes les observations saisies seront scellées dans le registre. Signez avant de valider.</p>
  </div>

  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Patient</span>
      <i class="fas fa-user-injured"></i>
    </div>
    <div class="dash-hero-card-hash" style="font-size:16px;"><?= htmlspecialchars($patient['prenom'] . ' ' . $patient['nom']) ?></div>
    <div class="dash-hero-card-meta">
      <?php if (!empty($patient['groupe_sanguin'])): ?><span><i class="fas fa-tint"></i> <?= htmlspecialchars($patient['groupe_sanguin']) ?></span><?php endif; ?>
      <?php if (!empty($patient['date_naissance'])): ?>
        <span>·</span>
        <span><?= (int)((time() - strtotime($patient['date_naissance']))/31536000) ?> ans</span>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($ok): ?>
  <div class="alert-box success reveal-up">
    <div class="ab-icon"><i class="fas fa-check-circle"></i></div>
    <div>
      <strong>Consultation enregistrée et scellée dans le registre.</strong>
      <p style="margin:4px 0 0;font-size:13px;">Dossier n°<?= $ok['dossier_id'] ?>. <?= $rdvId ? 'Le rendez-vous associé a été marqué comme terminé.' : '' ?></p>
    </div>
    <div class="alert-actions">
      <a href="prescriptions.php?patient=<?= $patient['id'] ?>&action=new&dossier=<?= $ok['dossier_id'] ?>" class="btn btn-primary">
        <i class="fas fa-prescription"></i> Prescrire maintenant
      </a>
      <a href="dossier_patient.php?id=<?= $patient['id'] ?>" class="btn btn-outline">
        <i class="fas fa-folder-open"></i> Dossier patient
      </a>
    </div>
  </div>
<?php endif; ?>

<?php if ($erreur): ?>
  <div class="alert-box error reveal-up">
    <i class="fas fa-exclamation-triangle"></i>
    <div><?= htmlspecialchars($erreur) ?></div>
  </div>
<?php endif; ?>

<?php if (!$ok): ?>
<section class="form-shell reveal-up">
  <form method="POST" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
    <?php if ($rdvId): ?><input type="hidden" name="rdv_id" value="<?= $rdvId ?>"><?php endif; ?>

    <div class="form-head forest">
      <div class="form-head-icon forest-icon"><i class="fas fa-stethoscope"></i></div>
      <div>
        <h2>Nouvelle consultation médicale</h2>
        <p>Le motif est obligatoire. Les autres champs sont recommandés pour un suivi optimal.</p>
      </div>
    </div>

    <div class="form-body">
      <div class="section-divider"><i class="fas fa-comment-medical"></i> Motif de consultation</div>
      <div class="form-group full">
        <label>Motif <span class="req">*</span></label>
        <div class="input-shell">
          <i class="fas fa-comment"></i>
          <input type="text" name="motif" required value="<?= htmlspecialchars($_POST['motif'] ?? ($rdvData['motif'] ?? '')) ?>" placeholder="Ex: Douleurs abdominales, contrôle annuel...">
        </div>
      </div>

      <div class="section-divider"><i class="fas fa-heart-pulse"></i> Constantes vitales</div>
      <div class="form-grid-4">
        <div class="form-group">
          <label>Tension</label>
          <div class="input-shell">
            <i class="fas fa-gauge"></i>
            <input type="text" name="tension" value="<?= htmlspecialchars($_POST['tension'] ?? '') ?>" placeholder="12/8">
          </div>
        </div>
        <div class="form-group">
          <label>Température</label>
          <div class="input-shell">
            <i class="fas fa-temperature-half"></i>
            <input type="text" name="temperature" value="<?= htmlspecialchars($_POST['temperature'] ?? '') ?>" placeholder="37°C">
          </div>
        </div>
        <div class="form-group">
          <label>Poids</label>
          <div class="input-shell">
            <i class="fas fa-weight-scale"></i>
            <input type="text" name="poids" value="<?= htmlspecialchars($_POST['poids'] ?? '') ?>" placeholder="70 kg">
          </div>
        </div>
        <div class="form-group">
          <label>Pouls</label>
          <div class="input-shell">
            <i class="fas fa-heart"></i>
            <input type="text" name="pouls" value="<?= htmlspecialchars($_POST['pouls'] ?? '') ?>" placeholder="72 bpm">
          </div>
        </div>
      </div>

      <div class="section-divider"><i class="fas fa-notes-medical"></i> Observation clinique</div>
      <div class="form-group full">
        <label>Symptômes rapportés</label>
        <textarea name="symptomes" rows="3" placeholder="Ce que le patient décrit..."><?= htmlspecialchars($_POST['symptomes'] ?? '') ?></textarea>
      </div>
      <div class="form-group full">
        <label>Examen clinique</label>
        <textarea name="examen" rows="3" placeholder="Auscultation, palpation, observations..."><?= htmlspecialchars($_POST['examen'] ?? '') ?></textarea>
      </div>

      <div class="section-divider"><i class="fas fa-diagnoses"></i> Conclusion</div>
      <div class="form-group full">
        <label>Diagnostic</label>
        <div class="input-shell">
          <i class="fas fa-stethoscope"></i>
          <input type="text" name="diagnostic" value="<?= htmlspecialchars($_POST['diagnostic'] ?? '') ?>" placeholder="Diagnostic principal ou provisoire">
        </div>
      </div>
      <div class="form-group full">
        <label>Observations complémentaires</label>
        <textarea name="observations" rows="3" placeholder="Recommandations, suivi, orientation..."><?= htmlspecialchars($_POST['observations'] ?? '') ?></textarea>
      </div>

      <div class="signature-strip">
        <div class="sig-left">
          <i class="fas fa-pen-fancy"></i>
          <div>
            <strong>Signature numérique</strong>
            <span>Cette consultation sera signée par votre clé de praticien et scellée dans le registre.</span>
          </div>
        </div>
        <div class="sig-dot"><span></span> Prêt à signer</div>
      </div>
    </div>

    <div class="form-actions">
      <a href="dossier_patient.php?id=<?= $patient['id'] ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Annuler
      </a>
      <button type="submit" name="submit_consult" value="1" class="btn btn-forest">
        <i class="fas fa-cube"></i> Valider et sceller la consultation
      </button>
    </div>
  </form>
</section>
<?php endif; ?>

<style>
.form-shell { max-width: 960px; margin: 0 auto; }
.form-card { background: white; border: 1px solid var(--line); border-radius: 20px; overflow: hidden; box-shadow: 0 14px 34px -18px rgba(15,23,42,.12); }
.form-head { display: flex; align-items: center; gap: 16px; padding: 24px 28px; border-bottom: 1px solid var(--line); }
.form-head.forest { background: linear-gradient(135deg, rgba(26,71,42,.08), rgba(16,185,129,.04)); }
.form-head-icon { width: 52px; height: 52px; border-radius: 14px; color: white; display: grid; place-items: center; font-size: 20px; }
.form-head-icon.forest-icon { background: var(--g-forest); }
.form-head h2 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 18px; font-weight: 800; color: var(--ink); margin: 0; }
.form-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }
.form-head .req { color: #ef4444; }

.form-body { padding: 28px; }
.section-divider { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: var(--forest); padding: 18px 0 12px; margin-top: 8px; border-bottom: 2px solid rgba(16,185,129,.1); margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
.section-divider:first-child { margin-top: 0; padding-top: 0; }

.form-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 10px; }
.form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.form-group.full { grid-column: 1/-1; }
.form-group label { font-size: 13px; font-weight: 700; color: var(--ink); }
.form-group label .req { color: #ef4444; }

.input-shell { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1.5px solid var(--line); border-radius: 12px; padding: 0 14px; transition: .25s; }
.input-shell:focus-within { border-color: var(--forest); background: white; box-shadow: 0 0 0 3px rgba(26,71,42,.1); }
.input-shell i { color: var(--muted); width: 14px; font-size: 14px; }
.input-shell input { flex: 1; border: none; background: transparent; outline: none; padding: 12px 0; font-size: 14px; color: var(--ink); font-family: inherit; }

textarea { width: 100%; border: 1.5px solid var(--line); background: #f8fafc; border-radius: 12px; padding: 12px 14px; font-size: 14px; color: var(--ink); font-family: inherit; resize: vertical; transition: .25s; }
textarea:focus { outline: none; border-color: var(--forest); background: white; box-shadow: 0 0 0 3px rgba(26,71,42,.1); }

.signature-strip { margin-top: 20px; padding: 18px 20px; background: linear-gradient(135deg, rgba(16,185,129,.05), rgba(99,102,241,.03)); border: 1px solid rgba(16,185,129,.2); border-radius: 14px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.sig-left { display: flex; align-items: center; gap: 14px; }
.sig-left > i { width: 44px; height: 44px; display: grid; place-items: center; background: var(--g-emerald); color: white; border-radius: 12px; font-size: 18px; box-shadow: 0 10px 22px -8px rgba(16,185,129,.5); }
.sig-left strong { display: block; font-size: 13px; color: var(--ink); margin-bottom: 3px; }
.sig-left span { font-size: 12px; color: var(--muted); }
.sig-dot { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.3); color: var(--forest); font-size: 12px; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
.sig-dot span { width: 8px; height: 8px; border-radius: 50%; background: var(--emerald); animation: pulse 1.5s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .6; transform: scale(1.3); } }

.form-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 20px 28px; border-top: 1px solid var(--line); background: #f8fafc; }

.alert-box { display: flex; align-items: center; gap: 14px; padding: 18px; margin-bottom: 20px; border-radius: 16px; font-size: 14px; }
.alert-box.success { background: linear-gradient(135deg, rgba(16,185,129,.08), rgba(16,185,129,.03)); border: 1px solid rgba(16,185,129,.3); color: #065f46; }
.alert-box.error { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25); color: #b91c1c; }
.ab-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--g-emerald); color: white; display: grid; place-items: center; font-size: 18px; flex-shrink: 0; }
.alert-box > div { flex: 1; }
.alert-box strong { display: block; font-size: 14px; color: var(--ink); }
.alert-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: .3s; font-family: inherit; }
.btn-primary { background: var(--g-emerald); color: white; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -8px rgba(16,185,129,.4); }
.btn-forest { background: var(--g-forest); color: white; }
.btn-forest:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -8px rgba(26,71,42,.4); }
.btn-outline { background: white; border: 1px solid var(--line); color: var(--ink); }
.btn-outline:hover { border-color: var(--forest); color: var(--forest); }

@media (max-width: 720px) { .form-grid-4 { grid-template-columns: 1fr 1fr; } }
@media (max-width: 520px) { .form-grid-4 { grid-template-columns: 1fr; } .form-body, .form-head, .form-actions { padding: 20px; } }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
