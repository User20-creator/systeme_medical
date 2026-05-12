-- ============================================================
-- Migration : élargir les ENUMs corrompus silencieusement
-- À exécuter une fois dans phpMyAdmin sur la base systeme_medical
-- ============================================================

-- 1) logs_blockchain.status — ajouter 'success' (variante anglaise utilisée dans HashChain)
ALTER TABLE logs_blockchain
  MODIFY COLUMN status ENUM('succes','echec','success','error') NOT NULL;

-- 2) logs_blockchain.type_action — ajouter les types métier réellement utilisés par le code
ALTER TABLE logs_blockchain
  MODIFY COLUMN type_action ENUM(
    'insert','update','delete','consultation',
    'LOGIN','LOGIN_FAILED','LOGIN_ADMIN',
    'CREATE_PATIENT','CREATE_DOCTEUR','CREATE_INFIRMIER',
    'UPDATE_PROFIL','UPDATE_DOSSIER',
    'GRANT_ACCESS','REVOKE_ACCESS',
    'CREATE_DOSSIER','CREATE_PRESCRIPTION'
  ) NOT NULL;

-- 3) logs_blockchain.type_utilisateur — ajouter docteur, infirmier, personnel, system
ALTER TABLE logs_blockchain
  MODIFY COLUMN type_utilisateur ENUM(
    'patient','medecin','admin','hopital',
    'docteur','infirmier','personnel','system'
  ) NOT NULL;

-- 4) infirmiers.fonction — élargir aux valeurs utilisées par creer_infirmier.php
ALTER TABLE infirmiers
  MODIFY COLUMN fonction ENUM(
    'accueil','soins','urgence','chef','pediatrie','bloc'
  ) NOT NULL DEFAULT 'accueil';

-- 5) Réparer les lignes existantes qui ont status='' à cause du bug
UPDATE logs_blockchain
   SET status = 'success'
 WHERE status = '' OR status IS NULL;

-- ============================================================
-- Après cette migration, HashChain et tous les controllers
-- pourront enregistrer leurs logs sans corruption silencieuse.
-- ============================================================
