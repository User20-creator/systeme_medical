<?php
require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_extended_columns($pdo);

if (!isset($_SESSION['medecin_id']) || $_SESSION['user_type'] !== 'infirmier') {
    header('Location: connexion2.php');
    exit;
}

$infirmier_id = $_SESSION['medecin_id'];

// Flashes posés par envoyer_au_docteur.php (ou par creer_patient.php)
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$infirmier_nom = $_SESSION['medecin_nom'] ?? '';
$infirmier_prenom = $_SESSION['medecin_prenom'] ?? '';
$infirmier_specialite = $_SESSION['medecin_specialite'] ?? '';
$infirmier_licence = $_SESSION['medecin_numero_licence'] ?? '';
$infirmier_email = $_SESSION['medecin_email'] ?? '';
$infirmier_hopital = $_SESSION['medecin_hopital_principal_id'] ?? null;
$infirmier_blockchain = $_SESSION['medecin_identifiant_blockchain'] ?? '';

$total_patients_enregistres = 0;
$patients_aujourdhui = 0;
$total_dossiers = 0;
$recent_patients = [];
$recent_dossiers = [];
$hopital_nom = '';

try {
    if ($infirmier_hopital) {
        $stmt = $pdo->prepare("SELECT nom FROM hopitaux WHERE id = ?");
        $stmt->execute([$infirmier_hopital]);
        $hopital_nom = $stmt->fetchColumn() ?: '';
    }

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM dossiers_medicaux WHERE cree_par_infirmier = ?");
    $stmt->execute([$infirmier_id]);
    $total_patients_enregistres = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dossiers_medicaux WHERE cree_par_infirmier = ? AND DATE(date_creation) = CURDATE()");
    $stmt->execute([$infirmier_id]);
    $patients_aujourdhui = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dossiers_medicaux WHERE cree_par_infirmier = ?");
    $stmt->execute([$infirmier_id]);
    $total_dossiers = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT DISTINCT p.id, p.nom, p.prenom, p.telephone, p.date_naissance,
               p.groupe_sanguin, p.identifiant_blockchain, MAX(dm.date_creation) AS derniere_visite
        FROM dossiers_medicaux dm
        JOIN patients p ON p.id = dm.patient_id
        WHERE dm.cree_par_infirmier = ?
        GROUP BY p.id
        ORDER BY derniere_visite DESC
        LIMIT 5
    ");
    $stmt->execute([$infirmier_id]);
    $recent_patients = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT dm.*, p.nom, p.prenom, h.nom AS hopital_nom
        FROM dossiers_medicaux dm
        JOIN patients p ON p.id = dm.patient_id
        LEFT JOIN hopitaux h ON h.id = dm.hopital_id
        WHERE dm.cree_par_infirmier = ?
        ORDER BY dm.date_creation DESC
        LIMIT 6
    ");
    $stmt->execute([$infirmier_id]);
    $recent_dossiers = $stmt->fetchAll();
} catch (PDOException $e) {
    // silent
}

$pageTitle = 'Tableau de bord infirmier';
$pageActive = 'dashboard';
$userType = 'infirmier';
$userName = $infirmier_prenom . ' ' . $infirmier_nom;

require_once 'includes/header_dashboard.php';
?>

<?php if ($flash_success): ?>
  <div class="alert alert-success reveal-up" style="display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:12px;background:#d1fae5;color:#065f46;border:1px solid rgba(16,185,129,.3);margin-bottom:20px;font-size:14px;font-weight:600">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?>
  </div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error reveal-up" style="display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:12px;background:#fee2e2;color:#991b1b;border:1px solid rgba(239,68,68,.3);margin-bottom:20px;font-size:14px;font-weight:600">
    <i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($flash_error) ?>
  </div>
<?php endif; ?>

