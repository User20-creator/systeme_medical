<?php
// config.php - Connexion BDD + helpers de sécurité
//
// Helpers fournis :
//   csrf_token()       → génère/retourne le token CSRF de la session
//   csrf_field()       → renvoie l'<input hidden> à coller dans tout <form>
//   csrf_check()       → vérifie le token (POST ou en-tête X-CSRF-Token)
//   record_login()     → enregistre l'heure de connexion en session
//   register_attempt() → +1 échec login dans login_attempts
//   too_many_attempts()→ true si IP dépasse 5 échecs en 15 min
//   clear_attempts()   → reset après login réussi

// ─── Session sécurisée ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    // Activer Secure si on est en HTTPS
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) == 443;
    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// ─── Connexion BDD ─────────────────────────────────────────────────
$host = 'localhost';
$dbname = 'systeme_medical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log("Erreur de connexion MySQL : " . $e->getMessage());
    die("Erreur de connexion à la base de données. Veuillez réessayer plus tard.");
}

// MySQLi optionnel (legacy)
$mysqli = new mysqli($host, $username, $password, $dbname);
if ($mysqli->connect_error) {
    error_log("Erreur MySQLi : " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");

// ─── Helpers CSRF ──────────────────────────────────────────────────
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_check(): bool
{
    $token = $_POST['csrf_token']
          ?? $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? '';
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!csrf_check()) {
        http_response_code(419);
        die('Jeton CSRF invalide. Veuillez recharger la page et réessayer.');
    }
}

// ─── Helpers session ───────────────────────────────────────────────
function record_login(): void
{
    $_SESSION['login_time'] = time();
}

function session_expired(int $maxIdleSeconds = 7200): bool
{
    if (!isset($_SESSION['login_time'])) return false;
    return (time() - (int)$_SESSION['login_time']) > $maxIdleSeconds;
}

// ─── Rate-limit sur les pages de connexion ─────────────────────────
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Crée la table login_attempts si elle n'existe pas.
 * Idempotent — coût négligeable.
 */
function _ensure_login_attempts_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                ip VARCHAR(45) NOT NULL,
                last_try DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                attempts INT NOT NULL DEFAULT 0,
                PRIMARY KEY (ip)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $checked = true;
    } catch (PDOException $e) {
        error_log('login_attempts table error : ' . $e->getMessage());
    }
}

function register_attempt(): void
{
    global $pdo;
    _ensure_login_attempts_table($pdo);
    $ip = client_ip();
    try {
        $pdo->prepare("
            INSERT INTO login_attempts (ip, attempts, last_try)
            VALUES (:ip, 1, NOW())
            ON DUPLICATE KEY UPDATE
              attempts = IF(last_try < NOW() - INTERVAL 15 MINUTE, 1, attempts + 1),
              last_try = NOW()
        ")->execute([':ip' => $ip]);
    } catch (PDOException $e) {
        error_log('register_attempt : ' . $e->getMessage());
    }
}

/**
 * @return bool true si plus de 5 échecs dans les 15 dernières minutes pour cette IP
 */
function too_many_attempts(int $max = 5, int $minutes = 15): bool
{
    global $pdo;
    _ensure_login_attempts_table($pdo);
    $ip = client_ip();
    try {
        $stmt = $pdo->prepare("
            SELECT attempts, last_try
            FROM login_attempts
            WHERE ip = :ip AND last_try >= NOW() - INTERVAL :mins MINUTE
        ");
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':mins', $minutes, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row && (int)$row['attempts'] >= $max;
    } catch (PDOException $e) {
        return false;
    }
}

function clear_attempts(): void
{
    global $pdo;
    _ensure_login_attempts_table($pdo);
    $ip = client_ip();
    try {
        $pdo->prepare("DELETE FROM login_attempts WHERE ip = :ip")
            ->execute([':ip' => $ip]);
    } catch (PDOException $e) {
        // silencieux
    }
}
