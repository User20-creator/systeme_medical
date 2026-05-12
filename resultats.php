<?php
// resultats.php — Résultats d'analyses / examens
require_once 'config.php';
require_once 'includes/hash_chain.php';

$userType = $_SESSION['user_type'] ?? '';
if ($userType === 'patient') {
    $patientId = (int)($_SESSION['user_id'] ?? 0);
    if (!$patientId) { header('Location: connexion1.php'); exit; }
} elseif (in_array($userType, ['docteur','medecin','infirmier','admin'])) {
    $currentId = (int)($_SESSION['medecin_id'] ?? $_SESSION['admin_id'] ?? 0);
    if (!$currentId && $userType !== 'admin') { header('Location: connexion2.php'); exit; }
} else {
    header('Location: connexion2.php'); exit;
}

$erreur = '';
$ok     = null;

// Vérifier existence de la table analyses_medicales (sinon fallback)
$hasTable = true;
try {
    $pdo->query("SELECT 1 FROM analyses_medicales LIMIT 1");
} catch (PDOException $e) {
    $hasTable = false;
}

// ── ACTION : créer une analyse (docteur uniquement) ───────────
$action = $_GET['action'] ?? '';
if (in_array($userType, ['docteur','medecin']) && $action === 'new' && $hasTable) {
    $patientIdParam = (int)($_GET['patient'] ?? $_POST['patient_id'] ?? 0);
    if (!$patientIdParam) { header('Location: resultats.php'); exit; }

    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$patientIdParam]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) { header('Location: mes_patients.php'); exit; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_analyse']) && !csrf_check()) {
        $erreur = "Jeton de sécurité invalide. Veuillez recharger la page.";
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_analyse'])) {
        $type_analyse = trim($_POST['type_analyse'] ?? '');
        $laboratoire  = trim($_POST['laboratoire']  ?? '');
        $resultats    = trim($_POST['resultats']    ?? '');
        $valeurs      = trim($_POST['valeurs']      ?? '');
        $interp       = trim($_POST['interpretation'] ?? '');
        $dateAnalyse  = $_POST['date_analyse']      ?? date('Y-m-d');
        $statut       = $_POST['statut']            ?? 'normal';

        if (empty($type_analyse)) {
            $erreur = "Le type d'analyse est obligatoire.";
        } else {
            try {
                $cstmt = $pdo->query("SHOW COLUMNS FROM analyses_medicales");
                $cols = array_column($cstmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

                $f = [];
                if (in_array('patient_id', $cols))     $f['patient_id']   = $patientIdParam;
                if (in_array('docteur_id', $cols))     $f['docteur_id']   = $currentId;
                if (in_array('medecin_id', $cols))     $f['medecin_id']   = $currentId;
                if (in_array('type_analyse', $cols))   $f['type_analyse'] = $type_analyse;
                if (in_array('laboratoire', $cols))    $f['laboratoire']  = $laboratoire ?: null;
                if (in_array('resultats', $cols))      $f['resultats']    = $resultats ?: null;
                if (in_array('valeurs', $cols))        $f['valeurs']      = $valeurs ?: null;
                if (in_array('interpretation', $cols)) $f['interpretation'] = $interp ?: null;
                if (in_array('date_analyse', $cols))   $f['date_analyse'] = $dateAnalyse;
                if (in_array('statut', $cols))         $f['statut']       = $statut;
                if (in_array('date_creation', $cols))  $f['date_creation'] = date('Y-m-d H:i:s');

                $colList = implode(',', array_keys($f));
                $ph = implode(',', array_fill(0, count($f), '?'));
                $pdo->prepare("INSERT INTO analyses_medicales ($colList) VALUES ($ph)")->execute(array_values($f));
                $analyseId = (int)$pdo->lastInsertId();

                HashChain::addBlock('ADD_ANALYSE', $analyseId, $currentId, $userType, [
                    'patient_id'   => $patientIdParam,
                    'patient'      => $patient['prenom'] . ' ' . $patient['nom'],
                    'type_analyse' => $type_analyse,
                    'statut'       => $statut,
                    'date_analyse' => $dateAnalyse,
                ], 'analyses_medicales');

                $ok = ['type' => $type_analyse, 'patient' => $patient];
            } catch (PDOException $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }

    $pageTitle = 'Ajouter un résultat';
    $pageActive = 'resultats';
    $breadcrumb = ['Soins', 'Résultats', 'Nouveau'];
    require_once 'includes/header_dashboard.php';
    ?>

    <section class="dash-hero reveal-up" style="background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 50%,#0ea5e9 100%)">
      <div class="dash-hero-content">
        <div class="dash-hero-greet">
          <span class="dash-hero-dot"></span>
          Saisie de résultat d'examen
        </div>
        <h1>Nouveau résultat pour <span><?= htmlspecialchars($patient['prenom'] . ' ' . $patient['nom']) ?></span></h1>
        <p>Ajoutez un résultat d'analyse ou d'imagerie. L'entrée sera signée et liée au dossier du patient.</p>
      </div>
    </section>

    <?php if ($ok): ?>
      <div class="alert-box success reveal-up">
        <div class="ab-icon"><i class="fas fa-check-circle"></i></div>
        <div>
          <strong>Résultat enregistré et signé</strong>
          <p style="margin:4px 0 0;font-size:13px;"><?= htmlspecialchars($ok['type']) ?> · <?= htmlspecialchars($ok['patient']['prenom'] . ' ' . $ok['patient']['nom']) ?></p>
        </div>
        <div class="alert-actions">
          <a href="dossier_patient.php?id=<?= $patientIdParam ?>" class="btn btn-primary"><i class="fas fa-folder-open"></i> Dossier</a>
          <a href="resultats.php" class="btn btn-outline"><i class="fas fa-list"></i> Tous les résultats</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($erreur): ?>
      <div class="alert-box error reveal-up"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <?php if (!$ok): ?>
    <section class="form-shell reveal-up">
      <form method="POST" class="form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="patient_id" value="<?= $patientIdParam ?>">
        <div class="form-head">
          <div class="form-head-icon"><i class="fas fa-flask"></i></div>
          <div>
            <h2>Détails du résultat</h2>
            <p>Saisissez les informations de l'examen.</p>
          </div>
        </div>
        <div class="form-body">
          <div class="form-grid">
            <div class="form-group">
              <label>Type d'analyse <span class="req">*</span></label>
              <div class="input-shell">
                <i class="fas fa-vial"></i>
                <input type="text" name="type_analyse" list="types-analyse" required placeholder="Ex: NFS, Glycémie...">
                <datalist id="types-analyse">
                  <option value="Hémogramme (NFS)">
                  <option value="Glycémie à jeun">
                  <option value="Bilan lipidique">
                  <option value="Bilan hépatique">
                  <option value="Bilan rénal">
                  <option value="TSH / Thyroïde">
                  <option value="Ionogramme">
                  <option value="CRP / Inflammation">
                  <option value="ECBU">
                  <option value="Radiographie thorax">
                  <option value="Échographie">
                  <option value="IRM">
                  <option value="Scanner">
                  <option value="Test HIV">
                  <option value="Test paludisme">
                </datalist>
              </div>
            </div>
            <div class="form-group">
              <label>Laboratoire / Centre</label>
              <div class="input-shell">
                <i class="fas fa-building"></i>
                <input type="text" name="laboratoire" placeholder="CNHU-HKM, Cermel...">
              </div>
            </div>
            <div class="form-group">
              <label>Date de l'analyse</label>
              <div class="input-shell">
                <i class="fas fa-calendar"></i>
                <input type="date" name="date_analyse" value="<?= date('Y-m-d') ?>">
              </div>
            </div>
            <div class="form-group">
              <label>Statut</label>
              <div class="input-shell">
                <i class="fas fa-circle-dot"></i>
                <select name="statut">
                  <option value="normal">Normal</option>
                  <option value="anormal">Anormal</option>
                  <option value="critique">Critique</option>
                  <option value="a_interpreter">À interpréter</option>
                </select>
              </div>
            </div>
          </div>

          <div class="form-group full">
            <label>Résultats (texte libre)</label>
            <textarea name="resultats" rows="4" placeholder="Détail des résultats observés..."></textarea>
          </div>

          <div class="form-group full">
            <label>Valeurs clés / biomarqueurs</label>
            <textarea name="valeurs" rows="3" placeholder="Ex: Hb = 13.2 g/dL (norme 12-16)&#10;Glycémie = 1.02 g/L (norme 0.7-1.1)"></textarea>
          </div>

          <div class="form-group full">
            <label>Interprétation médicale</label>
            <textarea name="interpretation" rows="3" placeholder="Votre interprétation clinique..."></textarea>
          </div>

          <div class="signature-strip">
            <div class="sig-left">
              <i class="fas fa-file-signature"></i>
              <div>
                <strong>Signature du résultat</strong>
                <span>L'ajout sera signé et rendu infalsifiable.</span>
              </div>
            </div>
            <div class="sig-dot"><span></span> Prêt</div>
          </div>
        </div>
        <div class="form-actions">
          <a href="dossier_patient.php?id=<?= $patientIdParam ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Annuler</a>
          <button type="submit" name="submit_analyse" value="1" class="btn btn-primary"><i class="fas fa-cube"></i> Signer et enregistrer</button>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <style>
      .form-shell { max-width:960px; margin:0 auto; }
      .form-card { background:white; border:1px solid var(--line); border-radius:20px; overflow:hidden; box-shadow:0 14px 34px -18px rgba(15,23,42,.12); }
      .form-head { display:flex; align-items:center; gap:16px; padding:24px 28px; border-bottom:1px solid var(--line); background:linear-gradient(135deg, rgba(14,165,233,.08), rgba(3,105,161,.03)); }
      .form-head-icon { width:52px; height:52px; border-radius:14px; background:var(--g-trust); color:white; display:grid; place-items:center; font-size:20px; box-shadow:0 10px 24px -10px rgba(14,165,233,.5); }
      .form-head h2 { font-family:'Plus Jakarta Sans',sans-serif; font-size:18px; font-weight:800; color:var(--ink); margin:0; }
      .form-head p { font-size:13px; color:var(--muted); margin-top:2px; }
      .form-body { padding:28px; }
      .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
      .form-group { display:flex; flex-direction:column; gap:6px; margin-bottom:14px; }
      .form-group.full { grid-column:1/-1; }
      .form-group label { font-size:13px; font-weight:700; color:var(--ink); }
      .form-group label .req { color:#ef4444; }
      .input-shell { display:flex; align-items:center; gap:10px; background:#f8fafc; border:1.5px solid var(--line); border-radius:12px; padding:0 14px; transition:.25s; }
      .input-shell:focus-within { border-color:#0369a1; background:white; box-shadow:0 0 0 3px rgba(14,165,233,.1); }
      .input-shell i { color:var(--muted); width:14px; font-size:14px; }
      .input-shell input, .input-shell select { flex:1; border:none; background:transparent; outline:none; padding:12px 0; font-size:14px; color:var(--ink); font-family:inherit; }
      textarea { width:100%; border:1.5px solid var(--line); background:#f8fafc; border-radius:12px; padding:12px 14px; font-size:14px; color:var(--ink); font-family:inherit; resize:vertical; }
      textarea:focus { outline:none; border-color:#0369a1; background:white; box-shadow:0 0 0 3px rgba(14,165,233,.1); }
      .signature-strip { margin-top:20px; padding:18px 20px; background:linear-gradient(135deg, rgba(14,165,233,.05), rgba(99,102,241,.03)); border:1px solid rgba(14,165,233,.2); border-radius:14px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
      .sig-left { display:flex; align-items:center; gap:14px; }
      .sig-left > i { width:44px; height:44px; display:grid; place-items:center; background:var(--g-trust); color:white; border-radius:12px; font-size:18px; }
      .sig-left strong { display:block; font-size:13px; color:var(--ink); }
      .sig-left span { font-size:12px; color:var(--muted); }
      .sig-dot { display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px; background:rgba(14,165,233,.1); border:1px solid rgba(14,165,233,.3); color:#0369a1; font-size:12px; font-weight:700; font-family:'JetBrains Mono',monospace; }
      .sig-dot span { width:8px; height:8px; border-radius:50%; background:#0ea5e9; animation:pulse 1.5s infinite; }
      @keyframes pulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:.6; transform:scale(1.3); } }
      .form-actions { display:flex; justify-content:flex-end; gap:10px; padding:20px 28px; border-top:1px solid var(--line); background:#f8fafc; }
      .alert-box { display:flex; align-items:center; gap:14px; padding:18px; margin-bottom:20px; border-radius:16px; font-size:14px; }
      .alert-box.success { background:linear-gradient(135deg, rgba(16,185,129,.08), rgba(16,185,129,.03)); border:1px solid rgba(16,185,129,.3); color:#065f46; }
      .alert-box.error { background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.25); color:#b91c1c; }
      .ab-icon { width:44px; height:44px; border-radius:12px; background:var(--g-emerald); color:white; display:grid; place-items:center; font-size:18px; flex-shrink:0; }
      .alert-box > div { flex:1; }
      .alert-actions { display:flex; gap:8px; flex-wrap:wrap; }
      .btn { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border-radius:12px; font-size:13px; font-weight:700; text-decoration:none; border:none; cursor:pointer; transition:.3s; font-family:inherit; }
      .btn-primary { background:var(--g-trust); color:white; }
      .btn-primary:hover { transform:translateY(-1px); box-shadow:0 10px 24px -8px rgba(14,165,233,.4); }
      .btn-outline { background:white; border:1px solid var(--line); color:var(--ink); }
      .btn-outline:hover { border-color:#0369a1; color:#0369a1; }
      @media (max-width:640px) { .form-grid { grid-template-columns:1fr; } }
    </style>

    <?php
    require_once 'includes/footer_dashboard.php';
    exit;
}

// ── MODE LISTE ─────────────────────────────
$resultats = [];
if ($hasTable) {
    if (in_array($userType, ['docteur','medecin'])) {
        $stmt = $pdo->prepare("
            SELECT a.*, p.id AS patient_id, p.prenom AS p_prenom, p.nom AS p_nom, p.groupe_sanguin
            FROM analyses_medicales a
            JOIN patients p ON p.id = a.patient_id
            WHERE a.docteur_id = ? OR a.medecin_id = ?
            ORDER BY a.date_analyse DESC, a.id DESC
            LIMIT 100
        ");
        $stmt->execute([$currentId, $currentId]);
        $resultats = $stmt->fetchAll();
    } elseif ($userType === 'patient') {
        $stmt = $pdo->prepare("
            SELECT a.*, CONCAT(d.prenom,' ',d.nom) AS docteur_nom, d.specialite
            FROM analyses_medicales a
            LEFT JOIN docteurs d ON d.id = a.docteur_id OR d.id = a.medecin_id
            WHERE a.patient_id = ?
            ORDER BY a.date_analyse DESC
        ");
        $stmt->execute([$patientId]);
        $resultats = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query("
            SELECT a.*, p.prenom AS p_prenom, p.nom AS p_nom, CONCAT(d.prenom,' ',d.nom) AS docteur_nom
            FROM analyses_medicales a
            JOIN patients p ON p.id = a.patient_id
            LEFT JOIN docteurs d ON d.id = a.docteur_id
            ORDER BY a.date_analyse DESC LIMIT 100
        ");
        $resultats = $stmt->fetchAll();
    }
}

$pageTitle = 'Résultats d\'analyses';
$pageActive = 'resultats';
$breadcrumb = $userType === 'patient' ? ['Espace patient', 'Résultats'] : ['Soins', 'Résultats d\'analyses'];
require_once 'includes/header_dashboard.php';
?>

<!-- HERO -->
<section class="dash-hero reveal-up" style="background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 50%,#0ea5e9 100%)">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span>
      Laboratoire · Imagerie · Biologie
    </div>
    <h1><?= $userType === 'patient' ? 'Vos <span>résultats d\'examens.</span>' : 'Résultats <span>d\'analyses.</span>' ?></h1>
    <p><?= $userType === 'patient'
      ? 'Tous vos examens biologiques et d\'imagerie, accessibles à tout moment.'
      : 'Centralise les analyses, examens d\'imagerie et biomarqueurs de vos patients.' ?></p>

    <?php if (in_array($userType, ['docteur','medecin']) && $hasTable): ?>
    <div class="dash-hero-actions">
      <a href="mes_patients.php" class="btn btn-white">
        <i class="fas fa-vial"></i> Depuis un patient
      </a>
    </div>
    <?php endif; ?>
  </div>

  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Total résultats</span>
      <i class="fas fa-flask"></i>
    </div>
    <div class="dash-hero-card-hash"><?= count($resultats) ?></div>
    <div class="dash-hero-card-meta">
      <span><i class="fas fa-shield-halved"></i> Certifiés</span>
    </div>
  </div>
</section>

<?php if (!$hasTable): ?>
  <div class="alert-box warn reveal-up">
    <i class="fas fa-triangle-exclamation"></i>
    <div>
      <strong>Table <code>analyses_medicales</code> absente</strong>
      <p style="margin:4px 0 0;font-size:13px;">Cette fonctionnalité nécessite une table dédiée. Vous pouvez la créer avec le SQL ci-dessous.</p>
      <details style="margin-top:8px;">
        <summary style="cursor:pointer;font-weight:700;">Afficher le SQL</summary>
        <pre style="margin-top:10px; padding:14px; background:#0f172a; color:#e2e8f0; border-radius:10px; font-size:12px; overflow-x:auto;">CREATE TABLE analyses_medicales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  docteur_id INT,
  type_analyse VARCHAR(200) NOT NULL,
  laboratoire VARCHAR(200),
  resultats TEXT,
  valeurs TEXT,
  interpretation TEXT,
  date_analyse DATE,
  statut ENUM('normal','anormal','critique','a_interpreter') DEFAULT 'normal',
  date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (docteur_id) REFERENCES docteurs(id)
);</pre>
      </details>
    </div>
  </div>
<?php endif; ?>

<!-- LISTE -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-flask" style="color:#0369a1"></i> Tous les résultats</h3>
      <p>Classés par date d'analyse</p>
    </div>
    <span class="pill-count"><?= count($resultats) ?></span>
  </div>

  <?php if (empty($resultats)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-vials"></i></div>
      <h4>Aucun résultat</h4>
      <p><?= $userType === 'patient' ? 'Vos examens biologiques apparaîtront ici.' : 'Commencez par ajouter un résultat depuis un dossier patient.' ?></p>
    </div>
  <?php else: ?>
    <div class="result-grid">
      <?php foreach ($resultats as $r):
        $statut = $r['statut'] ?? 'normal';
        $cls = match($statut) {
          'normal'   => 'ok',
          'anormal'  => 'warn',
          'critique' => 'danger',
          default    => 'muted',
        };
        $ic = match($statut) {
          'normal'   => 'fa-check',
          'anormal'  => 'fa-triangle-exclamation',
          'critique' => 'fa-circle-exclamation',
          default    => 'fa-question',
        };
      ?>
        <div class="result-card">
          <div class="result-head">
            <div class="result-icon"><i class="fas fa-flask"></i></div>
            <div class="result-title">
              <h4><?= htmlspecialchars($r['type_analyse']) ?></h4>
              <?php if ($userType !== 'patient'): ?>
                <span><i class="fas fa-user"></i> <?= htmlspecialchars(($r['p_prenom'] ?? '') . ' ' . ($r['p_nom'] ?? '')) ?></span>
              <?php else: ?>
                <span><i class="fas fa-user-doctor"></i> Dr. <?= htmlspecialchars($r['docteur_nom'] ?? '—') ?></span>
              <?php endif; ?>
            </div>
            <span class="result-status <?= $cls ?>">
              <i class="fas <?= $ic ?>"></i> <?= ucfirst($statut) ?>
            </span>
          </div>

          <?php if (!empty($r['resultats'])): ?>
            <div class="result-body">
              <p><?= nl2br(htmlspecialchars(mb_strimwidth($r['resultats'], 0, 200, '...'))) ?></p>
            </div>
          <?php endif; ?>

          <?php if (!empty($r['valeurs'])): ?>
            <div class="result-values">
              <span class="rv-label"><i class="fas fa-chart-line"></i> Valeurs clés</span>
              <pre><?= htmlspecialchars(mb_strimwidth($r['valeurs'], 0, 300, '...')) ?></pre>
            </div>
          <?php endif; ?>

          <div class="result-footer">
            <?php if (!empty($r['laboratoire'])): ?>
              <span><i class="fas fa-building"></i> <?= htmlspecialchars($r['laboratoire']) ?></span>
            <?php endif; ?>
            <span class="result-date">
              <i class="fas fa-calendar"></i>
              <?= $r['date_analyse'] ? date('d/m/Y', strtotime($r['date_analyse'])) : '—' ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<style>
  .dash-hero-card-hash { font-size: 38px; font-weight: 800; font-family: 'Plus Jakarta Sans', sans-serif; }
  .alert-box { display: flex; align-items: flex-start; gap: 14px; padding: 18px; margin-bottom: 20px; border-radius: 16px; font-size: 14px; }
  .alert-box.warn { background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.3); color: #92400e; }
  .alert-box > div { flex: 1; }
  .alert-box strong { display: block; font-size: 14px; }

  .result-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; }
  .result-card { background: white; border: 1px solid var(--line); border-radius: 16px; padding: 20px; transition: .25s; display: flex; flex-direction: column; gap: 12px; }
  .result-card:hover { border-color: rgba(14,165,233,.3); box-shadow: 0 12px 28px -14px rgba(15,23,42,.12); transform: translateY(-2px); }

  .result-head { display: flex; align-items: flex-start; gap: 12px; }
  .result-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--g-trust); color: white; display: grid; place-items: center; font-size: 18px; flex-shrink: 0; box-shadow: 0 8px 18px -6px rgba(14,165,233,.5); }
  .result-title { flex: 1; min-width: 0; }
  .result-title h4 { font-size: 15px; font-weight: 800; color: var(--ink); margin: 0 0 4px; }
  .result-title span { font-size: 12px; color: var(--muted); display: flex; align-items: center; gap: 6px; }

  .result-status { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; padding: 5px 10px; border-radius: 6px; flex-shrink: 0; display: inline-flex; align-items: center; gap: 5px; }
  .result-status.ok     { background: rgba(16,185,129,.12); color: var(--forest); }
  .result-status.warn   { background: rgba(245,158,11,.12); color: #92400e; }
  .result-status.danger { background: rgba(239,68,68,.12); color: #dc2626; }
  .result-status.muted  { background: rgba(100,116,139,.12); color: var(--muted); }

  .result-body p { font-size: 13px; color: var(--muted); line-height: 1.6; margin: 0; }
  .result-values { padding: 12px; background: #0f172a; border-radius: 10px; }
  .rv-label { display: flex; align-items: center; gap: 6px; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
  .result-values pre { color: #e2e8f0; font-family: 'JetBrains Mono', monospace; font-size: 11px; line-height: 1.5; margin: 0; white-space: pre-wrap; overflow-x: auto; }

  .result-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px dashed var(--line); font-size: 11px; color: var(--muted); }
  .result-footer > span { display: inline-flex; align-items: center; gap: 5px; }
  .result-date { font-family: 'JetBrains Mono', monospace; font-weight: 600; }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
