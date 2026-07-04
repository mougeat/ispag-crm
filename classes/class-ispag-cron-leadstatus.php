<?php
/**
 * Classe ISPAG_Cron_LeadStatus
 * Gère l'automatisation des statuts de lead.
 * EXCLUT les notes 'SYSTEM' pour éviter les boucles infinies.
 */
class ISPAG_Cron_LeadStatus {

    const TABLE_NAME_SUFFIX = 'ispag_lead_statuses';
    const CRON_ACTION       = 'ispag_auto_update_lead_statuses';
    const META_LEAD_STATUS  = ISPAG_Crm_Contact_Constants::META_LEAD_STATUS;

    // Statuts "manuels" — le cron ne les écrase jamais
    // (posés par un humain, pas par l'activité automatique)
    const MANUAL_STATUSES = ['unqualified', 'bad_timing', 'open'];

    public function __construct() {
        add_action( self::CRON_ACTION, [ $this, 'update_all_lead_statuses' ] );
        add_action( 'wp',              [ $this, 'register_cron' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DONNÉES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne tous les statuts indexés par status_key,
     * avec TOUTES les colonnes nécessaires.
     *
     * @return array<string, object>
     */
    public static function get_statuses_from_db(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME_SUFFIX;

        $rows = $wpdb->get_results(
            "SELECT status_key, status_label, status_description, status_order
             FROM {$table}
             ORDER BY status_order ASC"
        );

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->status_key] = $row;
        }
        return $indexed;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGIQUE DE STATUT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Détermine le statut automatique d'un contact en fonction
     * de sa dernière vraie activité (hors SYSTEM).
     */
    public static function get_automated_status_for_user(int $user_id): string {
        global $wpdb;
        $notes_table = ISPAG_Note_Manager::TABLE_NOTE;

        $last_act = $wpdb->get_row( $wpdb->prepare(
            "SELECT type FROM {$notes_table}
             WHERE contact_id = %d
               AND type != 'SYSTEM'
             ORDER BY created_at DESC
             LIMIT 1",
            $user_id
        ));

        if ( ! $last_act ) {
            return 'new';
        }

        switch ( $last_act->type ) {
            case 'EMAIL':
            case 'CALL':
                return 'attempted_contact'; // order 60

            case 'MEETING':
            case 'SMS':
            case 'WHATSAPP':
            case 'LINKEDIN':
                return 'connected';         // order 70

            case 'OFFER':
                return 'open_transaction';  // order 40

            default:
                return 'in_progress';       // order 30
        }
    }

    /**
     * Détermine si la transition current → new est autorisée.
     *
     * Règles :
     *  - Les statuts manuels (unqualified, bad_timing, open) ne sont jamais
     *    écrasés par le cron.
     *  - On n'autorise la progression que si le nouveau status_order
     *    est SUPÉRIEUR à l'actuel (on n'écrase pas un statut plus avancé).
     *  - Exception : open_transaction (offre) est prioritaire sur
     *    attempted_contact/connected — une offre envoyée ne doit pas
     *    être rétrogradée par un simple email de relance.
     */
    private static function is_transition_allowed(
        string $current,
        string $new,
        array  $statuses
    ): bool {
        // Statut identique → rien à faire
        if ($current === $new) return false;

        // Statut manuel → le cron ne touche pas
        if (in_array($current, self::MANUAL_STATUSES, true)) return false;

        $current_order = $statuses[$current]->status_order ?? 0;
        $new_order     = $statuses[$new]->status_order     ?? 0;

        // On ne progresse que vers un status_order plus élevé
        // (comparaison sur les vraies valeurs SQL, pas sur les index tableau)
        return $new_order > $current_order;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRON
    // ─────────────────────────────────────────────────────────────────────────

    public function update_all_lead_statuses(): void {
        self::log("--- DEBUT EXECUTION CRON STATUS ---");

        $users = get_users([
            'fields'       => ['ID', 'display_name'],
            'role__not_in' => ['administrator', 'editor', 'vente_ispag', 'author', 'membre_ispag', 'ispag_commercial'],
        ]);

        if ( empty($users) ) {
            self::log("Aucun utilisateur à traiter.");
            return;
        }

        $statuses     = self::get_statuses_from_db();
        $note_manager = new ISPAG_Note_Manager();
        $updated      = 0;

        foreach ($users as $user) {
            $user_id        = (int)$user->ID;
            $current_status = get_user_meta($user_id, self::META_LEAD_STATUS, true) ?: 'new';
            $new_status     = self::get_automated_status_for_user($user_id);

            if ( ! self::is_transition_allowed($current_status, $new_status, $statuses) ) {
                continue;
            }

            // ── Mise à jour du statut ─────────────────────────────────────
            update_user_meta($user_id, self::META_LEAD_STATUS, $new_status);

            // ── Raison lisible (depuis la DB) ─────────────────────────────
            $reason_text = $statuses[$new_status]->status_description ?? '';

            update_user_meta($user_id, '_ispag_status_reason', [
                'reason' => $reason_text,
                'date'   => current_time('mysql'),
                'type'   => 'auto',
            ]);

            // ── Note système (seulement si l'utilisateur avait déjà un statut) ──
            if ( ! empty($current_status) && $current_status !== 'new' ) {
                $label = $statuses[$new_status]->status_label ?? $new_status;

                $note_data                = new stdClass();
                $note_data->contact_id    = $user_id;
                $note_data->activity_type = 'SYSTEM';
                $note_data->title         = 'Lead status automated change';
                $note_data->content       = "System updated status to: {$label} (Real activity detected).";
                $note_data->author_id     = 0;

                $note_manager->create_note($note_data);
            }

            self::log("[ID: {$user_id} — {$user->display_name}] {$current_status} → {$new_status}");
            $updated++;
        }

        self::log("BILAN : {$updated} contact(s) mis à jour sur " . count($users));
        self::log("--- FIN EXECUTION CRON STATUS ---");
    }

    public function register_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_ACTION ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_ACTION );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOG
    // ─────────────────────────────────────────────────────────────────────────

    private static function log(string $message): void {
        if ( ! defined('WP_CONTENT_DIR') ) return;
        $log_file = WP_CONTENT_DIR . '/ispag_cron_leadstatus.log';
        file_put_contents(
            $log_file,
            '[' . date('Y-m-d H:i:s') . '] [LEAD_STATUS] ' . $message . "\n",
            FILE_APPEND
        );
    }
}