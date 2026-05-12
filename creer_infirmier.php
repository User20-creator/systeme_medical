<?php
// creer_infirmier.php — Création d'un compte infirmier (admin OU infirmier)
require_once 'config.php';
require_once 'includes/hash_chain.php';

$userType = $_SESSION['user_type'] ?? '';
$creatorId = 0;

if ($userType === 'admin' && !empty($_SESSION['admin_id'])) {
    $creatorId = (int)$_SESSION['admin_id'];
} elseif ($userType === 'infirmier' && !empty($_SESSION['medecin_id'])) {
    $creatorId = (int)$_SESSION['medecin_id'];
} else {
    header('Location: connexion2.php'); exit;
}

// Hôpital pré-sélectionné pour les infirmiers (leur hôpital principal)
$hopitalParDefaut = ($userType === 'infirmier')
    ? (int)($_SESSION['medecin_hopital_principal_id'] ?? 0)
    : 0;

$erreur  = '';
$created = null;

$hopitaux = $pdo->query("SELECT id, nom, ville FROM hopitaux WHERE statut='actif' ORDER BY nom")->fetchAll();

$fonctions = [
    'accueil'    => 'Agent d\'accueil',
    'soins'      => 'Soins généraux',
    'urgence'    => 'Urgences',
    'chef'       => 'Infirmier chef',
    'pediatrie'  => 'Pédiatrie',
    'bloc'       => 'Bloc opératoire',
];

// Liste des fonctions valides — DOIT correspondre à l'ENUM en BDD
$FONCTIONS_VALIDES = ['accueil', 'soins', 'urgence', 'chef', 'pediatrie', 'bloc'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $erreur = "Jeton de sécurité invalide. Veuillez recharger la page.";
    }
    $nom        = trim($_POST['nom']            ?? '');
    $prenom     = trim($_POST['prenom']         ?? '');
    $email      = trim($_POST['email']          ?? '');
    $telephone  = trim($_POST['telephone']      ?? '');
    $specialite = trim($_POST['specialite']     ?? '');
    $fonction   = $_POST['fonction']            ?? 'accueil';
    $licence    = trim($_POST['numero_licence'] ?? '');
    $hopitalId  = (int)($_POST['hopital_id']    ?? 0);
    $motDePasse = $_POST['mot_de_passe']        ?? '';
    $confirmation = $_POST['confirmation']      ?? '';

    if (!in_array($fonction, $FONCTIONS_VALIDES, true)) {
        $fonction = 'accueil';
    }

    if ($erreur) {
        // CSRF déjà en erreur
    } elseif (empty($nom) || empty($prenom) || empty($email) || empty($motDePasse) || empty($licence)) {
        $erreur = "Veuillez remplir tous les champs obligatoires.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = "Adresse email invalide.";
    } elseif (strlen($motDePasse) < 8) {
        $erreur = "Le mot de passe doit contenir au moins 8 caractères.";
    } elseif ($motDePasse !== $confirmation) {
        $erreur = "Les mots de passe ne correspondent pas.";
    } else {
        $check = $pdo->prepare("SELECT id FROM infirmiers WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $erreur = "Cet email est déjà utilisé par un autre infirmier.";
        } else {
            $check2 = $pdo->prepare("SELECT id FROM infirmiers WHERE numero_licence = ?");
            $check2->execute([$licence]);
            if ($check2->fetch()) {
                $erreur = "Ce numéro de licence est déjà utilisé.";
            } else {
                try {
                    $motChiffre = password_hash($motDePasse, PASSWORD_DEFAULT);
                    $blockchain = HashChain::generateIdentifier('INF');

                    $stmt = $pdo->prepare("
                        INSERT INTO infirmiers
                            (identifiant_blockchain, nom, prenom, specialite, fonction,
                             numero_licence, telephone, email, mot_de_passe,
                             hopital_principal_id, statut, role_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif',
                                (SELECT id FROM roles WHERE code = 'infirmier'))
                    ");
                    $stmt->execute([
                        $blockchain, $nom, $prenom, $specialite, $fonction,
                        $licence, $telephone ?: null, $email, $motChiffre,
                        $hopitalId ?: null
                    ]);

                    $newId = (int)$pdo->lastInsertId();

                    HashChain::addBlock('CREATE_INFIRMIER', $newId, $creatorId, $userType, [
                        'infirmier'   => "$prenom $nom",
                        'fonction'    => $fonction,
                        'licence'     => $licence,
                        'identifiant' => $blockchain,
                        'hopital_id'  => $hopitalId ?: null,
                    ], 'infirmiers');

                    $created = [
                        'prenom'     => $prenom,
                        'nom'        => $nom,
                        'email'      => $email,
                        'fonction'   => $fonctions[$fonction] ?? $fonction,
                        'licence'    => $licence,
                        'mdp'        => $motDePasse,
                        'blockchain' => $blockchain,
                    ];

                    $_POST = [];
                } catch (PDOException $e) {
                    error_log('creer_infirmier: ' . $e->getMessage());
                    $erreur = "Erreur lors de l'enregistrement de l'infirmier. Vérifiez les informations.";
                }
            }
        }
    }
}

