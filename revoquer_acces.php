<?php
// revoquer_acces.php — Endpoint de révocation rapide (POST redirect pattern)
// Utilise la table 'acces_patients'.
require_once 'config.php';
require_once 'includes/hash_chain.php';

$userType = $_SESSION['user_type'] ?? '';
$userId = 0;

if      ($userType === 'patient') $userId = (int)($_SESSION['user_id']  ?? 0);
elseif  ($userType === 'admin')   $userId = (int)($_SESSION['admin_id'] ?? 0);
else { header('Location: connexion1.php'); exit; }

if (!$userId) { header('Location: connexion1.php'); exit; }

$accesId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$accesId) { header('Location: gestion_acces.php'); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT a.id, a.patient_id, a.entite_id AS docteur_id, a.actif,
               a.date_debut AS date_accordee,
               a.date_fin   AS date_expiration,
               p.prenom AS p_prenom, p.nom AS p_nom,
               d.prenom AS d_prenom, d.nom AS d_nom, d.specialite,
               d.identifiant_blockchain AS d_chain
        FROM acces_patients a
        JOIN patients p ON p.id = a.patient_id
        JOIN docteurs d ON d.id = a.entite_id AND a.type_entite = 'docteur'
        WHERE a.id = ?
    ");
    $stmt->execute([$accesId]);
    $acces = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('revoquer_acces fetch: ' . $e->getMessage());
    $acces = null;
}

if (!$acces) {
    header('Location: gestion_acces.php');
    exit;
}

$canRevoke = $userType === 'admin'
          || ($userType === 'patient' && (int)$acces['patient_id'] === $userId);
if (!$canRevoke) { header('Location: gestion_acces.php'); exit; }

$done = false; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    if (!csrf_check()) {
        $error = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE acces_patients SET actif = 0, date_fin = NOW() WHERE id = ?");
            $stmt->execute([$accesId]);

            HashChain::addBlock('REVOKE_ACCESS', $accesId, $userId, $userType, [
                'patient_id' => $acces['patient_id'],
                'docteur_id' => $acces['docteur_id'],
                'revoked_at' => date('c'),
            ], 'acces_patients');

            $done = true;
        } catch (PDOException $e) {
            error_log('revoquer_acces update: ' . $e->getMessage());
            $error = "Erreur lors de la révocation.";
        }
    }
}

$pageTitle = 'Révoquer un accès';
$pageActive = 'acces';
$breadcrumb = ['Accès', 'Révoquer'];
require_once 'includes/header_dashboard.php';
?>

<section class="revoke-wrap reveal-up">
  <?php if ($done): ?>
    <!-- CONFIRMATION DE RÉVOCATION -->
    <div class="revoke-card revoke-success">
      <div class="revoke-icon success">
        <i class="fas fa-check"></i>
      </div>
      <h2>Accès révoqué</h2>
      <p>
        Dr. <?= htmlspecialchars($acces['d_prenom'] . ' ' . $acces['d_nom']) ?>
        ne peut plus accéder à ce dossier.
        <br><strong>Un nouveau bloc a été ajouté à la chaîne.</strong>
      </p>
      <div class="revoke-blockchain-proof">
        <i class="fas fa-cube"></i>
        <span>Action signée et inscrite dans la blockchain à <?= date('H:i:s') ?></span>
      </div>
      <div class="revoke-actions">
        <a href="gestion_acces.php" class="btn btn-primary">
          <i class="fas fa-arrow-left"></i> Retour aux accès
        </a>
        <a href="logs.php" class="btn btn-outline">
          <i class="fas fa-link"></i> Voir dans le journal
        </a>
      </div>
    </div>
  <?php elseif ($error): ?>
    <div class="revoke-card revoke-error">
      <div class="revoke-icon error"><i class="fas fa-triangle-exclamation"></i></div>
      <h2>Erreur</h2>
      <p><?= htmlspecialchars($error) ?></p>
      <a href="gestion_acces.php" class="btn btn-outline">Retour</a>
    </div>
  <?php else: ?>
    <!-- CONFIRMATION AVANT RÉVOCATION -->
    <div class="revoke-card">
      <div class="revoke-icon warn">
        <i class="fas fa-ban"></i>
      </div>
      <h2>Confirmer la révocation</h2>
      <p>Cette action est <strong>immédiate</strong> et <strong>tracée dans la blockchain</strong>.</p>

      <div class="revoke-details">
        <div class="revoke-party">
          <div class="party-avatar-lg forest">
            <?= strtoupper(substr($acces['d_prenom'], 0, 1) . substr($acces['d_nom'], 0, 1)) ?>
          </div>
          <div>
            <strong>Dr. <?= htmlspecialchars($acces['d_prenom'] . ' ' . $acces['d_nom']) ?></strong>
            <span><?= htmlspecialchars($acces['specialite'] ?: 'Docteur') ?></span>
          </div>
        </div>

        <div class="revoke-info-grid">
          <div>
            <span>Accordé le</span>
            <strong><?= date('d/m/Y · H:i', strtotime($acces['date_accordee'])) ?></strong>
          </div>
          <?php if (!empty($acces['date_expiration'])): ?>
          <div>
            <span>Expiration prévue</span>
            <strong><?= date('d/m/Y', strtotime($acces['date_expiration'])) ?></strong>
          </div>
          <?php endif; ?>
          <?php if (!empty($acces['d_chain'])): ?>
          <div>
            <span>Blockchain ID</span>
            <code><?= htmlspecialchars(HashChain::shortHash($acces['d_chain'], 6, 4)) ?></code>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" class="revoke-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$acces['id'] ?>">
        <a href="gestion_acces.php" class="btn btn-outline">
          <i class="fas fa-arrow-left"></i> Annuler
        </a>
        <button type="submit" name="confirm" value="1" class="btn btn-danger">
          <i class="fas fa-ban"></i> Confirmer la révocation
        </button>
      </form>
    </div>
  <?php endif; ?>
