<?php
defined('ABSPATH') || exit;

/**
 * Relance automatique des offres ouvertes, calée sur la date de décision attendue.
 *
 * Chaque jour, pour chaque offre ouverte, on s'assure qu'il existe une tâche de relance ouverte pour son responsable :
 *  - décision à venir : relance « N jours avant » la décision (jamais avant « première relance » jours après l'offre) ;
 *  - décision dépassée : relance toutes les « répétition » jours, jusqu'à ce que l'offre soit gagnée, perdue ou déplacée.
 * La tâche suivante n'est créée qu'une fois la précédente terminée. Les contacts dont le rôle / type d'entreprise est
 * réglé « Aucune relance » (ex. ingénieurs) sont ignorés. La date de décision n'est jamais obligatoire (voir ISPAG_Crm_Decision_Date).
 */
class ISPAG_Crm_Deal_Follow_Up {

    const CRON_HOOK = 'ispag_crm_deal_follow_up';
    const OPTION    = 'ispag_crm_deal_follow_up_settings';
    const MARKER    = '[auto-deal-followup]';
    const MAX_PER_RUN = 25;
    const DEFAULTS  = ['enabled' => 1, 'lead' => 7, 'repeat' => 14, 'first' => 10];

    public function __construct() {
        add_action(self::CRON_HOOK, [$this, 'run']);
        add_action('init', function () {
            self::schedule_at_hour(self::CRON_HOOK, 4, 30);
        }, 25);
    }

    /** Planifie un passage quotidien à heure fixe (heure du site) ; recale une planification existante à une autre heure. */
    public static function schedule_at_hour(string $hook, int $hour, int $minute): void {
        $next = wp_next_scheduled($hook);
        $tz   = wp_timezone();
        if ($next) {
            $d = (new DateTimeImmutable('@' . $next))->setTimezone($tz);
            if ((int) $d->format('G') === $hour && (int) $d->format('i') === $minute) return;
            wp_unschedule_event($next, $hook);
        }
        $t = (new DateTimeImmutable('now', $tz))->setTime($hour, $minute);
        if ($t->getTimestamp() <= time() + 60) $t = $t->modify('+1 day');
        wp_schedule_event($t->getTimestamp(), 'daily', $hook);
    }