<!-- HERO -->
<section class="dash-hero reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span>
      <?= date('H') < 12 ? 'Bonjour' : (date('H') < 18 ? 'Bon après-midi' : 'Bonsoir') ?>, <?= htmlspecialchars($infirmier_prenom) ?>
    </div>
    <h1>Accueil et <span>enregistrement patient.</span></h1>
    <p>
      <?php if ($patients_aujourdhui): ?>
        <strong><?= $patients_aujourdhui ?></strong> dossier<?= $patients_aujourdhui > 1 ? 's' : '' ?> créé<?= $patients_aujourdhui > 1 ? 's' : '' ?> aujourd'hui.
      <?php else: ?>
        Aucun dossier créé aujourd'hui.
      <?php endif; ?>
      <?= $hopital_nom ? '· ' . htmlspecialchars($hopital_nom) : '' ?>
    </p>

    <div class="dash-hero-actions">
      <a href="creer_patient.php" class="btn btn-white">
        <i class="fas fa-user-plus"></i> Enregistrer un patient
      </a>
      <a href="liste_utilisateurs.php" class="btn btn-ghost-w">
        <i class="fas fa-users"></i> Liste des patients
      </a>
    </div>
  </div>

  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Identifiant infirmier</span>
      <i class="fas fa-user-nurse"></i>
    </div>
    <div class="dash-hero-card-hash" data-copy="<?= htmlspecialchars($infirmier_blockchain) ?>" title="Copier">
      <?= htmlspecialchars($infirmier_blockchain ? HashChain::shortHash($infirmier_blockchain, 8, 6) : '—') ?>
    </div>
    <div class="dash-hero-card-meta">
      <span><i class="fas fa-id-card"></i> Licence <?= htmlspecialchars($infirmier_licence ?: '—') ?></span>
    </div>
  </div>
</section>

<!-- STATS -->
<section class="stat-grid">
  <div class="stat-card tilt reveal-up">
    <div class="stat-card-icon forest"><i class="fas fa-user-plus"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $total_patients_enregistres ?>">0</div>
      <div class="stat-card-label">Patients enregistrés</div>
    </div>
  </div>

  <div class="stat-card tilt reveal-up" style="animation-delay:.05s">
    <div class="stat-card-icon emerald"><i class="fas fa-calendar-day"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $patients_aujourdhui ?>">0</div>
      <div class="stat-card-label">Aujourd'hui</div>
    </div>
  </div>

  <div class="stat-card tilt reveal-up" style="animation-delay:.1s">
    <div class="stat-card-icon trust"><i class="fas fa-folder-open"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $total_dossiers ?>">0</div>
      <div class="stat-card-label">Dossiers créés</div>
    </div>
  </div>

  <div class="stat-card tilt reveal-up" style="animation-delay:.15s">
    <div class="stat-card-icon blockchain"><i class="fas fa-link"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $total_dossiers ?>">0</div>
      <div class="stat-card-label">Blocs signés</div>
    </div>
  </div>
</section>

<!-- ACTIONS RAPIDES -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-bolt" style="color:var(--emerald)"></i> Actions rapides</h3>
      <p>Tâches quotidiennes de l'accueil</p>
    </div>
  </div>

  <div class="quick-actions">
    <a href="accueil_dossier.php" class="quick-action">
      <div class="quick-action-icon trust"><i class="fas fa-clipboard-user"></i></div>
      <div>
        <strong>Enregistrer une arrivée</strong>
        <span>Motif, constantes, urgence</span>
      </div>
    </a>

    <a href="creer_patient.php" class="quick-action">
      <div class="quick-action-icon forest"><i class="fas fa-user-plus"></i></div>
      <div>
        <strong>Nouveau patient</strong>
        <span>Créer un dossier citoyen</span>
      </div>
    </a>

    <a href="liste_utilisateurs.php" class="quick-action">
      <div class="quick-action-icon emerald"><i class="fas fa-users"></i></div>
      <div>
        <strong>Rechercher patient</strong>
        <span>Trouver un dossier</span>
      </div>
    </a>

    <a href="creer_infirmier.php" class="quick-action">
      <div class="quick-action-icon blockchain"><i class="fas fa-user-nurse"></i></div>
      <div>
        <strong>Ajouter collègue</strong>
        <span>Nouvel infirmier</span>
      </div>
    </a>
  </div>
</section>

