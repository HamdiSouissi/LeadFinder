<?php
// Codes NAF 2008 pour les catégories françaises couvertes par l'API Recherche d'entreprises.
$categories = [
    'restaurant' => ['56.10A', '56.10B', '56.10C', '56.21Z', '56.29A', '56.29B'],
    'cafe' => ['56.10C', '56.30Z'],
    'hairdresser' => ['96.02A'],
    'garage' => ['45.20A', '45.20B'],
    'bakery' => ['10.71C', '10.71D'],
    'pharmacy' => ['47.73Z'],
    'hotel' => ['55.10Z', '55.20Z', '55.90Z'],
    'fitness' => ['93.13Z', '93.11Z'],
    'beauty' => ['96.02B'],
    'dentist' => ['86.23Z'],
    'florist' => ['47.76Z'],
    'supermarket' => ['47.11B', '47.11C', '47.11D', '47.11E', '47.11F'],
];
// La catégorie large couvre l'union des activités de prospection configurées ci-dessus.
$categories['all_businesses'] = array_values(array_unique(array_merge(...array_values($categories))));
return $categories;
