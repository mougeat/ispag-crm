<?php
/**
 * Classe ISPAG_Cron_LeadStatus
 * Gère l'automatisation des statuts de lead.
 * EXCLUT les notes 'SYSTEM' pour éviter les boucles infinies.
 * Utilise ISPAG_Logger pour centraliser les logs dans wp-content/ispag_logs/ispag_cron_leadstatus.log.
 */
class ISPAG_Cron_LeadStatus
{
    const TABLE_NAME_SUFFIX = 'ispag_lead_statuses';
    const CRON_ACTION = 'ispag_auto_update_lead_statuses';
    const META_LEAD_STATUS = ISPAG_Crm_Contact_Constants::META_LEAD_STATUS;
    private const LOG_NAME = 'cron_lead_status';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    // Statuts "manuels" — le cron ne les écrase jamais
    const MANUAL_STATUSES = ['unqualified', 'bad_timing', 'open'];

    /**
     * Constructeur.
     */
    public function __construct()
    {
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // $this->logger->log_user_action(self::LOG_NAME, 'class_initialized', [], $user_id);

        add_action(self::CRON_ACTION, [$this, 'update_all_lead_statuses']);
        add_action('wp', [$this, 'register_cron']);
    }

    // ------------------------------------------------------------------
    // DONNÉES
    // ------------------------------------------------------------------

    /**
     * Retourne tous les statuts indexés par status_key,
     * avec TOUTES les colonnes nécessaires.
     *
     * @return array<string, object>
     */
    public static function get_statuses_from_db(): array
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME_SUFFIX;
        $logger->log_db_change(self::LOG_NAME, $table, 'SELECT_ALL', [], $user_id);

        $rows = $wpdb->get_results(
            "SELECT status_key, status_label, status_description, status_order
             FROM {$table}
             ORDER BY status_order ASC"
        );

        $indexed = [];
        foreach ($rows as $row)
        {
            $indexed[$row->status_key] = $row;
        }

