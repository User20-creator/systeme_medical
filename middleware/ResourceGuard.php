<?php
// ============================================================
//  middleware/ResourceGuard.php — ADAPTÉ AU PROJET EXISTANT
//
//  ✅ Utilise $pdo global depuis config.php
//  ✅ Utilise Auth::userId() et Auth::roleCode() adaptés
//     aux sessions existantes du projet
// ============================================================

require_once __DIR__ . '/Auth.php';

class ResourceGuard {

    // ----------------------------------------------------------
    // Peut voir le dossier médical d'un patient ?
    // ----------------------------------------------------------
    public static function peutVoirDossier(int $patientId): bool {
        return match(Auth::roleCode()) {

            // Patient : son propre dossier uniquement
            'patient'   => $patientId === Auth::userId(),

            // Infirmier : tous les dossiers (il les crée)
            'infirmier' => true,

            // Docteur : uniquement si le patient l'a autorisé
            'docteur'   => self::docteurAutorise($patientId),

            // Admin : accès total
            'admin'     => true,

            default => false,
        };
    }

    // ----------------------------------------------------------
    // Peut créer/modifier un dossier ?
    // ----------------------------------------------------------
    public static function peutModifierDossier(int $dossierId): bool {
        if (Auth::estAdmin()) return true;

        global $pdo;
        $stmt = $pdo->prepare("
            SELECT cree_par_infirmier, docteur_id, patient_id
            FROM   dossiers_medicaux
            WHERE  id = ?
        ");
        $stmt->execute([$dossierId]);
        $dossier = $stmt->fetch();

        if (!$dossier) return false;

        // Infirmier : peut modifier s'il l'a créé
        if (Auth::estInfirmier()) {
            return (int)$dossier['cree_par_infirmier'] === Auth::userId();
        }

        // Docteur : peut modifier si le patient l'a autorisé
        if (Auth::estDocteur()) {
            return self::docteurAutorise((int)$dossier['patient_id']);
        }

        return false;
    }

    // ----------------------------------------------------------
    // Docteur autorisé par le patient ?
    // Vérifie dans acces_patients
    // ----------------------------------------------------------
    private static function docteurAutorise(int $patientId): bool {
        global $pdo;
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM   acces_patients
            WHERE  patient_id  = :patient_id
              AND  entite_id   = :docteur_id
              AND  type_entite = 'docteur'
              AND  actif       = 1
              AND  (date_fin IS NULL OR date_fin > NOW())
        ");
        $stmt->execute([
            ':patient_id' => $patientId,
            ':docteur_id' => Auth::userId(),
        ]);
        return (bool) $stmt->fetch()['total'];
    }

    // ----------------------------------------------------------
    // Peut voir une ordonnance ?
    // Visible par tout le monde sauf accès anonyme
    // ----------------------------------------------------------
    public static function peutVoirOrdonnance(int $dossierPatientId): bool {
        return match(Auth::roleCode()) {
            'patient'   => $dossierPatientId === Auth::userId(),
            'infirmier' => true,
            'docteur'   => true,
            'admin'     => true,
            default     => false,
        };
    }

    // ----------------------------------------------------------
    // Accorder accès à un docteur (fait par l'infirmier)
    // ----------------------------------------------------------
    public static function accorderAccesDocteur(
        int    $patientId,
        int    $docteurId,
        string $typeAcces = 'lecture'
    ): bool {
        global $pdo;
        try {
            $stmt = $pdo->prepare("
                INSERT INTO acces_patients
                    (patient_id, entite_id, type_entite,
                     type_acces, actif, accorde_par, motif)
                VALUES
                    (:patient_id, :docteur_id, 'docteur',
                     :type_acces, 1, :accorde_par, 'Assigné par infirmier')
                ON DUPLICATE KEY UPDATE
                    actif      = 1,
                    date_debut = NOW(),
                    date_fin   = NULL
            ");
            $stmt->execute([
                ':patient_id'  => $patientId,
                ':docteur_id'  => $docteurId,
                ':type_acces'  => $typeAcces,
                ':accorde_par' => $patientId,
            ]);

            Auth::logAction(
                'insert', 'acces_patients', $docteurId,
                Auth::userId(), Auth::roleCode(),
                "Accès accordé au docteur #$docteurId pour patient #$patientId",
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            );

            return true;
        } catch (PDOException $e) {
            error_log("Erreur accorderAcces : " . $e->getMessage());
            return false;
        }
    }

    // ----------------------------------------------------------
    // Révoquer accès d'un docteur (fait par le patient)
    // ----------------------------------------------------------
    public static function revoquerAccesDocteur(
        int $patientId,
        int $docteurId
    ): bool {
        // Seul le patient concerné ou l'admin peut révoquer
        if (!Auth::estAdmin() && $patientId !== Auth::userId()) {
            return false;
        }

        global $pdo;
        $stmt = $pdo->prepare("
            UPDATE acces_patients
            SET    actif    = 0,
                   date_fin = NOW()
            WHERE  patient_id  = :patient_id
              AND  entite_id   = :docteur_id
              AND  type_entite = 'docteur'
        ");
        $stmt->execute([
            ':patient_id' => $patientId,
            ':docteur_id' => $docteurId,
        ]);

        Auth::logAction(
            'update', 'acces_patients', $docteurId,
            Auth::userId(), Auth::roleCode(),
            "Accès révoqué — docteur #$docteurId patient #$patientId",
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        );

        return true;
    }

    // ----------------------------------------------------------
    // Bloquer l'accès avec log
    // ----------------------------------------------------------
    public static function bloquer(string $msg = "Accès non autorisé."): void {
        Auth::logAction(
            'consultation', 'resource_guard', 0,
            Auth::userId(), Auth::roleCode(),
            $msg,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'echec'
        );
        http_response_code(403);
        include __DIR__ . '/../views/errors/403.php';
        exit;
    }
}