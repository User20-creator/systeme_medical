<?php
require_once 'config.php';
require_once 'includes/hash_chain.php';

$error = '';

// Si l'utilisateur arrive ici alors qu'une session est encore active
// (par exemple via le bouton "précédent" du navigateur), on le déconnecte
// automatiquement. La page de connexion ne doit jamais être visible
// pendant une session.
if (!empty($_SESSION['user_type'])) {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant = trim($_POST['identifiant'] ?? '');
    $password    = $_POST['password']         ?? '';

    if (too_many_attempts()) {
        $error = "Trop de tentatives. Réessayez dans 15 minutes.";
    } elseif (!csrf_check()) {
        $error = "Jeton de sécurité invalide. Veuillez recharger la page.";
    } elseif (empty($identifiant) || empty($password)) {
        $error = "Veuillez remplir tous les champs.";
    } else {
        // ─── Anti-timing : on interroge TOUJOURS les 3 tables, même si on
        // trouve l'utilisateur dès la première. Le coût est négligeable et
        // empêche la fingerprint de "quel rôle est cet email".
        $candidates = [];

        $stmt = $pdo->prepare("SELECT *, 'infirmier' AS _t FROM infirmiers WHERE (email = ? OR identifiant_blockchain = ?) AND statut = 'actif' LIMIT 1");
        $stmt->execute([$identifiant, $identifiant]);
        if ($r = $stmt->fetch()) $candidates[] = $r;

        $stmt = $pdo->prepare("SELECT *, 'docteur' AS _t FROM docteurs WHERE (email = ? OR identifiant_blockchain = ?) AND statut = 'actif' LIMIT 1");
        $stmt->execute([$identifiant, $identifiant]);
        if ($r = $stmt->fetch()) $candidates[] = $r;

        $stmt = $pdo->prepare("SELECT *, 'admin' AS _t FROM admin WHERE email = ? AND statut = 'actif' LIMIT 1");
        $stmt->execute([$identifiant]);
        if ($r = $stmt->fetch()) $candidates[] = $r;

        // Sélectionner la première correspondance dont le mot de passe valide.
        // S'il n'y en a aucune, on reste avec $utilisateur = null.
        $utilisateur = null;
        $typeConnecte = '';
        foreach ($candidates as $c) {
            if (!empty($c['mot_de_passe']) && password_verify($password, $c['mot_de_passe'])) {
                $utilisateur = $c;
                $typeConnecte = $c['_t'];
                break;
            }
        }

        if ($utilisateur) {
            clear_attempts();
            session_regenerate_id(true);

            $_SESSION['medecin_id'] = $utilisateur['id'];
            $_SESSION['user_type']  = $typeConnecte;
            record_login();

            $tableName = ($typeConnecte === 'infirmier') ? 'infirmiers'
                      : (($typeConnecte === 'docteur')   ? 'docteurs' : 'admin');

            switch ($typeConnecte) {
                case 'infirmier':
                case 'docteur':
                    $_SESSION['medecin_nom']                    = $utilisateur['nom'];
                    $_SESSION['medecin_prenom']                 = $utilisateur['prenom'];
                    $_SESSION['medecin_specialite']             = $utilisateur['specialite']           ?? '';
                    $_SESSION['medecin_numero_licence']         = $utilisateur['numero_licence']       ?? '';
                    $_SESSION['medecin_telephone']              = $utilisateur['telephone']            ?? '';
                    $_SESSION['medecin_email']                  = $utilisateur['email'];
                    $_SESSION['medecin_hopital_principal_id']   = $utilisateur[($typeConnecte === 'infirmier' ? 'hopital_principal_id' : 'hopital_id')] ?? null;
                    $_SESSION['medecin_identifiant_blockchain'] = $utilisateur['identifiant_blockchain'] ?? '';
                    $_SESSION['medecin_signature_numerique']    = $utilisateur['signature_numerique']  ?? '';

                    HashChain::addBlock('LOGIN', $utilisateur['id'], $utilisateur['id'], $typeConnecte, [
                        'method'      => 'email_or_blockchain',
                        'identifiant' => $identifiant,
                    ], $tableName);

                    header('Location: ' . ($typeConnecte === 'infirmier' ? 'dashboard_infirmier.php' : 'dashboard_docteur.php'));
                    exit;

                case 'admin':
                    $_SESSION['admin_id']     = $utilisateur['id'];
                    $_SESSION['admin_nom']    = $utilisateur['nom'];
                    $_SESSION['admin_prenom'] = $utilisateur['prenom'];
                    $_SESSION['admin_email']  = $utilisateur['email'];

                    $pdo->prepare("UPDATE admin SET derniere_connexion = NOW() WHERE id = ?")
                        ->execute([$utilisateur['id']]);

                    HashChain::addBlock('LOGIN_ADMIN', $utilisateur['id'], $utilisateur['id'], 'admin', [
                        'method'      => 'email',
                        'identifiant' => $identifiant,
                    ], 'admin');

                    header('Location: dashboard_admin.php');
                    exit;
            }
        } else {
            register_attempt();
            $error = "Identifiant ou mot de passe incorrect.";
            HashChain::addBlock('LOGIN_FAILED', $identifiant ?: 'unknown', 0, 'personnel', [
                'identifiant_tente' => $identifiant,
            ], 'personnel');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion Personnel — MedChain</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?>">
<style>
  body {
    min-height: 100vh;
    background: radial-gradient(ellipse at top right, rgba(3,105,161,.08), transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(26,71,42,.06), transparent 50%),
                #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    position: relative;
    overflow-x: hidden;
  }

  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
      linear-gradient(rgba(3,105,161,.04) 1px, transparent 1px),
      linear-gradient(90deg, rgba(3,105,161,.04) 1px, transparent 1px);
    background-size: 48px 48px;
    pointer-events: none;
    z-index: 0;
  }

  .auth-shell {
    width: 100%;
    max-width: 1080px;
    position: relative;
    z-index: 1;
  }

  .auth-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
  }

  .auth-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
  }

  .auth-brand-logo {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--g-forest);
    display: grid;
    place-items: center;
    color: white;
    box-shadow: 0 10px 24px rgba(26,71,42,.25);
  }

  .auth-brand-txt { display: flex; flex-direction: column; line-height: 1.1; }
  .auth-brand-txt strong { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 18px; font-weight: 800; color: var(--forest); }
  .auth-brand-txt span { font-size: 12px; color: var(--muted); }

  .auth-back {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 18px; background: white; border: 1px solid var(--line);
    border-radius: 999px; color: var(--ink); text-decoration: none;
    font-size: 14px; font-weight: 600; transition: .3s;
  }

  .auth-back:hover { background: var(--trust); color: white; border-color: var(--trust); transform: translateX(-2px); }

  .auth-card {
    display: grid;
    grid-template-columns: 1fr 1.1fr;
    background: white;
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 40px 100px -40px rgba(15,23,42,.25);
    border: 1px solid rgba(226,232,240,.8);
  }

  /* ─── Left hero — ACCENT BLEU MEDICAL ─── */
  .auth-hero {
    position: relative;
    padding: 56px 48px;
    color: white;
    background: linear-gradient(135deg, #0f2d1c 0%, #0a3a5c 60%, #0369a1 100%);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  .auth-hero::before {
    content: ''; position: absolute; top: -120px; right: -120px;
    width: 380px; height: 380px;
    background: radial-gradient(circle, rgba(56,189,248,.35), transparent 60%);
    filter: blur(60px);
  }

  .auth-hero::after {
    content: ''; position: absolute; bottom: -100px; left: -80px;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(16,185,129,.25), transparent 60%);
    filter: blur(60px);
  }

  .auth-hero-badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 14px; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.15);
    border-radius: 999px; font-size: 12px; font-weight: 600; letter-spacing: .04em;
    width: fit-content; position: relative; z-index: 1;
  }

  .auth-hero-badge .dot {
    width: 7px; height: 7px; border-radius: 50%; background: #38bdf8;
    box-shadow: 0 0 12px #38bdf8; animation: pulse 2s infinite;
  }

  .auth-hero h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 38px; font-weight: 800; line-height: 1.15;
    margin-top: 24px; letter-spacing: -.02em;
    position: relative; z-index: 1;
  }

  .auth-hero h1 span {
    display: block;
    background: linear-gradient(135deg, #38bdf8, #7dd3fc);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }

  .auth-hero-desc {
    font-size: 15px; line-height: 1.6;
    color: rgba(255,255,255,.75); margin-top: 16px;
    position: relative; z-index: 1;
  }

  /* Rôles */
  .role-list {
    margin-top: 32px;
    display: flex; flex-direction: column; gap: 12px;
    position: relative; z-index: 1;
  }

  .role-item {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 16px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    backdrop-filter: blur(10px);
    transition: .3s;
  }

  .role-item:hover {
    background: rgba(255,255,255,.1);
    border-color: rgba(56,189,248,.3);
    transform: translateX(4px);
  }

  .role-item-icon {
    width: 42px; height: 42px; border-radius: 12px;
    background: rgba(56,189,248,.15); color: #38bdf8;
    display: grid; place-items: center;
    font-size: 17px;
    border: 1px solid rgba(56,189,248,.2);
    flex-shrink: 0;
  }

  .role-item-txt h4 { font-size: 14px; font-weight: 700; color: white; margin-bottom: 2px; }
  .role-item-txt p { font-size: 12px; color: rgba(255,255,255,.6); }

  .auth-hero-chain {
    margin-top: auto; padding-top: 32px;
    display: flex; align-items: center; gap: 12px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px; color: rgba(255,255,255,.5);
    position: relative; z-index: 1;
  }

  .auth-hero-chain-hash {
    padding: 6px 10px;
    background: rgba(56,189,248,.1); border: 1px solid rgba(56,189,248,.2);
    border-radius: 6px; color: #7dd3fc;
  }

  /* ─── Right form ─── */
  .auth-form { padding: 56px 48px; display: flex; flex-direction: column; }

  .auth-form-head { margin-bottom: 32px; }
  .auth-form-head h2 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 28px; font-weight: 800; color: var(--ink); letter-spacing: -.02em;
  }
  .auth-form-head p { font-size: 14px; color: var(--muted); margin-top: 6px; }

  .field { margin-bottom: 20px; }
  .field-label { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--ink); margin-bottom: 8px; }
  .field-label i { color: var(--trust); font-size: 12px; }

  .field-input {
    display: flex; align-items: center;
    background: #f8fafc; border: 1.5px solid var(--line);
    border-radius: 12px; padding: 0 16px; transition: .3s;
  }

  .field-input:focus-within {
    background: white; border-color: var(--trust);
    box-shadow: 0 0 0 4px rgba(3,105,161,.08);
  }

  .field-input > i { color: var(--muted); font-size: 15px; width: 20px; }

  .field-input input {
    flex: 1; padding: 14px 12px; border: none; background: transparent;
    outline: none; font-size: 14px; font-family: inherit; color: var(--ink);
  }

  .field-input input::placeholder { color: #94a3b8; }

  .pw-toggle {
    background: transparent; border: none; color: var(--muted);
    cursor: pointer; padding: 8px; border-radius: 8px; transition: .3s;
  }

  .pw-toggle:hover { color: var(--trust); background: rgba(3,105,161,.06); }

  .field-hint { font-size: 12px; color: var(--muted); margin-top: 6px; padding-left: 4px; }
  .field-hint i { color: var(--trust); margin-right: 4px; }

  .form-options {
    display: flex; justify-content: space-between; align-items: center;
    margin: 18px 0 24px;
  }

  .check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); cursor: pointer; }
  .check input { width: 16px; height: 16px; accent-color: var(--trust); cursor: pointer; }

  .forgot { font-size: 13px; color: var(--trust); text-decoration: none; font-weight: 600; }
  .forgot:hover { text-decoration: underline; }

  .btn-submit {
    width: 100%; padding: 15px 20px;
    background: var(--g-trust); color: white; border: none;
    border-radius: 12px; font-size: 15px; font-weight: 700; font-family: inherit;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    gap: 10px; transition: .3s;
    box-shadow: 0 10px 24px -8px rgba(3,105,161,.45);
    position: relative; overflow: hidden;
  }

  .btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 32px -8px rgba(3,105,161,.55);
  }

  .btn-submit::before {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(110deg, transparent 40%, rgba(255,255,255,.2) 50%, transparent 60%);
    transform: translateX(-100%); transition: transform .6s;
  }

  .btn-submit:hover::before { transform: translateX(100%); }

  .trust-seal {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px; margin-top: 20px;
    background: linear-gradient(135deg, rgba(3,105,161,.06), rgba(99,102,241,.04));
    border: 1px solid rgba(3,105,161,.2);
    border-radius: 12px;
  }

  .trust-seal-icon {
    width: 40px; height: 40px; background: var(--g-blockchain);
    border-radius: 10px; display: grid; place-items: center;
    color: white; font-size: 18px;
  }

  .trust-seal-text { flex: 1; font-size: 12px; }
  .trust-seal-text strong { display: block; color: var(--ink); font-size: 13px; margin-bottom: 2px; }
  .trust-seal-text span { color: var(--muted); font-family: 'JetBrains Mono', monospace; font-size: 11px; }

  .alert-err {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px; background: #fef2f2; border: 1px solid #fca5a5;
    border-radius: 12px; color: #b91c1c; font-size: 13px; font-weight: 500; margin-bottom: 20px;
  }

  .alert-err i { font-size: 18px; color: #ef4444; }

  .auth-foot {
    margin-top: 24px; padding-top: 20px;
    border-top: 1px dashed var(--line);
    text-align: center; font-size: 13px; color: var(--muted);
  }

  .auth-foot a { color: var(--trust); font-weight: 600; text-decoration: none; }
  .auth-foot a:hover { text-decoration: underline; }

  @media (max-width: 900px) {
    .auth-card { grid-template-columns: 1fr; }
    .auth-hero { padding: 40px 32px; }
    .auth-hero h1 { font-size: 28px; }
    .auth-form { padding: 40px 32px; }
  }

  @media (max-width: 560px) {
    body { padding: 16px; }
    .auth-hero, .auth-form { padding: 32px 24px; }
    .auth-top { flex-direction: column; gap: 12px; align-items: flex-start; }
  }

  @keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: .6; transform: scale(1.2); }
  }
