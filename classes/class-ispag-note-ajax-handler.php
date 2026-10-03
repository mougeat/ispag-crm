<?php
defined('ABSPATH') || exit;
/**
 * Gère les requêtes AJAX pour les notes et tâches (Sauvegarde, Suppression, etc.).
 */
class ISPAG_Note_Ajax_Handler {

    private $repository;

    const META_LAST_CONTACT_DATE        = 'ispag_last_contact_date';
    const META_LAST_CONTACT_SOURCE      = 'ispag_last_contact_source';

    const TABLE_NOTE                    = ISPAG_Note_Manager::TABLE_NOTE;

    public function __construct( ISPAG_Note_Repository $repository ) {
        $this->repository = $repository;
        add_action( 'wp_ajax_ispag_save_activity', array( $this, 'handle_save_activity' ) );
        add_action( 'wp_ajax_ispag_get_activity_details', array( $this, 'handle_get_activity_details' ) );
        add_action( 'wp_ajax_ispag_delete_activity', array( $this, 'ispag_delete_activity_callback' ) );

        add_action( 'wp_ajax_ispag_save_contact_note', array( $this, 'handle_save_note_ajax' ) );
        // ON N'AJOUTE L'ACTION QUE SI ELLE N'EXISTE PAS DÉJÀ
        if ( ! has_action( 'ispag_save_contact_note', array( $this, 'handle_save_note' ) ) ) {
            add_action( 'ispag_save_contact_note', array( $this, 'handle_save_note' ), 10, 4 );
        }
    }

    /**
     * Gère la requête AJAX de sauvegarde/mise à jour d'une note ou tâche.
     */
    public function handle_save_activity() {
        $logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        if ( ! current_user_can( 'manage_order' ) || ! check_ajax_referer( 'ispag-note-nonce', 'security', false ) ) {
            $logger->log_error( 'note_ajax', 'Security check failed in handle_save_activity.', [], $user_id );
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ispag-crm' ) ) );
        }

        // 1. Récupérer et valider les données de $_POST
        $data = $_POST['activity_data'] ?? [];
        $activity_id = isset( $data['id'] ) ? absint( $data['id'] ) : null;
        
        $logger->log_user_action( 'note_ajax', 'save_activity_attempt', $data, $user_id );

        // 2. Save via le Repository
        $new_id = $this->repository->save_activity( $data, $activity_id );

        if ( $new_id ) {
            $activity = $this->repository->get_activity_by_id( $new_id );
            
            $action_type = $activity_id ? 'UPDATE' : 'INSERT';
            $logger->log_db_change( 'note_ajax', self::TABLE_NOTE, $action_type, ['activity_id' => $new_id, 'data' => $data], $user_id );
            $logger->log_user_action( 'note_ajax', 'save_activity_success', ['activity_id' => $new_id], $user_id );

            wp_send_json_success( array( 
                'message' => __( 'Activity saved successfully.', 'ispag-crm' ),
                'activity_id' => $new_id,
                'activity_data' => $activity,
            ) );
        } else {
            $logger->log_error( 'note_ajax', 'Failed to save activity in repository.', ['activity_id' => $activity_id, 'data' => $data], $user_id );
            wp_send_json_error( array( 'message' => __( 'Failed to save activity.', 'ispag-crm' ) ) );
        }
    }

    /**
     * Sauvegarde une note/tâche provenant d'une action PHP directe (ex: Séquences)
     */
    public function handle_save_note( $data, $meta = null, $contact_id = null, $is_internal = true ) {
        global $wpdb;
        $table_name = ISPAG_Note_Manager::TABLE_NOTE; 
        $logger = ISPAG_Logger::get_instance();
        $user_id = $data['created_by'] ?? 1;

        $data_to_save = array(
            'contact_id'    => $data['contact_id'] ?? (string)$contact_id ?? null,
            'user_id'       => $user_id,
            'company_id'    => $data['company_id'] ?? null,
            'deal_id'       => $data['deal_id'] ?? null,
            'type'          => $data['type'] ?? 'TASK',
            'content'       => $data['content'] ?? '',
            'title'         => $data['title'] ?? 'Action de Séquence',
            'is_task'       => $data['is_task'] ?? 1,
            'is_completed'  => 0,
            'due_date'      => $data['due_date'] ?? current_time('mysql'),
            'reminder_date' => $data['reminder_date'] ?? null,
            'created_at'    => current_time('mysql')
        );

        $result = $wpdb->insert( $table_name, $data_to_save );

        if ( $result !== false ) {
            $logger->log_db_change( 'note_direct', $table_name, 'INSERT', $data_to_save, (int)$user_id );
        } else {
            $logger->log_error( 'note_direct', 'Failed to insert note via direct action.', ['data' => $data_to_save], (int)$user_id );
        }

        return $result;
    }

