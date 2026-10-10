<?php
defined('ABSPATH') || exit;

/** Cantons suisses : canton d'une entreprise d'après son code postal (sinon sa localité), coordonnées et ordre de tournée. */
class ISPAG_Swiss_Geo {

    private static $data = null;

    public static function cantons() {
        return [
            'AG' => 'Argovie', 'AI' => 'Appenzell Rhodes-Intérieures', 'AR' => 'Appenzell Rhodes-Extérieures', 'BE' => 'Berne', 'BL' => 'Bâle-Campagne',
            'BS' => 'Bâle-Ville', 'FR' => 'Fribourg', 'GE' => 'Genève', 'GL' => 'Glaris', 'GR' => 'Grisons', 'JU' => 'Jura', 'LU' => 'Lucerne',
            'NE' => 'Neuchâtel', 'NW' => 'Nidwald', 'OW' => 'Obwald', 'SG' => 'Saint-Gall', 'SH' => 'Schaffhouse', 'SO' => 'Soleure', 'SZ' => 'Schwytz',
            'TG' => 'Thurgovie', 'TI' => 'Tessin', 'UR' => 'Uri', 'VD' => 'Vaud', 'VS' => 'Valais', 'ZG' => 'Zoug', 'ZH' => 'Zurich',
        ];
    }

    private static function data() {
        if (self::$data === null) self::$data = include ISPAG_CRM_PLUGIN_DIR . 'includes/data/ch-postal-codes.php';
        return self::$data;
    }

    private static function norm($s) {
        $s = remove_accents((string) $s);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
    }

    /** @return array{canton:?string, lat:?float, lon:?float} */
    public static function locate($plz, $city = '', $forced = '') {
        $d = self::data();
        $plz = preg_replace('/\D/', '', (string) $plz);
        $plz = strlen($plz) >= 4 ? substr($plz, 0, 4) : '';
        $canton = null; $lat = null; $lon = null;
        if ($plz !== '' && isset($d['plz'][$plz])) { [$canton, $lat, $lon] = $d['plz'][$plz]; }
        if ($canton === null || $lat === null) {
            $n = self::norm($city);
            if ($n !== '' && isset($d['city'][$n])) {
                [$ck, $cla, $clo] = $d['city'][$n];
                if ($canton === null) $canton = $ck;
                if ($lat === null) { $lat = $cla; $lon = $clo; }
            }
        }
        $forced = strtoupper(trim((string) $forced));
        if ($forced !== '' && isset(self::cantons()[$forced])) $canton = $forced;   // canton saisi à la main : prioritaire
        return ['canton' => $canton, 'lat' => $lat, 'lon' => $lon];
    }

    /** Localisation de plusieurs entreprises : company_id => [city, plz, canton, lat, lon]. */
    public static function companies($ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $meta = $wpdb->prefix . 'ispag_companies_meta';
        $rows = $wpdb->get_results("SELECT company_id, meta_key, meta_value FROM {$meta} WHERE company_id IN ({$in}) AND meta_key IN ('ispag_company_city','ispag_company_postal_code','ispag_company_canton')");
        $by = [];
        foreach ($rows as $r) $by[(int) $r->company_id][$r->meta_key] = (string) $r->meta_value;
        $city_col = $wpdb->get_results("SELECT Id, city FROM {$wpdb->prefix}ispag_companies WHERE Id IN ({$in})");
        foreach ($city_col as $c) if (empty($by[(int) $c->Id]['ispag_company_city'])) $by[(int) $c->Id]['ispag_company_city'] = (string) $c->city;
        $out = [];
        foreach ($ids as $id) {
            $m = $by[$id] ?? [];
            $loc = self::locate($m['ispag_company_postal_code'] ?? '', $m['ispag_company_city'] ?? '', $m['ispag_company_canton'] ?? '');
            $out[$id] = ['city' => $m['ispag_company_city'] ?? '', 'plz' => $m['ispag_company_postal_code'] ?? ''] + $loc;
        }
        return $out;
    }

    public static function distance_km($a, $b) {
        $r = 6371; $p1 = deg2rad($a['lat']); $p2 = deg2rad($b['lat']);
        $dp = $p2 - $p1; $dl = deg2rad($b['lon'] - $a['lon']);
        $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($h)));
    }

    /**
     * Ordre de visite suggéré : on part de l'élément le plus important (premier de la liste), puis toujours le plus proche suivant.
     * Les éléments sans coordonnées restent à la fin, dans l'ordre reçu. Chaque élément reçoit « km_from_previous » (à vol d'oiseau).
     */
    public static function route(array $items) {
        $with = []; $without = [];
        foreach ($items as $it) { if (isset($it['lat']) && $it['lat'] !== null) $with[] = $it; else $without[] = $it; }
        $ordered = [];
        if ($with) {
            $cur = array_shift($with); $cur['km_from_previous'] = 0; $ordered[] = $cur;
            while ($with) {
                $best = 0; $bd = INF;
                foreach ($with as $i => $c) { $d = self::distance_km($cur, $c); if ($d < $bd) { $bd = $d; $best = $i; } }
                $cur = $with[$best]; unset($with[$best]); $with = array_values($with);
                $cur['km_from_previous'] = round($bd); $ordered[] = $cur;
            }
        }
        foreach ($without as $it) { $it['km_from_previous'] = null; $ordered[] = $it; }
        return $ordered;
    }
}
