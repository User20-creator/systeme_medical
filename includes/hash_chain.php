<?php
/**
 * HashChain — Vraie chaîne de hash pour les logs blockchain.
 *
 * Principe : chaque log contient le hash du log précédent (prev_hash)
 * et son propre hash (block_hash) calculé à partir de tout son contenu.
 * Modifier un log brise la chaîne de tous les suivants → intégrité prouvée.
 *
 * Usage :
 *   require_once 'includes/hash_chain.php';
 *   HashChain::addBlock('CREATE_PATIENT', $patientId, $userId, 'admin', ['nom' => 'Doe']);
 *   $ok = HashChain::verifyChain();  // true si intègre
 *   $logs = HashChain::getChain(50); // 50 derniers blocs
 *
 * Migration SQL (à exécuter une fois, cf. sql/migration_hashchain.sql) :
 *   ALTER TABLE logs_blockchain
 *     ADD COLUMN prev_hash VARCHAR(64) DEFAULT NULL AFTER transaction_id,
 *     ADD COLUMN block_hash VARCHAR(64) DEFAULT NULL AFTER prev_hash,
 *     ADD COLUMN block_number INT DEFAULT NULL AFTER block_hash;
 */

class HashChain {

    const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private static function pdo(): PDO {
        global $pdo;
        return $pdo;
    }

