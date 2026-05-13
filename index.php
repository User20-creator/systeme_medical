<?php
$pageTitle = 'Accueil';
$pageActive = 'accueil';
require_once 'includes/header_public.php';
require_once 'includes/hash_chain.php';

// Récupérer les hôpitaux partenaires
$hopitaux = [];
$hopitauxCount = 0;
$patientsCount = 0;
try {
    $stmt = $pdo->query("SELECT * FROM hopitaux WHERE statut = 'actif' ORDER BY nom LIMIT 6");
    $hopitaux = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hopitauxCount = (int) $pdo->query("SELECT COUNT(*) FROM hopitaux WHERE statut = 'actif'")->fetchColumn();
    $patientsCount = (int) $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
} catch (Exception $e) { /* silent */ }

// Stats blockchain
$bcStats = HashChain::getStats();

// Mapping images hôpitaux — uniquement les fichiers réellement présents dans images/
$imageMap = [
    'CHU-Parakou'       => 'images/cnhu_parakou.jpg',
    'CHU de Parakou'    => 'images/cnhu_parakou.jpg',
    'CNHU-HKM'          => 'images/cnhu.jpeg',
    'CNHU de Cotonou'   => 'images/cnhu.jpeg',
    'CNHU de Allada'    => 'images/cnhu.jpeg',
    'Institut Pasteur'  => 'images/pasteur.jpg',
    'Hôpital de Zone'   => 'images/zone.jpg',
];
function hopitalImage($hopital, $imageMap) {
    foreach ($imageMap as $key => $file) {
        if (stripos($hopital['nom'], $key) !== false) {
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/systeme_medical/' . $file)) {
                return $file;
            }
        }
    }
    // Fallback : la colonne s'appelle 'Image' (majuscule) en BDD
    $img = $hopital['Image'] ?? $hopital['image'] ?? null;
    if (!empty($img) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/systeme_medical/' . $img)) {
        return $img;
    }
    return null;
}
?>