<!-- GRID 2 -->
<section class="grid-2">
  <!-- Recent patients -->
  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-user-friends" style="color:var(--forest)"></i> Patients récents</h3>
        <p>Dossiers créés ces derniers temps</p>
      </div>
      <a href="liste_utilisateurs.php" class="btn btn-outline">Voir tout</a>
    </div>

    <?php if (empty($recent_patients)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
        <h4>Aucun patient enregistré</h4>
        <p>Commencez par enregistrer votre premier patient.</p>
        <a href="creer_patient.php" class="btn btn-primary" style="margin-top:12px;">
          <i class="fas fa-user-plus"></i> Nouveau patient
        </a>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($recent_patients as $p):
          $age = $p['date_naissance'] ? date_diff(date_create($p['date_naissance']), date_create('today'))->y : null;
        ?>
        <div class="item-row">
          <div class="avatar-md forest"><?= strtoupper(substr($p['prenom'], 0, 1) . substr($p['nom'], 0, 1)) ?></div>
          <div class="item-body">
            <h4><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></h4>
            <p>
              <?= $age !== null ? "$age ans" : 'Âge inconnu' ?>
              <?= !empty($p['groupe_sanguin']) ? '· Gr. ' . htmlspecialchars($p['groupe_sanguin']) : '' ?>
            </p>
            <small>Dernière visite : <?= date('d/m/Y', strtotime($p['derniere_visite'])) ?></small>
          </div>
          <div class="item-actions">
            <form method="POST" action="envoyer_au_docteur.php" style="display:inline" onsubmit="return confirm('Envoyer ce patient au docteur en service ?');">
              <?= csrf_field() ?>
              <input type="hidden" name="patient_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="redirect" value="dashboard_infirmier.php">
              <button type="submit" class="btn-send-doc" title="Envoyer au docteur en service">
                <i class="fas fa-user-doctor"></i>
                <span>Envoyer au docteur</span>
              </button>
            </form>
            <a href="dossier_patient.php?id=<?= $p['id'] ?>" class="btn-ic" title="Voir dossier">
              <i class="fas fa-folder-open"></i>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recent dossiers -->
  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-history" style="color:var(--trust)"></i> Activité récente</h3>
        <p>Derniers dossiers que vous avez créés</p>
      </div>
    </div>

    <?php if (empty($recent_dossiers)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="fas fa-folder-open"></i></div>
        <h4>Aucune activité</h4>
        <p>Votre activité récente apparaîtra ici.</p>
      </div>
    <?php else: ?>
      <div class="activity-list">
        <?php foreach ($recent_dossiers as $d): ?>
        <div class="activity-item">
          <div class="activity-dot"></div>
          <div class="activity-body">
            <div class="activity-head">
              <strong><?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?></strong>
              <span class="activity-time"><?= date('d/m H:i', strtotime($d['date_creation'])) ?></span>
            </div>
            <p><?= htmlspecialchars($d['motif_visite'] ?? 'Consultation') ?></p>
            <small><?= htmlspecialchars($d['hopital_nom'] ?? '—') ?></small>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- MON PROFIL -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-id-badge" style="color:var(--forest)"></i> Mon profil</h3>
      <p>Identité professionnelle</p>
    </div>
    <a href="mon_profil.php" class="btn btn-outline">
      <i class="fas fa-edit"></i> Modifier
    </a>
  </div>

  <div class="doctor-card">
    <div class="doctor-card-avatar" style="background:linear-gradient(135deg,#0ea5e9,#0369a1)">
      <?= strtoupper(substr($infirmier_prenom, 0, 1) . substr($infirmier_nom, 0, 1)) ?>
    </div>
    <div class="doctor-card-info">
      <h3><?= htmlspecialchars($infirmier_prenom . ' ' . $infirmier_nom) ?></h3>
      <p><?= htmlspecialchars($infirmier_specialite ?: 'Infirmier·ère') ?></p>
      <div class="doctor-card-meta">
        <span><i class="fas fa-id-card"></i> Licence <?= htmlspecialchars($infirmier_licence ?: '—') ?></span>
        <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($infirmier_email) ?></span>
        <?php if ($hopital_nom): ?>
          <span><i class="fas fa-hospital"></i> <?= htmlspecialchars($hopital_nom) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="hash-block">
    <div class="hash-block-icon"><i class="fas fa-cube"></i></div>
    <div class="hash-block-body">
      <strong>Identifiant Blockchain</strong>
      <code data-copy="<?= htmlspecialchars($infirmier_blockchain) ?>" title="Copier"><?= htmlspecialchars($infirmier_blockchain) ?></code>
    </div>
    <div class="hash-block-verified">
      <i class="fas fa-check-circle"></i>
      <span>Vérifié</span>
    </div>
  </div>
</section>

<style>
  .dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #0369a1 0%, #0e2a1a 60%, #1a472a 100%);
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

  .btn-white { background: white; color: var(--trust); border: none; }
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
  .dash-hero-card-hash:hover { background: rgba(56,189,248,.18); }
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
    background: var(--g-trust); transform: scaleX(0); transform-origin: left;
    transition: transform .4s;
  }
  .stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px -16px rgba(15,23,42,.15);
    border-color: rgba(3,105,161,.2);
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

  .grid-2 {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 24px; margin-bottom: 24px;
  }

  .quick-actions {
    display: grid; grid-template-columns: repeat(4, 1fr);
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
  .quick-action strong { display: block; font-size: 14px; color: var(--ink); margin-bottom: 2px; }
  .quick-action span { display: block; font-size: 12px; color: var(--muted); }

  .empty { text-align: center; padding: 40px 20px; }
  .empty-icon {
    width: 72px; height: 72px; margin: 0 auto 16px;
    background: rgba(3,105,161,.06); border-radius: 50%;
    display: grid; place-items: center; color: var(--trust); font-size: 28px;
  }
  .empty h4 { font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
  .empty p { font-size: 13px; color: var(--muted); }

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
  .avatar-md {
    width: 44px; height: 44px; border-radius: 14px;
    display: grid; place-items: center;
    color: white; font-size: 14px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif; flex-shrink: 0;
  }
  .avatar-md.forest { background: var(--g-forest); }
  .item-body { flex: 1; min-width: 0; }
  .item-body h4 { font-size: 14px; font-weight: 600; color: var(--ink); margin-bottom: 2px; }
  .item-body p { font-size: 13px; color: var(--muted); margin-bottom: 2px; }
  .item-body small { font-size: 11px; color: #94a3b8; }

  .btn-ic {
    width: 36px; height: 36px; border-radius: 10px;
    background: rgba(3,105,161,.08); color: var(--trust);
    display: inline-grid; place-items: center;
    text-decoration: none; border: none; cursor: pointer; transition: .3s;
  }
  .btn-ic:hover { background: var(--trust); color: white; }

  /* Actions sur item-row */
  .item-actions {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0;
  }
  .btn-send-doc {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 14px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white; border: none; border-radius: 10px;
    font-size: 12px; font-weight: 700;
    cursor: pointer; transition: .25s;
    font-family: inherit;
    box-shadow: 0 6px 14px -6px rgba(16,185,129,.5);
  }
  .btn-send-doc:hover { transform: translateY(-2px); box-shadow: 0 10px 18px -6px rgba(16,185,129,.6); }
  @media (max-width: 720px) {
    .btn-send-doc span { display: none; }
    .btn-send-doc { padding: 8px 10px; }
  }

  /* Activity timeline */
  .activity-list { display: flex; flex-direction: column; gap: 0; }
  .activity-item {
    display: flex; gap: 16px; padding: 12px 0;
    position: relative;
  }
  .activity-item:not(:last-child)::after {
    content: ''; position: absolute; left: 5px; top: 24px; bottom: -4px;
    width: 2px; background: #e2e8f0;
  }
  .activity-dot {
    width: 12px; height: 12px; border-radius: 50%;
    background: var(--trust); border: 3px solid white;
    box-shadow: 0 0 0 2px var(--trust);
    margin-top: 6px; flex-shrink: 0; z-index: 1; position: relative;
  }
  .activity-body { flex: 1; }
  .activity-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 4px;
  }
  .activity-head strong { font-size: 14px; color: var(--ink); }
  .activity-time { font-size: 12px; color: var(--muted); font-family: 'JetBrains Mono', monospace; }
  .activity-body p { font-size: 13px; color: var(--muted); margin-bottom: 2px; }
  .activity-body small { font-size: 11px; color: #94a3b8; }

  .doctor-card {
    display: flex; align-items: center; gap: 18px;
    padding: 20px; background: linear-gradient(135deg, rgba(3,105,161,.04), rgba(56,189,248,.04));
    border: 1px solid rgba(3,105,161,.1); border-radius: 16px;
    margin-bottom: 16px;
  }
  .doctor-card-avatar {
    width: 64px; height: 64px; border-radius: 16px;
    display: grid; place-items: center;
    color: white; font-size: 20px; font-weight: 800;
    font-family: 'Plus Jakarta Sans', sans-serif; flex-shrink: 0;
    box-shadow: 0 10px 24px -8px rgba(3,105,161,.3);
  }
  .doctor-card-info h3 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 18px; font-weight: 700; color: var(--ink); margin-bottom: 2px;
  }
  .doctor-card-info p {
    font-size: 14px; color: var(--trust); font-weight: 600; margin-bottom: 8px;
  }
  .doctor-card-meta {
    display: flex; flex-direction: column; gap: 4px;
    font-size: 12px; color: var(--muted);
  }
  .doctor-card-meta i { color: var(--trust); margin-right: 6px; }

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
    .quick-actions { grid-template-columns: repeat(2, 1fr); }
  }

  @media (max-width: 960px) {
    .dash-hero { grid-template-columns: 1fr; padding: 32px 24px; }
    .dash-hero h1 { font-size: 28px; }
    .grid-2 { grid-template-columns: 1fr; }
  }

  @media (max-width: 560px) {
    .stat-grid, .quick-actions { grid-template-columns: 1fr; }
    .dash-card { padding: 20px; }
    .hash-block { flex-direction: column; align-items: flex-start; }
  }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
