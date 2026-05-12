<?php
// transferer_patient.php — Demande et suivi des transferts inter-hôpitaux.
// Accessible aux patients (leurs propres transferts), infirmiers et docteurs.
require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_medecin_traitant_column($pdo);

$userType = $_SESSION['user_type'] ?? '';
$userId   = 0;

if ($userType === 'patient') {
    $userId = (int)($_SESSION['user_id'] ?? 0);
} elseif (in_array($userType, ['infirmier', 'docteur', 'medecin'])) {
    $userId = (int)($_SESSION['medecin_id'] ?? 0);
} elseif ($userType === 'admin') {
    $userId = (int)($_SESSION['admin_id'] ?? 0);
} else {
    header('Location: connexion1.php'); exit;
}

if (!$userId) { header('Location: connexion1.php'); exit; }

$success = '';
$error   = '';

// Hôpitaux disponibles (destinations possibles)
$hopitaux = $pdo->query("
    SELECT id, nom, ville
    FROM hopitaux
    WHERE statut = 'actif'
    ORDER BY nom
")->fetchAll();

// Pour patient : son hôpital actuel
$hopitalSource = null;
if ($userType === 'patient') {
    $stmt = $pdo->prepare("SELECT hopital_reference FROM patients WHERE id = ?");
    $stmt->execute([$userId]);
    $hopitalSource = $stmt->fetchColumn() ?: null;
} elseif (in_array($userType, ['infirmier', 'docteur', 'medecin'])) {
    $hopitalSource = (int)($_SESSION['medecin_hopital_principal_id'] ?? 0) ?: null;
}

// Liste des patients (pour staff médical)
$patientsListe = [];
if (in_array($userType, ['infirmier', 'docteur', 'medecin', 'admin'])) {
    $patientsListe = $pdo->query("
        SELECT p.id, p.nom, p.prenom, p.identifiant_blockchain,
               h.nom AS hopital_nom
        FROM patients p
        LEFT JOIN hopitaux h ON h.id = p.hopital_reference
        WHERE p.statut = 'actif'
        ORDER BY p.nom, p.prenom
        LIMIT 200
    ")->fetchAll();
}

// ─── Traitement POST : créer un transfert ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $patientCible = ($userType === 'patient')
                ? $userId
                : (int)($_POST['patient_id'] ?? 0);

            $hopitalDest = (int)($_POST['hopital_destination'] ?? 0);
            $motif       = trim($_POST['motif'] ?? '');

            // Source : hôpital actuel du patient (toujours)
            $stmt = $pdo->prepare("SELECT hopital_reference FROM patients WHERE id = ?");
            $stmt->execute([$patientCible]);
            $sourceId = (int)$stmt->fetchColumn();

            if (!$patientCible || !$hopitalDest) {
                $error = 'Patient et hôpital de destination requis.';
            } elseif ($hopitalDest === $sourceId) {
                $error = "L'hôpital de destination doit être différent de l'hôpital actuel.";
            } elseif (!$sourceId) {
                $error = "Le patient n'est rattaché à aucun hôpital source.";
            } else {
                try {
                    $contenu = json_encode([
                        'patient_id'  => $patientCible,
                        'source'      => $sourceId,
                        'destination' => $hopitalDest,
                        'motif'       => $motif,
                        'demande_par' => "$userType:$userId",
                        'date'        => date('c'),
                    ], JSON_UNESCAPED_UNICODE);
                    $txHash    = '0x' . substr(hash('sha256', $contenu . random_bytes(16)), 0, 64);
                    $signature = HashChain::sign($contenu . $userId . time());
                    $signatureStr = is_string($signature) ? $signature : json_encode($signature);

                    $ins = $pdo->prepare("
                        INSERT INTO transferts_patients
                            (transaction_hash, patient_id, hopital_source, hopital_destination,
                             date_transfert, motif, statut, signature_source)
                        VALUES (?, ?, ?, ?, NOW(), ?, 'demande', ?)
                    ");
                    $ins->execute([
                        $txHash, $patientCible, $sourceId, $hopitalDest,
                        $motif ?: null, $signatureStr,
                    ]);

                    $newId = (int)$pdo->lastInsertId();
                    HashChain::addBlock('REQUEST_TRANSFER', $newId, $userId, $userType, [
                        'patient_id'  => $patientCible,
                        'source'      => $sourceId,
                        'destination' => $hopitalDest,
                        'motif'       => $motif ?: null,
                    ], 'transferts_patients');

                    $success = 'Demande de transfert enregistrée. Un bloc a été ajouté à la chaîne.';
                } catch (PDOException $e) {
                    error_log('transferer_patient: ' . $e->getMessage());
                    $error = "Erreur lors de la création du transfert.";
                }
            }
        } elseif ($action === 'update_status') {
            // L'approbation est strictement limitée aux administrateurs et docteurs.
            if (!in_array($userType, ['admin', 'docteur', 'medecin'], true)) {
                $error = "Seul un administrateur ou un docteur peut valider une demande de transfert.";
            } else {
                $transId    = (int)($_POST['transfert_id'] ?? 0);
                $newStatus  = $_POST['statut'] ?? '';
                $valid = ['accepte', 'refuse', 'complete'];
                if ($transId && in_array($newStatus, $valid, true)) {
                    try {
                        $upd = $pdo->prepare("
                            UPDATE transferts_patients
                               SET statut = ?
                             WHERE id = ?
                        ");
                        $upd->execute([$newStatus, $transId]);

                        // Si complété, mettre à jour l'hôpital de référence du patient
                        if ($newStatus === 'complete') {
                            $stmtT = $pdo->prepare("SELECT patient_id, hopital_destination FROM transferts_patients WHERE id = ?");
                            $stmtT->execute([$transId]);
                            if ($t = $stmtT->fetch()) {
                                $pdo->prepare("UPDATE patients SET hopital_reference = ? WHERE id = ?")
                                    ->execute([$t['hopital_destination'], $t['patient_id']]);
                            }
                        }

                        HashChain::addBlock('UPDATE_TRANSFER', $transId, $userId, $userType, [
                            'nouveau_statut' => $newStatus,
                        ], 'transferts_patients');

                        $success = 'Statut du transfert mis à jour.';
                    } catch (PDOException $e) {
                        error_log('transferer_patient update: ' . $e->getMessage());
                        $error = 'Erreur de mise à jour.';
                    }
                }
            }
        }
    }
}

// ─── Liste des transferts ───────────────────────────────────────────
$transferts = [];
try {
    if ($userType === 'patient') {
        $stmt = $pdo->prepare("
            SELECT tp.*, hs.nom AS source_nom, hd.nom AS dest_nom
            FROM transferts_patients tp
            LEFT JOIN hopitaux hs ON hs.id = tp.hopital_source
            LEFT JOIN hopitaux hd ON hd.id = tp.hopital_destination
            WHERE tp.patient_id = ?
            ORDER BY tp.date_transfert DESC
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->query("
            SELECT tp.*, hs.nom AS source_nom, hd.nom AS dest_nom,
                   p.prenom AS p_prenom, p.nom AS p_nom
            FROM transferts_patients tp
            LEFT JOIN hopitaux hs ON hs.id = tp.hopital_source
            LEFT JOIN hopitaux hd ON hd.id = tp.hopital_destination
            LEFT JOIN patients p  ON p.id  = tp.patient_id
            ORDER BY tp.date_transfert DESC
            LIMIT 100
        ");
    }
    $transferts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('transferer list: ' . $e->getMessage());
    $transferts = [];
}

// Compteurs par statut pour la stat-grid
$nbTotal     = count($transferts);
$nbDemandes  = 0; $nbAcceptes = 0; $nbCompletes = 0; $nbRefuses = 0;
foreach ($transferts as $t) {
    switch ($t['statut']) {
        case 'demande':  $nbDemandes++;  break;
        case 'accepte':  $nbAcceptes++;  break;
        case 'complete': $nbCompletes++; break;
        case 'refuse':   $nbRefuses++;   break;
    }
}

$pageTitle  = 'Transferts inter-hôpitaux';
$pageActive = 'transferts';
$breadcrumb = ['Transferts'];
require_once 'includes/header_dashboard.php';
?>

<!-- HERO (même structure que dashboard_patient) -->
<section class="dash-hero reveal-up">
  <div class="dash-hero-content">
    <div class="dash-hero-greet">
      <span class="dash-hero-dot"></span> Transmission sécurisée
    </div>
    <h1>Transferts <span>inter-hôpitaux</span></h1>
    <p>
      Demandez et suivez les transferts de dossier vers d'autres établissements.
      Chaque demande est <strong>signée numériquement</strong> et inscrite dans la chaîne.
    </p>

    <div class="dash-hero-actions">
      <a href="#nouveau" class="btn btn-white"><i class="fas fa-paper-plane"></i> Nouvelle demande</a>
      <a href="#liste" class="btn btn-ghost-w"><i class="fas fa-list"></i> Voir l'historique</a>
    </div>
  </div>

  <div class="dash-hero-card">
    <div class="dash-hero-card-head">
      <span class="dash-hero-card-label">Inscrites dans la chaîne</span>
      <i class="fas fa-cube"></i>
    </div>
    <div class="dash-hero-card-hash" style="font-size:32px"><?= $nbTotal ?></div>
    <div class="dash-hero-card-meta">
      <span><i class="fas fa-check-circle"></i> Signées numériquement</span>
      <span>·</span>
      <span>Chaîne active</span>
    </div>
  </div>
</section>

<!-- STAT GRID (alignée avec dashboard_patient.php) -->
<section class="stat-grid">
  <div class="stat-card tilt reveal-up">
    <div class="stat-card-icon blockchain"><i class="fas fa-link"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbTotal ?>">0</div>
      <div class="stat-card-label">Total demandes</div>
    </div>
  </div>
  <div class="stat-card tilt reveal-up" style="animation-delay:.05s">
    <div class="stat-card-icon emerald"><i class="fas fa-hourglass-half"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbDemandes ?>">0</div>
      <div class="stat-card-label">En attente</div>
    </div>
  </div>
  <div class="stat-card tilt reveal-up" style="animation-delay:.1s">
    <div class="stat-card-icon trust"><i class="fas fa-thumbs-up"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbAcceptes ?>">0</div>
      <div class="stat-card-label">Acceptés</div>
    </div>
  </div>
  <div class="stat-card tilt reveal-up" style="animation-delay:.15s">
    <div class="stat-card-icon forest"><i class="fas fa-flag-checkered"></i></div>
    <div class="stat-card-body">
      <div class="stat-card-value" data-count="<?= $nbCompletes ?>">0</div>
      <div class="stat-card-label">Complétés</div>
    </div>
  </div>
</section>

<?php if ($success): ?>
  <div class="alert alert-success reveal-up"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-error reveal-up"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- FORMULAIRE NOUVEAU TRANSFERT -->
<section class="dash-card reveal-up" id="nouveau">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-paper-plane" style="color:var(--emerald)"></i> Nouvelle demande de transfert</h3>
      <p>Sélectionnez l'hôpital de destination et le motif</p>
    </div>
  </div>

  <form method="POST" class="grant-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">

    <?php if ($userType !== 'patient'): ?>
    <div class="field full">
      <label>Patient à transférer</label>
      <select name="patient_id" required>
        <option value="">— Sélectionner un patient —</option>
        <?php foreach ($patientsListe as $p): ?>
          <option value="<?= $p['id'] ?>">
            <?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?>
            <?= !empty($p['hopital_nom']) ? ' · ' . htmlspecialchars($p['hopital_nom']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="field">
      <label>Hôpital de destination</label>
      <select name="hopital_destination" required>
        <option value="">— Choisir un hôpital —</option>
        <?php foreach ($hopitaux as $h):
          if ($hopitalSource && (int)$h['id'] === (int)$hopitalSource) continue;
        ?>
          <option value="<?= $h['id'] ?>">
            <?= htmlspecialchars($h['nom']) ?> — <?= htmlspecialchars($h['ville']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field full">
      <label>Motif du transfert</label>
      <textarea name="motif" rows="3" placeholder="Ex : besoin d'une spécialité non disponible, rapprochement familial..."></textarea>
    </div>

    <button type="submit" class="btn btn-primary">
      <i class="fas fa-link"></i> Enregistrer la demande
    </button>
  </form>
</section>

<!-- LISTE DES TRANSFERTS -->
<section class="dash-card reveal-up" id="liste">
  <div class="dash-card-head">
    <div>
      <h3><i class="fas fa-list" style="color:var(--blockchain)"></i>
        <?= count($transferts) ?> transfert<?= count($transferts) > 1 ? 's' : '' ?>
      </h3>
      <p>Historique complet, plus récent en premier</p>
    </div>
  </div>

  <?php if (empty($transferts)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fas fa-ambulance"></i></div>
      <h4>Aucun transfert</h4>
      <p>Aucune demande de transfert enregistrée pour l'instant.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <?php if ($userType !== 'patient'): ?><th>Patient</th><?php endif; ?>
            <th>Source</th>
            <th>Destination</th>
            <th>Motif</th>
            <th>Statut</th>
            <?php if (in_array($userType, ['admin', 'docteur', 'medecin'])): ?>
              <th>Action</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transferts as $t):
            $cls = match($t['statut']) {
              'complete' => 'badge-success',
              'accepte'  => 'badge-info',
              'demande'  => 'badge-warn',
              'refuse'   => 'badge-danger',
              default    => 'badge-neutral',
            };
          ?>
          <tr>
            <td><strong><?= date('d/m/Y', strtotime($t['date_transfert'])) ?></strong></td>
            <?php if ($userType !== 'patient'): ?>
              <td><?= htmlspecialchars(($t['p_prenom'] ?? '') . ' ' . ($t['p_nom'] ?? '')) ?></td>
            <?php endif; ?>
            <td><?= htmlspecialchars($t['source_nom'] ?? '—') ?></td>
            <td>
              <i class="fas fa-arrow-right" style="color:var(--emerald);font-size:11px"></i>
              <?= htmlspecialchars($t['dest_nom'] ?? '—') ?>
            </td>
            <td><?= htmlspecialchars($t['motif'] ?? '—') ?></td>
            <td><span class="badge <?= $cls ?>"><?= ucfirst($t['statut']) ?></span></td>
            <?php if (in_array($userType, ['admin', 'docteur', 'medecin'])): ?>
              <td>
                <?php if ($t['statut'] === 'demande'): ?>
                  <form method="POST" style="display:inline-flex;gap:6px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="transfert_id" value="<?= $t['id'] ?>">
                    <button type="submit" name="statut" value="accepte" class="btn-mini btn-mini-ok"><i class="fas fa-check"></i></button>
                    <button type="submit" name="statut" value="refuse" class="btn-mini btn-mini-no"><i class="fas fa-times"></i></button>
                  </form>
                <?php elseif ($t['statut'] === 'accepte'): ?>
                  <form method="POST" style="display:inline-flex;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="transfert_id" value="<?= $t['id'] ?>">
                    <button type="submit" name="statut" value="complete" class="btn-mini btn-mini-ok">
                      <i class="fas fa-flag-checkered"></i> Compléter
                    </button>
                  </form>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<style>
/* ─── Hero dashboard (calé sur dashboard_patient.php) ─── */
.dash-hero {
  position: relative; overflow: hidden;
  background: linear-gradient(135deg, var(--theme-primary, #1a472a) 0%, #0d9488 55%, var(--theme-accent, #0369a1) 100%);
  color: white; border-radius: 24px;
  padding: 40px; margin-bottom: 24px;
  display: grid; grid-template-columns: 1.4fr 1fr;
  gap: 32px; align-items: center;
}
.dash-hero::before {
  content: ''; position: absolute; top: -150px; right: -150px;
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(16,185,129,.35), transparent 60%);
  filter: blur(80px);
}
.dash-hero::after {
  content: ''; position: absolute; bottom: -100px; left: -100px;
  width: 300px; height: 300px;
  background: radial-gradient(circle, rgba(56,189,248,.25), transparent 60%);
  filter: blur(60px);
}
.dash-hero-content { position: relative; z-index: 1; }
.dash-hero-greet {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 6px 12px; background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.15); border-radius: 999px;
  font-size: 12px; font-weight: 600; margin-bottom: 16px;
}
.dash-hero-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #10b981; box-shadow: 0 0 12px #10b981;
  animation: pulse 2s infinite;
}
@keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .6; transform: scale(1.2); } }
.dash-hero h1 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 36px; font-weight: 800; line-height: 1.15; letter-spacing: -.02em; }
.dash-hero h1 span { display: block; background: linear-gradient(135deg, #34d399, #7dd3fc); -webkit-background-clip: text; background-clip: text; color: transparent; }
.dash-hero p { font-size: 15px; color: rgba(255,255,255,.78); margin-top: 12px; line-height: 1.55; }
.dash-hero p strong { color: #86efac; }
.dash-hero-actions { display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap; }

.btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; text-decoration: none; transition: .3s; font-family: inherit; }
.btn-white { background: white; color: var(--forest); border: none; }
.btn-white:hover { background: #f1f5f9; transform: translateY(-2px); box-shadow: 0 10px 24px -8px rgba(255,255,255,.3); }
.btn-ghost-w { background: rgba(255,255,255,.1); color: white; border: 1px solid rgba(255,255,255,.2); }
.btn-ghost-w:hover { background: rgba(255,255,255,.18); transform: translateY(-2px); }
.btn-primary { background: var(--g-emerald); color: white; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -8px rgba(16,185,129,.4); }

.dash-hero-card {
  position: relative; z-index: 1;
  padding: 24px; background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(16px);
  border-radius: 18px;
}
.dash-hero-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.dash-hero-card-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: rgba(255,255,255,.6); }
.dash-hero-card-head i { color: #34d399; font-size: 18px; }
.dash-hero-card-hash {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-weight: 800; color: #34d399;
  padding: 12px 14px; background: rgba(16,185,129,.1);
  border: 1px solid rgba(16,185,129,.25); border-radius: 10px;
  text-align: center;
}
.dash-hero-card-meta { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 12px; color: rgba(255,255,255,.6); }
.dash-hero-card-meta i { color: #34d399; }

/* ─── Stat grid (identique au dashboard_patient) ─── */
.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card { display: flex; align-items: center; gap: 16px; padding: 20px; background: white; border: 1px solid var(--line); border-radius: 16px; transition: .3s; position: relative; overflow: hidden; }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--g-forest); transform: scaleX(0); transform-origin: left; transition: transform .4s; }
.stat-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px -16px rgba(15,23,42,.15); border-color: rgba(26,71,42,.2); }
.stat-card:hover::before { transform: scaleX(1); }
.stat-card-icon { width: 48px; height: 48px; border-radius: 14px; display: grid; place-items: center; color: white; font-size: 20px; flex-shrink: 0; }
.stat-card-icon.forest    { background: var(--g-forest); }
.stat-card-icon.emerald   { background: var(--g-emerald); }
.stat-card-icon.trust     { background: var(--g-trust); }
.stat-card-icon.blockchain{ background: var(--g-blockchain); }
.stat-card-body { flex: 1; }
.stat-card-value { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 28px; font-weight: 800; color: var(--ink); line-height: 1; }
.stat-card-label { font-size: 13px; color: var(--muted); margin-top: 4px; }

/* Alerts */
.alert { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 500; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid rgba(16,185,129,.3); }
.alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid rgba(239,68,68,.3); }

/* Dash card */
.dash-card { background: white; border: 1px solid var(--line); border-radius: 20px; padding: 28px; margin-bottom: 24px; }
.dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; }
.dash-card-head h3 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 18px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 10px; letter-spacing: -.01em; }
.dash-card-head p { font-size: 13px; color: var(--muted); margin-top: 2px; }

