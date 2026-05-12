<?php
// gestion_acces.php — Gestion des accès aux dossiers (admin + patient)
// Utilise la table 'acces_patients' (et non 'acces_dossiers' qui n'existe pas).
require_once 'config.php';
require_once 'includes/hash_chain.php';

$userType = $_SESSION['user_type'] ?? '';
$userId = 0;

if      ($userType === 'admin')   $userId = (int)($_SESSION['admin_id'] ?? 0);
elseif  ($userType === 'patient') $userId = (int)($_SESSION['user_id']  ?? 0);
else { header('Location: connexion1.php'); exit; }

if (!$userId) { header('Location: connexion1.php'); exit; }

// Helper : SELECT commun qui aliase les colonnes acces_patients vers les noms
// utilisés dans la vue (date_accordee, date_expiration, statut, date_revocation).
$selectAcces = "
    SELECT a.id, a.patient_id, a.entite_id AS docteur_id, a.type_acces, a.actif,
           a.date_debut AS date_accordee,
           a.date_fin   AS date_expiration,
           CASE
             WHEN a.actif = 1 AND (a.date_fin IS NULL OR a.date_fin > NOW()) THEN 'actif'
             WHEN a.actif = 0 THEN 'revoque'
             WHEN a.date_fin IS NOT NULL AND a.date_fin <= NOW() THEN 'expire'
             ELSE 'inactif'
           END AS statut,
           CASE WHEN a.actif = 0 THEN a.date_fin ELSE NULL END AS date_revocation,
           p.prenom AS p_prenom, p.nom AS p_nom, p.identifiant_blockchain AS p_chain,
           d.prenom AS d_prenom, d.nom AS d_nom, d.specialite,
           d.email AS d_email, d.identifiant_blockchain AS d_chain
    FROM acces_patients a
    JOIN patients p ON p.id = a.patient_id
    JOIN docteurs d ON d.id = a.entite_id AND a.type_entite = 'docteur'
";

$success = ''; $error = '';

