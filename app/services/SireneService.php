<?php
declare(strict_types=1);

/** Client léger de l'API publique officielle Recherche d'entreprises (données Sirene). */
final class SireneService {
    private const BASE_URL = 'https://recherche-entreprises.api.gouv.fr/near_point';
    private const MAX_PER_PAGE = 25;

    public function __construct(private array $config) {}

    public function fetchPage(float $lat, float $lon, float $radiusKm, array $nafCodes, int $page): array {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || $radiusKm <= 0 || $radiusKm > 50) {
            throw new InvalidArgumentException('Paramètres géographiques Sirene invalides.');
        }
        $codes = array_values(array_unique(array_filter($nafCodes, static fn($code) => is_string($code) && preg_match('/^\d{2}\.\d{2}[A-Z]$/', $code))));
        if (!$codes) throw new InvalidArgumentException('Aucun code NAF Sirene configuré pour cette catégorie.');
        $params = [
            'lat' => $lat, 'long' => $lon, 'radius' => $radiusKm,
            'activite_principale' => implode(',', $codes), 'per_page' => self::MAX_PER_PAGE,
            'page' => max(1, $page), 'limite_matching_etablissements' => 100,
            'minimal' => 'true', 'include' => 'matching_etablissements,siege',
        ];
        $cacheKey = 'sirene_' . sha1(http_build_query($params));
        $cached = $this->cacheGet($cacheKey);
        if (is_array($cached)) return $cached;

        $this->throttle();
        $url = self::BASE_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => (string)($this->config['sources']['user_agent'] ?? 'LeadFinder/1.0'),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('Connexion à l’API officielle Sirene impossible : ' . ($error ?: 'erreur réseau inconnue') . '.');
        if ($status === 429) throw new RuntimeException('L’API Sirene limite temporairement les requêtes (HTTP 429). Réessayez plus tard.');
        if ($status < 200 || $status >= 300) throw new RuntimeException('L’API officielle Sirene a répondu HTTP ' . $status . '. Réessayez plus tard.');
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) throw new RuntimeException('Réponse JSON Sirene invalide.');
        $this->cacheSet($cacheKey, $data);
        return $data;
    }

    private function throttle(): void {
        $file = dirname(__DIR__, 2) . '/storage/cache/sirene-last-request';
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $handle = @fopen($file, 'c+');
        if (!$handle) { usleep(1100000); return; }
        try {
            if (flock($handle, LOCK_EX)) {
                rewind($handle); $last = (float)stream_get_contents($handle);
                $wait = 1.1 - (microtime(true) - $last);
                if ($wait > 0) usleep((int)($wait * 1000000));
                ftruncate($handle, 0); rewind($handle); fwrite($handle, (string)microtime(true)); fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally { fclose($handle); }
    }

    private function cacheGet(string $key): ?array {
        $file = dirname(__DIR__, 2) . '/storage/cache/' . $key . '.json';
        $ttl = max(300, (int)($this->config['app']['cache_ttl'] ?? 86400));
        if (is_file($file) && filemtime($file) >= time() - $ttl) {
            $value = json_decode((string)file_get_contents($file), true);
            if (is_array($value)) return $value;
        }
        return null;
    }
    private function cacheSet(string $key, array $data): void {
        @file_put_contents(dirname(__DIR__, 2) . '/storage/cache/' . $key . '.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