$pageTitle = 'Nouvel infirmier';
$pageActive = 'creer-infirmier';
$breadcrumb = ['Création de comptes', 'Nouvel infirmier'];
require_once 'includes/header_dashboard.php';
?>

<!-- HERO PREMIUM (style creer_patient) -->
<section class="creer-hero reveal-up">
  <div class="ch-decor">
    <div class="ch-orb ch-orb-1"></div>
    <div class="ch-orb ch-orb-2"></div>
    <svg class="ch-grid" viewBox="0 0 200 200" preserveAspectRatio="none" aria-hidden="true">
      <defs>
        <pattern id="chGridPatInf" width="22" height="22" patternUnits="userSpaceOnUse">
          <path d="M 22 0 L 0 0 0 22" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="1"/>
        </pattern>
      </defs>
      <rect width="200" height="200" fill="url(#chGridPatInf)"/>
    </svg>
  </div>

  <div class="ch-content">
    <span class="ch-eyebrow">
      <span class="ch-dot"></span>
      Ouverture d'un nouveau dossier citoyen
    </span>

    <h1 class="ch-title">
      Enregistrer un<br>
      <span class="ch-title-hl">nouvel infirmier</span>
    </h1>

    <p class="ch-lead">
      Chaque création est <strong>signée</strong> et ajoutée au registre national.
      L'infirmier reçoit un <strong>identifiant universel</strong> valable dans tout le réseau hospitalier du Bénin.
    </p>

    <div class="ch-chips">
      <div class="ch-chip">
        <div class="ch-chip-icon"><i class="fas fa-id-badge"></i></div>
        <div class="ch-chip-text">
          <strong>Identifiant universel</strong>
          <span>Généré automatiquement</span>
        </div>
      </div>
      <div class="ch-chip">
        <div class="ch-chip-icon"><i class="fas fa-link"></i></div>
        <div class="ch-chip-text">
          <strong>Traçabilité totale</strong>
          <span>Journal infalsifiable</span>
        </div>
      </div>
      <div class="ch-chip">
        <div class="ch-chip-icon"><i class="fas fa-hospital-user"></i></div>
        <div class="ch-chip-text">
          <strong>Réseau national</strong>
          <span>Tous les hôpitaux agréés</span>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if ($erreur): ?>
  <div class="alert-box error reveal-up">
    <i class="fas fa-exclamation-triangle"></i>
    <div><?= htmlspecialchars($erreur) ?></div>
  </div>
<?php endif; ?>

