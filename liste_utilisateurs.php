<?php
// liste_utilisateurs.php — Répertoire détaillé des utilisateurs.
// Admin : voit tout + peut modifier + gérer mots de passe.
// Docteur / Infirmier : voit tout en lecture seule.

// DEBUG : afficher les erreurs (à retirer une fois le diagnostic fait)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_medecin_traitant_column($pdo);

$userType = $_SESSION['user_type'] ?? '';
if (!in_array($userType, ['admin', 'infirmier', 'docteur', 'medecin'])) {
    header('Location: connexion2.php');
    exit;
}
$canEdit = ($userType === 'admin');

$filterRole = $_GET['role'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$like = '%' . $search . '%';

$users = [];
$counts = ['patients' => 0, 'infirmiers' => 0, 'docteurs' => 0];

try {
    $counts['patients']   = (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    $counts['infirmiers'] = (int)$pdo->query("SELECT COUNT(*) FROM infirmiers")->fetchColumn();
    $counts['docteurs']   = (int)$pdo->query("SELECT COUNT(*) FROM docteurs")->fetchColumn();

    $whereSearch = " WHERE (p.prenom LIKE ? OR p.nom LIKE ? OR p.email LIKE ? OR p.telephone LIKE ? OR p.identifiant_blockchain LIKE ?)";

    // ─── Patients ─────────────────────────────────────────
    if ($filterRole === 'all' || $filterRole === 'patient') {
        $sql = "
            SELECT p.id, 'patient' AS role,
                   p.prenom, p.nom, p.email, p.telephone, p.identifiant_blockchain,
                   p.date_inscription AS d, p.statut,
                   p.date_naissance, p.sexe, p.groupe_sanguin, p.NPI, p.adresse,
                   p.hopital_reference,
                   p.medecin_traitant_id,
                   h.nom AS hopital_nom, h.ville AS hopital_ville,
                   CONCAT(d.prenom, ' ', d.nom) AS medecin_traitant_nom,
                   d.specialite AS medecin_traitant_specialite,
                   NULL AS specialite, NULL AS numero_licence, NULL AS fonction
            FROM patients p
            LEFT JOIN hopitaux h ON h.id = p.hopital_reference
            LEFT JOIN docteurs d ON d.id = p.medecin_traitant_id
        ";
        $params = [];
        if ($search) {
            $sql .= $whereSearch;
            array_push($params, $like, $like, $like, $like, $like);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) $users[] = $row;
    }

    // ─── Infirmiers ─────────────────────────────────────────
    if ($filterRole === 'all' || $filterRole === 'infirmier') {
        $sql = "
            SELECT p.id, 'infirmier' AS role,
                   p.prenom, p.nom, p.email, p.telephone, p.identifiant_blockchain,
                   p.date_inscription AS d, p.statut,
                   NULL AS date_naissance, NULL AS sexe, NULL AS groupe_sanguin, NULL AS NPI, NULL AS adresse,
                   p.hopital_principal_id AS hopital_reference,
                   NULL AS medecin_traitant_id,
                   h.nom AS hopital_nom, h.ville AS hopital_ville,
                   NULL AS medecin_traitant_nom, NULL AS medecin_traitant_specialite,
                   p.specialite, p.numero_licence, p.fonction
            FROM infirmiers p
            LEFT JOIN hopitaux h ON h.id = p.hopital_principal_id
        ";
        $params = [];
        if ($search) {
            $sql .= $whereSearch;
            array_push($params, $like, $like, $like, $like, $like);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) $users[] = $row;
    }

    // ─── Docteurs ─────────────────────────────────────────
    if ($filterRole === 'all' || $filterRole === 'docteur') {
        $sql = "
            SELECT p.id, 'docteur' AS role,
                   p.prenom, p.nom, p.email, p.telephone, p.identifiant_blockchain,
                   p.date_inscription AS d, p.statut,
                   NULL AS date_naissance, NULL AS sexe, NULL AS groupe_sanguin, NULL AS NPI, NULL AS adresse,
                   p.hopital_id AS hopital_reference,
                   NULL AS medecin_traitant_id,
                   h.nom AS hopital_nom, h.ville AS hopital_ville,
                   NULL AS medecin_traitant_nom, NULL AS medecin_traitant_specialite,
                   p.specialite, p.numero_licence, NULL AS fonction
            FROM docteurs p
            LEFT JOIN hopitaux h ON h.id = p.hopital_id
        ";
        $params = [];
        if ($search) {
            $sql .= $whereSearch;
            array_push($params, $like, $like, $like, $like, $like);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) $users[] = $row;
    }

    // Trier par date d'inscription décroissante
    usort($users, fn($a, $b) => strtotime($b['d']) - strtotime($a['d']));
    $users = array_slice($users, 0, 1000);
} catch (PDOException $e) {
    error_log('liste_utilisateurs: ' . $e->getMessage());
    $users = [];
}

$pageTitle = 'Utilisateurs';
$pageActive = 'utilisateurs';
$breadcrumb = [$userType === 'admin' ? 'Admin' : 'Infirmier', 'Utilisateurs'];
require_once 'includes/header_dashboard.php';
?>

<!-- HERO COMPACT -->
<section class="dash-hero dash-hero-compact reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span> Répertoire national
    </div>
    <h1>Tous les <span>utilisateurs.</span></h1>
    <p>
      <strong><?= number_format(array_sum($counts)) ?></strong> comptes actifs sur la plateforme.
      Chacun possède un identifiant blockchain unique.
    </p>
  </div>
  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Répartition</span>
      <i class="fas fa-chart-pie"></i>
    </div>
    <div class="repartition">
      <div><strong><?= $counts['patients'] ?></strong><span>Patients</span></div>
      <div><strong><?= $counts['docteurs'] ?></strong><span>Docteurs</span></div>
      <div><strong><?= $counts['infirmiers'] ?></strong><span>Infirmiers</span></div>
    </div>
  </div>
</section>

<!-- FILTRES + ACTIONS -->
<section class="dash-card reveal-up">
  <div class="filter-bar">
    <form method="get" class="filter-form">
      <div class="filter-input">
        <i class="fas fa-search"></i>
        <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher par nom, email, téléphone, ID blockchain...">
      </div>
      <input type="hidden" name="role" value="<?= htmlspecialchars($filterRole) ?>">
      <button type="submit" class="btn btn-primary">
        <i class="fas fa-filter"></i> Filtrer
      </button>
    </form>

    <div class="role-tabs">
      <?php
        $tabs = [
          'all'       => ['Tous', array_sum($counts)],
          'patient'   => ['Patients', $counts['patients']],
          'docteur'   => ['Docteurs', $counts['docteurs']],
          'infirmier' => ['Infirmiers', $counts['infirmiers']],
        ];
        foreach ($tabs as $k => [$label, $n]):
          $qs = $_GET; $qs['role'] = $k; unset($qs['page']);
      ?>
        <a href="?<?= http_build_query($qs) ?>" class="role-tab <?= $filterRole === $k ? 'active' : '' ?>">
          <?= $label ?> <span class="tab-count"><?= $n ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($canEdit): ?>
    <div class="create-actions">
      <a href="creer_patient.php" class="btn-create">
        <i class="fas fa-user-plus"></i> Patient
      </a>
      <a href="creer_docteur.php" class="btn-create">
        <i class="fas fa-user-md"></i> Docteur
      </a>
      <a href="creer_infirmier.php" class="btn-create">
        <i class="fas fa-user-nurse"></i> Infirmier
      </a>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- TABLEAU -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-users" style="color:var(--emerald)"></i>
        <?= count($users) ?> résultat<?= count($users) > 1 ? 's' : '' ?>
      </h3>
      <p>Tri par date d'inscription décroissante</p>
    </div>
  </div>

  <?php if (empty($users)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
      <h4>Aucun utilisateur trouvé</h4>
      <p>Ajustez vos filtres ou créez un nouveau compte.</p>
    </div>
  <?php else: ?>
    <div class="users-grid">
      <?php foreach ($users as $u):
        $initials = strtoupper(substr($u['prenom'] ?? '', 0, 1) . substr($u['nom'] ?? '', 0, 1));
        $gradMap = [
          'patient'   => 'var(--g-emerald)',
          'infirmier' => 'var(--g-trust)',
          'docteur'   => 'var(--g-forest)',
        ];
        $iconMap = [
          'patient'   => 'fa-user',
          'infirmier' => 'fa-user-nurse',
          'docteur'   => 'fa-user-md',
        ];
        $badgeMap = [
          'patient'   => 'emerald',
          'infirmier' => 'trust',
          'docteur'   => 'neutral',
        ];
        $age = (!empty($u['date_naissance']))
          ? date_diff(date_create($u['date_naissance']), date_create('today'))->y
          : null;
      ?>
      <div class="user-card">
        <div class="user-card-top">
          <div class="user-avatar" style="background:<?= $gradMap[$u['role']] ?>">
            <?= htmlspecialchars($initials ?: '?') ?>
          </div>
          <span class="badge badge-<?= $badgeMap[$u['role']] ?>">
            <i class="fas <?= $iconMap[$u['role']] ?>"></i>
            <?= ucfirst($u['role']) ?>
          </span>
        </div>

        <div class="user-card-body">
          <h4><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?></h4>

          <!-- INFOS COMMUNES -->
          <?php if (!empty($u['email'])): ?>
            <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($u['email']) ?></p>
          <?php endif; ?>
          <?php if (!empty($u['telephone'])): ?>
            <p><i class="fas fa-phone"></i> <?= htmlspecialchars($u['telephone']) ?></p>
          <?php endif; ?>

          <!-- SPÉCIFIQUE PATIENT -->
          <?php if ($u['role'] === 'patient'): ?>
            <?php if ($age !== null): ?>
              <p><i class="fas fa-birthday-cake"></i> <?= $age ?> ans
                <?php if (!empty($u['sexe'])): ?> · <?= $u['sexe'] === 'M' ? 'Masculin' : 'Féminin' ?><?php endif; ?>
              </p>
            <?php endif; ?>
            <?php if (!empty($u['groupe_sanguin'])): ?>
              <p><i class="fas fa-tint"></i> Groupe <?= htmlspecialchars($u['groupe_sanguin']) ?></p>
            <?php endif; ?>
            <?php if (!empty($u['NPI'])): ?>
              <p><i class="fas fa-id-card"></i> NPI <?= htmlspecialchars($u['NPI']) ?></p>
            <?php endif; ?>
            <?php if (!empty($u['adresse'])): ?>
              <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($u['adresse']) ?></p>
            <?php endif; ?>
            <?php if (!empty($u['medecin_traitant_nom']) && trim($u['medecin_traitant_nom']) !== ''): ?>
              <p><i class="fas fa-user-doctor"></i> Dr. <?= htmlspecialchars($u['medecin_traitant_nom']) ?>
                <?php if (!empty($u['medecin_traitant_specialite'])): ?> · <?= htmlspecialchars($u['medecin_traitant_specialite']) ?><?php endif; ?>
              </p>
            <?php endif; ?>
          <?php endif; ?>

          <!-- SPÉCIFIQUE STAFF MÉDICAL -->
          <?php if ($u['role'] === 'docteur' || $u['role'] === 'infirmier'): ?>
            <?php if (!empty($u['specialite'])): ?>
              <p><i class="fas fa-stethoscope"></i> <?= htmlspecialchars($u['specialite']) ?></p>
            <?php endif; ?>
            <?php if (!empty($u['fonction'])): ?>
              <p><i class="fas fa-user-tag"></i> <?= htmlspecialchars(ucfirst($u['fonction'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($u['numero_licence'])): ?>
              <p><i class="fas fa-id-badge"></i> Lic. <?= htmlspecialchars($u['numero_licence']) ?></p>
            <?php endif; ?>
          <?php endif; ?>

          <!-- HÔPITAL (si lié) -->
          <?php if (!empty($u['hopital_nom'])): ?>
            <p><i class="fas fa-hospital"></i> <?= htmlspecialchars($u['hopital_nom']) ?>
              <?php if (!empty($u['hopital_ville'])): ?> · <?= htmlspecialchars($u['hopital_ville']) ?><?php endif; ?>
            </p>
          <?php endif; ?>

          <!-- BLOCKCHAIN ID -->
          <?php if (!empty($u['identifiant_blockchain'])): ?>
            <code class="user-chain-id" data-copy="<?= htmlspecialchars($u['identifiant_blockchain']) ?>" title="Cliquer pour copier">
              <i class="fas fa-cube"></i>
              <?= htmlspecialchars(HashChain::shortHash($u['identifiant_blockchain'], 8, 6)) ?>
            </code>
          <?php endif; ?>
        </div>

        <?php if ($canEdit): ?>
        <div class="user-card-actions">
          <a href="modifier_utilisateur.php?type=<?= htmlspecialchars($u['role']) ?>&id=<?= (int)$u['id'] ?>"
             class="card-action card-action-edit"
             title="Modifier l'utilisateur">
            <i class="fas fa-pen-to-square"></i>
            <span>Modifier</span>
          </a>
          <a href="reinitialiser_mdp.php?type=<?= htmlspecialchars($u['role']) ?>&id=<?= (int)$u['id'] ?>"
             class="card-action card-action-pwd"
             title="Réinitialiser le mot de passe">
            <i class="fas fa-key"></i>
            <span>Mot de passe</span>
          </a>
        </div>
        <?php endif; ?>

        <div class="user-card-footer">
          <span>
            <i class="far fa-calendar"></i>
            Inscrit le <?= $u['d'] ? date('d/m/Y', strtotime($u['d'])) : '—' ?>
          </span>
          <span class="status-pill <?= ($u['statut'] ?? 'actif') === 'actif' ? 'ok' : 'off' ?>">
            <span class="status-dot <?= ($u['statut'] ?? 'actif') === 'actif' ? 'ok' : 'off' ?>"></span>
            <?= ucfirst($u['statut'] ?? 'actif') ?>
          </span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<style>
  .dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #1e1b4b 0%, #4338ca 50%, #0369a1 100%);
    color: white; border-radius: 24px; padding: 28px 36px;
    display: grid; grid-template-columns: 1.4fr 1fr; gap: 28px;
    align-items: center; margin-bottom: 24px;
  }
  .dash-hero::before {
    content: ''; position: absolute; top: -140px; right: -140px;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(139,92,246,.35), transparent 60%);
    filter: blur(80px);
  }
  .dash-hero-content { position: relative; z-index: 1; }
  .dash-hero-greet {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 5px 12px; background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.15); border-radius: 999px;
    font-size: 12px; font-weight: 600; margin-bottom: 12px;
  }
  .dash-hero-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #a78bfa; box-shadow: 0 0 12px #a78bfa;
    animation: pulse 2s infinite;
  }
  .dash-hero h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 30px; font-weight: 800; line-height: 1.15; letter-spacing: -.02em;
  }
  .dash-hero h1 span {
    background: linear-gradient(135deg, #c4b5fd, #7dd3fc);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .dash-hero p { font-size: 14px; color: rgba(255,255,255,.8); margin-top: 10px; }
  .dash-hero p strong { color: #c4b5fd; }

  .dash-hero-card {
    position: relative; z-index: 1;
    padding: 18px 20px; background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(16px);
    border-radius: 14px;
  }
  .dash-hero-card-head {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;
  }
  .dash-hero-card-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    color: rgba(255,255,255,.6);
  }
  .dash-hero-card-head i { color: #c4b5fd; }
  .repartition {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;
  }
  .repartition > div {
    text-align: center; padding: 10px 6px;
    background: rgba(255,255,255,.05); border-radius: 10px;
  }
  .repartition strong {
    display: block; font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 20px; font-weight: 800; color: #c4b5fd;
  }
  .repartition span { font-size: 10px; color: rgba(255,255,255,.6); text-transform: uppercase; letter-spacing: .04em; }

  .dash-card {
    background: white; border: 1px solid var(--line);
    border-radius: 20px; padding: 24px 28px; margin-bottom: 20px;
  }
  .dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .dash-card-head h3 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 17px; font-weight: 700;
    display: flex; align-items: center; gap: 10px;
  }
  .dash-card-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }

  /* Filter bar */
  .filter-bar {
    display: flex; flex-direction: column; gap: 16px;
  }
  .filter-form { display: flex; gap: 10px; }
  .filter-input {
    flex: 1; position: relative;
  }
  .filter-input i {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: var(--muted); font-size: 13px;
  }
  .filter-input input {
    width: 100%; padding: 11px 14px 11px 38px;
    border: 1px solid var(--line); border-radius: 10px;
    background: #f8fafc; font-size: 13px; color: var(--ink);
    font-family: inherit; transition: .2s;
  }
  .filter-input input:focus {
    outline: none; background: white; border-color: var(--blockchain);
    box-shadow: 0 0 0 3px rgba(99,102,241,.1);
  }

  .role-tabs {
    display: flex; gap: 6px; padding: 4px;
    background: #f1f5f9; border-radius: 12px;
    overflow-x: auto;
  }
  .role-tab {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px; border-radius: 8px;
    font-size: 13px; font-weight: 600; color: var(--muted);
    text-decoration: none; transition: .2s;
    white-space: nowrap;
  }
  .role-tab:hover { background: rgba(255,255,255,.5); color: var(--ink); }
  .role-tab.active { background: white; color: var(--ink); box-shadow: 0 2px 8px -2px rgba(15,23,42,.1); }
  .tab-count {
    font-family: 'JetBrains Mono', monospace; font-size: 11px;
    padding: 2px 8px; background: rgba(99,102,241,.1);
    color: var(--blockchain); border-radius: 999px; font-weight: 700;
  }

  .create-actions {
    display: flex; gap: 8px; flex-wrap: wrap;
    padding-top: 12px; border-top: 1px dashed var(--line);
  }
  .btn-create {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; background: #f8fafc;
    border: 1px solid var(--line); border-radius: 10px;
    font-size: 12px; font-weight: 600; color: var(--ink);
    text-decoration: none; transition: .2s;
  }
  .btn-create:hover {
    border-color: var(--forest); color: var(--forest);
    background: rgba(26,71,42,.04); transform: translateY(-1px);
  }

  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 11px 16px; border-radius: 10px;
    font-size: 13px; font-weight: 700; font-family: inherit;
    text-decoration: none; border: none; cursor: pointer; transition: .3s;
  }
  .btn-primary { background: var(--g-blockchain); color: white; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -4px rgba(99,102,241,.4); }

  /* Users grid */
  .users-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 16px;
  }
  .user-card {
    display: flex; flex-direction: column;
    padding: 18px; background: #f8fafc;
    border: 1px solid var(--line); border-radius: 16px;
    transition: .3s;
  }
  .user-card:hover {
    background: white; border-color: rgba(99,102,241,.25);
    transform: translateY(-3px);
    box-shadow: 0 16px 32px -16px rgba(15,23,42,.15);
  }
  .user-card-top {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;
  }
  .user-avatar {
    width: 52px; height: 52px; border-radius: 14px;
    display: grid; place-items: center;
    color: white; font-size: 16px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif;
    box-shadow: 0 8px 20px -6px rgba(15,23,42,.2);
  }
  .user-card-body { flex: 1; margin-bottom: 14px; }
  .user-card-body h4 {
    font-size: 15px; font-weight: 700; color: var(--ink); margin-bottom: 6px;
  }
  .user-card-body p {
    font-size: 12px; color: var(--muted); margin-bottom: 4px;
    word-break: break-word;
  }
  .user-card-body p i { color: var(--forest); margin-right: 4px; font-size: 10px; }
  .user-chain-id {
    display: inline-flex; align-items: center; gap: 6px;
    margin-top: 6px; padding: 4px 10px;
    background: rgba(99,102,241,.08); border: 1px solid rgba(99,102,241,.15);
    border-radius: 6px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px; color: var(--blockchain);
    cursor: pointer; transition: .2s;
  }
  .user-chain-id:hover { background: rgba(99,102,241,.18); }
  .user-chain-id i { font-size: 9px; }

  .user-card-actions {
    display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap;
  }
  .card-action {
    flex: 1;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 8px 10px; border-radius: 8px;
    font-size: 11px; font-weight: 700;
    text-decoration: none; transition: .2s;
    border: 1px solid transparent;
  }
  .card-action-edit {
    background: rgba(99,102,241,.08);
    border-color: rgba(99,102,241,.18);
    color: var(--blockchain);
  }
  .card-action-edit:hover {
    background: var(--blockchain); color: white;
    border-color: var(--blockchain);
    transform: translateY(-1px);
    box-shadow: 0 6px 14px -4px rgba(99,102,241,.4);
  }
  .card-action-pwd {
    background: rgba(245,158,11,.08);
    border-color: rgba(245,158,11,.2);
    color: #b45309;
  }
  .card-action-pwd:hover {
    background: linear-gradient(135deg,#f59e0b,#d97706); color: white;
    border-color: #d97706;
    transform: translateY(-1px);
    box-shadow: 0 6px 14px -4px rgba(245,158,11,.4);
  }
  .card-action i { font-size: 10px; }

  .user-card-footer {
    display: flex; justify-content: space-between; align-items: center;
    padding-top: 12px; border-top: 1px dashed var(--line);
    font-size: 11px; color: var(--muted); gap: 8px;
  }
  .user-card-footer > span:first-child { display:flex; align-items:center; gap:5px; }
  .status-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 3px 9px; border-radius: 999px;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing:.04em;
  }
  .status-pill.ok  { background: rgba(16,185,129,.12); color: #065f46; }
  .status-pill.off { background: rgba(239,68,68,.12); color: #991b1b; }
  .status-dot {
    width: 7px; height: 7px; border-radius: 50%;
  }
  .status-dot.ok  { background: #10b981; }
  .status-dot.off { background: #ef4444; }

  /* Badges */
  .badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 999px;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
  }
  .badge-emerald { background: #d1fae5; color: #065f46; }
  .badge-trust   { background: #e0f2fe; color: #075985; }
  .badge-neutral { background: #f1f5f9; color: #475569; }

  .empty { text-align: center; padding: 40px 20px; }
  .empty-icon {
    width: 72px; height: 72px; margin: 0 auto 16px;
    background: rgba(99,102,241,.08); border-radius: 50%;
    display: grid; place-items: center; color: var(--blockchain); font-size: 28px;
  }
  .empty h4 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
  .empty p { font-size: 13px; color: var(--muted); }

  @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }

  @media (max-width: 1100px) { .dash-hero { grid-template-columns: 1fr; } }
  @media (max-width: 640px) {
    .users-grid { grid-template-columns: 1fr; }
    .filter-form { flex-direction: column; }
    .role-tabs { flex-wrap: nowrap; overflow-x: auto; }
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