</section>

<style>
  .revoke-wrap {
    max-width: 560px; margin: 20px auto;
  }
  .revoke-card {
    background: white; border: 1px solid var(--line);
    border-radius: 20px; padding: 40px 32px;
    text-align: center;
    box-shadow: 0 20px 40px -16px rgba(15,23,42,.08);
  }
  .revoke-icon {
    width: 88px; height: 88px; border-radius: 50%;
    display: grid; place-items: center;
    color: white; font-size: 36px;
    margin: 0 auto 20px;
  }
  .revoke-icon.warn { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 12px 32px -8px rgba(245,158,11,.5); }
  .revoke-icon.success { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 12px 32px -8px rgba(16,185,129,.5); }
  .revoke-icon.error { background: linear-gradient(135deg, #ef4444, #dc2626); box-shadow: 0 12px 32px -8px rgba(239,68,68,.5); }

  .revoke-card h2 {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 24px; font-weight: 800; color: var(--ink); margin-bottom: 10px;
  }
  .revoke-card > p {
    font-size: 14px; color: var(--muted); margin-bottom: 24px; line-height: 1.6;
  }
  .revoke-card > p strong { color: var(--ink); }

  .revoke-details {
    padding: 20px; background: #f8fafc;
    border: 1px solid var(--line); border-radius: 14px;
    margin-bottom: 24px;
  }
  .revoke-party {
    display: flex; align-items: center; gap: 14px;
    padding-bottom: 16px; margin-bottom: 16px;
    border-bottom: 1px dashed var(--line);
  }
  .party-avatar-lg {
    width: 56px; height: 56px; border-radius: 14px;
    display: grid; place-items: center;
    color: white; font-size: 16px; font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif;
  }
  .party-avatar-lg.forest { background: var(--g-forest); }
  .revoke-party > div strong {
    display: block; font-size: 16px; font-weight: 700; color: var(--ink); text-align: left;
  }
  .revoke-party > div span { font-size: 12px; color: var(--muted); }

  .revoke-info-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
  }
  .revoke-info-grid > div { display: flex; flex-direction: column; gap: 2px; text-align: left; }
  .revoke-info-grid span {
    font-size: 10px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .04em;
  }
  .revoke-info-grid strong { font-size: 13px; color: var(--ink); font-weight: 600; }
  .revoke-info-grid code {
    font-family: 'JetBrains Mono', monospace; font-size: 11px;
    color: var(--blockchain); align-self: flex-start;
    padding: 2px 8px; background: rgba(99,102,241,.08); border-radius: 6px;
  }

  .revoke-blockchain-proof {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 10px 16px;
    background: rgba(99,102,241,.08); border: 1px solid rgba(99,102,241,.2);
    border-radius: 999px;
    font-size: 12px; color: var(--blockchain); font-family: 'JetBrains Mono', monospace;
    margin-bottom: 20px;
  }
  .revoke-blockchain-proof i { font-size: 14px; }

  .revoke-actions {
    display: flex; justify-content: center; gap: 10px; flex-wrap: wrap;
  }

  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 11px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 700;
    text-decoration: none; border: none; cursor: pointer; transition: .3s;
    font-family: inherit;
  }
  .btn-primary { background: var(--g-forest); color: white; }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -4px rgba(26,71,42,.4); }
  .btn-outline { background: white; border: 1px solid var(--line); color: var(--ink); }
  .btn-outline:hover { border-color: var(--forest); color: var(--forest); }
  .btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
  .btn-danger:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -4px rgba(239,68,68,.4); }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
