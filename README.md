# MedChain — Système national de gestion des dossiers médicaux

Plateforme web de centralisation et de partage sécurisé des dossiers médicaux pour les structures hospitalières du Bénin. Chaque dossier patient est unique, traçable et accessible en temps réel par les soignants autorisés, avec une couche d'intégrité inspirée des chaînes de blocs (hash chaining) pour les journaux d'activité.

## Fonctionnalités principales

- **5 rôles distincts** : administrateur, médecin, docteur, infirmier, patient — chacun avec son tableau de bord dédié.
- **Dossier patient unifié** : antécédents, consultations, prescriptions, résultats d'examens, ordonnances.
- **Gestion des accès** : le patient autorise/révoque l'accès à son dossier pour un médecin ou un hôpital donné.
- **Transferts inter-hôpitaux** : prise en charge du patient d'une structure à une autre sans perte d'information.
- **Journal d'audit blockchain-like** : chaque action critique est consignée dans `logs_blockchain` avec `prev_hash` + `block_hash` — toute altération brise la chaîne (cf. `includes/hash_chain.php`).
- **Statistiques** : tableau de bord admin avec indicateurs en temps réel.
- **Sécurité** : protection CSRF sur tous les formulaires, rate-limiting des tentatives de login (5 essais / 15 min / IP), sessions HttpOnly + SameSite, mots de passe `password_hash()`.

## Stack technique

- **Backend** : PHP 8+ (vanilla, sans framework)
- **Base de données** : MySQL / MariaDB
- **Frontend** : HTML5 + CSS3 + JavaScript (vanilla, animations CSS custom)
- **Serveur** : XAMPP (Apache + MySQL)

## Installation

### Prérequis
- XAMPP (Apache + MySQL + PHP 8 ou supérieur)
- Un navigateur moderne

### Étapes

1. **Cloner le repo** dans le dossier `htdocs` de XAMPP :
   ```bash
   cd C:/xampp/htdocs
   git clone https://github.com/User20-creator/systeme_medical.git
   ```

2. **Démarrer Apache + MySQL** depuis le panneau XAMPP.

3. **Créer la base de données** :
   - Ouvrir phpMyAdmin (`http://localhost/phpmyadmin`)
   - Créer une base nommée `systeme_medical` (UTF-8 utf8mb4)
   - Importer `sql/schema_dump.sql`
   - Appliquer la migration : importer `sql/migration_hashchain.sql`

4. **Vérifier la configuration** dans `config.php` (par défaut adapté à XAMPP : `host=localhost`, `user=root`, `password=''`).

5. **(Optionnel) Charger des données de test** :
   ```
   http://localhost/systeme_medical/seed_data.php
   http://localhost/systeme_medical/seed_ordonnances.php
   ```

6. **Accéder à l'application** : `http://localhost/systeme_medical/`

## Structure du projet

```
systeme_medical/
├── index.php                    # Page d'accueil publique
├── config.php                   # Connexion BDD + helpers CSRF / rate-limit
├── connexion1.php               # Connexion patient
├── connexion2.php               # Connexion personnel (admin/médecin/infirmier)
├── dashboard_*.php              # Tableaux de bord par rôle
├── creer_*.php                  # Création de comptes (patient, docteur, infirmier)
├── gestion_acces.php            # Gestion des autorisations d'accès au dossier
├── transferer_patient.php       # Transfert inter-hôpitaux
├── prescriptions.php            # Saisie et historique des prescriptions
├── consultation.php             # Saisie d'une consultation
├── logs.php                     # Journal d'audit (chaîne de hashs)
├── statistiques.php             # Stats admin
├── includes/
│   ├── hash_chain.php           # Implémentation de la chaîne de hashs
│   ├── migrations.php           # Migrations automatiques au boot
│   ├── header_*.php             # Templates header public / dashboard
│   └── footer_*.php             # Templates footer
├── middleware/
│   ├── Auth.php                 # Garde d'authentification + autorisation par rôle
│   └── ResourceGuard.php        # Vérification d'accès aux ressources
├── sql/
│   ├── schema_dump.sql          # Schéma initial
│   ├── migration_hashchain.sql  # Ajout colonnes prev_hash / block_hash
│   ├── fix_enums.sql            # Correctifs ENUM
│   └── repair_chain.php         # Outil de réparation de la chaîne
└── assets/                      # CSS, JS, thèmes
```

## Sécurité — notes importantes

- `config.php` est livré avec les credentials XAMPP par défaut (`root` / mot de passe vide). **Pour un déploiement réel, externaliser ces secrets** (variables d'environnement ou `config.local.php` non versionné).
- Les seeds (`seed_data.php`, `seed_ordonnances.php`, `inserer_medecins.php`) contiennent des **données fictives uniquement** — aucune information patient réelle.
- L'intégrité de la chaîne d'audit peut être vérifiée à tout moment via `HashChain::verifyChain()` ou la page admin dédiée.

## Statut

Projet académique / preuve de concept — pas encore déployé en production. Contributions et retours bienvenus.
