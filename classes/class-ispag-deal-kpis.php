<?php
defined('ABSPATH') || exit;

/**
 * Indicateurs commerciaux calculés sur les deals du CRM (une ligne par dossier = deal_group_ref).
 *
 * Un dossier est :
 *  - GAGNÉ   : étape « open_won » (en réalisation) ou « closed_won » ;
 *  - PERDU   : étape « closed_lost » ;
 *  - OUVERT  : toute autre étape (offre en cours).
 * Date d'un gain ou d'une perte = date de clôture du dossier, sinon date de dernière mise à jour de l'étape.
 * Date d'une offre rédigée = date de création de la première ligne « Offre » du dossier.
 * Montant d'un dossier = montant HT de sa dernière ligne (offre, commande ou facture).
 *
 * Taux de transformation = gagnés / (gagnés + perdus) sur les dossiers clos pendant la période.
 */
class ISPAG_Deal_Kpis {

    const WON_STAGES  = ['open_won', 'closed_won'];
    const LOST_STAGES = ['closed_lost'];
    const FOLLOW_UP_DAYS = 90;

    /** Table dérivée : un dossier par ligne, avec étape, date de dernière activité, montant et date de l'offre. */
    public static function groups_sql() {
        $l = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $s = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES;
        $amount = "CAST(REPLACE(REPLACE(REPLACE(NULLIF(l.total_excl_vat, ''), '''', ''), ' ', ''), ',', '.') AS DECIMAL(14,2))";
        return "SELECT l.id, l.deal_group_ref, l.project_name, l.associated_company_id, l.deal_owner,
                       {$amount} AS amount,
                       s.current_stage_key AS stage, s.last_updated,
                       COALESCE(NULLIF(l.closing_date, '0000-00-00'), DATE(s.last_updated)) AS end_date,
                       (SELECT MIN(o.date_creation) FROM {$l} o
                         WHERE o.deal_group_ref = l.deal_group_ref AND o.process_type LIKE '%Offre%') AS offer_date
                FROM {$l} l
                INNER JOIN (SELECT MAX(id) AS mid FROM {$l} WHERE deal_group_ref IS NOT NULL AND deal_group_ref <> '' GROUP BY deal_group_ref) m ON m.mid = l.id
                LEFT JOIN {$s} s ON (s.deal_group_ref COLLATE utf8mb4_unicode_ci) = (l.deal_group_ref COLLATE utf8mb4_unicode_ci)";
    }

    private static function in_list(array $keys) {
        return "'" . implode("','", array_map('esc_sql', $keys)) . "'";
    }

