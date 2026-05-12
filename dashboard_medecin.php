<?php
require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_extended_columns($pdo);

if (!isset($_SESSION['medecin_id']) || !in_array($_SESSION['user_type'] ?? '', ['medecin', 'docteur'])) {
    header('Location: connexion2.php');
    exit;
}

$medecin_id = (int)$_SESSION['medecin_id'];

// ─── POST : toggle en_service ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_en_service') {
    if (csrf_check()) {
        $new = (int)(!empty($_POST['en_service']));
        try {
            $pdo->prepare("UPDATE docteurs SET en_service = ? WHERE id = ?")
                ->execute([$new, $medecin_id]);
            HashChain::addBlock('TOGGLE_EN_SERVICE', $medecin_id, $medecin_id, 'docteur', [
                'en_service' => $new,
            ], 'docteurs');
        } catch (PDOException $e) {
            error_log('toggle_en_service: ' . $e->getMessage());
        }
    }
    header('Location: dashboard_medecin.php#en-service');
    exit;
}

// Récupérer le statut en_service actuel
$en_service = 0;
try {
    $stmt = $pdo->prepare("SELECT en_service FROM docteurs WHERE id = ?");
    $stmt->execute([$medecin_id]);
    $en_service = (int)($stmt->fetchColumn() ?: 0);
} catch (PDOException $e) {
    $en_service = 0;
}
$medecin_identifiant_blockchain = $_SESSION['medecin_identifiant_blockchain'] ?? '';
$medecin_nom        = $_SESSION['medecin_nom']        ?? '';
$medecin_prenom     = $_SESSION['medecin_prenom']     ?? '';
$medecin_specialite = $_SESSION['medecin_specialite'] ?? '';
$medecin_numero_licence = $_SESSION['medecin_numero_licence'] ?? '';
$medecin_telephone  = $_SESSION['medecin_telephone']  ?? '';
$medecin_email      = $_SESSION['medecin_email']      ?? '';
$medecin_signature_numerique = $_SESSION['medecin_signature_numerique'] ?? '';

$total_patients      = 0;
$total_consultations = 0;
$nb_acces_actifs     = 0;
$nb_prescriptions_actives = 0;
$patients            = [];
$consultations_jour  = [];
$consultations_recentes = [];
$dossiers_infirmiers = [];
$nb_dossiers_attente = 0;
$error = '';