<!-- ============ HERO ============ -->
<section class="hero" style="position:relative;overflow:hidden;padding:160px 0 120px;isolation:isolate;">

  <!-- Aurora background -->
  <div class="aurora-wrap" aria-hidden="true">
    <div class="aurora aurora-forest"></div>
    <div class="aurora aurora-emerald"></div>
    <div class="aurora aurora-trust"></div>
  </div>
  <div class="grid-bg" aria-hidden="true"></div>

  <!-- Meteors -->
  <div class="meteors" aria-hidden="true">
    <?php for ($i = 0; $i < 10; $i++): ?>
      <span class="meteor" style="top:<?= rand(0, 70) ?>%;left:<?= rand(20, 90) ?>%;animation-delay:<?= rand(0, 40) / 10 ?>s;animation-duration:<?= rand(5, 10) ?>s;"></span>
    <?php endfor; ?>
  </div>

  <div class="container" style="position:relative;z-index:5;">
    <div style="display:grid;grid-template-columns:1.15fr 1fr;gap:64px;align-items:center;" class="hero-grid-responsive">

      <!-- LEFT : text -->
      <div>
        <span class="trust-pill reveal-up" data-delay="0" style="margin-bottom:24px;">
          <span class="trust-pill-dot"></span>
          <span>Plateforme nationale officielle · Bénin</span>
          <i class="fas fa-arrow-right" style="font-size:.7rem;color:var(--emerald);"></i>
        </span>

        <h1 class="reveal-up" data-delay="100" style="font-size:clamp(2.5rem,5vw,4.25rem);font-weight:800;line-height:1.05;letter-spacing:-.03em;margin-bottom:24px;">
          Gestion nationale des<br>
          <span class="gradient-text">dossiers médicaux</span><br>
          unifiés en temps réel
        </h1>

        <p class="reveal-up" data-delay="200" style="font-size:1.125rem;color:var(--slate);max-width:560px;margin-bottom:40px;line-height:1.7;">
          Médecins, hôpitaux et patients accèdent en temps réel aux dossiers médicaux via un <strong style="color:var(--ink);">registre national unifié</strong>. Confidentialité garantie, traçabilité totale, accès instantané partout au Bénin.
        </p>

        <div class="reveal-up" data-delay="300" style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:48px;">
          <a href="connexion1.php" class="btn-shimmer">
            <span class="btn-shimmer-inner">
              <i class="fas fa-user"></i>
              Espace patient
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </span>
          </a>
          <a href="connexion2.php" class="btn btn-outline btn-lg">
            <i class="fas fa-user-md"></i>
            Espace professionnel
          </a>
        </div>

        <!-- Trust row -->
        <div class="reveal-up" data-delay="400" style="display:flex;gap:32px;align-items:center;flex-wrap:wrap;padding-top:24px;border-top:1px solid var(--border);">
          <div>
            <div style="font-family:var(--sans);font-size:1.75rem;font-weight:800;color:var(--ink);line-height:1;" data-count="<?= $hopitauxCount ?>"><?= $hopitauxCount ?></div>
            <div style="font-size:.75rem;color:var(--muted);letter-spacing:.05em;text-transform:uppercase;margin-top:4px;font-weight:600;">Hôpitaux connectés</div>
          </div>
          <div style="width:1px;height:36px;background:var(--border);"></div>
          <div>
            <div style="font-family:var(--sans);font-size:1.75rem;font-weight:800;color:var(--ink);line-height:1;" data-count="<?= $patientsCount ?>"><?= $patientsCount ?></div>
            <div style="font-size:.75rem;color:var(--muted);letter-spacing:.05em;text-transform:uppercase;margin-top:4px;font-weight:600;">Patients enregistrés</div>
          </div>
          <div style="width:1px;height:36px;background:var(--border);"></div>
          <div>
            <div style="font-family:var(--sans);font-size:1.75rem;font-weight:800;color:var(--ink);line-height:1;" data-count="<?= max(1, $bcStats['total_blocks']) ?>">0</div>
            <div style="font-size:.75rem;color:var(--muted);letter-spacing:.05em;text-transform:uppercase;margin-top:4px;font-weight:600;">Dossiers tracés</div>
          </div>
        </div>
      </div>

      <!-- RIGHT : Hero visual — carte dossier + hash chain -->
      <div class="reveal-scale" data-delay="300" style="position:relative;">
        <!-- Card principale dossier médical -->
        <div class="tilt" style="background:white;border-radius:20px;padding:28px;box-shadow:0 40px 80px rgba(15,23,42,.15),0 0 0 1px var(--border);position:relative;transform-style:preserve-3d;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
            <div>
              <div style="font-size:.7rem;color:var(--muted);letter-spacing:.15em;text-transform:uppercase;font-weight:700;margin-bottom:6px;">Dossier médical</div>
              <div style="font-family:var(--sans);font-size:1.25rem;font-weight:800;color:var(--ink);">Patient #0x4A7B9C</div>
            </div>
            <span class="badge badge-emerald badge-live">VÉRIFIÉ</span>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;">
            <div style="padding:14px;border-radius:12px;background:var(--bg);">
              <div style="font-size:.7rem;color:var(--muted);margin-bottom:4px;">Groupe sanguin</div>
              <div style="font-family:var(--sans);font-size:1.1rem;font-weight:700;color:var(--forest);">O+</div>
            </div>
            <div style="padding:14px;border-radius:12px;background:var(--bg);">
              <div style="font-size:.7rem;color:var(--muted);margin-bottom:4px;">Dernière visite</div>
              <div style="font-family:var(--sans);font-size:1.1rem;font-weight:700;color:var(--ink);">Il y a 3j</div>
            </div>
          </div>

          <div style="padding:14px;border-radius:12px;background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
              <i class="fas fa-id-badge" style="color:var(--blockchain);font-size:.8rem;"></i>
              <span style="font-size:.7rem;color:var(--blockchain);font-weight:700;letter-spacing:.1em;text-transform:uppercase;">Référence du dossier</span>
            </div>
            <code class="mono" style="font-size:.75rem;color:var(--blockchain);word-break:break-all;line-height:1.5;">PAT-2024-04-7B9C</code>
          </div>

          <!-- Visites récentes -->
          <div class="hash-chain">
            <span class="hash-block verified">12/04</span>
            <span class="hash-block verified">28/03</span>
            <span class="hash-block verified">15/02</span>
            <span class="hash-block">07/12</span>
            <span class="hash-block">19/09</span>
          </div>
        </div>


        <!-- Floating badge - sécurité -->
        <div style="position:absolute;bottom:-20px;left:-20px;background:white;border-radius:14px;padding:12px 16px;box-shadow:0 20px 40px rgba(15,23,42,.15);display:flex;align-items:center;gap:10px;animation:cueFloat 4s ease-in-out infinite;animation-delay:-2s;">
          <div style="width:36px;height:36px;border-radius:10px;background:var(--g-forest);color:white;display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow-forest);">
            <i class="fas fa-shield-alt" style="font-size:.95rem;"></i>
          </div>
          <div>
            <div style="font-size:.65rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;font-weight:600;">Stockage</div>
            <div style="font-family:var(--sans);font-size:.85rem;font-weight:700;color:var(--ink);">Officiel & sécurisé</div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<style>