    /** Offres rédigées entre deux dates (incluses, Y-m-d). */
    public static function offers_written($from, $to) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (" . self::groups_sql() . ") g WHERE g.offer_date BETWEEN %s AND %s", $from, $to));
    }

    /** Commandes (dossiers gagnés) entre deux dates : nombre et montant HT. */
    public static function orders($from, $to) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(g.amount), 0) AS total FROM (" . self::groups_sql() . ") g
              WHERE g.stage IN (" . self::in_list(self::WON_STAGES) . ") AND g.end_date BETWEEN %s AND %s", $from, $to));
        return ['count' => (int) ($row->n ?? 0), 'amount' => round((float) ($row->total ?? 0), 2)];
    }

    /** Dossiers clos (gagnés / perdus) entre deux dates et taux de transformation en %, null si aucun dossier clos. */
    public static function conversion($from, $to) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(g.stage IN (" . self::in_list(self::WON_STAGES) . ")) AS won, SUM(g.stage IN (" . self::in_list(self::LOST_STAGES) . ")) AS lost
               FROM (" . self::groups_sql() . ") g
              WHERE g.stage IN (" . self::in_list(array_merge(self::WON_STAGES, self::LOST_STAGES)) . ") AND g.end_date BETWEEN %s AND %s", $from, $to));
        return self::rate((int) ($row->won ?? 0), (int) ($row->lost ?? 0));
    }

    public static function rate($won, $lost) {
        $closed = $won + $lost;
        return ['won' => $won, 'lost' => $lost, 'rate' => $closed > 0 ? round($won * 100 / $closed, 1) : null];
    }

    /**
     * Offres ouvertes sans mouvement depuis plus de $days jours (dernière mise à jour d'étape, sinon date de l'offre).
     * @return array{count:int, amount:float, items:array}
     */
    public static function follow_up($today, $days = self::FOLLOW_UP_DAYS, $limit = 25) {
        global $wpdb;
        $companies = $wpdb->prefix . 'ispag_companies';
        $sql = "SELECT g.id, g.project_name, g.amount, g.stage, g.offer_date, g.last_updated, c.company_name, u.display_name AS owner,
                       DATEDIFF(%s, DATE(COALESCE(g.last_updated, g.offer_date))) AS idle_days
                  FROM (" . self::groups_sql() . ") g
                  LEFT JOIN {$companies} c ON c.Id = g.associated_company_id
                  LEFT JOIN {$wpdb->users} u ON u.ID = g.deal_owner
                 WHERE g.offer_date IS NOT NULL
                   AND (g.stage IS NULL OR g.stage NOT IN (" . self::in_list(array_merge(self::WON_STAGES, self::LOST_STAGES)) . "))
                   AND COALESCE(g.last_updated, g.offer_date) IS NOT NULL
                   AND DATEDIFF(%s, DATE(COALESCE(g.last_updated, g.offer_date))) >= %d
                 ORDER BY g.amount DESC";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $today, $today, (int) $days));
        $items = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) $r->amount;
            if (count($items) < $limit) {
                $items[] = [
                    'id'        => (int) $r->id,
                    'company'   => (string) ($r->company_name ?: ''),
                    'project'   => (string) $r->project_name,
                    'amount'    => round((float) $r->amount, 2),
                    'stage'     => (string) ($r->stage ?: ''),
                    'idle_days' => (int) $r->idle_days,
                    'owner'     => (string) ($r->owner ?: ''),
                    'link'      => trailingslashit(home_url('/deal/' . (int) $r->id . '/')),
                ];
            }
        }
        return ['threshold_days' => (int) $days, 'count' => count($rows), 'amount' => round($total, 2), 'items' => $items];
    }

    /** Toutes les offres ouvertes (une par dossier), avec leur entreprise : base des propositions de visites. */
    public static function open_offers_list($today) {
        global $wpdb;
        $companies = $wpdb->prefix . 'ispag_companies';
        $sql = "SELECT g.id, g.associated_company_id AS company_id, g.project_name, g.amount, g.stage, c.company_name, u.display_name AS owner,
                       DATEDIFF(%s, DATE(COALESCE(g.last_updated, g.offer_date))) AS idle_days
                  FROM (" . self::groups_sql() . ") g
                  LEFT JOIN {$companies} c ON c.Id = g.associated_company_id
                  LEFT JOIN {$wpdb->users} u ON u.ID = g.deal_owner
                 WHERE g.offer_date IS NOT NULL
                   AND (g.stage IS NULL OR g.stage NOT IN (" . self::in_list(array_merge(self::WON_STAGES, self::LOST_STAGES)) . "))
                 ORDER BY g.amount DESC";
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare($sql, $today)) as $r) {
            $out[] = [
                'id' => (int) $r->id, 'company_id' => (int) $r->company_id, 'company' => (string) ($r->company_name ?: ''), 'project' => (string) $r->project_name,
                'amount' => round((float) $r->amount, 2), 'stage' => (string) ($r->stage ?: ''), 'idle_days' => $r->idle_days === null ? null : (int) $r->idle_days,
                'owner' => (string) ($r->owner ?: ''), 'link' => trailingslashit(home_url('/deal/' . (int) $r->id . '/')),
            ];
        }
        return $out;
    }

    /** Offres ouvertes : nombre et montant HT. */
    public static function pipeline() {
        global $wpdb;
        $row = $wpdb->get_row("SELECT COUNT(*) AS n, COALESCE(SUM(g.amount), 0) AS total FROM (" . self::groups_sql() . ") g
              WHERE g.offer_date IS NOT NULL AND (g.stage IS NULL OR g.stage NOT IN (" . self::in_list(array_merge(self::WON_STAGES, self::LOST_STAGES)) . "))");
        return ['count' => (int) ($row->n ?? 0), 'amount' => round((float) ($row->total ?? 0), 2)];
    }

    /** Dossiers gagnés / perdus d'une entreprise ou d'un contact (fiche CRM). */
    public static function conversion_for($id, $type = 'company') {
        global $wpdb;
        $l = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $where = $type === 'company' ? $wpdb->prepare('g.associated_company_id = %d', $id) : $wpdb->prepare('FIND_IN_SET(%d, g.contacts)', $id);
        $sql = "SELECT SUM(g.stage IN (" . self::in_list(self::WON_STAGES) . ")) AS won, SUM(g.stage IN (" . self::in_list(self::LOST_STAGES) . ")) AS lost
                  FROM (SELECT g0.*, (SELECT x.associated_contact_ids FROM {$l} x WHERE x.id = g0.id) AS contacts FROM (" . self::groups_sql() . ") g0) g
                 WHERE {$where}";
        $row = $wpdb->get_row($sql);
        return self::rate((int) ($row->won ?? 0), (int) ($row->lost ?? 0));
    }

    /** Synthèse hebdomadaire pour le point du lundi. $ref = date du jour (Y-m-d). La semaine analysée est la dernière semaine complète (lundi → dimanche). */
    public static function weekly_summary($ref) {
        $t = strtotime($ref);
        $monday_this = strtotime('monday this week', $t);
        $w_from = date('Y-m-d', strtotime('-7 days', $monday_this));
        $w_to   = date('Y-m-d', strtotime('-1 day', $monday_this));
        $p_from = date('Y-m-d', strtotime('-14 days', $monday_this));
        $p_to   = date('Y-m-d', strtotime('-8 days', $monday_this));
        $y_from = date('Y-01-01', strtotime($w_to));
        $r_from = date('Y-m-d', strtotime('-12 months +1 day', strtotime($w_to)));
        $m_from = date('Y-m-d', strtotime('-28 days', $monday_this)); // 4 dernières semaines complètes
        return [
            'reference_date' => date('Y-m-d', $t),
            'week'           => ['from' => $w_from, 'to' => $w_to],
            'offers_written' => [
                'week'         => self::offers_written($w_from, $w_to),
                'previous_week' => self::offers_written($p_from, $p_to),
                'last_4_weeks' => self::offers_written($m_from, $w_to),
                'year_to_date' => self::offers_written($y_from, $w_to),
            ],
            'orders' => [
                'week'          => self::orders($w_from, $w_to),
                'previous_week' => self::orders($p_from, $p_to),
                'last_4_weeks'  => self::orders($m_from, $w_to),
                'year_to_date'  => self::orders($y_from, $w_to),
            ],
            'conversion' => [
                'definition'   => 'won / (won + lost) among deals closed in the period',
                'week'         => self::conversion($w_from, $w_to),
                'year_to_date' => self::conversion($y_from, $w_to),
                'last_12_months' => self::conversion($r_from, $w_to),
            ],
            'open_offers' => self::pipeline(),
            'follow_up'   => self::follow_up(date('Y-m-d', $t)),
        ];
    }
}
