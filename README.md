# Prime LeadFinder

Application web de prospection locale en PHP natif 8.x et MySQL/PDO, prioritairement pour l’Italie et la France. En Italie, la recherche couvre les commerces nommés présents dans OpenStreetMap/Overpass, avec une recherche web complémentaire et des pistes à valider. En France, les recherches de proximité utilisent l’API publique officielle de l’Annuaire des Entreprises (données Sirene) pour les catégories configurées, avec OpenStreetMap pour les recherches par zone entière et les catégories sans correspondance NAF. Interface responsive, suivi commercial, vue Kanban et carte Leaflet.

## Arborescence

```text
.
├── .htaccess
├── README.md
├── app/
│   ├── bootstrap.php
│   ├── services/
│   │   ├── AiService.php
│   │   ├── EnrichmentService.php
│   │   ├── ExportService.php
│   │   ├── NominatimService.php
│   │   ├── ContactSearchService.php
│   │   ├── SireneService.php
│   │   ├── OverpassService.php
│   │   └── PhoneNormalizer.php
│   └── views/
│       ├── dashboard.php
│       ├── error.php
│       ├── history.php
│       ├── kanban.php
│       ├── layout.php
│       ├── login.php
│       ├── map.php
│       ├── prospect.php
│       ├── results.php
│       ├── search.php
│       └── settings.php
├── config/
│   ├── categories.php
│   ├── sirene_categories.php
│   └── config.example.php
├── database/
│   ├── migrations/
│   │   ├── 001_add_site_web.sql
│   │   └── 002_add_linkedin.sql
│   ├── schema.sql
│   └── seed.sql
├── public/
│   ├── .htaccess
│   ├── assets/
│   │   ├── app.css
│   │   └── app.js
│   └── index.php
└── storage/
    ├── cache/
    └── logs/
```

## Installation mutualisée IONOS

1. Dans IONOS, créez une base MySQL et un utilisateur avec droits sur cette base. Notez l’hôte SQL (il n’est pas toujours `localhost`), le nom, l’utilisateur et le mot de passe.
2. Pour une nouvelle base, importez `database/schema.sql` via phpMyAdmin. Pour une base existante, appliquez les migrations dans l’ordre : `database/migrations/001_add_site_web.sql` si elle n’a pas déjà été appliquée, puis `database/migrations/002_add_linkedin.sql` une seule fois avant de déployer le nouveau code. Les tests peuvent être lancés avec `php tests/contact_search_service_test.php` et `php tests/lead_priority_test.php`. Importez ensuite `database/seed.sql` pour créer le compte administrateur de démonstration (aucun faux prospect n’est ajouté).
3. Copiez `config/config.example.php` vers `config/config.php` et remplacez les informations MySQL et le contact dans `sources.user_agent` par une adresse de contact réelle (pas `admin@example.com`). Nominatim refuse ou limite les requêtes dont le User-Agent n’identifie pas réellement l’application. `config/config.php` est ignoré par Git.
4. Dans IONOS, choisissez PHP 8.1 ou plus récent. Activez les extensions `pdo_mysql`, `curl` et `json` (et vérifiez qu’elles sont disponibles sur l’offre).
5. Donnez au processus PHP les droits d’écriture sur `storage/cache` et `storage/logs` (permissions usuelles 755/775 selon l’hébergement).
6. Réglez le répertoire cible du domaine sur `public/` si IONOS le permet. Sinon, placez le dépôt hors de la racine publique et faites pointer la racine web vers son dossier `public`. Le `.htaccess` à la racine réécrit les requêtes vers `public/` et interdit l’accès HTTP aux répertoires privés.
7. Ouvrez le domaine et connectez-vous avec le compte de démonstration indiqué dans `database/seed.sql`. **Compte de démonstration : `admin@primeleadfinder.local` / `ChangeMe!2026`. Changez le mot de passe dans Paramètres et retirez ou modifiez le compte de démonstration avant toute mise en production** : le jeu de démonstration ne doit pas rester accessible sur un site public. Supprimez aussi les lignes de démonstration si nécessaire.
8. Confirmez que le site est servi en HTTPS. Les cookies de session sont `HttpOnly` et `SameSite=Lax`; le drapeau `Secure` est activé lorsque PHP détecte HTTPS.

La configuration initiale est un exemple : ne la publiez pas avec des identifiants réels. Si la base n’est pas configurée ou si le schéma manque, les pages afficheront une erreur et le détail sera consigné sous `storage/logs/app.log`.

## Fonctionnalités disponibles

