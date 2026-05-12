<?php
/**
 * repair_chain.php — Recalcule en cascade les block_hash de la chaîne.
 *
 * À EXÉCUTER UNE FOIS après les corrections d'audit, pour réparer la chaîne
 * dont l'intégrité a été cassée par la corruption ENUM silencieuse de MySQL
 * (les valeurs originales 'LOGIN_ADMIN', 'docteur', etc. ont été tronquées
 * en '' à l'INSERT, alors que les block_hash avaient été calculés sur les
 * valeurs originales).
 *
 * USAGE : exécuter en CLI uniquement.
 *   "C:/xamppp/php/php.exe" sql/repair_chain.php
 *
 * Effet : les block_hash deviennent cohérents avec les données stockées.
 * verifyChain() retournera valid=true ensuite.
 *
 * NOTE : on perd la garantie d'immuabilité historique. C'est acceptable
 * UNIQUEMENT pour ce projet de démo, après une migration schéma.
 */

if (PHP_SAPI !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('CLI ou localhost uniquement.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/hash_chain.php';

$rows = $pdo->query("SELECT * FROM logs_blockchain WHERE block_hash IS NOT NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);

if (!$total) {
    echo "Chaîne vide — rien à réparer.\n";
    exit(0);
}

$prevHash = HashChain::GENESIS_HASH;
$repaired = 0;

$pdo->beginTransaction();
try {
    foreach ($rows as $i => $row) {
        $details = json_decode($row['details'] ?: '[]', true) ?: [];
        $blockData = [
            'type'      => $row['type_action'],
            'record_id' => $row['record_id'],
            'user_id'   => (int)$row['utilisateur_id'],
            'user_type' => $row['type_utilisateur'],
            'timestamp' => $row['timestamp_action'],
            'details'   => $details,
            'table'     => $row['table_concernee'] ?: '',
        ];
        $newHash = HashChain::computeHash($prevHash, $blockData);

        if ($newHash !== $row['block_hash'] || $prevHash !== $row['prev_hash']) {
            $upd = $pdo->prepare("
                UPDATE logs_blockchain
                   SET prev_hash = :prev,
                       block_hash = :block,
                       block_number = :num
                 WHERE id = :id
            ");
            $upd->execute([
                ':prev'  => $prevHash,
                ':block' => $newHash,
                ':num'   => $i + 1,
                ':id'    => $row['id'],
            ]);
            $repaired++;
        }
        $prevHash = $newHash;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERREUR : " . $e->getMessage() . "\n");
    exit(1);
}

echo "Chaîne réparée : $repaired / $total blocs ré-écrits.\n";
echo "Vérification : ";
$v = HashChain::verifyChain();
echo ($v['valid'] ? 'OK' : 'ECHEC') . " — " . $v['message'] . "\n";
