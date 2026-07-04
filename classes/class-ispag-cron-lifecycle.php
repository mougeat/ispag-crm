<?php
/**
 * Classe ISPAG_Cron_Lifecycle
 * Gère l'automatisation dynamique des phases.
 */
class ISPAG_Cron_Lifecycle {

    const TABLE_NAME_SUFFIX    = ISPAG_Crm_Contact_Constants::LIFECYCLE_TABLE_NAME; 
    const PROJECTS_TABLE       = ISPAG_Crm_Deal_Constants::TABLE_NAME;
    const CRON_ACTION          = 'ispag_auto_update_lifecycle_phases';
    const META_LIFECYCLE_PHASE = 'ispag_contact_lifecycle_phase'; 

    // Nom exact de la colonne en base de données
    const CONTACT_ID_COLUMN    = 'associated_contact_ids'; 

    public function __construct() {
        // L'action doit être liée à la fonction de rappel dès l'instanciation
        add_action( self::CRON_ACTION, array( $this, 'update_all_user_phases_by_projects' ) );
        
        // On enregistre la planification sur 'init'
        add_action( 'init', array( $this, 'register_cron_action' ) );
    }

    public static function log_data_to_file($message) {
        if ( ! defined('WP_CONTENT_DIR') ) return;
        $log_file = WP_CONTENT_DIR . '/ispag_cron.log'; 
        // file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [LIFECYCLE] $message\n", FILE_APPEND);
    }

    public static function get_table_name() {
        return self::TABLE_NAME_SUFFIX;
    }

    public static function get_phases_for_select() {
        global $wpdb;
        $table_name = self::get_table_name();
        $phases = $wpdb->get_results( "SELECT phase_key, phase_label FROM {$table_name} ORDER BY phase_order ASC" );
        
        $output = array();
        if ( $phases ) {
            foreach ( $phases as $phase ) {
                $output[ $phase->phase_key ] = $phase->phase_label;
            }
        }
        return $output; 
    }

    public static function get_automated_phase_for_user($user_id) {
        global $wpdb;
        $deals_table = self::PROJECTS_TABLE;
        $phases_table = self::TABLE_NAME_SUFFIX;
        $col_name = self::CONTACT_ID_COLUMN;

        // On récupère les phases automisables dans l'ordre décroissant (la plus haute en premier)
        $potential_phases = $wpdb->get_results(
            "SELECT phase_key FROM {$phases_table} 
            WHERE phase_key NOT IN ('lead', 'subscriber', 'other') 
            ORDER BY phase_order DESC"
        );

        foreach ($potential_phases as $phase) {
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$deals_table} 
                WHERE FIND_IN_SET(%d, REPLACE({$col_name}, ' ', '')) > 0
                AND process_type = %s", 
                $user_id, 
                $phase->phase_key
            );

            $count = (int) $wpdb->get_var($query);

            if ($user_id == 1492) {
                self::log_data_to_file("DEBUG 1492 | Phase testée: {$phase->phase_key} | Matchs trouvés: $count");
            }

            if ($count > 0) {
                return $phase->phase_key;
            }
        }

        $current_meta = get_user_meta($user_id, self::META_LIFECYCLE_PHASE, true);
        return ($current_meta === 'subscriber') ? 'subscriber' : 'lead';
    }

    /**
     * Cœur du traitement Cron
     */
    public function update_all_user_phases_by_projects() {
        self::log_data_to_file("--- DÉBUT EXÉCUTION CRON ---");
        
        // Attention : Vérifiez bien que vos contacts n'ont PAS un de ces rôles
        $users = get_users( array( 
            'fields' => array('ID', 'display_name'), 
            'role__not_in' => array( 'administrator', 'editor', 'vente_ispag', 'author', 'subscriber', 'membre_ispag', 'achat_ispag', 'ispag_commercial' )
        ) );

        if ( empty( $users ) ) {
            self::log_data_to_file("FIN : Aucun utilisateur éligible trouvé (tous exclus par leur rôle).");
            return;
        }

        self::log_data_to_file("Nombre d'utilisateurs à traiter : " . count($users));

        $phases_labels = self::get_phases_for_select();
        $phases_keys_ordered = array_keys($phases_labels); 
        $note_manager = new ISPAG_Note_Manager();

        foreach ( $users as $user ) {
            $user_id = $user->ID;
            $user_name = $user->display_name;

            $current_phase_key = get_user_meta( $user_id, self::META_LIFECYCLE_PHASE, true );
            $new_phase_key = self::get_automated_phase_for_user( $user_id ); 
            
            $auto_index    = array_search( $new_phase_key, $phases_keys_ordered );
            $current_index = $current_phase_key ? array_search( $current_phase_key, $phases_keys_ordered ) : -1;
            
            // On ne met à jour que si la nouvelle phase est "supérieure" dans l'ordre défini
            if ( $auto_index !== false && $auto_index > $current_index ) {
                update_user_meta( $user_id, self::META_LIFECYCLE_PHASE, $new_phase_key );
                
                $new_label = isset($phases_labels[$new_phase_key]) ? $phases_labels[$new_phase_key] : $new_phase_key;
                
                $note_data = new stdClass();
                $note_data->contact_id    = $user_id;
                $note_data->activity_type = 'SYSTEM';
                $note_data->title         = 'Lifecycle change';
                $note_data->content       = "System updated the lifecycle stage for this contact to $new_label.";
                $note_data->author_id     = 0;

                $note_manager->create_note( $note_data );
                self::log_data_to_file("[ID: $user_id | $user_name] UPDATE: $new_phase_key");
            } 
        }
        self::log_data_to_file("--- FIN EXÉCUTION CRON ---");
    }

    /**
     * Planification de l'événement
     */
    public function register_cron_action() {
        if ( ! wp_next_scheduled( self::CRON_ACTION ) ) {
            // Planifie l'exécution quotidienne
            wp_schedule_event( time(), 'daily', self::CRON_ACTION );
            self::log_data_to_file("ACTION : Le cron '" . self::CRON_ACTION . "' vient d'être planifié en base de données.");
        }
    }
}