    public static function settings(): array {
        $saved = (array) get_option(self::OPTION, []);
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $out[$k] = isset($saved[$k]) ? max($k === 'enabled' ? 0 : 0, (int) $saved[$k]) : $def;
        }
        $out['repeat'] = max(1, $out['repeat']);
        return $out;
    }

    /**
     * Prochaine date de relance (timestamp, début de journée du site) d'une offre, ou null si rien à planifier.
     * @param int $decision_ts date de décision effective ; $offer_ts date de l'offre ; $last_ts dernière relance terminée (0 si aucune)
     */
    public static function next_due(int $decision_ts, int $offer_ts, int $last_ts, array $cfg, int $today_ts): ?int {
        $earliest_first = $offer_ts ? $offer_ts + $cfg['first'] * DAY_IN_SECONDS : 0;
        $after_last     = $last_ts ? $last_ts + $cfg['repeat'] * DAY_IN_SECONDS : 0;
        if ($decision_ts && $decision_ts >= $today_ts) {
            $due = $decision_ts - $cfg['lead'] * DAY_IN_SECONDS;      // juste avant la décision
            if ($due < $earliest_first) $due = $earliest_first;        // pas trop tôt après l'offre
            if ($due < $after_last)     $due = $after_last;            // pas deux relances rapprochées
            if ($due > $decision_ts)    $due = $decision_ts;           // et au plus tard le jour de la décision
        } else {
            $due = max($after_last, $earliest_first);                  // décision dépassée / inconnue : rythme régulier
        }
        return max($due, $today_ts);
    }

    const OPT_LAST = 'ispag_crm_deal_follow_up_last';

    /** Dernier passage : date + compteurs par motif (affiché dans Réglages → Relances CRM). */
    public static function last_run(): array { return (array) get_option(self::OPT_LAST, []); }

    public function run() {
        $cfg = self::settings();
        $sum = ['time' => time(), 'enabled' => (int) $cfg['enabled'], 'seen' => 0, 'has_open_task' => 0, 'no_contact' => 0, 'no_follow_up_role' => 0, 'no_owner_user' => 0, 'insert_failed' => 0, 'created' => 0, 'cap_reached' => 0, 'error' => ''];
        if (!$cfg['enabled']) { update_option(self::OPT_LAST, $sum, false); return; }
        try { $this->run_inner($cfg, $sum); } catch (Throwable $e) { $sum['error'] = $e->getMessage(); }
        update_option(self::OPT_LAST, $sum, false);
    }

    private function run_inner(array $cfg, array &$sum) {
        global $wpdb;
        $deals  = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $stages = ISPAG_Crm_Deal_Constants::TABLE_DEAL_STAGES;
        $notes  = ISPAG_Note_Manager::TABLE_NOTE;
        $today  = strtotime(wp_date('Y-m-d') . ' 00:00:00');

        ISPAG_Crm_Decision_Date::ensure_column();
        // Offre à relancer = encore ouverte (statut 0), jamais gagnée / perdue / déjà commandée ; l'étape réelle est dans la table de liaison
        $link = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES;
        $rows = $wpdb->get_results("
            SELECT d.* FROM {$deals} d
            LEFT JOIN {$link} l ON l.deal_group_ref COLLATE utf8mb4_unicode_ci = (COALESCE(NULLIF(d.deal_group_ref, ''), SUBSTRING_INDEX(d.offer_num, '.', 1)) COLLATE utf8mb4_unicode_ci)
            LEFT JOIN {$stages} s ON s.stage_key COLLATE utf8mb4_unicode_ci = (l.current_stage_key COLLATE utf8mb4_unicode_ci)
            WHERE d.project_db_status = " . (int) ISPAG_Crm_Deal_Constants::STATUS_OPEN . "
              AND (d.process_type IS NULL OR d.process_type <> 'Commande')
              AND d.deal_owner > 0 AND d.associated_contact_ids <> ''
              AND (s.id IS NULL OR (s.is_closed = 0 AND s.probability < 100))
            ORDER BY d.id DESC");
        if ($rows === null || $wpdb->last_error) { $sum['error'] = 'SQL : ' . $wpdb->last_error; return; }
        // Nettoyage : les tâches automatiques encore ouvertes d'une offre qui n'est plus à relancer (gagnée, perdue, commandée, clôturée) sont supprimées
        $keep = [];
        foreach ($rows as $r) $keep[] = (string) ($r->deal_group_ref !== '' ? $r->deal_group_ref : strtok((string) $r->offer_num, '.'));
        $sum['removed'] = 0;
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, deal_id FROM {$notes} WHERE is_task = 1 AND is_completed = 0 AND content LIKE %s", '%' . $wpdb->esc_like(self::MARKER) . '%')) as $t) {
            if (!in_array((string) $t->deal_id, $keep, true)) { $wpdb->delete($notes, ['id' => (int) $t->id]); $sum['removed']++; }
        }
        $created = 0;
        $done_refs = [];
        foreach ((array) $rows as $deal) {
            $ref = (string) ($deal->deal_group_ref !== '' ? $deal->deal_group_ref : strtok((string) $deal->offer_num, '.'));
            if (isset($done_refs[$ref])) continue;
            $done_refs[$ref] = true;
            $sum['seen']++;
            if ($created >= self::MAX_PER_RUN) { $sum['cap_reached']++; continue; }
            if ($ref === '') { $sum['no_ref'] = ($sum['no_ref'] ?? 0) + 1; continue; }

            // Une tâche de relance déjà ouverte pour cette offre ? alors rien à faire
            $open = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$notes} WHERE is_task = 1 AND is_completed = 0 AND deal_id = %s AND content LIKE %s",
                $ref, '%' . $wpdb->esc_like(self::MARKER) . '%'
            ));
            if ($open) { $sum['has_open_task']++; continue; }

            // Contact principal (le premier) ; rôle / type d'entreprise « sans relance » : on ignore l'offre
            $contact_id = (int) trim((string) strtok((string) $deal->associated_contact_ids, ','));
            if (!$contact_id) { $sum['no_contact']++; continue; }
            $role  = ISPAG_Crm_Follow_Up_Settings::contact_role($contact_id);
            $ctype = ISPAG_Crm_Follow_Up_Settings::company_type((int) $deal->associated_company_id);
            if (ISPAG_Crm_Follow_Up_Settings::days_for_contact('', $role, $ctype) === 0) { $sum['no_follow_up_role']++; continue; }

            $decision = ISPAG_Crm_Decision_Date::effective($deal);
            $last = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT completed_at FROM {$notes} WHERE is_task = 1 AND is_completed = 1 AND deal_id = %s AND content LIKE %s AND completed_at IS NOT NULL ORDER BY completed_at DESC LIMIT 1",
                $ref, '%' . $wpdb->esc_like(self::MARKER) . '%'
            ));
            $last_ts  = $last ? strtotime(wp_date('Y-m-d', strtotime($last)) . ' 00:00:00') : 0;
            $offer_ts = !empty($deal->date_creation) ? strtotime($deal->date_creation . ' 00:00:00') : 0;
            $rhythm   = ISPAG_Crm_Follow_Up_Settings::rhythm_for($role, $ctype, $cfg);   // rythme propre au rôle / type d'entreprise
            $due_ts   = self::next_due($decision['date'] ? strtotime($decision['date'] . ' 00:00:00') : 0, $offer_ts, $last_ts, $rhythm, $today);
            if ($due_ts === null) continue;

            $user = get_userdata((int) $deal->deal_owner);
            if (!$user) { $sum['no_owner_user']++; continue; }
            $contact = get_userdata($contact_id);
            $cname   = $contact ? $contact->display_name : '#' . $contact_id;
            $due_day = wp_date('Y-m-d', $due_ts);
            $when    = $decision['date'] ? sprintf('décision attendue le %s', wp_date('d.m.Y', strtotime($decision['date']))) : 'pas de date de décision';
            $ok = $wpdb->insert($notes, [
                'contact_id'    => $contact_id,
                'company_id'    => (string) (int) $deal->associated_company_id,
                'deal_id'       => $ref,
                'user_id'       => (int) $deal->deal_owner,
                'type'          => 'TASK',
                'title'         => '📞 Relance offre : ' . $deal->project_name . ' (' . $cname . ')',
                'content'       => "Relance de l'offre auprès de {$cname} ({$when}). " . self::MARKER,
                'is_task'       => 1,
                'is_completed'  => 0,
                'due_date'      => $due_day . ' 17:00:00',
                'reminder_date' => $due_day . ' 08:00:00',
                'reminder_offset' => 'none',
                'created_at'    => current_time('mysql'),
            ]);
            if ($ok) { $created++; $sum['created']++; } else { $sum['insert_failed']++; $sum['error'] = $wpdb->last_error ?: $sum['error']; }
        }
    }
}
