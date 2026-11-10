<?php
final class EnrichmentService {
    public function verify(string $name,string $city,array $config): array {
        $delayFile=dirname(__DIR__,2).'/storage/cache/enrichment-last';$last=is_file($delayFile)?(float)file_get_contents($delayFile):0;$wait=2.0-(microtime(true)-$last);if($wait>0)usleep((int)($wait*1000000));@file_put_contents($delayFile,(string)microtime(true),LOCK_EX);
        $query=trim($name.' '.$city); $url='https://html.duckduckgo.com/html/?'.http_build_query(['q'=>$query]); $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERAGENT=>$config['sources']['user_agent'],CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8]);$html=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if(!$html||$code>=400)throw new RuntimeException('La vérification web a échoué. Réessayez plus tard.');
        $links=[]; if(preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is',$html,$matches,PREG_SET_ORDER)){foreach(array_slice($matches,0,8) as $m){$href=html_entity_decode($m[1],ENT_QUOTES);if(str_contains($href,'uddg=')){parse_str((string)parse_url($href,PHP_URL_QUERY),$p);$href=$p['uddg']??$href;} $host=parse_url($href,PHP_URL_HOST)??'';if($host)$links[]=['url'=>$href,'host'=>$host,'title'=>trim(strip_tags($m[2]))];}}
        $social=[];$regular=[];foreach($links as $l){if(preg_match('/facebook\.com|instagram\.com/i',$l['host']))$social[]=$l;elseif(!preg_match('/duckduckgo|wikipedia|linkedin|youtube|tripadvisor|foursquare/i',$l['host']))$regular[]=$l;}
        return ['confidence'=>$regular?'faible':($social?'moyen':'élevé'),'status'=>$regular?'site_possible':($social?'reseaux_sociaux':'verifie_aucun'),'links'=>$links];
    }
}
