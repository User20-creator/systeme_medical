<?php
// modifier_profil.php — Modification du profil (patient/infirmier/docteur/admin)
require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_extended_columns($pdo);

$userType = $_SESSION['user_type'] ?? '';
$table = ''; $userId = 0;

// Schéma FIGÉ par rôle (champs autorisés à la modification + colonne mdp).
// On évite SHOW COLUMNS dynamique qui masque les bugs en cas de drift schéma.
$ALLOWED = [
    'patient'   => ['email', 'telephone', 'adresse', 'groupe_sanguin'],
    'infirmier' => ['email', 'telephone'],
    'docteur'   => ['email', 'telephone'],
    'admin'     => ['email'],
];
$PWD_COL = 'mot_de_passe';

// Normalise un numéro saisi côté formulaire : extrait les chiffres (max 10),
// puis reforme "+229 XX XX XX XX XX" pour stockage cohérent.
function normalize_phone_be(string $raw): ?string
{
    $digits = preg_replace('/[^0-9]/', '', $raw);
    if ($digits === '') return null;
    // On garde les 10 derniers chiffres si plus long (ex: collé avec préfixe)
    if (strlen($digits) > 10) $digits = substr($digits, -10);
    if (strlen($digits) !== 10) return null;
    $groups = [];
    for ($i = 0; $i < 10; $i += 2) $groups[] = substr($digits, $i, 2);
    return '+229 ' . implode(' ', $groups);
}

switch ($userType) {
    case 'patient':   $userId = (int)($_SESSION['user_id']    ?? 0); $table = 'patients';   break;
    case 'infirmier': $userId = (int)($_SESSION['medecin_id'] ?? 0); $table = 'infirmiers'; break;
    case 'docteur':
    case 'medecin':   $userId = (int)($_SESSION['medecin_id'] ?? 0); $table = 'docteurs';   $userType = 'docteur'; break;
    case 'admin':     $userId = (int)($_SESSION['admin_id']   ?? 0); $table = 'admin';      break;
    default: header('Location: connexion1.php'); exit;
}

if (!$userId) { header('Location: connexion1.php'); exit; }

$success = ''; $error = '';

// Whitelist par rôle pour le SELECT et l'UPDATE
$allowedFields = $ALLOWED[$userType] ?? [];

try {
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('modifier_profil select: ' . $e->getMessage());
    $profile = null;
}

if (!$profile) { header('Location: connexion1.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    if (!csrf_check()) {
        $error = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email invalide.';
        }

        // Validation téléphone : 10 chiffres requis si saisi
        if (!$error && !empty($_POST['telephone'])) {
            $normalized = normalize_phone_be($_POST['telephone']);
            if ($normalized === null) {
                $error = 'Le numéro de téléphone doit contenir exactement 10 chiffres (ex: +229 01 56 57 58 48).';
            } else {
                $_POST['telephone'] = $normalized; // On utilise la version normalisée pour la suite
            }
        }

        // ─── Upload photo de profil (patient uniquement) ───
        if (!$error && $userType === 'patient' && !empty($_FILES['photo']['name'])) {
            $f = $_FILES['photo'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $error = "Erreur d'envoi de la photo.";
            } elseif ($f['size'] > 4 * 1024 * 1024) {
                $error = 'La photo ne doit pas dépasser 4 Mo.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $f['tmp_name']);
                finfo_close($finfo);
                $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extMap[$mime])) {
                    $error = 'Formats acceptés : JPG, PNG, WEBP uniquement.';
                } else {
                    $dir = __DIR__ . '/images/profils';
                    if (!is_dir($dir)) @mkdir($dir, 0775, true);
                    $filename = 'patient_' . $userId . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $extMap[$mime];
                    $relPath = 'images/profils/' . $filename;
                    if (move_uploaded_file($f['tmp_name'], $dir . '/' . $filename)) {
                        // Supprimer l'ancienne photo si elle existe
                        if (!empty($profile['photo']) && file_exists(__DIR__ . '/' . $profile['photo'])) {
                            @unlink(__DIR__ . '/' . $profile['photo']);
                        }
                        $_POST['photo'] = $relPath;
                        if (!in_array('photo', $allowedFields, true)) {
                            $allowedFields[] = 'photo';
                        }
                    } else {
                        $error = "Impossible d'enregistrer la photo sur le serveur.";
                    }
                }
            }
        }

        if (!$error) {
            try {
                $updates = [];
                $params  = [];
                $changes = [];
                foreach ($allowedFields as $col) {
                    $newVal = trim($_POST[$col] ?? '');
                    $oldVal = $profile[$col] ?? '';
                    if ($newVal !== $oldVal) {
                        $updates[] = "`$col` = ?";
                        $params[]  = $newVal;
                        $changes[$col] = $newVal;
                    }
                }

                // Mot de passe optionnel — minimum 8 caractères
                $newPwd = $_POST['password'] ?? '';
                if ($newPwd !== '') {
                    if (strlen($newPwd) < 8) {
                        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
                    } else {
                        $hashed = password_hash($newPwd, PASSWORD_DEFAULT);
                        $updates[] = "`$PWD_COL` = ?";
                        $params[]  = $hashed;
                        $changes['password_updated'] = true;
                    }
                }

                if (!$error && !empty($updates)) {
                    $params[] = $userId;
                    $sql = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE id = ?";
                    $pdo->prepare($sql)->execute($params);

                    HashChain::addBlock('UPDATE_PROFIL', $userId, $userId, $userType, $changes, $table);

                    $success = 'Profil mis à jour avec succès.';

                    // Recharger
                    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
                    $stmt->execute([$userId]);
                    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                } elseif (!$error) {
                    $success = 'Aucune modification détectée.';
                }
            } catch (PDOException $e) {
                error_log('modifier_profil update: ' . $e->getMessage());
                $error = 'Erreur lors de la mise à jour.';
            }
        }
    }
}

