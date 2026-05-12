
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `acces_patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `acces_patients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL COMMENT 'FK → patients(id)',
  `entite_id` int(11) NOT NULL COMMENT 'ID du docteur ou infirmier',
  `type_entite` enum('docteur','infirmier') NOT NULL,
  `type_acces` enum('lecture','ecriture','complet') NOT NULL DEFAULT 'lecture',
  `date_debut` datetime NOT NULL DEFAULT current_timestamp(),
  `date_fin` datetime DEFAULT NULL COMMENT 'NULL = pas expiration',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `accorde_par` int(11) NOT NULL COMMENT 'ID du patient ou infirmier qui accorde',
  `motif` varchar(200) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `accorde_par` (`accorde_par`),
  CONSTRAINT `acces_patients_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `acces_patients_ibfk_2` FOREIGN KEY (`accorde_par`) REFERENCES `patients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(50) NOT NULL,
  `prenom` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `statut` enum('actif','inactif') DEFAULT 'actif',
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `derniere_connexion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `admin_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `autorisations_acces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `autorisations_acces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `smart_contract_address` varchar(66) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `entite_autorisee_id` int(11) NOT NULL,
  `type_entite` enum('medecin','hopital') NOT NULL,
  `type_acces` enum('lecture','ecriture','partage') NOT NULL,
  `dossier_type` varchar(50) DEFAULT NULL,
  `date_debut` datetime NOT NULL,
  `date_fin` datetime DEFAULT NULL,
  `actif` tinyint(1) DEFAULT 1,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `transaction_hash` varchar(66) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `smart_contract_address` (`smart_contract_address`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `autorisations_acces_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docteurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docteurs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifiant_blockchain` varchar(64) NOT NULL,
  `nom` varchar(50) NOT NULL,
  `prenom` varchar(50) NOT NULL,
  `specialite` varchar(100) NOT NULL,
  `numero_licence` varchar(50) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `hopital_id` int(11) NOT NULL,
  `signature_numerique` text DEFAULT NULL,
  `role_id` int(11) NOT NULL,
  `statut` enum('actif','inactif','suspendu') DEFAULT 'actif',
  `date_inscription` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifiant_blockchain` (`identifiant_blockchain`),
  UNIQUE KEY `numero_licence` (`numero_licence`),
  UNIQUE KEY `email` (`email`),
  KEY `hopital_id` (`hopital_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `docteurs_ibfk_1` FOREIGN KEY (`hopital_id`) REFERENCES `hopitaux` (`id`),
  CONSTRAINT `docteurs_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dossiers_medicaux`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dossiers_medicaux` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_hash` varchar(66) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `docteur_id` int(11) DEFAULT NULL COMMENT 'FK → docteurs(id) — rempli par le docteur',
  `hopital_id` int(11) NOT NULL,
  `type_document` enum('consultation','analyse','radio','ordonnance','hospitalisation','vaccin') NOT NULL,
  `titre` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `date_creation` datetime NOT NULL,
  `signature_medecin` text NOT NULL,
  `hash_contenu` varchar(64) NOT NULL,
  `bloc_number` int(11) DEFAULT NULL,
  `timestamp_blockchain` datetime DEFAULT NULL,
  `confidentialite` enum('public','medecin','patient_seul') DEFAULT 'medecin',
  `tension` varchar(20) DEFAULT NULL COMMENT 'Ex: 120/80',
  `poids` decimal(5,2) DEFAULT NULL COMMENT 'En kg',
  `temperature` decimal(4,1) DEFAULT NULL COMMENT 'En °C',
  `motif_visite` text DEFAULT NULL COMMENT 'Raison de la consultation',
  `antecedents` text DEFAULT NULL COMMENT 'Antécédents médicaux généraux',
  `allergies` text DEFAULT NULL COMMENT 'Allergies connues',
  `maladies_chroniques` text DEFAULT NULL COMMENT 'Diabète, HTA, etc.',
  `chirurgies_passees` text DEFAULT NULL COMMENT 'Opérations antérieures',
  `cree_par_infirmier` int(11) DEFAULT NULL COMMENT 'FK → infirmiers(id)',
  `modifie_par_docteur` int(11) DEFAULT NULL COMMENT 'FK → docteurs(id)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_hash` (`transaction_hash`),
  KEY `medecin_id` (`docteur_id`),
  KEY `hopital_id` (`hopital_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_date` (`date_creation`),
  CONSTRAINT `dossiers_medicaux_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `dossiers_medicaux_ibfk_2` FOREIGN KEY (`docteur_id`) REFERENCES `infirmiers` (`id`),
  CONSTRAINT `dossiers_medicaux_ibfk_3` FOREIGN KEY (`hopital_id`) REFERENCES `hopitaux` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hopitaux`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hopitaux` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) NOT NULL,
  `ville` varchar(255) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `code_blockchain` varchar(64) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `statut` enum('actif','inactif') DEFAULT 'actif',
  `nombre_lits` int(11) NOT NULL,
  `nombre_medecins` int(11) NOT NULL,
  `nombre_ambulances` int(11) NOT NULL,
  `nombre_labos` int(11) NOT NULL,
  `nombre_etudiants` int(11) NOT NULL,
  `Image` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_blockchain` (`code_blockchain`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `infirmiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `infirmiers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifiant_blockchain` varchar(64) NOT NULL,
  `nom` varchar(50) NOT NULL,
  `prenom` varchar(50) NOT NULL,
  `specialite` varchar(100) NOT NULL,
  `fonction` enum('accueil','soins') NOT NULL DEFAULT 'accueil',
  `numero_licence` varchar(50) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `hopital_principal_id` int(11) DEFAULT NULL,
  `signature_numerique` text DEFAULT NULL,
  `date_inscription` timestamp NOT NULL DEFAULT current_timestamp(),
  `statut` enum('actif','inactif','suspendu') DEFAULT 'actif',
  `role_id` int(11) NOT NULL DEFAULT 2 COMMENT 'FK → roles(id)',
  `mot_de_passe` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifiant_blockchain` (`identifiant_blockchain`),
  UNIQUE KEY `numero_licence` (`numero_licence`),
  KEY `hopital_principal_id` (`hopital_principal_id`),
  KEY `fk_infirmier_role` (`role_id`),
  CONSTRAINT `fk_infirmier_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `infirmiers_ibfk_1` FOREIGN KEY (`hopital_principal_id`) REFERENCES `hopitaux` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `logs_blockchain`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_blockchain` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(66) DEFAULT NULL,
  `prev_hash` varchar(64) DEFAULT NULL,
  `block_hash` varchar(64) DEFAULT NULL,
  `block_number` int(11) DEFAULT NULL,
  `type_action` enum('insert','update','delete','consultation') NOT NULL,
  `table_concernee` varchar(50) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `utilisateur_id` int(11) DEFAULT NULL,
  `type_utilisateur` enum('patient','medecin','admin','hopital') NOT NULL,
  `timestamp_action` datetime DEFAULT current_timestamp(),
  `status` enum('succes','echec') NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_block_hash` (`block_hash`),
  KEY `idx_block_number` (`block_number`),
  KEY `idx_prev_hash` (`prev_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `patients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifiant_blockchain` varchar(64) NOT NULL,
  `nom_utilisateur` varchar(50) DEFAULT NULL,
  `nom` varchar(50) NOT NULL,
  `prenom` varchar(50) NOT NULL,
  `date_naissance` date NOT NULL,
  `sexe` enum('M','F') NOT NULL,
  `groupe_sanguin` varchar(5) DEFAULT NULL,
  `NPI` varchar(20) DEFAULT NULL,
  `adresse` text DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `statut` enum('actif','inactif') NOT NULL DEFAULT 'actif',
  `role_id` int(11) NOT NULL DEFAULT 1 COMMENT 'FK → roles(id)',
  `date_inscription` timestamp NOT NULL DEFAULT current_timestamp(),
  `hopital_reference` int(11) DEFAULT NULL,
  `cle_publique` text DEFAULT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifiant_blockchain` (`identifiant_blockchain`),
  UNIQUE KEY `numero_securite_sociale` (`NPI`),
  UNIQUE KEY `nom_utilisateur` (`nom_utilisateur`),
  KEY `hopital_reference` (`hopital_reference`),
  KEY `fk_patient_role` (`role_id`),
  CONSTRAINT `fk_patient_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`hopital_reference`) REFERENCES `hopitaux` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(100) NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `table_cible` varchar(60) NOT NULL,
  `action` enum('read','write','delete','admin') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prescriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_hash` varchar(66) NOT NULL,
  `dossier_medical_id` int(11) NOT NULL,
  `medicament` varchar(100) NOT NULL,
  `dosage` varchar(50) NOT NULL,
  `frequence` varchar(100) DEFAULT NULL,
  `duree` varchar(50) DEFAULT NULL,
  `date_prescription` date NOT NULL,
  `valide_jusquau` date DEFAULT NULL,
  `renouvelable` tinyint(1) DEFAULT 0,
  `pharmacie_designee` varchar(100) DEFAULT NULL,
  `statut` enum('active','terminee','annulee') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_hash` (`transaction_hash`),
  KEY `dossier_medical_id` (`dossier_medical_id`),
  CONSTRAINT `prescriptions_ibfk_1` FOREIGN KEY (`dossier_medical_id`) REFERENCES `dossiers_medicaux` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `libelle` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transferts_patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transferts_patients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_hash` varchar(66) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `hopital_source` int(11) NOT NULL,
  `hopital_destination` int(11) NOT NULL,
  `docteur_source` int(11) DEFAULT NULL COMMENT 'FK → docteurs(id)',
  `docteur_destination` int(11) DEFAULT NULL COMMENT 'FK → docteurs(id)',
  `date_transfert` datetime NOT NULL,
  `motif` text DEFAULT NULL,
  `dossiers_transferes` text DEFAULT NULL,
  `statut` enum('demande','accepte','refuse','complete') DEFAULT 'demande',
  `signature_source` text DEFAULT NULL,
  `signature_destination` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_hash` (`transaction_hash`),
  KEY `patient_id` (`patient_id`),
  KEY `hopital_source` (`hopital_source`),
  KEY `hopital_destination` (`hopital_destination`),
  KEY `medecin_source` (`docteur_source`),
  KEY `medecin_destination` (`docteur_destination`),
  CONSTRAINT `transferts_patients_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `transferts_patients_ibfk_2` FOREIGN KEY (`hopital_source`) REFERENCES `hopitaux` (`id`),
  CONSTRAINT `transferts_patients_ibfk_3` FOREIGN KEY (`hopital_destination`) REFERENCES `hopitaux` (`id`),
  CONSTRAINT `transferts_patients_ibfk_4` FOREIGN KEY (`docteur_source`) REFERENCES `infirmiers` (`id`),
  CONSTRAINT `transferts_patients_ibfk_5` FOREIGN KEY (`docteur_destination`) REFERENCES `infirmiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

