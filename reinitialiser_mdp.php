<?php
// reinitialiser_mdp.php — Réinitialisation de mot de passe (admin)
require_once 'config.php';
require_once 'includes/hash_chain.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: connexion2.php'); exit;
}

$adminId = (int)$_SESSION['admin_id'];
$erreur  = '';
$done    = null;

// Récupérer le paramètre (type + id)
$type = $_GET['type'] ?? $_POST['type'] ?? '';
$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!in_array($type, ['patient', 'docteur', 'infirmier']) || !$id) {
    header('Location: liste_utilisateurs.php'); exit;
}

// Table + label selon type
$tableMap = [
    'patient'   => ['table' => 'patients',   'label' => 'patient',   'avatar' => 'emerald', 'gradient' => 'var(--g-emerald)'],
    'docteur'   => ['table' => 'docteurs',   'label' => 'docteur',   'avatar' => 'forest',  'gradient' => 'var(--g-forest)'],
    'infirmier' => ['table' => 'infirmiers', 'label' => 'infirmier', 'avatar' => 'trust',   'gradient' => 'var(--g-trust)'],
];
$cfg = $tableMap[$type];

// Récupérer l'utilisateur
try {
    $stmt = $pdo->prepare("SELECT * FROM {$cfg['table']} WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = null;
}

if (!$user) { header('Location: liste_utilisateurs.php'); exit; }

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $erreur = "Jeton de sécurité invalide. Veuillez recharger la page.";
    }
    $action = $_POST['action'] ?? '';
    $newMdp = '';

    if (!$erreur && $action === 'generer') {
        // Mot de passe aléatoire
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 12; $i++) $newMdp .= $chars[random_int(0, strlen($chars) - 1)];
    } elseif (!$erreur && $action === 'manuel') {
        $newMdp = $_POST['nouveau_mdp'] ?? '';
        $conf   = $_POST['confirmation'] ?? '';
        if (strlen($newMdp) < 8) $erreur = "Le mot de passe doit contenir au moins 8 caractères.";
        elseif ($newMdp !== $conf) $erreur = "Les mots de passe ne correspondent pas.";
    }

    if (!$erreur && !empty($newMdp)) {
        try {
            $motChiffre = password_hash($newMdp, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE {$cfg['table']} SET mot_de_passe = ? WHERE id = ?");
            $upd->execute([$motChiffre, $id]);

            HashChain::addBlock('RESET_PASSWORD', $id, $adminId, 'admin', [
                'cible_type' => $type,
                'cible_nom'  => $user['prenom'] . ' ' . $user['nom'],
                'reset_par'  => 'admin',
                'reset_at'   => date('c'),
            ], $cfg['table']);

            $done = [
                'mdp'  => $newMdp,
                'user' => $user,
            ];
        } catch (PDOException $e) {
            $erreur = "Erreur : " . $e->getMessage();
        }
    }
}

$pageTitle = 'Réinitialiser un mot de passe';
$pageActive = 'utilisateurs';
$breadcrumb = ['Utilisateurs', 'Réinitialiser mot de passe'];
require_once 'includes/header_dashboard.php';
?>

