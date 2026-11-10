-- Compte de démonstration : admin@primeleadfinder.local / ChangeMe!2026
-- Aucun faux prospect n'est inséré : commencez par une recherche réelle en Italie ou en France.
INSERT INTO users (nom,email,mot_de_passe_hash,role) VALUES ('Administrateur','admin@primeleadfinder.local','$2y$10$50GxyWhp5vVWhqhnfw48Uu7eMrk0XOlamZp1GJEsKw8g4goIGBET6','admin') ON DUPLICATE KEY UPDATE email=email;
INSERT INTO settings (`cle`,`valeur`) VALUES ('lang','fr'),('ai_enabled','0') ON DUPLICATE KEY UPDATE `cle`=`cle`;