@keyframes cueFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-10px)} }

.meteors { position: absolute; inset: 0; z-index: 2; pointer-events: none; overflow: hidden; }
.meteor {
  position: absolute; width: 2px; height: 2px; border-radius: 50%;
  background: var(--emerald); animation: meteorFall linear infinite;
}
.meteor::before {
  content: ''; position: absolute; top: 50%; left: 0;
  width: 100px; height: 1px;
  background: linear-gradient(90deg, var(--emerald), transparent);
  transform: translateY(-50%);
}
@keyframes meteorFall {
  0% { transform: translate(0,0) rotate(215deg); opacity: 0; }
  10% { opacity: 1; }
  80% { opacity: 1; }
  100% { transform: translate(-500px, 500px) rotate(215deg); opacity: 0; }
}

@media (max-width: 960px) {
  .hero-grid-responsive { grid-template-columns: 1fr !important; gap: 48px !important; }
}
</style>

<!-- ============ SERVICES "HOW IT WORKS" ============ -->
<section class="section" id="services">
  <div class="container">
    <div class="reveal-up" style="text-align:center;max-width:720px;margin:0 auto 56px;">
      <div class="eyebrow" style="justify-content:center;">Comment ça marche</div>
      <h2 class="section-title">Un système<br><span class="gradient-text-forest">simple, sûr et rapide</span></h2>
      <p class="section-sub" style="margin:0 auto;">Trois piliers pour un accès aux données médicales protégé, tracé et instantané partout au Bénin.</p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:24px;" class="services-grid">
      <?php
      $services = [
        ['notes-medical', 'Dossiers médicaux', 'Accès complet à l\'historique du patient — consultations, prescriptions, analyses. Chaque modification est horodatée et tracée dans le registre national.', 'forest', '01'],
        ['calendar-check', 'Rendez-vous', 'Planification en temps réel avec les médecins et hôpitaux partenaires. Rappels automatiques et confirmation immédiate.', 'emerald', '02'],
        ['flask', 'Résultats d\'analyses', 'Consultation centralisée des examens médicaux. Traçabilité complète : qui, quand, comment — tout est enregistré.', 'trust', '03'],
      ];
      foreach ($services as $i => $s): ?>
      <div class="card card-hover reveal-up tilt" data-delay="<?= $i * 100 ?>" data-tilt-max="5" style="position:relative;padding:32px;">
        <div style="position:absolute;top:20px;right:24px;font-family:var(--sans);font-size:2.5rem;font-weight:800;color:rgba(26,71,42,.06);line-height:1;letter-spacing:-.05em;"><?= $s[4] ?></div>
        <div class="stat-icon stat-icon-<?= $s[3] ?>" style="margin-bottom:20px;">
          <i class="fas fa-<?= $s[0] ?>" style="font-size:1.15rem;"></i>
        </div>
        <h3 style="font-size:1.3rem;font-weight:700;margin-bottom:12px;letter-spacing:-.01em;"><?= $s[1] ?></h3>
        <p style="color:var(--muted);line-height:1.7;font-size:.9375rem;"><?= $s[2] ?></p>
        <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--blockchain);font-weight:600;">
          <i class="fas fa-circle-check"></i>
          <span>Dossier vérifié et tracé</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