        $logger->log_user_action(self::LOG_NAME, 'statuses_loaded', ['count' => count($indexed)], $user_id);
        return $indexed;
    }

    // ------------------------------------------------------------------
    // LOGIQUE DE STATUT
    // ------------------------------------------------------------------

    /**
     * Détermine le statut automatique d'un contact en fonction
     * de sa dernière vraie activité (hors SYSTEM).
     *
     * @param int $user_id ID de l'utilisateur.
     * @return string
     */
    public static function get_automated_status_for_user(int $user_id): string
    {
        $user_id_log = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'get_automated_status_start', ['target_user_id' => $user_id], $user_id_log);

        global $wpdb;
        $notes_table = ISPAG_Note_Manager::TABLE_NOTE;

        $last_act = $wpdb->get_row($wpdb->prepare(
            "SELECT type FROM {$notes_table}
             WHERE contact_id = %d
               AND type != 'SYSTEM'
             ORDER BY created_at DESC
             LIMIT 1",
            $user_id
        ));

        $logger->log_db_change(self::LOG_NAME, $notes_table, 'SELECT_LAST_ACTIVITY', ['user_id' => $user_id, 'result' => $last_act], $user_id_log);

        if (!$last_act)
        {
            $logger->log_user_action(self::LOG_NAME, 'no_activity_found', ['user_id' => $user_id], $user_id_log);
            return 'new';
        }

        switch ($last_act->type)
        {
            case 'EMAIL':
            case 'CALL':
                $status = 'attempted_contact';
                $logger->log_user_action(self::LOG_NAME, 'status_determined', ['user_id' => $user_id, 'status' => $status, 'activity' => $last_act->type], $user_id_log);
                return $status; // order 60

            case 'MEETING':
            case 'SMS':
            case 'WHATSAPP':
            case 'LINKEDIN':
                $status = 'connected';
                $logger->log_user_action(self::LOG_NAME, 'status_determined', ['user_id' => $user_id, 'status' => $status, 'activity' => $last_act->type], $user_id_log);
                return $status; // order 70

            case 'OFFER':
                $status = 'open_transaction';
                $logger->log_user_action(self::LOG_NAME, 'status_determined', ['user_id' => $user_id, 'status' => $status, 'activity' => $last_act->type], $user_id_log);
                return $status; // order 40

            default:
                $status = 'in_progress';
                $logger->log_user_action(self::LOG_NAME, 'status_determined', ['user_id' => $user_id, 'status' => $status, 'activity' => $last_act->type], $user_id_log);
                return $status; // order 30
        }
    }

    /**
     * Détermine si la transition current → new est autorisée.
     *
     * Règles :
     * - Les statuts manuels (unqualified, bad_timing, open) ne sont jamais
     *   écrasés par le cron.
     * - On n'autorise la progression que si le nouveau status_order
     *   est SUPÉRIEUR à l'actuel (on n'écrase pas un statut plus avancé).
     * - Exception : open_transaction (offre) est prioritaire sur
     *   attempted_contact/connected — une offre envoyée ne doit pas
     *   être rétrogradée par un simple email de relance.
     *
     * @param string $current Statut actuel.
     * @param string $new Nouveau statut.
     * @param array $statuses Liste des statuts indexés.
     * @return bool
     */
    private static function is_transition_allowed(
        string $current,
        string $new,
        array $statuses
    ): bool
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();

        $logger->log_user_action(self::LOG_NAME, 'check_transition', ['current' => $current, 'new' => $new], $user_id);

        // Statut identique → rien à faire
        if ($current === $new)
        {
            $logger->log_user_action(self::LOG_NAME, 'transition_skipped_same_status', ['current' => $current], $user_id);
            return false;
        }

        // Statut manuel → le cron ne touche pas
        if (in_array($current, self::MANUAL_STATUSES, true))
        {
            $logger->log_user_action(self::LOG_NAME, 'transition_skipped_manual_status', ['current' => $current], $user_id);
            return false;
        }

        $current_order = $statuses[$current]->status_order ?? 0;
        $new_order = $statuses[$new]->status_order ?? 0;

        $logger->log_user_action(self::LOG_NAME, 'transition_orders_checked', ['current_order' => $current_order, 'new_order' => $new_order], $user_id);

        // On ne progresse que vers un status_order plus élevé
        $allowed = $new_order > $current_order;
        $logger->log_user_action(self::LOG_NAME, 'transition_allowed', ['allowed' => $allowed, 'current' => $current, 'new' => $new], $user_id);

        return $allowed;
    }

    // ------------------------------------------------------------------
    // CRON
    // ------------------------------------------------------------------

    /**
     * Met à jour tous les statuts de lead.
     */
    public function update_all_lead_statuses(): void
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'cron_execution_start', [], $user_id);
        $this->log("--- DEBUT EXECUTION CRON STATUS ---");

        $users = get_users([
            'fields' => ['ID', 'display_name'],
            'role__not_in' => ['administrator', 'editor', 'vente_ispag', 'author', 'membre_ispag', 'ispag_commercial'],
        ]);

        $this->logger->log_user_action(self::LOG_NAME, 'users_fetched', ['count' => count($users)], $user_id);

        if (empty($users)) {
            $this->log("Aucun utilisateur à traiter.");
            $this->logger->log_user_action(self::LOG_NAME, 'no_users_to_process', [], $user_id);
            return;
        }

        $statuses = self::get_statuses_from_db();
        $note_manager = new ISPAG_Note_Manager();
        $updated = 0;

        foreach ($users as $user) {
            $user_id_target = (int)$user->ID;
            $current_status = get_user_meta($user_id_target, self::META_LEAD_STATUS, true) ?: 'new';
            $new_status = self::get_automated_status_for_user($user_id_target);

            $this->logger->log_user_action(self::LOG_NAME, 'processing_user', [
                'user_id' => $user_id_target,
                'current_status' => $current_status,
                'new_status' => $new_status
            ], $user_id);

            if (!self::is_transition_allowed($current_status, $new_status, $statuses)) {
                $this->logger->log_user_action(self::LOG_NAME, 'transition_not_allowed', [
                    'user_id' => $user_id_target,
                    'current' => $current_status,
                    'new' => $new_status
                ], $user_id);
                continue;
            }

            // Mise à jour du statut
            $update_result = update_user_meta($user_id_target, self::META_LEAD_STATUS, $new_status);
            $this->logger->log_db_change(self::LOG_NAME, 'user_meta', 'UPDATE_LEAD_STATUS', [
                'user_id' => $user_id_target,
                'new_status' => $new_status,
                'result' => $update_result
            ], $user_id);

            // Raison lisible (depuis la DB)
            $reason_text = $statuses[$new_status]->status_description ?? '';
            update_user_meta($user_id_target, '_ispag_status_reason', [
                'reason' => $reason_text,
                'date' => current_time('mysql'),
                'type' => 'auto',
            ]);
            $this->logger->log_db_change(self::LOG_NAME, 'user_meta', 'UPDATE_STATUS_REASON', [
                'user_id' => $user_id_target,
                'reason' => $reason_text
            ], $user_id);

            // Note système (seulement si l'utilisateur avait déjà un statut)
            if (!empty($current_status) && $current_status !== 'new') {
                $label = $statuses[$new_status]->status_label ?? $new_status;

                $note_data = new stdClass();
                $note_data->contact_id = $user_id_target;
                $note_data->activity_type = 'SYSTEM';
                $note_data->title = 'Lead status automated change';
                $note_data->content = "System updated status to: {$label} (Real activity detected).";
                $note_data->author_id = 0;

                $note_manager->create_note($note_data);
                $this->logger->log_db_change(self::LOG_NAME, ISPAG_Note_Manager::TABLE_NOTE, 'INSERT_SYSTEM_NOTE', [
                    'user_id' => $user_id_target,
                    'content' => $note_data->content
                ], $user_id);
            }

            // --- NOUVEAU : Notification au propriétaire du contact ---
            $contact_owner_id = get_user_meta($user_id_target, '_ispag_contact_owner', true);
            if (!empty($contact_owner_id)) {
                $owner_id = (int)$contact_owner_id;
                $label = $statuses[$new_status]->status_label ?? $new_status;

                // Titre et contenu de la notification
                $notification_title = sprintf(
                    __("The lead status of %s has changed", 'ispag'),
                    $user->display_name
                );
                $notification_content = sprintf(
                    __("The lead status of %s changed from <strong>%s</strong> to <strong>%s</strong>.", 'ispag'),
                    $user->display_name,
                    $current_status,
                    $label
                );

                // URL vers le profil du contact (à adapter selon votre structure)
                $contact_url = admin_url("user-edit.php?user_id={$user_id_target}");

                // Envoi de la notification
                ISPAG_Notifications_Manager::send(
                    $owner_id,
                    'contact_leadstatus',
                    $notification_title,
                    $notification_content,
                    $contact_url,
                    $user_id_target
                );
            }
            // --- FIN NOUVEAU ---

            $this->log("[ID: {$user_id_target} — {$user->display_name}] {$current_status} → {$new_status}");
            $updated++;
        }

        $this->log("BILAN : {$updated} contact(s) mis à jour sur " . count($users));
        $this->logger->log_user_action(self::LOG_NAME, 'cron_execution_complete', [
            'updated' => $updated,
            'total' => count($users)
        ], $user_id);
        $this->log("--- FIN EXECUTION CRON STATUS ---");
    }
 
    /**
     * Enregistre le cron.
     */
    public function register_cron(): void
    {
        $user_id = get_current_user_id();
        if (!wp_next_scheduled(self::CRON_ACTION))
        {
            wp_schedule_event(time(), 'hourly', self::CRON_ACTION);
            $this->logger->log_user_action(self::LOG_NAME, 'cron_scheduled', ['action' => self::CRON_ACTION], $user_id);
        }
    }

    // ------------------------------------------------------------------
    // LOG
    // ------------------------------------------------------------------

    /**
     * Log un message dans le fichier de log.
     *
     * @param string $message Message à logger.
     */
    private static function log(string $message): void
    {
        if (!defined('WP_CONTENT_DIR')) return;

        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log(self::LOG_NAME, $message, $user_id);
    }
}