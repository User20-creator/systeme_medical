<?php
// gestion_hopitaux.php — CRUD hôpitaux (admin uniquement)
require_once 'config.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: connexion2.php'); exit;
}

$message = '';
$erreur  = '';
$mode    = $_GET['mode'] ?? 'liste'; // liste | ajouter | modifier
$editId  = (int)($_GET['id'] ?? 0);

// SUPPRIMER — passe par POST avec CSRF (plus de DELETE via GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer') {
    if (!csrf_check()) {
        $erreur = "Jeton de sécurité invalide. Veuillez recharger la page.";
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $nbLies = $pdo->prepare("
                SELECT (SELECT COUNT(*) FROM patients   WHERE hopital_reference     = ?)
                     + (SELECT COUNT(*) FROM infirmiers WHERE hopital_principal_id  = ?)
                     + (SELECT COUNT(*) FROM docteurs   WHERE hopital_id            = ?) AS total
            ");
            $nbLies->execute([$id, $id, $id]);
            $total = (int)$nbLies->fetch()['total'];

            if ($total > 0) {
                $erreur = "Impossible de supprimer : cet hôpital est lié à $total utilisateur(s).";
            } else {
                $pdo->prepare("DELETE FROM hopitaux WHERE id = ?")->execute([$id]);
                $message = "Hôpital supprimé avec succès.";
            }
        }
    }
}

// AJOUTER / MODIFIER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'supprimer') {
    if (!csrf_check()) {
        $erreur = "Jeton de sécurité invalide. Veuillez recharger la page.";
    } else {
        $nom        = trim($_POST['nom']        ?? '');
        $ville      = trim($_POST['ville']      ?? '');
        $telephone  = trim($_POST['telephone']  ?? '');
        $email      = trim($_POST['email']      ?? '');
        $lits       = (int)($_POST['nombre_lits']       ?? 0);
        $medecins   = (int)($_POST['nombre_medecins']    ?? 0);
        $ambulances = (int)($_POST['nombre_ambulances']  ?? 0);
        $labos      = (int)($_POST['nombre_labos']       ?? 0);
        $etudiants  = (int)($_POST['nombre_etudiants']   ?? 0);
        $image      = trim($_POST['image']      ?? '');
        $statut     = ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif';
        $postId     = (int)($_POST['id'] ?? 0);

        if (empty($nom) || empty($ville)) {
            $erreur = "Le nom et la ville sont obligatoires.";
        } else {
            try {
                if ($postId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE hopitaux SET nom=?, ville=?, telephone=?, email=?,
                            nombre_lits=?, nombre_medecins=?, nombre_ambulances=?,
                            nombre_labos=?, nombre_etudiants=?, `Image`=?, statut=?
                        WHERE id=?
                    ");
                    $stmt->execute([$nom,$ville,$telephone,$email,
                        $lits,$medecins,$ambulances,$labos,$etudiants,$image,$statut,$postId]);
                    $message = "Hôpital modifié avec succès.";
                } else {
                    $blockchain = strtoupper(hash('sha256', $nom.$ville.time()));
                    $stmt = $pdo->prepare("
                        INSERT INTO hopitaux
                            (nom,ville,telephone,email,code_blockchain,
                             nombre_lits,nombre_medecins,nombre_ambulances,
                             nombre_labos,nombre_etudiants,`Image`,statut)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,'actif')
                    ");
                    $stmt->execute([$nom,$ville,$telephone,$email,$blockchain,
                        $lits,$medecins,$ambulances,$labos,$etudiants,$image]);
                    $message = "Hôpital ajouté avec succès.";
                }
                $mode = 'liste';
            } catch (PDOException $e) {
                error_log('gestion_hopitaux: ' . $e->getMessage());
                $erreur = "Erreur lors de l'enregistrement.";
            }
        }
    }
}

// Charger hôpital pour modification
$hopitalEdit = null;
if ($mode === 'modifier' && $editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM hopitaux WHERE id=?");
    $stmt->execute([$editId]);
    $hopitalEdit = $stmt->fetch();
    if (!$hopitalEdit) { $mode = 'liste'; }
}