$pageTitle = 'Modifier mon profil';
$pageActive = 'profil';
$breadcrumb = ['Profil', 'Modifier'];
require_once 'includes/header_dashboard.php';
?>

<section class="dash-hero dash-hero-compact reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span>
      <?php if ($userType === 'patient'): ?>Édition sécurisée — Espace patient<?php else: ?>Édition sécurisée<?php endif; ?>
    </div>
    <h1>
      <?php if ($userType === 'patient'): ?>
        Modifier mon <span>profil patient.</span>
      <?php else: ?>
        Modifier <span>vos informations.</span>
      <?php endif; ?>
    </h1>
    <p>Toute modification est <strong>signée cryptographiquement</strong> et inscrite dans la blockchain.
       Vos données restent votre propriété.</p>
  </div>
  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Sécurité</span>
      <i class="fas fa-shield-halved"></i>
    </div>
    <ul class="security-notes">
      <li><i class="fas fa-check"></i> Chaque modification crée un nouveau bloc</li>
      <li><i class="fas fa-check"></i> Historique immuable et vérifiable</li>
      <li><i class="fas fa-check"></i> Chiffrement AES-256</li>
    </ul>
  </div>
</section>

<?php if ($success): ?>
  <div class="alert alert-success reveal-up">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert alert-error reveal-up">
    <i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
  </div>
<?php endif; ?>