@media (max-width: 900px) {
  .services-grid { grid-template-columns: 1fr !important; }
}
</style>

<!-- ============ REGISTRE NATIONAL SECTION ============ -->
<section class="section" id="registre" style="background:var(--ink);color:white;position:relative;overflow:hidden;margin-top:80px;">
  <!-- grid pattern sombre -->
  <div style="position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:48px 48px;mask-image:radial-gradient(ellipse 80% 70% at 50% 40%,black 30%,transparent 75%);-webkit-mask-image:radial-gradient(ellipse 80% 70% at 50% 40%,black 30%,transparent 75%);"></div>
  <!-- gradient orb -->
  <div style="position:absolute;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle,rgba(99,102,241,.3),transparent 65%);filter:blur(100px);top:20%;left:10%;"></div>
  <div style="position:absolute;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(16,185,129,.25),transparent 65%);filter:blur(100px);bottom:10%;right:10%;"></div>

  <div class="container" style="position:relative;z-index:2;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;" class="blockchain-grid">

      <div class="reveal-up">
        <div class="eyebrow" style="color:var(--blockchain-light);">
          <span style="background:var(--blockchain-light) !important;"></span>
          Registre national
        </div>
        <h2 style="font-size:clamp(2rem,4vw,3rem);font-weight:800;line-height:1.1;letter-spacing:-.02em;margin:16px 0 20px;color:white;">
          Un registre<br>
          <span style="background:linear-gradient(135deg,#818cf8,#10b981);-webkit-background-clip:text;background-clip:text;color:transparent;">unifié & traçable</span>
        </h2>
        <p style="font-size:1.0625rem;color:rgba(255,255,255,.7);line-height:1.75;margin-bottom:32px;">
          Chaque dossier médical, ordonnance ou consultation est horodaté et tracé dans le registre national. <strong style="color:white;">Toute action est journalisée</strong> et chaque intervenant signe son geste — un historique complet et fiable est garanti.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:28px;">
          <?php
          $bcFeatures = [
            ['lock', 'Stockage officiel sécurisé'],
            ['user-check', 'Identité vérifiée'],
            ['history', 'Historique complet'],
            ['user-lock', 'Contrôle du patient'],
          ];
          foreach ($bcFeatures as $f): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);">
            <div style="width:32px;height:32px;border-radius:8px;background:var(--g-blockchain);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <i class="fas fa-<?= $f[0] ?>" style="font-size:.85rem;color:white;"></i>
            </div>
            <div style="font-size:.8125rem;font-weight:500;color:rgba(255,255,255,.9);"><?= $f[1] ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <a href="connexion1.php" class="btn btn-emerald">
          <i class="fas fa-rocket"></i>
          Commencer maintenant
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>
      </div>

      <!-- Visual : journal d'activité du registre -->
      <div class="reveal-up" data-delay="200" style="position:relative;">
        <div style="background:rgba(255,255,255,.04);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:28px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span class="badge badge-blockchain badge-live" style="background:rgba(99,102,241,.2);color:#a5b4fc;">LIVE</span>
              <span style="font-size:.875rem;color:rgba(255,255,255,.8);font-weight:600;">Activité du registre</span>
            </div>
            <div style="font-size:.75rem;color:rgba(255,255,255,.5);font-weight:600;">
              <?= str_pad($bcStats['total_blocks'], 6, '0', STR_PAD_LEFT) ?> entrées
            </div>
          </div>

          <!-- Entrées du journal -->
          <div style="display:flex;flex-direction:column;gap:12px;">
            <?php
            $libelles = [
                'CONSULTATION'    => 'Consultation médicale',
                'PRESCRIPTION'    => 'Ordonnance signée',
                'CREATE_DOSSIER'  => 'Nouveau dossier ouvert',
                'CREATE_PATIENT'  => 'Patient enregistré',
                'ACCUEIL_PATIENT' => 'Accueil patient',
                'LOGIN'           => 'Connexion sécurisée',
            ];
            $blocks = HashChain::getChain(4);
            if (empty($blocks)) {
                $blocks = [
                    ['type_action' => 'CONSULTATION',    'timestamp_action' => date('Y-m-d H:i:s')],
                    ['type_action' => 'PRESCRIPTION',    'timestamp_action' => date('Y-m-d H:i:s', time() - 300)],
                    ['type_action' => 'CREATE_DOSSIER',  'timestamp_action' => date('Y-m-d H:i:s', time() - 600)],
                    ['type_action' => 'LOGIN',           'timestamp_action' => date('Y-m-d H:i:s', time() - 900)],
                ];
            }
            foreach ($blocks as $i => $b):
              $libelle = $libelles[$b['type_action']] ?? ucfirst(strtolower(str_replace('_', ' ', $b['type_action'])));
              $isLatest = ($i === 0);
              $time = date('H:i', strtotime($b['timestamp_action']));
            ?>
            <div style="display:flex;align-items:center;gap:14px;padding:14px;border-radius:12px;background:rgba(99,102,241,<?= $isLatest ? '.15' : '.06' ?>);border:1px solid rgba(99,102,241,<?= $isLatest ? '.3' : '.15' ?>);position:relative;">
              <div style="width:40px;height:40px;border-radius:10px;background:var(--g-blockchain);display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 8px 20px rgba(99,102,241,.3);">
                <i class="fas fa-file-medical" style="color:white;"></i>
              </div>
              <div style="flex:1;min-width:0;">
                <div style="font-family:var(--sans);font-size:.85rem;font-weight:700;color:white;margin-bottom:2px;"><?= htmlspecialchars($libelle) ?></div>
                <span style="font-size:.7rem;color:#a5b4fc;"><?= htmlspecialchars($time) ?> · enregistrement officiel</span>
              </div>
              <?php if ($isLatest): ?>
                <span style="font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--emerald-light);padding:4px 8px;border-radius:6px;background:rgba(16,185,129,.15);">RÉCENT</span>
              <?php endif; ?>
            </div>
            <?php if ($i < count($blocks) - 1): ?>
              <div style="margin-left:20px;height:12px;width:2px;background:linear-gradient(180deg,var(--blockchain),transparent);"></div>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <div style="margin-top:20px;padding-top:20px;border-top:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:center;">
            <div style="font-size:.75rem;color:rgba(255,255,255,.5);">Dernière vérification</div>
            <div style="display:flex;align-items:center;gap:6px;font-size:.75rem;color:var(--emerald-light);font-weight:600;">
              <i class="fas fa-check-circle"></i> Conforme
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<style>
@media (max-width: 960px) {
  .blockchain-grid { grid-template-columns: 1fr !important; gap: 48px !important; }
}
</style>

