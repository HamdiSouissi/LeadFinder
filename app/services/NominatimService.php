<?php
final class NominatimService {
    public function __construct(private array $config) {}
    public function suggest(string $query): array {
        $query=trim($query);if(strlen($query)<3)return [];$key='sug_'.sha1(strtolower($query));$cached=$this->cacheGet($key);if($cached)return $cached;
        $url=rtrim($this->config['sources']['nominatim_url'],'/').'/search?'.http_build_query(['q'=>$query,'format'=>'jsonv2','limit'=>5,'addressdetails'=>1]);$rows=$this->get($url);$result=[];foreach($rows as $row)if(!empty($row['display_name']))$result[]=$row['display_name'];$this->cacheSet($key,$result);return $result;
    }
    public function geocode(string $query): array {
        $key='nom_v2_'.sha1(strtolower(trim($query))); $cached=$this->cacheGet($key); if($cached) return $cached;
        $url=rtrim($this->config['sources']['nominatim_url'],'/').'/search?'.http_build_query(['q'=>$query,'format'=>'jsonv2','limit'=>1,'addressdetails'=>1]);
        $data=$this->get($url); if(!$data || empty($data[0]['boundingbox'])) throw new RuntimeException('Zone introuvable : vérifiez le nom de la ville ou de la zone.');
        $r=$data[0]; $result=['label'=>$r['display_name']??$query,'bbox'=>array_map('floatval',$r['boundingbox']),'lat'=>(float)$r['lat'],'lon'=>(float)$r['lon'],'country_code'=>strtolower((string)($r['address']['country_code']??''))]; $this->cacheSet($key,$result); return $result;
    }
    private function get(string $url): array {
        $this->delay(); $agent=trim((string)($this->config['sources']['user_agent']??''));
        if($agent===''||str_contains($agent,'admin@example.com'))throw new RuntimeException('Le User-Agent Nominatim doit contenir un nom d’application et une adresse de contact réelle. Configurez-le dans config.php ou Paramètres.');
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERAGENT=>$agent,CURLOPT_HTTPHEADER=>['Accept: application/json','Accept-Language: fr'],CURLOPT_ENCODING=>'',CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>true]); $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        if($body===false)throw new RuntimeException('Connexion Nominatim impossible (cURL : '.($err?:'erreur réseau inconnue').'). Vérifiez que l’hébergement autorise les connexions HTTPS sortantes et que cURL/CA certificates sont actifs.');
        if($code<200||$code>=300){$detail=trim(preg_replace('/\\s+/',' ',strip_tags((string)$body)));$detail=substr($detail,0,180);throw new RuntimeException('Nominatim a répondu HTTP '.$code.'. '.($detail?:$err?:'Service temporairement indisponible.').' Vérifiez le User-Agent et réessayez après quelques instants.');}
        $data=json_decode($body,true);if(!is_array($data))throw new RuntimeException('Nominatim a renvoyé une réponse illisible (JSON invalide).');return $data;
    }
    private function delay(): void { $file=dirname(__DIR__,2).'/storage/cache/nominatim-last'; $last=is_file($file)?(float)file_get_contents($file):0; $wait=1.05-(microtime(true)-$last); if($wait>0) usleep((int)($wait*1000000)); @file_put_contents($file,(string)microtime(true),LOCK_EX); }
    private function cacheGet(string $key): ?array { $f=dirname(__DIR__,2).'/storage/cache/'.$key.'.json'; if(is_file($f) && filemtime($f)>time()-(int)($this->config['app']['cache_ttl']??86400)) return json_decode((string)file_get_contents($f),true)?:null; return null; }
    private function cacheSet(string $key,array $data): void { @file_put_contents(dirname(__DIR__,2).'/storage/cache/'.$key.'.json',json_encode($data)); }
}
