<?php
final class PhoneNormalizer {
    public static function normalize(?string $phone, string $country='IT'): ?string {
        if (!$phone) return null;
        $s=trim($phone); $digits=preg_replace('/\D+/', '', $s);
        if (str_starts_with($s,'+')) return '+'.$digits;
        if (str_starts_with($digits,'00')) return '+'.substr($digits,2);
        $prefix=['FR'=>'33','MA'=>'212','DZ'=>'213','IT'=>'39'][$country]??null;
        if(!$prefix) return $digits?'+'.$digits:null;
        $national=match($country){'FR'=>9,'MA'=>9,'DZ'=>9,'IT'=>10,default=>0};
        if(str_starts_with($digits,$prefix) && strlen($digits)>$national) return '+'.$digits;
        if($country==='FR' && str_starts_with($digits,'0')) $digits=substr($digits,1);
        if(in_array($country,['MA','DZ'],true) && str_starts_with($digits,'0')) $digits=substr($digits,1);
        return $digits?'+'.$prefix.$digits:null;
    }
    public static function whatsapp(?string $phone): ?string { $n=self::normalize($phone); return $n ? 'https://wa.me/'.preg_replace('/\D/','',$n) : null; }
}