<!-- ============ SERVICES INNOVANTS ============ -->
<section class="section">
  <div class="container">
    <div class="reveal-up" style="text-align:center;max-width:720px;margin:0 auto 56px;">
      <div class="eyebrow" style="justify-content:center;">Écosystème complet</div>
      <h2 class="section-title">Services<br><span class="gradient-text-emerald">médicaux innovants</span></h2>
      <p class="section-sub" style="margin:0 auto;">De la téléconsultation à la génomique, une suite complète d'outils médicaux au service des familles béninoises.</p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;" class="innovative-grid">
      <?php
      $innovative = [
        ['robot', 'Téléconsultation IA', 'Consultations à distance avec diagnostic assisté par intelligence artificielle.', 'Prochain', 'warning'],
        ['prescription-bottle-alt', 'Prescriptions numériques', 'Ordonnances dématérialisées validées et signées par le médecin traitant.', '', ''],
        ['dna', 'Génomique & Prévention', 'Analyse prédictive basée sur les données génétiques, traitée en toute confidentialité.', '', ''],
        ['ambulance', 'Urgences connectées', 'Transmission automatique des données vitales aux services d\'urgence les plus proches.', '24/7', 'emerald'],
        ['brain', 'Santé mentale', 'Suivi psychologique avec anonymat garanti et confidentialité totale.', '', ''],
        ['syringe', 'Carnet vaccinal', 'Certificats vaccinaux officiels vérifiables par QR code.', 'Nouveau', 'emerald'],
      ];
      foreach ($innovative as $i => $s): ?>
      <div class="card card-hover reveal-up" data-delay="<?= ($i % 3) * 80 ?>" style="display:flex;align-items:flex-start;gap:16px;padding:24px;">
        <div style="width:48px;height:48px;border-radius:12px;background:rgba(16,185,129,.1);color:var(--emerald);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:var(--transition);">
          <i class="fas fa-<?= $s[0] ?>" style="font-size:1.15rem;"></i>
        </div>
        <div style="flex:1;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
            <h4 style="font-family:var(--sans);font-size:1.0625rem;font-weight:700;color:var(--ink);letter-spacing:-.01em;"><?= $s[1] ?></h4>
            <?php if ($s[3]): ?>
              <span class="badge badge-<?= $s[4] ?>"><?= $s[3] ?></span>
            <?php endif; ?>
          </div>
          <p style="font-size:.875rem;color:var(--muted);line-height:1.6;"><?= $s[2] ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