<form method="post" class="dash-card reveal-up" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update">

  <?php if ($userType === 'patient'):
    $currentPhoto = !empty($profile['photo']) && file_exists(__DIR__ . '/' . $profile['photo'])
        ? $profile['photo'] : '';
  ?>
  <div class="dash-card-head" id="photo">
    <div>
      <h3><i class="fas fa-camera" style="color:var(--forest)"></i> Photo de profil patient</h3>
      <p>Formats acceptés : JPG, PNG, WEBP — 4 Mo maximum</p>
    </div>
  </div>

  <div class="photo-upload-row">
    <div class="photo-upload-preview" id="photoPreview">
      <?php if ($currentPhoto): ?>
        <img id="photoPreviewImg" src="<?= htmlspecialchars($currentPhoto) ?>?v=<?= time() ?>" alt="Photo">
      <?php else: ?>
        <div class="photo-upload-fallback" id="photoFallback">
          <?= strtoupper(substr($profile['prenom'] ?? '', 0, 1) . substr($profile['nom'] ?? '', 0, 1)) ?>
        </div>
        <img id="photoPreviewImg" alt="Photo" style="display:none">
      <?php endif; ?>
    </div>
    <div class="photo-upload-controls">
      <input type="file" name="photo" id="photoInput" accept="image/jpeg,image/png,image/webp" style="display:none">
      <button type="button" class="btn btn-primary" onclick="document.getElementById('photoInput').click()">
        <i class="fas fa-upload"></i> <?= $currentPhoto ? 'Changer ma photo' : 'Choisir une photo' ?>
      </button>
      <p class="photo-upload-hint"><i class="fas fa-info-circle"></i> La photo sera visible dans votre espace et auprès de vos soignants.</p>
    </div>
  </div>
  <?php endif; ?>

  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-user-pen" style="color:var(--forest)"></i> Informations personnelles</h3>
      <p>Mise à jour de votre profil</p>
    </div>
  </div>

  <div class="form-grid">
    <div class="field">
      <label>Prénom</label>
      <input type="text" value="<?= htmlspecialchars($profile['prenom'] ?? '') ?>" disabled>
      <small><i class="fas fa-lock"></i> Non modifiable — verrouillé par la blockchain</small>
    </div>
    <div class="field">
      <label>Nom</label>
      <input type="text" value="<?= htmlspecialchars($profile['nom'] ?? '') ?>" disabled>
      <small><i class="fas fa-lock"></i> Non modifiable — verrouillé par la blockchain</small>
    </div>
    <div class="field">
      <label>Email</label>
      <input type="email" name="email" value="<?= htmlspecialchars($profile['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Téléphone <small style="color:var(--muted);font-weight:500">(10 chiffres)</small></label>
      <input type="tel" name="telephone" id="phoneEdit" maxlength="14" inputmode="numeric"
             placeholder="+229 01 56 57 58 48"
             value="<?= htmlspecialchars($profile['telephone'] ?? '') ?>">
      <small id="phoneEditHint"><i class="fas fa-info-circle"></i> Format : +229 XX XX XX XX XX</small>
    </div>
    <div class="field field-full">
      <label>Adresse</label>
      <input type="text" name="adresse" value="<?= htmlspecialchars($profile['adresse'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Ville</label>
      <input type="text" name="ville" value="<?= htmlspecialchars($profile['ville'] ?? '') ?>">
    </div>
    <?php if ($userType === 'patient'): ?>
    <div class="field">
      <label>Groupe sanguin</label>
      <select name="groupe_sanguin">
        <option value="">— Non renseigné —</option>
        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $g): ?>
          <option value="<?= $g ?>" <?= ($profile['groupe_sanguin'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
  </div>
</form>

<form method="post" class="dash-card reveal-up">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update">

  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-key" style="color:var(--blockchain)"></i> Changer le mot de passe</h3>
      <p>Laissez vide pour conserver l'ancien</p>
    </div>
  </div>

  <div class="form-grid">
    <div class="field field-full">
      <label>Nouveau mot de passe</label>
      <input type="password" name="password" minlength="8" placeholder="8 caractères minimum">
      <small><i class="fas fa-info-circle"></i> Utilisez au moins 8 caractères avec majuscules, chiffres et symboles.</small>
    </div>
  </div>

  <div class="form-actions">
    <a href="mon_profil.php" class="btn btn-outline">
      <i class="fas fa-arrow-left"></i> Annuler
    </a>
    <button type="submit" class="btn btn-primary">
      <i class="fas fa-save"></i> Enregistrer les modifications
    </button>
  </div>
</form>

<style>
  /* Réutilise hero compact du pattern admin */
  .dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #1a472a 0%, #0e2a1a 50%, #0369a1 100%);
    color: white; border-radius: 24px;
    padding: 28px 36px; margin-bottom: 24px;
    display: grid; grid-template-columns: 1.4fr 1fr; gap: 28px; align-items: center;
  }
  .dash-hero::before {
    content: ''; position: absolute; top: -150px; right: -150px;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(56,189,248,.3), transparent 60%);
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
    background: #7dd3fc; box-shadow: 0 0 12px #7dd3fc;
    animation: pulse 2s infinite;
  }
  .dash-hero h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 28px; font-weight: 800; line-height: 1.15;
  }
  .dash-hero h1 span {
    background: linear-gradient(135deg, #86efac, #7dd3fc);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .dash-hero p { font-size: 13px; color: rgba(255,255,255,.8); margin-top: 8px; line-height: 1.6; }
  .dash-hero p strong { color: #86efac; }

  .dash-hero-card {
    position: relative; z-index: 1;
    padding: 18px 20px; background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(16px);
    border-radius: 14px;
  }
  .dash-hero-card-head {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;
  }
  .dash-hero-card-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    color: rgba(255,255,255,.6);
  }
  .dash-hero-card-head i { color: #7dd3fc; font-size: 16px; }
  .security-notes { list-style: none; display: flex; flex-direction: column; gap: 6px; }
  .security-notes li {
    font-size: 12px; color: rgba(255,255,255,.85);
    display: flex; align-items: center; gap: 8px;
  }
  .security-notes li i { color: #86efac; font-size: 10px; }

  .alert {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 18px; border-radius: 12px;
    margin-bottom: 20px; font-size: 14px; font-weight: 500;
  }
  .alert-success { background: #d1fae5; color: #065f46; border: 1px solid rgba(16,185,129,.3); }
  .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid rgba(239,68,68,.3); }
  .alert i { font-size: 18px; }

  .dash-card {
    background: white; border: 1px solid var(--line);
    border-radius: 20px; padding: 28px; margin-bottom: 20px;
  }
  .dash-card-head { margin-bottom: 20px; }
  .dash-card-head h3 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 17px; font-weight: 700; color: var(--ink);
    display: flex; align-items: center; gap: 10px;
  }
  .dash-card-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }

  .form-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 16px;
  }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field-full { grid-column: 1 / -1; }
  .field label {
    font-size: 12px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .04em;
  }
  .field input, .field select {
    padding: 11px 14px; border: 1px solid var(--line);
    border-radius: 10px; background: white;
    font-size: 14px; color: var(--ink); font-family: inherit;
    transition: .2s;
  }
  .field input:focus, .field select:focus {
    outline: none; border-color: var(--forest);
    box-shadow: 0 0 0 3px rgba(26,71,42,.1);
  }
  .field input:disabled {
    background: #f1f5f9; color: var(--muted); cursor: not-allowed;
  }
  .field small {
    font-size: 11px; color: var(--muted);
    display: inline-flex; align-items: center; gap: 4px;
  }
  .field small i { color: var(--blockchain); }

  .form-actions {
    display: flex; justify-content: flex-end; gap: 12px;
    margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--line);
  }
  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 11px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 700;
    text-decoration: none; border: none; cursor: pointer; transition: .3s;
    font-family: inherit;
  }
  .btn-primary { background: var(--g-forest); color: white; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 20px -4px rgba(26,71,42,.4); }
  .btn-outline { background: white; border: 1px solid var(--line); color: var(--ink); }
  .btn-outline:hover { border-color: var(--forest); color: var(--forest); }

  @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }

  /* ── Bloc upload photo ── */
  .photo-upload-row {
    display: flex; gap: 20px; align-items: center;
    padding: 20px; margin-bottom: 24px;
    background: linear-gradient(135deg, rgba(16,185,129,.06), rgba(26,71,42,.04));
    border: 1px solid rgba(16,185,129,.2);
    border-radius: 14px;
  }
  .photo-upload-preview {
    width: 110px; height: 110px;
    border-radius: 24px; overflow: hidden;
    flex-shrink: 0;
    border: 3px solid white;
    box-shadow: 0 8px 20px -6px rgba(15,23,42,.2);
    position: relative;
  }
  .photo-upload-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .photo-upload-fallback {
    width: 100%; height: 100%;
    display: grid; place-items: center;
    color: white; font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 800; font-size: 36px;
    background: linear-gradient(135deg, #10b981, #059669);
  }
  .photo-upload-controls { flex: 1; min-width: 0; }
  .photo-upload-hint {
    font-size: 12px; color: var(--muted);
    margin-top: 10px;
  }
  .photo-upload-hint i { color: var(--blockchain); margin-right: 4px; }

  @media (max-width: 800px) {
    .dash-hero { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    .photo-upload-row { flex-direction: column; text-align: center; }
  }
</style>

<script>
  // Preview photo avant upload
  (function() {
    const input = document.getElementById('photoInput');
    if (!input) return;
    input.addEventListener('change', function() {
      const f = this.files[0];
      if (!f) return;
      const img = document.getElementById('photoPreviewImg');
      const fb = document.getElementById('photoFallback');
      const reader = new FileReader();
      reader.onload = e => {
        img.src = e.target.result;
        img.style.display = 'block';
        if (fb) fb.style.display = 'none';
      };
      reader.readAsDataURL(f);
    });
  })();

  // Formatage live du téléphone : +229 XX XX XX XX XX (10 chiffres)
  (function() {
    const ph = document.getElementById('phoneEdit');
    if (!ph) return;
    function format() {
      let digits = ph.value.replace(/[^0-9]/g, '');
      // Strip 229 prefix si présent (l'utilisateur peut coller "+229...")
      if (digits.startsWith('229') && digits.length > 10) digits = digits.slice(3);
      digits = digits.slice(0, 10);
      if (!digits) { ph.value = ''; return; }
      const groups = [];
      for (let i = 0; i < digits.length; i += 2) groups.push(digits.slice(i, i + 2));
      ph.value = '+229 ' + groups.join(' ');
    }
    ph.addEventListener('input', format);
    if (ph.value) format();
  })();
</script>

<?php require_once 'includes/footer_dashboard.php'; ?>