- Authentification par `password_hash` / `password_verify`, sessions, jeton CSRF, requêtes PDO préparées et échappement HTML.
- Recherche géographique Nominatim avec cache et rayon réglable (2, 5, 10 ou 20 km). En France, les catégories configurées utilisent l’API publique officielle de l’Annuaire des Entreprises/Sirene; la catégorie large couvre l’union des activités NAF configurées. Les établissements actifs sont filtrés par code NAF et distance réelle; jusqu’à 10 pages de 25 entreprises sont parcourues. En Italie, la recherche OSM inclut maintenant une catégorie large de commerces, bureaux et artisans nommés, en plus des catégories usuelles (notamment bars/pubs et restauration rapide). Une recherche web complémentaire propose des pages publiques à valider avant l’ajout. Le registre Registro Imprese reste une piste manuelle : aucune automatisation ni contournement de ses restrictions. La prospection guidée est limitée à l’Italie et à la France; les recherches « zone entière » en France utilisent la grille Overpass, ses miroirs et le redécoupage des zones trop lourdes.
- Miroirs Overpass et temporisations configurables. Nominatim est limité localement à environ une requête par seconde; les recherches DuckDuckGo sont espacées.
- Correspondance des catégories françaises avec les codes NAF dans `config/sirene_categories.php`. Les catégories personnalisées restent compatibles avec la recherche OpenStreetMap.
- Prospects OSM dédupliqués par type/id, et prospects Sirene par SIRET (stocké dans les champs d’identifiant source existants, sans migration de schéma). Une mise à jour issue d’une recherche ne remplace pas les notes ni le statut de prospection existants.
- Tableau filtrable, export CSV UTF-8 avec BOM utilisable dans Excel, fiche avec actions téléphoniques/WhatsApp/email/itinéraire, journal d’interactions, enrichissement web facultatif sans IA (suggestions déterministes téléphone/email/site/réseaux sociaux/adresse, liens sources visibles et validation manuelle avant sauvegarde), Kanban et carte OSM/Leaflet.
- Normalisation locale des numéros français et italiens par défaut (compatibilité conservée pour les numéros marocains et algériens existants). Génération de message et suggestion de normalisation par IA désactivées par défaut; l’application ne fait appel à aucune IA pour chercher des prospects ou enrichir les coordonnées. L’interface propose aussi une présentation arabe avec direction RTL.

## Paramètres et limites d’usage

Le User-Agent doit être identifiable et fournir un contact. L’API Recherche d’entreprises/Sirene est gratuite mais publique : disponibilité et quotas ne sont pas garantis, les résultats sont limités à 10 pages (250 fiches d’entreprises maximum par recherche) et les établissements non diffusibles peuvent manquer. Elle fournit des informations administratives et de localisation, mais pas directement les téléphones, emails, sites web ou réseaux sociaux. Les champs correspondants restent donc vides/inconnus : ne déduisez pas de leur absence que l’entreprise n’a pas ces coordonnées. Pour les recherches OSM, respectez les politiques d’utilisation Nominatim et d’Overpass : pas de recherches massives ou répétitives, sélectionnez une zone raisonnable, et prévoyez que les serveurs gratuits peuvent être indisponibles. Si tous les hôtes affichent `Connection timed out`, vérifiez auprès de l’hébergeur que les connexions HTTPS sortantes (port 443) sont autorisées depuis PHP. La grille est plafonnée à 16 cellules par recherche. Les tuiles cartographiques viennent d’OpenStreetMap; respectez aussi leur politique d’usage.

L’absence d’un tag `website`, `contact:website` ou `url` dans OSM **ne prouve pas** que l’établissement n’a pas de site : la donnée peut être incomplète. Le statut de confiance est indicatif. La recherche DuckDuckGo est non garantie, susceptible de blocage et n’est pas une preuve exhaustive; la découverte web propose au plus 10 liens par ville, non vérifiés, et peut être indisponible. Les suggestions ne sont jamais enregistrées sans validation explicite, les champs déjà renseignés ne sont pas écrasés et chaque suggestion affiche sa page source. Vérifiez leur exactitude avant usage. Cette application n’automatise pas le registre italien et ne scrape ni Google Maps ni les Pages Jaunes.

Le modèle IA d’exemple est préconfiguré avec un endpoint Groq compatible OpenAI, mais reste désactivé et sans clé par défaut. Pour l’activer, ajoutez votre clé API directement dans `config/config.php` ou dans Paramètres sur votre hébergement; ne la publiez pas dans Git et ne la partagez pas dans le chat. L’IA et la recherche DuckDuckGo transmettent le nom du prospect/la requête au service externe choisi. Activez ces fonctions uniquement après avoir évalué les conditions et règles de confidentialité du fournisseur. Les tuiles Leaflet et la police web sont chargées via CDN; pour une installation hors-ligne ou avec politique CSP stricte, hébergez ces ressources conformément à leurs licences.

## Développement local

Avec un serveur local PHP configuré pour pointer sur `public/`, utilisez par exemple `php -S 0.0.0.0:8000 -t public`. Il faut toujours configurer la base de données dans `config/config.php` et importer le schéma avant le premier accès.
