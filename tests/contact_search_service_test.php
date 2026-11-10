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
<script type="application/ld+json">{"@type":"LocalBusiness","telephone":"+39 02 12345678","email":"info@example.it","address":{"@type":"PostalAddress","streetAddress":"Via Roma 1","addressLocality":"Milano","postalCode":"20100"},"sameAs":["https://www.instagram.com/example"]}</script>
<a href="https://www.linkedin.com/company/example">LinkedIn</a>
HTML;
$contacts = $invoke('extractFromHtml', [$html, 'IT']);
$expect($contacts['phone'] === '+39 02 12345678', 'extracts a structured telephone');
$expect($contacts['email'] === 'info@example.it', 'extracts a structured email');
$expect($contacts['address'] === 'Via Roma 1' && $contacts['city'] === 'Milano' && $contacts['postcode'] === '20100', 'extracts structured address fields');
$expect($contacts['instagram'] === 'https://www.instagram.com/example', 'extracts a social profile');
$expect($contacts['linkedin'] === 'https://www.linkedin.com/company/example', 'extracts LinkedIn links');

$resultsHtml = '<a class="result__a" href="https://duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.it%2F">Example</a>'
    . '<a class="result__a" href="https://duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.paginegialle.it%2F">Directory</a>';
$results = $invoke('parseSearchResults', [$resultsHtml]);
$expect(count($results) === 1 && $results[0]['url'] === 'https://example.it/', 'unwraps results and filters directory sites');
$expect($invoke('validHttpUrl', ['http://example.it:8080/']) === null, 'rejects nonstandard ports');
$expect($invoke('validHttpUrl', ['http://127.0.0.1/']) === null, 'rejects private IP addresses');

echo "ContactSearchService tests passed.\n";
