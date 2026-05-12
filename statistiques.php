<?php
// statistiques.php — Tableau de bord analytique (admin uniquement)
require_once 'config.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: connexion2.php'); exit;
}

// Stats globales
$nbPatients   = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
$nbInfirmiers = $pdo->query("SELECT COUNT(*) FROM infirmiers")->fetchColumn();
$nbDocteurs   = $pdo->query("SELECT COUNT(*) FROM docteurs")->fetchColumn();
$nbHopitaux   = $pdo->query("SELECT COUNT(*) FROM hopitaux")->fetchColumn();
$nbDossiers   = $pdo->query("SELECT COUNT(*) FROM dossiers_medicaux")->fetchColumn();
$nbOrdonnances= $pdo->query("SELECT COUNT(*) FROM prescriptions")->fetchColumn();

// Inscriptions mensuelles patients (12 derniers mois)
$patientsParMois = $pdo->query("
    SELECT DATE_FORMAT(date_inscription,'%Y-%m') AS mois,
           COUNT(*) AS total
    FROM patients
    WHERE date_inscription >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY mois ORDER BY mois
")->fetchAll();

$infirmiersParMois = $pdo->query("
    SELECT DATE_FORMAT(date_inscription,'%Y-%m') AS mois,
           COUNT(*) AS total
    FROM infirmiers
    WHERE date_inscription >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY mois ORDER BY mois
")->fetchAll();

$docteursParMois = $pdo->query("
    SELECT DATE_FORMAT(date_inscription,'%Y-%m') AS mois,
           COUNT(*) AS total
    FROM docteurs
    WHERE date_inscription >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY mois ORDER BY mois
")->fetchAll();

$dossiersParMois = $pdo->query("
    SELECT DATE_FORMAT(date_creation,'%Y-%m') AS mois,
           COUNT(*) AS total
    FROM dossiers_medicaux
    WHERE date_creation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY mois ORDER BY mois
")->fetchAll();

$patientsParHopital = $pdo->query("
    SELECT h.nom, COUNT(p.id) AS total
    FROM hopitaux h
    LEFT JOIN patients p ON p.hopital_reference = h.id
    GROUP BY h.id ORDER BY total DESC
")->fetchAll();

$sexes = $pdo->query("
    SELECT sexe, COUNT(*) AS total FROM patients GROUP BY sexe
")->fetchAll();

// Générer les 12 derniers mois comme labels
$labels = [];
for ($i = 11; $i >= 0; $i--) {
    $labels[] = date('Y-m', strtotime("-$i months"));
}

function mapData(array $rows, array $labels): array {
    $map = [];
    foreach ($rows as $r) { $map[$r['mois']] = (int)$r['total']; }
    return array_map(fn($l) => $map[$l] ?? 0, $labels);
}

$dataPatients   = mapData($patientsParMois,   $labels);
$dataInfirmiers = mapData($infirmiersParMois, $labels);
$dataDocteurs   = mapData($docteursParMois,   $labels);
$dataDossiers   = mapData($dossiersParMois,   $labels);
$labelsMois     = array_map(fn($l) => date('M Y', strtotime($l . '-01')), $labels);

$pageTitle  = 'Statistiques';
$pageActive = 'statistiques';
$breadcrumb = ['Admin', 'Statistiques'];
require_once 'includes/header_dashboard.php';
?>

<!-- Chart.js (CDN, chargé après header_dashboard) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<!-- HERO -->
<section class="dash-hero reveal-up" style="background:linear-gradient(135deg,#0e2a1a 0%,#0369a1 50%,#6366f1 100%)">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span>
      Tableau analytique national
    </div>
    <h1>Statistiques <span>de la plateforme</span></h1>
    <p>
      Une vue d'ensemble en temps réel de l'activité du registre médical national.
      Toutes les données sont issues de la base live au <?= date('d/m/Y · H:i') ?>.
    </p>
  </div>
  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Total comptes</span>
      <i class="fas fa-users"></i>
    </div>
    <div class="dash-hero-card-big">
      <strong><?= number_format($nbPatients + $nbInfirmiers + $nbDocteurs) ?></strong>
      <span>utilisateurs sur la plateforme</span>
    </div>
  </div>
</section>

<!-- CHIFFRES CLÉS -->
<section class="stat-grid reveal-up">
  <div class="stat-card tilt">
    <div class="stat-card-icon forest"><i class="fas fa-hospital"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= (int)$nbHopitaux ?>">0</div>
      <div class="stat-card-label">Hôpitaux</div>
    </div>
  </div>
  <div class="stat-card tilt" style="animation-delay:.05s">
    <div class="stat-card-icon emerald"><i class="fas fa-user-injured"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= (int)$nbPatients ?>">0</div>
      <div class="stat-card-label">Patients</div>
    </div>
  </div>
  <div class="stat-card tilt" style="animation-delay:.1s">
    <div class="stat-card-icon trust"><i class="fas fa-user-nurse"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= (int)$nbInfirmiers ?>">0</div>
      <div class="stat-card-label">Infirmiers</div>
    </div>
  </div>
  <div class="stat-card tilt" style="animation-delay:.15s">
    <div class="stat-card-icon blockchain"><i class="fas fa-user-md"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= (int)$nbDocteurs ?>">0</div>
      <div class="stat-card-label">Docteurs</div>
    </div>
  </div>
  <div class="stat-card tilt" style="animation-delay:.2s">
    <div class="stat-card-icon forest"><i class="fas fa-folder-open"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= (int)$nbDossiers ?>">0</div>
      <div class="stat-card-label">Dossiers médicaux</div>
    </div>
  </div>
  <div class="stat-card tilt" style="animation-delay:.25s">
    <div class="stat-card-icon emerald"><i class="fas fa-prescription"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= (int)$nbOrdonnances ?>">0</div>
      <div class="stat-card-label">Ordonnances</div>
    </div>
  </div>
</section>

<!-- GRAPHIQUE PRINCIPAL -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-chart-line" style="color:var(--forest)"></i> Évolution mensuelle des inscriptions</h3>
      <p>12 derniers mois — patients, infirmiers, docteurs</p>
    </div>
  </div>
  <div class="chart-container">
    <canvas id="chartEvolution" height="80"></canvas>
  </div>
</section>

<!-- 2 GRAPHIQUES CÔTE-À-CÔTE -->
<div class="grid-2">
  <section class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-hospital" style="color:var(--trust)"></i> Patients par hôpital</h3>
        <p>Répartition par établissement</p>
      </div>
    </div>
    <div class="chart-container">
      <canvas id="chartHopitaux" height="220"></canvas>
    </div>
  </section>

  <section class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-venus-mars" style="color:var(--blockchain)"></i> Répartition par sexe</h3>
        <p>Patients enregistrés</p>
      </div>
    </div>
    <div class="chart-container">
      <canvas id="chartSexe" height="220"></canvas>
    </div>
  </section>
</div>

<!-- DOSSIERS PAR MOIS -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-folder-medical" style="color:var(--forest)"></i> Dossiers médicaux créés par mois</h3>
      <p>12 derniers mois</p>
    </div>
  </div>
  <div class="chart-container">
    <canvas id="chartDossiers" height="80"></canvas>
  </div>
</section>

<style>
.dash-hero-card-big strong { display:block; font-family:'Plus Jakarta Sans',sans-serif; font-size:36px; font-weight:800; color:white; line-height:1; margin-bottom:6px; }
.dash-hero-card-big span { font-size:12px; color:rgba(255,255,255,.7); }

.stat-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:24px; }
.stat-card { display:flex; align-items:center; gap:14px; background:white; border:1px solid var(--line); border-radius:18px; padding:18px 20px; transition:.3s; }
.stat-card:hover { transform:translateY(-2px); box-shadow:0 12px 28px -14px rgba(15,23,42,.15); }
.stat-card-icon { width:48px; height:48px; border-radius:12px; display:grid; place-items:center; color:white; font-size:18px; flex-shrink:0; }
.stat-card-icon.forest     { background:var(--g-forest); }
.stat-card-icon.emerald    { background:var(--g-emerald); }
.stat-card-icon.trust      { background:var(--g-trust); }
.stat-card-icon.blockchain { background:var(--g-blockchain); }
.stat-card-value { font-family:'Plus Jakarta Sans',sans-serif; font-size:28px; font-weight:800; color:var(--ink); line-height:1; margin-bottom:4px; }
.stat-card-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; font-weight:700; }

.dash-card { background:white; border:1px solid var(--line); border-radius:20px; padding:24px 28px; margin-bottom:20px; }
.dash-card-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
.dash-card-head h3 { font-family:'Plus Jakarta Sans',sans-serif; font-size:17px; font-weight:700; display:flex; align-items:center; gap:10px; }
.dash-card-head p { font-size:13px; color:var(--muted); margin-top:2px; }

.chart-container { position:relative; padding-top:8px; }

.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
@media (max-width: 900px) {
  .grid-2 { grid-template-columns:1fr; }
}
</style>

<script>
const labels = <?= json_encode($labelsMois) ?>;

const cssVar = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#1a472a';

new Chart(document.getElementById('chartEvolution'), {
  type: 'line',
  data: {
    labels: labels,
    datasets: [
      {
        label: 'Patients',
        data: <?= json_encode($dataPatients) ?>,
        borderColor: '#10b981',
        backgroundColor: 'rgba(16,185,129,.1)',
        tension: 0.4, fill: true, pointRadius: 3, borderWidth: 2.5,
      },
      {
        label: 'Infirmiers',
        data: <?= json_encode($dataInfirmiers) ?>,
        borderColor: '#0ea5e9',
        backgroundColor: 'rgba(14,165,233,.1)',
        tension: 0.4, fill: true, pointRadius: 3, borderWidth: 2.5,
      },
      {
        label: 'Docteurs',
        data: <?= json_encode($dataDocteurs) ?>,
        borderColor: '#1a472a',
        backgroundColor: 'rgba(26,71,42,.1)',
        tension: 0.4, fill: true, pointRadius: 3, borderWidth: 2.5,
      }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'top', labels: { font: { family: 'Inter', weight: 600, size: 12 } } } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1, font: { family: 'Inter' } }, grid: { color: 'rgba(0,0,0,.05)' } },
      x: { ticks: { font: { family: 'Inter', size: 11 } }, grid: { display: false } }
    }
  }
});

new Chart(document.getElementById('chartHopitaux'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($patientsParHopital, 'nom')) ?>,
    datasets: [{
      label: 'Patients',
      data: <?= json_encode(array_column($patientsParHopital, 'total')) ?>,
      backgroundColor: ['#10b981','#0ea5e9','#1a472a','#f59e0b','#6366f1','#ec4899','#06b6d4','#8b5cf6'],
      borderRadius: 8,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1, font: { family: 'Inter' } }, grid: { color: 'rgba(0,0,0,.05)' } },
      x: { ticks: { font: { family: 'Inter', size: 11 } }, grid: { display: false } }
    }
  }
});

const sexeData = <?= json_encode($sexes) ?>;
const sexeLabels = sexeData.map(s => s.sexe === 'M' ? 'Masculin' : 'Féminin');
const sexeTotals = sexeData.map(s => parseInt(s.total));

new Chart(document.getElementById('chartSexe'), {
  type: 'doughnut',
  data: {
    labels: sexeLabels,
    datasets: [{
      data: sexeTotals,
      backgroundColor: ['#0ea5e9','#ec4899'],
      borderWidth: 3, borderColor: '#fff',
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { font: { family: 'Inter', weight: 600 } } } }
  }
});

new Chart(document.getElementById('chartDossiers'), {
  type: 'bar',
  data: {
    labels: labels,
    datasets: [{
      label: 'Dossiers créés',
      data: <?= json_encode($dataDossiers) ?>,
      backgroundColor: 'rgba(26,71,42,.7)',
      borderColor: '#1a472a',
      borderWidth: 1, borderRadius: 6,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1, font: { family: 'Inter' } }, grid: { color: 'rgba(0,0,0,.05)' } },
      x: { ticks: { font: { family: 'Inter', size: 11 } }, grid: { display: false } }
    }
  }
});
</script>

<?php require_once 'includes/footer_dashboard.php'; ?>
