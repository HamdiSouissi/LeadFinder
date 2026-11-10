# Prime LeadFinder

Application web de prospection locale en PHP natif 8.x et MySQL/PDO. En France, les recherches par rayon utilisent l’API publique officielle de l’Annuaire des Entreprises (données Sirene), sans recherche de commerces dans les cartes. Pour les autres pays et les recherches couvrant une zone entière, l’application conserve Nominatim et Overpass (OpenStreetMap). Interface responsive, suivi commercial, vue Kanban et carte Leaflet.

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
│   │   └── 001_add_site_web.sql
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
2. Pour une nouvelle base, importez `database/schema.sql` via phpMyAdmin. Si vous mettez à jour une base créée avec l’ancienne version, importez une seule fois `database/migrations/001_add_site_web.sql` avant de déployer le nouveau code. Pour les données d’exemple, importez ensuite `database/seed.sql`.
3. Copiez `config/config.example.php` vers `config/config.php` et remplacez les informations MySQL et le contact dans `sources.user_agent` par une adresse de contact réelle (pas `admin@example.com`). Nominatim refuse ou limite les requêtes dont le User-Agent n’identifie pas réellement l’application. `config/config.php` est ignoré par Git.
4. Dans IONOS, choisissez PHP 8.1 ou plus récent. Activez les extensions `pdo_mysql`, `curl` et `json` (et vérifiez qu’elles sont disponibles sur l’offre).
5. Donnez au processus PHP les droits d’écriture sur `storage/cache` et `storage/logs` (permissions usuelles 755/775 selon l’hébergement).
6. Réglez le répertoire cible du domaine sur `public/` si IONOS le permet. Sinon, placez le dépôt hors de la racine publique et faites pointer la racine web vers son dossier `public`. Le `.htaccess` à la racine réécrit les requêtes vers `public/` et interdit l’accès HTTP aux répertoires privés.
7. Ouvrez le domaine et connectez-vous avec le compte de démonstration indiqué dans `database/seed.sql`. **Compte de démonstration : `admin@primeleadfinder.local` / `ChangeMe!2026`. Changez le mot de passe dans Paramètres et retirez ou modifiez le compte de démonstration avant toute mise en production** : le jeu de démonstration ne doit pas rester accessible sur un site public. Supprimez aussi les lignes de démonstration si nécessaire.
8. Confirmez que le site est servi en HTTPS. Les cookies de session sont `HttpOnly` et `SameSite=Lax`; le drapeau `Secure` est activé lorsque PHP détecte HTTPS.

La configuration initiale est un exemple : ne la publiez pas avec des identifiants réels. Si la base n’est pas configurée ou si le schéma manque, les pages afficheront une erreur et le détail sera consigné sous `storage/logs/app.log`.

## Fonctionnalités disponibles

- Authentification par `password_hash` / `password_verify`, sessions, jeton CSRF, requêtes PDO préparées et échappement HTML.
- Recherche géographique Nominatim avec cache et rayon réglable (2, 5, 10 ou 20 km). En France, si un rayon est choisi et que la catégorie a une correspondance NAF, l’application interroge l’API publique officielle de l’Annuaire des Entreprises/Sirene; les résultats actifs sont filtrés par code NAF et distance réelle, puis enregistrés avec leur SIRET. Chaque recherche parcourt au plus 10 pages de 25 entreprises, avec mise en cache et temporisation entre les requêtes. Pour les autres pays, les catégories personnalisées sans correspondance NAF et les recherches « zone entière », l’application conserve la grille Overpass, les miroirs et le redécoupage automatique des zones trop lourdes.
- Miroirs Overpass et temporisations configurables. Nominatim est limité localement à environ une requête par seconde; les recherches DuckDuckGo sont espacées.
- Correspondance des catégories françaises avec les codes NAF dans `config/sirene_categories.php`. Les catégories personnalisées restent compatibles avec la recherche OpenStreetMap.
- Prospects OSM dédupliqués par type/id, et prospects Sirene par SIRET (stocké dans les champs d’identifiant source existants, sans migration de schéma). Une mise à jour issue d’une recherche ne remplace pas les notes ni le statut de prospection existants.
- Tableau filtrable, export CSV UTF-8 avec BOM utilisable dans Excel, fiche avec actions téléphoniques/WhatsApp/email/itinéraire, journal d’interactions, vérification web facultative, Kanban et carte OSM/Leaflet.
- Normalisation locale de numéros tunisiens, français, marocains, algériens et italiens. Génération de message et suggestion de normalisation par IA désactivées par défaut et compatibles avec endpoint Chat Completions configuré par l’utilisateur. L’interface propose aussi une présentation arabe avec direction RTL.

## Paramètres et limites d’usage

Le User-Agent doit être identifiable et fournir un contact. L’API Recherche d’entreprises/Sirene est gratuite mais publique : disponibilité et quotas ne sont pas garantis, les résultats sont limités à 10 pages (250 fiches d’entreprises maximum par recherche) et les établissements non diffusibles peuvent manquer. Elle fournit des informations administratives et de localisation, mais pas directement les téléphones, emails, sites web ou réseaux sociaux. Les champs correspondants restent donc vides/inconnus : ne déduisez pas de leur absence que l’entreprise n’a pas ces coordonnées. Pour les recherches OSM, respectez les politiques d’utilisation Nominatim et d’Overpass : pas de recherches massives ou répétitives, sélectionnez une zone raisonnable, et prévoyez que les serveurs gratuits peuvent être indisponibles. Si tous les hôtes affichent `Connection timed out`, vérifiez auprès de l’hébergeur que les connexions HTTPS sortantes (port 443) sont autorisées depuis PHP. La grille est plafonnée à 16 cellules par recherche. Les tuiles cartographiques viennent d’OpenStreetMap; respectez aussi leur politique d’usage.

L’absence d’un tag `website`, `contact:website` ou `url` dans OSM **ne prouve pas** que l’établissement n’a pas de site : la donnée peut être incomplète. Le statut de confiance est indicatif. La vérification DuckDuckGo est une recherche web non garantie, et non une preuve exhaustive. Cette application ne scrape ni Google Maps ni les Pages Jaunes. Les téléphones, emails et réseaux sociaux sont repris des balises OSM lorsqu’elles existent; aucune extraction de données personnelles depuis les sites tiers n’est effectuée.

Le modèle IA d’exemple est préconfiguré avec un endpoint Groq compatible OpenAI, mais reste désactivé et sans clé par défaut. Pour l’activer, ajoutez votre clé API directement dans `config/config.php` ou dans Paramètres sur votre hébergement; ne la publiez pas dans Git et ne la partagez pas dans le chat. L’IA et la recherche DuckDuckGo transmettent le nom du prospect/la requête au service externe choisi. Activez ces fonctions uniquement après avoir évalué les conditions et règles de confidentialité du fournisseur. Les tuiles Leaflet et la police web sont chargées via CDN; pour une installation hors-ligne ou avec politique CSP stricte, hébergez ces ressources conformément à leurs licences.

## Développement local

Avec un serveur local PHP configuré pour pointer sur `public/`, utilisez par exemple `php -S 0.0.0.0:8000 -t public`. Il faut toujours configurer la base de données dans `config/config.php` et importer le schéma avant le premier accès.
