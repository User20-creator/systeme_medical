<?php
require_once 'config.php';
require_once 'includes/hash_chain.php';

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

$error = '';
$selected_type    = isset($_POST['type'])    ? $_POST['type']    : 'social';
$selected_country = isset($_POST['country']) ? $_POST['country'] : 'bj';
$selected_prefix  = isset($_POST['prefix'])  ? $_POST['prefix']  : '+229';
$input_social = isset($_POST['social']) ? htmlspecialchars($_POST['social']) : '';
$input_email  = isset($_POST['email'])  ? htmlspecialchars($_POST['email'])  : '';
$input_phone  = isset($_POST['phone'])  ? htmlspecialchars($_POST['phone'])  : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate-limit avant CSRF (économise des ressources)
    if (too_many_attempts()) {
        $error = "Trop de tentatives. Réessayez dans 15 minutes.";
    } elseif (!csrf_check()) {
        $error = "Jeton de sécurité invalide. Veuillez recharger la page.";
    } else {
        $password = $_POST['password'] ?? '';
        $type     = $_POST['type']     ?? 'social';

        $identifiant = '';
        $field = '';
        if ($type === 'social') {
            $identifiant = trim($_POST['social'] ?? '');
            $field = 'NPI';
        } elseif ($type === 'email') {
            $identifiant = trim($_POST['email'] ?? '');
            $field = 'email';
        } elseif ($type === 'phone') {
            $phone_number = $_POST['phone'] ?? '';
            $prefix       = $_POST['prefix'] ?? '+229';
            $clean_phone  = preg_replace('/[^0-9]/', '', $phone_number);
            // On limite à 10 chiffres maximum côté serveur (sécurité)
            $clean_phone  = substr($clean_phone, 0, 10);
            if (strlen($clean_phone) !== 10) {
                $error = "Le numéro de téléphone doit contenir exactement 10 chiffres.";
            }
            $identifiant  = $prefix . ' ' . $clean_phone;
            $field = 'telephone';
        } else {
            $type = 'social';
            $field = 'NPI';
        }

        if (empty($identifiant) || empty($password)) {
            $error = "Veuillez remplir tous les champs.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM patients WHERE $field = ? AND statut = 'actif' LIMIT 1");
            $stmt->execute([$identifiant]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['mot_de_passe'])) {
                clear_attempts();
                session_regenerate_id(true);

                $_SESSION['user_id']          = $user['id'];
                $_SESSION['user_type']        = 'patient';
                $_SESSION['user_nom']         = $user['prenom'] . ' ' . $user['nom'];
                $_SESSION['user_identifiant'] = $user[$field];
                record_login();

                HashChain::addBlock('LOGIN', $user['id'], $user['id'], 'patient', [
                    'method'      => $type,
                    'identifiant' => $identifiant,
                ], 'patients');

                header('Location: dashboard_patient.php');
                exit;
            } else {
                register_attempt();
                $error = "Identifiant ou mot de passe incorrect.";
                HashChain::addBlock('LOGIN_FAILED', $identifiant ?: 'unknown', 0, 'patient', [
                    'method'             => $type,
                    'identifiant_tente'  => $identifiant,
                ], 'patients');
            }
        }
    }
}

