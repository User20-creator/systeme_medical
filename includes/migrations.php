<?php
// includes/migrations.php — Ajouts de colonnes idempotents.
// Appelés depuis les pages qui en ont besoin. Coût négligeable.

if (!function_exists('add_column_if_missing')) {
    function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS n
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :t
                  AND COLUMN_NAME = :c
            ");
            $stmt->execute([':t' => $table, ':c' => $column]);
            $exists = (int)($stmt->fetch()['n'] ?? 0) > 0;
            if (!$exists) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (PDOException $e) {
            error_log("add_column_if_missing $table.$column: " . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_medecin_traitant_column')) {
    function ensure_medecin_traitant_column(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) AS n
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'patients'
                  AND COLUMN_NAME  = 'medecin_traitant_id'
            ");
            $exists = (int)($stmt->fetch()['n'] ?? 0) > 0;
            if (!$exists) {
                $pdo->exec("
                    ALTER TABLE patients
                    ADD COLUMN medecin_traitant_id INT(11) DEFAULT NULL
                    COMMENT 'FK → docteurs(id) — médecin traitant'
                ");
                try {
                    $pdo->exec("
                        ALTER TABLE patients
                        ADD CONSTRAINT fk_patient_medecin_traitant
                        FOREIGN KEY (medecin_traitant_id) REFERENCES docteurs(id)
                        ON DELETE SET NULL
                    ");
                } catch (PDOException $e) {
                    error_log('FK medecin_traitant skipped: ' . $e->getMessage());
                }
            }
            $checked = true;
        } catch (PDOException $e) {
            error_log('ensure_medecin_traitant_column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_extended_columns')) {
    /**
     * Ajoute les colonnes nécessaires pour :
     * - photo de profil patient
     * - consultations enrichies (symptômes, injections, décision finale, workflow infirmier→docteur)
     * - prescriptions structurées (dates et heures de prise)
     * - docteur en service (toggle)
     */
    function ensure_extended_columns(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;

        // Patient — photo profil
        add_column_if_missing($pdo, 'patients', 'photo',
            "VARCHAR(255) DEFAULT NULL COMMENT 'Chemin relatif vers photo profil'");

        // Dossiers médicaux — consultation détaillée + workflow
        add_column_if_missing($pdo, 'dossiers_medicaux', 'symptomes',
            "TEXT DEFAULT NULL COMMENT 'Symptômes déclarés par le patient'");
        add_column_if_missing($pdo, 'dossiers_medicaux', 'injections_administrees',
            "TEXT DEFAULT NULL COMMENT 'Injections / médicaments administrés sur place'");
        add_column_if_missing($pdo, 'dossiers_medicaux', 'decision_finale',
            "TEXT DEFAULT NULL COMMENT 'Décision finale du docteur'");
        add_column_if_missing($pdo, 'dossiers_medicaux', 'envoye_au_docteur_id',
            "INT(11) DEFAULT NULL COMMENT 'FK → docteurs(id) — docteur destinataire'");
        add_column_if_missing($pdo, 'dossiers_medicaux', 'date_envoi_docteur',
            "DATETIME DEFAULT NULL COMMENT 'Quand l infirmier a envoyé au docteur'");
        add_column_if_missing($pdo, 'dossiers_medicaux', 'statut_prise_en_charge',
            "ENUM('en_attente','envoye','pris_en_charge','traite') DEFAULT 'en_attente'");

        // Prescriptions — dates et heures de prise
        add_column_if_missing($pdo, 'prescriptions', 'date_debut',
            "DATE DEFAULT NULL COMMENT 'Date de début de prise'");
        add_column_if_missing($pdo, 'prescriptions', 'date_fin',
            "DATE DEFAULT NULL COMMENT 'Date de fin de prise'");
        add_column_if_missing($pdo, 'prescriptions', 'heures_prise',
            "VARCHAR(255) DEFAULT NULL COMMENT 'JSON: heures de prise quotidiennes ex: [\"08:00\",\"13:00\",\"20:00\"]'");

        // Docteurs — toggle en service
        add_column_if_missing($pdo, 'docteurs', 'en_service',
            "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Docteur actuellement de garde / en service'");

        $checked = true;
    }
}
