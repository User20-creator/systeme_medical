<?php
// ============================================================
//  middleware/Auth.php — ADAPTÉ AU PROJET EXISTANT
//
//  ✅ Utilise $pdo global depuis config.php (pas de getDB())
//  ✅ Respecte les variables de session existantes :
//       - connexion1.php → $_SESSION['user_id'], 'user_type' = 'patient'
//       - connexion2.php → $_SESSION['medecin_id'], 'user_type' = 'infirmier'/'docteur'
//       - admin          → $_SESSION['admin_id'], 'user_type' = 'admin'
//
//  USAGE dans chaque page :
//       require_once 'config.php';
//       require_once 'middleware/Auth.php';
//       Auth::exigerConnexion();
//       Auth::exigerPermission('dossiers.write.infirmier');
// ============================================================

class Auth {

    // ----------------------------------------------------------
    // Récupérer $pdo depuis le scope global
    // ----------------------------------------------------------
    private static function pdo(): PDO {
        global $pdo;
        return $pdo;
    }

    // ----------------------------------------------------------
    // Est connecté ? (compatible toutes les pages existantes)
    // ----------------------------------------------------------
    public static function estConnecte(): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Vérifier selon le type d'utilisateur
        $userType = $_SESSION['user_type'] ?? '';

        switch ($userType) {
            case 'patient':
                return !empty($_SESSION['user_id']);

            case 'infirmier':
            case 'docteur':
                return !empty($_SESSION['medecin_id']);

            case 'admin':
                return !empty($_SESSION['admin_id']);

            default:
                return false;
        }
    }

    // ----------------------------------------------------------
    // Exiger connexion — redirige sinon
    // ----------------------------------------------------------
    public static function exigerConnexion(): void {
        if (!self::estConnecte()) {
            // Rediriger vers la bonne page selon le contexte
            $userType = $_SESSION['user_type'] ?? '';
            if ($userType === 'patient') {
                header('Location: ../connexion1.php');
            } else {
                header('Location: ../connexion2.php');
            }
            exit;
        }

        // Expiration session après 2h d'inactivité
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 7200) {
            self::logout();
        }
        $_SESSION['login_time'] = time();
    }

    // ----------------------------------------------------------
    // Déconnexion — compatible toutes les sessions
    // ----------------------------------------------------------
    public static function logout(): void {
        $userId   = self::userId();
        $userType = $_SESSION['user_type'] ?? 'patient';

        self::logAction(
            'consultation', 'session', $userId,
            $userId, $userType,
            'Déconnexion',
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        );

        $_SESSION = [];
        session_destroy();
        header('Location: connexion1.php');
        exit;
    }

    // ----------------------------------------------------------
    // Vérifier une permission via la BDD
    // ----------------------------------------------------------
    public static function peutFaire(string $permission): bool {
        if (!self::estConnecte()) return false;

        $roleId = self::roleId();
        if (!$roleId) return false;

        $stmt = self::pdo()->prepare("
            SELECT COUNT(*) AS total
            FROM   role_permissions rp
            JOIN   permissions p ON p.id = rp.permission_id
            WHERE  rp.role_id = :role_id
              AND  p.code     = :perm
        ");
        $stmt->execute([
            ':role_id' => $roleId,
            ':perm'    => $permission,
        ]);
        return (bool) $stmt->fetch()['total'];
    }

    // ----------------------------------------------------------
    // Exiger une permission — bloque avec 403 sinon
    // ----------------------------------------------------------
    public static function exigerPermission(string $permission): void {
        self::exigerConnexion();

        if (!self::peutFaire($permission)) {
            self::logAction(
                'consultation', 'permissions', 0,
                self::userId(), self::roleCode(),
                "Accès refusé — permission manquante : $permission",
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'echec'
            );
            http_response_code(403);
            include __DIR__ . '/../views/errors/403.php';
            exit;
        }
    }

    // ----------------------------------------------------------
    // Exiger un rôle précis
    // ----------------------------------------------------------
    public static function exigerRole(string ...$roles): void {
        self::exigerConnexion();

        if (!in_array(self::roleCode(), $roles, true)) {
            http_response_code(403);
            include __DIR__ . '/../views/errors/403.php';
            exit;
        }
    }

    // ----------------------------------------------------------
    // Vérification CSRF — à appeler sur tout formulaire POST
    // ----------------------------------------------------------
    public static function verifierCSRF(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(419);
            die('Token CSRF invalide. Veuillez recharger la page.');
        }
    }

    // ----------------------------------------------------------
    // Générer un token CSRF (à appeler une fois par page)
    // ----------------------------------------------------------
    public static function genererCSRF(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // ----------------------------------------------------------
    // Logger dans logs_blockchain
    // ----------------------------------------------------------
    public static function logAction(
        string $typeAction,
        string $table,
        int    $recordId,
        int    $userId,
        string $typeUser,
        string $details,
        string $ip,
        string $status = 'succes'
    ): void {
        try {
            $stmt = self::pdo()->prepare("
                INSERT INTO logs_blockchain
                    (transaction_id, type_action, table_concernee,
                     record_id, utilisateur_id, type_utilisateur,
                     timestamp_action, status, details, ip_address)
                VALUES
                    (CONCAT('0x', SHA2(CONCAT(NOW(),:uid,:tbl,RAND()), 256)),
                     :action, :table, :record,
                     :user_id, :user_type, NOW(),
                     :status, :details, :ip)
            ");
            $stmt->execute([
                ':uid'       => $userId,
                ':tbl'       => $table,
                ':action'    => $typeAction,
                ':table'     => $table,
                ':record'    => $recordId,
                ':user_id'   => $userId,
                ':user_type' => $typeUser,
                ':status'    => $status,
                ':details'   => $details,
                ':ip'        => $ip,
            ]);
        } catch (PDOException $e) {
            error_log("Erreur log_action : " . $e->getMessage());
        }
    }

    // ----------------------------------------------------------
    // Toutes les permissions du rôle connecté
    // ----------------------------------------------------------
    public static function mesPermissions(): array {
        if (!self::estConnecte()) return [];

        $roleId = self::roleId();
        if (!$roleId) return [];

        $stmt = self::pdo()->prepare("
            SELECT p.code
            FROM   role_permissions rp
            JOIN   permissions p ON p.id = rp.permission_id
            WHERE  rp.role_id = :role_id
        ");
        $stmt->execute([':role_id' => $roleId]);
        return array_column($stmt->fetchAll(), 'code');
    }

    // ----------------------------------------------------------
    // GETTERS — récupère les infos selon le type d'utilisateur
    // ----------------------------------------------------------

    // ID de l'utilisateur connecté
    public static function userId(): int {
        $type = $_SESSION['user_type'] ?? '';
        return match($type) {
            'patient'            => (int)($_SESSION['user_id']    ?? 0),
            'infirmier','docteur'=> (int)($_SESSION['medecin_id'] ?? 0),
            'admin'              => (int)($_SESSION['admin_id']   ?? 0),
            default              => 0,
        };
    }

    // Nom de l'utilisateur connecté
    public static function userNom(): string {
        $type = $_SESSION['user_type'] ?? '';
        return match($type) {
            'patient'            => $_SESSION['user_nom']    ?? '',
            'infirmier','docteur'=> ($_SESSION['medecin_prenom'] ?? '') . ' ' . ($_SESSION['medecin_nom'] ?? ''),
            'admin'              => ($_SESSION['admin_prenom'] ?? '') . ' ' . ($_SESSION['admin_nom'] ?? ''),
            default              => '',
        };
    }

    // Type/rôle de l'utilisateur (patient, infirmier, docteur, admin)
    public static function roleCode(): string {
        return $_SESSION['user_type'] ?? '';
    }

    // role_id depuis la BDD selon le type
    public static function roleId(): int {
        $roleCode = self::roleCode();
        if (!$roleCode) return 0;

        // Chercher en cache session d'abord
        if (!empty($_SESSION['role_id'])) {
            return (int)$_SESSION['role_id'];
        }

        // Sinon chercher en BDD
        try {
            $stmt = self::pdo()->prepare("
                SELECT id FROM roles WHERE code = :code LIMIT 1
            ");
            $stmt->execute([':code' => $roleCode]);
            $role = $stmt->fetch();
            $roleId = (int)($role['id'] ?? 0);

            // Mettre en cache
            $_SESSION['role_id'] = $roleId;
            return $roleId;
        } catch (PDOException $e) {
            return 0;
        }
    }

    // ID de l'hôpital selon le type
    public static function hopitalId(): ?int {
        $type = $_SESSION['user_type'] ?? '';
        return match($type) {
            'patient'            => (int)($_SESSION['user_hopital_id']         ?? 0) ?: null,
            'infirmier','docteur'=> (int)($_SESSION['medecin_hopital_principal_id'] ?? 0) ?: null,
            default              => null,
        };
    }

    // Token CSRF
    public static function csrfToken(): string {
        return self::genererCSRF();
    }

    // ----------------------------------------------------------
    // Helpers rôles — raccourcis pratiques dans les vues
    // ----------------------------------------------------------
    public static function estPatient():   bool { return self::roleCode() === 'patient';   }
    public static function estInfirmier(): bool { return self::roleCode() === 'infirmier'; }
    public static function estDocteur():   bool { return self::roleCode() === 'docteur';   }
    public static function estAdmin():     bool { return self::roleCode() === 'admin';     }
}