<section class="form-shell reveal-up">
  <form method="POST" class="form-card" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-head trust">
      <div class="form-head-icon trust-icon"><i class="fas fa-user-nurse"></i></div>
      <div>
        <h2>Identité professionnelle</h2>
        <p>Tous les champs sont obligatoires sauf mention contraire.</p>
      </div>
    </div>

    <div class="form-body">
      <div class="section-divider"><i class="fas fa-user"></i> Identité</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Nom <span class="req">*</span></label>
          <div class="input-shell">
            <i class="fas fa-user"></i>
            <input type="text" name="nom" required value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>" placeholder="Nom de famille">
          </div>
        </div>
        <div class="form-group">
          <label>Prénom <span class="req">*</span></label>
          <div class="input-shell">
            <i class="fas fa-user"></i>
            <input type="text" name="prenom" required value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>" placeholder="Prénom">
          </div>
        </div>
        <div class="form-group">
          <label>Email <span class="req">*</span></label>
          <div class="input-shell">
            <i class="fas fa-envelope"></i>
            <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="infirmier@hopital.bj">
          </div>
        </div>
        <div class="form-group">
          <label>Téléphone</label>
          <div class="input-shell">
            <i class="fas fa-phone"></i>
            <input type="text" name="telephone" value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>" placeholder="+229 00 00 00 00">
          </div>
        </div>
      </div>

      <div class="section-divider"><i class="fas fa-briefcase-medical"></i> Fonction</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Fonction <span class="req">*</span></label>
          <div class="input-shell">
            <i class="fas fa-user-nurse"></i>
            <select name="fonction" required>
              <?php foreach ($fonctions as $k => $v): ?>
                <option value="<?= $k ?>" <?= ($_POST['fonction'] ?? 'accueil') === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Spécialité</label>
          <div class="input-shell">
            <i class="fas fa-stethoscope"></i>
            <input type="text" name="specialite" value="<?= htmlspecialchars($_POST['specialite'] ?? '') ?>" placeholder="Ex: Soins intensifs, Pédiatrie...">
          </div>
        </div>
        <div class="form-group">
          <label>Numéro de licence <span class="req">*</span></label>
          <div class="input-shell">
            <i class="fas fa-id-badge"></i>
            <input type="text" name="numero_licence" required value="<?= htmlspecialchars($_POST['numero_licence'] ?? '') ?>" placeholder="LIC-INF-001">
          </div>
        </div>
        <div class="form-group">
          <label>Hôpital d'affectation</label>
          <div class="input-shell">
            <i class="fas fa-hospital"></i>
            <select name="hopital_id">
              <option value="">— Sélectionner —</option>
              <?php
                $hopitalSelected = $_POST['hopital_id'] ?? $hopitalParDefaut;
                foreach ($hopitaux as $h): ?>
                <option value="<?= $h['id'] ?>" <?= $hopitalSelected == $h['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($h['nom']) ?> — <?= htmlspecialchars($h['ville']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="section-divider"><i class="fas fa-lock"></i> Accès au compte</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Mot de passe <span class="req">*</span></label>
          <div class="input-shell">
            <i class="fas fa-key"></i>
            <input type="password" name="mot_de_passe" id="mdp" required minlength="8" placeholder="Minimum 8 caractères">
            <button type="button" class="input-eye" onclick="togglePw('mdp', this)"><i class="fas fa-eye"></i></button>
          </div>
          <div id="mdp-strength" class="hint-muted"></div>
        </div>
        <div class="form-group">
          <label>Confirmation <span class="req">*</span></label>
          <div class="input-shell">
            <i class="fas fa-check"></i>
            <input type="password" name="confirmation" id="confirm" required placeholder="Répéter le mot de passe">
            <button type="button" class="input-eye" onclick="togglePw('confirm', this)"><i class="fas fa-eye"></i></button>
          </div>
          <div id="mdp-match" class="hint-muted"></div>
        </div>
      </div>

      <div class="form-info-strip">
        <i class="fas fa-circle-info"></i>
        <span>
          <strong>Note :</strong> Notez le mot de passe et communiquez-le par un canal sécurisé.
          L'infirmier pourra le modifier à sa première connexion.
        </span>
      </div>
    </div>

    <div class="form-actions">
      <a href="<?= $userType === 'admin' ? 'dashboard_admin.php' : 'dashboard_infirmier.php' ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Annuler
      </a>
      <button type="submit" class="btn btn-trust">
        <i class="fas fa-cube"></i> Enregistrer dans le registre
      </button>
    </div>
  </form>
</section>

<?php if ($created): ?>
<div id="popup-succes" class="modal-overlay" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-card reveal-up">
    <div class="modal-ribbon trust-ribbon"><i class="fas fa-check-circle"></i> Infirmier enregistré avec succès</div>
    <div class="modal-body">
      <div class="modal-avatar trust">
        <?= strtoupper(substr($created['prenom'],0,1) . substr($created['nom'],0,1)) ?>
      </div>
      <h2><?= htmlspecialchars($created['prenom'] . ' ' . $created['nom']) ?></h2>
      <div class="modal-specialty"><?= htmlspecialchars($created['fonction']) ?></div>

      <div class="modal-id-pill" data-copy="<?= htmlspecialchars($created['blockchain']) ?>">
        <i class="fas fa-id-badge"></i>
        <code><?= htmlspecialchars($created['blockchain']) ?></code>
        <i class="fas fa-copy copy-ic"></i>
      </div>

      <div class="credentials-table">
        <div class="cred-row"><span>Email</span><strong><?= htmlspecialchars($created['email']) ?></strong></div>
        <div class="cred-row"><span>Licence</span><strong><?= htmlspecialchars($created['licence']) ?></strong></div>
        <div class="cred-row"><span>Mot de passe</span><code><?= htmlspecialchars($created['mdp']) ?></code></div>
      </div>

      <div class="modal-warn">
        <i class="fas fa-triangle-exclamation"></i>
        Transmettez ces identifiants à l'infirmier.
      </div>

      <div class="modal-actions">
        <a href="liste_utilisateurs.php?role=infirmier" class="btn btn-trust">
          <i class="fas fa-list"></i> Voir tous les infirmiers
        </a>
        <button onclick="document.getElementById('popup-succes').style.display='none'" class="btn btn-outline">
          <i class="fas fa-user-nurse"></i> Créer un autre infirmier
        </button>
        <a href="<?= $userType === 'admin' ? 'dashboard_admin.php' : 'dashboard_infirmier.php' ?>" class="btn btn-outline">
          <i class="fas fa-home"></i> Tableau de bord
        </a>
      </div>
    </div>
  </div>
</div>
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
.ch-chips { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; }
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
@media (max-width: 860px) { .creer-hero { padding:32px 24px; border-radius:22px; } .ch-chips { grid-template-columns:1fr; } }
/* ================== FIN HERO ================== */

.form-shell { max-width:920px; margin:0 auto; }
.form-card { background:white; border:1px solid var(--line); border-radius:20px; overflow:hidden; box-shadow:0 14px 34px -18px rgba(15,23,42,.12); }
.form-head { display:flex; align-items:center; gap:16px; padding:24px 28px; border-bottom:1px solid var(--line); }
.form-head.trust { background:linear-gradient(135deg, rgba(14,165,233,.08), rgba(3,105,161,.04)); }
.form-head-icon { width:52px; height:52px; border-radius:14px; color:white; display:grid; place-items:center; font-size:20px; }
.form-head-icon.trust-icon { background:var(--g-trust); box-shadow:0 10px 24px -10px rgba(14,165,233,.5); }
.form-head h2 { font-family:'Plus Jakarta Sans',sans-serif; font-size:18px; font-weight:800; color:var(--ink); margin:0; }
.form-head p { font-size:13px; color:var(--muted); margin-top:2px; }
.form-head .req { color:#ef4444; }

.form-body { padding:28px; }
.section-divider { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#0369a1; padding:18px 0 12px; margin-top:8px; border-bottom:2px solid rgba(14,165,233,.15); margin-bottom:18px; display:flex; align-items:center; gap:8px; }
.section-divider:first-child { margin-top:0; padding-top:0; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:8px; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-group.full { grid-column:1/-1; }
.form-group label { font-size:13px; font-weight:700; color:var(--ink); display:flex; align-items:center; gap:6px; }
.form-group label .req { color:#ef4444; }
.input-shell { display:flex; align-items:center; gap:10px; background:#f8fafc; border:1.5px solid var(--line); border-radius:12px; padding:0 14px; transition:.25s; }
.input-shell:focus-within { border-color:#0ea5e9; background:white; box-shadow:0 0 0 3px rgba(14,165,233,.12); }
.input-shell i { color:var(--muted); width:14px; font-size:14px; }
.input-shell input, .input-shell select { flex:1; border:none; background:transparent; outline:none; padding:12px 0; font-size:14px; color:var(--ink); font-family:inherit; }
.input-eye { background:none; border:none; color:var(--muted); cursor:pointer; padding:4px; font-size:14px; transition:.2s; }
.input-eye:hover { color:#0369a1; }

.hint-muted { font-size:11px; color:var(--muted); font-weight:600; margin-top:2px; }
.hint-muted.ok  { color:#059669; }
.hint-muted.err { color:#ef4444; }

.form-info-strip { margin-top:18px; display:flex; align-items:center; gap:12px; padding:12px 16px; background:rgba(245,158,11,.06); border:1px solid rgba(245,158,11,.25); border-radius:12px; font-size:13px; color:#92400e; line-height:1.5; }
.form-info-strip i { font-size:16px; color:#d97706; flex-shrink:0; }

.form-actions { display:flex; justify-content:flex-end; gap:10px; padding:20px 28px; border-top:1px solid var(--line); background:#f8fafc; }

.alert-box { display:flex; align-items:center; gap:12px; padding:14px 18px; margin-bottom:20px; border-radius:14px; font-size:14px; }
.alert-box.error { background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.25); color:#b91c1c; }
.alert-box i { font-size:18px; }

.btn { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border-radius:12px; font-size:13px; font-weight:700; text-decoration:none; border:none; cursor:pointer; transition:.3s; font-family:inherit; }
.btn-trust { background:var(--g-trust); color:white; }
.btn-trust:hover { transform:translateY(-1px); box-shadow:0 10px 24px -8px rgba(14,165,233,.4); }
.btn-outline { background:white; border:1px solid var(--line); color:var(--ink); }
.btn-outline:hover { border-color:#0369a1; color:#0369a1; }

.modal-overlay { position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,.6); backdrop-filter:blur(4px); display:flex; align-items:center; justify-content:center; padding:20px; }
.modal-card { background:white; border-radius:22px; max-width:480px; width:100%; box-shadow:0 30px 80px rgba(0,0,0,.4); overflow:hidden; }
.modal-ribbon { padding:14px 20px; font-size:14px; font-weight:700; color:white; display:flex; align-items:center; gap:10px; }
.modal-ribbon.trust-ribbon { background:var(--g-trust); }
.modal-body { padding:28px; text-align:center; }
.modal-avatar { width:84px; height:84px; border-radius:50%; display:grid; place-items:center; margin:0 auto 16px; color:white; font-weight:800; font-size:28px; font-family:'Plus Jakarta Sans',sans-serif; }
.modal-avatar.trust { background:var(--g-trust); box-shadow:0 14px 30px -10px rgba(14,165,233,.5); }
.modal-body h2 { font-family:'Plus Jakarta Sans',sans-serif; font-size:20px; font-weight:800; color:var(--ink); margin-bottom:6px; }
.modal-specialty { font-size:13px; color:var(--muted); font-weight:600; margin-bottom:16px; }

.modal-id-pill { display:inline-flex; align-items:center; gap:10px; padding:8px 14px; background:rgba(99,102,241,.08); border:1px solid rgba(99,102,241,.2); border-radius:999px; font-size:12px; color:var(--blockchain); cursor:pointer; transition:.2s; margin-bottom:20px; }
.modal-id-pill:hover { background:rgba(99,102,241,.15); }
.modal-id-pill code { font-family:'JetBrains Mono',monospace; font-weight:700; }
.modal-id-pill .copy-ic { font-size:10px; opacity:.6; }

.credentials-table { background:#f8fafc; border:1px solid var(--line); border-radius:14px; padding:14px 18px; margin-bottom:18px; text-align:left; }
.cred-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px dashed var(--line); font-size:13px; gap:10px; }
.cred-row:last-child { border-bottom:none; }
.cred-row span { color:var(--muted); font-weight:600; }
.cred-row strong, .cred-row code { color:var(--ink); font-weight:700; word-break:break-all; text-align:right; }
.cred-row code { font-family:'JetBrains Mono',monospace; background:rgba(14,165,233,.1); color:#0369a1; padding:3px 10px; border-radius:6px; }

.modal-warn { padding:10px 14px; background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2); border-radius:10px; font-size:12px; color:#92400e; display:flex; align-items:center; gap:8px; margin-bottom:20px; }
.modal-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }

@media (max-width: 640px) { .form-grid { grid-template-columns:1fr; } .form-body, .form-head, .form-actions { padding:20px; } }
</style>

<script>
const mdp = document.getElementById('mdp');
const mdpStrength = document.getElementById('mdp-strength');
const confirmInput = document.getElementById('confirm');
const mdpMatch = document.getElementById('mdp-match');

mdp.addEventListener('input', function() {
  const v = this.value; let force = 0;
  if (v.length >= 6)  force++;
  if (v.length >= 10) force++;
  if (/[A-Z]/.test(v)) force++;
  if (/[0-9]/.test(v)) force++;
  if (/[^A-Za-z0-9]/.test(v)) force++;
  const labels = ['Très faible','Faible','Moyen','Bon','Fort','Très fort'];
  mdpStrength.textContent = v.length ? 'Force : ' + labels[force] : '';
  mdpStrength.className = 'hint-muted ' + (force >= 4 ? 'ok' : (force <= 1 ? 'err' : ''));
  if (confirmInput.value) checkMatch();
});
confirmInput.addEventListener('input', checkMatch);
function checkMatch() {
  if (!confirmInput.value) { mdpMatch.textContent = ''; return; }
  if (mdp.value === confirmInput.value) { mdpMatch.textContent = 'Les mots de passe correspondent'; mdpMatch.className = 'hint-muted ok'; }
  else { mdpMatch.textContent = 'Les mots de passe ne correspondent pas'; mdpMatch.className = 'hint-muted err'; }
}
function togglePw(id, btn) {
  const el = document.getElementById(id);
  if (el.type === 'password') { el.type = 'text'; btn.innerHTML = '<i class="fas fa-eye-slash"></i>'; }
  else { el.type = 'password'; btn.innerHTML = '<i class="fas fa-eye"></i>'; }
}
document.querySelectorAll('[data-copy]').forEach(el => {
  el.addEventListener('click', function () {
    navigator.clipboard.writeText(this.dataset.copy).then(() => {
      const orig = this.style.background;
      this.style.background = 'rgba(16,185,129,.25)';
      setTimeout(() => this.style.background = orig, 700);
    });
  });
});
</script>

<?php require_once 'includes/footer_dashboard.php'; ?>
