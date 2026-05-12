<?php
// mes_rendezvous.php — Renommé en "Mon journal de consultations"
// La feature "rendez-vous" n'existe pas dans le schéma. Cette page liste
// désormais l'historique des dossiers signés par le médecin connecté.
require_once 'config.php';
require_once 'includes/hash_chain.php';

if (!isset($_SESSION['medecin_id']) || !in_array($_SESSION['user_type'] ?? '', ['medecin', 'docteur'])) {
    header('Location: connexion2.php'); exit;
}
$medecin_id = (int)$_SESSION['medecin_id'];

// Filtre par période
$filterPeriode = $_GET['periode'] ?? 'tous';
$filterType    = $_GET['type']    ?? 'all';

$where = ["dm.modifie_par_docteur = ?"];
$params = [$medecin_id];

if      ($filterPeriode === 'aujourdhui') { $where[] = "DATE(dm.date_creation) = CURDATE()"; }
elseif  ($filterPeriode === 'semaine')    { $where[] = "dm.date_creation >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; }
elseif  ($filterPeriode === 'mois')       { $where[] = "dm.date_creation >= DATE_SUB(NOW(), INTERVAL 30 DAY)"; }

if ($filterType !== 'all') {
    $where[] = "dm.type_document = ?";
    $params[] = $filterType;
}

$consultations = [];
try {
    $sql = "
      SELECT dm.id, dm.titre, dm.type_document, dm.motif_visite,
             dm.date_creation, dm.tension, dm.poids, dm.temperature,
             p.id AS patient_id, p.prenom AS p_prenom, p.nom AS p_nom,
             p.telephone, p.NPI
        FROM dossiers_medicaux dm
        JOIN patients p ON p.id = dm.patient_id
       WHERE " . implode(' AND ', $where) . "
    ORDER BY dm.date_creation DESC
       LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('mes_rendezvous: ' . $e->getMessage());
    $consultations = [];
}

// Stats
$stats = ['total' => 0, 'jour' => 0, 'semaine' => 0];
try {
    $row = $pdo->prepare("
      SELECT
        COUNT(*) AS total,
        SUM(DATE(date_creation) = CURDATE()) AS jour,
        SUM(date_creation >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS semaine
      FROM dossiers_medicaux
      WHERE modifie_par_docteur = ?
    ");
    $row->execute([$medecin_id]);
    $stats = array_merge($stats, $row->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (PDOException $e) { /* silencieux */ }

// Grouper par date pour affichage
$grouped = [];
foreach ($consultations as $c) {
    $d = substr($c['date_creation'], 0, 10);
    $grouped[$d][] = $c;
}

$pageTitle  = 'Mon journal';
$pageActive = 'journal';
$breadcrumb = ['Médecin', 'Journal'];
require_once 'includes/header_dashboard.php';
?>

<section class="dash-hero dash-hero-compact reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span> Journal médical
    </div>
    <h1>Mon historique <span>de consultations.</span></h1>
    <p>
      <strong><?= (int)$stats['total'] ?></strong> consultation<?= (int)$stats['total'] > 1 ? 's' : '' ?> ·
      <strong><?= (int)$stats['jour'] ?></strong> aujourd'hui ·
      <strong><?= (int)$stats['semaine'] ?></strong> cette semaine
    </p>
  </div>
  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Aujourd'hui</span>
      <i class="fas fa-calendar-day"></i>
    </div>
    <div class="today-big">
      <strong><?= date('d') ?></strong>
      <span><?= date('F Y') ?></span>
    </div>
    <p class="today-day"><?= date('l') ?></p>
  </div>
</section>

<!-- Filtres -->
<section class="dash-card reveal-up">
  <div class="tabs-row">
    <?php
      $periodes = [
        'tous'       => ['Tous', 'fa-list'],
        'aujourdhui' => ["Aujourd'hui", 'fa-calendar-day'],
        'semaine'    => ['7 derniers jours', 'fa-calendar-week'],
        'mois'       => ['30 derniers jours', 'fa-calendar'],
      ];
      foreach ($periodes as $k => [$lbl, $ic]):
        $qs = $_GET; $qs['periode'] = $k;
        $active = $filterPeriode === $k;
    ?>
      <a href="?<?= http_build_query($qs) ?>" class="tab <?= $active ? 'active' : '' ?>">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
      </a>
    <?php endforeach; ?>

    <span class="tabs-sep"></span>

    <?php
      $types = [
        'all'            => 'Tous',
        'consultation'   => 'Consultations',
        'analyse'        => 'Analyses',
        'radio'          => 'Radios',
        'ordonnance'     => 'Ordonnances',
        'hospitalisation'=> 'Hospitalisations',
        'vaccin'         => 'Vaccins',
      ];
      foreach ($types as $k => $lbl):
        $qs = $_GET; $qs['type'] = $k;
        $active = $filterType === $k;
    ?>
      <a href="?<?= http_build_query($qs) ?>" class="tab-mini <?= $active ? 'active' : '' ?>">
        <?= $lbl ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- Liste -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-clock-rotate-left" style="color:var(--forest)"></i>
        <?= count($consultations) ?> consultation<?= count($consultations) > 1 ? 's' : '' ?>
      </h3>
      <p>Ordre chronologique inverse</p>
    </div>
  </div>

  <?php if (empty($consultations)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="far fa-calendar"></i></div>
      <h4>Aucune consultation</h4>
      <p>Aucun dossier signé ne correspond à ces filtres.</p>
    </div>
  <?php else: ?>
    <?php foreach ($grouped as $date => $items):
      $dateObj = date_create($date);
    ?>
      <div class="day-block">
        <div class="day-head">
          <strong><?= htmlspecialchars(strftime('%A %d %B %Y', strtotime($date)) ?: date('d/m/Y', strtotime($date))) ?></strong>
          <span class="pill-count"><?= count($items) ?></span>
        </div>
        <div class="cons-list">
          <?php foreach ($items as $c): ?>
            <a href="dossier_patient.php?id=<?= (int)$c['id'] ?>" class="cons-row">
              <div class="cons-time">
                <strong><?= date('H:i', strtotime($c['date_creation'])) ?></strong>
              </div>
              <div class="cons-body">
                <h4><?= htmlspecialchars($c['p_prenom'] . ' ' . $c['p_nom']) ?></h4>
                <p><?= htmlspecialchars($c['titre']) ?></p>
                <small>
                  <?= htmlspecialchars(ucfirst($c['type_document'])) ?>
                  <?= !empty($c['motif_visite']) ? ' · ' . htmlspecialchars($c['motif_visite']) : '' ?>
                </small>
              </div>
              <div class="cons-meta">
                <span class="badge badge-success"><?= htmlspecialchars(ucfirst($c['type_document'])) ?></span>
                <i class="fas fa-arrow-right"></i>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<style>
  .dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #1a472a 0%, #0e2a1a 60%, #0369a1 100%);
    color: white; border-radius: 24px; padding: 28px 36px;
    display: grid; grid-template-columns: 1.4fr 1fr; gap: 28px;
    align-items: center; margin-bottom: 24px;
  }
  .dash-hero-content { position: relative; z-index: 1; }
  .dash-hero-greet {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 5px 12px; background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.15); border-radius: 999px;
    font-size: 12px; font-weight: 600; margin-bottom: 12px;
  }
  .dash-hero-dot { width: 7px; height: 7px; border-radius: 50%; background: #38bdf8; box-shadow: 0 0 12px #38bdf8; }
  .dash-hero h1 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 28px; font-weight: 800; line-height: 1.15; }
  .dash-hero h1 span { background: linear-gradient(135deg, #38bdf8, #86efac); -webkit-background-clip: text; background-clip: text; color: transparent; }
  .dash-hero p { font-size: 14px; color: rgba(255,255,255,.8); margin-top: 10px; }
  .dash-hero p strong { color: #86efac; }

  .dash-hero-card { padding: 18px 20px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); border-radius: 14px; }
  .today-big { display: flex; align-items: baseline; gap: 8px; }
  .today-big strong { font-size: 36px; font-family: 'Plus Jakarta Sans', sans-serif; }
  .today-big span { color: rgba(255,255,255,.7); }
  .today-day { color: rgba(255,255,255,.5); font-size: 12px; text-transform: capitalize; }

  .dash-card { background: white; border: 1px solid var(--line); border-radius: 20px; padding: 24px 28px; margin-bottom: 20px; }
  .dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }

  .tabs-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .tab { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; color: var(--muted); background: #f1f5f9; text-decoration: none; }
  .tab.active { background: var(--forest); color: white; }
  .tabs-sep { width: 1px; height: 24px; background: var(--line); margin: 0 4px; }
  .tab-mini { padding: 6px 12px; border-radius: 999px; font-size: 12px; color: var(--muted); border: 1px solid var(--line); text-decoration: none; }
  .tab-mini.active { background: var(--forest); color: white; border-color: var(--forest); }

  .day-block { margin-bottom: 24px; }
  .day-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px dashed var(--line); }
  .day-head strong { font-size: 13px; color: var(--ink); text-transform: capitalize; }
  .pill-count { padding: 2px 10px; background: rgba(26,71,42,.08); color: var(--forest); border-radius: 999px; font-size: 11px; font-weight: 700; }

  .cons-list { display: flex; flex-direction: column; gap: 8px; }
  .cons-row { display: grid; grid-template-columns: 60px 1fr auto; gap: 12px; padding: 12px; background: #f8fafc; border-radius: 12px; text-decoration: none; color: inherit; align-items: center; transition: .2s; }
  .cons-row:hover { background: white; box-shadow: 0 4px 12px -4px rgba(15,23,42,.08); }
  .cons-time { font-family: 'JetBrains Mono', monospace; font-size: 14px; color: var(--forest); }
  .cons-body h4 { font-size: 14px; font-weight: 700; margin-bottom: 2px; }
  .cons-body p { font-size: 12px; color: var(--muted); margin-bottom: 2px; }
  .cons-body small { font-size: 11px; color: #94a3b8; }
  .cons-meta { display: flex; align-items: center; gap: 8px; }
  .badge { padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
  .badge-success { background: rgba(16,185,129,.15); color: #047857; }

  .empty { text-align: center; padding: 40px 20px; }
  .empty-icon { width: 72px; height: 72px; margin: 0 auto 16px; background: rgba(26,71,42,.08); border-radius: 50%; display: grid; place-items: center; color: var(--forest); font-size: 28px; }

  @media (max-width: 720px) {
    .dash-hero { grid-template-columns: 1fr; }
    .cons-row { grid-template-columns: 50px 1fr; }
    .cons-meta { grid-column: 1 / -1; justify-content: flex-end; }
  }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
