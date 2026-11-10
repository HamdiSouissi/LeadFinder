-- Compte de démonstration : admin@primeleadfinder.local / ChangeMe!2026
INSERT INTO users (nom,email,mot_de_passe_hash,role) VALUES ('Administrateur','admin@primeleadfinder.local','$2y$10$50GxyWhp5vVWhqhnfw48Uu7eMrk0XOlamZp1GJEsKw8g4goIGBET6','admin') ON DUPLICATE KEY UPDATE email=email;
INSERT INTO settings (`cle`,`valeur`) VALUES ('lang','fr'),('ai_enabled','0') ON DUPLICATE KEY UPDATE `cle`=`cle`;
INSERT INTO prospects (osm_type,osm_id,nom,categorie,adresse,ville,code_postal,telephone,telephone_normalise,email,site_web,facebook,instagram,horaires,latitude,longitude,site_web_statut,score_confiance,statut_prospection,notes)
VALUES
('node',980000001,'Café des Jasmins','Café','12 avenue Habib Bourguiba','Tunis','1001','71 234 567','+21671234567','contact@jasmins.example','https://facebook.com/cafedesjasmins','https://facebook.com/cafedesjasmins',NULL,'Mo-Sa 07:00-20:00',36.8008,10.1800,'reseaux_sociaux','moyen','a_contacter','Page Facebook uniquement — données de démonstration.'),
('node',980000002,'Boulangerie El Medina','Boulangerie','5 rue de la Kasbah','Tunis','1006','22 345 678','+21622345678',NULL,NULL,NULL,NULL,'Mo-Su 06:00-19:00',36.7980,10.1710,'aucun','élevé','nouveau','Prospect de démonstration sans site renseigné.'),
('node',980000003,'Librairie de démonstration','Librairie','20 avenue de la Liberté','Tunis','1002','71 000 000','+21671000000','contact@librairie.example','https://librairie.example',NULL,NULL,'Mo-Sa 09:00-18:00',36.8060,10.1820,'present','faible','nouveau','Prospect de démonstration avec site web.')
ON DUPLICATE KEY UPDATE nom=VALUES(nom),site_web=VALUES(site_web),site_web_statut=VALUES(site_web_statut);
