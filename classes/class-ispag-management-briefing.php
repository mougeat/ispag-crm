<?php
defined('ABSPATH') || exit;

/**
 * API en lecture seule pour le point du lundi (routine hebdomadaire) : chiffres de la semaine et offres à relancer.
 *
 *   GET /wp-json/ispag/v1/management-briefing[?ref=YYYY-MM-DD]
 *
 * Droit : export_management_briefing, à donner à un compte API dédié (fiche utilisateur), jamais à un rôle.
 * Contient des montants HT et les noms des offres à relancer ; aucune marge, aucun coefficient, aucun contact.
 */
class ISPAG_Management_Briefing {

    const CAP = 'export_management_briefing';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('ispag/v1', '/management-briefing', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_briefing'],
            'permission_callback' => [$this, 'can_read'],
            'args'                => ['ref' => ['required' => false, 'validate_callback' => function ($v) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v); }]],
        ]);
    }

    public function can_read() {
        return is_user_logged_in() && current_user_can(self::CAP);
    }

    public function rest_briefing($req) {
        $ref = (string) ($req->get_param('ref') ?: wp_date('Y-m-d'));
        $data = ISPAG_Deal_Kpis::weekly_summary($ref);
        // Agenda de l'iPhone (synchronisé par un Raccourci) : 7 jours à partir de la date de référence
        $to = date('Y-m-d', strtotime($ref . ' +6 days'));
        $ag = ISPAG_Agenda_Sync::events($ref, $to);
        $age_h = !empty($ag['synced_at']) ? (time() - strtotime($ag['synced_at'])) / 3600 : null;
        $data['agenda'] = [
            'from'      => $ref, 'to' => $to,
            'synced_at' => $ag['synced_at'],
            'stale'     => $age_h === null || $age_h > 48,
            'events'    => $ag['events'],
        ];
        $data['week_plan'] = self::week_plan($ref);
        $data['todo']      = self::todo();
        return rest_ensure_response($data);
    }

    /**
     * Mardi et jeudi de la semaine : cantons choisis dans « Ma semaine » et, pour chaque jour, les offres ouvertes des entreprises de ces cantons
     * (les plus importantes d'abord, 12 au plus) dans un ordre de visite suggéré (plus proche voisin, distances à vol d'oiseau).
     */
    private static function week_plan($ref) {
        $monday = date('Y-m-d', strtotime('monday this week', strtotime($ref)));
        $days   = ISPAG_Week_Plan::resolve($monday);
        $offers = ISPAG_Deal_Kpis::open_offers_list($ref);
        $locs   = ISPAG_Swiss_Geo::companies(array_column($offers, 'company_id'));
        $unknown = 0;
        foreach ($offers as $o) if (empty($locs[$o['company_id']]['canton'])) $unknown++;
        $cantons_names = ISPAG_Swiss_Geo::cantons();
        $out = [];
        foreach ($days as $d) {
            $row = ['day' => $d['label'], 'date' => $d['date'], 'cantons' => $d['cantons'], 'cantons_names' => array_map(function ($c) use ($cantons_names) { return $cantons_names[$c] ?? $c; }, $d['cantons']), 'source' => $d['source'], 'visits' => []];
            if ($d['cantons']) {
                $cand = [];
                foreach ($offers as $o) {
                    $l = $locs[$o['company_id']] ?? null;
                    if ($l && in_array($l['canton'], $d['cantons'], true)) $cand[] = $o + ['city' => $l['city'], 'plz' => $l['plz'], 'canton' => $l['canton'], 'lat' => $l['lat'], 'lon' => $l['lon']];
                }
                $total = count($cand);
                $cand = ISPAG_Swiss_Geo::route(array_slice($cand, 0, 12));
                foreach ($cand as &$c) unset($c['lat'], $c['lon']);
                unset($c);
                $row['visits'] = $cand; $row['offers_in_cantons'] = $total;
            }
            $out[] = $row;
        }
        return ['monday' => $monday, 'days' => $out, 'offers_without_location' => $unknown];
    }

    /** Tâches ouvertes du CRM de la personne choisie dans « Ma semaine » (échues et à venir, 40 au plus). */
    private static function todo() {
        global $wpdb;
        $uid = (int) (ISPAG_Week_Plan::get()['todo_user'] ?? 0);
        if ($uid <= 0) return ['user' => null, 'items' => []];
        $t = ISPAG_Note_Manager::TABLE_NOTE;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, content, due_date, reminder_date, priority, deal_id, company_id FROM {$t}
              WHERE user_id = %d AND is_task = 1 AND (is_completed = 0 OR is_completed IS NULL)
                AND type NOT IN ('HEALTH_REMINDER','EMAIL_CAMPAIGN','EMAIL_TRANSACTIONAL','CHRISTMAS_PRESENT','STAGE','SYSTEM')
              ORDER BY COALESCE(due_date, reminder_date, '9999-12-31') ASC, id ASC LIMIT 40", $uid));
        $now = current_time('mysql');
        $items = [];
        foreach ((array) $rows as $r) {
            $due = $r->due_date ?: $r->reminder_date;
            $items[] = [
                'id'       => (int) $r->id,
                'title'    => mb_substr(trim(wp_strip_all_tags((string) ($r->title ?: $r->content))), 0, 160),
                'detail'   => $r->title ? mb_substr(trim(wp_strip_all_tags((string) $r->content)), 0, 240) : '',
                'due'      => $due,
                'overdue'  => $due ? $due < $now : false,
                'priority' => (string) $r->priority,
            ];
        }
        $u = get_userdata($uid);
        return ['user' => $u ? $u->display_name : null, 'items' => $items];
    }
}
