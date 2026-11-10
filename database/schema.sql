CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE,
 mot_de_passe_hash VARCHAR(255) NOT NULL, role ENUM('admin','user') NOT NULL DEFAULT 'user', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS searches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, ville VARCHAR(255) NOT NULL, zone_label TEXT,
 categorie VARCHAR(80) NOT NULL, bbox JSON NULL, nb_trouves INT UNSIGNED NOT NULL DEFAULT 0, nb_sans_site INT UNSIGNED NOT NULL DEFAULT 0,
 statut ENUM('en_cours','terminee','erreur') NOT NULL DEFAULT 'en_cours', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_search_user(user_id,created_at), CONSTRAINT fk_search_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS prospects (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, osm_type VARCHAR(12) NOT NULL, osm_id BIGINT UNSIGNED NOT NULL,
 nom VARCHAR(255) NOT NULL, categorie VARCHAR(100) NOT NULL, adresse TEXT, ville VARCHAR(150), code_postal VARCHAR(20),
 telephone VARCHAR(100), telephone_normalise VARCHAR(32), email VARCHAR(190), site_web TEXT, facebook TEXT, instagram TEXT, horaires TEXT,
 latitude DECIMAL(10,7), longitude DECIMAL(10,7), site_web_statut ENUM('aucun','reseaux_sociaux','verifie_aucun','present') NOT NULL DEFAULT 'aucun',
 score_confiance ENUM('élevé','moyen','faible') NOT NULL DEFAULT 'moyen',
 statut_prospection ENUM('nouveau','a_contacter','contacte','interesse','rdv','client','refuse') NOT NULL DEFAULT 'nouveau',
 notes TEXT, search_id BIGINT UNSIGNED NULL, assigned_to BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_osm(osm_type,osm_id), INDEX idx_prospect_status(statut_prospection), INDEX idx_prospect_city(ville), INDEX idx_prospect_search(search_id),
 CONSTRAINT fk_prospect_search FOREIGN KEY(search_id) REFERENCES searches(id) ON DELETE SET NULL,
 CONSTRAINT fk_prospect_assigned FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS interactions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, prospect_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 type ENUM('appel','whatsapp','email','visite') NOT NULL, note TEXT, date_interaction TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_interaction_prospect(prospect_id,date_interaction),
 CONSTRAINT fk_interaction_prospect FOREIGN KEY(prospect_id) REFERENCES prospects(id) ON DELETE CASCADE,
 CONSTRAINT fk_interaction_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS settings (`cle` VARCHAR(100) PRIMARY KEY, `valeur` MEDIUMTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