    /**
     * Gère la requête AJAX pour récupérer les données d'une activité pour remplir la modal.
     */
    public function handle_get_activity_details() {
        $logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        if ( ! current_user_can( 'read' ) ) {
            $logger->log_error( 'note_ajax', 'Permission denied in handle_get_activity_details.', [], $user_id );
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $activity_id = isset( $_POST['activity_id'] ) ? absint( $_POST['activity_id'] ) : 0;
        
        if ( ! $activity_id ) {
            wp_send_json_error( [ 'message' => 'Invalid ID.' ] );
        }

        $activity = $this->repository->get_activity_by_id( $activity_id );
        
        if ( $activity ) {
            wp_send_json_success( [ 'data' => $activity ] );
        } else {
            $logger->log_error( 'note_ajax', 'Activity not found.', ['activity_id' => $activity_id], $user_id );
            wp_send_json_error( [ 'message' => 'Activity not found.' ] );
        }
    }

    /**
     * Gère la requête AJAX pour sauvegarder la note, la tâche ou le meeting.
     */
    public function handle_save_note_ajax() { 
        global $wpdb;
        $table_name = ISPAG_Note_Manager::TABLE_NOTE;
        $logger = ISPAG_Logger::get_instance();
        $current_user_id = get_current_user_id();

        // 1. Vérification de sécurité
        if ( ! check_ajax_referer( 'ispag_crm_nonce', 'security', false ) ) {
            $logger->log_error( 'note_ajax', 'Security check (nonce) failed in handle_save_note_ajax.', [], $current_user_id );
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ispag-crm' ) ) );
        }

        $orig_contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
        $orig_company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : null;
        $orig_deal_id    = isset( $_POST['deal_id'] ) ? absint( $_POST['deal_id'] ) : null;

        $note_content = wp_kses_post( isset( $_POST['note_content'] ) ? $_POST['note_content'] : '' );
        $note_title   = sanitize_text_field( isset( $_POST['activity_title'] ) ? $_POST['activity_title'] : '' );
        $action_type  = isset( $_POST['action_type'] ) ? strtoupper($_POST['action_type']) : 'NOTE';
        $activity_id  = isset( $_POST['activity_id'] ) ? absint( $_POST['activity_id'] ) : 0;
        
        // Tâches & Meetings
        $is_task         = isset( $_POST['is_task'] ) && ( $_POST['is_task'] === 'true' || $_POST['is_task'] === '1' );
        $due_offset      = isset( $_POST['due_offset'] ) ? sanitize_text_field( $_POST['due_offset'] ) : '';
        $reminder_offset = isset( $_POST['reminder_offset'] ) ? sanitize_text_field( $_POST['reminder_offset'] ) : 'none';
        
        $is_meeting = ($action_type === 'MEETING');
        $is_call = ($action_type === 'CALL');
        $is_linkedin = ($action_type === 'LINKEDIN');
        $is_sms = ($action_type === 'SMS');
        $is_whatsapp = ($action_type === 'WHATSAPP'); 

        // Participants / Liaisons multiples
        $meeting_attendees = isset( $_POST['meeting_attendees'] ) ? array_filter( array_map( 'absint', (array) $_POST['meeting_attendees'] ) ) : [];
        $meeting_companies = isset( $_POST['meeting_companies'] ) ? array_filter( array_map( 'absint', (array) $_POST['meeting_companies'] ) ) : [];
        $meeting_deals     = isset( $_POST['meeting_deals'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $_POST['meeting_deals'] ) ) : [];

        $meeting_outcome = isset( $_POST['meeting_outcome'] ) ? sanitize_key( $_POST['meeting_outcome'] ) : 'SCHEDULED';
        $meeting_date    = isset( $_POST['meeting_date'] ) ? sanitize_text_field( $_POST['meeting_date'] ) : '';
        $meeting_time    = isset( $_POST['meeting_time'] ) ? sanitize_text_field( $_POST['meeting_time'] ) : '';

        // Vérification de base
        if ( (empty($orig_contact_id) && empty($orig_company_id) && empty($orig_deal_id) && empty($meeting_attendees)) || empty( $note_content ) ) {
            $logger->log_error( 'note_ajax', 'Missing data in handle_save_note_ajax.', $_POST, $current_user_id );
            wp_send_json_error( array( 'message' => __( 'Missing data.', 'ispag-crm' ) ) );
        }
        
        // 3. CALCUL DES DATES
        $final_due_date_time = null;
        $reminder_date       = null;
        $created_date        = null;

        if ( $is_call || $is_meeting || $is_linkedin || $is_sms || $is_whatsapp ) {
            $meeting_ts = strtotime("{$meeting_date} {$meeting_time}");
            $created_date = $meeting_ts ? date('Y-m-d H:i:s', $meeting_ts) : current_time('mysql');
        }

        if ( $is_task || $is_meeting ) {
            if ( $is_meeting ) {
                $meeting_ts = strtotime("{$meeting_date} {$meeting_time}");
                $final_due_date_time = $meeting_ts ? date('Y-m-d H:i:s', $meeting_ts) : current_time('mysql');
            } 
            else if ( $is_task ) {
                if ( $due_offset === 'custom' && !empty($_POST['due_date']) ) {
                    $time = !empty($_POST['due_time']) ? sanitize_text_field($_POST['due_time']) : '08:00';
                    if (strlen($time) === 4) $time = substr($time, 0, 2) . ':' . substr($time, 2);
                    $final_due_date_time = sanitize_text_field($_POST['due_date']) . ' ' . $time . ':00';
                } 
                elseif ( !empty($due_offset) && $due_offset !== 'none' ) {
                    $val = (int) filter_var($due_offset, FILTER_SANITIZE_NUMBER_INT);
                    $unit = strtolower(trim(str_replace($val, '', $due_offset)));
                    
                    $modifier = "+{$val} days";
                    if ($unit === 'w') $modifier = "+{$val} weeks";
                    if ($unit === 'm') $modifier = "+{$val} months";
                    
                    $time = !empty($_POST['due_time']) ? sanitize_text_field($_POST['due_time']) : '08:00';
                    $final_due_date_time = date('Y-m-d H:i:s', strtotime($modifier . " " . $time));
                }
            }

            // CALCUL DU RAPPEL (REMINDER)
            if ( !empty($final_due_date_time) && !empty($reminder_offset) && $reminder_offset !== 'none' ) {
                $due_ts = strtotime($final_due_date_time);
                $rem_val = (int) filter_var($reminder_offset, FILTER_SANITIZE_NUMBER_INT);
                $rem_unit = strtolower(trim(str_replace($rem_val, '', $reminder_offset)));
                
                $sub_modifier = "-{$rem_val} minutes";
                if ($rem_unit === 'h') $sub_modifier = "-{$rem_val} hours";
                if ($rem_unit === 'd') $sub_modifier = "-{$rem_val} days";
                
                $reminder_date = date('Y-m-d H:i:s', strtotime($sub_modifier, $due_ts));
            }
        }

        // WhatsApp logic
        $whatsapp_url = '';
        if($is_whatsapp && !empty($meeting_attendees)){
            foreach ($meeting_attendees as $att_id) {
                $phone = get_user_meta($att_id, 'lead_phone', true);
                if (!empty($phone)) {
                    $whatsapp_url = "https://wa.me/" . preg_replace('/[^0-9]/', '', $phone) . "?text=" . urlencode(strip_tags($note_content));
                    break;
                }
            }
        }

        // Préparation des IDs pour la DB (Virgules)
        $db_contact_ids = !empty($meeting_attendees) ? implode(',', $meeting_attendees) : $orig_contact_id;
        $db_company_ids = !empty($meeting_companies) ? implode(',', $meeting_companies) : $orig_company_id;
        $db_deal_ids    = !empty($meeting_deals)     ? implode(',', $meeting_deals)     : $orig_deal_id;

        // 4. Données finales pour la sauvegarde
        $data_to_save = array(
            'contact_id'      => $db_contact_ids,
            'user_id'         => $current_user_id,
            'company_id'      => $db_company_ids,
            'deal_id'         => $db_deal_ids,
            'type'            => $action_type,
            'content'         => $note_content,
            'title'           => $note_title,
            'is_task'         => $is_task ? 1 : 0,
            'due_date'        => $final_due_date_time,
            'reminder_date'   => $reminder_date,
            'reminder_offset' => $reminder_offset,
            'outcome'         => ($is_meeting || $is_call) ? $meeting_outcome : null,
        );

        if($created_date){
            $data_to_save['created_at'] = $created_date;
        }

        // 5. Sauvegarde (Insert ou Update)
        if ( $activity_id > 0 ) {
            $data_to_save['notified_at'] = null; 
            $data_to_save['updated_at'] = current_time( 'mysql' );; 
            $result = $wpdb->update( $table_name, $data_to_save, array( 'id' => $activity_id ) );
            $message = __( 'Activity updated.', 'ispag-crm' );

            if ( $result !== false ) {
                $logger->log_db_change( 'note_ajax', $table_name, 'UPDATE', ['activity_id' => $activity_id, 'data' => $data_to_save], $current_user_id );
            }
        } else {
            $result = $wpdb->insert( $table_name, $data_to_save );
            $activity_id = $wpdb->insert_id;
            $message = __( 'Note recorded.', 'ispag-crm' );
            $data_to_save['created_at'] = current_time( 'mysql' );

            if ( $result !== false ) {
                $logger->log_db_change( 'note_ajax', $table_name, 'INSERT', ['activity_id' => $activity_id, 'data' => $data_to_save], $current_user_id );
            }
        }

        if ( $result === false ) {
            $logger->log_error( 'note_ajax', 'Database error during note/activity save.', ['db_error' => $wpdb->last_error, 'data' => $data_to_save], $current_user_id );
            wp_send_json_error( array( 'message' => 'Database error.' ) );
        }

        $logger->log_user_action( 'note_ajax', "save_{$action_type}", ['activity_id' => $activity_id], $current_user_id );

        // 6. Rendu HTML pour retour immédiat
        $log_entry = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $activity_id) );
        $new_item_html = '';
        $table_html = '';
        