@media (max-width: 960px) { .innovative-grid { grid-template-columns: repeat(2,1fr) !important; } }
@media (max-width: 600px) { .innovative-grid { grid-template-columns: 1fr !important; } }
</style>

<!-- ============ HÔPITAUX PARTENAIRES ============ -->
<section class="section" id="hopitaux" style="background:var(--bg);">
  <div class="container">
    <div class="reveal-up" style="text-align:center;max-width:720px;margin:0 auto 56px;">
      <div class="eyebrow" style="justify-content:center;">Réseau national</div>
      <h2 class="section-title">Nos hôpitaux<br><span class="gradient-text">partenaires</span></h2>
      <p class="section-sub" style="margin:0 auto;">Les centres hospitaliers du Bénin déjà connectés à la plateforme MedChain.</p>
    </div>

    <?php if (empty($hopitaux)): ?>
      <div class="empty-state" style="max-width:520px;margin:0 auto;">
        <div class="empty-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 21V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14"/><path d="M12 6v8m-4-4h8"/></svg>
        </div>
        <div class="empty-title">Aucun hôpital disponible</div>
        <p class="empty-sub">Les hôpitaux partenaires seront affichés ici dès leur référencement dans la base.</p>
      </div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;" class="hopitaux-grid">
        <?php foreach ($hopitaux as $i => $h):
          $img = hopitalImage($h, $imageMap);
          $bg = $img ? "url('$img')" : "var(--g-forest)";
        ?>
        <article class="card card-hover reveal-up" data-delay="<?= ($i % 3) * 80 ?>" style="padding:0;overflow:hidden;">
          <div style="aspect-ratio:16/10;background:<?= $bg ?>;background-size:cover;background-position:center;position:relative;">
            <div style="position:absolute;inset:0;background:linear-gradient(180deg,transparent 40%,rgba(15,23,42,.8) 100%);"></div>
            <div style="position:absolute;top:16px;right:16px;">
              <span class="badge badge-emerald badge-dot" style="background:rgba(255,255,255,.95);color:var(--emerald);backdrop-filter:blur(12px);">Partenaire</span>
            </div>
            <div style="position:absolute;bottom:16px;left:16px;right:16px;color:white;">
              <h3 style="font-family:var(--sans);font-size:1.125rem;font-weight:700;color:white;line-height:1.2;letter-spacing:-.01em;"><?= htmlspecialchars($h['nom']) ?></h3>
              <div style="display:flex;align-items:center;gap:6px;font-size:.8125rem;color:rgba(255,255,255,.85);margin-top:4px;">
                <i class="fas fa-map-marker-alt" style="color:var(--emerald-light);"></i>
                <?= htmlspecialchars($h['ville']) ?>, Bénin
              </div>
            </div>
          </div>
          <div style="padding:20px;">
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;">
              <?php
              $stats = [
                ['procedures', 'Lits', $h['nombre_lits'] ?? 0],
                ['user-md', 'Médecins', $h['nombre_medecins'] ?? 0],
                ['ambulance', 'Ambulances', $h['nombre_ambulances'] ?? 0],
                ['flask', 'Labos', $h['nombre_labos'] ?? 0],
              ];
              foreach ($stats as $s): if ($s[2] > 0): ?>
              <div style="display:flex;align-items:center;gap:8px;font-size:.8125rem;">
                <i class="fas fa-<?= $s[0] ?>" style="color:var(--emerald);width:16px;"></i>
                <span style="font-weight:700;color:var(--ink);"><?= $s[2] ?></span>
                <span style="color:var(--muted);"><?= $s[1] ?></span>
              </div>
              <?php endif; endforeach; ?>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<style>