// La feature "rendez-vous" n'existe pas dans le schéma. Le dashboard
// s'appuie sur les tables existantes : acces_patients, dossiers_medicaux,
// prescriptions.
try {
    // Patients dont le docteur a un accès actif (table acces_patients)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT a.patient_id)
        FROM acces_patients a
        WHERE a.entite_id = ? AND a.type_entite = 'docteur'
          AND a.actif = 1 AND (a.date_fin IS NULL OR a.date_fin > NOW())
    ");
    $stmt->execute([$medecin_id]);
    $total_patients = (int)$stmt->fetchColumn();

    // Consultations du jour : dossiers signés par ce docteur (modifie_par_docteur)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM dossiers_medicaux
         WHERE modifie_par_docteur = ?
           AND DATE(date_creation) = CURDATE()
    ");
    $stmt->execute([$medecin_id]);
    $total_consultations = (int)$stmt->fetchColumn();

    // Accès actifs accordés au docteur
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM acces_patients
         WHERE entite_id = ? AND type_entite = 'docteur'
           AND actif = 1 AND (date_fin IS NULL OR date_fin > NOW())
    ");
    $stmt->execute([$medecin_id]);
    $nb_acces_actifs = (int)$stmt->fetchColumn();

    // Prescriptions actives signées via les dossiers du docteur
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM prescriptions pr
          JOIN dossiers_medicaux dm ON dm.id = pr.dossier_medical_id
         WHERE dm.modifie_par_docteur = ? AND pr.statut = 'active'
    ");
    $stmt->execute([$medecin_id]);
    $nb_prescriptions_actives = (int)$stmt->fetchColumn();

    // 5 derniers patients suivis (via acces_patients)
    $stmt = $pdo->prepare("
        SELECT p.id, p.nom, p.prenom, p.date_naissance, p.groupe_sanguin,
               p.telephone, p.identifiant_blockchain,
               MAX(a.date_debut) AS dernier_acces
          FROM acces_patients a
          JOIN patients p ON p.id = a.patient_id
         WHERE a.entite_id = ? AND a.type_entite = 'docteur'
           AND a.actif = 1 AND (a.date_fin IS NULL OR a.date_fin > NOW())
      GROUP BY p.id, p.nom, p.prenom, p.date_naissance, p.groupe_sanguin,
               p.telephone, p.identifiant_blockchain
      ORDER BY dernier_acces DESC
         LIMIT 5
    ");
    $stmt->execute([$medecin_id]);
    $patients = $stmt->fetchAll();

    // Consultations du jour (dossiers signés par le docteur aujourd'hui)
    $stmt = $pdo->prepare("
        SELECT dm.id, dm.titre, dm.type_document, dm.motif_visite, dm.date_creation,
               p.nom AS patient_nom, p.prenom AS patient_prenom, p.telephone, p.NPI
          FROM dossiers_medicaux dm
          JOIN patients p ON p.id = dm.patient_id
         WHERE dm.modifie_par_docteur = ?
           AND DATE(dm.date_creation) = CURDATE()
      ORDER BY dm.date_creation DESC
    ");
    $stmt->execute([$medecin_id]);
    $consultations_jour = $stmt->fetchAll();

    // 5 dernières consultations (toutes dates)
    $stmt = $pdo->prepare("
        SELECT dm.id, dm.titre, dm.type_document, dm.date_creation,
               p.nom AS patient_nom, p.prenom AS patient_prenom, p.telephone
          FROM dossiers_medicaux dm
          JOIN patients p ON p.id = dm.patient_id
         WHERE dm.modifie_par_docteur = ?
      ORDER BY dm.date_creation DESC
         LIMIT 5
    ");
    $stmt->execute([$medecin_id]);
    $consultations_recentes = $stmt->fetchAll();

    // Dossiers créés par les infirmiers à l'accueil — en attente du médecin
    // PRIORITÉ : dossiers explicitement envoyés à CE docteur (envoye_au_docteur_id = mon id)
    // PUIS : dossiers en attente génériques (compatibilité historique)
    $stmt = $pdo->prepare("
        SELECT dm.id, dm.patient_id, dm.cree_par_infirmier, dm.date_creation,
               dm.motif_visite, dm.tension, dm.temperature, dm.poids, dm.titre,
               dm.hopital_id, dm.type_document,
               dm.envoye_au_docteur_id, dm.date_envoi_docteur, dm.statut_prise_en_charge,
               p.nom AS patient_nom, p.prenom AS patient_prenom,
               p.date_naissance, p.groupe_sanguin, p.telephone,
               inf.nom AS inf_nom, inf.prenom AS inf_prenom,
               CASE WHEN dm.envoye_au_docteur_id = :me THEN 1 ELSE 0 END AS _is_for_me
          FROM dossiers_medicaux dm
          JOIN patients p ON p.id = dm.patient_id
     LEFT JOIN infirmiers inf ON inf.id = dm.cree_par_infirmier
         WHERE dm.cree_par_infirmier IS NOT NULL
           AND dm.modifie_par_docteur IS NULL
           AND (
                dm.envoye_au_docteur_id = :me
                OR (dm.envoye_au_docteur_id IS NULL AND dm.date_creation >= DATE_SUB(NOW(), INTERVAL 2 DAY))
           )
      ORDER BY _is_for_me DESC, dm.date_creation DESC
         LIMIT 8
    ");
    $stmt->execute([':me' => $medecin_id]);
    $dossiers_infirmiers = $stmt->fetchAll();
    $nb_dossiers_attente = count($dossiers_infirmiers);

    // Patients explicitement envoyés à ce docteur (sous-ensemble pour stat dédiée)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM dossiers_medicaux
        WHERE envoye_au_docteur_id = ? AND modifie_par_docteur IS NULL
    ");
    $stmt->execute([$medecin_id]);
    $nb_envoyes_a_moi = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('dashboard_medecin: ' . $e->getMessage());
    $error = "Certaines données n'ont pas pu être chargées (voir error_log).";
}

// Pour le header dashboard
$pageTitle = 'Tableau de bord médecin';
$pageActive = 'dashboard';
$userType = 'docteur';
$userName = 'Dr. ' . $medecin_prenom . ' ' . $medecin_nom;

require_once 'includes/header_dashboard.php';
?>

<!-- HERO -->
<section class="dash-hero reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span>
      <?= date('H') < 12 ? 'Bonjour' : (date('H') < 18 ? 'Bon après-midi' : 'Bonsoir') ?>, Dr. <?= htmlspecialchars($medecin_nom) ?>
    </div>
    <h1>Soigner avec <span>clarté et traçabilité.</span></h1>
    <p>
      <?php if ($total_consultations): ?>
        Vous avez réalisé <strong><?= $total_consultations ?> consultation<?= $total_consultations > 1 ? 's' : '' ?></strong> aujourd'hui.
      <?php elseif ($nb_dossiers_attente): ?>
        <strong><?= $nb_dossiers_attente ?> dossier<?= $nb_dossiers_attente > 1 ? 's' : '' ?></strong> en attente d'examen.
      <?php else: ?>
        Aucune consultation enregistrée aujourd'hui pour le moment.
      <?php endif; ?>
      <?= htmlspecialchars($medecin_specialite ? '· ' . $medecin_specialite : '') ?>
    </p>
    <?php if ($error): ?>
      <p class="dash-hero-error" style="margin-top:8px;color:#fecaca;font-size:13px;">
        <i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
      </p>
    <?php endif; ?>

    <div class="dash-hero-actions">
      <a href="#agenda" class="btn btn-white">
        <i class="fas fa-calendar-day"></i> Agenda du jour
      </a>
      <a href="mes_patients.php" class="btn btn-ghost-w">
        <i class="fas fa-users"></i> Mes patients
      </a>
    </div>
  </div>

  <div class="dash-hero-card" id="en-service">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Statut de service</span>
      <i class="fas <?= $en_service ? 'fa-circle-check' : 'fa-circle' ?>" style="color:<?= $en_service ? '#34d399' : 'rgba(255,255,255,.4)' ?>"></i>
    </div>

    <form method="POST" class="en-service-toggle <?= $en_service ? 'is-on' : '' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_en_service">
      <input type="hidden" name="en_service" value="<?= $en_service ? 0 : 1 ?>">
      <button type="submit" class="en-service-btn">
        <span class="en-service-dot"></span>
        <strong><?= $en_service ? 'En service' : 'Hors service' ?></strong>
        <small><?= $en_service ? 'Cliquer pour vous mettre hors service' : 'Cliquer pour vous mettre en service' ?></small>
      </button>
    </form>

    <div class="dash-hero-card-meta" style="margin-top:14px">
      <?php if ($nb_envoyes_a_moi > 0): ?>
        <span><i class="fas fa-inbox"></i> <strong><?= $nb_envoyes_a_moi ?></strong> patient<?= $nb_envoyes_a_moi > 1 ? 's' : '' ?> envoyé<?= $nb_envoyes_a_moi > 1 ? 's' : '' ?> par les infirmiers</span>
      <?php else: ?>
        <span><i class="fas fa-id-badge"></i> Licence #<?= htmlspecialchars($medecin_numero_licence ?: '—') ?></span>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- STATS -->
<section class="stat-grid">
  <div class="stat-card tilt reveal-up">
    <div class="stat-card-icon forest"><i class="fas fa-user-injured"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $total_patients ?>">0</div>
      <div class="stat-card-label">Patients suivis</div>
    </div>
  </div>

  <div class="stat-card tilt reveal-up" style="animation-delay:.05s">
    <div class="stat-card-icon emerald"><i class="fas fa-key"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nb_acces_actifs ?>">0</div>
      <div class="stat-card-label">Accès actifs aux dossiers</div>
    </div>
  </div>

  <a href="#accueil-infirmiers" class="stat-card tilt reveal-up" style="animation-delay:.1s;text-decoration:none;color:inherit;">
    <div class="stat-card-icon trust"><i class="fas fa-clipboard-user"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nb_dossiers_attente ?>">0</div>
      <div class="stat-card-label">Patients accueillis (à voir)</div>
    </div>
  </a>

  <div class="stat-card tilt reveal-up" style="animation-delay:.15s">
    <div class="stat-card-icon blockchain"><i class="fas fa-stethoscope"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $total_consultations ?>">0</div>
      <div class="stat-card-label">Consultations réalisées</div>
    </div>
  </div>
</section>

<!-- CONSULTATIONS DU JOUR -->
<section class="dash-card reveal-up" id="agenda">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-stethoscope" style="color:var(--forest)"></i> Consultations du jour</h3>
      <p><?= date('l d F Y', strtotime(date('Y-m-d'))) ?></p>
    </div>
    <span class="pill-count"><?= count($consultations_jour) ?></span>
  </div>

  <?php if (empty($consultations_jour)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-stethoscope"></i></div>
      <h4>Aucune consultation enregistrée aujourd'hui</h4>
      <p>Examinez les patients accueillis ci-dessous pour démarrer une consultation.</p>
    </div>
  <?php else: ?>
    <div class="schedule-list">
      <?php foreach ($consultations_jour as $cons): ?>
      <div class="schedule-row">
        <div class="schedule-time">
          <strong><?= date('H:i', strtotime($cons['date_creation'])) ?></strong>
        </div>
        <div class="schedule-dot"><span></span></div>
        <div class="schedule-body">
          <div class="schedule-patient">
            <div class="avatar-sm forest"><?= strtoupper(substr($cons['patient_prenom'], 0, 1) . substr($cons['patient_nom'], 0, 1)) ?></div>
            <div>
              <h4><?= htmlspecialchars($cons['patient_prenom'] . ' ' . $cons['patient_nom']) ?></h4>
              <small><?= htmlspecialchars($cons['motif_visite'] ?: ucfirst($cons['type_document'])) ?></small>
            </div>
          </div>
          <div class="schedule-meta">
            <span class="badge badge-success"><?= htmlspecialchars(ucfirst($cons['type_document'])) ?></span>
            <?php if (!empty($cons['telephone'])): ?>
              <a href="tel:<?= htmlspecialchars($cons['telephone']) ?>" class="btn-ic" title="Appeler">
                <i class="fas fa-phone"></i>
              </a>
            <?php endif; ?>
            <a href="dossier_patient.php?id=<?= (int)$cons['id'] ?>" class="btn-ic" title="Voir le dossier">
              <i class="fas fa-arrow-right"></i>
            </a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- ============ PATIENTS ACCUEILLIS PAR LES INFIRMIERS ============ -->
<section class="dash-card reveal-up" id="accueil-infirmiers">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-clipboard-user" style="color:#0369a1"></i> Patients accueillis par l'infirmerie</h3>
      <p>Fiches saisies à l'accueil — motif, constantes vitales, niveau d'urgence</p>
    </div>
    <span class="pill-count" style="background:rgba(14,165,233,.12);color:#0369a1;">
      <?= count($dossiers_infirmiers) ?> dossier<?= count($dossiers_infirmiers) > 1 ? 's' : '' ?>
    </span>
  </div>

  <?php if (empty($dossiers_infirmiers)): ?>
    <div class="empty">
      <div class="empty-icon" style="background:rgba(14,165,233,.08);color:#0369a1;"><i class="fas fa-clipboard-list"></i></div>
      <h4>Aucun patient en attente</h4>
      <p>Les fiches d'accueil créées par les infirmiers apparaîtront ici dès qu'un patient se présentera.</p>
    </div>
  <?php else: ?>
    <div class="accueil-grid">
      <?php foreach ($dossiers_infirmiers as $d):
        $age = !empty($d['date_naissance']) ? date_diff(date_create($d['date_naissance']), date_create('today'))->y : null;
        $urg = $d['niveau_urgence'] ?? 'normal';
        $urgClass = ['critique'=>'urg-critique','urgent'=>'urg-urgent','normal'=>'urg-normal'][$urg] ?? 'urg-normal';
        $urgLabel = ['critique'=>'Critique','urgent'=>'Urgent','normal'=>'Normal'][$urg] ?? 'Normal';
        $statut = $d['statut'] ?? '';
      ?>
      <div class="accueil-card <?= $urgClass ?>">
        <div class="accueil-card-head">
          <div class="accueil-avatar"><?= strtoupper(substr($d['patient_prenom'],0,1) . substr($d['patient_nom'],0,1)) ?></div>
          <div class="accueil-patient">
            <h4><?= htmlspecialchars($d['patient_prenom'] . ' ' . $d['patient_nom']) ?></h4>
            <p>
              <?= $age !== null ? "$age ans" : 'Âge inconnu' ?>
              <?= !empty($d['groupe_sanguin']) ? ' · Gr. ' . htmlspecialchars($d['groupe_sanguin']) : '' ?>
            </p>
            <small><i class="fas fa-clock"></i> <?= date('d/m H:i', strtotime($d['date_creation'])) ?></small>
          </div>
          <span class="urg-badge"><?= $urgLabel ?></span>
        </div>

        <div class="accueil-motif">
          <span class="accueil-label"><i class="fas fa-stethoscope"></i> Motif</span>
          <p><?= htmlspecialchars($d['motif_visite'] ?? '—') ?></p>
        </div>

        <?php if (!empty($d['symptomes'])): ?>
          <div class="accueil-motif">
            <span class="accueil-label"><i class="fas fa-comment-medical"></i> Symptômes</span>
            <p><?= nl2br(htmlspecialchars($d['symptomes'])) ?></p>
          </div>
        <?php endif; ?>

        <?php
        $constantes = [];
        if (!empty($d['tension_arterielle'])) $constantes[] = ['fa-heart', 'Tension', htmlspecialchars($d['tension_arterielle'])];
        if (!empty($d['temperature']))        $constantes[] = ['fa-temperature-half', 'T°', htmlspecialchars($d['temperature']) . '°C'];
        if (!empty($d['pouls']))              $constantes[] = ['fa-wave-square', 'Pouls', htmlspecialchars($d['pouls']) . ' bpm'];
        if (!empty($d['poids']))              $constantes[] = ['fa-weight-scale', 'Poids', htmlspecialchars($d['poids']) . ' kg'];
        ?>
        <?php if (!empty($constantes)): ?>
          <div class="constantes-row">
            <?php foreach ($constantes as [$ic, $lab, $val]): ?>
              <div class="constante">
                <i class="fas <?= $ic ?>"></i>
                <div>
                  <small><?= $lab ?></small>
                  <strong><?= $val ?></strong>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($d['observations'])): ?>
          <div class="accueil-note">
            <i class="fas fa-pen-nib"></i>
            <?= htmlspecialchars($d['observations']) ?>
          </div>
        <?php endif; ?>

        <div class="accueil-foot">
          <small>
            <i class="fas fa-user-nurse"></i>
            <?= htmlspecialchars(trim(($d['inf_prenom'] ?? '') . ' ' . ($d['inf_nom'] ?? '')) ?: 'Infirmier inconnu') ?>
          </small>
          <a href="consultation.php?patient=<?= (int)$d['patient_id'] ?>" class="btn btn-trust-sm">
            <i class="fas fa-stethoscope"></i> Démarrer la consultation
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<style>
  .accueil-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 16px;
  }

  .accueil-card {
    background: white; border: 1px solid var(--line);
    border-radius: 16px; padding: 18px;
    border-left-width: 4px; transition: .25s;
    display: flex; flex-direction: column; gap: 12px;
  }
  .accueil-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 30px -14px rgba(15,23,42,.15);
  }
  .accueil-card.urg-normal   { border-left-color: #10b981; }
  .accueil-card.urg-urgent   { border-left-color: #f59e0b; background: linear-gradient(180deg, rgba(245,158,11,.04), white 30%); }
  .accueil-card.urg-critique { border-left-color: #ef4444; background: linear-gradient(180deg, rgba(239,68,68,.05), white 30%); }

  .accueil-card-head {
    display: flex; align-items: center; gap: 12px;
  }
  .accueil-avatar {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg,#0ea5e9,#0369a1);
    color: white; display: grid; place-items: center;
    font-weight: 800; font-family: 'Plus Jakarta Sans',sans-serif;
    font-size: 14px; flex-shrink: 0;
  }
  .accueil-patient { flex: 1; min-width: 0; }
  .accueil-patient h4 { font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 2px; }
  .accueil-patient p { font-size: 12px; color: var(--muted); margin-bottom: 2px; }
  .accueil-patient small { font-size: 11px; color: #94a3b8; }

  .urg-badge {
    padding: 4px 10px; border-radius: 999px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .05em;
    flex-shrink: 0;
  }
  .urg-normal   .urg-badge { background: rgba(16,185,129,.12); color: #047857; }
  .urg-urgent   .urg-badge { background: rgba(245,158,11,.15); color: #b45309; }
  .urg-critique .urg-badge { background: rgba(239,68,68,.15); color: #b91c1c; animation: blinkBadge 1.4s ease-in-out infinite; }
  @keyframes blinkBadge { 50% { opacity: .55; } }

  .accueil-motif {
    background: #f8fafc; border-radius: 10px;
    padding: 8px 12px; font-size: 13px;
  }
  .accueil-label {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .07em;
    color: #0369a1; margin-bottom: 3px;
  }
  .accueil-motif p { color: var(--ink); line-height: 1.45; }

  .constantes-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 8px;
  }
  .constante {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px; background: rgba(99,102,241,.05);
    border: 1px solid rgba(99,102,241,.15);
    border-radius: 10px;
  }
  .constante i { color: #6366f1; font-size: 14px; }
  .constante small { font-size: 10px; color: var(--muted); display: block; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }
  .constante strong { font-size: 13px; color: var(--ink); font-weight: 700; }

  .accueil-note {
    display: flex; gap: 8px;
    padding: 10px 12px; background: rgba(245,158,11,.06);
    border: 1px solid rgba(245,158,11,.18);
    border-radius: 10px;
    font-size: 12px; color: #92400e; line-height: 1.5;
  }
  .accueil-note i { color: #d97706; flex-shrink: 0; margin-top: 2px; }

  .accueil-foot {
    display: flex; justify-content: space-between; align-items: center;
    padding-top: 8px; border-top: 1px dashed var(--line);
    gap: 10px;
  }
  .accueil-foot small {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; color: var(--muted); font-weight: 600;
  }
  .accueil-foot small i { color: #0369a1; }

  .btn-trust-sm {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 12px; background: linear-gradient(135deg,#0ea5e9,#0369a1);
    color: white; border-radius: 8px; font-size: 12px; font-weight: 700;
    text-decoration: none; transition: .2s;
  }
  .btn-trust-sm:hover { transform: translateY(-1px); box-shadow: 0 8px 18px -8px rgba(14,165,233,.5); }
</style>

<!-- GRID 2 -->
<section class="grid-2">
  <!-- PATIENTS -->
  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-user-friends" style="color:var(--emerald)"></i> Patients récents</h3>
        <p>Nouveaux patients sous votre suivi</p>
      </div>
      <a href="mes_patients.php" class="btn btn-outline">Voir tout</a>
    </div>

    <?php if (empty($patients)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
        <h4>Aucun patient</h4>
        <p>Les patients que vous suivez apparaîtront ici.</p>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($patients as $p): ?>
        <div class="item-row">
          <div class="avatar-md emerald"><?= strtoupper(substr($p['prenom'], 0, 1) . substr($p['nom'], 0, 1)) ?></div>
          <div class="item-body">
            <h4><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></h4>
            <p>
              <?php if ($p['date_naissance']): ?>
                <?php
                  $age = date_diff(date_create($p['date_naissance']), date_create('today'))->y;
                ?>
                <?= $age ?> ans
              <?php else: ?>
                Âge inconnu
              <?php endif; ?>
              <?= !empty($p['groupe_sanguin']) ? '· Gr. ' . htmlspecialchars($p['groupe_sanguin']) : '' ?>
            </p>
            <small><?= htmlspecialchars($p['telephone'] ?? '—') ?></small>
          </div>
          <a href="dossier_patient.php?id=<?= $p['id'] ?>" class="btn-ic" title="Voir dossier">
            <i class="fas fa-folder-open"></i>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- DERNIÈRES CONSULTATIONS -->
  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-clock-rotate-left" style="color:var(--trust)"></i> Dernières consultations</h3>
        <p>Vos 5 dernières interventions</p>
      </div>
      <a href="mes_patients.php" class="btn btn-outline">Tous mes patients</a>
    </div>

    <?php if (empty($consultations_recentes)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="far fa-calendar-alt"></i></div>
        <h4>Aucune consultation récente</h4>
        <p>Vous n'avez pas encore signé de dossier médical.</p>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($consultations_recentes as $cons): ?>
        <div class="item-row">
          <div class="item-icon trust">
            <div style="text-align:center;font-size:11px;line-height:1.1;">
              <strong style="font-size:14px;"><?= date('d', strtotime($cons['date_creation'])) ?></strong><br>
              <span style="text-transform:uppercase;"><?= strtoupper(substr(date('M', strtotime($cons['date_creation'])), 0, 3)) ?></span>
            </div>
          </div>
          <div class="item-body">
            <h4><?= htmlspecialchars($cons['patient_prenom'] . ' ' . $cons['patient_nom']) ?></h4>
            <p><?= htmlspecialchars($cons['titre']) ?></p>
            <small><?= date('H:i', strtotime($cons['date_creation'])) ?> · <?= htmlspecialchars(ucfirst($cons['type_document'])) ?></small>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- QUICK ACTIONS + PROFIL -->
<section class="grid-2-alt">
  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-bolt" style="color:var(--blockchain)"></i> Actions rapides</h3>
        <p>Tout ce qu'il vous faut en un clic</p>
      </div>
    </div>

    <div class="quick-actions">
      <a href="consultation.php" class="quick-action">
        <div class="quick-action-icon forest"><i class="fas fa-notes-medical"></i></div>
        <div>
          <strong>Nouvelle consultation</strong>
          <span>Démarrer une consultation</span>
        </div>
      </a>

      <a href="prescriptions.php" class="quick-action">
        <div class="quick-action-icon emerald"><i class="fas fa-prescription"></i></div>
        <div>
          <strong>Prescrire</strong>
          <span>Rédiger une ordonnance</span>
        </div>
      </a>

      <a href="resultats.php" class="quick-action">
        <div class="quick-action-icon trust"><i class="fas fa-flask"></i></div>
        <div>
          <strong>Résultats d'analyses</strong>
          <span>Consulter les examens</span>
        </div>
      </a>

      <a href="mes_patients.php?action=new" class="quick-action">
        <div class="quick-action-icon blockchain"><i class="fas fa-user-plus"></i></div>
        <div>
          <strong>Ajouter un patient</strong>
          <span>Enregistrer un nouveau patient</span>
        </div>
      </a>
    </div>
  </div>

  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-id-badge" style="color:var(--forest)"></i> Mon profil médecin</h3>
        <p>Identité professionnelle vérifiée</p>
      </div>
      <a href="mon_profil.php" class="btn btn-outline">
        <i class="fas fa-edit"></i> Modifier
      </a>
    </div>

    <div class="doctor-card">
      <div class="doctor-card-avatar" style="background:var(--g-forest)">
        <?= strtoupper(substr($medecin_prenom, 0, 1) . substr($medecin_nom, 0, 1)) ?>
      </div>
      <div class="doctor-card-info">
        <h3>Dr. <?= htmlspecialchars($medecin_prenom . ' ' . $medecin_nom) ?></h3>
        <p><?= htmlspecialchars($medecin_specialite ?: 'Médecin généraliste') ?></p>
        <div class="doctor-card-meta">
          <span><i class="fas fa-id-card"></i> Licence #<?= htmlspecialchars($medecin_numero_licence ?: '—') ?></span>
          <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($medecin_email) ?></span>
        </div>
      </div>
    </div>

    <div class="hash-block">
      <div class="hash-block-icon"><i class="fas fa-cube"></i></div>
      <div class="hash-block-body">
        <strong>Identifiant Blockchain</strong>
        <code data-copy="<?= htmlspecialchars($medecin_identifiant_blockchain) ?>" title="Copier"><?= htmlspecialchars($medecin_identifiant_blockchain) ?></code>
      </div>
      <div class="hash-block-verified">
        <i class="fas fa-check-circle"></i>
        <span>Vérifié</span>
      </div>
    </div>
  </div>
</section>

<style>
  .dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #1a472a 0%, #0a3a5c 60%, #0369a1 100%);
    color: white; border-radius: 24px;
    padding: 40px;
    display: grid; grid-template-columns: 1.4fr 1fr; gap: 32px;
    align-items: center; margin-bottom: 24px;
  }

  .dash-hero::before {
    content: ''; position: absolute; top: -150px; right: -150px;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(56,189,248,.35), transparent 60%);
    filter: blur(80px);
  }

  .dash-hero::after {
    content: ''; position: absolute; bottom: -100px; left: -100px;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(16,185,129,.25), transparent 60%);
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
    background: #38bdf8; box-shadow: 0 0 12px #38bdf8;
    animation: pulse 2s infinite;
  }

  .dash-hero h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 36px; font-weight: 800; line-height: 1.15;
    letter-spacing: -.02em;
  }

  .dash-hero h1 span {
    display: block;
    background: linear-gradient(135deg, #38bdf8, #7dd3fc);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }

  .dash-hero p { font-size: 15px; color: rgba(255,255,255,.8); margin-top: 12px; }
  .dash-hero-actions { display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap; }

  .btn-white { background: white; color: var(--forest); border: none; }
  .btn-white:hover { background: #f1f5f9; transform: translateY(-2px); }
  .btn-ghost-w { background: rgba(255,255,255,.1); color: white; border: 1px solid rgba(255,255,255,.2); }
  .btn-ghost-w:hover { background: rgba(255,255,255,.18); transform: translateY(-2px); }

  .dash-hero-card {
    position: relative; z-index: 1;
    padding: 24px; background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(16px);
    border-radius: 18px;
  }

  .dash-hero-card-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 12px;
  }

  .dash-hero-card-label {
    font-size: 11px; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: rgba(255,255,255,.6);
  }

  .dash-hero-card-head i { color: #7dd3fc; font-size: 18px; }

  .dash-hero-card-hash {
    font-family: 'JetBrains Mono', monospace;
    font-size: 15px; font-weight: 600; color: #7dd3fc;
    padding: 12px 14px; background: rgba(56,189,248,.1);
    border: 1px solid rgba(56,189,248,.25); border-radius: 10px;
    cursor: pointer; transition: .3s; word-break: break-all;
  }

  .dash-hero-card-hash:hover { background: rgba(56,189,248,.18); border-color: rgba(56,189,248,.4); }

  .dash-hero-card-meta {
    display: flex; align-items: center; gap: 8px;
    margin-top: 12px; font-size: 12px; color: rgba(255,255,255,.6);
  }

  .dash-hero-card-meta i { color: #7dd3fc; }

  .stat-grid {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 16px; margin-bottom: 24px;
  }

  .stat-card {
    display: flex; align-items: center; gap: 16px;
    padding: 20px; background: white;
    border: 1px solid var(--line); border-radius: 16px;
    transition: .3s; position: relative; overflow: hidden;
  }

  .stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: var(--g-forest); transform: scaleX(0); transform-origin: left;
    transition: transform .4s;
  }

  .stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px -16px rgba(15,23,42,.15);
    border-color: rgba(26,71,42,.2);
  }
  .stat-card:hover::before { transform: scaleX(1); }

  .stat-card-icon {
    width: 48px; height: 48px; border-radius: 14px;
    display: grid; place-items: center; color: white;
    font-size: 20px; flex-shrink: 0;
  }

  .stat-card-icon.forest    { background: var(--g-forest); }
  .stat-card-icon.emerald   { background: var(--g-emerald); }
  .stat-card-icon.trust     { background: var(--g-trust); }
  .stat-card-icon.blockchain{ background: var(--g-blockchain); }

  .stat-card-body { flex: 1; }

  .stat-card-value {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 28px; font-weight: 800; color: var(--ink); line-height: 1;
  }
  .stat-card-label { font-size: 13px; color: var(--muted); margin-top: 4px; }

  .dash-card {
    background: white; border: 1px solid var(--line);
    border-radius: 20px; padding: 28px; margin-bottom: 24px;
  }

  .dash-card-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px; gap: 16px;
  }

  .dash-card-head h3 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 18px; font-weight: 700; color: var(--ink);
    display: flex; align-items: center; gap: 10px; letter-spacing: -.01em;
  }

  .dash-card-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }

  .pill-count {
    padding: 4px 12px; background: rgba(26,71,42,.08);
    color: var(--forest); border-radius: 999px;
    font-size: 13px; font-weight: 700; font-family: 'JetBrains Mono', monospace;
  }

  .grid-2 {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 24px; margin-bottom: 24px;
  }

  .grid-2-alt {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 24px; margin-bottom: 24px;
  }

  /* Schedule list */
  .schedule-list { display: flex; flex-direction: column; gap: 4px; }

  .schedule-row {
    display: grid;
    grid-template-columns: 80px 20px 1fr;
    gap: 16px; align-items: stretch;
    padding: 8px 0;
  }

  .schedule-time {
    font-family: 'JetBrains Mono', monospace;
    font-size: 15px; font-weight: 700; color: var(--forest);
    padding-top: 16px;
  }

  .schedule-dot {
    position: relative; display: flex; justify-content: center;
  }

  .schedule-dot::before {
    content: ''; position: absolute; top: 0; bottom: 0; left: 50%;
    width: 2px; background: #e2e8f0; transform: translateX(-50%);
  }

  .schedule-dot span {
    width: 12px; height: 12px; border-radius: 50%;
    background: var(--forest); border: 3px solid white;
    box-shadow: 0 0 0 2px var(--forest);
    margin-top: 18px; z-index: 1;
  }

  .schedule-body {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 16px; background: #f8fafc;
    border: 1px solid transparent; border-radius: 12px;
    transition: .3s;
  }

  .schedule-body:hover {
    background: white; border-color: var(--line);
    box-shadow: 0 4px 12px -4px rgba(15,23,42,.08);
  }

  .schedule-patient { display: flex; align-items: center; gap: 12px; }
  .schedule-patient h4 { font-size: 14px; font-weight: 600; color: var(--ink); }
  .schedule-patient small { font-size: 12px; color: var(--muted); }

  .schedule-meta { display: flex; align-items: center; gap: 8px; }

  .empty { text-align: center; padding: 40px 20px; }
  .empty-icon {
    width: 72px; height: 72px; margin: 0 auto 16px;
    background: rgba(26,71,42,.06); border-radius: 50%;
    display: grid; place-items: center; color: var(--forest); font-size: 28px;
  }
  .empty h4 { font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
  .empty p { font-size: 13px; color: var(--muted); }

  .badge {
    display: inline-block; padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
  }
  .badge-success { background: #d1fae5; color: #065f46; }
  .badge-info    { background: #dbeafe; color: #1e40af; }
  .badge-warn    { background: #fef3c7; color: #92400e; }
  .badge-danger  { background: #fee2e2; color: #991b1b; }
  .badge-neutral { background: #f1f5f9; color: #475569; }

  .avatar-sm {
    width: 32px; height: 32px; border-radius: 50%;
    display: grid; place-items: center;
    color: white; font-size: 11px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif;
  }
  .avatar-sm.forest  { background: var(--g-forest); }
  .avatar-sm.emerald { background: var(--g-emerald); }

  .avatar-md {
    width: 44px; height: 44px; border-radius: 14px;
    display: grid; place-items: center;
    color: white; font-size: 14px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif; flex-shrink: 0;
  }
  .avatar-md.emerald { background: var(--g-emerald); }

  .item-list { display: flex; flex-direction: column; gap: 10px; }

  .item-row {
    display: flex; align-items: center; gap: 14px;
    padding: 14px; background: #f8fafc;
    border: 1px solid transparent; border-radius: 12px; transition: .3s;
  }

  .item-row:hover {
    background: white; border-color: var(--line);
    box-shadow: 0 4px 12px -4px rgba(15,23,42,.08);
  }

  .item-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: grid; place-items: center; color: white; flex-shrink: 0;
  }
  .item-icon.forest  { background: var(--g-forest); }
  .item-icon.emerald { background: var(--g-emerald); }
  .item-icon.trust   { background: var(--g-trust); }

  .item-body { flex: 1; min-width: 0; }
  .item-body h4 { font-size: 14px; font-weight: 600; color: var(--ink); margin-bottom: 2px; }
  .item-body p { font-size: 13px; color: var(--muted); margin-bottom: 2px; }
  .item-body small { font-size: 11px; color: #94a3b8; }

  .btn-ic {
    width: 36px; height: 36px; border-radius: 10px;
    background: rgba(26,71,42,.08); color: var(--forest);
    display: inline-grid; place-items: center;
    text-decoration: none; border: none; cursor: pointer; transition: .3s;
  }
  .btn-ic:hover { background: var(--forest); color: white; }

  /* Quick actions */
  .quick-actions {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 12px;
  }

  .quick-action {
    display: flex; align-items: center; gap: 14px;
    padding: 16px; background: #f8fafc;
    border: 1px solid transparent; border-radius: 14px;
    text-decoration: none; transition: .3s;
  }

  .quick-action:hover {
    background: white; border-color: var(--line);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -8px rgba(15,23,42,.12);
  }

  .quick-action-icon {
    width: 40px; height: 40px; border-radius: 12px;
    display: grid; place-items: center; color: white;
    font-size: 15px; flex-shrink: 0;
  }
  .quick-action-icon.forest    { background: var(--g-forest); }
  .quick-action-icon.emerald   { background: var(--g-emerald); }
  .quick-action-icon.trust     { background: var(--g-trust); }
  .quick-action-icon.blockchain{ background: var(--g-blockchain); }

  .quick-action strong {
    display: block; font-size: 14px; color: var(--ink); margin-bottom: 2px;
  }
  .quick-action span {
    display: block; font-size: 12px; color: var(--muted);
  }

  /* Doctor card */
  .doctor-card {
    display: flex; align-items: center; gap: 18px;
    padding: 20px; background: linear-gradient(135deg, rgba(26,71,42,.04), rgba(16,185,129,.04));
    border: 1px solid rgba(26,71,42,.1); border-radius: 16px;
    margin-bottom: 16px;
  }

  .doctor-card-avatar {
    width: 64px; height: 64px; border-radius: 16px;
    display: grid; place-items: center;
    color: white; font-size: 20px; font-weight: 800;
    font-family: 'Plus Jakarta Sans', sans-serif;
    flex-shrink: 0;
    box-shadow: 0 10px 24px -8px rgba(26,71,42,.3);
  }

  .doctor-card-info h3 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 18px; font-weight: 700; color: var(--ink); margin-bottom: 2px;
  }

  .doctor-card-info p {
    font-size: 14px; color: var(--forest); font-weight: 600;
    margin-bottom: 8px;
  }

  .doctor-card-meta {
    display: flex; flex-direction: column; gap: 4px;
    font-size: 12px; color: var(--muted);
  }

  .doctor-card-meta i { color: var(--forest); margin-right: 6px; }

  /* Hash block */
  .hash-block {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 20px;
    background: linear-gradient(135deg, rgba(99,102,241,.06), rgba(3,105,161,.04));
    border: 1px solid rgba(99,102,241,.2);
    border-radius: 14px;
  }

  .hash-block-icon {
    width: 44px; height: 44px; background: var(--g-blockchain);
    border-radius: 12px; display: grid; place-items: center;
    color: white; font-size: 18px; flex-shrink: 0;
  }

  .hash-block-body { flex: 1; min-width: 0; }
  .hash-block-body strong {
    display: block; font-size: 13px; font-weight: 700;
    color: var(--ink); margin-bottom: 4px;
  }
  .hash-block-body code {
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px; color: var(--blockchain);
    word-break: break-all; cursor: pointer;
  }

  .hash-block-verified {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 12px; background: #d1fae5;
    color: #065f46; border-radius: 999px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em; flex-shrink: 0;
  }

  @media (max-width: 1200px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
  }

  @media (max-width: 960px) {
    .dash-hero { grid-template-columns: 1fr; padding: 32px 24px; }
    .dash-hero h1 { font-size: 28px; }
    .grid-2, .grid-2-alt { grid-template-columns: 1fr; }
    .quick-actions { grid-template-columns: 1fr; }
    .schedule-row { grid-template-columns: 60px 16px 1fr; gap: 10px; }
  }

  @media (max-width: 560px) {
    .stat-grid { grid-template-columns: 1fr; }
    .dash-card { padding: 20px; }
    .hash-block { flex-direction: column; align-items: flex-start; }
    .schedule-body { flex-direction: column; align-items: flex-start; gap: 10px; }
  }

  /* ─── Toggle « En service » ─── */
  .en-service-toggle { margin: 0; }
  .en-service-btn {
    width: 100%;
    display: flex; flex-direction: column; align-items: flex-start; gap: 4px;
    padding: 14px 16px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 12px;
    color: white;
    cursor: pointer;
    font-family: inherit;
    transition: .25s;
    text-align: left;
  }
  .en-service-btn:hover {
    background: rgba(255,255,255,.14);
    transform: translateY(-1px);
  }
  .en-service-toggle.is-on .en-service-btn {
    background: linear-gradient(135deg, rgba(16,185,129,.35), rgba(5,150,105,.2));
    border-color: rgba(52,211,153,.5);
    box-shadow: 0 8px 22px -10px rgba(16,185,129,.6);
  }
  .en-service-btn strong {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 16px; font-weight: 800;
    display: flex; align-items: center; gap: 10px;
  }
  .en-service-btn small {
    font-size: 11px; color: rgba(255,255,255,.65);
    font-family: inherit;
  }
  .en-service-dot {
    width: 10px; height: 10px; border-radius: 50%;
    background: rgba(255,255,255,.3);
    display: inline-block;
  }
  .en-service-toggle.is-on .en-service-dot {
    background: #34d399;
    box-shadow: 0 0 14px #34d399;
    animation: pulse 1.6s infinite;
  }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