$countries = [
    'bj' => 'Bénin (+229)',
    'fr' => 'France (+33)',
    'ci' => "Côte d'Ivoire (+225)",
    'sn' => 'Sénégal (+221)',
    'tg' => 'Togo (+228)',
    'ng' => 'Nigéria (+234)'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion Patient — MedChain</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/6.6.6/css/flag-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?>">
<style>
  body {
    min-height: 100vh;
    background: radial-gradient(ellipse at top left, rgba(26,71,42,.08), transparent 50%),
                radial-gradient(ellipse at bottom right, rgba(99,102,241,.08), transparent 50%),
                #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    position: relative;
    overflow-x: hidden;
  }

  /* Grille subtile en fond */
  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
      linear-gradient(rgba(26,71,42,.04) 1px, transparent 1px),
      linear-gradient(90deg, rgba(26,71,42,.04) 1px, transparent 1px);
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

  .auth-brand-txt {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
  }

  .auth-brand-txt strong {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 18px;
    font-weight: 800;
    color: var(--forest);
  }

  .auth-brand-txt span {
    font-size: 12px;
    color: var(--muted);
  }

  .auth-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: white;
    border: 1px solid var(--line);
    border-radius: 999px;
    color: var(--ink);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: .3s;
  }

  .auth-back:hover {
    background: var(--forest);
    color: white;
    border-color: var(--forest);
    transform: translateX(-2px);
  }

  .auth-card {
    display: grid;
    grid-template-columns: 1fr 1.1fr;
    background: white;
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 40px 100px -40px rgba(15,23,42,.25);
    border: 1px solid rgba(226,232,240,.8);
  }

  /* ─────── Partie gauche — hero brand ─────── */
  .auth-hero {
    position: relative;
    padding: 56px 48px;
    color: white;
    background: linear-gradient(135deg, #1a472a 0%, #0e2a1a 60%, #0a1f14 100%);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  .auth-hero::before {
    content: '';
    position: absolute;
    top: -120px;
    right: -120px;
    width: 380px;
    height: 380px;
    background: radial-gradient(circle, rgba(16,185,129,.35), transparent 60%);
    filter: blur(60px);
  }

  .auth-hero::after {
    content: '';
    position: absolute;
    bottom: -100px;
    left: -80px;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(99,102,241,.25), transparent 60%);
    filter: blur(60px);
  }

  .auth-hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: .04em;
    width: fit-content;
    position: relative;
    z-index: 1;
  }

  .auth-hero-badge .dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 12px #10b981;
    animation: pulse 2s infinite;
  }

  .auth-hero h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 38px;
    font-weight: 800;
    line-height: 1.15;
    margin-top: 24px;
    letter-spacing: -.02em;
    position: relative;
    z-index: 1;
  }

  .auth-hero h1 span {
    display: block;
    background: linear-gradient(135deg, #10b981, #34d399);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
  }

  .auth-hero-desc {
    font-size: 15px;
    line-height: 1.6;
    color: rgba(255,255,255,.75);
    margin-top: 16px;
    position: relative;
    z-index: 1;
  }

  .auth-features {
    list-style: none;
    margin-top: 32px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    position: relative;
    z-index: 1;
  }

  .auth-features li {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    color: rgba(255,255,255,.9);
  }

  .auth-features i {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: rgba(16,185,129,.15);
    color: #34d399;
    display: grid;
    place-items: center;
    font-size: 14px;
    border: 1px solid rgba(16,185,129,.2);
  }

  .auth-hero-chain {
    margin-top: auto;
    padding-top: 32px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    color: rgba(255,255,255,.5);
    position: relative;
    z-index: 1;
  }

  .auth-hero-chain-hash {
    padding: 6px 10px;
    background: rgba(16,185,129,.1);
    border: 1px solid rgba(16,185,129,.2);
    border-radius: 6px;
    color: #34d399;
  }

  /* ─────── Partie droite — form ─────── */
  .auth-form {
    padding: 56px 48px;
    display: flex;
    flex-direction: column;
  }

  .auth-form-head {
    margin-bottom: 32px;
  }

  .auth-form-head h2 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 28px;
    font-weight: 800;
    color: var(--ink);
    letter-spacing: -.02em;
  }

  .auth-form-head p {
    font-size: 14px;
    color: var(--muted);
    margin-top: 6px;
  }

  /* Sélecteur de type */
  .type-tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    padding: 6px;
    background: #f1f5f9;
    border-radius: 14px;
    margin-bottom: 22px;
  }

  .type-tab {
    padding: 12px 8px;
    background: transparent;
    border: none;
    border-radius: 10px;
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    transition: .3s;
  }

  .type-tab i {
    font-size: 18px;
  }

  .type-tab.active {
    background: white;
    color: var(--forest);
    box-shadow: 0 4px 12px rgba(15,23,42,.08), 0 1px 2px rgba(15,23,42,.04);
  }

  .type-tab:hover:not(.active) {
    color: var(--ink);
  }

  /* Form field */
  .field {
    margin-bottom: 18px;
  }

  .field-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--ink);
    margin-bottom: 8px;
  }

  .field-label i {
    color: var(--forest);
    font-size: 12px;
  }

  .field-input {
    display: flex;
    align-items: center;
    background: #f8fafc;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    padding: 0 16px;
    transition: .3s;
  }

  .field-input:focus-within {
    background: white;
    border-color: var(--forest);
    box-shadow: 0 0 0 4px rgba(26,71,42,.08);
  }

  .field-input > i {
    color: var(--muted);
    font-size: 15px;
    width: 20px;
  }

  .field-input input {
    flex: 1;
    padding: 14px 12px;
    border: none;
    background: transparent;
    outline: none;
    font-size: 14px;
    font-family: inherit;
    color: var(--ink);
  }

  .field-input input::placeholder {
    color: #94a3b8;
  }

  .pw-toggle {
    background: transparent;
    border: none;
    color: var(--muted);
    cursor: pointer;
    padding: 8px;
    border-radius: 8px;
    transition: .3s;
  }

  .pw-toggle:hover {
    color: var(--forest);
    background: rgba(26,71,42,.06);
  }

  /* Country selector */
  .country-box {
    position: relative;
    margin-bottom: 18px;
  }

  .country-trigger {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: #f8fafc;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    cursor: pointer;
    transition: .3s;
    font-size: 14px;
    color: var(--ink);
  }

  .country-trigger:hover {
    border-color: var(--forest);
  }

  .country-trigger .flag-icon {
    font-size: 20px;
    border-radius: 3px;
  }

  .country-trigger .chev {
    margin-left: auto;
    color: var(--muted);
    font-size: 11px;
    transition: .3s;
  }

  .country-trigger.open .chev {
    transform: rotate(180deg);
  }

  .country-dropdown {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    background: white;
    border: 1px solid var(--line);
    border-radius: 12px;
    box-shadow: 0 20px 40px -10px rgba(15,23,42,.15);
    overflow: hidden;
    z-index: 10;
    display: none;
  }

  .country-dropdown.open {
    display: block;
  }

  .country-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    cursor: pointer;
    font-size: 14px;
    color: var(--ink);
    transition: background .2s;
  }

  .country-item:hover,
  .country-item.active {
    background: rgba(26,71,42,.06);
    color: var(--forest);
  }

  .country-item .flag-icon {
    font-size: 18px;
    border-radius: 3px;
  }

  .phone-field-wrap {
    display: flex;
    align-items: stretch;
    background: #f8fafc;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    overflow: hidden;
    transition: .3s;
  }

  .phone-field-wrap:focus-within {
    background: white;
    border-color: var(--forest);
    box-shadow: 0 0 0 4px rgba(26,71,42,.08);
  }

  .phone-prefix-box {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 16px;
    background: rgba(26,71,42,.04);
    border-right: 1.5px solid var(--line);
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
  }

  .phone-prefix-box .flag-icon {
    font-size: 18px;
    border-radius: 3px;
  }

  .phone-field-wrap input {
    flex: 1;
    border: none;
    background: transparent;
    outline: none;
    padding: 14px 16px;
    font-size: 14px;
    font-family: inherit;
    color: var(--ink);
  }

  /* Options */
  .form-options {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 18px 0 24px;
  }

  .check {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--muted);
    cursor: pointer;
  }

  .check input {
    width: 16px;
    height: 16px;
    accent-color: var(--forest);
    cursor: pointer;
  }

  .forgot {
    font-size: 13px;
    color: var(--forest);
    text-decoration: none;
    font-weight: 600;
  }

  .forgot:hover {
    text-decoration: underline;
  }

  /* Submit button */
  .btn-submit {
    width: 100%;
    padding: 15px 20px;
    background: var(--g-forest);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: .3s;
    box-shadow: 0 10px 24px -8px rgba(26,71,42,.45);
    position: relative;
    overflow: hidden;
  }

  .btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 32px -8px rgba(26,71,42,.55);
  }

  .btn-submit::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(110deg, transparent 40%, rgba(255,255,255,.2) 50%, transparent 60%);
    transform: translateX(-100%);
    transition: transform .6s;
  }

  .btn-submit:hover::before {
    transform: translateX(100%);
  }

  /* Divider */
  .divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 24px 0;
    font-size: 12px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 600;
  }

  .divider::before,
  .divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--line);
  }

  /* Social buttons */
  .social-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
  }

  .social-btn {
    padding: 12px;
    background: white;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    color: var(--ink);
    font-size: 18px;
    text-decoration: none;
    text-align: center;
    transition: .3s;
  }

  .social-btn:hover {
    transform: translateY(-2px);
    border-color: var(--forest);
    color: var(--forest);
    box-shadow: 0 10px 20px -8px rgba(15,23,42,.12);
  }

  /* Footer links */
  .auth-foot {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px dashed var(--line);
    text-align: center;
    font-size: 13px;
    color: var(--muted);
  }

  .auth-foot a {
    color: var(--forest);
    font-weight: 600;
    text-decoration: none;
  }

  .auth-foot a:hover {
    text-decoration: underline;
  }

  /* Trust seal */
  .trust-seal {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    margin-top: 20px;
    background: linear-gradient(135deg, rgba(16,185,129,.06), rgba(26,71,42,.04));
    border: 1px solid rgba(16,185,129,.2);
    border-radius: 12px;
  }

  .trust-seal-icon {
    width: 40px;
    height: 40px;
    background: var(--g-emerald);
    border-radius: 10px;
    display: grid;
    place-items: center;
    color: white;
    font-size: 18px;
  }

  .trust-seal-text {
    flex: 1;
    font-size: 12px;
  }

  .trust-seal-text strong {
    display: block;
    color: var(--ink);
    font-size: 13px;
    margin-bottom: 2px;
  }

  .trust-seal-text span {
    color: var(--muted);
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
  }

  /* Error */
  .alert-err {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: 12px;
    color: #b91c1c;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 20px;
  }

  .alert-err i {
    font-size: 18px;
    color: #ef4444;
  }

  /* Hint */
  .field-hint {
    font-size: 12px;
    color: var(--muted);
    margin-top: 6px;
    padding-left: 4px;
  }

  .field-hint i {
    color: var(--forest);
    margin-right: 4px;
  }

  /* Responsive */
  @media (max-width: 900px) {
    .auth-card {
      grid-template-columns: 1fr;
    }

    .auth-hero {
      padding: 40px 32px;
    }

    .auth-hero h1 {
      font-size: 28px;
    }

    .auth-form {
      padding: 40px 32px;
    }
  }

  @media (max-width: 560px) {
    body {
      padding: 16px;
    }

    .auth-hero,
    .auth-form {
      padding: 32px 24px;
    }

    .auth-top {
      flex-direction: column;
      gap: 12px;
      align-items: flex-start;
    }

    .social-row {
      grid-template-columns: repeat(2, 1fr);
    }
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
    <!-- ─────── Left hero ─────── -->
    <div class="auth-hero">
      <div class="auth-hero-badge">
        <span class="dot"></span> ESPACE PATIENT
      </div>

      <h1>
        Votre santé,
        <span>entre vos mains.</span>
      </h1>

      <p class="auth-hero-desc">
        Accédez à votre dossier médical sécurisé, consultez vos ordonnances et gérez vos rendez-vous en toute confidentialité.
      </p>

      <ul class="auth-features">
        <li><i class="fas fa-shield-alt"></i> Connexion protégée et sécurisée</li>
        <li><i class="fas fa-id-badge"></i> Identifiant national unique</li>
        <li><i class="fas fa-user-md"></i> Partage contrôlé avec vos soignants</li>
        <li><i class="fas fa-history"></i> Traçabilité complète de chaque accès</li>
      </ul>

      <div class="auth-hero-chain">
        <i class="fas fa-circle-check"></i>
        <span>Plateforme officielle</span>
        <span class="auth-hero-chain-hash">République du Bénin</span>
      </div>
    </div>

    <!-- ─────── Right form ─────── -->
    <div class="auth-form">
      <div class="auth-form-head">
        <h2>Connexion</h2>
        <p>Choisissez votre méthode de connexion sécurisée</p>
      </div>

      <?php if (!empty($error)): ?>
      <div class="alert-err">
        <i class="fas fa-exclamation-circle"></i>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="post" action="" id="loginForm">
        <?= csrf_field() ?>
        <input type="hidden" name="type" id="type-input" value="<?= htmlspecialchars($selected_type) ?>">
        <input type="hidden" name="country" id="country-input" value="<?= htmlspecialchars($selected_country) ?>">
        <input type="hidden" name="prefix" id="prefix-input" value="<?= htmlspecialchars($selected_prefix) ?>">

        <div class="type-tabs" role="tablist">
          <button type="button" class="type-tab <?= $selected_type === 'social' ? 'active' : '' ?>" data-type="social">
            <i class="fas fa-id-card"></i>
            <span>NPI</span>
          </button>
          <button type="button" class="type-tab <?= $selected_type === 'email' ? 'active' : '' ?>" data-type="email">
            <i class="fas fa-envelope"></i>
            <span>Email</span>
          </button>
          <button type="button" class="type-tab <?= $selected_type === 'phone' ? 'active' : '' ?>" data-type="phone">
            <i class="fas fa-phone-alt"></i>
            <span>Téléphone</span>
          </button>
        </div>

        <!-- Sélecteur pays (téléphone) -->
        <div class="country-box" id="countryBox" style="display: <?= $selected_type === 'phone' ? 'block' : 'none' ?>;">
          <label class="field-label"><i class="fas fa-globe-africa"></i> Pays</label>
          <div class="country-trigger" id="countryTrigger">
            <span class="flag-icon flag-icon-<?= htmlspecialchars($selected_country) ?>" id="selectedFlag"></span>
            <span id="selectedCountry"><?= htmlspecialchars($countries[$selected_country] ?? 'Bénin (+229)') ?></span>
            <i class="fas fa-chevron-down chev"></i>
          </div>
          <div class="country-dropdown" id="countryDropdown">
            <?php
            $countryList = [
              ['bj', '+229', 'Bénin'],
              ['fr', '+33', 'France'],
              ['ci', '+225', "Côte d'Ivoire"],
              ['sn', '+221', 'Sénégal'],
              ['tg', '+228', 'Togo'],
              ['ng', '+234', 'Nigéria'],
            ];
            foreach ($countryList as [$code, $pfx, $name]):
            ?>
            <div class="country-item <?= $selected_country === $code ? 'active' : '' ?>"
                 data-code="<?= $code ?>" data-prefix="<?= $pfx ?>" data-name="<?= htmlspecialchars($name) ?>">
              <span class="flag-icon flag-icon-<?= $code ?>"></span>
              <span><?= htmlspecialchars($name) ?> (<?= $pfx ?>)</span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="field">
          <label class="field-label"><i class="fas fa-user-circle"></i> Identifiant</label>

          <div class="field-input" id="input-social" style="display: <?= $selected_type === 'social' ? 'flex' : 'none' ?>;">
            <i class="fas fa-id-card"></i>
            <input type="text" name="social" id="social-input" placeholder="Votre numéro NPI"
                   value="<?= $input_social ?>" <?= $selected_type === 'social' ? 'required' : '' ?>>
          </div>

          <div class="field-input" id="input-email" style="display: <?= $selected_type === 'email' ? 'flex' : 'none' ?>;">
            <i class="fas fa-envelope"></i>
            <input type="email" name="email" id="email-input" placeholder="vous@exemple.com"
                   value="<?= $input_email ?>" <?= $selected_type === 'email' ? 'required' : '' ?>>
          </div>

          <div class="phone-field-wrap" id="input-phone" style="display: <?= $selected_type === 'phone' ? 'flex' : 'none' ?>;">
            <div class="phone-prefix-box" id="phonePrefix">
              <span class="flag-icon flag-icon-<?= htmlspecialchars($selected_country) ?>"></span>
              <span><?= htmlspecialchars($selected_prefix) ?></span>
            </div>
            <input type="tel" name="phone" id="phone-input" placeholder="01 00 00 00 00"
                   maxlength="14" inputmode="numeric" autocomplete="tel-national"
                   value="<?= $input_phone ?>" <?= $selected_type === 'phone' ? 'required' : '' ?>>
          </div>

          <div class="field-hint" id="phoneHint" style="display: <?= $selected_type === 'phone' ? 'block' : 'none' ?>;">
            <i class="fas fa-info-circle"></i> Exemple : +229 01 56 57 58 48 (10 chiffres)
          </div>
        </div>

        <div class="field">
          <label class="field-label"><i class="fas fa-lock"></i> Mot de passe</label>
          <div class="field-input">
            <i class="fas fa-key"></i>
            <input type="password" name="password" id="pwInput" placeholder="••••••••" required>
            <button type="button" class="pw-toggle" data-pw-toggle="pwInput" aria-label="Afficher/masquer">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>

        <div class="form-options">
          <label class="check">
            <input type="checkbox" name="remember" checked>
            <span>Se souvenir de moi</span>
          </label>
          <a href="#" class="forgot"><i class="fas fa-question-circle"></i> Oublié ?</a>
        </div>

        <button type="submit" class="btn-submit">
          <i class="fas fa-sign-in-alt"></i> Se connecter
        </button>

        <div class="trust-seal">
          <div class="trust-seal-icon">
            <i class="fas fa-shield-halved"></i>
          </div>
          <div class="trust-seal-text">
            <strong>Connexion sécurisée</strong>
            <span>Chaque accès est tracé dans le registre national</span>
          </div>
        </div>
      </form>

      <div class="divider">Ou connectez-vous avec</div>

      <div class="social-row">
        <a href="#" class="social-btn" title="Google"><i class="fab fa-google"></i></a>
        <a href="#" class="social-btn" title="Facebook"><i class="fab fa-facebook-f"></i></a>
        <a href="#" class="social-btn" title="Microsoft"><i class="fab fa-microsoft"></i></a>
        <a href="#" class="social-btn" title="Apple"><i class="fab fa-apple"></i></a>
      </div>

      <div class="auth-foot">
        <div>Pas encore de compte patient ? <strong>Contactez votre hôpital</strong> pour qu'un infirmier d'accueil l'enregistre.</div>
        <div style="margin-top:10px;">Vous êtes professionnel de santé ? <a href="connexion2.php">Connexion médecin →</a></div>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
<script>
(function() {
  const typeTabs = document.querySelectorAll('.type-tab');
  const typeInput = document.getElementById('type-input');
  const countryBox = document.getElementById('countryBox');
  const countryTrigger = document.getElementById('countryTrigger');
  const countryDropdown = document.getElementById('countryDropdown');
  const countryItems = document.querySelectorAll('.country-item');
  const selectedFlag = document.getElementById('selectedFlag');
  const selectedCountry = document.getElementById('selectedCountry');
  const phonePrefix = document.getElementById('phonePrefix');
  const countryInput = document.getElementById('country-input');
  const prefixInput = document.getElementById('prefix-input');
  const phoneHint = document.getElementById('phoneHint');

  const inputs = {
    social: { wrap: document.getElementById('input-social'), input: document.getElementById('social-input') },
    email:  { wrap: document.getElementById('input-email'),  input: document.getElementById('email-input') },
    phone:  { wrap: document.getElementById('input-phone'),  input: document.getElementById('phone-input') }
  };

  function setType(type) {
    typeTabs.forEach(t => t.classList.toggle('active', t.dataset.type === type));
    typeInput.value = type;

    Object.entries(inputs).forEach(([k, o]) => {
      const show = k === type;
      o.wrap.style.display = show ? 'flex' : 'none';
      o.input.required = show;
    });

    const isPhone = type === 'phone';
    countryBox.style.display = isPhone ? 'block' : 'none';
    phoneHint.style.display = isPhone ? 'block' : 'none';

    const focusable = inputs[type].input;
    setTimeout(() => focusable.focus(), 100);
  }

  typeTabs.forEach(tab => {
    tab.addEventListener('click', () => setType(tab.dataset.type));
  });

  countryTrigger.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = countryDropdown.classList.toggle('open');
    countryTrigger.classList.toggle('open', isOpen);
  });

  countryItems.forEach(item => {
    item.addEventListener('click', () => {
      const code = item.dataset.code;
      const pfx = item.dataset.prefix;
      const name = item.dataset.name;

      countryItems.forEach(c => c.classList.remove('active'));
      item.classList.add('active');

      selectedFlag.className = 'flag-icon flag-icon-' + code;
      selectedCountry.textContent = name + ' (' + pfx + ')';
      phonePrefix.innerHTML = '<span class="flag-icon flag-icon-' + code + '"></span><span>' + pfx + '</span>';

      countryInput.value = code;
      prefixInput.value = pfx;

      countryDropdown.classList.remove('open');
      countryTrigger.classList.remove('open');
    });
  });

  document.addEventListener('click', (e) => {
    if (!countryTrigger.contains(e.target) && !countryDropdown.contains(e.target)) {
      countryDropdown.classList.remove('open');
      countryTrigger.classList.remove('open');
    }
  });

  // ── Formatage live du téléphone : "XX XX XX XX XX" max 10 chiffres ──
  const phoneInput = document.getElementById('phone-input');
  if (phoneInput) {
    phoneInput.addEventListener('input', function () {
      let digits = this.value.replace(/[^0-9]/g, '').slice(0, 10);
      const groups = [];
      for (let i = 0; i < digits.length; i += 2) groups.push(digits.slice(i, i + 2));
      this.value = groups.join(' ');
    });
    // Format initial si valeur présente
    if (phoneInput.value) phoneInput.dispatchEvent(new Event('input'));
  }
})();
</script>
</body>
</html>
