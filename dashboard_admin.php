<?php
// dashboard_admin.php — Vue globale de la plateforme
require_once 'config.php';
require_once 'includes/hash_chain.php';

if (!isset($_SESSION['admin_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: connexion2.php');
    exit;
}

$adminPrenom = $_SESSION['admin_prenom'] ?? '';
$adminNom    = $_SESSION['admin_nom'] ?? 'Admin';
$adminEmail  = $_SESSION['admin_email'] ?? '';
$adminFullName = trim($adminPrenom . ' ' . $adminNom);

// Stats globales
$nbPatients    = 0; $nbInfirmiers = 0; $nbDocteurs = 0;
$nbHopitaux    = 0; $nbDossiers   = 0; $nbOrdonnances = 0;
$logs = []; $derniersComptes = []; $hopitaux = [];

try {
    $nbPatients    = (int) $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    $nbInfirmiers  = (int) $pdo->query("SELECT COUNT(*) FROM infirmiers")->fetchColumn();
    $nbDocteurs    = (int) $pdo->query("SELECT COUNT(*) FROM docteurs")->fetchColumn();
    $nbHopitaux    = (int) $pdo->query("SELECT COUNT(*) FROM hopitaux")->fetchColumn();
    $nbDossiers    = (int) $pdo->query("SELECT COUNT(*) FROM dossiers_medicaux")->fetchColumn();
    $nbOrdonnances = (int) $pdo->query("SELECT COUNT(*) FROM prescriptions WHERE statut='active'")->fetchColumn();

    $logs = $pdo->query("
        SELECT * FROM logs_blockchain
        ORDER BY id DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $derniersComptes = $pdo->query("
        SELECT 'patient' AS type, CONCAT(prenom,' ',nom) AS nom_complet, email, date_inscription AS d FROM patients
        UNION ALL
        SELECT 'infirmier', CONCAT(prenom,' ',nom), email, date_inscription FROM infirmiers
        UNION ALL
        SELECT 'docteur', CONCAT(prenom,' ',nom), email, date_inscription FROM docteurs
        ORDER BY d DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    $hopitaux = $pdo->query("
        SELECT h.*,
               (SELECT COUNT(*) FROM patients   WHERE hopital_reference    = h.id) AS nb_patients,
               (SELECT COUNT(*) FROM infirmiers WHERE hopital_principal_id = h.id) AS nb_infirmiers,
               (SELECT COUNT(*) FROM docteurs   WHERE hopital_id           = h.id) AS nb_docteurs
        FROM hopitaux h
        ORDER BY h.nom
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // silencieux — tableau reste à 0
}

// Blockchain
$chainStats  = HashChain::getStats();
$chainVerify = HashChain::verifyChain();
$lastHashShort = HashChain::shortHash($chainStats['last_block_hash'] ?? '', 10, 8);

$pageTitle = 'Administration';
$pageActive = 'dashboard';
$userType = 'admin';
$userName = $adminFullName ?: 'Administrateur';
$breadcrumb = ['Admin', 'Vue globale'];

require_once 'includes/header_dashboard.php';
?>

<!-- HERO -->
<section class="dash-hero reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span>
      <?= date('H') < 12 ? 'Bonjour' : (date('H') < 18 ? 'Bon après-midi' : 'Bonsoir') ?>,
      <?= htmlspecialchars($adminPrenom ?: 'Admin') ?>
    </div>
    <h1>Pilotage national <span>de la plateforme.</span></h1>
    <p>
      <strong><?= number_format($nbPatients + $nbInfirmiers + $nbDocteurs) ?></strong> utilisateurs ·
      <strong><?= $nbHopitaux ?></strong> hôpitaux ·
      <strong><?= number_format($chainStats['total_blocks']) ?></strong> blocs inscrits dans la chaîne.
    </p>

    <div class="dash-hero-actions">
      <a href="logs.php" class="btn btn-white">
        <i class="fas fa-link"></i> Journal blockchain
      </a>
      <a href="statistiques.php" class="btn btn-ghost-w">
        <i class="fas fa-chart-bar"></i> Statistiques complètes
      </a>
    </div>
  </div>

  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Dernier bloc signé</span>
      <i class="fas fa-cube"></i>
    </div>
    <div class="dash-hero-card-hash" data-copy="<?= htmlspecialchars($chainStats['last_block_hash'] ?? '') ?>" title="Cliquer pour copier">
      <?= $chainStats['total_blocks'] > 0 ? htmlspecialchars($lastHashShort) : '— Chaîne vide —' ?>
    </div>
    <div class="dash-hero-card-meta">
      <?php if ($chainStats['last_block_time']): ?>
        <span><i class="fas fa-clock"></i> <?= date('d/m/Y · H:i', strtotime($chainStats['last_block_time'])) ?></span>
      <?php else: ?>
        <span><i class="fas fa-info-circle"></i> Aucun bloc pour le moment</span>
      <?php endif; ?>
      <span><i class="fas fa-hashtag"></i> <?= $chainStats['today'] ?> aujourd'hui</span>
    </div>
  </div>
</section>

<!-- STATUS BLOCKCHAIN -->
<section class="chain-status <?= $chainVerify['valid'] ? 'ok' : 'broken' ?> reveal-up">
  <div class="chain-status-icon">
    <?php if ($chainVerify['valid']): ?>
      <i class="fas fa-shield-check"></i>
    <?php else: ?>
      <i class="fas fa-triangle-exclamation"></i>
    <?php endif; ?>
  </div>
  <div class="chain-status-body">
    <strong>
      <?php if ($chainVerify['valid']): ?>
        Chaîne intègre · <?= $chainVerify['total'] ?> blocs vérifiés
      <?php else: ?>
        Intégrité compromise
      <?php endif; ?>
    </strong>
    <p><?= htmlspecialchars($chainVerify['message']) ?></p>
  </div>
  <div class="chain-status-meta">
    <div><span>Blocs total</span><strong><?= number_format($chainStats['total_blocks']) ?></strong></div>
    <div><span>Aujourd'hui</span><strong><?= number_format($chainStats['today']) ?></strong></div>
    <div><span>Algorithme</span><strong>SHA-256</strong></div>
  </div>
  <a href="logs.php" class="chain-status-cta">
    Consulter <i class="fas fa-arrow-right"></i>
  </a>
</section>

<!-- STATS GLOBALES (cliquables → listes détaillées) -->
<section class="stat-grid stat-grid-6">
  <a href="gestion_hopitaux.php" class="stat-card stat-card-link tilt reveal-up">
    <div class="stat-card-icon forest"><i class="fas fa-hospital"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbHopitaux ?>">0</div>
      <div class="stat-card-label">Hôpitaux</div>
    </div>
    <div class="stat-card-trend"><i class="fas fa-arrow-right"></i></div>
  </a>
  <a href="liste_utilisateurs.php?role=patient" class="stat-card stat-card-link tilt reveal-up" style="animation-delay:.05s">
    <div class="stat-card-icon emerald"><i class="fas fa-user-injured"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbPatients ?>">0</div>
      <div class="stat-card-label">Patients</div>
    </div>
    <div class="stat-card-trend"><i class="fas fa-arrow-right"></i></div>
  </a>
  <a href="liste_utilisateurs.php?role=infirmier" class="stat-card stat-card-link tilt reveal-up" style="animation-delay:.1s">
    <div class="stat-card-icon trust"><i class="fas fa-user-nurse"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbInfirmiers ?>">0</div>
      <div class="stat-card-label">Infirmiers</div>
    </div>
    <div class="stat-card-trend"><i class="fas fa-arrow-right"></i></div>
  </a>
  <a href="liste_utilisateurs.php?role=docteur" class="stat-card stat-card-link tilt reveal-up" style="animation-delay:.15s">
    <div class="stat-card-icon blockchain"><i class="fas fa-user-md"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbDocteurs ?>">0</div>
      <div class="stat-card-label">Docteurs</div>
    </div>
    <div class="stat-card-trend"><i class="fas fa-arrow-right"></i></div>
  </a>
  <a href="logs.php" class="stat-card stat-card-link tilt reveal-up" style="animation-delay:.2s">
    <div class="stat-card-icon forest"><i class="fas fa-folder-open"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbDossiers ?>">0</div>
      <div class="stat-card-label">Dossiers médicaux</div>
    </div>
    <div class="stat-card-trend"><i class="fas fa-arrow-right"></i></div>
  </a>
  <a href="statistiques.php" class="stat-card stat-card-link tilt reveal-up" style="animation-delay:.25s">
    <div class="stat-card-icon emerald"><i class="fas fa-prescription"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbOrdonnances ?>">0</div>
      <div class="stat-card-label">Ordonnances actives</div>
    </div>
    <div class="stat-card-trend"><i class="fas fa-arrow-right"></i></div>
  </a>
</section>

<style>
.stat-card-link { text-decoration:none; color:inherit; cursor:pointer; transition:.3s; }
.stat-card-link:hover { transform:translateY(-3px); box-shadow:0 16px 32px -16px rgba(15,23,42,.18); border-color:rgba(99,102,241,.25); }
.stat-card-link .stat-card-trend { color:var(--muted); font-size:13px; transition:.3s; }
.stat-card-link:hover .stat-card-trend { color:var(--blockchain); transform:translateX(4px); }
</style>

<!-- QUICK ACTIONS -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-bolt" style="color:var(--blockchain)"></i> Actions administrateur</h3>
      <p>Gérez votre plateforme en un clic</p>
    </div>
  </div>

  <div class="quick-actions quick-actions-4">
    <a href="creer_docteur.php" class="quick-action">
      <div class="quick-action-icon forest"><i class="fas fa-user-md"></i></div>
      <div>
        <strong>Nouveau docteur</strong>
        <span>Enregistrer un médecin</span>
      </div>
    </a>
    <a href="creer_infirmier.php" class="quick-action">
      <div class="quick-action-icon trust"><i class="fas fa-user-nurse"></i></div>
      <div>
        <strong>Nouvel infirmier</strong>
        <span>Créer un compte infirmier</span>
      </div>
    </a>
    <a href="gestion_hopitaux.php" class="quick-action">
      <div class="quick-action-icon emerald"><i class="fas fa-hospital"></i></div>
      <div>
        <strong>Gérer les hôpitaux</strong>
        <span><?= $nbHopitaux ?> établissement<?= $nbHopitaux > 1 ? 's' : '' ?></span>
      </div>
    </a>
    <a href="logs.php" class="quick-action">
      <div class="quick-action-icon blockchain"><i class="fas fa-link"></i></div>
      <div>
        <strong>Journal blockchain</strong>
        <span><?= number_format($chainStats['total_blocks']) ?> transactions</span>
      </div>
    </a>
  </div>
</section>

<!-- GRID 2 : COMPTES + HÔPITAUX -->
<section class="grid-2">
  <!-- DERNIERS COMPTES -->
  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-users" style="color:var(--emerald)"></i> Derniers comptes créés</h3>
        <p>Toutes catégories confondues</p>
      </div>
      <a href="liste_utilisateurs.php" class="btn btn-outline">Voir tout</a>
    </div>

    <?php if (empty($derniersComptes)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
        <h4>Aucun compte</h4>
        <p>Les nouveaux utilisateurs apparaîtront ici.</p>
      </div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($derniersComptes as $c):
          $parts = explode(' ', trim($c['nom_complet']));
          $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr(end($parts) ?: '', 0, 1));
          $typeClass = match($c['type']) {
              'patient'   => 'emerald',
              'infirmier' => 'trust',
              'docteur'   => 'forest',
              default     => 'emerald'
          };
          $badgeClass = match($c['type']) {
              'patient'   => 'badge-success',
              'infirmier' => 'badge-info',
              'docteur'   => 'badge-neutral',
              default     => 'badge-neutral'
          };
        ?>
        <div class="item-row">
          <div class="avatar-md <?= $typeClass ?>"><?= htmlspecialchars($initials) ?></div>
          <div class="item-body">
            <h4><?= htmlspecialchars($c['nom_complet']) ?></h4>
            <p><?= htmlspecialchars($c['email'] ?: '—') ?></p>
            <small><?= date('d/m/Y', strtotime($c['d'])) ?></small>
          </div>
          <span class="badge <?= $badgeClass ?>"><?= ucfirst($c['type']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- HÔPITAUX -->
  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-hospital" style="color:var(--forest)"></i> Hôpitaux</h3>
        <p>Établissements partenaires</p>
      </div>
      <a href="gestion_hopitaux.php" class="btn btn-outline">Gérer</a>
    </div>

    <?php if (empty($hopitaux)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="fas fa-hospital"></i></div>
        <h4>Aucun hôpital</h4>
        <p>Ajoutez des établissements au réseau.</p>
      </div>
    <?php else: ?>
      <div class="hopital-list">
        <?php foreach ($hopitaux as $h): ?>
          <div class="hopital-row">
            <div class="hopital-icon"><i class="fas fa-hospital"></i></div>
            <div class="hopital-body">
              <h4><?= htmlspecialchars($h['nom']) ?></h4>
              <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($h['ville'] ?? '—') ?></p>
              <div class="hopital-stats">
                <span title="Patients"><i class="fas fa-user-injured"></i> <?= $h['nb_patients'] ?></span>
                <span title="Infirmiers"><i class="fas fa-user-nurse"></i> <?= $h['nb_infirmiers'] ?></span>
                <span title="Docteurs"><i class="fas fa-user-md"></i> <?= $h['nb_docteurs'] ?></span>
              </div>
            </div>
            <span class="badge <?= ($h['statut'] ?? 'actif') === 'actif' ? 'badge-success' : 'badge-danger' ?>">
              <?= ucfirst($h['statut'] ?? 'actif') ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- LOGS RÉCENTS -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-link" style="color:var(--blockchain)"></i> Derniers blocs inscrits</h3>
      <p>Journal immuable — chaque bloc contient le hash du précédent</p>
    </div>
    <a href="logs.php" class="btn btn-outline">Chaîne complète</a>
  </div>

  <?php if (empty($logs)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-database"></i></div>
      <h4>Aucune action enregistrée</h4>
      <p>Les transactions blockchain apparaîtront ici.</p>
    </div>
  <?php else: ?>
    <div class="chain-list">
      <?php foreach ($logs as $log):
        $blockHash = $log['block_hash'] ?? $log['transaction_id'] ?? '';
        $prevHash  = $log['prev_hash'] ?? '';
        $actionClass = match($log['type_action'] ?? '') {
            'LOGIN', 'LOGIN_ADMIN'        => 'trust',
            'LOGIN_FAILED'                 => 'danger',
            'CREATE_PATIENT', 'CREATE_DOSSIER', 'CREATE_ORDONNANCE' => 'emerald',
            'UPDATE_DOSSIER', 'UPDATE_PRESCRIPTION' => 'warn',
            default                        => 'neutral'
        };
      ?>
        <div class="chain-row">
          <div class="chain-index">
            <span class="chain-block-num">#<?= $log['block_number'] ?? $log['id'] ?></span>
            <span class="chain-block-line"></span>
          </div>
          <div class="chain-body">
            <div class="chain-body-head">
              <span class="badge badge-<?= $actionClass ?>"><?= htmlspecialchars($log['type_action']) ?></span>
              <code class="chain-hash" data-copy="<?= htmlspecialchars($blockHash) ?>" title="Cliquer pour copier">
                <?= htmlspecialchars(HashChain::shortHash($blockHash, 10, 8)) ?>
              </code>
              <span class="chain-date"><?= date('d/m/Y · H:i:s', strtotime($log['timestamp_action'])) ?></span>
            </div>
            <div class="chain-body-meta">
              <span><i class="fas fa-user"></i> <?= htmlspecialchars($log['type_utilisateur']) ?> #<?= $log['utilisateur_id'] ?></span>
              <?php if (!empty($log['table_concernee'])): ?>
                <span><i class="fas fa-table"></i> <?= htmlspecialchars($log['table_concernee']) ?></span>
              <?php endif; ?>
              <span><i class="fas fa-network-wired"></i> <?= htmlspecialchars($log['ip_address'] ?? '—') ?></span>
              <?php if ($prevHash && $prevHash !== HashChain::GENESIS_HASH): ?>
                <span class="chain-prev" title="Hash du bloc précédent">
                  <i class="fas fa-link"></i> prev: <?= htmlspecialchars(HashChain::shortHash($prevHash, 6, 4)) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<style>
  /* HERO ADMIN : indigo blockchain */
  .dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #1e1b4b 0%, #4338ca 50%, #0369a1 100%);
    color: white; border-radius: 24px;
    padding: 40px;
    display: grid; grid-template-columns: 1.4fr 1fr; gap: 32px;
    align-items: center; margin-bottom: 24px;
  }
  .dash-hero::before {
    content: ''; position: absolute; top: -150px; right: -150px;
    width: 420px; height: 420px;
    background: radial-gradient(circle, rgba(139,92,246,.35), transparent 60%);
    filter: blur(80px);
  }
  .dash-hero::after {
    content: ''; position: absolute; bottom: -120px; left: -120px;
    width: 320px; height: 320px;
    background: radial-gradient(circle, rgba(56,189,248,.28), transparent 60%);
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
    background: #a78bfa; box-shadow: 0 0 12px #a78bfa;
    animation: pulse 2s infinite;
  }
  .dash-hero h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 36px; font-weight: 800; line-height: 1.15;
    letter-spacing: -.02em;
  }
  .dash-hero h1 span {
    display: block;
    background: linear-gradient(135deg, #c4b5fd, #7dd3fc);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .dash-hero p { font-size: 15px; color: rgba(255,255,255,.85); margin-top: 12px; line-height: 1.6; }
  .dash-hero p strong { color: #fff; font-weight: 700; }
  .dash-hero-actions { display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap; }

  .btn-white { background: white; color: #4338ca; border: none; font-weight: 700; }
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
  .dash-hero-card-head i { color: #c4b5fd; font-size: 18px; }
  .dash-hero-card-hash {
    font-family: 'JetBrains Mono', monospace;
    font-size: 14px; font-weight: 600; color: #c4b5fd;
    padding: 12px 14px; background: rgba(139,92,246,.12);
    border: 1px solid rgba(139,92,246,.3); border-radius: 10px;
    cursor: pointer; transition: .3s; word-break: break-all;
  }
  .dash-hero-card-hash:hover { background: rgba(139,92,246,.22); border-color: rgba(139,92,246,.5); }
  .dash-hero-card-meta {
    display: flex; flex-direction: column; gap: 4px;
    margin-top: 12px; font-size: 12px; color: rgba(255,255,255,.7);
  }
  .dash-hero-card-meta i { color: #c4b5fd; margin-right: 6px; }

  /* CHAIN STATUS BANNER */
  .chain-status {
    display: grid;
    grid-template-columns: auto 1fr auto auto;
    gap: 20px; align-items: center;
    padding: 20px 24px; border-radius: 16px;
    margin-bottom: 24px;
  }
  .chain-status.ok {
    background: linear-gradient(135deg, rgba(16,185,129,.08), rgba(3,105,161,.04));
    border: 1px solid rgba(16,185,129,.25);
  }
  .chain-status.broken {
    background: linear-gradient(135deg, rgba(239,68,68,.08), rgba(249,115,22,.04));
    border: 1px solid rgba(239,68,68,.35);
  }
  .chain-status-icon {
    width: 52px; height: 52px; border-radius: 14px;
    display: grid; place-items: center;
    color: white; font-size: 22px;
  }
  .chain-status.ok .chain-status-icon { background: linear-gradient(135deg, #10b981, #059669); }
  .chain-status.broken .chain-status-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }
  .chain-status-body strong {
    display: block; font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 2px;
  }
  .chain-status-body p { font-size: 13px; color: var(--muted); }
  .chain-status-meta {
    display: flex; gap: 18px;
    padding: 0 18px; border-left: 1px solid var(--line);
  }
  .chain-status-meta > div { display: flex; flex-direction: column; gap: 2px; text-align: center; }
  .chain-status-meta span {
    font-size: 10px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .05em;
  }
  .chain-status-meta strong {
    font-family: 'JetBrains Mono', monospace;
    font-size: 16px; font-weight: 700; color: var(--ink);
  }
  .chain-status-cta {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 16px; border-radius: 10px;
    background: #4338ca; color: white;
    font-size: 13px; font-weight: 700;
    text-decoration: none; transition: .3s;
  }
  .chain-status-cta:hover { background: #3730a3; transform: translateX(2px); }

  /* STAT GRID (6 col on admin) */
  .stat-grid {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 16px; margin-bottom: 24px;
  }
  .stat-grid-6 { grid-template-columns: repeat(6, 1fr); }
  .stat-card {
    display: flex; align-items: center; gap: 14px;
    padding: 20px; background: white;
    border: 1px solid var(--line); border-radius: 16px;
    transition: .3s; position: relative; overflow: hidden;
  }
  .stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: var(--g-blockchain); transform: scaleX(0); transform-origin: left;
    transition: transform .4s;
  }
  .stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px -16px rgba(15,23,42,.15);
    border-color: rgba(99,102,241,.2);
  }
  .stat-card:hover::before { transform: scaleX(1); }
  .stat-card-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: grid; place-items: center; color: white;
    font-size: 18px; flex-shrink: 0;
  }
  .stat-card-icon.forest    { background: var(--g-forest); }
  .stat-card-icon.emerald   { background: var(--g-emerald); }
  .stat-card-icon.trust     { background: var(--g-trust); }
  .stat-card-icon.blockchain{ background: var(--g-blockchain); }
  .stat-card-body { flex: 1; min-width: 0; }
  .stat-card-value {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 26px; font-weight: 800; color: var(--ink); line-height: 1;
  }
  .stat-card-label { font-size: 12px; color: var(--muted); margin-top: 4px; }

  /* CARD SHELL */
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

  /* QUICK ACTIONS 4 col */
  .quick-actions {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 12px;
  }
  .quick-action {
    display: flex; align-items: center; gap: 14px;
    padding: 18px; background: #f8fafc;
    border: 1px solid transparent; border-radius: 14px;
    text-decoration: none; transition: .3s;
  }
  .quick-action:hover {
    background: white; border-color: var(--line);
    transform: translateY(-2px);
    box-shadow: 0 10px 24px -10px rgba(15,23,42,.12);
  }
  .quick-action-icon {
    width: 42px; height: 42px; border-radius: 12px;
    display: grid; place-items: center; color: white;
    font-size: 16px; flex-shrink: 0;
  }
  .quick-action-icon.forest    { background: var(--g-forest); }
  .quick-action-icon.emerald   { background: var(--g-emerald); }
  .quick-action-icon.trust     { background: var(--g-trust); }
  .quick-action-icon.blockchain{ background: var(--g-blockchain); }
  .quick-action strong {
    display: block; font-size: 14px; color: var(--ink); margin-bottom: 2px;
  }
  .quick-action span { display: block; font-size: 12px; color: var(--muted); }

  /* GRID 2 */
  .grid-2 {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 24px; margin-bottom: 24px;
  }

  /* BADGES */
  .badge {
    display: inline-block; padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
  }
  .badge-success { background: #d1fae5; color: #065f46; }
  .badge-info    { background: #dbeafe; color: #1e40af; }
  .badge-warn    { background: #fef3c7; color: #92400e; }
  .badge-danger  { background: #fee2e2; color: #991b1b; }
  .badge-neutral { background: #f1f5f9; color: #475569; }
  .badge-trust   { background: #e0f2fe; color: #075985; }
  .badge-emerald { background: #d1fae5; color: #065f46; }

  /* ITEM LIST */
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
  .item-body { flex: 1; min-width: 0; }
  .item-body h4 { font-size: 14px; font-weight: 600; color: var(--ink); margin-bottom: 2px; }
  .item-body p { font-size: 13px; color: var(--muted); margin-bottom: 2px; word-break: break-all; }
  .item-body small { font-size: 11px; color: #94a3b8; }

  .avatar-md {
    width: 44px; height: 44px; border-radius: 14px;
    display: grid; place-items: center;
    color: white; font-size: 13px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif; flex-shrink: 0;
  }
  .avatar-md.forest  { background: var(--g-forest); }
  .avatar-md.emerald { background: var(--g-emerald); }
  .avatar-md.trust   { background: var(--g-trust); }
  .avatar-md.blockchain { background: var(--g-blockchain); }

  /* HOPITAUX */
  .hopital-list { display: flex; flex-direction: column; gap: 10px; }
  .hopital-row {
    display: flex; align-items: center; gap: 14px;
    padding: 14px; background: #f8fafc;
    border: 1px solid transparent; border-radius: 12px; transition: .3s;
  }
  .hopital-row:hover { background: white; border-color: var(--line); }
  .hopital-icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: var(--g-forest); color: white;
    display: grid; place-items: center; font-size: 16px;
    flex-shrink: 0;
  }
  .hopital-body { flex: 1; min-width: 0; }
  .hopital-body h4 { font-size: 14px; font-weight: 700; color: var(--ink); }
  .hopital-body p { font-size: 12px; color: var(--muted); margin-top: 2px; }
  .hopital-body p i { color: var(--forest); margin-right: 4px; }
  .hopital-stats {
    display: flex; gap: 12px; margin-top: 6px;
    font-size: 12px; color: var(--muted);
  }
  .hopital-stats span i { color: var(--forest); margin-right: 3px; }

  /* CHAIN LIST (logs) */
  .chain-list { display: flex; flex-direction: column; gap: 0; }
  .chain-row {
    display: grid; grid-template-columns: 80px 1fr;
    gap: 16px; padding: 14px 0;
    border-bottom: 1px dashed var(--line);
  }
  .chain-row:last-child { border-bottom: none; }
  .chain-index {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    position: relative;
  }
  .chain-block-num {
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px; font-weight: 700; color: var(--blockchain);
    padding: 4px 10px; background: rgba(99,102,241,.1);
    border-radius: 8px;
  }
  .chain-block-line {
    flex: 1; width: 2px;
    background: repeating-linear-gradient(to bottom, var(--line) 0, var(--line) 3px, transparent 3px, transparent 6px);
    min-height: 12px;
  }
  .chain-body { flex: 1; min-width: 0; }
  .chain-body-head {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    margin-bottom: 6px;
  }
  .chain-hash {
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px; color: var(--blockchain);
    padding: 3px 8px; background: rgba(99,102,241,.08);
    border: 1px solid rgba(99,102,241,.15);
    border-radius: 6px; cursor: pointer; transition: .2s;
  }
  .chain-hash:hover { background: rgba(99,102,241,.15); }
  .chain-date {
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px; color: var(--muted); margin-left: auto;
  }
  .chain-body-meta {
    display: flex; gap: 12px; flex-wrap: wrap;
    font-size: 12px; color: var(--muted);
  }
  .chain-body-meta i { color: var(--muted); margin-right: 4px; }
  .chain-prev {
    font-family: 'JetBrains Mono', monospace;
    padding: 2px 8px; background: #f1f5f9;
    border-radius: 6px; font-size: 11px;
  }

  /* EMPTY */
  .empty { text-align: center; padding: 40px 20px; }
  .empty-icon {
    width: 72px; height: 72px; margin: 0 auto 16px;
    background: rgba(99,102,241,.08); border-radius: 50%;
    display: grid; place-items: center; color: var(--blockchain); font-size: 28px;
  }
  .empty h4 { font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
  .empty p { font-size: 13px; color: var(--muted); }

  .btn-outline {
    padding: 8px 14px; background: transparent;
    border: 1px solid var(--line); border-radius: 10px;
    font-size: 13px; font-weight: 600; color: var(--ink);
    text-decoration: none; transition: .3s; display: inline-flex; align-items: center; gap: 6px;
  }
  .btn-outline:hover { border-color: var(--blockchain); color: var(--blockchain); }

  @keyframes pulse {
    0%, 100% { opacity: 1; } 50% { opacity: .4; }
  }

  @media (max-width: 1300px) {
    .stat-grid-6 { grid-template-columns: repeat(3, 1fr); }
    .quick-actions { grid-template-columns: repeat(2, 1fr); }
  }
  @media (max-width: 1100px) {
    .chain-status { grid-template-columns: auto 1fr; row-gap: 14px; }
    .chain-status-meta { grid-column: 1 / -1; border-left: none; padding: 0; border-top: 1px solid var(--line); padding-top: 14px; }
    .chain-status-cta { grid-column: 1 / -1; justify-content: center; }
  }
  @media (max-width: 960px) {
    .dash-hero { grid-template-columns: 1fr; padding: 32px 24px; }
    .dash-hero h1 { font-size: 28px; }
    .grid-2 { grid-template-columns: 1fr; }
    .stat-grid-6 { grid-template-columns: repeat(2, 1fr); }
    .quick-actions { grid-template-columns: 1fr; }
  }
  @media (max-width: 560px) {
    .stat-grid-6 { grid-template-columns: 1fr; }
    .dash-card { padding: 20px; }
    .chain-row { grid-template-columns: 60px 1fr; gap: 10px; }
    .chain-status-meta { flex-direction: column; gap: 8px; }
  }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
