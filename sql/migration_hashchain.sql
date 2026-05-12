-- ============================================================
-- Migration : activation de la vraie chaîne de hash blockchain
-- À exécuter une fois dans phpMyAdmin sur la base systeme_medical
-- ============================================================

-- Ajout des colonnes de chaînage
ALTER TABLE logs_blockchain
  ADD COLUMN IF NOT EXISTS prev_hash VARCHAR(64) DEFAULT NULL AFTER transaction_id,
  ADD COLUMN IF NOT EXISTS block_hash VARCHAR(64) DEFAULT NULL AFTER prev_hash,
  ADD COLUMN IF NOT EXISTS block_number INT DEFAULT NULL AFTER block_hash;

-- Index pour accélérer la vérification de chaîne et la recherche par hash
CREATE INDEX IF NOT EXISTS idx_block_hash ON logs_blockchain(block_hash);
CREATE INDEX IF NOT EXISTS idx_block_number ON logs_blockchain(block_number);
CREATE INDEX IF NOT EXISTS idx_prev_hash ON logs_blockchain(prev_hash);

-- ============================================================
-- Après cette migration, HashChain::addBlock() activera
-- automatiquement le chaînage. HashChain::verifyChain() pourra
-- vérifier l'intégrité de toute la chaîne depuis le bloc genèse.
-- ============================================================