        if ( class_exists( 'ISPAG_Note_Renderer' ) ) {
            $note_renderer = new ISPAG_Note_Renderer();
            $new_item_html = $note_renderer->render_activity_card( $log_entry );

            // Ligne du tableau des tâches : seulement pour une tâche ouverte de l'utilisateur courant
            if ( ! empty( $log_entry->is_task ) && class_exists( 'ISPAG_Note_Repository' ) && function_exists( 'ispag_get_template' ) ) {
                $task_for_row = ( new ISPAG_Note_Repository() )->get_active_task( $activity_id );
                if ( $task_for_row ) {
                    $table_html = ispag_get_template( 'task-row', [ 'task' => $task_for_row ] );
                }
            }
        }

        // 7. Mise à jour des métas de dernier contact
        if($action_type != 'TASK' && !empty($meeting_attendees)){
            foreach ($meeting_attendees as $u_id) {
                update_user_meta( $u_id, 'last_contact_date', current_time('mysql') );
                update_user_meta( $u_id, 'last_contact_source', $action_type );
            }
        }

        wp_send_json_success( array(
            'message'      => $message, 
            'insert_id'    => $activity_id,
            'html'         => $new_item_html,
            'whatsapp_url' => $whatsapp_url,
            'task_html'    => $table_html
        ) );
    }

    /**
     * Arrondit un timestamp donné à la prochaine tranche de 15 minutes.
     */
    function ispag_round_to_next_quarter_hour( $timestamp ) {
        $minutes = date( 'i', $timestamp );
        $seconds = date( 's', $timestamp );
        $remainder = ( $minutes % 15 ) * 60 + $seconds;
        
        if ($remainder === 0 && (int)$minutes % 15 === 0) {
            return $timestamp;
        }

        $minutes_to_add = 900 - $remainder; 

        return $timestamp + $minutes_to_add;
    }

    public function delete_activity($activity_id) {
        global $wpdb;
        $table_name = self::TABLE_NOTE;

        return $wpdb->delete(
            $table_name,
            ['id' => $activity_id],
            ['%d']
        );
    }

    // La fonction de rappel (callback)
    public function ispag_delete_activity_callback() {
        $logger = ISPAG_Logger::get_instance();
        $current_user_id = get_current_user_id();

        // 1. Sécurité : vérification du nonce
        if ( ! check_ajax_referer('ispag_crm_nonce', 'security', false) ) {
            $logger->log_error( 'note_ajax', 'Security check (nonce) failed in ispag_delete_activity_callback.', [], $current_user_id );
            wp_send_json_error(['message' => 'Security check failed.']);
        }

        // 2. Récupération de l'ID passé en AJAX
        $activity_id = isset($_POST['activity_id']) ? intval($_POST['activity_id']) : 0;

        if ($activity_id <= 0) {
            $logger->log_error( 'note_ajax', 'Invalid activity ID provided for deletion.', ['activity_id' => $activity_id], $current_user_id );
            wp_send_json_error(['message' => 'ID invalide.']);
        }

        // 3. Appel au Repository pour la suppression en base de données
        $deleted = $this->delete_activity($activity_id);

        if ($deleted) {
            $logger->log_db_change( 'note_ajax', self::TABLE_NOTE, 'DELETE', ['activity_id' => $activity_id], $current_user_id );
            $logger->log_user_action( 'note_ajax', 'delete_activity', ['activity_id' => $activity_id], $current_user_id );

            wp_send_json_success([
                'message' => __( 'Activity deleted', 'ispag-crm' ),
                'id' => $activity_id
            ]);
        } else {
            $logger->log_error( 'note_ajax', 'Failed to delete activity from database.', ['activity_id' => $activity_id], $current_user_id );
            wp_send_json_error(['message' => __( 'Failed to delete from database.', 'ispag-crm' )]);
        }
    }
}