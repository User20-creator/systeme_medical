<?php
require_once 'config.php';
require_once 'includes/hash_chain.php';

// Sécurité minimale : seulement accessible en local pour le seeding initial.
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'])) {
    http_response_code(403);
    exit('Accès local uniquement.');
}

// Liste des docteurs de démonstration à insérer
$docteurs = [
    [
        'nom' => 'Dubois',
        'prenom' => 'Martin',
        'specialite' => 'Médecin généraliste',
        'numero_licence' => 'MED-BJ-2024-001234',
        'telephone' => '+229 01 23 45 67',
        'email' => 'martin.dubois@medical.bj',
    ],
    [
        'nom' => 'Bernard',
        'prenom' => 'Sophie',
        'specialite' => 'Cardiologue',
        'numero_licence' => 'MED-BJ-2024-001235',
        'telephone' => '+229 98 76 54 32',
        'email' => 'sophie.bernard@medical.bj',
    ],
    [
        'nom' => 'Koffi',
        'prenom' => 'Alain',
        'specialite' => 'Dermatologue',
        'numero_licence' => 'MED-BJ-2024-001236',
        'telephone' => '+229 11 22 33 44',
        'email' => 'alain.koffi@medical.bj',
    ],
];

$mdpDefault = 'password123';

echo "<h2>Insertion des docteurs de démonstration</h2>";

foreach ($docteurs as $doc) {
    try {
        // Vérifier si l'email existe déjà
        $stmt = $pdo->prepare("SELECT id FROM docteurs WHERE email = ?");
        $stmt->execute([$doc['email']]);

        if ($stmt->fetch()) {
            echo "<p style='color:orange'>⚠️ Le docteur {$doc['email']} existe déjà.</p>";
            continue;
        }

        $blockchain = HashChain::generateIdentifier('DOC');
        $signature  = HashChain::sign($doc['email'] . $doc['numero_licence'] . time());
        $motChiffre = password_hash($mdpDefault, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO docteurs (
                identifiant_blockchain, nom, prenom, specialite,
                numero_licence, telephone, email, signature_numerique,
                statut, mot_de_passe, role_id
            ) VALUES (
                :blockchain, :nom, :prenom, :specialite,
                :licence, :tel, :email, :signature,
                'actif', :mdp,
                (SELECT id FROM roles WHERE code = 'docteur')
            )
        ");

        $stmt->execute([
            ':blockchain' => $blockchain,
            ':nom'        => $doc['nom'],
            ':prenom'     => $doc['prenom'],
            ':specialite' => $doc['specialite'],
            ':licence'    => $doc['numero_licence'],
            ':tel'        => $doc['telephone'],
            ':email'      => $doc['email'],
            ':signature'  => $signature,
            ':mdp'        => $motChiffre,
        ]);

        echo "<p style='color:green'>✅ Dr. {$doc['prenom']} {$doc['nom']} inséré.</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ Erreur : " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<hr>";
echo "<h3>📋 Identifiants de connexion (mot de passe : <code>$mdpDefault</code>) :</h3><ul>";
foreach ($docteurs as $doc) {
    echo "<li><strong>Dr. {$doc['prenom']} {$doc['nom']}</strong> — {$doc['email']}</li>";
}
echo "</ul>";
echo "<p><a href='connexion2.php'>→ Aller à la page de connexion</a></p>";
