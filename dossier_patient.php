<?php
// dossier_patient.php — Vue complète d'un dossier patient (médecin + infirmier)
require_once 'config.php';
require_once 'includes/hash_chain.php';

$userType = $_SESSION['user_type'] ?? '';
$userId = 0;
$canView = false;

if (in_array($userType, ['docteur', 'medecin'])) {
    $userId = (int)($_SESSION['medecin_id'] ?? 0);
    $canView = true;
} elseif ($userType === 'infirmier') {
    $userId = (int)($_SESSION['medecin_id'] ?? 0);
    $canView = true;
} elseif ($userType === 'admin') {
    $userId = (int)($_SESSION['admin_id'] ?? 0);
    $canView = true;
} elseif ($userType === 'patient') {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $canView = true;
}

if (!$canView || !$userId) { header('Location: connexion1.php'); exit; }

$patientId = (int)($_GET['id'] ?? 0);
if (!$patientId) { header('Location: mes_patients.php'); exit; }

// Patient ne peut voir que SON dossier
if ($userType === 'patient' && $patientId !== $userId) {
    header('Location: dashboard_patient.php');
    exit;
}

try {
    // On récupère un docteur "référent" : le dernier docteur ayant signé
    // un dossier pour ce patient (le médecin traitant n'est pas modélisé en BDD).
    $stmt = $pdo->prepare("
        SELECT p.*,
               (SELECT COUNT(*) FROM dossiers_medicaux WHERE patient_id = p.id) AS nb_dossiers,
               (SELECT COUNT(*) FROM prescriptions pr
                  JOIN dossiers_medicaux dm ON dm.id = pr.dossier_medical_id
                 WHERE dm.patient_id = p.id) AS nb_prescriptions,
               (SELECT d.prenom FROM docteurs d
                  JOIN dossiers_medicaux dm ON dm.modifie_par_docteur = d.id
                 WHERE dm.patient_id = p.id
                 ORDER BY dm.date_creation DESC LIMIT 1) AS medecin_prenom,
               (SELECT d.nom FROM docteurs d
                  JOIN dossiers_medicaux dm ON dm.modifie_par_docteur = d.id
                 WHERE dm.patient_id = p.id
                 ORDER BY dm.date_creation DESC LIMIT 1) AS medecin_nom,
               (SELECT d.specialite FROM docteurs d
                  JOIN dossiers_medicaux dm ON dm.modifie_par_docteur = d.id
                 WHERE dm.patient_id = p.id
                 ORDER BY dm.date_creation DESC LIMIT 1) AS medecin_specialite
        FROM patients p
        WHERE p.id = ?
    ");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('dossier_patient: ' . $e->getMessage());
    $patient = null;
}

if (!$patient) {
    echo "Dossier introuvable."; exit;
}

// Dossiers médicaux
$dossiers = [];
try {
    $stmt = $pdo->prepare("
      SELECT dm.*,
             d.prenom AS dr_prenom, d.nom AS dr_nom
      FROM dossiers_medicaux dm
      LEFT JOIN docteurs d ON d.id = dm.modifie_par_docteur
      WHERE dm.patient_id = ?
      ORDER BY dm.date_creation DESC
      LIMIT 20
    ");
    $stmt->execute([$patientId]);
    $dossiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('dossier_patient dossiers: ' . $e->getMessage());
    $dossiers = [];
}

// Prescriptions
$prescriptions = [];
try {
    $stmt = $pdo->prepare("
      SELECT pr.*, d.prenom AS dr_prenom, d.nom AS dr_nom, dm.patient_id
      FROM prescriptions pr
      JOIN dossiers_medicaux dm ON dm.id = pr.dossier_medical_id
      LEFT JOIN docteurs d ON d.id = dm.modifie_par_docteur
      WHERE dm.patient_id = ?
      ORDER BY pr.date_prescription DESC
      LIMIT 20
    ");
    $stmt->execute([$patientId]);
    $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('dossier_patient prescriptions: ' . $e->getMessage());
    $prescriptions = [];
}

// Logger la consultation du dossier (pour audit blockchain)
if ($userType !== 'patient') {
    HashChain::addBlock('VIEW_DOSSIER', $patientId, $userId, $userType, [
        'patient_id' => $patientId,
        'accessed_at' => date('c'),
    ], 'dossiers_medicaux');
}

$age = $patient['date_naissance'] ? date_diff(date_create($patient['date_naissance']), date_create('today'))->y : null;
$initials = strtoupper(substr($patient['prenom'], 0, 1) . substr($patient['nom'], 0, 1));

$pageTitle = 'Dossier patient';
$pageActive = ($userType === 'patient') ? 'dossiers' : 'patients';
$breadcrumb = [$userType === 'patient' ? 'Mes dossiers' : 'Patients', $patient['prenom'] . ' ' . $patient['nom']];
require_once 'includes/header_dashboard.php';
?>

<!-- Patient header -->
<section class="patient-hero reveal-up">
  <div class="patient-hero-bg"></div>
  <div class="patient-hero-body">
    <div class="patient-hero-avatar"><?= htmlspecialchars($initials) ?></div>
    <div class="patient-hero-info">
      <div class="patient-tags">
        <span class="tag tag-verified"><i class="fas fa-check-circle"></i> Patient vérifié</span>
        <?php if ($patient['groupe_sanguin']): ?>
          <span class="tag tag-blood"><i class="fas fa-droplet"></i> <?= htmlspecialchars($patient['groupe_sanguin']) ?></span>
        <?php endif; ?>
      </div>
      <h1><?= htmlspecialchars($patient['prenom'] . ' ' . $patient['nom']) ?></h1>
      <div class="patient-meta">
        <?php if ($age !== null): ?><span><i class="fas fa-cake-candles"></i> <?= $age ?> ans</span><?php endif; ?>
        <?php if ($patient['sexe']): ?><span><i class="fas fa-venus-mars"></i> <?= htmlspecialchars(ucfirst($patient['sexe'])) ?></span><?php endif; ?>
        <?php if ($patient['telephone']): ?><span><i class="fas fa-phone"></i> <?= htmlspecialchars($patient['telephone']) ?></span><?php endif; ?>
        <?php if ($patient['email']): ?><span><i class="fas fa-envelope"></i> <?= htmlspecialchars($patient['email']) ?></span><?php endif; ?>
      </div>
    </div>
    <?php if (in_array($userType, ['docteur', 'medecin'])): ?>
    <div class="patient-hero-actions">
      <a href="consultation.php?patient=<?= $patient['id'] ?>" class="btn btn-primary">
        <i class="fas fa-stethoscope"></i> Nouvelle consultation
      </a>
      <a href="prescriptions.php?patient=<?= $patient['id'] ?>&action=new" class="btn btn-outline">
        <i class="fas fa-prescription"></i> Prescrire
      </a>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- Identifiant blockchain -->
<?php if ($patient['identifiant_blockchain']): ?>
<section class="identity-strip reveal-up">
  <div class="identity-icon"><i class="fas fa-cube"></i></div>
  <div>
    <span class="identity-label">Identifiant blockchain</span>
    <code class="identity-value" data-copy="<?= htmlspecialchars($patient['identifiant_blockchain']) ?>" title="Cliquer pour copier">
      <?= htmlspecialchars($patient['identifiant_blockchain']) ?>
    </code>
  </div>
  <span class="identity-badge">Vérifié</span>
</section>
<?php endif; ?>

<!-- Stats -->
<section class="stat-grid reveal-up">
  <div class="stat-card">
    <div class="stat-card-icon forest"><i class="fas fa-folder-open"></i></div>
    <div><div class="stat-card-value"><?= $patient['nb_dossiers'] ?></div><div class="stat-card-label">Dossiers médicaux</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon emerald"><i class="fas fa-prescription"></i></div>
    <div><div class="stat-card-value"><?= $patient['nb_prescriptions'] ?></div><div class="stat-card-label">Ordonnances</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon trust"><i class="fas fa-heart-pulse"></i></div>
    <div><div class="stat-card-value"><?= htmlspecialchars($patient['groupe_sanguin'] ?: '—') ?></div><div class="stat-card-label">Groupe sanguin</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon blockchain"><i class="fas fa-user-md"></i></div>
    <div>
      <div class="stat-card-value-sm">
        <?= $patient['medecin_nom'] ? 'Dr. ' . htmlspecialchars($patient['medecin_nom']) : 'Aucun' ?>
      </div>
      <div class="stat-card-label">Médecin traitant</div>
    </div>
  </div>
</section>

<div class="grid-2">
  <!-- Antécédents -->
  <section class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-clipboard-list" style="color:var(--forest)"></i> Antécédents</h3>
        <p>Informations médicales de référence</p>
      </div>
    </div>

    <div class="anamnese-grid">
      <?php
        $ana = [
          'antecedents_medicaux' => ['Antécédents médicaux', 'fa-notes-medical'],
          'allergies'            => ['Allergies', 'fa-triangle-exclamation'],
          'traitements_en_cours' => ['Traitements en cours', 'fa-pills'],
          'antecedents_familiaux'=> ['Antécédents familiaux', 'fa-people-group'],
        ];
        foreach ($ana as $key => [$lbl, $ic]):
          $value = trim($patient[$key] ?? '');
      ?>
        <div class="anamnese-row <?= $value ? 'has-value' : 'empty-value' ?>">
          <div class="ana-icon"><i class="fas <?= $ic ?>"></i></div>
          <div class="ana-body">
            <span><?= htmlspecialchars($lbl) ?></span>
            <p><?= $value ? nl2br(htmlspecialchars($value)) : '<em>Non renseigné</em>' ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Identité -->
  <section class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-id-card" style="color:var(--emerald)"></i> Identité administrative</h3>
        <p>Informations de contact et administratives</p>
      </div>
    </div>

    <div class="identity-list">
      <?php
        $fields = [
          'date_naissance' => ['Date de naissance', fn($v) => date('d/m/Y', strtotime($v))],
          'lieu_naissance' => ['Lieu de naissance', fn($v) => $v],
          'nationalite'    => ['Nationalité', fn($v) => $v],
          'NPI' => ['Numéro NPI', fn($v) => $v],
          'adresse'        => ['Adresse', fn($v) => $v],
          'ville'          => ['Ville', fn($v) => $v],
          'code_postal'    => ['Code postal', fn($v) => $v],
          'telephone'      => ['Téléphone', fn($v) => $v],
          'email'          => ['Email', fn($v) => $v],
          'personne_contact_urgence' => ['Contact d\'urgence', fn($v) => $v],
          'telephone_urgence'=> ['Tél. urgence', fn($v) => $v],
        ];
        foreach ($fields as $key => [$lbl, $fmt]):
          if (empty($patient[$key])) continue;
      ?>
        <div class="id-row">
          <span class="id-label"><?= htmlspecialchars($lbl) ?></span>
          <span class="id-value"><?= htmlspecialchars($fmt($patient[$key])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<!-- Historique consultations -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-stethoscope" style="color:var(--forest)"></i> Historique des consultations</h3>
      <p><?= count($dossiers) ?> dossier<?= count($dossiers) > 1 ? 's' : '' ?> enregistré<?= count($dossiers) > 1 ? 's' : '' ?></p>
    </div>
  </div>

  <?php if (empty($dossiers)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-folder-open"></i></div>
      <h4>Aucune consultation enregistrée</h4>
      <p>Les dossiers médicaux apparaîtront ici.</p>
    </div>
  <?php else: ?>
    <div class="timeline">
      <?php foreach ($dossiers as $d): ?>
        <div class="tl-row">
          <div class="tl-date">
            <strong><?= date('d', strtotime($d['date_creation'])) ?></strong>
            <span><?= strtoupper(date('M', strtotime($d['date_creation']))) ?></span>
            <small><?= date('Y', strtotime($d['date_creation'])) ?></small>
          </div>
          <div class="tl-dot"><span></span></div>
          <div class="tl-body">
            <div class="tl-head">
              <h4><?= htmlspecialchars($d['titre'] ?? $d['motif_visite'] ?? 'Consultation') ?></h4>
              <span><i class="fas fa-user-md"></i>
                <?= $d['dr_prenom'] ? 'Dr. ' . htmlspecialchars($d['dr_prenom'] . ' ' . $d['dr_nom']) : 'Infirmier' ?>
              </span>
            </div>
            <?php if (!empty($d['diagnostic'])): ?>
              <p><strong>Diagnostic :</strong> <?= htmlspecialchars(mb_strimwidth($d['diagnostic'], 0, 180, '…')) ?></p>
            <?php endif; ?>
            <?php if (!empty($d['observations'])): ?>
              <p class="tl-obs"><?= htmlspecialchars(mb_strimwidth($d['observations'], 0, 220, '…')) ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- Ordonnances -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-prescription" style="color:var(--emerald)"></i> Ordonnances</h3>
      <p>Prescriptions médicales récentes</p>
    </div>
  </div>

  <?php if (empty($prescriptions)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-prescription-bottle"></i></div>
      <h4>Aucune ordonnance</h4>
      <p>Les prescriptions apparaîtront ici.</p>
    </div>
  <?php else: ?>
    <div class="prescr-list">
      <?php foreach ($prescriptions as $pr):
        $statutClass = match($pr['statut']) {
          'active'   => 'success',
          'terminee' => 'neutral',
          'annulee'  => 'danger',
          default    => 'neutral'
        };
      ?>
        <div class="prescr-row">
          <div class="prescr-icon"><i class="fas fa-pills"></i></div>
          <div class="prescr-body">
            <h4><?= htmlspecialchars($pr['medicament'] ?? 'Prescription') ?></h4>
            <p>
              <?= htmlspecialchars($pr['posologie'] ?? '') ?>
              <?php if (!empty($pr['duree'])): ?> · <?= htmlspecialchars($pr['duree']) ?><?php endif; ?>
            </p>
            <small>
              Prescrit le <?= date('d/m/Y', strtotime($pr['date_prescription'])) ?>
              <?php if ($pr['dr_nom']): ?>
                par Dr. <?= htmlspecialchars($pr['dr_prenom'] . ' ' . $pr['dr_nom']) ?>
              <?php endif; ?>
            </small>
          </div>
          <span class="badge badge-<?= $statutClass ?>"><?= ucfirst($pr['statut'] ?? 'actif') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<style>
  /* Patient hero */
  .patient-hero {
    position: relative; border-radius: 24px; overflow: hidden;
    margin-bottom: 20px; background: white; border: 1px solid var(--line);
  }
  .patient-hero-bg {
    height: 130px;
    background: linear-gradient(135deg, #1a472a 0%, #0e2a1a 40%, #0369a1 100%);
    position: relative;
  }
  .patient-hero-bg::before {
    content: ''; position: absolute; inset: 0;
    background: radial-gradient(circle at 70% 50%, rgba(56,189,248,.4), transparent 60%);
    filter: blur(40px);
  }
  .patient-hero-body {
    display: grid; grid-template-columns: auto 1fr auto;
    gap: 24px; align-items: flex-end;
    padding: 0 32px 24px; margin-top: -52px;
    position: relative; z-index: 1;
  }
  .patient-hero-avatar {
    width: 116px; height: 116px; border-radius: 26px;
    display: grid; place-items: center;
    color: white; font-size: 40px; font-weight: 800;
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: var(--g-emerald);
    border: 5px solid white;
    box-shadow: 0 14px 36px -10px rgba(15,23,42,.25);
  }
  .patient-hero-info { padding-bottom: 4px; flex: 1; }
  .patient-tags { display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
  .tag {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 999px;
    font-size: 11px; font-weight: 700;
  }
  .tag-verified { background: #d1fae5; color: #065f46; border: 1px solid rgba(16,185,129,.3); }
  .tag-verified i { color: #10b981; }
  .tag-blood { background: #fee2e2; color: #991b1b; border: 1px solid rgba(239,68,68,.3); }
  .patient-hero-info h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 32px; font-weight: 800; color: var(--ink); margin-bottom: 8px;
  }
  .patient-meta { display: flex; flex-wrap: wrap; gap: 14px; font-size: 13px; color: var(--muted); }
  .patient-meta i { color: var(--forest); margin-right: 4px; }
  .patient-hero-actions { display: flex; gap: 10px; padding-bottom: 4px; }

  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 11px 18px; border-radius: 10px;
    font-size: 13px; font-weight: 700;
    text-decoration: none; border: none; cursor: pointer; transition: .3s;
  }
  .btn-primary { background: var(--g-forest); color: white; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -4px rgba(26,71,42,.4); }
  .btn-outline { background: white; border: 1px solid var(--line); color: var(--ink); }
  .btn-outline:hover { border-color: var(--forest); color: var(--forest); }

  /* Identity strip */
  .identity-strip {
    display: grid; grid-template-columns: auto 1fr auto;
    gap: 18px; align-items: center;
    padding: 14px 20px; margin-bottom: 20px;
    background: linear-gradient(135deg, rgba(99,102,241,.06), rgba(3,105,161,.04));
    border: 1px solid rgba(99,102,241,.2);
    border-radius: 14px;
  }
  .identity-icon {
    width: 42px; height: 42px; border-radius: 10px;
    background: var(--g-blockchain); color: white;
    display: grid; place-items: center; font-size: 18px;
  }
  .identity-label {
    display: block; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
  }
  .identity-value {
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px; font-weight: 700; color: var(--blockchain);
    padding: 4px 10px; background: rgba(99,102,241,.08);
    border-radius: 6px; cursor: pointer;
    word-break: break-all;
  }
  .identity-value:hover { background: rgba(99,102,241,.18); }
  .identity-badge {
    padding: 6px 14px; background: #d1fae5; color: #065f46;
    border-radius: 999px; font-size: 11px; font-weight: 700;
  }

  /* Stats */
  .stat-grid {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 20px;
  }
  .stat-card {
    display: flex; align-items: center; gap: 12px;
    padding: 18px; background: white;
    border: 1px solid var(--line); border-radius: 14px;
  }
  .stat-card-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: grid; place-items: center; color: white; font-size: 16px;
  }
  .stat-card-icon.forest { background: var(--g-forest); }
  .stat-card-icon.emerald { background: var(--g-emerald); }
  .stat-card-icon.trust { background: var(--g-trust); }
  .stat-card-icon.blockchain { background: var(--g-blockchain); }
  .stat-card-value { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 22px; font-weight: 800; color: var(--ink); line-height: 1; }
  .stat-card-value-sm { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 14px; font-weight: 700; color: var(--ink); line-height: 1.2; }
  .stat-card-label { font-size: 11px; color: var(--muted); margin-top: 4px; }

  /* Cards + grid */
  .dash-card { background: white; border: 1px solid var(--line); border-radius: 20px; padding: 24px 28px; margin-bottom: 20px; }
  .dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
  .dash-card-head h3 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
  .dash-card-head p { font-size: 12px; color: var(--muted); margin-top: 2px; }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }

  /* Anamnèse */
  .anamnese-grid { display: flex; flex-direction: column; gap: 10px; }
  .anamnese-row {
    display: flex; gap: 12px; padding: 12px 14px;
    background: #f8fafc; border-radius: 12px;
    border-left: 3px solid transparent;
  }
  .anamnese-row.has-value { border-left-color: var(--forest); }
  .anamnese-row.empty-value { opacity: .7; }
  .ana-icon {
    width: 34px; height: 34px; border-radius: 10px;
    background: rgba(26,71,42,.08); color: var(--forest);
    display: grid; place-items: center; font-size: 13px; flex-shrink: 0;
  }
  .ana-body { flex: 1; min-width: 0; }
  .ana-body span { display: block; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
  .ana-body p { font-size: 13px; color: var(--ink); line-height: 1.5; word-break: break-word; }
  .ana-body em { color: var(--muted); font-style: italic; font-size: 12px; }

  /* Identity list */
  .identity-list { display: flex; flex-direction: column; gap: 0; }
  .id-row {
    display: grid; grid-template-columns: 180px 1fr; gap: 12px;
    padding: 10px 12px; border-bottom: 1px dashed var(--line);
  }
  .id-row:last-child { border-bottom: none; }
  .id-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
  .id-value { font-size: 13px; color: var(--ink); word-break: break-word; }

  /* Timeline */
  .timeline { display: flex; flex-direction: column; }
  .tl-row { display: grid; grid-template-columns: 70px 20px 1fr; gap: 14px; padding: 8px 0; }
  .tl-date {
    text-align: center; padding-top: 10px;
    font-family: 'Plus Jakarta Sans', sans-serif;
  }
  .tl-date strong { display: block; font-size: 24px; font-weight: 800; color: var(--forest); line-height: 1; }
  .tl-date span { display: block; font-size: 10px; color: var(--muted); font-weight: 700; text-transform: uppercase; }
  .tl-date small { font-size: 10px; color: var(--muted); font-family: 'JetBrains Mono', monospace; }
  .tl-dot { position: relative; display: flex; justify-content: center; }
  .tl-dot::before {
    content: ''; position: absolute; top: 0; bottom: -18px; left: 50%; width: 2px;
    background: #e2e8f0; transform: translateX(-50%);
  }
  .tl-dot span {
    width: 12px; height: 12px; border-radius: 50%;
    background: var(--forest); border: 3px solid white;
    box-shadow: 0 0 0 2px var(--forest); margin-top: 18px; z-index: 1;
  }
  .tl-body {
    padding: 14px 16px; background: #f8fafc;
    border: 1px solid transparent; border-radius: 12px;
    transition: .2s;
  }
  .tl-body:hover { background: white; border-color: var(--line); }
  .tl-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 6px; }
  .tl-head h4 { font-size: 15px; font-weight: 700; color: var(--ink); }
  .tl-head span { font-size: 11px; color: var(--muted); }
  .tl-head i { color: var(--forest); margin-right: 4px; }
  .tl-body p { font-size: 13px; color: var(--ink); line-height: 1.6; margin-bottom: 4px; }
  .tl-body strong { color: var(--forest); }
  .tl-obs { color: var(--muted) !important; font-size: 12px !important; }

  /* Prescriptions */
  .prescr-list { display: flex; flex-direction: column; gap: 10px; }
  .prescr-row {
    display: flex; align-items: center; gap: 12px;
    padding: 14px; background: #f8fafc;
    border-left: 3px solid var(--emerald); border-radius: 12px;
  }
  .prescr-icon {
    width: 42px; height: 42px; border-radius: 10px;
    background: var(--g-emerald); color: white;
    display: grid; place-items: center; font-size: 16px; flex-shrink: 0;
  }
  .prescr-body { flex: 1; min-width: 0; }
  .prescr-body h4 { font-size: 14px; font-weight: 700; color: var(--ink); }
  .prescr-body p { font-size: 12px; color: var(--muted); margin-top: 2px; }
  .prescr-body small { font-size: 11px; color: #94a3b8; display: block; margin-top: 4px; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .badge-success { background: #d1fae5; color: #065f46; }
  .badge-neutral { background: #f1f5f9; color: #475569; }
  .badge-danger { background: #fee2e2; color: #991b1b; }

  .empty { text-align: center; padding: 40px 20px; }
  .empty-icon { width: 72px; height: 72px; margin: 0 auto 16px; background: rgba(26,71,42,.08); border-radius: 50%; display: grid; place-items: center; color: var(--forest); font-size: 28px; }
  .empty h4 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
  .empty p { font-size: 13px; color: var(--muted); }

  @media (max-width: 960px) {
    .grid-2 { grid-template-columns: 1fr; }
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
  }
  @media (max-width: 720px) {
    .patient-hero-body { grid-template-columns: 1fr; text-align: center; }
    .patient-hero-avatar { margin: -52px auto 0; }
    .patient-meta { justify-content: center; }
    .patient-hero-actions { justify-content: center; flex-wrap: wrap; }
    .id-row { grid-template-columns: 1fr; gap: 2px; }
    .tl-row { grid-template-columns: 55px 16px 1fr; }
  }
</style>

<script>
  document.querySelectorAll('[data-copy]').forEach(el => {
    el.addEventListener('click', () => {
      const t = el.getAttribute('data-copy'); if (!t) return;
      navigator.clipboard.writeText(t).then(() => {
        const orig = el.style.background; el.style.background = '#10b981'; el.style.color = 'white';
        setTimeout(() => { el.style.background = orig; el.style.color = ''; }, 600);
      });
    });
  });
</script>

<?php require_once 'includes/footer_dashboard.php'; ?>
