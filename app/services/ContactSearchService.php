<?php
declare(strict_types=1);

/** Recherche web publique et extraction prudente de coordonnées; sans IA ni scraping de cartes/annuaires jaunes. */
final class ContactSearchService {
    private const SEARCH_URL = 'https://html.duckduckgo.com/html/';
    private const MAX_PAGE_BYTES = 524288;
    private const BLOCKED_DOMAINS = ['google.','bing.com','duckduckgo.com','pagesjaunes.','paginegialle.it','paginebianche.it','yelp.','tripadvisor.','foursquare.com','mapquest.','yellowpages.','facebook.com','instagram.com','linkedin.com','tiktok.com','youtube.com'];

    public function search(array $prospect, string $country, array $config): array {
        $name=trim((string)($prospect['nom']??''));$address=trim((string)($prospect['adresse']??''));$city=trim((string)($prospect['ville']??''));$postcode=trim((string)($prospect['code_postal']??''));
        if($name===''||($city===''&&$address===''))throw new RuntimeException('Le prospect doit avoir au minimum un nom et une ville ou une adresse.');
        $country=in_array($country,['IT','FR','TN','MA','DZ'],true)?$country:'IT';$key='contact_'.sha1(strtolower($name.'|'.$address.'|'.$postcode.'|'.$city.'|'.$country));$cached=$this->cacheGet($key);if($cached)return $cached;
        $locale=['IT'=>'it-it','FR'=>'fr-fr','TN'=>'fr-fr','MA'=>'fr-fr','DZ'=>'fr-fr'][$country];$terms=$country==='IT'?'telefono email contatti sito ufficiale':'téléphone email contact site officiel';
        $query=trim('"'.$name.'" '.$address.' '.$postcode.' '.$city.' '.$terms);$html=$this->httpGet(self::SEARCH_URL.'?'.http_build_query(['q'=>$query,'kl'=>$locale]),$config,true);
        if($html===null)throw new RuntimeException('La recherche web est momentanément indisponible. Réessayez plus tard.');
        $results=$this->parseSearchResults($html);$contacts=['phone'=>null,'email'=>null,'website'=>null,'facebook'=>null,'instagram'=>null,'linkedin'=>null,'address'=>null,'city'=>null,'postcode'=>null];$contactSources=[];$sources=[];$visited=[];$pages=0;
        foreach($results as $row){
            $host=strtolower((string)(parse_url($row['url'],PHP_URL_HOST)??''));if(!$host||$this->isBlocked($host)||isset($visited[$host])||$pages>=3)continue;$visited[$host]=true;
            $page=$this->httpGet($row['url'],$config,false);$pages++;$sources[]=['url'=>$row['url'],'title'=>$row['title'],'fetched'=>$page!==null];if($page===null)continue;
            $pageContacts=$this->extractFromHtml($page,$country);$this->mergeContacts($contacts,$pageContacts,$contactSources,$row['url']);if(empty($contacts['website'])){$contacts['website']=$this->origin($row['url']);$contactSources['website']=$row['url'];}
            $contactUrl=$this->contactPageUrl($page,$row['url'],$host);if($contactUrl&&$pages<4){$contactPage=$this->httpGet($contactUrl,$config,false);$pages++;$sources[]=['url'=>$contactUrl,'title'=>'Page contact','fetched'=>$contactPage!==null];if($contactPage!==null)$this->mergeContacts($contacts,$this->extractFromHtml($contactPage,$country),$contactSources,$contactUrl);}
            if($contacts['phone']&&$contacts['email']&&$contacts['website']&&($contacts['facebook']||$contacts['instagram']||$contacts['linkedin']))break;
        }
        $answer=['contacts'=>$contacts,'contact_sources'=>$contactSources,'sources'=>$sources,'search_results'=>array_slice($results,0,8),'cached'=>false,'notice'=>'Coordonnées suggérées à vérifier avant enregistrement. Les résultats publics peuvent être incomplets ou correspondre à une autre entreprise.'];$this->cacheSet($key,$answer);return $answer;
    }