</style>
</head>
<body>

<div class="auth-shell reveal-fade">
  <div class="auth-top">
    <a href="index.php" class="auth-brand">
      <div class="auth-brand-logo">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      </div>
      <div class="auth-brand-txt">
        <strong>MedChain</strong>
        <span>Plateforme nationale</span>
      </div>
    </a>
    <a href="index.php" class="auth-back">
      <i class="fas fa-arrow-left"></i> Retour accueil
    </a>
  </div>

  <div class="auth-card">
    <!-- Left hero -->
    <div class="auth-hero">
      <div class="auth-hero-badge">
        <span class="dot"></span> ESPACE PROFESSIONNEL
      </div>

      <h1>
        Soigner avec
        <span>confiance et traçabilité.</span>
      </h1>

      <p class="auth-hero-desc">
        Accédez aux dossiers patients, prescrivez, signez numériquement. Chaque action est vérifiable sur la chaîne.
      </p>

      <div class="role-list">
        <div class="role-item">
          <div class="role-item-icon"><i class="fas fa-user-nurse"></i></div>
          <div class="role-item-txt">
            <h4>Infirmier / Infirmière</h4>
            <p>Enregistrement patients · Dossiers médicaux</p>
          </div>
        </div>
        <div class="role-item">
          <div class="role-item-icon"><i class="fas fa-stethoscope"></i></div>
          <div class="role-item-txt">
            <h4>Docteur</h4>
            <p>Consultations · Diagnostics · Ordonnances</p>
          </div>
        </div>
      </div>

      <div class="auth-hero-chain">
        <i class="fas fa-link"></i>
        <span>Dernier bloc :</span>
        <span class="auth-hero-chain-hash"><?= htmlspecialchars(HashChain::shortHash(HashChain::getLastHash(), 6, 4)) ?></span>
      </div>
    </div>

    <!-- Right form -->
    <div class="auth-form">
      <div class="auth-form-head">
        <h2>Connexion</h2>
        <p>Utilisez votre email ou votre identifiant professionnel</p>
      </div>

      <?php if (!empty($error)): ?>
      <div class="alert-err">
        <i class="fas fa-exclamation-circle"></i>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="">
        <?= csrf_field() ?>
        <div class="field">
          <label class="field-label"><i class="fas fa-id-card"></i> Email ou Identifiant professionnel</label>
          <div class="field-input">
            <i class="fas fa-envelope"></i>
            <input type="text" name="identifiant" placeholder="email@hopital.bj ou DOC-…"
                   value="<?= htmlspecialchars($_POST['identifiant'] ?? '') ?>" required autocomplete="username">
          </div>
          <div class="field-hint">
            <i class="fas fa-info-circle"></i> Exemple : amadou.koffi@chu-parakou.bj
          </div>
        </div>

        <div class="field">
          <label class="field-label"><i class="fas fa-lock"></i> Mot de passe</label>
          <div class="field-input">
            <i class="fas fa-key"></i>
            <input type="password" name="password" id="pwInput" placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="pw-toggle" data-pw-toggle="pwInput" aria-label="Afficher/masquer">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>

        <div class="form-options">
          <label class="check">
            <input type="checkbox" name="remember">
            <span>Se souvenir de moi</span>
          </label>
          <a href="#" class="forgot"><i class="fas fa-question-circle"></i> Oublié ?</a>
        </div>

        <button type="submit" class="btn-submit">
          <i class="fas fa-sign-in-alt"></i> Se connecter
        </button>

        <div class="trust-seal">
          <div class="trust-seal-icon">
            <i class="fas fa-id-badge"></i>
          </div>
          <div class="trust-seal-text">
            <strong>Identifiant professionnel unique</strong>
            <span>Votre identité vérifiée sur la plateforme nationale</span>
          </div>
        </div>
      </form>

      <div class="auth-foot">
        <div>Patient ? <a href="connexion1.php">Connexion patient</a></div>
        <div style="margin-top:10px;">Pas encore de compte ? Contactez votre administrateur hospitalier</div>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
