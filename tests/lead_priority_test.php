<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/PhoneNormalizer.php';
require_once dirname(__DIR__) . '/app/services/OverpassService.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
};

$categories = require dirname(__DIR__) . '/config/categories.php';
foreach ($categories as $category) $assert(is_array($category) && isset($category['label']) && is_array($category['tags'] ?? null), 'every category has a label and OSM tag list');
$assert(array_key_first($categories) === 'all_businesses', 'broad business category is the default option');
$assert(in_array('shop=*', $categories['all_businesses']['tags'], true), 'broad OSM search includes shops');
$assert(in_array('office=*', $categories['all_businesses']['tags'], true), 'broad OSM search includes offices');

$service = new OverpassService(['sources' => []]);
$queryMethod = new ReflectionMethod($service, 'query');
$queryMethod->setAccessible(true);
$query = $queryMethod->invoke($service, [41.8, 12.4, 42.0, 12.6], ['shop=*', 'amenity=bar']);
$assert(str_contains($query, '["shop"]["name"]'), 'wildcard selectors search named prospects');
$assert(str_contains($query, '["amenity"="bar"]'), 'exact tag selectors remain supported');

$assert(PhoneNormalizer::normalize('02 12345678') === '+390212345678', 'Italian is the default phone country');
$assert(PhoneNormalizer::normalize('01 42 00 00 00', 'FR') === '+33142000000', 'French numbers remain supported');

$sirene = require dirname(__DIR__) . '/config/sirene_categories.php';
$assert(isset($sirene['all_businesses']) && count($sirene['all_businesses']) > 10, 'France broad category combines configured NAF activities');

echo "Lead priority tests passed.\n";
