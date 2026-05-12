<?php
// logs.php — Journal blockchain complet (admin, infirmier, docteur, patient)
require_once 'config.php';
require_once 'includes/hash_chain.php';

$userType = $_SESSION['user_type'] ?? '';
$userId = 0;
if ($userType === 'admin' && isset($_SESSION['admin_id']))         $userId = (int)$_SESSION['admin_id'];
elseif ($userType === 'infirmier' && isset($_SESSION['medecin_id'])) $userId = (int)$_SESSION['medecin_id'];
elseif (in_array($userType, ['docteur', 'medecin']) && isset($_SESSION['medecin_id']))   $userId = (int)$_SESSION['medecin_id'];
elseif ($userType === 'patient' && isset($_SESSION['user_id']))    $userId = (int)$_SESSION['user_id'];

if (!$userId) {
    header('Location: connexion1.php');
    exit;
}

// Filtres
$filterType  = $_GET['type']  ?? '';
$filterRole  = $_GET['role']  ?? '';
$filterDate  = $_GET['date']  ?? '';
$filterQuery = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// WHERE clauses selon rôle
$where = [];
$params = [];

if ($userType === 'patient') {
    // Patient voit seulement les logs le concernant (record_id = son id sur table patients, OU actions qu'il a faites)
    $where[] = "((record_id = ? AND table_concernee='patients') OR (utilisateur_id = ? AND type_utilisateur='patient'))";
    $params[] = (string)$userId;
    $params[] = $userId;
} elseif (in_array($userType, ['docteur', 'medecin'])) {
    // Docteur voit ses propres actions + actions sur ses patients
    $where[] = "(utilisateur_id = ? AND type_utilisateur IN ('docteur','medecin'))";
    $params[] = $userId;
}
// infirmier et admin voient tout

