-- À exécuter une seule fois sur une installation qui possède déjà l'ancien schéma.
ALTER TABLE prospects ADD COLUMN site_web TEXT NULL AFTER email;
ALTER TABLE prospects MODIFY site_web_statut ENUM('aucun','reseaux_sociaux','verifie_aucun','present') NOT NULL DEFAULT 'aucun';