    private function parseSearchResults(string $html): array {
        $rows=[];if(preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is',$html,$matches,PREG_SET_ORDER))foreach(array_slice($matches,0,12) as $m){$url=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');if(str_contains($url,'uddg=')){parse_str((string)parse_url($url,PHP_URL_QUERY),$args);$url=(string)($args['uddg']??$url);}$url=$this->validHttpUrl($url);$host=$url?strtolower((string)parse_url($url,PHP_URL_HOST)):'';if($url&&$host&&!$this->isBlocked($host))$rows[]=['url'=>$url,'title'=>trim(html_entity_decode(strip_tags($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8'))];}
        $unique=[];foreach($rows as $row)$unique[$row['url']]=$row;return array_values($unique);
    }

    private function extractFromHtml(string $html,string $country): array {
        $data=['phone'=>null,'email'=>null,'website'=>null,'facebook'=>null,'instagram'=>null,'linkedin'=>null,'address'=>null,'city'=>null,'postcode'=>null];
        if(preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',$html,$scripts))foreach($scripts[1] as $script){$json=json_decode(html_entity_decode($script,ENT_QUOTES|ENT_HTML5,'UTF-8'),true);if(is_array($json))$this->walkStructuredData($json,$data);}
        if(preg_match_all('~href=["\']mailto:([^"\'?]+)~i',$html,$emails))foreach($emails[1] as $email){$email=rawurldecode($email);if(filter_var($email,FILTER_VALIDATE_EMAIL))$this->setIfEmpty($data,'email',strtolower($email));}
        if(preg_match_all('~href=["\']tel:([^"\']+)~i',$html,$phones))foreach($phones[1] as $phone)$this->setIfEmpty($data,'phone',rawurldecode($phone));
        if(preg_match_all('~href=["\']([^"\']+)["\']~i',$html,$hrefs))foreach($hrefs[1] as $href)$this->captureSocial($data,html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        $text=html_entity_decode(strip_tags((string)preg_replace('~<(script|style|noscript)[^>]*>.*?</\1>~is',' ',$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(empty($data['email'])&&preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',$text,$emails))foreach($emails[0] as $email){$email=trim($email,".,;:()[]{}<>\"' ");if(filter_var($email,FILTER_VALIDATE_EMAIL)){$data['email']=strtolower($email);break;}}
        if(empty($data['phone'])){$pattern=$country==='IT'?'/((?:\+39|0039)?\s?(?:0\d{1,3}[\s.\/-]?\d{5,8}|3\d{2}[\s.\/-]?\d{6,8}))/u':'/(?<!\d)(?:\+|00)\d[\d\s().\/-]{7,}\d(?!\d)/u';if(preg_match($pattern,$text,$phone))$data['phone']=trim($phone[0]);}
        if(empty($data['phone'])&&preg_match('/(?<!\d)(?:\+|00)\d[\d\s().\/-]{7,}\d(?!\d)/u',$text,$phone))$data['phone']=trim($phone[0]);
        return $data;
    }

    private function walkStructuredData(array $data,array &$result): void {
        foreach($data as $key=>$value){$key=strtolower((string)$key);if(is_string($value)){if(in_array($key,['telephone','email','streetaddress','addresslocality','postalcode'],true)){$field=['telephone'=>'phone','streetaddress'=>'address','addresslocality'=>'city','postalcode'=>'postcode'][$key]??$key;$this->setIfEmpty($result,$field,trim(strip_tags($value)));}elseif($key==='sameas')$this->captureSocial($result,$value);elseif($key==='url'&&empty($result['website'])&&filter_var($value,FILTER_VALIDATE_URL))$result['website']=$value;}elseif(is_array($value)){if($key==='sameas'){foreach($value as $link)if(is_string($link))$this->captureSocial($result,$link);}else $this->walkStructuredData($value,$result);}}
    }
    private function captureSocial(array &$result,string $url): void {$host=strtolower((string)(parse_url(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'),PHP_URL_HOST)??''));foreach(['facebook.com'=>'facebook','instagram.com'=>'instagram','linkedin.com'=>'linkedin'] as $domain=>$field)if($host===$domain||str_ends_with($host,'.'.$domain))$this->setIfEmpty($result,$field,$url);}
    private function mergeContacts(array &$target,array $source,array &$contactSources,string $sourceUrl): void {foreach($target as $key=>&$value)if(($value===null||$value==='')&&!empty($source[$key])){$value=trim((string)$source[$key]);$contactSources[$key]=$sourceUrl;}unset($value);}
    private function setIfEmpty(array &$row,string $key,string $value): void {$value=trim($value);if(($row[$key]??null)===null&&$value!=='')$row[$key]=$value;}

    private function contactPageUrl(string $html,string $baseUrl,string $host): ?string {
        if(!preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>.*?</a>~is',$html,$matches,PREG_SET_ORDER))return null;
        foreach($matches as $match){$href=html_entity_decode(trim($match[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');if(!preg_match('/contatt|contact|chi-siamo|about-us|impressum/i',$href))continue;$url=$this->resolveUrl($href,$baseUrl);if($url&&strtolower((string)parse_url($url,PHP_URL_HOST))===$host)return $url;}return null;
    }

    private function httpGet(string $url,array $config,bool $search): ?string {
        $host='';$ip='';if(!$search){$url=$this->validHttpUrl($url);if(!$url)return null;$host=strtolower((string)parse_url($url,PHP_URL_HOST));if(!$host||$this->isBlocked($host))return null;$ip=gethostbyname($host);if($ip===$host||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;}
        $this->throttle();$agent=trim((string)($config['sources']['user_agent']??'LeadFinder/1.0'));if($agent===''||str_contains($agent,'admin@example.com'))$agent='LeadFinder/1.0';$body='';$ch=curl_init($url);
        $options=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_USERAGENT=>$agent,CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml;q=0.9'],CURLOPT_TIMEOUT=>$search?18:7,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($curl,$chunk)use(&$body){$remaining=self::MAX_PAGE_BYTES-strlen($body);if($remaining<=0)return 0;$take=substr($chunk,0,$remaining);$body.=$take;return strlen($take);}];
        if(!$search){$port=(int)(parse_url($url,PHP_URL_PORT)??(parse_url($url,PHP_URL_SCHEME)==='https'?443:80));$options[CURLOPT_RESOLVE]=[$host.':'.$port.':'.$ip];}curl_setopt_array($ch,$options);curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=strtolower((string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE));curl_close($ch);
        if(($search&&$status!==200)||(!$search&&($status<200||$status>=300)))return null;if(!$search&&!str_contains($type,'text/html')&&!str_contains($type,'application/xhtml+xml'))return null;return $body!==''?$body:null;
    }
    private function throttle(): void {$file=dirname(__DIR__,2).'/storage/cache/contact-search-last';$dir=dirname($file);if(!is_dir($dir))@mkdir($dir,0775,true);$h=@fopen($file,'c+');if(!$h){usleep(1200000);return;}try{if(flock($h,LOCK_EX)){rewind($h);$last=(float)stream_get_contents($h);$wait=1.2-(microtime(true)-$last);if($wait>0)usleep((int)($wait*1000000));ftruncate($h,0);rewind($h);fwrite($h,(string)microtime(true));fflush($h);flock($h,LOCK_UN);}}finally{fclose($h);}}
    private function validHttpUrl(string $url): ?string {$url=html_entity_decode(trim($url),ENT_QUOTES|ENT_HTML5,'UTF-8');$parts=parse_url($url);if(!$parts||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))return null;$scheme=strtolower((string)$parts['scheme']);$port=(int)($parts['port']??($scheme==='https'?443:80));if(($scheme==='https'&&$port!==443)||($scheme==='http'&&$port!==80))return null;$host=strtolower((string)$parts['host']);if($this->isBlocked($host))return null;if(filter_var($host,FILTER_VALIDATE_IP)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;return $url;}
    private function isBlocked(string $host): bool {foreach(self::BLOCKED_DOMAINS as $domain)if(str_contains($host,$domain))return true;return false;}
    private function origin(string $url): string {$parts=parse_url($url);return strtolower((string)($parts['scheme']??'https')).'://'.strtolower((string)$parts['host']);}
    private function resolveUrl(string $href,string $base): ?string {if(str_starts_with($href,'//'))$href=(string)parse_url($base,PHP_URL_SCHEME).':'.$href;elseif(str_starts_with($href,'/'))$href=$this->origin($base).$href;elseif(!preg_match('~^https?://~i',$href))$href=$this->origin($base).'/'.ltrim($href,'./');return $this->validHttpUrl($href);}
    private function cacheGet(string $key): ?array {$file=dirname(__DIR__,2).'/storage/cache/'.$key.'.json';if(is_file($file)&&filemtime($file)>time()-43200){$data=json_decode((string)file_get_contents($file),true);if(is_array($data)){$data['cached']=true;return $data;}}return null;}
    private function cacheSet(string $key,array $data): void {@file_put_contents(dirname(__DIR__,2).'/storage/cache/'.$key.'.json',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);}
}