if ($filterType !== '') {
    $where[] = "type_action = ?";
    $params[] = $filterType;
}
if ($filterRole !== '') {
    $where[] = "type_utilisateur = ?";
    $params[] = $filterRole;
}
if ($filterDate !== '') {
    $where[] = "DATE(timestamp_action) = ?";
    $params[] = $filterDate;
}
if ($filterQuery !== '') {
    $where[] = "(transaction_id LIKE ? OR block_hash LIKE ? OR type_action LIKE ?)";
    $params[] = '%' . $filterQuery . '%';
    $params[] = '%' . $filterQuery . '%';
    $params[] = '%' . $filterQuery . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM logs_blockchain $whereSql");
    $countStmt->execute($params);
    $totalLogs = (int)$countStmt->fetchColumn();

    $logsStmt = $pdo->prepare("
        SELECT * FROM logs_blockchain
        $whereSql
        ORDER BY id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $logsStmt->execute($params);
    $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $logs = []; $totalLogs = 0;
}

$totalPages = max(1, (int)ceil($totalLogs / $perPage));

// Stats + vérification intégrité
$chainStats = HashChain::getStats();
$chainVerify = HashChain::verifyChain();

// Types d'actions distincts (pour filtre)
try {
    $typesActions = $pdo->query("SELECT DISTINCT type_action FROM logs_blockchain ORDER BY type_action")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $typesActions = []; }

$pageTitle = 'Journal Blockchain';
$pageActive = 'logs';
$breadcrumb = ['Blockchain', 'Journal'];
require_once 'includes/header_dashboard.php';
?>

<!-- HERO MINI -->
<section class="dash-hero dash-hero-compact reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span> Journal immuable
    </div>
    <h1>Chaîne <span>de confiance nationale.</span></h1>
    <p>
      Chaque action critique est inscrite dans une chaîne de blocs SHA-256.
      Altérer un bloc casse tous les suivants — <strong>la fraude est mathématiquement détectable</strong>.
    </p>
  </div>

  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">État de la chaîne</span>
      <i class="fas fa-<?= $chainVerify['valid'] ? 'shield-check' : 'triangle-exclamation' ?>"></i>
    </div>
    <div class="dash-hero-card-hash">
      <?= $chainVerify['valid']
        ? '<span style="color:#86efac">✓ Intègre · ' . $chainVerify['total'] . ' blocs</span>'
        : '<span style="color:#fca5a5">✗ ' . htmlspecialchars($chainVerify['message']) . '</span>' ?>
    </div>
    <div class="dash-hero-card-meta">
      <span><i class="fas fa-database"></i> Total : <?= number_format($chainStats['total_blocks']) ?></span>
      <span><i class="fas fa-clock"></i> Aujourd'hui : <?= $chainStats['today'] ?></span>
    </div>
  </div>
</section>

<!-- FILTRES -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-filter" style="color:var(--blockchain)"></i> Filtres</h3>
      <p><?= number_format($totalLogs) ?> bloc<?= $totalLogs > 1 ? 's' : '' ?> correspondent</p>
    </div>
    <?php if ($where): ?>
      <a href="logs.php" class="btn btn-outline">
        <i class="fas fa-times"></i> Réinitialiser
      </a>
    <?php endif; ?>
  </div>

  <form class="logs-filter-form" method="get">
    <div class="filter-field">
      <label>Recherche hash / transaction</label>
      <input type="search" name="q" value="<?= htmlspecialchars($filterQuery) ?>" placeholder="0x... ou CREATE_PATIENT">
    </div>
    <div class="filter-field">
      <label>Type d'action</label>
      <select name="type">
        <option value="">— Tous —</option>
        <?php foreach ($typesActions as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>>
            <?= htmlspecialchars($t) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (in_array($userType, ['admin', 'infirmier'])): ?>
    <div class="filter-field">
      <label>Utilisateur</label>
      <select name="role">
        <option value="">— Tous —</option>
        <?php foreach (['patient','docteur','medecin','infirmier','admin'] as $r): ?>
          <option value="<?= $r ?>" <?= $filterRole === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="filter-field">
      <label>Date</label>
      <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
    </div>
    <button type="submit" class="btn btn-primary">
      <i class="fas fa-search"></i> Appliquer
    </button>
  </form>
</section>

<!-- CHAIN LIST -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-link" style="color:var(--blockchain)"></i> Registre des transactions</h3>
      <p>Ordre chronologique inverse · Page <?= $page ?> / <?= $totalPages ?></p>
    </div>
  </div>

  <?php if (empty($logs)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-database"></i></div>
      <h4>Aucun bloc trouvé</h4>
      <p>Ajustez vos filtres ou réinitialisez la recherche.</p>
    </div>
  <?php else: ?>
    <div class="chain-list-full">
      <?php foreach ($logs as $log):
        $blockHash = $log['block_hash'] ?? $log['transaction_id'] ?? '';
        $prevHash  = $log['prev_hash'] ?? '';
        $details   = $log['details'] ? (json_decode($log['details'], true) ?: []) : [];
        $actionClass = match($log['type_action'] ?? '') {
            'LOGIN', 'LOGIN_ADMIN'        => 'trust',
            'LOGIN_FAILED', 'DELETE_DOSSIER' => 'danger',
            'CREATE_PATIENT', 'CREATE_DOSSIER', 'CREATE_ORDONNANCE', 'CREATE_DOCTEUR', 'CREATE_INFIRMIER', 'GRANT_ACCESS' => 'success',
            'UPDATE_DOSSIER', 'UPDATE_PRESCRIPTION', 'REVOKE_ACCESS' => 'warn',
            default                        => 'neutral'
        };
      ?>
        <div class="chain-block">
          <div class="chain-block-header">
            <div class="chain-block-ident">
              <span class="chain-block-num">Bloc #<?= $log['block_number'] ?? $log['id'] ?></span>
              <span class="badge badge-<?= $actionClass ?>"><?= htmlspecialchars($log['type_action']) ?></span>
            </div>
            <div class="chain-block-time">
              <i class="far fa-clock"></i>
              <?= date('d/m/Y · H:i:s', strtotime($log['timestamp_action'])) ?>
            </div>
          </div>

          <div class="chain-block-hashes">
            <div class="hash-line">
              <span class="hash-label">Block hash</span>
              <code class="hash-value hash-current" data-copy="<?= htmlspecialchars($blockHash) ?>" title="Cliquer pour copier"><?= htmlspecialchars($blockHash ?: '—') ?></code>
            </div>
            <?php if ($prevHash && $prevHash !== HashChain::GENESIS_HASH): ?>
            <div class="hash-line">
              <span class="hash-label">Prev hash</span>
              <code class="hash-value hash-prev" data-copy="<?= htmlspecialchars($prevHash) ?>" title="Cliquer pour copier"><?= htmlspecialchars($prevHash) ?></code>
            </div>
            <?php elseif ($prevHash === HashChain::GENESIS_HASH): ?>
            <div class="hash-line">
              <span class="hash-label">Prev hash</span>
              <code class="hash-value hash-genesis">GENESIS (bloc initial)</code>
            </div>
            <?php endif; ?>
          </div>

          <div class="chain-block-meta">
            <span class="meta-chip"><i class="fas fa-user"></i> <?= htmlspecialchars($log['type_utilisateur']) ?> #<?= $log['utilisateur_id'] ?></span>
            <?php if (!empty($log['table_concernee'])): ?>
              <span class="meta-chip"><i class="fas fa-table"></i> <?= htmlspecialchars($log['table_concernee']) ?></span>
            <?php endif; ?>
            <?php if (!empty($log['record_id'])): ?>
              <span class="meta-chip"><i class="fas fa-hashtag"></i> Record <?= htmlspecialchars($log['record_id']) ?></span>
            <?php endif; ?>
            <span class="meta-chip"><i class="fas fa-network-wired"></i> <?= htmlspecialchars($log['ip_address'] ?? '—') ?></span>
            <span class="meta-chip status-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($log['status'] ?? 'success') ?></span>
          </div>

          <?php if (!empty($details)): ?>
            <details class="chain-block-details">
              <summary>Voir les détails (<?= count($details) ?> champs)</summary>
              <div class="details-grid">
                <?php foreach ($details as $key => $val):
                  $valStr = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string)$val;
                ?>
                <div class="detail-row">
                  <span class="detail-key"><?= htmlspecialchars($key) ?></span>
                  <span class="detail-val"><?= htmlspecialchars(mb_strimwidth($valStr, 0, 200, '…')) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
      $qs = $_GET; unset($qs['page']);
      $baseUrl = 'logs.php?' . http_build_query($qs);
      $sep = $qs ? '&' : '';
    ?>
    <nav class="pagination">
      <a href="<?= $baseUrl . $sep . 'page=' . max(1, $page-1) ?>"
         class="pag-btn <?= $page <= 1 ? 'disabled' : '' ?>">
        <i class="fas fa-chevron-left"></i> Précédent
      </a>
      <span class="pag-info">
        Page <strong><?= $page ?></strong> / <?= $totalPages ?>
      </span>
      <a href="<?= $baseUrl . $sep . 'page=' . min($totalPages, $page+1) ?>"
         class="pag-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
        Suivant <i class="fas fa-chevron-right"></i>
      </a>
    </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

<style>
  /* Hero compact */
  .dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #1e1b4b 0%, #4338ca 50%, #0369a1 100%);
    color: white; border-radius: 24px; padding: 36px;
    display: grid; grid-template-columns: 1.4fr 1fr; gap: 28px;
    align-items: center; margin-bottom: 24px;
  }
  .dash-hero-compact { padding: 28px 36px; }
  .dash-hero::before {
    content: ''; position: absolute; top: -150px; right: -150px;
    width: 420px; height: 420px;
    background: radial-gradient(circle, rgba(139,92,246,.35), transparent 60%);
    filter: blur(80px);
  }
  .dash-hero-content { position: relative; z-index: 1; }
  .dash-hero-greet {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 12px; background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.15); border-radius: 999px;
    font-size: 12px; font-weight: 600; margin-bottom: 14px;
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
    display: block;
    background: linear-gradient(135deg, #c4b5fd, #7dd3fc);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .dash-hero p { font-size: 14px; color: rgba(255,255,255,.8); margin-top: 10px; line-height: 1.6; }
  .dash-hero p strong { color: #c4b5fd; }
  .dash-hero-card {
    position: relative; z-index: 1;
    padding: 20px; background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(16px);
    border-radius: 16px;
  }
  .dash-hero-card-head {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;
  }
  .dash-hero-card-label {
    font-size: 11px; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: rgba(255,255,255,.6);
  }
  .dash-hero-card-head i { color: #c4b5fd; font-size: 16px; }
  .dash-hero-card-hash {
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px; font-weight: 600; color: #c4b5fd;
    padding: 10px 12px; background: rgba(139,92,246,.12);
    border: 1px solid rgba(139,92,246,.3); border-radius: 10px;
  }
  .dash-hero-card-meta {
    display: flex; flex-direction: column; gap: 4px;
    margin-top: 10px; font-size: 11px; color: rgba(255,255,255,.7);
  }
  .dash-hero-card-meta i { color: #c4b5fd; margin-right: 4px; }

  /* Dash card */
  .dash-card {
    background: white; border: 1px solid var(--line);
    border-radius: 20px; padding: 24px 28px; margin-bottom: 20px;
  }
  .dash-card-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; gap: 16px;
  }
  .dash-card-head h3 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 17px; font-weight: 700; color: var(--ink);
    display: flex; align-items: center; gap: 10px;
  }
  .dash-card-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }

  /* Form filtres */
  .logs-filter-form {
    display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto;
    gap: 12px; align-items: flex-end;
  }
  .filter-field { display: flex; flex-direction: column; gap: 4px; }
  .filter-field label {
    font-size: 11px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .04em;
  }
  .filter-field input, .filter-field select {
    padding: 10px 12px; border: 1px solid var(--line);
    border-radius: 10px; background: white;
    font-size: 13px; color: var(--ink); font-family: inherit;
    transition: .2s;
  }
  .filter-field input:focus, .filter-field select:focus {
    outline: none; border-color: var(--blockchain);
    box-shadow: 0 0 0 3px rgba(99,102,241,.1);
  }
  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 16px; border-radius: 10px;
    font-size: 13px; font-weight: 700; font-family: inherit;
    text-decoration: none; border: none; cursor: pointer; transition: .3s;
  }
  .btn-primary { background: var(--g-blockchain); color: white; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -4px rgba(99,102,241,.4); }
  .btn-outline {
    padding: 9px 14px; background: transparent;
    border: 1px solid var(--line); color: var(--ink);
  }
  .btn-outline:hover { border-color: var(--blockchain); color: var(--blockchain); }

  /* CHAIN LIST */
  .chain-list-full { display: flex; flex-direction: column; gap: 14px; }
  .chain-block {
    padding: 18px 20px; background: #f8fafc;
    border: 1px solid var(--line);
    border-radius: 14px;
    border-left: 3px solid var(--blockchain);
    transition: .25s;
  }
  .chain-block:hover {
    background: white;
    box-shadow: 0 10px 24px -10px rgba(15,23,42,.12);
    transform: translateX(2px);
  }

  .chain-block-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 12px; flex-wrap: wrap; gap: 12px;
  }
  .chain-block-ident { display: flex; align-items: center; gap: 12px; }
  .chain-block-num {
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px; font-weight: 700; color: var(--blockchain);
    padding: 4px 10px; background: rgba(99,102,241,.1); border-radius: 8px;
  }
  .chain-block-time {
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px; color: var(--muted);
  }

  .chain-block-hashes {
    display: flex; flex-direction: column; gap: 6px;
    padding: 10px 12px;
    background: rgba(99,102,241,.04);
    border: 1px dashed rgba(99,102,241,.2);
    border-radius: 10px;
    margin-bottom: 12px;
  }
  .hash-line {
    display: grid; grid-template-columns: 100px 1fr; gap: 12px;
    align-items: center; font-size: 12px;
  }
  .hash-label {
    font-size: 10px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .06em;
  }
  .hash-value {
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px; word-break: break-all;
    padding: 6px 10px; border-radius: 6px;
    cursor: pointer; transition: .2s;
  }
  .hash-current { background: rgba(99,102,241,.1); color: var(--blockchain); }
  .hash-current:hover { background: rgba(99,102,241,.2); }
  .hash-prev { background: rgba(100,116,139,.1); color: #475569; }
  .hash-prev:hover { background: rgba(100,116,139,.2); }
  .hash-genesis { background: rgba(16,185,129,.08); color: #065f46; font-style: italic; }

  .chain-block-meta {
    display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;
  }
  .meta-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; background: white;
    border: 1px solid var(--line);
    border-radius: 999px;
    font-size: 11px; color: var(--muted);
  }
  .meta-chip i { color: var(--blockchain); font-size: 10px; }
  .meta-chip.status-ok { background: #d1fae5; border-color: rgba(16,185,129,.3); color: #065f46; }
  .meta-chip.status-ok i { color: #10b981; }

  .chain-block-details {
    margin-top: 8px; border-top: 1px dashed var(--line); padding-top: 10px;
  }
  .chain-block-details summary {
    cursor: pointer; font-size: 12px; font-weight: 600; color: var(--blockchain);
    padding: 4px 0;
  }
  .chain-block-details summary:hover { opacity: .8; }
  .details-grid { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
  .detail-row {
    display: grid; grid-template-columns: 140px 1fr; gap: 12px;
    padding: 6px 10px; background: white;
    border-radius: 6px; font-size: 12px;
  }
  .detail-key {
    font-family: 'JetBrains Mono', monospace;
    color: var(--muted); font-weight: 600;
  }
  .detail-val {
    color: var(--ink); word-break: break-word;
    font-family: 'JetBrains Mono', monospace; font-size: 11px;
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

  /* Pagination */
  .pagination {
    display: flex; justify-content: center; align-items: center;
    gap: 16px; margin-top: 24px; padding-top: 20px;
    border-top: 1px solid var(--line);
  }
  .pag-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px; background: white;
    border: 1px solid var(--line); border-radius: 10px;
    font-size: 13px; font-weight: 600; color: var(--ink);
    text-decoration: none; transition: .2s;
  }
  .pag-btn:hover:not(.disabled) { border-color: var(--blockchain); color: var(--blockchain); }
  .pag-btn.disabled { opacity: .4; pointer-events: none; }
  .pag-info { font-size: 13px; color: var(--muted); }
  .pag-info strong { color: var(--ink); font-family: 'JetBrains Mono', monospace; }

  .empty { text-align: center; padding: 40px 20px; }
  .empty-icon {
    width: 72px; height: 72px; margin: 0 auto 16px;
    background: rgba(99,102,241,.08); border-radius: 50%;
    display: grid; place-items: center; color: var(--blockchain); font-size: 28px;
  }
  .empty h4 { font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
  .empty p { font-size: 13px; color: var(--muted); }

  @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }

  @media (max-width: 1100px) {
    .logs-filter-form { grid-template-columns: repeat(2, 1fr); }
    .logs-filter-form button { grid-column: 1 / -1; }
  }
  @media (max-width: 800px) {
    .dash-hero { grid-template-columns: 1fr; }
    .hash-line { grid-template-columns: 80px 1fr; }
    .detail-row { grid-template-columns: 100px 1fr; }
  }
  @media (max-width: 560px) {
    .logs-filter-form { grid-template-columns: 1fr; }
    .dash-card { padding: 20px; }
    .hash-line { grid-template-columns: 1fr; gap: 4px; }
  }
</style>

<script>
  // Copie des hash
  document.querySelectorAll('[data-copy]').forEach(el => {
    el.addEventListener('click', e => {
      const text = el.getAttribute('data-copy');
      if (!text) return;
      navigator.clipboard.writeText(text).then(() => {
        const orig = el.style.background;
        el.style.background = '#10b981';
        el.style.color = 'white';
        setTimeout(() => { el.style.background = orig; el.style.color = ''; }, 600);
      });
    });
  });
</script>

<?php require_once 'includes/footer_dashboard.php'; ?>