<section class="reset-wrap reveal-up">

  <?php if ($done): ?>
    <!-- SUCCÈS : nouveau mot de passe -->
    <div class="reset-card reset-success">
      <div class="reset-ribbon success">
        <i class="fas fa-check-circle"></i> Mot de passe réinitialisé avec succès
      </div>
      <div class="reset-body">
        <div class="reset-avatar <?= $cfg['avatar'] ?>" style="background: <?= $cfg['gradient'] ?>">
          <?= strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)) ?>
        </div>
        <h2><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></h2>
        <div class="reset-role-pill"><?= ucfirst($cfg['label']) ?></div>

        <div class="mdp-reveal">
          <span class="mdp-reveal-label">Nouveau mot de passe</span>
          <div class="mdp-reveal-value">
            <code id="new-mdp"><?= htmlspecialchars($done['mdp']) ?></code>
            <button type="button" class="btn-ic-sm" onclick="copyMdp()" title="Copier">
              <i class="fas fa-copy" id="copy-icon"></i>
            </button>
          </div>
        </div>

        <div class="reset-warn">
          <i class="fas fa-shield-halved"></i>
          <div>
            <strong>Cette fenêtre est la seule occasion de voir ce mot de passe.</strong>
            Notez-le et communiquez-le à l'utilisateur par un canal sécurisé (SMS direct, en main propre).
            L'action a été inscrite dans le registre d'audit.
          </div>
        </div>

        <div class="reset-actions">
          <a href="liste_utilisateurs.php" class="btn btn-primary">
            <i class="fas fa-check"></i> Terminé
          </a>
          <button onclick="window.print()" class="btn btn-outline">
            <i class="fas fa-print"></i> Imprimer
          </button>
        </div>
      </div>
    </div>

  <?php else: ?>
    <!-- FORMULAIRE -->
    <div class="reset-card">
      <div class="reset-ribbon warn">
        <i class="fas fa-key"></i> Réinitialiser le mot de passe
      </div>
      <div class="reset-body">
        <div class="reset-avatar <?= $cfg['avatar'] ?>" style="background: <?= $cfg['gradient'] ?>">
          <?= strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)) ?>
        </div>
        <h2><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></h2>
        <div class="reset-role-pill"><?= ucfirst($cfg['label']) ?></div>

        <?php if (!empty($user['email'])): ?>
          <div class="reset-meta"><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></div>
        <?php endif; ?>
        <?php if (!empty($user['identifiant_blockchain'])): ?>
          <div class="reset-meta"><i class="fas fa-id-badge"></i> <?= htmlspecialchars($user['identifiant_blockchain']) ?></div>
        <?php endif; ?>

        <?php if ($erreur): ?>
          <div class="alert-box error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erreur) ?></div>
        <?php endif; ?>

        <div class="reset-info">
          <i class="fas fa-circle-info"></i>
          <div>
            Vous ne pouvez pas <strong>voir</strong> l'ancien mot de passe car il est stocké de manière irréversible.
            Vous pouvez en <strong>définir un nouveau</strong> ci-dessous.
          </div>
        </div>

        <!-- Tabs : Auto / Manuel -->
        <div class="reset-tabs">
          <button type="button" class="reset-tab active" data-tab="auto">
            <i class="fas fa-wand-magic-sparkles"></i> Générer automatiquement
          </button>
          <button type="button" class="reset-tab" data-tab="manuel">
            <i class="fas fa-keyboard"></i> Définir manuellement
          </button>
        </div>

        <!-- Panneau AUTO -->
        <form method="POST" class="reset-panel active" id="panel-auto">
          <?= csrf_field() ?>
          <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="action" value="generer">
          <p class="reset-panel-intro">
            Un mot de passe aléatoire de 10 caractères sera généré.
            Il sera affiché une fois à l'écran pour que vous puissiez le transmettre.
          </p>
          <div class="reset-actions">
            <a href="liste_utilisateurs.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Annuler</a>
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-wand-magic-sparkles"></i> Générer et appliquer
            </button>
          </div>
        </form>

        <!-- Panneau MANUEL -->
        <form method="POST" class="reset-panel" id="panel-manuel">
          <?= csrf_field() ?>
          <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="action" value="manuel">

          <div class="form-group">
            <label>Nouveau mot de passe</label>
            <div class="input-shell">
              <i class="fas fa-key"></i>
              <input type="password" name="nouveau_mdp" id="mdp-manuel" required minlength="6" placeholder="Minimum 6 caractères">
              <button type="button" class="input-eye" onclick="togglePw('mdp-manuel', this)"><i class="fas fa-eye"></i></button>
            </div>
          </div>

          <div class="form-group">
            <label>Confirmation</label>
            <div class="input-shell">
              <i class="fas fa-check"></i>
              <input type="password" name="confirmation" id="conf-manuel" required minlength="6" placeholder="Répéter">
              <button type="button" class="input-eye" onclick="togglePw('conf-manuel', this)"><i class="fas fa-eye"></i></button>
            </div>
          </div>

          <div class="reset-actions">
            <a href="liste_utilisateurs.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Annuler</a>
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-check"></i> Définir ce mot de passe
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</section>