// Liste des hôpitaux
$hopitaux = $pdo->query("
    SELECT h.*,
        (SELECT COUNT(*) FROM patients WHERE hopital_reference=h.id) AS nb_patients,
        (SELECT COUNT(*) FROM infirmiers WHERE hopital_principal_id=h.id) AS nb_infirmiers,
        (SELECT COUNT(*) FROM docteurs WHERE hopital_id=h.id) AS nb_docteurs
    FROM hopitaux h ORDER BY h.nom
")->fetchAll();

$pageTitle  = 'Gestion des hôpitaux';
$pageActive = 'hopitaux';
$breadcrumb = ['Admin', 'Hôpitaux'];
require_once 'includes/header_dashboard.php';
?>

<?php
  $totalActifs   = count(array_filter($hopitaux, fn($h) => ($h['statut'] ?? '') === 'actif'));
  $totalPatients = array_sum(array_column($hopitaux, 'nb_patients'));
?>
<!-- HERO PREMIUM (style creer_patient) -->
<section class="creer-hero reveal-up">
  <div class="ch-decor">
    <div class="ch-orb ch-orb-1"></div>
    <div class="ch-orb ch-orb-2"></div>
    <svg class="ch-grid" viewBox="0 0 200 200" preserveAspectRatio="none" aria-hidden="true">
      <defs>
        <pattern id="chGridPatHop" width="22" height="22" patternUnits="userSpaceOnUse">
          <path d="M 22 0 L 0 0 0 22" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="1"/>
        </pattern>
      </defs>
      <rect width="200" height="200" fill="url(#chGridPatHop)"/>
    </svg>
  </div>

  <div class="ch-content">
    <span class="ch-eyebrow">
      <span class="ch-dot"></span>
      Pilotage du réseau hospitalier national
    </span>

    <h1 class="ch-title">
      Gestion<br>
      <span class="ch-title-hl">des hôpitaux</span>
    </h1>

    <p class="ch-lead">
      <strong><?= count($hopitaux) ?></strong> établissements enregistrés.
      Ajoutez, modifiez ou désactivez les hôpitaux du <strong>réseau national</strong> de santé du Bénin.
    </p>

    <div class="ch-chips">
      <div class="ch-chip">
        <div class="ch-chip-icon"><i class="fas fa-hospital"></i></div>
        <div class="ch-chip-text">
          <strong><?= $totalActifs ?> actifs</strong>
          <span>Établissements opérationnels</span>
        </div>
      </div>
      <div class="ch-chip">
        <div class="ch-chip-icon"><i class="fas fa-user-injured"></i></div>
        <div class="ch-chip-text">
          <strong><?= $totalPatients ?> patients</strong>
          <span>Affiliés au réseau</span>
        </div>
      </div>
      <div class="ch-chip">
        <div class="ch-chip-icon"><i class="fas fa-shield-halved"></i></div>
        <div class="ch-chip-text">
          <strong>Données certifiées</strong>
          <span>Couverture nationale</span>
        </div>
      </div>
    </div>

    <div class="ch-actions">
      <?php if ($mode === 'liste'): ?>
        <a href="?mode=ajouter" class="ch-btn ch-btn-primary">
          <i class="fas fa-plus-circle"></i> Nouvel hôpital
        </a>
      <?php else: ?>
        <a href="gestion_hopitaux.php" class="ch-btn ch-btn-primary">
          <i class="fas fa-arrow-left"></i> Retour à la liste
        </a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($message): ?>
  <div class="alert-box success reveal-up">
    <i class="fas fa-check-circle"></i>
    <div><?= htmlspecialchars($message) ?></div>
  </div>
<?php endif; ?>
<?php if ($erreur): ?>
  <div class="alert-box error reveal-up">
    <i class="fas fa-exclamation-triangle"></i>
    <div><?= htmlspecialchars($erreur) ?></div>
  </div>
<?php endif; ?>

<?php if ($mode === 'liste'): ?>
<!-- LISTE DES HÔPITAUX -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-hospital" style="color:var(--forest)"></i> <?= count($hopitaux) ?> établissement<?= count($hopitaux) > 1 ? 's' : '' ?></h3>
      <p>Triés par ordre alphabétique</p>
    </div>
  </div>

  <?php if (empty($hopitaux)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-hospital"></i></div>
      <h4>Aucun hôpital enregistré</h4>
      <p>Cliquez sur « Nouvel hôpital » pour commencer.</p>
    </div>
  <?php else: ?>
    <div class="hopital-list">
      <?php foreach ($hopitaux as $h): ?>
        <div class="hopital-card <?= ($h['statut'] ?? 'actif') !== 'actif' ? 'is-inactive' : '' ?>">
          <div class="hopital-icon"><i class="fas fa-hospital"></i></div>
          <div class="hopital-body">
            <div class="hopital-head">
              <h4><?= htmlspecialchars($h['nom']) ?></h4>
              <span class="hopital-badge <?= ($h['statut'] ?? 'actif') === 'actif' ? 'ok' : 'ko' ?>">
                <?= ucfirst($h['statut'] ?? 'actif') ?>
              </span>
            </div>
            <p class="hopital-meta">
              <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($h['ville']) ?></span>
              <?php if ($h['telephone']): ?><span><i class="fas fa-phone"></i> <?= htmlspecialchars($h['telephone']) ?></span><?php endif; ?>
              <?php if ($h['email']): ?><span><i class="fas fa-envelope"></i> <?= htmlspecialchars($h['email']) ?></span><?php endif; ?>
            </p>
            <div class="hopital-stats">
              <span><i class="fas fa-bed"></i> <?= (int)($h['nombre_lits'] ?? 0) ?> lits</span>
              <span><i class="fas fa-user-injured"></i> <?= (int)$h['nb_patients'] ?> patients</span>
              <span><i class="fas fa-user-nurse"></i> <?= (int)$h['nb_infirmiers'] ?> infirmiers</span>
              <span><i class="fas fa-user-md"></i> <?= (int)$h['nb_docteurs'] ?> docteurs</span>
              <span><i class="fas fa-flask"></i> <?= (int)($h['nombre_labos'] ?? 0) ?> labos</span>
              <span><i class="fas fa-ambulance"></i> <?= (int)($h['nombre_ambulances'] ?? 0) ?> ambulances</span>
            </div>
          </div>
          <div class="hopital-actions">
            <a href="?mode=modifier&id=<?= (int)$h['id'] ?>" class="btn btn-outline btn-sm">
              <i class="fas fa-edit"></i> Modifier
            </a>
            <form method="POST" action="gestion_hopitaux.php" style="display:inline" onsubmit="return confirm('Supprimer cet hôpital ?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="supprimer">
              <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">
                <i class="fas fa-trash"></i> Supprimer
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php else: // mode = ajouter | modifier ?>
<!-- FORMULAIRE -->
<section class="form-shell reveal-up">
  <form method="POST" action="gestion_hopitaux.php" class="form-card" autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($mode === 'modifier' && $hopitalEdit): ?>
      <input type="hidden" name="id" value="<?= (int)$hopitalEdit['id'] ?>">
    <?php endif; ?>
    <div class="form-head forest">
      <div class="form-head-icon forest-icon"><i class="fas fa-hospital"></i></div>
      <div>
        <h2><?= $mode === 'modifier' ? "Modifier l'hôpital" : "Nouvel hôpital" ?></h2>
        <p>Informations de l'établissement et capacités d'accueil.</p>
      </div>
    </div>

    <div class="form-body">
      <div class="section-divider"><i class="fas fa-info-circle"></i> Informations générales</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Nom <span class="req">*</span></label>
          <div class="input-shell"><i class="fas fa-hospital"></i>
            <input type="text" name="nom" required placeholder="Nom officiel"
                   value="<?= htmlspecialchars($hopitalEdit['nom'] ?? $_POST['nom'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Ville <span class="req">*</span></label>
          <div class="input-shell"><i class="fas fa-map-marker-alt"></i>
            <input type="text" name="ville" required placeholder="Cotonou, Parakou..."
                   value="<?= htmlspecialchars($hopitalEdit['ville'] ?? $_POST['ville'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Téléphone</label>
          <div class="input-shell"><i class="fas fa-phone"></i>
            <input type="text" name="telephone" placeholder="+229 00 00 00 00"
                   value="<?= htmlspecialchars($hopitalEdit['telephone'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Email</label>
          <div class="input-shell"><i class="fas fa-envelope"></i>
            <input type="email" name="email" placeholder="contact@hopital.bj"
                   value="<?= htmlspecialchars($hopitalEdit['email'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Image (chemin)</label>
          <div class="input-shell"><i class="fas fa-image"></i>
            <input type="text" name="image" placeholder="images/cnhu.jpg"
                   value="<?= htmlspecialchars($hopitalEdit['Image'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Statut</label>
          <div class="input-shell"><i class="fas fa-toggle-on"></i>
            <select name="statut">
              <option value="actif"   <?= ($hopitalEdit['statut'] ?? 'actif') === 'actif'   ? 'selected' : '' ?>>Actif</option>
              <option value="inactif" <?= ($hopitalEdit['statut'] ?? '') === 'inactif' ? 'selected' : '' ?>>Inactif</option>
            </select>
          </div>
        </div>
      </div>

      <div class="section-divider"><i class="fas fa-chart-bar"></i> Capacités d'accueil</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Lits</label>
          <div class="input-shell"><i class="fas fa-bed"></i>
            <input type="number" name="nombre_lits" min="0" value="<?= (int)($hopitalEdit['nombre_lits'] ?? 0) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Médecins</label>
          <div class="input-shell"><i class="fas fa-user-md"></i>
            <input type="number" name="nombre_medecins" min="0" value="<?= (int)($hopitalEdit['nombre_medecins'] ?? 0) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Ambulances</label>
          <div class="input-shell"><i class="fas fa-ambulance"></i>
            <input type="number" name="nombre_ambulances" min="0" value="<?= (int)($hopitalEdit['nombre_ambulances'] ?? 0) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Laboratoires</label>
          <div class="input-shell"><i class="fas fa-flask"></i>
            <input type="number" name="nombre_labos" min="0" value="<?= (int)($hopitalEdit['nombre_labos'] ?? 0) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Étudiants</label>
          <div class="input-shell"><i class="fas fa-graduation-cap"></i>
            <input type="number" name="nombre_etudiants" min="0" value="<?= (int)($hopitalEdit['nombre_etudiants'] ?? 0) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <a href="gestion_hopitaux.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Annuler
      </a>
      <button type="submit" class="btn btn-forest">
        <i class="fas fa-save"></i>
        <?= $mode === 'modifier' ? 'Enregistrer les modifications' : "Ajouter l'hôpital" ?>
      </button>
    </div>
  </form>
</section>
<?php endif; ?>

<style>
/* ================== HERO PREMIUM (identique à creer_patient) ================== */
.creer-hero {
  position:relative; overflow:hidden;
  background:
    radial-gradient(ellipse at top right, rgba(16,185,129,.35), transparent 55%),
    radial-gradient(ellipse at bottom left, rgba(5,150,105,.25), transparent 60%),
    linear-gradient(135deg,#0e2a1a 0%, #14532d 45%, #166534 100%);
  color:white; border-radius:28px;
  padding:44px 48px 40px;
  margin-bottom:24px;
  box-shadow:0 24px 60px -24px rgba(14,42,26,.55);
}
.ch-decor { position:absolute; inset:0; pointer-events:none; }
.ch-grid  { position:absolute; inset:0; width:100%; height:100%; opacity:.7; }
.ch-orb { position:absolute; border-radius:50%; filter:blur(60px); opacity:.55; }
.ch-orb-1 { width:360px; height:360px; top:-120px; right:-100px; background:radial-gradient(circle, rgba(52,211,153,.6), transparent 70%); }
.ch-orb-2 { width:280px; height:280px; bottom:-100px; left:-60px; background:radial-gradient(circle, rgba(6,95,70,.5), transparent 70%); }
.ch-content { position:relative; z-index:2; max-width:780px; }
.ch-eyebrow {
  display:inline-flex; align-items:center; gap:9px;
  padding:7px 16px; background:rgba(255,255,255,.09);
  border:1px solid rgba(255,255,255,.18); border-radius:999px;
  font-size:12px; font-weight:600; letter-spacing:.03em;
  backdrop-filter:blur(8px); margin-bottom:20px;
}
.ch-dot {
  width:8px; height:8px; border-radius:50%;
  background:#34d399; box-shadow:0 0 14px #34d399, 0 0 4px #a7f3d0;
  animation:chPulse 2s ease-in-out infinite;
}
@keyframes chPulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.3)} }
.ch-title {
  font-family:'Plus Jakarta Sans', sans-serif;
  font-size:clamp(30px, 4vw, 46px); font-weight:800;
  line-height:1.05; letter-spacing:-.025em;
  margin:0 0 16px; color:white;
}
.ch-title-hl {
  background:linear-gradient(135deg, #a7f3d0 0%, #6ee7b7 50%, #5eead4 100%);
  -webkit-background-clip:text; background-clip:text; color:transparent;
  position:relative; display:inline-block;
}
.ch-title-hl::after {
  content:''; position:absolute; left:0; right:0; bottom:-4px; height:3px;
  background:linear-gradient(90deg, transparent, rgba(110,231,183,.6), transparent);
  border-radius:2px;
}
.ch-lead { font-size:15px; color:rgba(255,255,255,.82); line-height:1.65; max-width:640px; margin:0 0 28px; }
.ch-lead strong { color:#a7f3d0; font-weight:700; }
.ch-chips { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:24px; }
.ch-chip {
  display:flex; align-items:center; gap:12px;
  padding:14px 16px; background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.12); border-radius:14px;
  backdrop-filter:blur(12px); transition:.25s;
}
.ch-chip:hover { background:rgba(255,255,255,.1); border-color:rgba(167,243,208,.3); transform:translateY(-2px); }
.ch-chip-icon {
  width:40px; height:40px; flex-shrink:0; border-radius:11px;
  background:linear-gradient(135deg, rgba(52,211,153,.25), rgba(16,185,129,.15));
  border:1px solid rgba(167,243,208,.25);
  display:grid; place-items:center; color:#a7f3d0; font-size:15px;
}
.ch-chip-text strong { display:block; font-size:13px; font-weight:700; color:white; margin-bottom:2px; line-height:1.2; }
.ch-chip-text span { font-size:11px; color:rgba(255,255,255,.6); letter-spacing:.01em; }

.ch-actions { display:flex; gap:10px; flex-wrap:wrap; }
.ch-btn {
  display:inline-flex; align-items:center; gap:8px;
  padding:12px 22px; border-radius:12px;
  font-size:13px; font-weight:700;
  text-decoration:none; transition:.25s; font-family:inherit;
  border:1px solid transparent;
}
.ch-btn-primary {
  background:white; color:#14532d;
  box-shadow:0 8px 24px -10px rgba(167,243,208,.4);
}
.ch-btn-primary:hover { transform:translateY(-1px); box-shadow:0 12px 28px -10px rgba(167,243,208,.55); }

@media (max-width: 860px) { .creer-hero { padding:32px 24px; border-radius:22px; } .ch-chips { grid-template-columns:1fr; } }
/* ================== FIN HERO ================== */

.alert-box { display:flex; align-items:center; gap:12px; padding:14px 18px; margin-bottom:20px; border-radius:14px; font-size:14px; }
.alert-box.success { background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.25); color:#065f46; }
.alert-box.error   { background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.25); color:#b91c1c; }
.alert-box i { font-size:18px; }

/* LISTE */
.hopital-list { display:flex; flex-direction:column; gap:14px; }
.hopital-card {
  display:flex; align-items:center; gap:18px;
  background:#f8fafc; border:1px solid var(--line); border-left:4px solid var(--forest);
  border-radius:14px; padding:18px 22px; transition:.25s;
}
.hopital-card:hover { background:white; box-shadow:0 8px 22px -10px rgba(15,23,42,.12); transform:translateY(-1px); }
.hopital-card.is-inactive { border-left-color:#94a3b8; opacity:.7; }
.hopital-icon { width:48px; height:48px; border-radius:12px; background:var(--g-forest); color:white; display:grid; place-items:center; font-size:18px; flex-shrink:0; }
.hopital-card.is-inactive .hopital-icon { background:linear-gradient(135deg,#94a3b8,#64748b); }
.hopital-body { flex:1; min-width:0; }
.hopital-head { display:flex; align-items:center; gap:10px; margin-bottom:4px; flex-wrap:wrap; }
.hopital-head h4 { font-family:'Plus Jakarta Sans',sans-serif; font-size:15px; font-weight:800; color:var(--ink); margin:0; }
.hopital-badge { padding:3px 10px; border-radius:999px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.hopital-badge.ok { background:rgba(16,185,129,.15); color:#047857; }
.hopital-badge.ko { background:rgba(239,68,68,.15); color:#b91c1c; }
.hopital-meta { display:flex; flex-wrap:wrap; gap:14px; font-size:12px; color:var(--muted); margin-bottom:8px; }
.hopital-meta i { color:var(--forest); margin-right:4px; }
.hopital-stats { display:flex; flex-wrap:wrap; gap:8px; font-size:12px; color:var(--muted); }
.hopital-stats span { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; background:white; border:1px solid var(--line); border-radius:999px; }
.hopital-stats i { color:var(--forest); font-size:11px; }
.hopital-actions { display:flex; gap:8px; flex-shrink:0; }

/* FORMULAIRE */
.form-shell { max-width:920px; margin:0 auto; }
.form-card { background:white; border:1px solid var(--line); border-radius:20px; overflow:hidden; box-shadow:0 14px 34px -18px rgba(15,23,42,.12); }
.form-head { display:flex; align-items:center; gap:16px; padding:24px 28px; border-bottom:1px solid var(--line); }
.form-head.forest { background:linear-gradient(135deg, rgba(26,71,42,.08), rgba(16,185,129,.04)); }
.form-head-icon { width:52px; height:52px; border-radius:14px; color:white; display:grid; place-items:center; font-size:20px; }
.form-head-icon.forest-icon { background:var(--g-forest); box-shadow:0 10px 24px -10px rgba(26,71,42,.5); }
.form-head h2 { font-family:'Plus Jakarta Sans',sans-serif; font-size:18px; font-weight:800; color:var(--ink); margin:0; }
.form-head p { font-size:13px; color:var(--muted); margin-top:2px; }
.form-head .req { color:#ef4444; }

.form-body { padding:28px; }
.section-divider { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:var(--forest); padding:18px 0 12px; margin-top:8px; border-bottom:2px solid rgba(26,71,42,.1); margin-bottom:18px; display:flex; align-items:center; gap:8px; }
.section-divider:first-child { margin-top:0; padding-top:0; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-group label { font-size:13px; font-weight:700; color:var(--ink); display:flex; align-items:center; gap:6px; }
.form-group label .req { color:#ef4444; }
.input-shell { display:flex; align-items:center; gap:10px; background:#f8fafc; border:1.5px solid var(--line); border-radius:12px; padding:0 14px; transition:.25s; }
.input-shell:focus-within { border-color:var(--forest); background:white; box-shadow:0 0 0 3px rgba(26,71,42,.1); }
.input-shell i { color:var(--muted); width:14px; font-size:14px; }
.input-shell input, .input-shell select { flex:1; border:none; background:transparent; outline:none; padding:12px 0; font-size:14px; color:var(--ink); font-family:inherit; }

.form-actions { display:flex; justify-content:flex-end; gap:10px; padding:20px 28px; border-top:1px solid var(--line); background:#f8fafc; }

.btn { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border-radius:12px; font-size:13px; font-weight:700; text-decoration:none; border:none; cursor:pointer; transition:.3s; font-family:inherit; }
.btn-sm { padding:7px 14px; font-size:12px; }
.btn-forest { background:var(--g-forest); color:white; }
.btn-forest:hover { transform:translateY(-1px); box-shadow:0 10px 24px -8px rgba(26,71,42,.4); }
.btn-outline { background:white; border:1px solid var(--line); color:var(--ink); }
.btn-outline:hover { border-color:var(--forest); color:var(--forest); }
.btn-danger { background:rgba(239,68,68,.1); color:#b91c1c; border:1px solid rgba(239,68,68,.25); }
.btn-danger:hover { background:#dc2626; color:white; }
.btn-white { background:white; color:var(--forest); }
.btn-white:hover { background:rgba(255,255,255,.9); transform:translateY(-1px); }

.empty { text-align:center; padding:40px 20px; }
.empty-icon { width:72px; height:72px; margin:0 auto 16px; background:rgba(26,71,42,.08); border-radius:50%; display:grid; place-items:center; color:var(--forest); font-size:28px; }
.empty h4 { font-size:16px; font-weight:700; margin-bottom:6px; color:var(--ink); }
.empty p { font-size:13px; color:var(--muted); }

@media (max-width: 720px) {
  .form-grid { grid-template-columns:1fr; }
  .hopital-card { flex-direction:column; align-items:stretch; }
  .hopital-actions { justify-content:flex-end; }
}
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
