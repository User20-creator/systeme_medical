<?php
// mes_patients.php — Liste des patients dont le docteur a un accès actif (acces_patients)
require_once 'config.php';
require_once 'includes/hash_chain.php';

if (!isset($_SESSION['medecin_id']) || !in_array($_SESSION['user_type'] ?? '', ['medecin', 'docteur'])) {
    header('Location: connexion2.php'); exit;
}
$medecin_id = (int)$_SESSION['medecin_id'];

$search = trim($_GET['q'] ?? '');
$patients = [];
try {
    $sql = "
      SELECT p.*,
             (SELECT COUNT(*) FROM dossiers_medicaux WHERE patient_id = p.id) AS nb_dossiers,
             (SELECT COUNT(*) FROM prescriptions pr
                JOIN dossiers_medicaux dm ON dm.id = pr.dossier_medical_id
               WHERE dm.patient_id = p.id AND pr.statut = 'active'
             ) AS nb_ordonnances,
             (SELECT MAX(date_creation) FROM dossiers_medicaux WHERE patient_id = p.id) AS derniere_visite
        FROM patients p
        JOIN acces_patients a ON a.patient_id = p.id
                              AND a.entite_id = ?
                              AND a.type_entite = 'docteur'
                              AND a.actif = 1
                              AND (a.date_fin IS NULL OR a.date_fin > NOW())
    ";
    $params = [$medecin_id];
    if ($search) {
        $sql .= " WHERE (p.prenom LIKE ? OR p.nom LIKE ? OR p.email LIKE ? OR p.NPI LIKE ? OR p.identifiant_blockchain LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= " GROUP BY p.id ORDER BY p.nom, p.prenom";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('mes_patients: ' . $e->getMessage());
    $patients = [];
}

$pageTitle = 'Mes patients';
$pageActive = 'patients';
$breadcrumb = ['Médecin', 'Patients'];
require_once 'includes/header_dashboard.php';
?>

<section class="dash-hero dash-hero-compact reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span> File active
    </div>
    <h1>Vos patients <span>en un regard.</span></h1>
    <p><strong><?= count($patients) ?></strong> patient<?= count($patients) > 1 ? 's' : '' ?> sous votre suivi.
      Chaque dossier consulté laisse une trace dans la blockchain.</p>
  </div>
  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Recherche rapide</span>
      <i class="fas fa-magnifying-glass"></i>
    </div>
    <form method="get" class="hero-search">
      <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Nom, email, NSS, ID blockchain...">
      <button type="submit"><i class="fas fa-arrow-right"></i></button>
    </form>
  </div>
</section>

<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-users" style="color:var(--emerald)"></i> <?= count($patients) ?> patient<?= count($patients) > 1 ? 's' : '' ?></h3>
      <p>Triés par ordre alphabétique</p>
    </div>
    <?php if ($search): ?>
      <a href="mes_patients.php" class="btn btn-outline"><i class="fas fa-times"></i> Effacer la recherche</a>
    <?php endif; ?>
  </div>

  <?php if (empty($patients)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
      <h4>Aucun patient trouvé</h4>
      <p><?= $search ? 'Aucun résultat pour cette recherche.' : 'Aucun patient ne vous a encore été assigné.' ?></p>
    </div>
  <?php else: ?>
    <div class="patients-grid">
      <?php foreach ($patients as $p):
        $age = $p['date_naissance'] ? date_diff(date_create($p['date_naissance']), date_create('today'))->y : null;
        $initials = strtoupper(substr($p['prenom'], 0, 1) . substr($p['nom'], 0, 1));
      ?>
      <a href="dossier_patient.php?id=<?= $p['id'] ?>" class="patient-card">
        <div class="patient-card-top">
          <div class="patient-avatar"><?= htmlspecialchars($initials) ?></div>
          <?php if ($p['groupe_sanguin']): ?>
            <span class="blood-badge"><i class="fas fa-droplet"></i> <?= htmlspecialchars($p['groupe_sanguin']) ?></span>
          <?php endif; ?>
        </div>
        <div class="patient-card-body">
          <h4><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></h4>
          <p>
            <?= $age !== null ? $age . ' ans' : 'Âge inconnu' ?>
            <?= $p['sexe'] ? ' · ' . htmlspecialchars(ucfirst($p['sexe'])) : '' ?>
          </p>
          <small><?= htmlspecialchars($p['telephone'] ?? '—') ?></small>
        </div>
        <div class="patient-card-footer">
          <div class="pat-stat">
            <strong><?= $p['nb_dossiers'] ?></strong>
            <span>Dossiers</span>
          </div>
          <div class="pat-stat">
            <strong><?= $p['nb_ordonnances'] ?></strong>
            <span>Ordonnances</span>
          </div>
          <div class="pat-stat pat-stat-date">
            <strong><?= $p['derniere_visite'] ? date('d/m/Y', strtotime($p['derniere_visite'])) : '—' ?></strong>
            <span>Dernière visite</span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
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
  .dash-hero::before {
    content: ''; position: absolute; top: -140px; right: -140px;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(56,189,248,.35), transparent 60%);
    filter: blur(80px);
  }
  .dash-hero-content { position: relative; z-index: 1; }
  .dash-hero-greet {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 5px 12px; background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.15); border-radius: 999px;
    font-size: 12px; font-weight: 600; margin-bottom: 12px;
  }
  .dash-hero-dot { width: 7px; height: 7px; border-radius: 50%; background: #38bdf8; box-shadow: 0 0 12px #38bdf8; animation: pulse 2s infinite; }
  .dash-hero h1 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 30px; font-weight: 800; line-height: 1.15; }
  .dash-hero h1 span { background: linear-gradient(135deg, #38bdf8, #86efac); -webkit-background-clip: text; background-clip: text; color: transparent; }
  .dash-hero p { font-size: 14px; color: rgba(255,255,255,.8); margin-top: 10px; }
  .dash-hero p strong { color: #86efac; }

  .dash-hero-card {
    position: relative; z-index: 1;
    padding: 18px 20px; background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(16px);
    border-radius: 14px;
  }
  .dash-hero-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
  .dash-hero-card-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.6); }
  .dash-hero-card-head i { color: #7dd3fc; }
  .hero-search { display: flex; gap: 6px; }
  .hero-search input {
    flex: 1; padding: 9px 12px; border-radius: 8px;
    border: 1px solid rgba(255,255,255,.2); background: rgba(255,255,255,.08);
    color: white; font-size: 12px; font-family: inherit;
  }
  .hero-search input::placeholder { color: rgba(255,255,255,.5); }
  .hero-search input:focus { outline: none; border-color: #7dd3fc; background: rgba(255,255,255,.15); }
  .hero-search button {
    width: 34px; padding: 0; border-radius: 8px;
    background: #7dd3fc; color: #0e2a1a; border: none;
    cursor: pointer; transition: .2s;
  }
  .hero-search button:hover { background: white; }

  .dash-card { background: white; border: 1px solid var(--line); border-radius: 20px; padding: 24px 28px; margin-bottom: 20px; }
  .dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .dash-card-head h3 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
  .dash-card-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }

  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 10px; font-size: 13px; font-weight: 700; text-decoration: none; transition: .3s; }
  .btn-outline { background: white; border: 1px solid var(--line); color: var(--ink); }
  .btn-outline:hover { border-color: var(--forest); color: var(--forest); }

  .patients-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
  }
  .patient-card {
    display: flex; flex-direction: column;
    background: #f8fafc; border: 1px solid var(--line);
    border-radius: 16px; padding: 18px;
    text-decoration: none; color: inherit;
    transition: .3s;
    border-top: 3px solid var(--forest);
  }
  .patient-card:hover {
    background: white; transform: translateY(-3px);
    box-shadow: 0 16px 32px -16px rgba(15,23,42,.15);
    border-top-color: var(--emerald);
  }
  .patient-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
  .patient-avatar {
    width: 52px; height: 52px; border-radius: 14px;
    background: var(--g-emerald); color: white;
    display: grid; place-items: center;
    font-size: 16px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif;
    box-shadow: 0 8px 20px -6px rgba(16,185,129,.4);
  }
  .blood-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; background: #fee2e2;
    color: #991b1b; border-radius: 999px;
    font-size: 11px; font-weight: 700;
  }
  .blood-badge i { font-size: 9px; }

  .patient-card-body { flex: 1; margin-bottom: 14px; }
  .patient-card-body h4 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 15px; font-weight: 700; color: var(--ink);
    margin-bottom: 4px;
  }
  .patient-card-body p { font-size: 12px; color: var(--muted); margin-bottom: 2px; }
  .patient-card-body small { font-size: 11px; color: #94a3b8; }

  .patient-card-footer {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 10px; padding-top: 12px;
    border-top: 1px dashed var(--line);
  }
  .pat-stat { display: flex; flex-direction: column; gap: 2px; text-align: center; }
  .pat-stat strong {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 16px; font-weight: 800; color: var(--forest);
  }
  .pat-stat span { font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
  .pat-stat-date strong { font-size: 12px; font-family: 'JetBrains Mono', monospace; }

  .empty { text-align: center; padding: 40px 20px; }
  .empty-icon { width: 72px; height: 72px; margin: 0 auto 16px; background: rgba(26,71,42,.08); border-radius: 50%; display: grid; place-items: center; color: var(--forest); font-size: 28px; }
  .empty h4 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
  .empty p { font-size: 13px; color: var(--muted); }

  @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }

  @media (max-width: 900px) {
    .dash-hero { grid-template-columns: 1fr; }
    .patients-grid { grid-template-columns: 1fr; }
  }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
