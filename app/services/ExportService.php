<?php
final class ExportService {
    public static function csv(array $rows,array $columns): never {
        $allowed=['nom'=>'Nom','categorie'=>'Catégorie','adresse'=>'Adresse','ville'=>'Ville','code_postal'=>'Code postal','telephone'=>'Téléphone','email'=>'Email','facebook'=>'Facebook','instagram'=>'Instagram','site_web_statut'=>'Site web','score_confiance'=>'Confiance','statut_prospection'=>'Statut','latitude'=>'Latitude','longitude'=>'Longitude','created_at'=>'Ajouté'];
        $columns=array_values(array_intersect($columns,array_keys($allowed)));if(!$columns)$columns=array_keys($allowed);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="prime-leads-'.date('Ymd-His').'.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');fputcsv($out,array_map(fn($c)=>$allowed[$c],$columns),';');foreach($rows as $r)fputcsv($out,array_map(fn($c)=>$r[$c]??'',$columns),';');fclose($out);exit;
    }
}