.grant-form { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: end; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field.full { grid-column: 1 / -1; }
.field label { font-size: 13px; font-weight: 700; color: var(--ink); }
.field select, .field textarea {
  width: 100%; padding: 11px 14px;
  border: 1.5px solid var(--line); border-radius: 12px; background: #f8fafc;
  font-family: inherit; font-size: 14px; color: var(--ink);
}
.field select:focus, .field textarea:focus {
  outline: none; border-color: var(--emerald); background: white;
  box-shadow: 0 0 0 3px rgba(16,185,129,.12);
}

/* Empty */
.empty { text-align: center; padding: 40px 20px; }
.empty-icon { width: 72px; height: 72px; margin: 0 auto 16px; background: rgba(26,71,42,.06); border-radius: 50%; display: grid; place-items: center; color: var(--forest); font-size: 28px; }
.empty h4 { font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
.empty p { font-size: 13px; color: var(--muted); }

/* Table */
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 14px; }
.table thead th { padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: .06em; border-bottom: 1px solid var(--line); background: #f8fafc; }
.table tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.table tbody tr:hover { background: #f8fafc; }
.table tbody tr:last-child td { border-bottom: none; }

.badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.badge-success { background: #d1fae5; color: #065f46; }
.badge-info    { background: #dbeafe; color: #1e40af; }
.badge-warn    { background: #fef3c7; color: #92400e; }
.badge-danger  { background: #fee2e2; color: #991b1b; }
.badge-neutral { background: #f1f5f9; color: #475569; }

.btn-mini { display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; border: none; cursor: pointer; transition: .2s; font-family: inherit; }
.btn-mini-ok { background: rgba(16,185,129,.12); color: #065f46; }
.btn-mini-ok:hover { background: #10b981; color: white; }
.btn-mini-no { background: rgba(239,68,68,.12); color: #991b1b; }
.btn-mini-no:hover { background: #ef4444; color: white; }

@media (max-width: 1200px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 960px) { .dash-hero { grid-template-columns: 1fr; padding: 32px 24px; } .dash-hero h1 { font-size: 28px; } }
@media (max-width: 720px) { .grant-form { grid-template-columns: 1fr; } .stat-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
