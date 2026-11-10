<?php
final class OverpassService {
    public function __construct(private array $config) {}
    public static function grid(array $b,int $max=16): array {
        // Nominatim order: south, north, west, east
        [$s,$n,$w,$e]=$b; $aspect=max(0.2,abs($e-$w)/max(0.01,abs($n-$s))); $cols=max(1,(int)ceil(sqrt($max*$aspect))); $rows=max(1,(int)ceil($max/$cols)); $cells=[];
        for($y=0;$y<$rows;$y++) for($x=0;$x<$cols;$x++){ $south=$s+($n-$s)*$y/$rows; $north=$s+($n-$s)*($y+1)/$rows; $west=$w+($e-$w)*$x/$cols; $east=$w+($e-$w)*($x+1)/$cols; $cells[]=[$south,$west,$north,$east]; }
        return $cells;
    }
    public function fetch(array $bbox,array $tagPairs): array {
        $parts=[]; foreach($tagPairs as $pair){if(!preg_match('/^([a-zA-Z0-9_:]+)=([a-zA-Z0-9_ -]+)$/',$pair,$m))continue; $k=$m[1];$v=$m[2]; $q='["'.addcslashes($k,'"').'"="'.addcslashes($v,'"').'"]'; $b=implode(',',array_map(fn($n)=>sprintf('%.6f',$n),$bbox)); foreach(['node','way','relation'] as $type)$parts[]="$type$q($b);";}
        if(!$parts) return []; $query='[out:json][timeout:25];('.implode('',$parts).');out center tags;'; $hash=sha1(json_encode([$bbox,$tagPairs])); $f=dirname(__DIR__,2).'/storage/cache/over_'.$hash.'.json'; if(is_file($f)&&filemtime($f)>time()-(int)($this->config['app']['cache_ttl']??86400)){ $cached=json_decode((string)file_get_contents($f),true);if(is_array($cached))return $cached; }
        $servers=$this->config['sources']['overpass_servers']; $lastError=''; foreach($servers as $server){ $this->delay(); $ch=curl_init($server); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['data'=>$query]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERAGENT=>$this->config['sources']['user_agent'],CURLOPT_TIMEOUT=>(int)$this->config['sources']['timeout'],CURLOPT_CONNECTTIMEOUT=>10]); $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch); if($body && $code<400){$data=json_decode($body,true); if(isset($data['elements'])&&is_array($data['elements'])){@file_put_contents($f,json_encode($data['elements']));return $data['elements'];}} $lastError=$err?:"HTTP $code"; }
        throw new RuntimeException('Les serveurs Overpass sont indisponibles. Réessayez plus tard. '.$lastError);
    }
    private function delay(): void { $f=dirname(__DIR__,2).'/storage/cache/overpass-last';$last=is_file($f)?(float)file_get_contents($f):0;$wait=(float)($this->config['sources']['request_delay']??1.2)-(microtime(true)-$last);if($wait>0)usleep((int)($wait*1000000));@file_put_contents($f,(string)microtime(true),LOCK_EX); }
}
