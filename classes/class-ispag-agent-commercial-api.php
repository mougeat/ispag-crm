<?php

class Ispag_Agent_Commercial_API {

    private $log_file;

    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/agent_api_debug.log';
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('ispag/v1', '/planning', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_planning_data'],
            'permission_callback' => '__return_true', 
        ]);
    }

    private function log_action($message, $data = null) {
        $timestamp = current_time('mysql');
        $log_entry = "[{$timestamp}] {$message}";
        if ($data) {
            $log_entry .= " | Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents($this->log_file, $log_entry . PHP_EOL, FILE_APPEND);
    }

    public function get_planning_data($request) {
        global $wpdb;

        // On cible l'ID 1 par défaut, ou celui passé en paramètre (ex: 6048)
        $target_user_id = $request->get_param('view_user') ? intval($request->get_param('view_user')) : 6048;

        $this->log_action("Requête planning reçue", ['target_id' => $target_user_id]);

        $deal_list    = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $deals_stages = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES;
        $note_table   = ISPAG_Note_Manager::TABLE_NOTE;

        // 1. Récupération des Deals actifs
        $deals = $wpdb->get_results($wpdb->prepare("
            SELECT d.* FROM {$deal_list} d
            LEFT JOIN {$deals_stages} ds ON ds.deal_group_ref COLLATE utf8mb4_unicode_520_ci = d.deal_group_ref COLLATE utf8mb4_unicode_520_ci
            WHERE d.deal_owner = %d 
            AND (ds.current_stage_key != 'closed_lost' AND ds.current_stage_key != 'closed_won' OR ds.current_stage_key IS NULL)
        ", $target_user_id));

        // 2. Récupération des Notes/Tâches filtrées
        // Exclusion des types : HEALTH_REMINDER, EMAIL_CAMPAIGN, EMAIL_TRANSACTIONAL, CHRISTMAS_PRESENT, STAGE, SYSTEM
        $tasks = $wpdb->get_results($wpdb->prepare("
            SELECT id, contact_id, deal_id, content, reminder_date as date_reminder, due_date, is_completed
            FROM {$note_table} 
            WHERE user_id = %d 
            AND (is_completed = 0 OR is_completed IS NULL)
            AND type NOT IN ('HEALTH_REMINDER','EMAIL_CAMPAIGN','EMAIL_TRANSACTIONAL','CHRISTMAS_PRESENT','STAGE','SYSTEM')
            ORDER BY reminder_date ASC
        ", $target_user_id));

        $result = [
            'requested_for_user' => $target_user_id,
            'current_date'       => current_time('mysql'),
            'deals_count'        => count($deals),
            'tasks_count'        => count($tasks),
            'deals'              => $deals,
            'tasks'              => $tasks
        ];

        return new WP_REST_Response([
            'status' => 'success',
            'data'   => $result
        ], 200);
    }
}