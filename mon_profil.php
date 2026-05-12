<?php
// mon_profil.php — Profil universel (docteur, infirmier, admin, patient)
require_once 'config.php';
require_once 'includes/hash_chain.php';

$userType = $_SESSION['user_type'] ?? '';
$profile = null;
$table = '';
$userId = 0;
$gradient = '';
$roleLabel = '';
$identifiantBlockchain = '';
$signatureNumerique = '';

switch ($userType) {
    case 'patient':
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $table = 'patients';
        $roleLabel = 'Patient';
        $gradient = 'var(--g-emerald)';
        break;
    case 'infirmier':
        $userId = (int)($_SESSION['medecin_id'] ?? 0);
        $table = 'infirmiers';
        $roleLabel = 'Infirmier';
        $gradient = 'var(--g-trust)';
        break;
    case 'docteur':
    case 'medecin':
        $userId = (int)($_SESSION['medecin_id'] ?? 0);
        $table = 'docteurs';
        $roleLabel = 'Docteur';
        $gradient = 'var(--g-forest)';
        break;
    case 'admin':
        $userId = (int)($_SESSION['admin_id'] ?? 0);
        $table = 'admin';
        $roleLabel = 'Administrateur';
        $gradient = 'var(--g-blockchain)';
        break;
    default:
        header('Location: connexion1.php');
        exit;
}

