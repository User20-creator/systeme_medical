<?php
// modifier_utilisateur.php — Édition complète d'un utilisateur (admin uniquement)
require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_medecin_traitant_column($pdo);

if (($_SESSION['user_type'] ?? '') !== 'admin' || empty($_SESSION['admin_id'])) {
    header('Location: connexion2.php');
    exit;
}

$adminId = (int)$_SESSION['admin_id'];

$type = $_GET['type'] ?? $_POST['type'] ?? '';
$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!in_array($type, ['patient', 'docteur', 'infirmier'], true) || !$id) {
    header('Location: liste_utilisateurs.php');
    exit;
}

$tableMap = [
    'patient'   => 'patients',
    'docteur'   => 'docteurs',
    'infirmier' => 'infirmiers',
];
$table = $tableMap[$type];

// Récupération
$stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { header('Location: liste_utilisateurs.php'); exit; }

$hopitaux = $pdo->query("SELECT id, nom, ville FROM hopitaux WHERE statut='actif' ORDER BY nom")->fetchAll();
$docteurs = $pdo->query("SELECT id, prenom, nom, specialite FROM docteurs WHERE statut='actif' ORDER BY nom")->fetchAll();

$erreur = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $erreur = "Jeton de sécurité invalide.";
    } else {
        $nom       = trim($_POST['nom']       ?? '');
        $prenom    = trim($_POST['prenom']    ?? '');
        $email     = trim($_POST['email']     ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $statut    = $_POST['statut']         ?? 'actif';

        if (empty($nom) || empty($prenom)) {
            $erreur = "Le nom et le prénom sont obligatoires.";
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = "Email invalide.";
        }

        if (!$erreur) {
            try {
                if ($type === 'patient') {
                    $ddn        = $_POST['date_naissance'] ?? null;
                    $sexe       = $_POST['sexe']           ?? 'M';
                    $groupe     = trim($_POST['groupe_sanguin'] ?? '');
                    $npi        = trim($_POST['NPI']       ?? '');
                    $adresse    = trim($_POST['adresse']   ?? '');
                    $hopitalId  = (int)($_POST['hopital_reference']   ?? 0);
                    $medecinId  = (int)($_POST['medecin_traitant_id'] ?? 0);

                    $upd = $pdo->prepare("
                        UPDATE patients SET
                            nom = ?, prenom = ?, email = ?, telephone = ?,
                            date_naissance = ?, sexe = ?, groupe_sanguin = ?,
                            NPI = ?, adresse = ?, hopital_reference = ?,
                            medecin_traitant_id = ?, statut = ?
                        WHERE id = ?
                    ");
                    $upd->execute([
                        $nom, $prenom, $email ?: null, $telephone ?: null,
                        $ddn ?: null, $sexe, $groupe ?: null,
                        $npi ?: null, $adresse ?: null, $hopitalId ?: null,
                        $medecinId ?: null, $statut, $id,
                    ]);
                } elseif ($type === 'docteur') {
                    $specialite = trim($_POST['specialite'] ?? '');
                    $licence    = trim($_POST['numero_licence'] ?? '');
                    $hopitalId  = (int)($_POST['hopital_id'] ?? 0);

                    $upd = $pdo->prepare("
                        UPDATE docteurs SET
                            nom = ?, prenom = ?, email = ?, telephone = ?,
                            specialite = ?, numero_licence = ?, hopital_id = ?, statut = ?
                        WHERE id = ?
                    ");
                    $upd->execute([
                        $nom, $prenom, $email ?: null, $telephone ?: null,
                        $specialite ?: null, $licence ?: null, $hopitalId ?: null, $statut, $id,
                    ]);
                } elseif ($type === 'infirmier') {
                    $specialite = trim($_POST['specialite'] ?? '');
                    $fonction   = $_POST['fonction']     ?? 'accueil';
                    $licence    = trim($_POST['numero_licence'] ?? '');
                    $hopitalId  = (int)($_POST['hopital_principal_id'] ?? 0);

                    $upd = $pdo->prepare("
                        UPDATE infirmiers SET
                            nom = ?, prenom = ?, email = ?, telephone = ?,
                            specialite = ?, fonction = ?, numero_licence = ?,
                            hopital_principal_id = ?, statut = ?
                        WHERE id = ?
                    ");
                    $upd->execute([
                        $nom, $prenom, $email ?: null, $telephone ?: null,
                        $specialite ?: null, $fonction, $licence ?: null,
                        $hopitalId ?: null, $statut, $id,
                    ]);
                }

                HashChain::addBlock('UPDATE_USER', $id, $adminId, 'admin', [
                    'cible_type' => $type,
                    'cible_nom'  => "$prenom $nom",
                    'modifie_par'=> 'admin',
                    'date'       => date('c'),
                ], $table);

                $success = "Modifications enregistrées et inscrites dans la chaîne.";
                // Recharger les données
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
                $stmt->execute([$id]);
                $user = $stmt->fetch();
            } catch (PDOException $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Modifier un utilisateur';
$pageActive = 'utilisateurs';
$breadcrumb = ['Utilisateurs', 'Modifier'];
require_once 'includes/header_dashboard.php';

$labelType = ucfirst($type);
$gradient = match($type) {
    'patient'   => 'var(--g-emerald)',
    'docteur'   => 'var(--g-forest)',
    'infirmier' => 'var(--g-trust)',
    default     => 'var(--g-blockchain)',
};
$initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));
?>

<!-- HERO COMPACT -->
<section class="dash-hero edit-hero reveal-up">
  <div class="edit-hero-content">
    <div class="edit-hero-greet">
      <span class="edit-hero-dot"></span>
      Modification administrateur
    </div>
    <h1>Modifier <span><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></span></h1>
    <p>Type : <strong><?= htmlspecialchars($labelType) ?></strong> · ID #<?= $id ?> · Toute modification est inscrite dans la chaîne.</p>
  </div>
  <div class="edit-hero-card" style="background:<?= $gradient ?>;">
    <div class="edit-avatar"><?= htmlspecialchars($initials) ?></div>
    <div class="edit-hero-meta">
      <span><i class="fas fa-cube"></i> <?= htmlspecialchars(HashChain::shortHash($user['identifiant_blockchain'] ?? '', 6, 4)) ?></span>
    </div>
  </div>
</section>

<?php if ($success): ?>
  <div class="alert alert-success reveal-up"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($erreur): ?>
  <div class="alert alert-error reveal-up"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($erreur) ?></div>
<?php endif; ?>

<section class="form-shell reveal-up">
  <form method="POST" class="form-card" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="form-head">
      <div class="form-head-icon" style="background:<?= $gradient ?>;"><i class="fas fa-pen-to-square"></i></div>
      <div>
        <h2>Informations modifiables</h2>
        <p>Mettez à jour les champs nécessaires puis enregistrez.</p>
      </div>
    </div>

    <div class="form-body">
      <div class="section-divider"><i class="fas fa-user"></i> Identité</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Nom <span class="req">*</span></label>
          <div class="input-shell"><i class="fas fa-user"></i>
            <input type="text" name="nom" required value="<?= htmlspecialchars($user['nom'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Prénom <span class="req">*</span></label>
          <div class="input-shell"><i class="fas fa-user"></i>
            <input type="text" name="prenom" required value="<?= htmlspecialchars($user['prenom'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Email</label>
          <div class="input-shell"><i class="fas fa-envelope"></i>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Téléphone</label>
          <div class="input-shell"><i class="fas fa-phone"></i>
            <input type="text" name="telephone" value="<?= htmlspecialchars($user['telephone'] ?? '') ?>">
          </div>
        </div>
      </div>

      <?php if ($type === 'patient'): ?>
      <div class="section-divider"><i class="fas fa-id-card"></i> Détails patient</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Date de naissance</label>
          <div class="input-shell"><i class="fas fa-calendar-day"></i>
            <input type="date" name="date_naissance" value="<?= htmlspecialchars($user['date_naissance'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Sexe</label>
          <div class="input-shell"><i class="fas fa-venus-mars"></i>
            <select name="sexe">
              <option value="M" <?= ($user['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>Masculin</option>
              <option value="F" <?= ($user['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>Féminin</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Groupe sanguin</label>
          <div class="input-shell"><i class="fas fa-tint"></i>
            <select name="groupe_sanguin">
              <option value="">—</option>
              <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $g): ?>
                <option value="<?= $g ?>" <?= ($user['groupe_sanguin'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>NPI</label>
          <div class="input-shell"><i class="fas fa-id-badge"></i>
            <input type="text" name="NPI" maxlength="20" value="<?= htmlspecialchars($user['NPI'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group full">
          <label>Adresse</label>
          <div class="input-shell"><i class="fas fa-map-marker-alt"></i>
            <input type="text" name="adresse" value="<?= htmlspecialchars($user['adresse'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Hôpital de référence</label>
          <div class="input-shell"><i class="fas fa-hospital"></i>
            <select name="hopital_reference">
              <option value="">—</option>
              <?php foreach ($hopitaux as $h): ?>
                <option value="<?= $h['id'] ?>" <?= (int)($user['hopital_reference'] ?? 0) === (int)$h['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($h['nom']) ?> — <?= htmlspecialchars($h['ville']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Médecin traitant</label>
          <div class="input-shell"><i class="fas fa-user-doctor"></i>
            <select name="medecin_traitant_id">
              <option value="">— Aucun —</option>
              <?php foreach ($docteurs as $d): ?>
                <option value="<?= $d['id'] ?>" <?= (int)($user['medecin_traitant_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                  Dr. <?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?>
                  <?= !empty($d['specialite']) ? ' · ' . htmlspecialchars($d['specialite']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <?php elseif ($type === 'docteur'): ?>
      <div class="section-divider"><i class="fas fa-user-doctor"></i> Détails docteur</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Spécialité</label>
          <div class="input-shell"><i class="fas fa-stethoscope"></i>
            <input type="text" name="specialite" value="<?= htmlspecialchars($user['specialite'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Numéro de licence</label>
          <div class="input-shell"><i class="fas fa-id-badge"></i>
            <input type="text" name="numero_licence" value="<?= htmlspecialchars($user['numero_licence'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group full">
          <label>Hôpital d'affectation</label>
          <div class="input-shell"><i class="fas fa-hospital"></i>
            <select name="hopital_id">
              <option value="">—</option>
              <?php foreach ($hopitaux as $h): ?>
                <option value="<?= $h['id'] ?>" <?= (int)($user['hopital_id'] ?? 0) === (int)$h['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($h['nom']) ?> — <?= htmlspecialchars($h['ville']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <?php elseif ($type === 'infirmier'): ?>
      <div class="section-divider"><i class="fas fa-user-nurse"></i> Détails infirmier</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Spécialité</label>
          <div class="input-shell"><i class="fas fa-stethoscope"></i>
            <input type="text" name="specialite" value="<?= htmlspecialchars($user['specialite'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Fonction</label>
          <div class="input-shell"><i class="fas fa-user-nurse"></i>
            <select name="fonction">
              <?php foreach (['accueil','soins','urgence','chef','pediatrie','bloc'] as $f): ?>
                <option value="<?= $f ?>" <?= ($user['fonction'] ?? '') === $f ? 'selected' : '' ?>><?= ucfirst($f) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Numéro de licence</label>
          <div class="input-shell"><i class="fas fa-id-badge"></i>
            <input type="text" name="numero_licence" value="<?= htmlspecialchars($user['numero_licence'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Hôpital principal</label>
          <div class="input-shell"><i class="fas fa-hospital"></i>
            <select name="hopital_principal_id">
              <option value="">—</option>
              <?php foreach ($hopitaux as $h): ?>
                <option value="<?= $h['id'] ?>" <?= (int)($user['hopital_principal_id'] ?? 0) === (int)$h['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($h['nom']) ?> — <?= htmlspecialchars($h['ville']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="section-divider"><i class="fas fa-toggle-on"></i> Statut du compte</div>
      <div class="form-grid">
        <div class="form-group full">
          <label>Statut</label>
          <div class="input-shell"><i class="fas fa-circle-check"></i>
            <select name="statut">
              <option value="actif" <?= ($user['statut'] ?? 'actif') === 'actif' ? 'selected' : '' ?>>Actif</option>
              <option value="inactif" <?= ($user['statut'] ?? '') === 'inactif' ? 'selected' : '' ?>>Inactif</option>
              <?php if ($type !== 'patient'): ?>
                <option value="suspendu" <?= ($user['statut'] ?? '') === 'suspendu' ? 'selected' : '' ?>>Suspendu</option>
              <?php endif; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <a href="liste_utilisateurs.php?role=<?= htmlspecialchars($type) ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour à la liste
      </a>
      <a href="reinitialiser_mdp.php?type=<?= htmlspecialchars($type) ?>&id=<?= $id ?>" class="btn btn-warn">
        <i class="fas fa-key"></i> Mot de passe
      </a>
      <button type="submit" class="btn btn-primary">
        <i class="fas fa-cube"></i> Enregistrer dans la chaîne
      </button>
    </div>
  </form>
</section>

<style>
.edit-hero {
  background: linear-gradient(135deg, #1e1b4b 0%, #4338ca 50%, #0369a1 100%);
  color: white; border-radius: 24px; padding: 28px 36px;
  display: grid; grid-template-columns: 1.4fr 1fr; gap: 28px;
  align-items: center; margin-bottom: 24px;
  position: relative; overflow: hidden;
}
.edit-hero::before {
  content: ''; position: absolute; top: -120px; right: -120px;
  width: 360px; height: 360px;
  background: radial-gradient(circle, rgba(167,139,250,.35), transparent 60%);
  filter: blur(80px);
}
.edit-hero-content { position: relative; z-index: 1; }
.edit-hero-greet {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 5px 12px; background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.15); border-radius: 999px;
  font-size: 12px; font-weight: 600; margin-bottom: 12px;
}
.edit-hero-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #a78bfa; box-shadow: 0 0 12px #a78bfa;
}
.edit-hero h1 {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 28px; font-weight: 800;
}
.edit-hero h1 span {
  background: linear-gradient(135deg, #c4b5fd, #7dd3fc);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.edit-hero p { font-size: 13px; color: rgba(255,255,255,.8); margin-top: 8px; }

.edit-hero-card {
  position: relative; z-index: 1;
  padding: 22px; border-radius: 16px; text-align: center; color: white;
  box-shadow: 0 14px 30px -10px rgba(0,0,0,.3);
}
.edit-avatar {
  width: 72px; height: 72px; margin: 0 auto 8px;
  border-radius: 50%; background: rgba(255,255,255,.18);
  display: grid; place-items: center;
  font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 24px;
}
.edit-hero-meta { font-size: 11px; opacity: .9; font-family: 'JetBrains Mono', monospace; }

.alert {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 18px; border-radius: 12px; margin-bottom: 18px;
  font-size: 14px;
}
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid rgba(16,185,129,.3); }
.alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid rgba(239,68,68,.3); }

.form-shell { max-width: 920px; margin: 0 auto; }
.form-card { background: white; border: 1px solid var(--line); border-radius: 20px; overflow: hidden; box-shadow: 0 14px 34px -18px rgba(15,23,42,.12); }
.form-head { display: flex; align-items: center; gap: 16px; padding: 24px 28px; border-bottom: 1px solid var(--line); background: #f8fafc; }
.form-head-icon { width: 52px; height: 52px; border-radius: 14px; color: white; display: grid; place-items: center; font-size: 20px; }
.form-head h2 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 18px; font-weight: 800; margin: 0; }
.form-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }
.form-head .req { color: #ef4444; }

.form-body { padding: 28px; }
.section-divider {
  font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em;
  color: var(--blockchain); padding: 18px 0 12px; margin-top: 8px;
  border-bottom: 2px solid rgba(99,102,241,.15); margin-bottom: 18px;
  display: flex; align-items: center; gap: 8px;
}
.section-divider:first-child { margin-top: 0; padding-top: 0; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full { grid-column: 1/-1; }
.form-group label { font-size: 13px; font-weight: 700; color: var(--ink); }
.input-shell { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1.5px solid var(--line); border-radius: 12px; padding: 0 14px; transition: .25s; }
.input-shell:focus-within { border-color: var(--blockchain); background: white; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
.input-shell i { color: var(--muted); width: 14px; font-size: 14px; }
.input-shell input, .input-shell select { flex: 1; border: none; background: transparent; outline: none; padding: 12px 0; font-size: 14px; color: var(--ink); font-family: inherit; }

.form-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 20px 28px; border-top: 1px solid var(--line); background: #f8fafc; }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: .3s; font-family: inherit; }
.btn-primary { background: var(--g-blockchain); color: white; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -8px rgba(99,102,241,.4); }
.btn-warn { background: linear-gradient(135deg,#f59e0b,#d97706); color: white; }
.btn-warn:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -8px rgba(245,158,11,.4); }
.btn-outline { background: white; border: 1px solid var(--line); color: var(--ink); }
.btn-outline:hover { border-color: var(--blockchain); color: var(--blockchain); }

@media (max-width: 720px) {
  .form-grid { grid-template-columns: 1fr; }
  .edit-hero { grid-template-columns: 1fr; }
}
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