// ─── Traitement POST : accorder ou révoquer ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'grant' && $userType === 'patient') {
                $docteurId = (int)($_POST['docteur_id'] ?? 0);
                $duree     = (int)($_POST['duree']      ?? 30);
                if ($docteurId > 0 && $duree > 0) {
                    $expire = date('Y-m-d H:i:s', strtotime("+$duree days"));

                    $stmt = $pdo->prepare("
                        INSERT INTO acces_patients
                            (patient_id, entite_id, type_entite, type_acces,
                             date_debut, date_fin, actif, accorde_par, motif)
                        VALUES (?, ?, 'docteur', 'lecture',
                                NOW(), ?, 1, ?, 'Accordé par le patient')
                        ON DUPLICATE KEY UPDATE
                            actif      = 1,
                            date_debut = NOW(),
                            date_fin   = VALUES(date_fin)
                    ");
                    $stmt->execute([$userId, $docteurId, $expire, $userId]);
                    $accesId = $pdo->lastInsertId() ?: 0;

                    HashChain::addBlock('GRANT_ACCESS', $accesId, $userId, 'patient', [
                        'docteur_id'  => $docteurId,
                        'duree_jours' => $duree,
                        'expire_le'   => $expire,
                    ], 'acces_patients');

                    $success = "Accès accordé pour $duree jours. Un bloc a été ajouté à la chaîne.";
                }
            } elseif ($action === 'revoke') {
                $accesId = (int)($_POST['acces_id'] ?? 0);
                if ($accesId > 0) {
                    $check = $pdo->prepare("SELECT * FROM acces_patients WHERE id = ?");
                    $check->execute([$accesId]);
                    $acces = $check->fetch(PDO::FETCH_ASSOC);

                    $canRevoke = $userType === 'admin'
                              || ($userType === 'patient' && (int)$acces['patient_id'] === $userId);

                    if ($acces && $canRevoke) {
                        $stmt = $pdo->prepare("
                            UPDATE acces_patients
                               SET actif = 0,
                                   date_fin = NOW()
                             WHERE id = ?
                        ");
                        $stmt->execute([$accesId]);

                        HashChain::addBlock('REVOKE_ACCESS', $accesId, $userId, $userType, [
                            'patient_id' => $acces['patient_id'],
                            'docteur_id' => $acces['entite_id'],
                        ], 'acces_patients');

                        $success = 'Accès révoqué. Le docteur ne peut plus consulter le dossier.';
                    } else {
                        $error = 'Action non autorisée.';
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('gestion_acces error: ' . $e->getMessage());
            $error = "Erreur lors de l'enregistrement.";
        }
    }
}

// ─── Liste des accès ───────────────────────────────────────────────
$acces = [];
try {
    if ($userType === 'patient') {
        $stmt = $pdo->prepare($selectAcces . " WHERE a.patient_id = ? ORDER BY a.date_debut DESC");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->query($selectAcces . " ORDER BY a.date_debut DESC LIMIT 50");
    }
    $acces = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('gestion_acces list: ' . $e->getMessage());
    $acces = [];
}

// ─── Liste docteurs (pour formulaire patient) ──────────────────────
$docteurs = [];
if ($userType === 'patient') {
    try {
        $stmt = $pdo->query("
            SELECT id, prenom, nom, specialite, identifiant_blockchain
            FROM docteurs WHERE statut='actif' ORDER BY nom
        ");
        $docteurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $docteurs = []; }
}

$pageTitle = 'Gestion des accès';
$pageActive = 'acces';
$breadcrumb = [$userType === 'admin' ? 'Admin' : 'Patient', 'Accès'];
require_once 'includes/header_dashboard.php';
?>

<section class="dash-hero dash-hero-compact reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span> Contrôle total
    </div>
    <h1>Vous décidez <span>qui voit vos données.</span></h1>
    <p>
      <strong>Chaque accès</strong> est un contrat temporaire signé dans la blockchain.
      Révocable à tout moment, traçable à la seconde.
    </p>
  </div>
  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Principe zéro-confiance</span>
      <i class="fas fa-shield-halved"></i>
    </div>
    <ul class="security-notes">
      <li><i class="fas fa-check"></i> Aucun docteur n'accède sans votre consentement</li>
      <li><i class="fas fa-check"></i> Durée limitée (vous choisissez)</li>
      <li><i class="fas fa-check"></i> Révocation instantanée</li>
      <li><i class="fas fa-check"></i> Historique immuable</li>
    </ul>
  </div>
</section>

<?php if ($success): ?>
  <div class="alert alert-success reveal-up"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-error reveal-up"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($userType === 'patient' && !empty($docteurs)): ?>
<!-- ACCORDER ACCÈS -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-user-plus" style="color:var(--forest)"></i> Accorder un nouvel accès</h3>
      <p>Sélectionnez un docteur et une durée</p>
    </div>
  </div>

  <form method="post" class="grant-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="grant">
    <div class="field">
      <label>Docteur</label>
      <select name="docteur_id" required>
        <option value="">— Sélectionner un docteur —</option>
        <?php foreach ($docteurs as $d): ?>
          <option value="<?= $d['id'] ?>">
            Dr. <?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?>
            <?= $d['specialite'] ? ' · ' . htmlspecialchars($d['specialite']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Durée d'accès</label>
      <select name="duree">
        <option value="7">7 jours</option>
        <option value="30" selected>30 jours</option>
        <option value="90">90 jours</option>
        <option value="365">1 an</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">
      <i class="fas fa-link"></i> Signer dans la blockchain
    </button>
  </form>
</section>
<?php endif; ?>

<!-- LISTE DES ACCÈS -->
<section class="dash-card reveal-up">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-key" style="color:var(--blockchain)"></i>
        <?= count($acces) ?> accès<?= count($acces) > 1 ? '' : '' ?> enregistré<?= count($acces) > 1 ? 's' : '' ?>
      </h3>
      <p>Triés par date (plus récent d'abord)</p>
    </div>
  </div>

  <?php if (empty($acces)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-key"></i></div>
      <h4>Aucun accès accordé</h4>
      <p>Aucun docteur n'a encore été autorisé à consulter ce dossier.</p>
    </div>
  <?php else: ?>
    <div class="acces-list">
      <?php foreach ($acces as $a):
        $expired = !empty($a['date_expiration']) && strtotime($a['date_expiration']) < time();
        $statut = $a['statut'] ?? 'actif';
        $isActive = $statut === 'actif' && !$expired;
        $displayStatus = $expired ? 'expire' : $statut;

        $statutColors = [
          'actif'    => 'success',
          'revoque'  => 'danger',
          'expire'   => 'warn',
          'suspendu' => 'neutral',
        ];
        $statutColor = $statutColors[$displayStatus] ?? 'neutral';
      ?>
        <div class="acces-card <?= $isActive ? 'acces-active' : 'acces-inactive' ?>">
          <div class="acces-card-head">
            <div class="acces-parties">
              <?php if ($userType === 'admin'): ?>
                <div class="party">
                  <div class="party-avatar emerald">
                    <?= strtoupper(substr($a['p_prenom'], 0, 1) . substr($a['p_nom'], 0, 1)) ?>
                  </div>
                  <div class="party-body">
                    <strong><?= htmlspecialchars($a['p_prenom'] . ' ' . $a['p_nom']) ?></strong>
                    <span>Patient</span>
                  </div>
                </div>
                <div class="party-arrow"><i class="fas fa-arrow-right"></i></div>
              <?php endif; ?>
              <div class="party">
                <div class="party-avatar forest">
                  <?= strtoupper(substr($a['d_prenom'], 0, 1) . substr($a['d_nom'], 0, 1)) ?>
                </div>
                <div class="party-body">
                  <strong>Dr. <?= htmlspecialchars($a['d_prenom'] . ' ' . $a['d_nom']) ?></strong>
                  <span><?= htmlspecialchars($a['specialite'] ?: 'Docteur') ?></span>
                </div>
              </div>
            </div>
            <span class="badge badge-<?= $statutColor ?>">
              <?= $displayStatus === 'expire' ? 'Expiré' : ucfirst($displayStatus) ?>
            </span>
          </div>

          <div class="acces-card-body">
            <div class="acces-meta">
              <div>
                <span>Accordé le</span>
                <strong><?= date('d/m/Y · H:i', strtotime($a['date_accordee'])) ?></strong>
              </div>
              <?php if (!empty($a['date_expiration'])): ?>
              <div>
                <span><?= $expired ? 'Expiré le' : 'Expire le' ?></span>
                <strong><?= date('d/m/Y', strtotime($a['date_expiration'])) ?></strong>
              </div>
              <?php endif; ?>
              <?php if (!empty($a['date_revocation'])): ?>
              <div>
                <span>Révoqué le</span>
                <strong><?= date('d/m/Y · H:i', strtotime($a['date_revocation'])) ?></strong>
              </div>
              <?php endif; ?>
              <?php if (!empty($a['d_chain'])): ?>
              <div>
                <span>Blockchain ID</span>
                <code title="<?= htmlspecialchars($a['d_chain']) ?>" data-copy="<?= htmlspecialchars($a['d_chain']) ?>">
                  <?= htmlspecialchars(HashChain::shortHash($a['d_chain'], 6, 4)) ?>
                </code>
              </div>
              <?php endif; ?>
            </div>

            <?php if ($isActive): ?>
              <form method="post" class="revoke-form" onsubmit="return confirm('Révoquer cet accès ? L\'action sera inscrite dans la blockchain.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="acces_id" value="<?= $a['id'] ?>">
                <button type="submit" class="btn-revoke">
                  <i class="fas fa-ban"></i> Révoquer
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<style>
  .dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #1a472a 0%, #059669 50%, #0369a1 100%);
    color: white; border-radius: 24px; padding: 28px 36px;
    display: grid; grid-template-columns: 1.4fr 1fr; gap: 28px;
    align-items: center; margin-bottom: 24px;
  }
  .dash-hero::before {
    content: ''; position: absolute; top: -140px; right: -140px;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(16,185,129,.3), transparent 60%);
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
    background: #86efac; box-shadow: 0 0 12px #86efac;
    animation: pulse 2s infinite;
  }
  .dash-hero h1 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 30px; font-weight: 800; line-height: 1.15;
  }
  .dash-hero h1 span {
    background: linear-gradient(135deg, #86efac, #7dd3fc);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .dash-hero p { font-size: 14px; color: rgba(255,255,255,.8); margin-top: 10px; }
  .dash-hero p strong { color: #86efac; }

  .dash-hero-card {
    position: relative; z-index: 1;
    padding: 18px 20px; background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(16px);
    border-radius: 14px;
  }
  .dash-hero-card-head {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;
  }
  .dash-hero-card-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    color: rgba(255,255,255,.6);
  }
  .dash-hero-card-head i { color: #86efac; }
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

  .dash-card {
    background: white; border: 1px solid var(--line);
    border-radius: 20px; padding: 24px 28px; margin-bottom: 20px;
  }
  .dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .dash-card-head h3 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 17px; font-weight: 700;
    display: flex; align-items: center; gap: 10px;
  }
  .dash-card-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }

  /* Grant form */
  .grant-form {
    display: grid; grid-template-columns: 2fr 1fr auto;
    gap: 12px; align-items: flex-end;
  }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field label {
    font-size: 11px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .04em;
  }
  .field select {
    padding: 11px 14px; border: 1px solid var(--line);
    border-radius: 10px; background: white;
    font-size: 13px; color: var(--ink); font-family: inherit;
  }
  .field select:focus {
    outline: none; border-color: var(--forest);
    box-shadow: 0 0 0 3px rgba(26,71,42,.1);
  }

  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 11px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 700; font-family: inherit;
    text-decoration: none; border: none; cursor: pointer; transition: .3s;
  }
  .btn-primary { background: var(--g-forest); color: white; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -4px rgba(26,71,42,.4); }

  /* Acces list */
  .acces-list { display: flex; flex-direction: column; gap: 12px; }
  .acces-card {
    padding: 18px 20px; background: #f8fafc;
    border: 1px solid var(--line); border-radius: 14px;
    border-left: 3px solid var(--forest);
    transition: .25s;
  }
  .acces-card:hover { background: white; box-shadow: 0 8px 20px -8px rgba(15,23,42,.1); }
  .acces-card.acces-inactive { border-left-color: #cbd5e1; opacity: .75; }

  .acces-card-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; flex-wrap: wrap; gap: 10px;
  }
  .acces-parties { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
  .party { display: flex; align-items: center; gap: 10px; }
  .party-avatar {
    width: 40px; height: 40px; border-radius: 10px;
    display: grid; place-items: center;
    color: white; font-size: 12px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif;
  }
  .party-avatar.forest { background: var(--g-forest); }
  .party-avatar.emerald { background: var(--g-emerald); }
  .party-body strong { display: block; font-size: 14px; font-weight: 700; color: var(--ink); }
  .party-body span { font-size: 11px; color: var(--muted); }
  .party-arrow {
    color: var(--muted); font-size: 14px;
  }

  .acces-card-body {
    display: flex; justify-content: space-between; align-items: flex-end;
    gap: 16px; flex-wrap: wrap;
  }
  .acces-meta {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px; flex: 1;
  }
  .acces-meta > div { display: flex; flex-direction: column; gap: 2px; }
  .acces-meta span {
    font-size: 10px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .04em;
  }
  .acces-meta strong {
    font-size: 13px; color: var(--ink); font-weight: 600;
  }
  .acces-meta code {
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px; color: var(--blockchain);
    padding: 2px 8px; background: rgba(99,102,241,.08);
    border-radius: 6px; cursor: pointer; align-self: flex-start;
  }
  .acces-meta code:hover { background: rgba(99,102,241,.18); }

  .revoke-form { flex-shrink: 0; }
  .btn-revoke {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px; background: white;
    border: 1px solid rgba(239,68,68,.3);
    color: #dc2626; border-radius: 10px;
    font-size: 12px; font-weight: 700; font-family: inherit;
    cursor: pointer; transition: .3s;
  }
  .btn-revoke:hover {
    background: #fee2e2; border-color: #ef4444;
    transform: translateY(-1px);
  }

  /* Badges */
  .badge {
    display: inline-block; padding: 4px 12px; border-radius: 999px;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
  }
  .badge-success { background: #d1fae5; color: #065f46; }
  .badge-warn    { background: #fef3c7; color: #92400e; }
  .badge-danger  { background: #fee2e2; color: #991b1b; }
  .badge-neutral { background: #f1f5f9; color: #475569; }

  .empty { text-align: center; padding: 40px 20px; }
  .empty-icon {
    width: 72px; height: 72px; margin: 0 auto 16px;
    background: rgba(26,71,42,.08); border-radius: 50%;
    display: grid; place-items: center; color: var(--forest); font-size: 28px;
  }
  .empty h4 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
  .empty p { font-size: 13px; color: var(--muted); }

  @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }

  @media (max-width: 900px) {
    .dash-hero { grid-template-columns: 1fr; }
    .grant-form { grid-template-columns: 1fr; }
    .acces-card-head, .acces-card-body { flex-direction: column; align-items: flex-start; }
  }
</style>

<script>
  document.querySelectorAll('[data-copy]').forEach(el => {
    el.addEventListener('click', () => {
      const t = el.getAttribute('data-copy'); if (!t) return;
      navigator.clipboard.writeText(t).then(() => {
        const orig = el.style.background; el.style.background = '#10b981'; el.style.color = 'white';
        setTimeout(() => { el.style.background = orig; el.style.color = ''; }, 600);
      });
    });
  });
</script>

<?php require_once 'includes/footer_dashboard.php'; ?>
