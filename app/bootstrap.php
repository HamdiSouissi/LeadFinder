<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('prime_leadfinder');
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
    session_start();
}
$configFile = dirname(__DIR__) . '/config/config.php';
$config = file_exists($configFile) ? require $configFile : require dirname(__DIR__) . '/config/config.example.php';
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
require_once __DIR__ . '/services/PhoneNormalizer.php';
require_once __DIR__ . '/services/NominatimService.php';
require_once __DIR__ . '/services/OverpassService.php';
require_once __DIR__ . '/services/EnrichmentService.php';
require_once __DIR__ . '/services/AiService.php';
require_once __DIR__ . '/services/ExportService.php';
function db(): PDO {
    static $pdo;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['db'];
    $pdo = new PDO('mysql:host='.$d['host'].';dbname='.$d['database'].';charset='.($d['charset'] ?? 'utf8mb4'), $d['username'], $d['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    return $pdo;
}
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_check(): void { if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { http_response_code(419); exit('Jeton de sécurité invalide. Rechargez la page.'); } }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_auth(): void { if (!current_user()) { header('Location: ?r=login'); exit; } }
function json_response(array $data, int $status=200): void { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function log_error(string $message): void { $dir=dirname(__DIR__).'/storage/logs'; if(!is_dir($dir)) @mkdir($dir,0775,true); @file_put_contents($dir.'/app.log','['.date('c').'] '.$message.PHP_EOL,FILE_APPEND|LOCK_EX); }
function setting(string $key, mixed $default=null): mixed { static $all=null; if($all===null){try{$all=db()->query('SELECT `cle`,`valeur` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);}catch(Throwable $e){$all=[];}} return $all[$key]??$default; }
function status_label(string $s): string { return ['nouveau'=>'Nouveau','a_contacter'=>'À contacter','contacte'=>'Contacté','interesse'=>'Intéressé','rdv'=>'Rendez-vous','client'=>'Client','refuse'=>'Refusé'][$s]??$s; }
function redirect(string $url): void { header('Location: '.$url); exit; }
try {
    $saved = db()->query('SELECT `cle`,`valeur` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($saved['user_agent'])) $config['sources']['user_agent'] = $saved['user_agent'];
    if (!empty($saved['overpass_servers'])) $config['sources']['overpass_servers'] = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $saved['overpass_servers']))));
    if (!empty($saved['request_delay'])) $config['sources']['request_delay'] = max(1.0, (float)$saved['request_delay']);
    if (isset($saved['ai_enabled'])) $config['ai']['enabled'] = $saved['ai_enabled'] === '1';
    foreach (['ai_endpoint'=>'endpoint','ai_key'=>'api_key','ai_model'=>'model'] as $key=>$target) if (isset($saved[$key])) $config['ai'][$target] = $saved[$key];
} catch (Throwable $ignored) { /* First run: settings table may not exist yet. */ }
