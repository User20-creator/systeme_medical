<?php
/**
 * Modèle de configuration locale / production.
 *
 *  1. Copier ce fichier en `config.local.php`
 *  2. Remplir avec les vraies valeurs fournies par ton hébergeur
 *  3. NE JAMAIS commiter `config.local.php` (déjà dans .gitignore)
 *
 * Si config.local.php n'existe pas, config.php utilise les valeurs
 * par défaut XAMPP (root / mot de passe vide).
 */

$host     = 'sqlXXX.infinityfree.com';   // hôte MySQL fourni par InfinityFree
$dbname   = 'if0_XXXXXXXX_medchain';     // nom complet de la BDD
$username = 'if0_XXXXXXXX';              // utilisateur MySQL
$password = 'TON_MOT_DE_PASSE_BDD';      // mot de passe BDD
