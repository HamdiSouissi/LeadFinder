<?php
final class OverpassService {
    public function __construct(private array $config) {}

    /** Nominatim bbox [south,north,west,east] -> Overpass bbox cells [south,west,north,east]. */
    public static function grid(array $b,int $max=16): array {
        [$s,$n,$w,$e]=$b; $aspect=max(0.2,abs($e-$w)/max(0.01,abs($n-$s)));
        $cols=max(1,(int)ceil(sqrt($max*$aspect))); $rows=max(1,(int)ceil($max/$cols)); $cells=[];
        for($y=0;$y<$rows;$y++) for($x=0;$x<$cols;$x++) {
            $south=$s+($n-$s)*$y/$rows; $north=$s+($n-$s)*($y+1)/$rows;
            $west=$w+($e-$w)*$x/$cols; $east=$w+($e-$w)*($x+1)/$cols;
            $cells[]=[$south,$west,$north,$east];
        }
        return $cells;
    }
    public static function split(array $b): array {
        [$s,$w,$n,$e]=$b; $midLat=($s+$n)/2; $midLon=($w+$e)/2;
        return [[$s,$w,$midLat,$midLon],[$s,$midLon,$midLat,$e],[$midLat,$w,$n,$midLon],[$midLat,$midLon,$n,$e]];
    }
    public function servers(): array { $servers=array_values(array_filter($this->config['sources']['overpass_servers']??[],'is_string'));$servers[]='https://overpass.osm.ch/api/interpreter';$servers[]='https://overpass.nchc.org.tw/api/interpreter';return array_slice(array_values(array_unique($servers)),0,5); }

    /** Try exactly one mirror so the browser can report the failover between attempts. */
    public function fetchAttempt(array $bbox,array $tagPairs,int $serverIndex): array {
        $servers=$this->servers(); $cacheFile=$this->cacheFile($bbox,$tagPairs);
        if(is_file($cacheFile)&&filemtime($cacheFile)>time()-(int)($this->config['app']['cache_ttl']??86400)) {
            $cached=json_decode((string)file_get_contents($cacheFile),true);
            if(is_array($cached))return ['ok'=>true,'elements'=>$cached,'cached'=>true,'server_index'=>$serverIndex];
        }
        if(!isset($servers[$serverIndex]))return ['ok'=>false,'error'=>'Aucun miroir Overpass configuré.','server_index'=>$serverIndex];
        $query=$this->query($bbox,$tagPairs);if($query==='')return ['ok'=>true,'elements'=>[],'cached'=>false,'server_index'=>$serverIndex];
        $this->delay(); $server=$servers[$serverIndex];$ch=curl_init($server);$timeout=max(10,min(12,(int)($this->config['sources']['timeout']??10)));
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['data'=>$query]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERAGENT=>$this->config['sources']['user_agent'],CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_ENCODING=>'',CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>min(5,$timeout),CURLOPT_LOW_SPEED_LIMIT=>32,CURLOPT_LOW_SPEED_TIME=>9]);
        $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
        if($body!==false&&$code>=200&&$code<300){
            $data=json_decode($body,true);
            if(!empty($data['remark']))return ['ok'=>false,'error'=>(string)$data['remark'],'server_index'=>$serverIndex,'server'=>$server];
            if(isset($data['elements'])&&is_array($data['elements'])){@file_put_contents($cacheFile,json_encode($data['elements']));return ['ok'=>true,'elements'=>$data['elements'],'cached'=>false,'server_index'=>$serverIndex,'server'=>$server];}
            $error='Réponse Overpass invalide ou incomplète';
        } else $error=$error?:'HTTP '.$code;
        return ['ok'=>false,'error'=>$error,'server_index'=>$serverIndex,'server'=>$server];
    }

    /** Backward-compatible helper for callers that want a single blocking call. */
    public function fetch(array $bbox,array $tagPairs): array {
        $last='';foreach($this->servers() as $index=>$server){$result=$this->fetchAttempt($bbox,$tagPairs,$index);if($result['ok'])return $result['elements'];$last=$result['error'];}
        throw new RuntimeException('Tous les miroirs Overpass ont échoué. '.$last);
    }

    private function query(array $bbox,array $tagPairs): string {
        $grouped=[];foreach($tagPairs as $pair)if(preg_match('/^([a-zA-Z0-9_:]+)=([a-zA-Z0-9_ -]+)$/',$pair,$m))$grouped[$m[1]][]=$m[2];
        $b=implode(',',array_map(fn($n)=>sprintf('%.6f',$n),array_slice($bbox,0,4)));$parts=[];
        foreach($grouped as $key=>$values){$values=array_values(array_unique($values));$selector=count($values)===1?'["'.$key.'"="'.$values[0].'"]':'["'.$key.'"~"^('.implode('|',array_map(fn($v)=>preg_quote($v,'/'),$values)).')$"]';$parts[]='nwr'.$selector.'('.$b.');';}
        return $parts?'[out:json][timeout:8];('.implode('',$parts).');out center tags;':'';
    }
    private function cacheFile(array $bbox,array $tagPairs): string { return dirname(__DIR__,2).'/storage/cache/over_v3_'.sha1(json_encode([$bbox,$tagPairs])).'.json'; }
    private function delay(): void {
        $file=dirname(__DIR__,2).'/storage/cache/overpass-last';$last=is_file($file)?(float)file_get_contents($file):0;$wait=(float)($this->config['sources']['request_delay']??1.2)-(microtime(true)-$last);
        if($wait>0)usleep((int)($wait*1000000));@file_put_contents($file,(string)microtime(true),LOCK_EX);
    }
}
