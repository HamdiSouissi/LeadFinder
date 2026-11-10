<?php
return [
    'db' => [
        'host' => 'localhost', 'database' => 'prime_leadfinder', 'username' => 'db_user',
        'password' => 'change-me', 'charset' => 'utf8mb4',
    ],
    'app' => ['name' => 'Prime LeadFinder', 'base_url' => '', 'timezone' => 'Africa/Tunis', 'cache_ttl' => 86400],
    'sources' => [
        'user_agent' => 'PrimeLeadFinder/1.0 (contact: admin@example.com)',
        'nominatim_url' => 'https://nominatim.openstreetmap.org',
        'overpass_servers' => ['https://overpass-api.de/api/interpreter', 'https://overpass.kumi.systems/api/interpreter', 'https://overpass.private.coffee/api/interpreter'],
        'request_delay' => 1.2, 'timeout' => 45, 'max_grid_cells' => 16,
    ],
    'ai' => ['enabled' => false, 'endpoint' => '', 'api_key' => '', 'model' => ''],
];