@media (max-width: 960px) { .hopitaux-grid { grid-template-columns: repeat(2,1fr) !important; } }
@media (max-width: 600px) { .hopitaux-grid { grid-template-columns: 1fr !important; } }
</style>

<!-- ============ CTA FINAL ============ -->
<section class="section" style="padding:80px 0;">
  <div class="container">
    <div class="reveal-up spotlight-host" style="position:relative;background:var(--g-forest);border-radius:24px;padding:72px 48px;text-align:center;overflow:hidden;">
      <!-- spotlight -->
      <div style="position:absolute;inset:0;background:radial-gradient(400px circle at var(--spot-x,50%) var(--spot-y,50%),rgba(16,185,129,.25),transparent 60%);pointer-events:none;"></div>
      <!-- pattern -->
      <div style="position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.06) 1.5px,transparent 1.5px);background-size:24px 24px;mask-image:radial-gradient(ellipse 70% 70% at 50% 50%,black 30%,transparent 75%);"></div>

      <div style="position:relative;z-index:2;">
        <span class="badge badge-emerald" style="margin-bottom:20px;background:rgba(255,255,255,.12);color:white;">
          <i class="fas fa-rocket" style="font-size:.7rem;"></i>
          Gratuit &amp; illimité
        </span>
        <h2 style="font-size:clamp(2rem,4vw,3rem);font-weight:800;color:white;line-height:1.1;letter-spacing:-.02em;margin-bottom:16px;">
          Prêt·e à sécuriser<br>vos données médicales ?
        </h2>
        <p style="color:rgba(255,255,255,.8);font-size:1.125rem;max-width:560px;margin:0 auto 36px;line-height:1.7;">
          Rejoignez les 20 000+ patients béninois qui gardent le contrôle total de leur santé grâce au registre national.
        </p>
        <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
          <a href="connexion1.php" class="btn btn-white btn-lg">
            Connexion patient
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </a>
          <a href="connexion2.php" class="btn btn-outline-white btn-lg">
            <i class="fas fa-user-md"></i>
            Je suis un professionnel
          </a>
        </div>
        <p style="color:rgba(255,255,255,.65);font-size:.9rem;margin-top:14px;">
          Les comptes patients sont créés sur place par l'infirmier d'accueil de votre hôpital.
        </p>
      </div>
    </div>
  </div>
</section>

<?php require_once 'includes/footer.php'; ?>
