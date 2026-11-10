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
        $country=in_array($country,['IT','FR'],true)?$country:'IT';$key='contact_v3_'.sha1(strtolower($name.'|'.$address.'|'.$postcode.'|'.$city.'|'.$country));$cached=$this->cacheGet($key);if($cached)return $cached;
        $locale=['IT'=>'it-it','FR'=>'fr-fr'][$country];$terms=$country==='IT'?'telefono email contatti sito ufficiale':'téléphone email contact site officiel';
        $query=trim('"'.$name.'" '.$address.' '.$postcode.' '.$city.' '.$terms);$html=$this->httpGet(self::SEARCH_URL.'?'.http_build_query(['q'=>$query,'kl'=>$locale]),$config,true);
        if($html===null)throw new RuntimeException('La recherche web est momentanément indisponible. Réessayez plus tard.');
        $results=$this->parseSearchResults($html);$contacts=['phone'=>null,'email'=>null,'website'=>null,'facebook'=>null,'instagram'=>null,'linkedin'=>null,'address'=>null,'city'=>null,'postcode'=>null];$contactSources=[];$businessInfo=['description'=>null,'opening_hours'=>null];$businessInfoSources=[];$sources=[];$visitedHosts=[];$pagesCrawled=0;$pagesRead=0;
        foreach($results as $row){
            $host=strtolower((string)(parse_url($row['url'],PHP_URL_HOST)??''));if(!$host||$this->isBlocked($host)||isset($visitedHosts[$host])||count($visitedHosts)>=3)continue;$visitedHosts[$host]=true;
            $queue=[$row['url']];$seenUrls=[];$pagesForHost=0;
            while($queue&&$pagesForHost<2&&$pagesCrawled<6){
                $url=array_shift($queue);if(isset($seenUrls[$url]))continue;$seenUrls[$url]=true;
                $page=$this->httpGet($url,$config,false);$pagesCrawled++;$pagesForHost++;
                $evidence=['name_match'=>false,'city_match'=>false,'snippet'=>''];
                $pageContacts=['description'=>null,'opening_hours'=>null];
                if($page!==null){$pagesRead++;$evidence=$this->pageEvidence($page,$name,$city);$pageContacts=$this->extractFromHtml($page,$country);}
                $pageSummary=trim((string)($pageContacts['description']??''));if($pageSummary==='')$pageSummary=$evidence['snippet'];
                $sources[]=['url'=>$url,'title'=>$url===$row['url']?$row['title']:'Page complémentaire','fetched'=>$page!==null,'name_match'=>$evidence['name_match'],'city_match'=>$evidence['city_match'],'summary'=>$pageSummary];
                if($page===null)continue;
                $this->mergeContacts($contacts,$pageContacts,$contactSources,$url);
                foreach(['description','opening_hours'] as $field)if(empty($businessInfo[$field])&&!empty($pageContacts[$field])){$businessInfo[$field]=$pageContacts[$field];$businessInfoSources[$field]=$url;}if(empty($businessInfo['description'])&&($evidence['name_match']||$evidence['city_match'])&&!empty($evidence['snippet'])){$businessInfo['description']=$evidence['snippet'];$businessInfoSources['description']=$url;}
                if(empty($contacts['website'])){$contacts['website']=$this->origin($url);$contactSources['website']=$url;}
                if($pagesForHost<2&&$pagesCrawled<6)$queue=array_merge($queue,$this->relevantPageUrls($page,$url,$host,2-count($seenUrls)));
            }
        }
        $answer=['contacts'=>$contacts,'contact_sources'=>$contactSources,'business_info'=>$businessInfo,'business_info_sources'=>$businessInfoSources,'sources'=>$sources,'search_results'=>array_slice($results,0,8),'pages_crawled'=>$pagesCrawled,'pages_read'=>$pagesRead,'sites_checked'=>count($visitedHosts),'cached'=>false,'notice'=>'Les pages publiques ont été parcourues pour relever des coordonnées et des informations de présentation. Vérifiez leur lien avec le prospect avant tout enregistrement.'];$this->cacheSet($key,$answer);return $answer;
    }

    public function discoverBusinessResults(string $city,string $category,string $country,array $config): array {
        $city=trim($city);$country=in_array($country,['IT','FR'],true)?$country:'IT';
        if($city===''||strlen($city)>480)throw new InvalidArgumentException('Indiquez une ville de moins de 120 caractères.');
        $terms=[
            'IT'=>['all_businesses'=>'attività imprese negozi aziende','restaurant'=>'ristoranti pizzerie','cafe'=>'bar caffetterie','hairdresser'=>'parrucchieri centri estetici','garage'=>'officine autoriparazioni','bakery'=>'panifici pasticcerie','pharmacy'=>'farmacie','hotel'=>'hotel alberghi','fitness'=>'palestre','beauty'=>'centri estetici','dentist'=>'studi dentistici','florist'=>'fioristi','supermarket'=>'supermercati alimentari'],
            'FR'=>['all_businesses'=>'entreprises commerces sociétés','restaurant'=>'restaurants pizzerias','cafe'=>'cafés bars','hairdresser'=>'salons de coiffure instituts de beauté','garage'=>'garages automobiles','bakery'=>'boulangeries pâtisseries','pharmacy'=>'pharmacies','hotel'=>'hôtels hébergements','fitness'=>'salles de sport','beauty'=>'instituts de beauté','dentist'=>'cabinets dentaires','florist'=>'fleuristes','supermarket'=>'supermarchés épiceries'],
        ];
        $categoryTerm=$terms[$country][$category]??($country==='IT'?'attività imprese':'entreprises commerces');
        $categoryQuery='('.str_replace(' ',' OR ',$categoryTerm).')';$query=$country==='IT'?$categoryQuery.' "'.$city.'" sito ufficiale contatti':$categoryQuery.' "'.$city.'" site officiel contact';
        $key='web_leads_'.sha1(strtolower($country.'|'.$category.'|'.$city));$cached=$this->cacheGet($key);if($cached)return $cached;
        $html=$this->httpGet(self::SEARCH_URL.'?'.http_build_query(['q'=>$query,'kl'=>$country==='IT'?'it-it':'fr-fr']),$config,true);
        if($html===null)throw new RuntimeException('Le moteur de recherche web est momentanément indisponible.');
        $results=array_slice($this->parseSearchResults($html),0,10);
        $answer=['results'=>$results,'cached'=>false,'notice'=>'Pistes issues d’un moteur web public, non vérifiées et susceptibles d’être limitées. Ouvrez chaque source et confirmez qu’il s’agit bien d’un prospect avant de l’ajouter.'];
        $this->cacheSet($key,$answer);return $answer;
    }

    private function parseSearchResults(string $html): array {
        $rows=[];if(preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is',$html,$matches,PREG_SET_ORDER))foreach(array_slice($matches,0,12) as $m){$url=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');if(str_contains($url,'uddg=')){parse_str((string)parse_url($url,PHP_URL_QUERY),$args);$url=(string)($args['uddg']??$url);}$url=$this->validHttpUrl($url);$host=$url?strtolower((string)parse_url($url,PHP_URL_HOST)):'';if($url&&$host&&!$this->isBlocked($host))$rows[]=['url'=>$url,'title'=>trim(html_entity_decode(strip_tags($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8'))];}
        $unique=[];foreach($rows as $row)$unique[$row['url']]=$row;return array_values($unique);
    }

    private function extractFromHtml(string $html,string $country): array {
        $data=['phone'=>null,'email'=>null,'website'=>null,'facebook'=>null,'instagram'=>null,'linkedin'=>null,'address'=>null,'city'=>null,'postcode'=>null,'description'=>null,'opening_hours'=>null];
        if(preg_match_all('~<meta\b[^>]*>~is',$html,$metaTags))foreach($metaTags[0] as $tag){$identity='';$content='';if(preg_match('~(?:name|property)=["\'](?:description|og:description|twitter:description)["\']~i',$tag))$identity='description';if(preg_match('~(?:name|property)=["\'](?:business:contact_data:phone_number)["\']~i',$tag))$identity='phone';if(preg_match('~content=["\'](.*?)["\']~is',$tag,$match))$content=html_entity_decode(strip_tags($match[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');if($identity!==''&&$content!=='')$this->setIfEmpty($data,$identity,$content);}
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
        foreach($data as $key=>$value){$key=strtolower((string)$key);if(is_string($value)){if(in_array($key,['telephone','email','streetaddress','addresslocality','postalcode','description','openinghours'],true)){$field=['telephone'=>'phone','streetaddress'=>'address','addresslocality'=>'city','postalcode'=>'postcode','openinghours'=>'opening_hours'][$key]??$key;$this->setIfEmpty($result,$field,trim(strip_tags($value)));}elseif($key==='sameas')$this->captureSocial($result,$value);elseif($key==='url'&&empty($result['website'])&&filter_var($value,FILTER_VALIDATE_URL))$result['website']=$value;}elseif(is_array($value)){if($key==='sameas'){foreach($value as $link)if(is_string($link))$this->captureSocial($result,$link);}elseif($key==='openinghours'&&array_is_list($value)){$hours=implode(', ',array_map('strval',array_filter($value,'is_scalar')));$this->setIfEmpty($result,'opening_hours',$hours);}else $this->walkStructuredData($value,$result);}}
    }
    private function captureSocial(array &$result,string $url): void {$host=strtolower((string)(parse_url(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'),PHP_URL_HOST)??''));foreach(['facebook.com'=>'facebook','instagram.com'=>'instagram','linkedin.com'=>'linkedin'] as $domain=>$field)if($host===$domain||str_ends_with($host,'.'.$domain))$this->setIfEmpty($result,$field,$url);}
    private function mergeContacts(array &$target,array $source,array &$contactSources,string $sourceUrl): void {foreach($target as $key=>&$value)if(($value===null||$value==='')&&!empty($source[$key])){$value=trim((string)$source[$key]);$contactSources[$key]=$sourceUrl;}unset($value);}
    private function setIfEmpty(array &$row,string $key,string $value): void {$value=trim($value);if(function_exists('iconv')){$clean=iconv('UTF-8','UTF-8//IGNORE',$value);if($clean!==false)$value=$clean;}if(($row[$key]??null)===null&&$value!=='')$row[$key]=$value;}

    private function pageEvidence(string $html,string $name,string $city): array {
        $text=html_entity_decode(strip_tags((string)preg_replace('~<(script|style|noscript|svg)[^>]*>.*?</\1>~is',' ',$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=trim(preg_replace('/\s+/u',' ',$text)??$text);$nameMatch=$name!==''&&stripos($text,$name)!==false;$cityMatch=$city!==''&&stripos($text,$city)!==false;
        $needle=$nameMatch?$name:($cityMatch?$city:'');$position=$needle!==''?stripos($text,$needle):false;
        if($position!==false){$start=max(0,$position-100);$snippet=substr($text,$start,300);if($start>0)$snippet='…'.$snippet;if($start+300<strlen($text))$snippet.='…';}
        else $snippet=substr($text,0,240);
        if(function_exists('iconv')){$clean=iconv('UTF-8','UTF-8//IGNORE',$snippet);if($clean!==false)$snippet=$clean;}
        return ['name_match'=>$nameMatch,'city_match'=>$cityMatch,'snippet'=>trim($snippet)];
    }

    /** Pick same-site pages likely to contain useful prospect details. */
    private function relevantPageUrls(string $html,string $baseUrl,string $host,int $limit=1): array {
        if($limit<1||!preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is',$html,$matches,PREG_SET_ORDER))return [];
        $candidates=[];$basePath=(string)(parse_url($baseUrl,PHP_URL_PATH)??'');
        foreach($matches as $match){
            $href=html_entity_decode(trim($match[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');$linkText=html_entity_decode(strip_tags($match[2]),ENT_QUOTES|ENT_HTML5,'UTF-8');$hint=strtolower($href.' '.$linkText);
            if(preg_match('/privacy|cookie|facebook|instagram|linkedin|twitter|youtube|login|logout|javascript:/i',$hint))continue;
            $score=0;
            if(preg_match('/contact|contatt|contatti|chi[-_ ]?siamo|about|azienda|societa|company|impressum|legal[-_ ]?notice|mentions-legales/i',$hint))$score=100;
            elseif(preg_match('/servizi|services|service|attivita|specialita|prodotti|products|offerta/i',$hint))$score=70;
            if(!$score)continue;
            $url=$this->resolveUrl($href,$baseUrl);if(!$url||strtolower((string)parse_url($url,PHP_URL_HOST))!==$host)continue;
            $parts=parse_url($url);$path=(string)($parts['path']??'/');if($path===$basePath)continue;
            $canonical=strtolower((string)($parts['scheme']??'https')).'://'.strtolower((string)$parts['host']).$path;
            if(isset($candidates[$canonical]))continue;$candidates[$canonical]=['url'=>$url,'score'=>$score];
        }
        usort($candidates,static fn($a,$b)=>$b['score']<=>$a['score']);return array_slice(array_column($candidates,'url'),0,min(2,$limit));
    }

    private function httpGet(string $url,array $config,bool $search): ?string {
        $host='';$ip='';if(!$search){$url=$this->validHttpUrl($url);if(!$url)return null;$host=strtolower((string)parse_url($url,PHP_URL_HOST));if(!$host||$this->isBlocked($host))return null;$ip=gethostbyname($host);if($ip===$host||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;}
        $this->throttle();$agent=trim((string)($config['sources']['user_agent']??'LeadFinder/1.0'));if($agent===''||str_contains($agent,'admin@example.com'))$agent='LeadFinder/1.0';$body='';$ch=curl_init($url);
        $options=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_USERAGENT=>$agent,CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml;q=0.9'],CURLOPT_TIMEOUT=>$search?12:4,CURLOPT_CONNECTTIMEOUT=>$search?6:3,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($curl,$chunk)use(&$body){$remaining=self::MAX_PAGE_BYTES-strlen($body);if($remaining<=0)return 0;$take=substr($chunk,0,$remaining);$body.=$take;return strlen($take);}];
        if(!$search){$port=(int)(parse_url($url,PHP_URL_PORT)??(parse_url($url,PHP_URL_SCHEME)==='https'?443:80));$options[CURLOPT_RESOLVE]=[$host.':'.$port.':'.$ip];}curl_setopt_array($ch,$options);curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=strtolower((string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE));curl_close($ch);
        if(($search&&$status!==200)||(!$search&&($status<200||$status>=300)))return null;if(!$search&&!str_contains($type,'text/html')&&!str_contains($type,'application/xhtml+xml'))return null;return $body!==''?$body:null;
    }
    private function throttle(): void {$file=dirname(__DIR__,2).'/storage/cache/contact-search-last';$dir=dirname($file);if(!is_dir($dir))@mkdir($dir,0775,true);$h=@fopen($file,'c+');if(!$h){usleep(1200000);return;}try{if(flock($h,LOCK_EX)){rewind($h);$last=(float)stream_get_contents($h);$wait=1.2-(microtime(true)-$last);if($wait>0)usleep((int)($wait*1000000));ftruncate($h,0);rewind($h);fwrite($h,(string)microtime(true));fflush($h);flock($h,LOCK_UN);}}finally{fclose($h);}}
    private function validHttpUrl(string $url): ?string {$url=html_entity_decode(trim($url),ENT_QUOTES|ENT_HTML5,'UTF-8');$parts=parse_url($url);if(!$parts||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))return null;$scheme=strtolower((string)$parts['scheme']);$port=(int)($parts['port']??($scheme==='https'?443:80));if(($scheme==='https'&&$port!==443)||($scheme==='http'&&$port!==80))return null;$host=strtolower((string)$parts['host']);if($this->isBlocked($host))return null;if(filter_var($host,FILTER_VALIDATE_IP)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;return $url;}
    private function isBlocked(string $host): bool {foreach(self::BLOCKED_DOMAINS as $domain)if(str_contains($host,$domain))return true;return false;}
    private function origin(string $url): string {$parts=parse_url($url);return strtolower((string)($parts['scheme']??'https')).'://'.strtolower((string)$parts['host']);}
    private function resolveUrl(string $href,string $base): ?string {
        $href=html_entity_decode(trim($href),ENT_QUOTES|ENT_HTML5,'UTF-8');$baseParts=parse_url($base);if(!$baseParts)return null;
        if(str_starts_with($href,'//'))$href=(string)($baseParts['scheme']??'https').':'.$href;
        elseif(!preg_match('~^https?://~i',$href)){
            $origin=$this->origin($base);$hrefParts=parse_url($href);$path=(string)($hrefParts['path']??'');
            if(str_starts_with($path,'/'))$resolved=$path;
            else{$basePath=(string)($baseParts['path']??'/');$directory=substr($basePath,0,strrpos($basePath,'/')!==false?strrpos($basePath,'/')+1:0);$resolved=$directory.$path;}
            $segments=[];foreach(explode('/',$resolved) as $segment){if($segment==='..')array_pop($segments);elseif($segment!=='.')$segments[]=$segment;}$resolvedPath=implode('/',$segments);if(!str_starts_with($resolvedPath,'/'))$resolvedPath='/'.$resolvedPath;
            $href=$origin.$resolvedPath;if(!empty($hrefParts['query']))$href.='?'.$hrefParts['query'];
        }
        return $this->validHttpUrl($href);
    }
    private function cacheGet(string $key): ?array {$file=dirname(__DIR__,2).'/storage/cache/'.$key.'.json';if(is_file($file)&&filemtime($file)>time()-43200){$data=json_decode((string)file_get_contents($file),true);if(is_array($data)){$data['cached']=true;return $data;}}return null;}
    private function cacheSet(string $key,array $data): void {@file_put_contents(dirname(__DIR__,2).'/storage/cache/'.$key.'.json',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);}
}