if (!$userId) {
    header('Location: connexion1.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $profile = null;
}

if (!$profile) {
    // Fallback : utiliser session
    $profile = [
        'prenom' => $_SESSION['admin_prenom'] ?? $_SESSION['infirmier_prenom'] ?? $_SESSION['medecin_prenom'] ?? 'Utilisateur',
        'nom'    => $_SESSION['admin_nom'] ?? $_SESSION['infirmier_nom'] ?? $_SESSION['medecin_nom'] ?? '',
        'email'  => $_SESSION['admin_email'] ?? $_SESSION['infirmier_email'] ?? $_SESSION['medecin_email'] ?? '',
    ];
}

$identifiantBlockchain = $profile['identifiant_blockchain'] ?? '';
$signatureNumerique = $profile['signature_numerique'] ?? $profile['signature_numerique_infirmier'] ?? '';

$fullName = trim(($profile['prenom'] ?? '') . ' ' . ($profile['nom'] ?? ''));
$initials = strtoupper(substr($profile['prenom'] ?? '', 0, 1) . substr($profile['nom'] ?? '', 0, 1));

// Stats de l'utilisateur (dépend du rôle)
$userStats = [];
try {
    if ($userType === 'patient') {
        $userStats['Dossiers médicaux']     = $pdo->prepare("SELECT COUNT(*) FROM dossiers_medicaux WHERE patient_id = ?");
        $userStats['Ordonnances actives']   = $pdo->prepare("
            SELECT COUNT(*) FROM prescriptions pr
              JOIN dossiers_medicaux dm ON dm.id = pr.dossier_medical_id
             WHERE dm.patient_id = ? AND pr.statut = 'active'
        ");
        $userStats['Accès accordés']        = $pdo->prepare("
            SELECT COUNT(*) FROM acces_patients
             WHERE patient_id = ? AND actif = 1
               AND (date_fin IS NULL OR date_fin > NOW())
        ");
        foreach ($userStats as $k => &$s) { $s->execute([$userId]); $s = (int)$s->fetchColumn(); }
    } elseif (in_array($userType, ['docteur', 'medecin'])) {
        $userStats['Patients suivis']       = $pdo->prepare("
            SELECT COUNT(DISTINCT patient_id) FROM acces_patients
             WHERE entite_id = ? AND type_entite = 'docteur'
               AND actif = 1 AND (date_fin IS NULL OR date_fin > NOW())
        ");
        $userStats['Consultations signées'] = $pdo->prepare("SELECT COUNT(*) FROM dossiers_medicaux WHERE modifie_par_docteur = ?");
        $userStats['Ordonnances émises']    = $pdo->prepare("
            SELECT COUNT(*) FROM prescriptions pr
              JOIN dossiers_medicaux dm ON dm.id = pr.dossier_medical_id
             WHERE dm.modifie_par_docteur = ?
        ");
        foreach ($userStats as $k => &$s) { $s->execute([$userId]); $s = (int)$s->fetchColumn(); }
    } elseif ($userType === 'infirmier') {
        $userStats['Dossiers créés']        = $pdo->prepare("SELECT COUNT(*) FROM dossiers_medicaux WHERE cree_par_infirmier = ?");
        $userStats['Patients enregistrés']  = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM dossiers_medicaux WHERE cree_par_infirmier = ?");
        foreach ($userStats as $k => &$s) { $s->execute([$userId]); $s = (int)$s->fetchColumn(); }
    } elseif ($userType === 'admin') {
        $userStats['Utilisateurs totaux']   = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM patients) + (SELECT COUNT(*) FROM docteurs) + (SELECT COUNT(*) FROM infirmiers)")->fetchColumn();
        $userStats['Blocs signés aujourd\'hui'] = (int)$pdo->query("SELECT COUNT(*) FROM logs_blockchain WHERE DATE(timestamp_action)=CURDATE()")->fetchColumn();
        $userStats['Hôpitaux actifs']       = (int)$pdo->query("SELECT COUNT(*) FROM hopitaux WHERE statut='actif'")->fetchColumn();
    }
} catch (PDOException $e) { $userStats = []; }

// Derniers logs de l'utilisateur
$myLogs = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM logs_blockchain WHERE utilisateur_id = ? AND type_utilisateur = ? ORDER BY id DESC LIMIT 8");
    $stmt->execute([$userId, $userType]);
    $myLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $myLogs = []; }

$pageTitle = 'Mon profil';
$pageActive = 'profil';
$userName = $fullName;
$breadcrumb = [$roleLabel, 'Profil'];
require_once 'includes/header_dashboard.php';
?>

<!-- PROFILE HEADER -->
<section class="profile-hero reveal-up">
  <div class="profile-hero-bg"></div>
  <div class="profile-hero-body">
    <div class="profile-hero-avatar" style="background:<?= $gradient ?>">
      <?= htmlspecialchars($initials ?: 'U') ?>
    </div>
    <div class="profile-hero-info">
      <div class="profile-role"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($roleLabel) ?> vérifié</div>
      <h1><?= htmlspecialchars($fullName) ?></h1>
      <p>
        <?php if (!empty($profile['email'])): ?>
          <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($profile['email']) ?></span>
        <?php endif; ?>
        <?php if (!empty($profile['telephone'])): ?>
          <span><i class="fas fa-phone"></i> <?= htmlspecialchars($profile['telephone']) ?></span>
        <?php endif; ?>
        <?php if (!empty($profile['specialite'])): ?>
          <span><i class="fas fa-stethoscope"></i> <?= htmlspecialchars($profile['specialite']) ?></span>
        <?php endif; ?>
      </p>
    </div>
    <div class="profile-hero-actions">
      <?php if ($userType === 'patient'): ?>
        <a href="modifier_profil.php" class="btn btn-primary">
          <i class="fas fa-pen"></i> Modifier
        </a>
      <?php endif; ?>
      <a href="logs.php" class="btn btn-outline">
        <i class="fas fa-link"></i> Mes transactions
      </a>
    </div>
  </div>
</section>

<!-- IDENTIFIANT BLOCKCHAIN -->
<?php if ($identifiantBlockchain): ?>
<section class="identity-card reveal-up">
  <div class="identity-icon"><i class="fas fa-cube"></i></div>
  <div class="identity-body">
    <span class="identity-label">Identifiant blockchain national</span>
    <code class="identity-value" data-copy="<?= htmlspecialchars($identifiantBlockchain) ?>" title="Cliquer pour copier">
      <?= htmlspecialchars($identifiantBlockchain) ?>
    </code>
    <small>Cet identifiant unique et immuable vous identifie sur toute la plateforme nationale.</small>
  </div>
  <div class="identity-verified">
    <i class="fas fa-shield-check"></i>
    Vérifié
  </div>
</section>
<?php endif; ?>

<!-- STATS -->
<?php if (!empty($userStats)): ?>
<section class="stat-grid reveal-up">
  <?php $i = 0; foreach ($userStats as $label => $val):
    $variants = ['forest','emerald','trust','blockchain'];
    $variant = $variants[$i % 4];
    $icons = ['fa-chart-line','fa-layer-group','fa-shield-halved','fa-cubes'];
    $icon = $icons[$i % 4];
    $i++;
  ?>
  <div class="stat-card tilt">
    <div class="stat-card-icon <?= $variant ?>"><i class="fas <?= $icon ?>"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= (int)$val ?>">0</div>
      <div class="stat-card-label"><?= htmlspecialchars($label) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- GRID : INFOS + ACTIVITÉ -->
<section class="grid-2">
  <!-- INFOS PERSONNELLES -->
  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-id-card" style="color:var(--forest)"></i> Informations personnelles</h3>
        <p>Données enregistrées sur la plateforme</p>
      </div>
    </div>

    <div class="info-list">
      <?php
      $fields = [
        'prenom'        => ['Prénom', 'fa-user'],
        'nom'           => ['Nom', 'fa-user'],
        'email'         => ['Email', 'fa-envelope'],
        'telephone'     => ['Téléphone', 'fa-phone'],
        'date_naissance'=> ['Date de naissance', 'fa-cake-candles'],
        'sexe'          => ['Sexe', 'fa-venus-mars'],
        'groupe_sanguin'=> ['Groupe sanguin', 'fa-droplet'],
        'adresse'       => ['Adresse', 'fa-location-dot'],
        'ville'         => ['Ville', 'fa-city'],
        'specialite'    => ['Spécialité', 'fa-stethoscope'],
        'numero_licence'=> ['Numéro de licence', 'fa-id-badge'],
        'NPI' => ['Numéro NPI', 'fa-shield-halved'],
      ];
      foreach ($fields as $key => [$label, $icon]):
        if (empty($profile[$key])) continue;
        $value = $profile[$key];
        if ($key === 'date_naissance') $value = date('d/m/Y', strtotime($value));
      ?>
      <div class="info-row">
        <div class="info-icon"><i class="fas <?= $icon ?>"></i></div>
        <div class="info-body">
          <span class="info-label"><?= htmlspecialchars($label) ?></span>
          <span class="info-value"><?= htmlspecialchars($value) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- DERNIÈRE ACTIVITÉ -->
  <div class="dash-card reveal-up">
    <div class="dash-card-head">
      <div>
        <h3><i class="fas fa-link" style="color:var(--blockchain)"></i> Mes dernières transactions</h3>
        <p>Actions inscrites dans la chaîne</p>
      </div>
      <a href="logs.php" class="btn btn-outline">Toutes</a>
    </div>

    <?php if (empty($myLogs)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="fas fa-database"></i></div>
        <h4>Aucune transaction</h4>
        <p>Vos actions apparaîtront ici.</p>
      </div>
    <?php else: ?>
      <div class="mini-chain">
        <?php foreach ($myLogs as $log):
          $blockHash = $log['block_hash'] ?? $log['transaction_id'] ?? '';
          $actionClass = match($log['type_action'] ?? '') {
            'LOGIN', 'LOGIN_ADMIN'                => 'trust',
            'LOGIN_FAILED'                         => 'danger',
            'CREATE_PATIENT', 'CREATE_DOSSIER', 'GRANT_ACCESS' => 'success',
            'UPDATE_DOSSIER', 'REVOKE_ACCESS'     => 'warn',
            default                                => 'neutral'
          };
        ?>
        <div class="mini-chain-row">
          <span class="mini-chain-time"><?= date('d/m · H:i', strtotime($log['timestamp_action'])) ?></span>
          <span class="badge badge-<?= $actionClass ?>"><?= htmlspecialchars($log['type_action']) ?></span>
          <code class="mini-chain-hash" title="<?= htmlspecialchars($blockHash) ?>">
            <?= htmlspecialchars(HashChain::shortHash($blockHash, 6, 4)) ?>
          </code>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<style>
  /* Profile hero */
  .profile-hero {
    position: relative; border-radius: 24px; overflow: hidden;
    margin-bottom: 24px; background: white; border: 1px solid var(--line);
  }
  .profile-hero-bg {
    height: 120px; background: linear-gradient(135deg, #1e1b4b 0%, #4338ca 50%, #0369a1 100%);
    position: relative;
  }
  .profile-hero-bg::before {
    content: ''; position: absolute; inset: 0;
    background: radial-gradient(circle at 20% 50%, rgba(139,92,246,.4), transparent 60%),
                radial-gradient(circle at 80% 50%, rgba(56,189,248,.3), transparent 60%);
    filter: blur(40px);
  }
  .profile-hero-body {
    display: grid; grid-template-columns: auto 1fr auto;
    gap: 24px; align-items: flex-end;
    padding: 0 32px 24px; margin-top: -48px;
    position: relative; z-index: 1;
  }
  .profile-hero-avatar {
    width: 104px; height: 104px; border-radius: 24px;
    display: grid; place-items: center;
    color: white; font-size: 36px; font-weight: 800;
    font-family: 'Plus Jakarta Sans', sans-serif;
    border: 5px solid white;
    box-shadow: 0 12px 32px -8px rgba(15,23,42,.2);
  }
  .profile-hero-info { padding-bottom: 8px; flex: 1; }
  .profile-role {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; background: rgba(16,185,129,.1);
    border: 1px solid rgba(16,185,129,.3); border-radius: 999px;
    font-size: 11px; font-weight: 700; color: #065f46; margin-bottom: 8px;
  }
  .profile-role i { color: #10b981; }
  .profile-hero-info h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 28px; font-weight: 800; color: var(--ink); margin-bottom: 6px;
  }
  .profile-hero-info p {
    display: flex; flex-wrap: wrap; gap: 16px;
    font-size: 13px; color: var(--muted);
  }
  .profile-hero-info p i { color: var(--forest); margin-right: 4px; }
  .profile-hero-actions { display: flex; gap: 10px; padding-bottom: 8px; }

  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 16px; border-radius: 10px;
    font-size: 13px; font-weight: 700;
    text-decoration: none; border: none; cursor: pointer; transition: .3s;
  }
  .btn-primary { background: var(--g-forest); color: white; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -4px rgba(26,71,42,.4); }
  .btn-outline { background: white; border: 1px solid var(--line); color: var(--ink); }
  .btn-outline:hover { border-color: var(--blockchain); color: var(--blockchain); }

  /* Identity card */
  .identity-card {
    display: grid; grid-template-columns: auto 1fr auto;
    gap: 20px; align-items: center;
    padding: 20px 24px; margin-bottom: 24px;
    background: linear-gradient(135deg, rgba(99,102,241,.06), rgba(3,105,161,.04));
    border: 1px solid rgba(99,102,241,.2);
    border-radius: 16px;
  }
  .identity-icon {
    width: 52px; height: 52px; border-radius: 14px;
    background: var(--g-blockchain); color: white;
    display: grid; place-items: center; font-size: 22px;
  }
  .identity-label {
    display: block; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
    margin-bottom: 4px;
  }
  .identity-value {
    display: inline-block;
    font-family: 'JetBrains Mono', monospace;
    font-size: 14px; font-weight: 700; color: var(--blockchain);
    padding: 6px 12px; background: rgba(99,102,241,.08);
    border-radius: 8px; cursor: pointer; margin-bottom: 6px; word-break: break-all;
  }
  .identity-value:hover { background: rgba(99,102,241,.18); }
  .identity-body small { display: block; font-size: 12px; color: var(--muted); line-height: 1.5; }
  .identity-verified {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; background: #d1fae5;
    color: #065f46; border-radius: 999px;
    font-size: 12px; font-weight: 700;
  }
  .identity-verified i { color: #10b981; }

  /* Stats */
  .stat-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 16px; margin-bottom: 24px;
  }
  .stat-card {
    display: flex; align-items: center; gap: 14px;
    padding: 20px; background: white;
    border: 1px solid var(--line); border-radius: 16px;
    transition: .3s;
  }
  .stat-card:hover { transform: translateY(-3px); box-shadow: 0 18px 36px -16px rgba(15,23,42,.15); }
  .stat-card-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: grid; place-items: center; color: white; font-size: 18px;
  }
  .stat-card-icon.forest    { background: var(--g-forest); }
  .stat-card-icon.emerald   { background: var(--g-emerald); }
  .stat-card-icon.trust     { background: var(--g-trust); }
  .stat-card-icon.blockchain{ background: var(--g-blockchain); }
  .stat-card-value {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 26px; font-weight: 800; color: var(--ink); line-height: 1;
  }
  .stat-card-label { font-size: 12px; color: var(--muted); margin-top: 4px; }

  /* Dash card + grid */
  .dash-card {
    background: white; border: 1px solid var(--line);
    border-radius: 20px; padding: 24px 28px; margin-bottom: 24px;
  }
  .dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .dash-card-head h3 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 17px; font-weight: 700;
    display: flex; align-items: center; gap: 10px;
  }
  .dash-card-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }

  /* Info list */
  .info-list { display: flex; flex-direction: column; gap: 10px; }
  .info-row {
    display: flex; align-items: center; gap: 14px;
    padding: 12px 14px; background: #f8fafc;
    border-radius: 12px; transition: .2s;
  }
  .info-row:hover { background: #f1f5f9; }
  .info-icon {
    width: 36px; height: 36px; border-radius: 10px;
    background: rgba(26,71,42,.08); color: var(--forest);
    display: grid; place-items: center; font-size: 14px; flex-shrink: 0;
  }
  .info-body { flex: 1; min-width: 0; }
  .info-label { display: block; font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
  .info-value { display: block; font-size: 14px; color: var(--ink); font-weight: 500; word-break: break-word; }

  /* Mini chain */
  .mini-chain { display: flex; flex-direction: column; gap: 8px; }
  .mini-chain-row {
    display: grid; grid-template-columns: 100px 1fr auto;
    gap: 12px; align-items: center;
    padding: 10px 14px; background: #f8fafc;
    border-radius: 10px; border-left: 2px solid var(--blockchain);
  }
  .mini-chain-time {
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px; color: var(--muted);
  }
  .mini-chain-hash {
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px; color: var(--blockchain);
    padding: 3px 8px; background: rgba(99,102,241,.08);
    border-radius: 6px;
  }

  /* Badges */
  .badge {
    display: inline-block; padding: 3px 8px; border-radius: 999px;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
  }
  .badge-success { background: #d1fae5; color: #065f46; }
  .badge-info    { background: #dbeafe; color: #1e40af; }
  .badge-warn    { background: #fef3c7; color: #92400e; }
  .badge-danger  { background: #fee2e2; color: #991b1b; }
  .badge-neutral { background: #f1f5f9; color: #475569; }
  .badge-trust   { background: #e0f2fe; color: #075985; }

  .empty { text-align: center; padding: 30px 20px; }
  .empty-icon {
    width: 60px; height: 60px; margin: 0 auto 12px;
    background: rgba(99,102,241,.08); border-radius: 50%;
    display: grid; place-items: center; color: var(--blockchain); font-size: 22px;
  }
  .empty h4 { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
  .empty p { font-size: 12px; color: var(--muted); }

  @media (max-width: 1100px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
    .grid-2 { grid-template-columns: 1fr; }
  }
  @media (max-width: 720px) {
    .profile-hero-body { grid-template-columns: 1fr; text-align: center; }
    .profile-hero-avatar { margin: -48px auto 0; }
    .profile-hero-info p { justify-content: center; }
    .profile-hero-actions { justify-content: center; flex-wrap: wrap; }
    .identity-card { grid-template-columns: 1fr; text-align: center; }
    .identity-icon { margin: 0 auto; }
    .stat-grid { grid-template-columns: 1fr; }
    .mini-chain-row { grid-template-columns: 1fr; text-align: left; }
  }
</style>

<script>
  document.querySelectorAll('[data-copy]').forEach(el => {
    el.addEventListener('click', () => {
      const text = el.getAttribute('data-copy');
      if (!text) return;
      navigator.clipboard.writeText(text).then(() => {
        const orig = el.style.background;
        el.style.background = '#10b981'; el.style.color = 'white';
        setTimeout(() => { el.style.background = orig; el.style.color = ''; }, 600);
      });
    });
  });

  // Compteurs animés
  document.querySelectorAll('.stat-card-value[data-count]').forEach(el => {
    const target = parseInt(el.dataset.count, 10) || 0;
    if (target === 0) { el.textContent = '0'; return; }
    let cur = 0; const step = Math.max(1, Math.ceil(target / 40));
    const tick = () => {
      cur += step; if (cur >= target) { el.textContent = target; return; }
      el.textContent = cur; requestAnimationFrame(tick);
    };
    setTimeout(tick, 200);
  });
</script>

<?php require_once 'includes/footer_dashboard.php'; ?>