    // ------------------------------------------------------------
    // Vérifier que la table a les colonnes de chaînage (migration)
    // ------------------------------------------------------------
    public static function isMigrated(): bool {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $cols = self::pdo()->query("SHOW COLUMNS FROM logs_blockchain")->fetchAll(PDO::FETCH_COLUMN);
            $cached = in_array('prev_hash', $cols) && in_array('block_hash', $cols);
        } catch (Exception $e) {
            $cached = false;
        }
        return $cached;
    }

    // ------------------------------------------------------------
    // Récupérer le dernier hash de la chaîne (ou GENESIS si vide)
    // ------------------------------------------------------------
    public static function getLastHash(): string {
        if (!self::isMigrated()) return self::GENESIS_HASH;
        try {
            $stmt = self::pdo()->query("SELECT block_hash FROM logs_blockchain WHERE block_hash IS NOT NULL ORDER BY id DESC LIMIT 1");
            $h = $stmt->fetchColumn();
            return $h ?: self::GENESIS_HASH;
        } catch (Exception $e) {
            return self::GENESIS_HASH;
        }
    }

    // ------------------------------------------------------------
    // Calculer le hash d'un bloc à partir de ses données
    // ------------------------------------------------------------
    public static function computeHash(string $prevHash, array $data): string {
        ksort($data); // ordre stable
        $payload = $prevHash . '|' . json_encode($data, JSON_UNESCAPED_UNICODE);
        return hash('sha256', $payload);
    }

    // ------------------------------------------------------------
    // Ajouter un bloc à la chaîne
    //
    // @param string $type       Ex. 'CREATE_PATIENT', 'UPDATE_DOSSIER', 'LOGIN'
    // @param string|int $recordId  ID de l'enregistrement concerné
    // @param int $userId        ID de l'utilisateur qui agit
    // @param string $userType   patient | docteur | infirmier | admin
    // @param array $details     Données contextuelles (nom, changements, etc.)
    // @param string $tableName  Table concernée (optionnel)
    // @return string            Le block_hash calculé
    // ------------------------------------------------------------
    public static function addBlock(
        string $type,
        $recordId,
        int $userId,
        string $userType,
        array $details = [],
        string $tableName = ''
    ): string {
        $pdo = self::pdo();
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';

        if (!self::isMigrated()) {
            // Fallback : schéma existant sans chaînage
            $transactionId = '0x' . hash('sha256', $timestamp . $userId . mt_rand());
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO logs_blockchain
                      (transaction_id, type_action, table_concernee, record_id, utilisateur_id, type_utilisateur, timestamp_action, status, details, ip_address)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'success', ?, ?)
                ");
                $stmt->execute([$transactionId, $type, $tableName, (string)$recordId, $userId, $userType, $timestamp, json_encode($details, JSON_UNESCAPED_UNICODE), $ip]);
            } catch (PDOException $e) {
                error_log('HashChain::addBlock fallback error: ' . $e->getMessage());
            }
            return $transactionId;
        }

        // Schéma chaîné
        $prevHash = self::getLastHash();
        $blockData = [
            'type' => $type,
            'record_id' => (string)$recordId,
            'user_id' => $userId,
            'user_type' => $userType,
            'timestamp' => $timestamp,
            'details' => $details,
            'table' => $tableName,
        ];
        $blockHash = self::computeHash($prevHash, $blockData);
        $transactionId = '0x' . substr($blockHash, 0, 16);

        try {
            // Récupérer le numéro du prochain bloc
            $nextNum = (int) $pdo->query("SELECT COALESCE(MAX(block_number),0)+1 FROM logs_blockchain")->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO logs_blockchain
                  (transaction_id, prev_hash, block_hash, block_number, type_action, table_concernee, record_id, utilisateur_id, type_utilisateur, timestamp_action, status, details, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', ?, ?)
            ");
            $stmt->execute([
                $transactionId, $prevHash, $blockHash, $nextNum,
                $type, $tableName, (string)$recordId, $userId, $userType,
                $timestamp, json_encode($details, JSON_UNESCAPED_UNICODE), $ip
            ]);
        } catch (PDOException $e) {
            error_log('HashChain::addBlock error: ' . $e->getMessage());
        }

        return $blockHash;
    }

    // ------------------------------------------------------------
    // Vérifier l'intégrité de toute la chaîne
    // @return array ['valid' => bool, 'total' => int, 'broken_at' => ?int, 'message' => string]
    // ------------------------------------------------------------
    public static function verifyChain(): array {
        if (!self::isMigrated()) {
            return ['valid' => true, 'total' => 0, 'broken_at' => null, 'message' => 'Migration non appliquée — vérification désactivée'];
        }
        try {
            $rows = self::pdo()->query("SELECT * FROM logs_blockchain WHERE block_hash IS NOT NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return ['valid' => false, 'total' => 0, 'broken_at' => null, 'message' => 'Erreur BDD : ' . $e->getMessage()];
        }

        $total = count($rows);
        if ($total === 0) {
            return ['valid' => true, 'total' => 0, 'broken_at' => null, 'message' => 'Chaîne vide'];
        }

        $expectedPrev = self::GENESIS_HASH;
        foreach ($rows as $i => $row) {
            // 1. prev_hash doit matcher
            if ($row['prev_hash'] !== $expectedPrev) {
                return [
                    'valid' => false, 'total' => $total, 'broken_at' => $i + 1,
                    'message' => "Bloc #" . ($i + 1) . " — prev_hash incohérent"
                ];
            }
            // 2. recalculer block_hash
            $details = json_decode($row['details'] ?: '[]', true) ?: [];
            $blockData = [
                'type' => $row['type_action'],
                'record_id' => $row['record_id'],
                'user_id' => (int)$row['utilisateur_id'],
                'user_type' => $row['type_utilisateur'],
                'timestamp' => $row['timestamp_action'],
                'details' => $details,
                'table' => $row['table_concernee'] ?: '',
            ];
            $recomputed = self::computeHash($expectedPrev, $blockData);
            if ($recomputed !== $row['block_hash']) {
                return [
                    'valid' => false, 'total' => $total, 'broken_at' => $i + 1,
                    'message' => "Bloc #" . ($i + 1) . " — block_hash falsifié"
                ];
            }
            $expectedPrev = $row['block_hash'];
        }

        return [
            'valid' => true, 'total' => $total, 'broken_at' => null,
            'message' => "Chaîne vérifiée : $total blocs intègres"
        ];
    }

    // ------------------------------------------------------------
    // Récupérer les N derniers blocs
    // ------------------------------------------------------------
    public static function getChain(int $limit = 50, int $offset = 0): array {
        try {
            $stmt = self::pdo()->prepare("SELECT * FROM logs_blockchain ORDER BY id DESC LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    // ------------------------------------------------------------
    // Générer un identifiant unique pour un nouveau compte.
    // L'id est optionnel : pour un nouvel enregistrement on ne le
    // connaît pas encore (il vient du lastInsertId() après l'INSERT).
    // ------------------------------------------------------------
    public static function generateIdentifier(string $prefix, int $id = 0): string {
        return strtoupper($prefix) . '-0x' . substr(hash('sha256', $prefix . $id . microtime(true) . mt_rand()), 0, 16);
    }

    // ------------------------------------------------------------
    // Signature numérique pour un document médical (ordonnance, dossier).
    // Accepte soit un array de payload (avec userId/userType) soit
    // simplement une string (cas legacy : email+licence+time).
    // @return array|string  array complet si $userType fourni, string sinon
    // ------------------------------------------------------------
    public static function sign($payload, int $userId = 0, string $userType = '') {
        $timestamp = date('c');

        if (is_array($payload)) {
            ksort($payload);
            $payloadStr = json_encode($payload, JSON_UNESCAPED_UNICODE);
        } else {
            $payloadStr = (string)$payload;
        }

        $fullHash = hash('sha256', $payloadStr . "|$userId|$userType|$timestamp");

        // Mode legacy : si pas de userType, on retourne juste la string courte
        if ($userType === '') {
            return '0x' . substr($fullHash, 0, 40);
        }

        return [
            'hash' => $fullHash,
            'signature' => '0x' . substr($fullHash, 0, 24),
            'timestamp' => $timestamp,
            'signer_id' => $userId,
            'signer_type' => $userType,
        ];
    }

    // ------------------------------------------------------------
    // Stats pour le dashboard blockchain
    // ------------------------------------------------------------
    public static function getStats(): array {
        $pdo = self::pdo();
        $stats = [
            'total_blocks' => 0,
            'today' => 0,
            'last_block_hash' => self::GENESIS_HASH,
            'last_block_time' => null,
            'migrated' => self::isMigrated(),
        ];
        try {
            $stats['total_blocks'] = (int) $pdo->query("SELECT COUNT(*) FROM logs_blockchain")->fetchColumn();
            $stats['today'] = (int) $pdo->query("SELECT COUNT(*) FROM logs_blockchain WHERE DATE(timestamp_action) = CURDATE()")->fetchColumn();
            $last = $pdo->query("SELECT " . (self::isMigrated() ? 'block_hash' : 'transaction_id') . " AS h, timestamp_action FROM logs_blockchain ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($last) {
                $stats['last_block_hash'] = $last['h'];
                $stats['last_block_time'] = $last['timestamp_action'];
            }
        } catch (PDOException $e) {
            // stats restent à 0
        }
        return $stats;
    }

    // ------------------------------------------------------------
    // Formater un hash pour affichage (0xabc123...xyz789)
    // ------------------------------------------------------------
    public static function shortHash(string $hash, int $head = 6, int $tail = 4): string {
        if (strlen($hash) <= $head + $tail + 3) return $hash;
        return substr($hash, 0, $head) . '...' . substr($hash, -$tail);
    }
}
