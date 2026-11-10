<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/ContactSearchService.php';

$service = new ContactSearchService();
$invoke = static function (string $method, array $arguments) use ($service): mixed {
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($service, $arguments);
};
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$html = <<<'HTML'
<script type="application/ld+json">{"@type":"LocalBusiness","telephone":"+39 02 12345678","email":"info@example.it","description":"Ristorante di cucina romana nel centro di Milano.","openingHours":["Mo-Fr 09:00-18:00"],"address":{"@type":"PostalAddress","streetAddress":"Via Roma 1","addressLocality":"Milano","postalCode":"20100"},"sameAs":["https://www.instagram.com/example"]}</script>
<a href="https://www.linkedin.com/company/example">LinkedIn</a>
HTML;
$contacts = $invoke('extractFromHtml', [$html, 'IT']);
$expect($contacts['phone'] === '+39 02 12345678', 'extracts a structured telephone');
$expect($contacts['email'] === 'info@example.it', 'extracts a structured email');
$expect($contacts['address'] === 'Via Roma 1' && $contacts['city'] === 'Milano' && $contacts['postcode'] === '20100', 'extracts structured address fields');
$expect($contacts['description'] === 'Ristorante di cucina romana nel centro di Milano.', 'extracts a business description');
$expect($contacts['opening_hours'] === 'Mo-Fr 09:00-18:00', 'extracts opening hours');
$expect($contacts['instagram'] === 'https://www.instagram.com/example', 'extracts a social profile');
$expect($contacts['linkedin'] === 'https://www.linkedin.com/company/example', 'extracts LinkedIn links');

$resultsHtml = '<a class="result__a" href="https://duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.it%2F">Example</a>'
    . '<a class="result__a" href="https://duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.paginegialle.it%2F">Directory</a>';
$results = $invoke('parseSearchResults', [$resultsHtml]);
$expect(count($results) === 1 && $results[0]['url'] === 'https://example.it/', 'unwraps results and filters directory sites');
$expect($invoke('validHttpUrl', ['http://example.it:8080/']) === null, 'rejects nonstandard ports');
$expect($invoke('validHttpUrl', ['http://127.0.0.1/']) === null, 'rejects private IP addresses');

echo "ContactSearchService tests passed.\n";

$metaHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="description" content="Pizzeria familiare à Turin depuis 1989."><meta property="og:description" content="Should not overwrite"><meta property="business:contact_data:phone_number" content="+39 011 1234567"></head><body><h1>Pizzeria Test</h1></body></html>';
$metaContacts = $invoke('extractFromHtml', [$metaHtml, 'IT']);
$expect($metaContacts['description'] === 'Pizzeria familiare à Turin depuis 1989.', 'extracts a meta description');
$expect($metaContacts['phone'] === '+39 011 1234567', 'extracts the structured phone meta');

$structuredHtml = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"LocalBusiness","name":"Bar Milano","description":"Bar à café et petite restauration dans le centre.","openingHours":"Mo-Sa 07:00-19:00","telephone":"+39 02 9876543"}]}</script>';
$structured = $invoke('extractFromHtml', [$structuredHtml, 'IT']);
$expect($structured['description'] === 'Bar à café et petite restauration dans le centre.', 'walks @graph for a business description');
$expect($structured['opening_hours'] === 'Mo-Sa 07:00-19:00', 'walks @graph for opening hours (string form)');

$aboutHtml = '<html><body><header>Pizzeria Test</header><main>Pizzeria Test è un piccolo ristorante a Torino, aperto dal 1989. Menù stagionale, tavoli all\'aperto.</main><a href="/contatti">Contatti</a></body></html>';
$evidence = $invoke('pageEvidence', [$aboutHtml, 'Pizzeria Test', 'Torino']);
$expect($evidence['name_match'] === true && $evidence['city_match'] === true, 'detects prospect name and city in the page');
$expect($evidence['snippet'] !== '' && stripos($evidence['snippet'], 'Pizzeria Test') !== false, 'snippet quotes evidence around the prospect');

$navHtml = '<html><body>
<a href="/privacy-policy">Privacy</a>
<a href="/chisiamo">Chi siamo</a>
<a href="https://external.example.com/contatti">Contatti esterno</a>
<a href="/prodotti/offerta-speciale">Le nos services</a>
</body></html>';
$relevant = $invoke('relevantPageUrls', [$navHtml, 'https://www.pizzeria-test.it/index.html', 'www.pizzeria-test.it', 2]);
$expect(count($relevant) === 2, 'keeps two same-site relevant pages');
$expect($relevant[0] === 'https://www.pizzeria-test.it/chisiamo', 'prefers the about/contact page first');
$expect(in_array($relevant[1], ['https://www.pizzeria-test.it/prodotti/offerta-speciale'], true), 'then picks the services page');

$resolved = $invoke('resolveUrl', ['../privacy/contatti.html?x=1#y', 'https://www.pizzeria-test.it/en/index.html']);
$expect($resolved === 'https://www.pizzeria-test.it/privacy/contatti.html?x=1', 'resolves relative paths with dot segments');

echo "New coverage tests passed.\n";

$googleJson = '{"items":[{"link":"https://www.pizzeria-test.it/","title":"Pizzeria Test — Milan"},{"link":"https://www.paginegialle.it/scheda/123","title":"Annuaire"},{"link":"pas-une-url","title":"Broken"},{"link":"https://www.pizzeria-test.it/","title":"Duplicate"}]}';
$google = $invoke('parseGoogleResults', [$googleJson]);
$expect(count($google) === 1 && $google[0]['url'] === 'https://www.pizzeria-test.it/', 'parses Google CSE items and filters blocked, invalid and duplicate links');
$expect($invoke('googleSearchResults', ['pizzeria test milano', 'IT', []]) === null, 'Google CSE is skipped silently when not configured');
$expect($invoke('parseGoogleResults', ['not-json']) === [], 'Google CSE handles malformed payloads');

echo "Google CSE tests passed.\n";