<style>
.reset-wrap { max-width: 520px; margin: 0 auto; }
.reset-card { background: white; border: 1px solid var(--line); border-radius: 20px; overflow: hidden; box-shadow: 0 14px 34px -18px rgba(15,23,42,.12); }
.reset-ribbon { padding: 14px 20px; font-size: 14px; font-weight: 700; color: white; display: flex; align-items: center; gap: 10px; }
.reset-ribbon.warn { background: linear-gradient(135deg,#f59e0b,#d97706); }
.reset-ribbon.success { background: var(--g-emerald); }

.reset-body { padding: 30px; text-align: center; }
.reset-avatar { width: 84px; height: 84px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 14px; color: white; font-weight: 800; font-size: 28px; font-family: 'Plus Jakarta Sans', sans-serif; box-shadow: 0 14px 30px -10px rgba(0,0,0,.3); }

.reset-body h2 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 20px; font-weight: 800; color: var(--ink); margin-bottom: 4px; }
.reset-role-pill { display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 10px; }
.reset-meta { font-size: 12px; color: var(--muted); margin: 4px 0; display: flex; align-items: center; justify-content: center; gap: 6px; }

.reset-info { margin: 20px 0; padding: 12px 16px; background: rgba(14,165,233,.06); border: 1px solid rgba(14,165,233,.2); border-radius: 12px; font-size: 13px; color: #0369a1; display: flex; align-items: flex-start; gap: 10px; text-align: left; line-height: 1.5; }
.reset-info i { flex-shrink: 0; margin-top: 2px; }

.reset-tabs { display: flex; gap: 6px; margin: 20px 0 16px; background: #f8fafc; padding: 4px; border-radius: 12px; border: 1px solid var(--line); }
.reset-tab { flex: 1; padding: 10px 12px; border: none; background: transparent; border-radius: 9px; font-size: 12px; font-weight: 700; color: var(--muted); cursor: pointer; transition: .2s; display: flex; align-items: center; justify-content: center; gap: 6px; font-family: inherit; }
.reset-tab.active { background: white; color: var(--forest); box-shadow: 0 2px 8px rgba(15,23,42,.08); }

.reset-panel { display: none; text-align: left; }
.reset-panel.active { display: block; }
.reset-panel-intro { font-size: 13px; color: var(--muted); line-height: 1.5; margin-bottom: 16px; text-align: center; }

.form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.form-group label { font-size: 13px; font-weight: 700; color: var(--ink); }
.input-shell { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1.5px solid var(--line); border-radius: 12px; padding: 0 14px; transition: .25s; }
.input-shell:focus-within { border-color: var(--forest); background: white; box-shadow: 0 0 0 3px rgba(26,71,42,.1); }
.input-shell i { color: var(--muted); width: 14px; font-size: 14px; }
.input-shell input { flex: 1; border: none; background: transparent; outline: none; padding: 12px 0; font-size: 14px; color: var(--ink); font-family: inherit; }
.input-eye { background: none; border: none; color: var(--muted); cursor: pointer; padding: 4px; font-size: 14px; }
.input-eye:hover { color: var(--forest); }

.reset-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 16px; }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: .3s; font-family: inherit; }
.btn-primary { background: var(--g-forest); color: white; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -8px rgba(26,71,42,.4); }
.btn-outline { background: white; border: 1px solid var(--line); color: var(--ink); }
.btn-outline:hover { border-color: var(--forest); color: var(--forest); }
.btn-ic-sm { padding: 8px 10px; background: white; border: 1px solid var(--line); border-radius: 8px; cursor: pointer; color: var(--forest); transition: .2s; }
.btn-ic-sm:hover { background: rgba(16,185,129,.1); }

.mdp-reveal { margin: 22px 0 18px; padding: 20px; background: linear-gradient(135deg, rgba(16,185,129,.08), rgba(14,165,233,.04)); border: 1px dashed rgba(16,185,129,.3); border-radius: 14px; }
.mdp-reveal-label { display: block; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 10px; }
.mdp-reveal-value { display: flex; align-items: center; justify-content: center; gap: 10px; }
.mdp-reveal-value code { font-family: 'JetBrains Mono', monospace; font-size: 22px; font-weight: 800; color: var(--forest); letter-spacing: .08em; background: white; padding: 10px 16px; border-radius: 10px; border: 1px solid rgba(16,185,129,.2); }

.reset-warn { margin: 16px 0; padding: 14px; background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.25); border-radius: 12px; display: flex; gap: 12px; text-align: left; font-size: 12px; color: #92400e; line-height: 1.5; }
.reset-warn i { font-size: 16px; flex-shrink: 0; margin-top: 2px; }
.reset-warn strong { display: block; margin-bottom: 4px; }

.alert-box { display: flex; align-items: center; gap: 10px; padding: 12px 14px; margin-bottom: 14px; border-radius: 12px; font-size: 13px; text-align: left; }
.alert-box.error { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25); color: #b91c1c; }

@media print {
  .reset-tabs, .reset-actions, .btn, .sidebar, .topbar { display: none !important; }
  .reset-card { box-shadow: none; border: 2px solid #ddd; }
}
</style>

<script>
// Switch tabs
document.querySelectorAll('.reset-tab').forEach(tab => {
  tab.addEventListener('click', function () {
    const target = this.dataset.tab;
    document.querySelectorAll('.reset-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.reset-panel').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    document.getElementById('panel-' + target).classList.add('active');
  });
});

// Toggle password
function togglePw(id, btn) {
  const el = document.getElementById(id);
  if (el.type === 'password') { el.type = 'text'; btn.innerHTML = '<i class="fas fa-eye-slash"></i>'; }
  else { el.type = 'password'; btn.innerHTML = '<i class="fas fa-eye"></i>'; }
}

// Copy mdp
function copyMdp() {
  const el = document.getElementById('new-mdp');
  if (!el) return;
  navigator.clipboard.writeText(el.textContent).then(() => {
    const ic = document.getElementById('copy-icon');
    ic.classList.remove('fa-copy');
    ic.classList.add('fa-check');
    setTimeout(() => {
      ic.classList.remove('fa-check');
      ic.classList.add('fa-copy');
    }, 1500);
  });
}
</script>

<?php require_once 'includes/footer_dashboard.php'; ?>
