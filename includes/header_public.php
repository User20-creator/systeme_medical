<?php
/**
 * Header public (non connecté) — à inclure AVANT tout contenu.
 * Usage :
 *   $pageTitle = 'Connexion';
 *   $pageActive = 'connexion';  // accueil | services | hopitaux | blockchain | contact | connexion
 *   require_once 'includes/header_public.php';
 */

require_once __DIR__ . '/../config.php';

if (!isset($pageTitle)) $pageTitle = 'Plateforme Médicale Nationale';
if (!isset($pageActive)) $pageActive = '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> · MedChain Bénin</title>
<meta name="description" content="Plateforme nationale officielle de gestion des dossiers médicaux du Bénin.">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<header class="site-nav" id="siteNav">
  <div class="nav-inner">
    <a href="index.php" class="nav-logo" aria-label="Accueil MedChain">
      <span class="nav-logo-mark">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M8 3v18M16 3v18M3 8h18M3 16h18" opacity=".35"/>
          <path d="M12 6v12M6 12h12" stroke-width="2.8"/>
        </svg>
      </span>
      <span>
        MedChain
        <span class="nav-logo-sub">Bénin</span>
      </span>
    </a>

    <nav class="nav-links" aria-label="Navigation principale">
      <a href="index.php" class="nav-link <?= $pageActive === 'accueil' ? 'active' : '' ?>">Accueil</a>
      <a href="index.php#services" class="nav-link <?= $pageActive === 'services' ? 'active' : '' ?>">Services</a>
      <a href="index.php#hopitaux" class="nav-link <?= $pageActive === 'hopitaux' ? 'active' : '' ?>">Hôpitaux</a>
      <a href="index.php#registre" class="nav-link <?= $pageActive === 'registre' ? 'active' : '' ?>">Le registre</a>
      <a href="index.php#contact" class="nav-link <?= $pageActive === 'contact' ? 'active' : '' ?>">Contact</a>
    </nav>

    <div class="nav-actions">
      <a href="connexion1.php" class="btn btn-ghost btn-sm">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Connexion
      </a>
      <a href="connexion2.php" class="btn btn-primary btn-sm">
        Espace personnel
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </a>
      <button class="nav-burger" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>
  </div>
</header>

<main>
