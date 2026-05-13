-- ──────────────────────────────────────────────────────────────────────
--  bootstrap_demo.sql — Initialisation des données de base MedChain.
--  À importer dans phpMyAdmin APRÈS schema_dump.sql et migration_hashchain.sql.
--  Idempotent (INSERT IGNORE) — peut être re-exécuté sans risque.
--
--  Crée :
--   • 5 rôles (admin, medecin, docteur, infirmier, patient)
--   • 4 hôpitaux du Bénin (CNHU, CHU Parakou, Pasteur, Hôpital de Zone)
--   • 1 compte admin (email : admin@medchain.bj)
--
--  ⚠️ Mot de passe admin haché ci-dessous (bcrypt).
--      Le mot de passe en clair n'est PAS dans ce fichier public.
--      Le propriétaire de l'instance le détient hors du repo.
--      Pour le changer : UPDATE admin SET mot_de_passe = '<nouveau_hash>' WHERE email = 'admin@medchain.bj';
-- ──────────────────────────────────────────────────────────────────────

-- ── 1. Rôles ──────────────────────────────────────────────────────────
INSERT IGNORE INTO `roles` (`code`, `libelle`, `description`) VALUES
('admin',     'Administrateur', 'Accès complet à la plateforme'),
('medecin',   'Médecin',        'Médecin praticien'),
('docteur',   'Docteur',        'Docteur en hôpital'),
('infirmier', 'Infirmier',      'Infirmier en hôpital'),
('patient',   'Patient',        'Patient enregistré');

-- ── 2. Hôpitaux ───────────────────────────────────────────────────────
INSERT IGNORE INTO `hopitaux`
  (`nom`, `ville`, `telephone`, `email`, `code_blockchain`, `statut`,
   `nombre_lits`, `nombre_medecins`, `nombre_ambulances`, `nombre_labos`, `nombre_etudiants`, `Image`)
VALUES
('CNHU-HKM',         'Cotonou', '+229 21 30 11 84', 'contact@cnhu.bj',          'HOP-0x1', 'actif', 350, 80, 8, 4, 120, 'images/cnhu.jpeg'),
('CHU de Parakou',   'Parakou', '+229 23 61 13 30', 'contact@chu-parakou.bj',   'HOP-0x2', 'actif', 200, 45, 5, 3,  80, 'images/cnhu_parakou.jpg'),
('Institut Pasteur', 'Cotonou', '+229 21 33 36 91', 'contact@pasteur.bj',       'HOP-0x3', 'actif',  80, 20, 2, 2,  30, 'images/pasteur.jpg'),
('Hôpital de Zone',  'Calavi',  '+229 21 35 00 00', 'contact@zone-calavi.bj',   'HOP-0x4', 'actif', 120, 30, 3, 2,  50, 'images/zone.jpg');

-- ── 3. Admin par défaut ───────────────────────────────────────────────
SET @admin_role_id := (SELECT id FROM `roles` WHERE `code` = 'admin' LIMIT 1);

INSERT IGNORE INTO `admin` (`nom`, `prenom`, `email`, `mot_de_passe`, `role_id`, `statut`)
VALUES
('MedChain', 'Admin', 'admin@medchain.bj',
 '$2y$10$wwsDqeuUgUnsmoZpBsTXCeBEBXOa7AHDY6WYQivkzFkja4NxUksBm',
 @admin_role_id, 'actif');
