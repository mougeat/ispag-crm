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
        $this->register_send_route();
        register_rest_route('ispag/v1', '/management-briefing', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_briefing'],
            'permission_callback' => [$this, 'can_read'],
            'args'                => ['ref' => ['required' => false, 'validate_callback' => function ($v) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v); }]],
        ]);
    }

    public function register_send_route() {
        register_rest_route('ispag/v1', '/management-briefing/digest', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_send'],
            'permission_callback' => [$this, 'can_read'],
        ]);
    }

    const SEND_LIMIT = 15;   // envois par jour, compteur propre au point du lundi (indépendant des autres routines)

    /**
     * Envoi du point du lundi par le site (wp_mail), à l'adresse réglée côté site : option ispag_briefing_to, sinon celle des
     * propositions LinkedIn (ispag_pub_digest_to), sinon l'adresse de l'administrateur. L'appelant ne choisit jamais le destinataire.
     * Corps : texte (champ « body ») et, facultatif, version mise en page (champ « html », nettoyée).
     */
    public function rest_send($req) {
        $to = sanitize_email((string) get_option('ispag_briefing_to', get_option('ispag_pub_digest_to', get_option('admin_email'))));
        if (!is_email($to)) return new WP_Error('no_recipient', 'No valid recipient.', ['status' => 500]);
        $count = (int) get_transient('ispag_briefing_digest_count');
        if ($count >= self::SEND_LIMIT) return new WP_Error('rate_limited', 'Too many briefing e-mails today.', ['status' => 429]);
        $subject = mb_substr(sanitize_text_field((string) $req->get_param('subject')), 0, 150);
        $text    = mb_substr(wp_strip_all_tags((string) $req->get_param('body')), 0, 30000);
        $html    = (string) $req->get_param('html');
        if ($subject === '' || ($text === '' && $html === '')) return new WP_Error('empty', 'Subject and body are required.', ['status' => 400]);
        if ($html !== '') {
            $ok = wp_mail($to, $subject, wp_kses_post(mb_substr($html, 0, 120000)), ['Content-Type: text/html; charset=UTF-8']);
        } else {
            $ok = wp_mail($to, $subject, $text, ['Content-Type: text/plain; charset=UTF-8']);
        }
        if ($ok) set_transient('ispag_briefing_digest_count', $count + 1, DAY_IN_SECONDS);   // un envoi qui échoue ne consomme pas la limite
        return rest_ensure_response(['sent' => (bool) $ok, 'remaining_today' => max(0, self::SEND_LIMIT - $count - ($ok ? 1 : 0))]);
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
                $by = [];
                foreach ($cand as $o) {
                    $cid = $o['company_id'];
                    if (!isset($by[$cid])) $by[$cid] = ['company_id' => $cid, 'company' => $o['company'], 'city' => $o['city'], 'plz' => $o['plz'], 'canton' => $o['canton'], 'lat' => $o['lat'], 'lon' => $o['lon'], 'total_amount' => 0, 'deals' => []];
                    $by[$cid]['total_amount'] += $o['amount'];
                    $by[$cid]['deals'][] = ['project' => $o['project'], 'amount' => $o['amount'], 'stage' => $o['stage'], 'idle_days' => $o['idle_days'], 'owner' => $o['owner'], 'link' => $o['link'], 'group_id' => $o['id']];
                }
                $companies = array_values($by);
                usort($companies, function ($a, $b) { return $b['total_amount'] <=> $a['total_amount']; });
                $row['companies_in_cantons'] = count($companies);
                $companies = ISPAG_Swiss_Geo::route(array_slice($companies, 0, 8));
                foreach ($companies as &$c) {
                    unset($c['lat'], $c['lon']);
                    $c['total_amount'] = round($c['total_amount'], 2);
                    $c['contacts'] = self::contacts_to_see($c['company_id'], array_column($c['deals'], 'group_id'));
                    foreach ($c['deals'] as &$dd) unset($dd['group_id']);
                    unset($dd);
                }
                unset($c);
                $row['visits'] = $companies; $row['offers_in_cantons'] = $total;
            }
            $out[] = $row;
        }
        return ['monday' => $monday, 'days' => $out, 'offers_without_location' => $unknown];
    }


    /** Personnes à voir pour une entreprise : contacts rattachés aux offres ouvertes (à défaut, contacts de l'entreprise), chefs de projet en premier. */
    private static function contacts_to_see($company_id, array $deal_ids) {
        global $wpdb;
        $l = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $ids = [];
        if ($deal_ids) {
            $in = implode(',', array_map('intval', $deal_ids));
            // Tous les contacts de toutes les lignes (offre, commande…) des dossiers concernés.
            $lists = $wpdb->get_col("SELECT x.associated_contact_ids FROM {$l} x WHERE x.deal_group_ref IN (SELECT deal_group_ref FROM {$l} WHERE id IN ({$in})) AND x.associated_contact_ids <> ''");
            foreach ((array) $lists as $csv) foreach (explode(',', (string) $csv) as $i) if ((int) $i > 0) $ids[(int) $i] = true;
        }
        $from_deals = !empty($ids);
        if (!$ids) {
            $found = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 30", ISPAG_Crm_Contact_Constants::META_COMPANY_ID, (string) $company_id));
            foreach ((array) $found as $i) $ids[(int) $i] = true;
        }
        $out = [];
        foreach (array_keys($ids) as $uid) {
            $u = get_userdata($uid);
            if (!$u) continue;
            $fn = trim((string) get_user_meta($uid, ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION, true));
            $out[] = [
                'name'     => trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name,
                'function' => $fn,
                'project_manager' => (bool) preg_match('/chef\s+de\s+(projet|chantier)|responsable\s+de\s+projet|conducteur\s+de\s+travaux|projektleiter|bauleiter|project\s+manager|\bCDP\b/iu', $fn),
                'phone'    => (string) get_user_meta($uid, ISPAG_Crm_Contact_Constants::META_LEAD_PHONE, true),
                'on_offer' => $from_deals,
            ];
        }
        usort($out, function ($a, $b) { return [$b['project_manager'], $a['name']] <=> [$a['project_manager'], $b['name']]; });
        return array_slice($out, 0, 5);
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
