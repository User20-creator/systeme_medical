<?php
// accueil_dossier.php — L'infirmier saisit les infos d'arrivée d'un patient
// (motif de visite, constantes vitales). Le dossier sera consulté ensuite
// par le médecin sur son tableau de bord.

require_once 'config.php';
require_once 'includes/hash_chain.php';
require_once 'includes/migrations.php';

ensure_medecin_traitant_column($pdo);

if (!isset($_SESSION['medecin_id']) || $_SESSION['user_type'] !== 'infirmier') {
    header('Location: connexion2.php');
    exit;
}

$infirmierId = (int)$_SESSION['medecin_id'];
$infirmierNom = trim(($_SESSION['medecin_prenom'] ?? '') . ' ' . ($_SESSION['medecin_nom'] ?? ''));
$hopitalInfirmier = (int)($_SESSION['medecin_hopital_principal_id'] ?? 0);

$erreur  = '';
$created = null;

// Patient ciblé : ?patient=ID dans l'URL ou via le formulaire
$patientCible = null;
$patientId    = (int)($_GET['patient'] ?? $_POST['patient_id'] ?? 0);

if ($patientId) {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND statut = 'actif'");
    $stmt->execute([$patientId]);
    $patientCible = $stmt->fetch();
}

// Liste des patients pour la recherche (limitée aux 50 plus récents)
$patientsRecents = $pdo->query("
    SELECT id, nom, prenom, telephone, date_naissance, identifiant_blockchain
    FROM patients
    WHERE statut = 'actif'
    ORDER BY date_inscription DESC
    LIMIT 50
")->fetchAll();

// Liste des docteurs disponibles pour transmission (priorité : même hôpital)
$docteursDispo = [];
try {
    if ($hopitalInfirmier) {
        $stmt = $pdo->prepare("
            SELECT id, prenom, nom, specialite, hopital_id
            FROM docteurs
            WHERE statut = 'actif'
            ORDER BY (hopital_id = ?) DESC, nom ASC
        ");
        $stmt->execute([$hopitalInfirmier]);
    } else {
        $stmt = $pdo->query("
            SELECT id, prenom, nom, specialite, hopital_id
            FROM docteurs
            WHERE statut = 'actif'
            ORDER BY nom ASC
        ");
    }
    $docteursDispo = $stmt->fetchAll();
} catch (PDOException $e) {
    $docteursDispo = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['enregistrer']) && !csrf_check()) {
    $erreur = "Jeton de sécurité invalide. Veuillez recharger la page.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['enregistrer'])) {
    $patientId   = (int)($_POST['patient_id'] ?? 0);
    $motif       = trim($_POST['motif_visite']      ?? '');
    $symptomes   = trim($_POST['symptomes']         ?? '');
    $tension     = trim($_POST['tension_arterielle']?? '');
    $temp        = trim($_POST['temperature']       ?? '');
    $poids       = trim($_POST['poids']             ?? '');
    $taille      = trim($_POST['taille']            ?? '');
    $pouls       = trim($_POST['pouls']             ?? '');
    $observ      = trim($_POST['observations']      ?? '');
    $urgence     = $_POST['niveau_urgence']         ?? 'normal';
    $docteurCible = (int)($_POST['docteur_cible']    ?? 0);

    if (!$patientId) {
        $erreur = "Veuillez sélectionner un patient.";
    } elseif (empty($motif)) {
        $erreur = "Le motif de la visite est obligatoire.";
    } else {
        try {
            // Vérifier que le patient existe
            $check = $pdo->prepare("SELECT id, nom, prenom FROM patients WHERE id = ?");
            $check->execute([$patientId]);
            $patient = $check->fetch();
            if (!$patient) {
                $erreur = "Patient introuvable.";
            } else {
                // Construire la description agrégée. Les champs symptomes/observations/
                // pouls/taille/niveau_urgence ne sont PAS des colonnes du schéma
                // dossiers_medicaux — on les concatène dans description (TEXT).
                $descriptionParts = [];
                if ($urgence && $urgence !== 'normal') {
                    $descriptionParts[] = '⚠ NIVEAU D\'URGENCE : ' . strtoupper($urgence);
                }
                if ($symptomes) $descriptionParts[] = "Symptômes :\n" . $symptomes;
                if ($pouls)     $descriptionParts[] = "Pouls : $pouls bpm";
                if ($taille)    $descriptionParts[] = "Taille : $taille";
                if ($observ)    $descriptionParts[] = "Observations :\n" . $observ;
                $description = implode("\n\n", $descriptionParts);

                // Hash de contenu + transaction_hash uniques pour respecter
                // la contrainte UNIQUE de transaction_hash.
                $contenuJson = json_encode([
                    'patient_id' => $patientId,
                    'motif'      => $motif,
                    'description'=> $description,
                    'tension'    => $tension,
                    'temperature'=> $temp,
                    'poids'      => $poids,
                    'created_at' => date('c'),
                ], JSON_UNESCAPED_UNICODE);
                $hashContenu     = hash('sha256', $contenuJson);
                $transactionHash = '0x' . substr(hash('sha256', $hashContenu . random_bytes(16)), 0, 64);
                $signature       = HashChain::sign($contenuJson . $infirmierId . time());
                $signatureStr    = is_string($signature) ? $signature : json_encode($signature);
                $titre           = mb_substr('Accueil — ' . $motif, 0, 200);

                $stmt = $pdo->prepare("
                    INSERT INTO dossiers_medicaux
                        (transaction_hash, patient_id, hopital_id, type_document, titre,
                         description, date_creation, signature_medecin, hash_contenu,
                         confidentialite, tension, poids, temperature, motif_visite,
                         cree_par_infirmier, modifie_par_docteur)
                    VALUES
                        (:tx, :pid, :hopital, 'consultation', :titre,
                         :description, NOW(), :signature, :hash,
                         'medecin', :tension, :poids, :temperature, :motif,
                         :infirmier, :docteur)
                ");
                $stmt->execute([
                    ':tx'          => $transactionHash,
                    ':pid'         => $patientId,
                    ':hopital'     => $hopitalInfirmier ?: null,
                    ':titre'       => $titre,
                    ':description' => $description ?: null,
                    ':signature'   => $signatureStr,
                    ':hash'        => $hashContenu,
                    ':tension'     => $tension ?: null,
                    ':poids'       => $poids ?: null,
                    ':temperature' => $temp ?: null,
                    ':motif'       => $motif,
                    ':infirmier'   => $infirmierId,
                    ':docteur'     => $docteurCible ?: null,
                ]);

                $dossierId = (int)$pdo->lastInsertId();

                // Récupérer le nom du docteur pour le bloc + définir comme médecin traitant
                $docteurNom = null;
                if ($docteurCible) {
                    $stmtDoc = $pdo->prepare("SELECT prenom, nom FROM docteurs WHERE id = ?");
                    $stmtDoc->execute([$docteurCible]);
                    if ($d = $stmtDoc->fetch()) {
                        $docteurNom = $d['prenom'] . ' ' . $d['nom'];
                    }

                    // Définir le médecin traitant si non défini, ou si le patient
                    // n'en a pas encore. On ne l'écrase pas s'il existe déjà.
                    try {
                        $pdo->prepare("
                            UPDATE patients
                               SET medecin_traitant_id = ?
                             WHERE id = ?
                               AND (medecin_traitant_id IS NULL OR medecin_traitant_id = 0)
                        ")->execute([$docteurCible, $patientId]);
                    } catch (PDOException $e) {
                        error_log('update medecin_traitant: ' . $e->getMessage());
                    }
                }

                // Ajout au registre
                HashChain::addBlock('ACCUEIL_PATIENT', $dossierId, $infirmierId, 'infirmier', [
                    'patient'        => $patient['prenom'] . ' ' . $patient['nom'],
                    'motif'          => mb_substr($motif, 0, 100),
                    'urgence'        => $urgence,
                    'tension'        => $tension ?: null,
                    'temperature'    => $temp ?: null,
                    'transmis_au_docteur' => $docteurNom,
                ], 'dossiers_medicaux');

                $created = [
                    'patient'  => $patient['prenom'] . ' ' . $patient['nom'],
                    'motif'    => $motif,
                    'urgence'  => $urgence,
                    'dossier'  => $dossierId,
                    'docteur'  => $docteurNom,
                ];

                // Reset
                $_POST = [];
                $patientCible = null;
            }
        } catch (PDOException $e) {
            error_log('accueil_dossier: ' . $e->getMessage());
            $erreur = "Erreur lors de l'enregistrement du dossier.";
        }
    }
}

$pageTitle  = "Accueil patient";
$pageActive = 'accueil-dossier';
$breadcrumb = ['Accueil', 'Enregistrer une arrivée'];
require_once 'includes/header_dashboard.php';
?>

<!-- HERO PREMIUM -->
<section class="creer-hero reveal-up">
  <div class="ch-decor">
    <div class="ch-orb ch-orb-1"></div>
    <div class="ch-orb ch-orb-2"></div>
    <svg class="ch-grid" viewBox="0 0 200 200" preserveAspectRatio="none" aria-hidden="true">
      <defs>
        <pattern id="chGridPatAcc" width="22" height="22" patternUnits="userSpaceOnUse">
          <path d="M 22 0 L 0 0 0 22" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="1"/>
        </pattern>
      </defs>
      <rect width="200" height="200" fill="url(#chGridPatAcc)"/>
    </svg>
  </div>

  <div class="ch-content">
    <span class="ch-eyebrow">
      <span class="ch-dot"></span>
      Accueil et triage
    </span>

    <h1 class="ch-title">
      Enregistrer<br>
      <span class="ch-title-hl">l'arrivée d'un patient</span>
    </h1>

    <p class="ch-lead">
      Notez le <strong>motif de visite</strong>, les constantes vitales et le niveau d'urgence.
      Le <strong>médecin verra immédiatement</strong> ces informations sur son tableau de bord.
    </p>

    <div class="ch-chips">
      <div class="ch-chip">
        <div class="ch-chip-icon"><i class="fas fa-user-nurse"></i></div>
        <div class="ch-chip-text">
          <strong>Infirmier en charge</strong>
          <span><?= htmlspecialchars($infirmierNom ?: '—') ?></span>
        </div>
      </div>
      <div class="ch-chip">
        <div class="ch-chip-icon"><i class="fas fa-clock"></i></div>
        <div class="ch-chip-text">
          <strong>Date et heure</strong>
          <span><?= date('d/m/Y · H:i') ?></span>
        </div>
      </div>
      <div class="ch-chip">
        <div class="ch-chip-icon"><i class="fas fa-shield-halved"></i></div>
        <div class="ch-chip-text">
          <strong>Signé numériquement</strong>
          <span>Inscrit dans la chaîne</span>
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

<?php if ($created): ?>
  <div class="alert-box success reveal-up">
    <i class="fas fa-check-circle"></i>
    <div>
      <strong>Dossier #<?= $created['dossier'] ?> enregistré</strong> pour <?= htmlspecialchars($created['patient']) ?>.
      Motif : <em><?= htmlspecialchars($created['motif']) ?></em>.
      <?php if (!empty($created['docteur'])): ?>
        Transmis au <strong>Dr. <?= htmlspecialchars($created['docteur']) ?></strong>.
      <?php else: ?>
        Le médecin de garde sera notifié.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<section class="form-shell reveal-up">
  <form method="POST" class="form-card" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-head" style="background:linear-gradient(135deg, rgba(14,165,233,.08), rgba(3,105,161,.04));">
      <div class="form-head-icon" style="background:linear-gradient(135deg,#0ea5e9,#0369a1);box-shadow:0 10px 24px -10px rgba(14,165,233,.5);">
        <i class="fas fa-clipboard-user"></i>
      </div>
      <div>
        <h2>Fiche d'accueil</h2>
        <p>Les champs marqués d'une <span class="req">*</span> sont obligatoires.</p>
      </div>
    </div>

    <div class="form-body">

      <!-- Sélection patient -->
      <div class="section-divider"><i class="fas fa-user-injured"></i> Patient</div>

      <?php if ($patientCible): ?>
        <div class="patient-selected">
          <div class="patient-selected-avatar">
            <?= strtoupper(substr($patientCible['prenom'],0,1) . substr($patientCible['nom'],0,1)) ?>
          </div>
          <div class="patient-selected-info">
            <h4><?= htmlspecialchars($patientCible['prenom'] . ' ' . $patientCible['nom']) ?></h4>
            <p>
              <?php if ($patientCible['date_naissance']): ?>
                <?= date_diff(date_create($patientCible['date_naissance']), date_create('today'))->y ?> ans
              <?php endif; ?>
              <?= !empty($patientCible['groupe_sanguin']) ? ' · Gr. ' . htmlspecialchars($patientCible['groupe_sanguin']) : '' ?>
              <?= !empty($patientCible['telephone']) ? ' · ' . htmlspecialchars($patientCible['telephone']) : '' ?>
            </p>
            <small><?= htmlspecialchars($patientCible['identifiant_blockchain']) ?></small>
          </div>
          <a href="accueil_dossier.php" class="btn-ic" title="Changer de patient">
            <i class="fas fa-times"></i>
          </a>
          <input type="hidden" name="patient_id" value="<?= (int)$patientCible['id'] ?>">
        </div>
      <?php else: ?>
        <div class="form-group full">
          <label>Sélectionner un patient <span class="req">*</span></label>
          <div class="input-shell">
            <i class="fas fa-search"></i>
            <select name="patient_id" required>
              <option value="">— Choisir un patient existant —</option>
              <?php foreach ($patientsRecents as $p): ?>
                <option value="<?= $p['id'] ?>">
                  <?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?>
                  <?= !empty($p['telephone']) ? ' · ' . htmlspecialchars($p['telephone']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="hint-muted">
            Patient introuvable ?
            <a href="creer_patient.php" style="color:var(--forest);font-weight:700;">Enregistrer un nouveau patient →</a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Motif et urgence -->
      <div class="section-divider"><i class="fas fa-clipboard"></i> Motif de visite</div>
      <div class="form-grid">
        <div class="form-group full">
          <label>Motif principal <span class="req">*</span></label>
          <div class="input-shell">
            <i class="fas fa-stethoscope"></i>
            <input type="text" name="motif_visite" required
                   value="<?= htmlspecialchars($_POST['motif_visite'] ?? '') ?>"
                   placeholder="Ex : douleurs abdominales, fièvre persistante, suivi...">
          </div>
        </div>

        <div class="form-group full">
          <label>Symptômes décrits par le patient</label>
          <div class="input-shell" style="align-items:flex-start;">
            <i class="fas fa-comment-medical" style="margin-top:13px;"></i>
            <textarea name="symptomes" rows="3"
                      placeholder="Décrivez les symptômes rapportés..."><?= htmlspecialchars($_POST['symptomes'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="form-group full">
          <label>Niveau d'urgence</label>
          <div class="urgence-pills">
            <?php
            $urgences = [
              'normal'   => ['Normal', '#10b981',  'fa-leaf'],
              'urgent'   => ['Urgent', '#f59e0b',  'fa-clock'],
              'critique' => ['Critique', '#ef4444','fa-triangle-exclamation'],
            ];
            $sel = $_POST['niveau_urgence'] ?? 'normal';
            foreach ($urgences as $val => [$label, $color, $icon]):
            ?>
              <label class="urgence-pill" style="--c:<?= $color ?>">
                <input type="radio" name="niveau_urgence" value="<?= $val ?>" <?= $sel === $val ? 'checked' : '' ?>>
                <span><i class="fas <?= $icon ?>"></i> <?= $label ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Constantes vitales -->
      <div class="section-divider"><i class="fas fa-heart-pulse"></i> Constantes vitales</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Tension artérielle</label>
          <div class="input-shell">
            <i class="fas fa-heart"></i>
            <input type="text" name="tension_arterielle"
                   value="<?= htmlspecialchars($_POST['tension_arterielle'] ?? '') ?>"
                   placeholder="120/80">
          </div>
        </div>
        <div class="form-group">
          <label>Température (°C)</label>
          <div class="input-shell">
            <i class="fas fa-temperature-half"></i>
            <input type="text" name="temperature"
                   value="<?= htmlspecialchars($_POST['temperature'] ?? '') ?>"
                   placeholder="36.8">
          </div>
        </div>
        <div class="form-group">
          <label>Pouls (bpm)</label>
          <div class="input-shell">
            <i class="fas fa-wave-square"></i>
            <input type="text" name="pouls"
                   value="<?= htmlspecialchars($_POST['pouls'] ?? '') ?>"
                   placeholder="72">
          </div>
        </div>
        <div class="form-group">
          <label>Poids (kg)</label>
          <div class="input-shell">
            <i class="fas fa-weight-scale"></i>
            <input type="text" name="poids"
                   value="<?= htmlspecialchars($_POST['poids'] ?? '') ?>"
                   placeholder="65">
          </div>
        </div>
        <div class="form-group">
          <label>Taille (cm)</label>
          <div class="input-shell">
            <i class="fas fa-ruler-vertical"></i>
            <input type="text" name="taille"
                   value="<?= htmlspecialchars($_POST['taille'] ?? '') ?>"
                   placeholder="170">
          </div>
        </div>
      </div>

      <!-- Observations infirmier -->
      <div class="section-divider"><i class="fas fa-pen"></i> Observations</div>
      <div class="form-grid">
        <div class="form-group full">
          <label>Notes pour le médecin</label>
          <div class="input-shell" style="align-items:flex-start;">
            <i class="fas fa-notes-medical" style="margin-top:13px;"></i>
            <textarea name="observations" rows="3"
                      placeholder="Ce que vous voulez signaler au médecin avant la consultation..."><?= htmlspecialchars($_POST['observations'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

      <!-- Transmission au docteur -->
      <div class="section-divider"><i class="fas fa-user-doctor"></i> Transmission</div>
      <div class="form-grid">
        <div class="form-group full">
          <label>Transmettre le dossier à un docteur (recommandé)</label>
          <div class="input-shell">
            <i class="fas fa-user-doctor"></i>
            <select name="docteur_cible">
              <option value="">— Aucun docteur sélectionné (consultable par tous) —</option>
              <?php foreach ($docteursDispo as $doc): ?>
                <option value="<?= $doc['id'] ?>"
                        <?= ($_POST['docteur_cible'] ?? '') == $doc['id'] ? 'selected' : '' ?>>
                  Dr. <?= htmlspecialchars($doc['prenom'] . ' ' . $doc['nom']) ?>
                  <?= !empty($doc['specialite']) ? ' · ' . htmlspecialchars($doc['specialite']) : '' ?>
                  <?= ((int)$doc['hopital_id'] === (int)$hopitalInfirmier) ? ' · Même hôpital' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="hint-muted">
            En sélectionnant un docteur, le dossier apparaîtra directement dans sa liste de patients.
          </div>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <a href="dashboard_infirmier.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Annuler
      </a>
      <button type="submit" name="enregistrer" value="1" class="btn btn-trust">
        <i class="fas fa-paper-plane"></i> Transmettre au médecin
      </button>
    </div>
  </form>
</section>

<style>
/* ================== HERO PREMIUM (style cohérent multi-pages) ================== */
.creer-hero {
  position:relative; overflow:hidden;
  background:
    radial-gradient(ellipse at top right, rgba(56,189,248,.35), transparent 55%),
    radial-gradient(ellipse at bottom left, rgba(14,165,233,.25), transparent 60%),
    linear-gradient(135deg,#0c4a6e 0%, #0369a1 45%, #0ea5e9 100%);
  color:white; border-radius:28px;
  padding:44px 48px 40px;
  margin-bottom:24px;
  box-shadow:0 24px 60px -24px rgba(12,74,110,.55);
}
.ch-decor { position:absolute; inset:0; pointer-events:none; }
.ch-grid  { position:absolute; inset:0; width:100%; height:100%; opacity:.7; }
.ch-orb { position:absolute; border-radius:50%; filter:blur(60px); opacity:.55; }
.ch-orb-1 { width:360px; height:360px; top:-120px; right:-100px; background:radial-gradient(circle, rgba(125,211,252,.6), transparent 70%); }
.ch-orb-2 { width:280px; height:280px; bottom:-100px; left:-60px; background:radial-gradient(circle, rgba(3,105,161,.5), transparent 70%); }
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
  background:#7dd3fc; box-shadow:0 0 14px #7dd3fc, 0 0 4px #bae6fd;
  animation:chPulseAcc 2s ease-in-out infinite;
}
@keyframes chPulseAcc { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.3)} }
.ch-title {
  font-family:'Plus Jakarta Sans', sans-serif;
  font-size:clamp(30px, 4vw, 46px); font-weight:800;
  line-height:1.05; letter-spacing:-.025em;
  margin:0 0 16px; color:white;
}
.ch-title-hl {
  background:linear-gradient(135deg, #bae6fd 0%, #7dd3fc 50%, #5eead4 100%);
  -webkit-background-clip:text; background-clip:text; color:transparent;
  position:relative; display:inline-block;
}
.ch-title-hl::after {
  content:''; position:absolute; left:0; right:0; bottom:-4px; height:3px;
  background:linear-gradient(90deg, transparent, rgba(125,211,252,.6), transparent);
  border-radius:2px;
}
.ch-lead { font-size:15px; color:rgba(255,255,255,.82); line-height:1.65; max-width:640px; margin:0 0 28px; }
.ch-lead strong { color:#bae6fd; font-weight:700; }
.ch-chips { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; }
.ch-chip {
  display:flex; align-items:center; gap:12px;
  padding:14px 16px; background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.12); border-radius:14px;
  backdrop-filter:blur(12px); transition:.25s;
}
.ch-chip:hover { background:rgba(255,255,255,.1); border-color:rgba(186,230,253,.3); transform:translateY(-2px); }
.ch-chip-icon {
  width:40px; height:40px; flex-shrink:0; border-radius:11px;
  background:linear-gradient(135deg, rgba(125,211,252,.25), rgba(56,189,248,.15));
  border:1px solid rgba(186,230,253,.25);
  display:grid; place-items:center; color:#bae6fd; font-size:15px;
}
.ch-chip-text strong { display:block; font-size:13px; font-weight:700; color:white; margin-bottom:2px; line-height:1.2; }
.ch-chip-text span { font-size:11px; color:rgba(255,255,255,.6); letter-spacing:.01em; }
@media (max-width: 860px) { .creer-hero { padding:32px 24px; border-radius:22px; } .ch-chips { grid-template-columns:1fr; } }
/* ================== FIN HERO ================== */

.alert-box.success { background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.25); color:#065f46; }
.alert-box { display:flex; align-items:center; gap:12px; padding:14px 18px; margin-bottom:20px; border-radius:14px; font-size:14px; }
.alert-box i { font-size:18px; }
.alert-box.error { background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.25); color:#b91c1c; }

.form-shell { max-width:920px; margin:0 auto; }
.form-card { background:white; border:1px solid var(--line); border-radius:20px; overflow:hidden; box-shadow:0 14px 34px -18px rgba(15,23,42,.12); }
.form-head { display:flex; align-items:center; gap:16px; padding:24px 28px; border-bottom:1px solid var(--line); }
.form-head-icon { width:52px; height:52px; border-radius:14px; color:white; display:grid; place-items:center; font-size:20px; }
.form-head h2 { font-family:'Plus Jakarta Sans',sans-serif; font-size:18px; font-weight:800; color:var(--ink); margin:0; }
.form-head p { font-size:13px; color:var(--muted); margin-top:2px; }
.form-head .req { color:#ef4444; }

.form-body { padding:28px; }
.section-divider { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#0369a1; padding:18px 0 12px; margin-top:8px; border-bottom:2px solid rgba(14,165,233,.12); margin-bottom:18px; display:flex; align-items:center; gap:8px; }
.section-divider:first-child { margin-top:0; padding-top:0; }

.form-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }
.form-grid > .full { grid-column:1/-1; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-group label { font-size:13px; font-weight:700; color:var(--ink); }
.form-group label .req { color:#ef4444; }

.input-shell { display:flex; align-items:center; gap:10px; background:#f8fafc; border:1.5px solid var(--line); border-radius:12px; padding:0 14px; transition:.25s; }
.input-shell:focus-within { border-color:#0ea5e9; background:white; box-shadow:0 0 0 3px rgba(14,165,233,.1); }
.input-shell i { color:var(--muted); width:14px; font-size:14px; }
.input-shell input, .input-shell select, .input-shell textarea { flex:1; border:none; background:transparent; outline:none; padding:12px 0; font-size:14px; color:var(--ink); font-family:inherit; resize:vertical; }

.hint-muted { font-size:12px; color:var(--muted); margin-top:2px; }

.urgence-pills { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.urgence-pill { cursor:pointer; position:relative; }
.urgence-pill input { position:absolute; opacity:0; inset:0; }
.urgence-pill span { display:flex; align-items:center; justify-content:center; gap:8px; padding:13px 14px; border:1.5px solid var(--line); border-radius:12px; font-size:13px; font-weight:600; color:var(--ink); background:#f8fafc; transition:.2s; }
.urgence-pill input:checked + span { border-color:var(--c); background:color-mix(in srgb, var(--c) 12%, white); color:var(--c); box-shadow:0 0 0 3px color-mix(in srgb, var(--c) 18%, transparent); }

.patient-selected { display:flex; align-items:center; gap:14px; padding:16px; background:linear-gradient(135deg, rgba(14,165,233,.06), rgba(3,105,161,.03)); border:1px solid rgba(14,165,233,.2); border-radius:14px; margin-bottom:8px; }
.patient-selected-avatar { width:52px; height:52px; border-radius:14px; background:linear-gradient(135deg,#0ea5e9,#0369a1); color:white; display:grid; place-items:center; font-weight:800; font-family:'Plus Jakarta Sans',sans-serif; flex-shrink:0; }
.patient-selected-info { flex:1; min-width:0; }
.patient-selected-info h4 { font-size:15px; font-weight:700; color:var(--ink); margin-bottom:3px; }
.patient-selected-info p { font-size:13px; color:var(--muted); margin-bottom:2px; }
.patient-selected-info small { font-size:11px; color:#94a3b8; font-family:'JetBrains Mono',monospace; }

.btn-ic { width:36px; height:36px; border-radius:10px; background:rgba(14,165,233,.08); color:#0369a1; display:inline-grid; place-items:center; text-decoration:none; border:none; cursor:pointer; transition:.3s; }
.btn-ic:hover { background:#0369a1; color:white; }

.form-actions { display:flex; justify-content:flex-end; gap:10px; padding:20px 28px; border-top:1px solid var(--line); background:#f8fafc; }
.btn { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border-radius:12px; font-size:13px; font-weight:700; text-decoration:none; border:none; cursor:pointer; transition:.3s; font-family:inherit; }
.btn-trust { background:linear-gradient(135deg,#0ea5e9,#0369a1); color:white; }
.btn-trust:hover { transform:translateY(-1px); box-shadow:0 10px 24px -8px rgba(14,165,233,.45); }
.btn-outline { background:white; border:1px solid var(--line); color:var(--ink); }
.btn-outline:hover { border-color:#0369a1; color:#0369a1; }

@media (max-width: 640px) { .form-grid { grid-template-columns:1fr; } .urgence-pills { grid-template-columns:1fr; } }
</style>

<?php require_once 'includes/footer_dashboard.php'; ?>
