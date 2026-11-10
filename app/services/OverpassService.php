<?php
final class OverpassService {
    public function __construct(private array $config) {}

    /** Nominatim bbox [south,north,west,east] -> Overpass bbox cells [south,west,north,east]. */
    public static function grid(array $b,int $max=16): array {
        [$s,$n,$w,$e]=$b;
        $aspect=max(0.2,abs($e-$w)/max(0.01,abs($n-$s)));
        $cols=max(1,(int)ceil(sqrt($max*$aspect)));
        $rows=max(1,(int)ceil($max/$cols));
        $cells=[];
        for($y=0;$y<$rows;$y++) for($x=0;$x<$cols;$x++) {
            $south=$s+($n-$s)*$y/$rows; $north=$s+($n-$s)*($y+1)/$rows;
            $west=$w+($e-$w)*$x/$cols; $east=$w+($e-$w)*($x+1)/$cols;
            $cells[]=[$south,$west,$north,$east];
        }
        return $cells;
    }

    /** Split a slow bbox into four smaller queries, preserving Overpass coordinate order. */
    public static function split(array $b): array {
        [$s,$w,$n,$e]=$b; $midLat=($s+$n)/2; $midLon=($w+$e)/2;
        return [[$s,$w,$midLat,$midLon],[$s,$midLon,$midLat,$e],[$midLat,$w,$n,$midLon],[$midLat,$midLon,$n,$e]];
    }

    public function fetch(array $bbox,array $tagPairs): array {
        // Group values by key. This avoids repeating node/way/relation selectors for every value.
        $grouped=[];
        foreach($tagPairs as $pair) {
            if(!preg_match('/^([a-zA-Z0-9_:]+)=([a-zA-Z0-9_ -]+)$/',$pair,$m)) continue;
            $grouped[$m[1]][]=$m[2];
        }
        $b=implode(',',array_map(fn($n)=>sprintf('%.6f',$n),array_slice($bbox,0,4)));
        $parts=[];
        foreach($grouped as $key=>$values) {
            $values=array_values(array_unique($values));
            if(count($values)===1) $selector='["'.$key.'"="'.$values[0].'"]';
            else $selector='["'.$key.'"~"^('.implode('|',array_map(fn($v)=>preg_quote($v,'/'),$values)).')$"]';
            $parts[]='nwr'.$selector.'('.$b.');';
        }
        if(!$parts) return [];
        $query='[out:json][timeout:8];('.implode('',$parts).');out center tags;';
        $hash=sha1(json_encode([$bbox,$tagPairs]));
        $file=dirname(__DIR__,2).'/storage/cache/over_'.$hash.'.json';
        if(is_file($file)&&filemtime($file)>time()-(int)($this->config['app']['cache_ttl']??86400)) {
            $cached=json_decode((string)file_get_contents($file),true); if(is_array($cached)) return $cached;
        }
        $servers=$this->config['sources']['overpass_servers']??[]; $lastError='';
        foreach($servers as $server) {
            $this->delay(); $ch=curl_init($server); $clientTimeout=max(8,min(12,(int)($this->config['sources']['timeout']??12)));
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>http_build_query(['data'=>$query]),
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_USERAGENT=>$this->config['sources']['user_agent'],
                CURLOPT_HTTPHEADER=>['Accept: application/json'],
                CURLOPT_ENCODING=>'',
                CURLOPT_TIMEOUT=>$clientTimeout,
                CURLOPT_CONNECTTIMEOUT=>min(3,$clientTimeout),
                CURLOPT_LOW_SPEED_LIMIT=>32,
                CURLOPT_LOW_SPEED_TIME=>8,
            ]);
            $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
            if($body!==false&&$code>=200&&$code<300) {
                $data=json_decode($body,true);
                if(!empty($data['remark'])) { $lastError=(string)$data['remark']; continue; }
                if(isset($data['elements'])&&is_array($data['elements'])) { @file_put_contents($file,json_encode($data['elements'])); return $data['elements']; }
                $lastError='Réponse Overpass invalide ou incomplète';
            } else $lastError=$err?:'HTTP '.$code;
        }
        throw new RuntimeException('Tous les miroirs Overpass ont échoué après des délais courts. Dernière réponse : '.$lastError);
    }

    private function delay(): void {
        $file=dirname(__DIR__,2).'/storage/cache/overpass-last';
        $last=is_file($file)?(float)file_get_contents($file):0;
        $wait=(float)($this->config['sources']['request_delay']??1.2)-(microtime(true)-$last);
        if($wait>0) usleep((int)($wait*1000000));
        @file_put_contents($file,(string)microtime(true),LOCK_EX);
    }
}
