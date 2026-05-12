<?php
require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_medecin_traitant_column($pdo);
ensure_extended_columns($pdo);

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'patient') {
    header('Location: connexion1.php');
    exit;
}

$patientId = $_SESSION['user_id'];

// Infos patient (incluant médecin traitant + photo)
$stmt = $pdo->prepare("
    SELECT p.*,
           h.nom AS hopital_nom,
           d.prenom AS medecin_prenom,
           d.nom AS medecin_nom,
           d.specialite AS medecin_specialite
    FROM patients p
    LEFT JOIN hopitaux h ON h.id = p.hopital_reference
    LEFT JOIN docteurs d ON d.id = p.medecin_traitant_id
    WHERE p.id = :id
");
$stmt->execute([':id' => $patientId]);
$patient = $stmt->fetch();

// Salutation contextuelle selon l'heure
$h = (int)date('H');
$greeting = ($h < 12) ? 'Bonjour' : (($h < 18) ? 'Bon après-midi' : 'Bonsoir');

// Consultations détaillées (avec symptômes / injections / décision)
$stmt = $pdo->prepare("
    SELECT dm.*,
           CONCAT(d.prenom,' ',d.nom) AS docteur_nom,
           d.specialite AS docteur_specialite,
           CONCAT(i.prenom,' ',i.nom) AS infirmier_nom,
           h.nom AS hopital_nom
    FROM dossiers_medicaux dm
    LEFT JOIN docteurs   d ON d.id = dm.modifie_par_docteur
    LEFT JOIN infirmiers i ON i.id = dm.cree_par_infirmier
    LEFT JOIN hopitaux   h ON h.id = dm.hopital_id
    WHERE dm.patient_id = :id
      AND (dm.symptomes IS NOT NULL
           OR dm.injections_administrees IS NOT NULL
           OR dm.decision_finale IS NOT NULL
           OR dm.motif_visite IS NOT NULL)
    ORDER BY dm.date_creation DESC
    LIMIT 10
");
$stmt->execute([':id' => $patientId]);
$consultations = $stmt->fetchAll();

// Photo profil (chemin)
$photoUrl = !empty($patient['photo']) && file_exists(__DIR__ . '/' . $patient['photo'])
    ? $patient['photo']
    : '';

// Dossiers médicaux
$stmt = $pdo->prepare("
    SELECT dm.*,
           CONCAT(i.prenom,' ',i.nom) AS infirmier_nom,
           CONCAT(d.prenom,' ',d.nom) AS docteur_nom,
           h.nom AS hopital_nom
    FROM   dossiers_medicaux dm
    LEFT JOIN infirmiers i ON i.id = dm.cree_par_infirmier
    LEFT JOIN docteurs   d ON d.id = dm.modifie_par_docteur
    LEFT JOIN hopitaux   h ON h.id = dm.hopital_id
    WHERE  dm.patient_id = :id
    ORDER  BY dm.date_creation DESC
");
$stmt->execute([':id' => $patientId]);
$dossiers = $stmt->fetchAll();

// Ordonnances
$stmt = $pdo->prepare("
    SELECT pr.*,
           CONCAT(d.prenom,' ',d.nom) AS docteur_nom,
           dm.motif_visite
    FROM   prescriptions pr
    JOIN   dossiers_medicaux dm ON dm.id = pr.dossier_medical_id
    LEFT JOIN docteurs d ON d.id = dm.modifie_par_docteur
    WHERE  dm.patient_id = :id
    ORDER  BY pr.date_prescription DESC
");
$stmt->execute([':id' => $patientId]);
$ordonnances = $stmt->fetchAll();

// Transferts
$stmt = $pdo->prepare("
    SELECT tp.*,
           hs.nom AS hopital_source_nom,
           hd.nom AS hopital_dest_nom
    FROM   transferts_patients tp
    LEFT JOIN hopitaux hs ON hs.id = tp.hopital_source
    LEFT JOIN hopitaux hd ON hd.id = tp.hopital_destination
    WHERE  tp.patient_id = :id
    ORDER  BY tp.date_transfert DESC
    LIMIT  5
");
$stmt->execute([':id' => $patientId]);
$transferts = $stmt->fetchAll();

$nbDossiers    = count($dossiers);
$nbOrdonnances = count($ordonnances);
$nbTransferts  = count($transferts);

$pageTitle = 'Mon espace patient';
$pageActive = 'dashboard';
$userType = 'patient';
$userName = $_SESSION['user_nom'] ?? ($patient['prenom'] . ' ' . $patient['nom']);
$userSubtitle = 'Patient';
$blockchainId = $patient['identifiant_blockchain'] ?? '';

require_once 'includes/header_dashboard.php';
?>

<!-- HERO SECTION -->
<section class="dash-hero reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span>
      <?= $greeting ?> <?= htmlspecialchars(($patient['nom'] ?? '') . ' ' . ($patient['prenom'] ?? '')) ?> 👋
    </div>
    <h1>Votre santé, <span>tracée et sécurisée.</span></h1>
    <p>Accédez à vos dossiers médicaux, ordonnances et partagez l'accès avec vos soignants.</p>

    <div class="dash-hero-actions">
      <a href="#consultations" class="btn btn-white">
        <i class="fas fa-stethoscope"></i> Mes consultations
      </a>
      <a href="prescriptions.php" class="btn btn-ghost-w">
        <i class="fas fa-prescription"></i> Mes ordonnances
      </a>
    </div>
  </div>

  <div class="dash-hero-card patient-profile-card">
    <div class="patient-photo">
      <?php if ($photoUrl): ?>
        <img src="<?= htmlspecialchars($photoUrl) ?>?v=<?= time() ?>" alt="Photo de profil">
      <?php else: ?>
        <div class="patient-photo-fallback">
          <?= strtoupper(substr($patient['prenom'] ?? '', 0, 1) . substr($patient['nom'] ?? '', 0, 1)) ?>
        </div>
      <?php endif; ?>
      <a href="modifier_profil.php#photo" class="patient-photo-edit" title="Changer la photo">
        <i class="fas fa-camera"></i>
      </a>
    </div>
    <div class="patient-card-info">
      <strong><?= htmlspecialchars(($patient['prenom'] ?? '') . ' ' . ($patient['nom'] ?? '')) ?></strong>
      <span><i class="fas fa-id-badge"></i> <?= htmlspecialchars($blockchainId ? HashChain::shortHash($blockchainId, 8, 4) : '—') ?></span>
      <span class="patient-card-verified"><i class="fas fa-check-circle"></i> Compte patient vérifié</span>
    </div>
  </div>
</section>

<!-- STATS GRID -->
<section class="stat-grid">
  <a href="#dossiers" class="stat-card tilt reveal-up">
    <div class="stat-card-icon forest">
      <i class="fas fa-file-medical"></i>
    </div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbDossiers ?>">0</div>
      <div class="stat-card-label">Dossiers médicaux</div>
    </div>
    <div class="stat-card-trend">
      <i class="fas fa-arrow-right"></i>
    </div>
  </a>

  <a href="prescriptions.php" class="stat-card tilt reveal-up" style="animation-delay:.05s">
    <div class="stat-card-icon emerald">
      <i class="fas fa-prescription"></i>
    </div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbOrdonnances ?>">0</div>
      <div class="stat-card-label">Ordonnances</div>
    </div>
    <div class="stat-card-trend">
      <i class="fas fa-arrow-right"></i>
    </div>
  </a>

  <a href="transferer_patient.php" class="stat-card tilt reveal-up" style="animation-delay:.15s">
    <div class="stat-card-icon blockchain">
      <i class="fas fa-ambulance"></i>
    </div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbTransferts ?>">0</div>
      <div class="stat-card-label">Transferts</div>
    </div>
    <div class="stat-card-trend">
      <i class="fas fa-arrow-right"></i>
    </div>
  </a>
</section>

<!-- DOSSIERS MEDICAUX -->
<section class="dash-card reveal-up" id="dossiers">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-file-medical" style="color:var(--forest)"></i> Mes dossiers médicaux</h3>
      <p>Historique de vos consultations et hospitalisations</p>
    </div>
    <span class="pill-count"><?= $nbDossiers ?></span>
  </div>

  <?php if (empty($dossiers)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-folder-open"></i></div>
      <h4>Aucun dossier pour l'instant</h4>
      <p>Vos dossiers apparaîtront ici après votre première consultation.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Motif</th>
            <th>Hôpital</th>
            <th>Soignant</th>
            <th>Statut</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dossiers as $d): ?>
          <tr>
            <td>
              <div class="cell-date">
                <strong><?= date('d/m/Y', strtotime($d['date_creation'])) ?></strong>
                <small><?= date('H:i', strtotime($d['date_creation'])) ?></small>
              </div>
            </td>
            <td><?= htmlspecialchars($d['motif_visite'] ?? '—') ?></td>
            <td><?= htmlspecialchars($d['hopital_nom'] ?? '—') ?></td>
            <td>
              <?php if ($d['docteur_nom']): ?>
                <div class="cell-person">
                  <div class="avatar-sm forest">DR</div>
                  <span>Dr. <?= htmlspecialchars($d['docteur_nom']) ?></span>
                </div>
              <?php else: ?>
                <div class="cell-person">
                  <div class="avatar-sm emerald">IN</div>
                  <span><?= htmlspecialchars($d['infirmier_nom'] ?? '—') ?></span>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= ($d['statut'] ?? 'actif') === 'actif' ? 'badge-success' : 'badge-neutral' ?>">
                <?= ucfirst($d['statut'] ?? 'actif') ?>
              </span>
            </td>
            <td>
              <a href="dossier_patient.php?id=<?= $d['id'] ?>" class="btn-ic" title="Voir détails">
                <i class="fas fa-eye"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- CONSULTATIONS DÉTAILLÉES (LECTURE SEULE) -->
<section class="dash-card reveal-up" id="consultations">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-stethoscope" style="color:var(--theme-primary, var(--forest))"></i> Mes consultations détaillées</h3>
      <p>Symptômes déclarés, soins administrés et décision finale du docteur · <em>lecture seule</em></p>
    </div>
    <span class="pill-count"><?= count($consultations) ?></span>
  </div>

  <?php if (empty($consultations)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
      <h4>Aucune consultation détaillée</h4>
      <p>Les symptômes, soins et décisions de vos docteurs apparaîtront ici après votre prochaine visite.</p>
    </div>
  <?php else: ?>
    <div class="consult-grid">
      <?php foreach ($consultations as $c):
        $date = strtotime($c['date_creation']);
      ?>
      <article class="consult-card">
        <header class="consult-card-head">
          <div class="consult-date">
            <i class="fas fa-calendar"></i>
            <div>
              <strong><?= date('d/m/Y', $date) ?></strong>
              <small><?= date('H:i', $date) ?> · <?= htmlspecialchars($c['hopital_nom'] ?? '—') ?></small>
            </div>
          </div>
          <?php if (!empty($c['docteur_nom'])): ?>
          <div class="consult-doctor">
            <div class="avatar-sm forest">DR</div>
            <div>
              <strong>Dr. <?= htmlspecialchars($c['docteur_nom']) ?></strong>
              <small><?= htmlspecialchars($c['docteur_specialite'] ?? 'Médecin') ?></small>
            </div>
          </div>
          <?php endif; ?>
        </header>

        <?php if (!empty($c['motif_visite'])): ?>
        <div class="consult-block consult-motif">
          <span class="consult-label"><i class="fas fa-comment-medical"></i> Motif de la visite</span>
          <p><?= nl2br(htmlspecialchars($c['motif_visite'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($c['symptomes'])): ?>
        <div class="consult-block consult-sympt">
          <span class="consult-label"><i class="fas fa-thermometer-half"></i> Symptômes déclarés</span>
          <p><?= nl2br(htmlspecialchars($c['symptomes'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($c['injections_administrees'])): ?>
        <div class="consult-block consult-inj">
          <span class="consult-label"><i class="fas fa-syringe"></i> Injections / médicaments administrés</span>
          <p><?= nl2br(htmlspecialchars($c['injections_administrees'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($c['decision_finale'])): ?>
        <div class="consult-block consult-decision">
          <span class="consult-label"><i class="fas fa-gavel"></i> Décision finale du docteur</span>
          <p><?= nl2br(htmlspecialchars($c['decision_finale'])) ?></p>
        </div>
        <?php endif; ?>

        <footer class="consult-readonly">
          <i class="fas fa-lock"></i> Information en lecture seule — non modifiable par le patient
        </footer>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- ORDONNANCES -->
<section class="dash-card reveal-up" id="ordonnances-section">
  <div class="ord-wrap">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-prescription" style="color:var(--emerald)"></i> Mes ordonnances</h3>
        <p>Traitements en cours et historique</p>
      </div>
      <span class="pill-count"><?= $nbOrdonnances ?></span>
    </div>

    <?php if (empty($ordonnances)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="fas fa-prescription-bottle"></i></div>
        <h4>Aucune ordonnance</h4>
        <p>Vos ordonnances numériques apparaîtront ici.</p>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach (array_slice($ordonnances, 0, 5) as $o): ?>
        <div class="item-row">
          <div class="item-icon emerald"><i class="fas fa-pills"></i></div>
          <div class="item-body">
            <h4><?= htmlspecialchars($o['medicament']) ?></h4>
            <p><?= htmlspecialchars($o['dosage']) ?> · <?= htmlspecialchars($o['frequence']) ?></p>
            <small>Dr. <?= htmlspecialchars($o['docteur_nom'] ?? '—') ?> · <?= date('d/m/Y', strtotime($o['date_prescription'])) ?></small>
          </div>
          <span class="badge <?= ($o['statut'] ?? 'active') === 'active' ? 'badge-success' : 'badge-neutral' ?>">
            <?= ucfirst($o['statut'] ?? 'active') ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($nbOrdonnances > 5): ?>
      <a href="prescriptions.php" class="card-more">
        Voir toutes les ordonnances <i class="fas fa-arrow-right"></i>
      </a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<!-- TRANSFERTS -->
<section class="dash-card reveal-up" id="transferts">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-ambulance" style="color:var(--blockchain)"></i> Mes transferts inter-hôpitaux</h3>
      <p>Transmissions sécurisées de votre dossier</p>
    </div>
    <span class="pill-count"><?= $nbTransferts ?></span>
  </div>

  <?php if (empty($transferts)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-ambulance"></i></div>
      <h4>Aucun transfert</h4>
      <p>Les transferts de votre dossier vers un autre hôpital apparaîtront ici.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Source</th>
            <th>Destination</th>
            <th>Motif</th>
            <th>Statut</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transferts as $t):
            $badgeClass = match($t['statut']) {
              'complete' => 'badge-success',
              'accepte'  => 'badge-info',
              'demande'  => 'badge-warn',
              'refuse'   => 'badge-danger',
              default    => 'badge-neutral'
            };
          ?>
          <tr>
            <td><strong><?= date('d/m/Y', strtotime($t['date_transfert'])) ?></strong></td>
            <td><?= htmlspecialchars($t['hopital_source_nom'] ?? '—') ?></td>
            <td>
              <div class="cell-arrow">
                <i class="fas fa-arrow-right"></i>
                <?= htmlspecialchars($t['hopital_dest_nom'] ?? '—') ?>
              </div>
            </td>
            <td><?= htmlspecialchars($t['motif'] ?? '—') ?></td>
            <td>
              <span class="badge <?= $badgeClass ?>"><?= ucfirst($t['statut']) ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- MON PROFIL -->
<section class="dash-card reveal-up" id="profil">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-user" style="color:var(--theme-primary, var(--forest))"></i> Mon profil patient</h3>
      <p>Informations personnelles et identité numérique du patient</p>
    </div>
    <a href="modifier_profil.php" class="btn btn-outline">
      <i class="fas fa-edit"></i> Modifier
    </a>
  </div>

  <div class="profil-photo-row">
    <div class="profil-photo">
      <?php if ($photoUrl): ?>
        <img src="<?= htmlspecialchars($photoUrl) ?>?v=<?= time() ?>" alt="Photo de profil">
      <?php else: ?>
        <div class="profil-photo-fallback">
          <?= strtoupper(substr($patient['prenom'] ?? '', 0, 1) . substr($patient['nom'] ?? '', 0, 1)) ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="profil-photo-meta">
      <strong>Photo de profil patient</strong>
      <p>Cette photo s'affiche dans votre espace et auprès de vos soignants.</p>
      <a href="modifier_profil.php#photo" class="btn btn-outline btn-sm">
        <i class="fas fa-camera"></i> <?= $photoUrl ? 'Changer ma photo' : 'Ajouter ma photo' ?>
      </a>
    </div>
  </div>

  <div class="profil-grid">
    <div class="profil-item">
      <label>Nom complet</label>
      <p><?= htmlspecialchars(($patient['prenom'] ?? '') . ' ' . ($patient['nom'] ?? '')) ?></p>
    </div>
    <div class="profil-item">
      <label>Date de naissance</label>
      <p><?= $patient['date_naissance'] ? date('d/m/Y', strtotime($patient['date_naissance'])) : '—' ?></p>
    </div>
    <div class="profil-item">
      <label>Groupe sanguin</label>
      <p><?= htmlspecialchars($patient['groupe_sanguin'] ?? '—') ?></p>
    </div>
    <div class="profil-item">
      <label>Téléphone</label>
      <p><?= htmlspecialchars($patient['telephone'] ?? '—') ?></p>
    </div>
    <div class="profil-item">
      <label>Email</label>
      <p><?= htmlspecialchars($patient['email'] ?? '—') ?></p>
    </div>
    <div class="profil-item">
      <label>Hôpital de référence</label>
      <p><?= htmlspecialchars($patient['hopital_nom'] ?? '—') ?></p>
    </div>
    <div class="profil-item">
      <label>Médecin traitant</label>
      <p>
        <?php if (!empty($patient['medecin_nom'])): ?>
          Dr. <?= htmlspecialchars($patient['medecin_prenom'] . ' ' . $patient['medecin_nom']) ?>
          <?php if (!empty($patient['medecin_specialite'])): ?>
            <small style="display:block;color:var(--muted);font-weight:500;margin-top:2px"><?= htmlspecialchars($patient['medecin_specialite']) ?></small>
          <?php endif; ?>
        <?php else: ?>
          <span style="color:var(--muted)">— Aucun médecin assigné —</span>
        <?php endif; ?>
      </p>
    </div>
  </div>

  <div class="hash-block">
    <div class="hash-block-icon">
      <i class="fas fa-cube"></i>
    </div>
    <div class="hash-block-body">
      <strong>Identifiant Blockchain</strong>
      <code data-copy="<?= htmlspecialchars($blockchainId) ?>" title="Cliquer pour copier"><?= htmlspecialchars($blockchainId) ?></code>
    </div>
    <div class="hash-block-verified">
      <i class="fas fa-check-circle"></i>
      <span>Vérifié</span>
    </div>
  </div>
</section>

<style>
  /* ─── Hero dashboard ─── */
  .dash-hero {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #1a472a 0%, #0e2a1a 60%, #041a0d 100%);
    color: white;
    border-radius: 24px;
    padding: 40px;
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 32px;
    align-items: center;
    margin-bottom: 24px;
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
    background: radial-gradient(circle, rgba(99,102,241,.2), transparent 60%);
    filter: blur(60px);
  }

  .dash-hero-content { position: relative; z-index: 1; }

  .dash-hero-greet {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 12px; background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 999px;
    font-size: 12px; font-weight: 600;
    margin-bottom: 16px;
  }

  .dash-hero-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #10b981; box-shadow: 0 0 12px #10b981;
    animation: pulse 2s infinite;
  }

  .dash-hero h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 36px; font-weight: 800; line-height: 1.15;
    letter-spacing: -.02em;
  }

  .dash-hero h1 span {
    display: block;
    background: linear-gradient(135deg, #10b981, #34d399);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }

  .dash-hero p {
    font-size: 15px; color: rgba(255,255,255,.75);
    margin-top: 12px; line-height: 1.5;
  }

  .dash-hero-actions {
    display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap;
  }

  .btn-white {
    background: white; color: var(--forest);
    border: none;
  }
  .btn-white:hover {
    background: #f1f5f9;
    transform: translateY(-2px);
    box-shadow: 0 10px 24px -8px rgba(255,255,255,.3);
  }

  .btn-ghost-w {
    background: rgba(255,255,255,.1);
    color: white;
    border: 1px solid rgba(255,255,255,.2);
  }
  .btn-ghost-w:hover {
    background: rgba(255,255,255,.18);
    transform: translateY(-2px);
  }

  .dash-hero-card {
    position: relative;
    z-index: 1;
    padding: 24px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12);
    backdrop-filter: blur(16px);
    border-radius: 18px;
  }

  .dash-hero-card-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 12px;
  }

  .dash-hero-card-label {
    font-size: 11px; font-weight: 700;
    letter-spacing: .08em; text-transform: uppercase;
    color: rgba(255,255,255,.6);
  }

  .dash-hero-card-head i {
    color: #34d399; font-size: 18px;
  }

  .dash-hero-card-hash {
    font-family: 'JetBrains Mono', monospace;
    font-size: 15px; font-weight: 600;
    color: #34d399;
    padding: 12px 14px;
    background: rgba(16,185,129,.1);
    border: 1px solid rgba(16,185,129,.25);
    border-radius: 10px;
    cursor: pointer;
    transition: .3s;
    word-break: break-all;
  }

  .dash-hero-card-hash:hover {
    background: rgba(16,185,129,.18);
    border-color: rgba(16,185,129,.4);
  }

  .dash-hero-card-meta {
    display: flex; align-items: center; gap: 8px;
    margin-top: 12px;
    font-size: 12px; color: rgba(255,255,255,.6);
  }

  .dash-hero-card-meta i { color: #34d399; }

  /* ─── Stat grid ─── */
  .stat-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
  }

  .stat-card {
    display: flex; align-items: center; gap: 16px;
    padding: 20px;
    background: white;
    border: 1px solid var(--line);
    border-radius: 16px;
    text-decoration: none;
    transition: .3s;
    position: relative;
    overflow: hidden;
  }

  .stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: var(--g-forest);
    transform: scaleX(0); transform-origin: left;
    transition: transform .4s;
  }

  .stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px -16px rgba(15,23,42,.15);
    border-color: rgba(26,71,42,.2);
  }

  .stat-card:hover::before { transform: scaleX(1); }

  .stat-card-icon {
    width: 48px; height: 48px;
    border-radius: 14px;
    display: grid; place-items: center;
    color: white;
    font-size: 20px;
    flex-shrink: 0;
  }

  .stat-card-icon.forest    { background: var(--g-forest); }
  .stat-card-icon.emerald   { background: var(--g-emerald); }
  .stat-card-icon.trust     { background: var(--g-trust); }
  .stat-card-icon.blockchain{ background: var(--g-blockchain); }

  .stat-card-body { flex: 1; }

  .stat-card-value {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 28px; font-weight: 800;
    color: var(--ink); line-height: 1;
  }

  .stat-card-label {
    font-size: 13px; color: var(--muted);
    margin-top: 4px;
  }

  .stat-card-trend {
    color: var(--muted); font-size: 13px;
    transition: .3s;
  }

  .stat-card:hover .stat-card-trend {
    color: var(--forest);
    transform: translateX(4px);
  }

  /* ─── Dash card ─── */
  .dash-card {
    background: white;
    border: 1px solid var(--line);
    border-radius: 20px;
    padding: 28px;
    margin-bottom: 24px;
  }

  .dash-card-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px;
    gap: 16px;
  }

  .dash-card-head h3 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 18px; font-weight: 700;
    color: var(--ink);
    display: flex; align-items: center; gap: 10px;
    letter-spacing: -.01em;
  }

  .dash-card-head p {
    font-size: 13px; color: var(--muted); margin-top: 2px;
  }

  .pill-count {
    padding: 4px 12px;
    background: rgba(26,71,42,.08);
    color: var(--forest);
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
  }

  /* ─── Grid 2 ─── */
  .grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
  }

  /* ─── Empty state ─── */
  .empty {
    text-align: center;
    padding: 40px 20px;
  }

  .empty-icon {
    width: 72px; height: 72px;
    margin: 0 auto 16px;
    background: rgba(26,71,42,.06);
    border-radius: 50%;
    display: grid; place-items: center;
    color: var(--forest);
    font-size: 28px;
  }

  .empty h4 {
    font-size: 16px; font-weight: 700;
    color: var(--ink); margin-bottom: 6px;
  }

  .empty p { font-size: 13px; color: var(--muted); }

  /* ─── Table ─── */
  .table-wrap { overflow-x: auto; }

  .table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }

  .table thead th {
    padding: 12px 16px;
    text-align: left;
    font-size: 11px; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase;
    color: var(--muted);
    border-bottom: 1px solid var(--line);
    background: #f8fafc;
  }

  .table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: var(--ink);
    vertical-align: middle;
  }

  .table tbody tr:hover { background: #f8fafc; }
  .table tbody tr:last-child td { border-bottom: none; }

  .cell-date { display: flex; flex-direction: column; }
  .cell-date strong { font-weight: 600; }
  .cell-date small { font-size: 11px; color: var(--muted); }

  .cell-person {
    display: flex; align-items: center; gap: 10px;
  }

  .avatar-sm {
    width: 32px; height: 32px; border-radius: 50%;
    display: grid; place-items: center;
    color: white; font-size: 11px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif;
  }

  .avatar-sm.forest  { background: var(--g-forest); }
  .avatar-sm.emerald { background: var(--g-emerald); }

  .cell-arrow {
    display: flex; align-items: center; gap: 8px;
  }

  .cell-arrow i { color: var(--forest); font-size: 12px; }

  /* ─── Badge ─── */
  .badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  .badge-success { background: #d1fae5; color: #065f46; }
  .badge-info    { background: #dbeafe; color: #1e40af; }
  .badge-warn    { background: #fef3c7; color: #92400e; }
  .badge-danger  { background: #fee2e2; color: #991b1b; }
  .badge-neutral { background: #f1f5f9; color: #475569; }

  /* ─── Item list ─── */
  .item-list { display: flex; flex-direction: column; gap: 10px; }

  .item-row {
    display: flex; align-items: center; gap: 14px;
    padding: 14px;
    background: #f8fafc;
    border: 1px solid transparent;
    border-radius: 12px;
    transition: .3s;
  }

  .item-row:hover {
    background: white;
    border-color: var(--line);
    box-shadow: 0 4px 12px -4px rgba(15,23,42,.08);
  }

  .item-icon {
    width: 40px; height: 40px;
    border-radius: 12px;
    display: grid; place-items: center;
    color: white;
    flex-shrink: 0;
  }

  .item-icon.forest  { background: var(--g-forest); }
  .item-icon.emerald { background: var(--g-emerald); }
  .item-icon.trust   { background: var(--g-trust); }

  .item-body { flex: 1; min-width: 0; }

  .item-body h4 {
    font-size: 14px; font-weight: 600;
    color: var(--ink); margin-bottom: 2px;
  }

  .item-body p {
    font-size: 13px; color: var(--muted);
    margin-bottom: 2px;
  }

  .item-body small {
    font-size: 11px; color: #94a3b8;
  }

  .btn-revoke {
    width: 32px; height: 32px; border-radius: 10px;
    background: rgba(239,68,68,.08);
    color: #ef4444; border: none;
    cursor: pointer; transition: .3s;
  }

  .btn-revoke:hover {
    background: #ef4444; color: white;
  }

  /* ─── Card more link ─── */
  .card-more {
    display: inline-flex; align-items: center; gap: 6px;
    margin-top: 16px;
    font-size: 13px; font-weight: 600;
    color: var(--forest); text-decoration: none;
    transition: .3s;
  }
  .card-more:hover {
    gap: 10px; color: var(--emerald);
  }

  /* ─── Profil grid ─── */
  .profil-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 20px;
  }

  .profil-item {
    padding: 14px 16px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid var(--line);
  }

  .profil-item label {
    display: block;
    font-size: 11px; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 6px;
  }

  .profil-item p {
    font-size: 14px; font-weight: 600;
    color: var(--ink);
  }

  /* ─── Hash block ─── */
  .hash-block {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 20px;
    background: linear-gradient(135deg, rgba(16,185,129,.06), rgba(26,71,42,.04));
    border: 1px solid rgba(16,185,129,.2);
    border-radius: 14px;
  }

  .hash-block-icon {
    width: 44px; height: 44px;
    background: var(--g-emerald);
    border-radius: 12px;
    display: grid; place-items: center;
    color: white; font-size: 18px;
    flex-shrink: 0;
  }

  .hash-block-body { flex: 1; min-width: 0; }

  .hash-block-body strong {
    display: block;
    font-size: 13px; font-weight: 700;
    color: var(--ink); margin-bottom: 4px;
  }

  .hash-block-body code {
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px; color: var(--emerald);
    word-break: break-all; cursor: pointer;
    padding: 2px 0;
  }

  .hash-block-verified {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 12px;
    background: #d1fae5;
    color: #065f46;
    border-radius: 999px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
    flex-shrink: 0;
  }

  /* ─── btn ic ─── */
  .btn-ic {
    width: 36px; height: 36px; border-radius: 10px;
    background: rgba(26,71,42,.08);
    color: var(--forest);
    display: inline-grid; place-items: center;
    text-decoration: none;
    transition: .3s;
  }

  .btn-ic:hover {
    background: var(--forest); color: white;
  }

  /* ─── Responsive ─── */
  @media (max-width: 1200px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
  }

  @media (max-width: 960px) {
    .dash-hero {
      grid-template-columns: 1fr;
      padding: 32px 24px;
    }
    .dash-hero h1 { font-size: 28px; }
    .grid-2 { grid-template-columns: 1fr; }
    .profil-grid { grid-template-columns: repeat(2, 1fr); }
  }

  @media (max-width: 560px) {
    .stat-grid { grid-template-columns: 1fr; }
    .profil-grid { grid-template-columns: 1fr; }
    .dash-card { padding: 20px; }
    .hash-block { flex-direction: column; align-items: flex-start; }
  }

  /* ─── Carte profil patient avec photo (hero) ─── */
  .patient-profile-card {
    display: flex; gap: 16px; align-items: center;
  }
  .patient-photo {
    position: relative;
    width: 88px; height: 88px;
    border-radius: 24px;
    overflow: hidden;
    flex-shrink: 0;
    border: 3px solid rgba(255,255,255,.25);
    box-shadow: 0 12px 24px -8px rgba(0,0,0,.4);
  }
  .patient-photo img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
  }
  .patient-photo-fallback {
    width: 100%; height: 100%;
    display: grid; place-items: center;
    color: white; font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 800; font-size: 28px;
    background: linear-gradient(135deg, #10b981, #34d399);
  }
  .patient-photo-edit {
    position: absolute; bottom: -2px; right: -2px;
    width: 28px; height: 28px; border-radius: 50%;
    background: white; color: var(--forest);
    display: grid; place-items: center;
    font-size: 12px; text-decoration: none;
    border: 2px solid var(--forest);
    transition: .3s;
  }
  .patient-photo-edit:hover { transform: scale(1.1); background: var(--forest); color: white; }

  .patient-card-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
  .patient-card-info strong {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 18px; font-weight: 700;
    color: white; letter-spacing: -.01em;
  }
  .patient-card-info span {
    font-size: 12px; color: rgba(255,255,255,.7);
    display: inline-flex; align-items: center; gap: 6px;
    font-family: 'JetBrains Mono', monospace;
  }
  .patient-card-info i { color: #34d399; }
  .patient-card-verified {
    margin-top: 6px;
    font-family: inherit !important;
    font-size: 11px !important;
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  /* ─── Photo profil dans la section profil ─── */
  .profil-photo-row {
    display: flex; gap: 20px; align-items: center;
    padding: 20px; margin-bottom: 18px;
    background: linear-gradient(135deg, rgba(16,185,129,.06), rgba(26,71,42,.04));
    border: 1px solid rgba(16,185,129,.2);
    border-radius: 14px;
  }
  .profil-photo {
    width: 96px; height: 96px;
    border-radius: 24px; overflow: hidden;
    flex-shrink: 0;
    border: 3px solid white;
    box-shadow: 0 8px 20px -6px rgba(15,23,42,.2);
  }
  .profil-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .profil-photo-fallback {
    width: 100%; height: 100%;
    display: grid; place-items: center;
    color: white; font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 800; font-size: 32px;
    background: linear-gradient(135deg, #10b981, #059669);
  }
  .profil-photo-meta strong { display: block; font-size: 14px; color: var(--ink); margin-bottom: 4px; }
  .profil-photo-meta p { font-size: 12px; color: var(--muted); margin-bottom: 10px; }
  .btn-sm { padding: 7px 14px; font-size: 12px; }

  /* ─── Consultations détaillées (lecture seule) ─── */
  .consult-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 16px;
  }
  .consult-card {
    background: white;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 18px;
    display: flex; flex-direction: column; gap: 14px;
    transition: .25s;
  }
  .consult-card:hover {
    border-color: rgba(16,185,129,.3);
    box-shadow: 0 14px 30px -16px rgba(15,23,42,.14);
    transform: translateY(-2px);
  }
  .consult-card-head {
    display: flex; justify-content: space-between; align-items: center;
    gap: 12px;
    padding-bottom: 12px;
    border-bottom: 1px dashed var(--line);
  }
  .consult-date {
    display: flex; align-items: center; gap: 10px;
  }
  .consult-date > i {
    width: 36px; height: 36px; border-radius: 10px;
    background: rgba(16,185,129,.1); color: var(--emerald);
    display: grid; place-items: center; font-size: 14px;
  }
  .consult-date strong { display: block; font-size: 13px; color: var(--ink); font-weight: 700; }
  .consult-date small { display: block; font-size: 11px; color: var(--muted); }
  .consult-doctor {
    display: flex; align-items: center; gap: 8px;
  }
  .consult-doctor strong { display: block; font-size: 12px; color: var(--ink); font-weight: 700; }
  .consult-doctor small { display: block; font-size: 10px; color: var(--muted); }

  .consult-block {
    padding: 12px 14px;
    border-radius: 10px;
    background: #f8fafc;
    border-left: 3px solid var(--line);
  }
  .consult-block.consult-motif    { border-left-color: #64748b; }
  .consult-block.consult-sympt    { border-left-color: #f59e0b; background: rgba(245,158,11,.05); }
  .consult-block.consult-inj      { border-left-color: #ef4444; background: rgba(239,68,68,.04); }
  .consult-block.consult-decision { border-left-color: #10b981; background: rgba(16,185,129,.06); }

  .consult-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted);
    margin-bottom: 6px;
  }
  .consult-block.consult-sympt .consult-label    { color: #92400e; }
  .consult-block.consult-inj   .consult-label    { color: #991b1b; }
  .consult-block.consult-decision .consult-label { color: #065f46; }

  .consult-block p {
    font-size: 13px; color: var(--ink);
    line-height: 1.5; margin: 0;
  }

  .consult-readonly {
    display: flex; align-items: center; gap: 6px;
    margin-top: auto; padding-top: 8px;
    font-size: 11px; color: var(--muted);
    font-style: italic;
    border-top: 1px dashed var(--line);
  }
  .consult-readonly i { color: #f59e0b; }

  @media (max-width: 720px) {
    .consult-grid { grid-template-columns: 1fr; }
    .patient-profile-card { flex-direction: column; text-align: center; }
    .profil-photo-row { flex-direction: column; text-align: center; }
  }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
