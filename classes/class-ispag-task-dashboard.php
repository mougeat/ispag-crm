<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Sortir si accès direct
}

/**
 * Gère le tableau de bord des tâches actives via un shortcode et les actions AJAX associées.
 */
class ISPAG_Task_Dashboard {

    const TABLE_NOTES       = 'ispag_contact_notes'; 
    const CONTACTS_TABLE    = 'ispag_crm_contacts';
    const COMPANIES_TABLE   = ISPAG_Crm_Company_Constants::TABLE_NAME;

    const META_LAST_CONTACT_DATE    = 'ispag_last_contact_date';
    const META_LAST_CONTACT_SOURCE  = 'ispag_last_contact_source';

    public function __construct() {
        // Enregistrement du shortcode pour l'affichage du tableau de bord
        // add_shortcode( 'ispag_task_dashboard', array( $this, 'render_dashboard_page' ) );
        
        // Enregistrement des actions AJAX
        add_action( 'wp_loaded', array( $this, 'register_ajax_actions' ) );
    }
    
    /**
     * Enregistre les actions AJAX (Terminer et Reporter) et les scripts.
     */
    public function register_ajax_actions() {
        // Pour marquer une tâche comme terminée
        add_action( 'wp_ajax_ispag_complete_task', array( $this, 'ajax_complete_task' ) );
        
        // Pour reporter une tâche
        add_action( 'wp_ajax_ispag_reschedule_task', array( $this, 'ajax_reschedule_task' ) );

        // Enregistrement des assets (scripts/styles)
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
    }

    /**
     * Enregistre les scripts/styles nécessaires.
     * La page étant accessible via shortcode, on l'enregistre sur les hooks front-end et back-end.
     */
    public function enqueue_dashboard_assets( $hook = '' ) {
        // Condition d'affichage : soit sur le hook admin_enqueue_scripts, soit si c'est une page d'admin
        // if ( ! is_admin() && ! has_shortcode( get_the_content(), 'ispag_task_dashboard' ) ) {
        //     return;
        // }
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );
        wp_enqueue_style( 'ispag-crm-styles', $plugin_url . 'assets/css/ispag-crm-styles.css', array(), '1.0.0' );

        // Enregistrement et chargement du script
        wp_enqueue_script(
            'ispag-task-dashboard-js',
            $plugin_url . 'assets/js/ispag-task-dashboard.js', // Assurez-vous que le chemin est correct
            array( 'jquery' ),
            '1.0',
            true
        );

        // Passage des variables PHP au script JS
        wp_localize_script(
            'ispag-task-dashboard-js',
            'ispag_dashboard_ajax',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ispag_crm_nonce' ) // Jeton de sécurité
            )
        );
    }
    
    /**
     * Fonction de rendu du tableau de bord (via shortcode [ispag_task_dashboard]).
     * @param array $atts Attributs du shortcode.
     * @return string Le contenu HTML du tableau de bord.
     */
    public function render_dashboard_page( $atts ) {
        // Vérification que l'utilisateur est connecté pour voir les tâches
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'You must be logged in to view your tasks.', 'ispag-crm' ) . '</p>';
        }

        ob_start(); // Commence la capture de l'output
        
        echo '<div class="ispag-dashboard-wrap wrap">';
        echo '<h2>' . esc_html__( 'My Active Tasks', 'ispag-crm' ) . '</h2>';
        
        $note_repo = new ISPAG_Note_Repository();
        $tasks = $note_repo->get_active_tasks();
        $this->render_tasks_table( $tasks );
 
        echo '</div>';
        
        return ob_get_clean(); // Retourne le contenu capturé
    }

        
    


    // /**
    //  * Récupère la liste des tâches actives pour l'utilisateur.
    //  * @return array
    //  */
    // public function get_active_tasks() {
    //     global $wpdb;
    //     $table = $wpdb->prefix . self::TABLE_NOTES;
    //     $user_id = get_current_user_id();
        
    //     // Requête pour les tâches actives assignées à l'utilisateur (incluant les rappels de santé)
    //     $sql = $wpdb->prepare(
    //         "SELECT * FROM {$table} 
    //         WHERE is_task = 1
    //         AND user_id = %d
    //         AND is_completed = 0
    //         ORDER BY due_date ASC", 
    //         $user_id
    //     );


    //     error_log(print_r($sql, true));

    //     return $wpdb->get_results( $sql );
    // }
    
    /**
     * Affiche les tâches dans un tableau HTML avec une meilleure mise en forme,
     * incluant la nouvelle colonne Reminder.
     */
    private function render_tasks_table( $tasks ) {
        if ( empty( $tasks ) ) {
            // Ajoute l'ID pour le ciblage JS si la liste devient vide
            echo '<p id="ispag-no-tasks-message">' . esc_html__( 'You have no active tasks.', 'ispag-crm' ) . '</p>'; 
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped ispag-task-list ispag-dashboard-style">';
        echo '<thead><tr>';
        echo '<th class="check-column"><input type="checkbox" id="cb-select-all-1" disabled></th>'; // Statut
        echo '<th>' . esc_html__( 'Task Title', 'ispag-crm' ) . '</th>';
        echo '<th>' . esc_html__( 'Associated Contact', 'ispag-crm' ) . '</th>';
        echo '<th class="column-reminder-date">' . esc_html__( 'Reminder', 'ispag-crm' ) . '</th>';
        echo '<th class="column-due-date">' . esc_html__( 'Due Date', 'ispag-crm' ) . '</th>';
        echo '<th class="column-actions">' . esc_html__( 'Actions', 'ispag-crm' ) . '</th>';
        echo '</tr></thead>';
        
        echo '<tbody>';
        foreach ( $tasks as $task ) {
            $entity_info = $this->get_entity_info( $task );
            
            // ----------------------------------------------------------------------
            // NOUVELLE LOGIQUE DE TRONQUAGE DU CONTENU
            // ----------------------------------------------------------------------
            $task_content = stripslashes( $task->content );
            $first_line_end = strpos( $task_content, "\n" );
            $short_content = $task_content;
            $has_more = false;

            if ( $first_line_end !== false ) {
                $short_content = substr( $task_content, 0, $first_line_end );
                $has_more = true;
            } 
            // OPTIONNEL : Limite de la première ligne si elle n'a pas de saut de ligne
            else if ( strlen($short_content) > 150 ) { 
                $short_content = substr( $task_content, 0, 150 );
                $has_more = true;
            }

            if ($has_more) {
                $short_content .= '...';
            }
            $task_display_content = wp_kses_post( $short_content );
            // ----------------------------------------------------------------------
            
            // --- 1. CALCUL ET FORMATAGE DE LA DATE D'ÉCHÉANCE (due_date) ---
            $due_date_raw = ! empty( $task->due_date ) ? strtotime( $task->due_date ) : false;
            $due_date_display = 'N/A';
            $due_date_class = '';
            
            if ( $due_date_raw ) {
                $time_diff = $due_date_raw - time();
                $date_format = ( $time_diff < DAY_IN_SECONDS && $time_diff > -DAY_IN_SECONDS ) ? 'H:i' : get_option( 'date_format' );
                
                $due_date_display = date_i18n( $date_format, $due_date_raw );
                
                if ( $time_diff < 0 ) {
                    $due_date_display = __( 'Overdue', 'ispag-crm' ) . ' ' . $due_date_display;
                    $due_date_class = 'is-overdue';
                } elseif ( $time_diff < DAY_IN_SECONDS ) {
                    $due_date_class = 'is-today';
                }
            }

            // --- 2. CALCUL ET FORMATAGE DE LA DATE DE RAPPEL (reminder_date) ---
            $reminder_date_raw = ! empty( $task->reminder_date ) ? strtotime( $task->reminder_date ) : false;
            $reminder_date_display = 'N/A';
            $reminder_date_class = '';

            if ( $reminder_date_raw ) {
                $time_diff_reminder = $reminder_date_raw - time();
                
                if ( $time_diff_reminder <= DAY_IN_SECONDS ) {
                    $reminder_date_display = date_i18n( get_option( 'time_format' ), $reminder_date_raw );
                    $reminder_date_class = $time_diff_reminder < 0 ? 'is-passed' : 'is-imminent';
                } else {
                    $reminder_date_display = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $reminder_date_raw );
                }
            }
            
            // --- Rendu des Lignes ---
            
            echo '<tr id="task-' . esc_attr( $task->id ) . '" data-task-id="' . esc_attr( $task->id ) . '">';
            
            // Colonne Statut (Case à cocher)
            echo '<th scope="row" class="check-column">';
            echo '<input type="checkbox" name="task[]" value="' . esc_attr( $task->id ) . '" class="ispag-task-checkbox" data-id="' . esc_attr( $task->id ) . '">';
            echo '</th>';

            // Colonne Titre de la tâche (Contenu) - UTILISE LE CONTENU TRONQUÉ
            echo '<td class="ispag-task-content">' . $task_display_content . '</td>';
            
            // Colonne Entité associée
            echo '<td>' . wp_kses_post( $entity_info ) . '</td>';
            
            // Colonne Rappel (Reminder)
            echo '<td class="column-reminder-date ' . esc_attr( $reminder_date_class ) . '">' . esc_html( $reminder_date_display ) . '</td>';

            // Colonne Date d'échéance (Due Date)
            echo '<td class="' . esc_attr( $due_date_class ) . '">' . esc_html( $due_date_display ) . '</td>';

            // Colonne Actions
            echo '<td>';
            echo '<button class="ispag-btn complete-task-btn button-small button-primary" data-activity-id="' . esc_attr( $task->id ) . '">' . esc_html__( 'Done', 'ispag-crm' ) . '</button>';
            echo '<button class="ispag-btn ispag-reschedule-task button-small" data-id="' . esc_attr( $task->id ) . '">' . esc_html__( 'Reschedule', 'ispag-crm' ) . '</button>';
            echo '</td>';
            
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Récupère le nom du contact/de l'entreprise lié à la tâche (Simplifié).
     * NOTE: Cette fonction doit être adaptée à votre structure réelle de contacts/entreprises.
     */
    private function get_entity_info( $task ) {
        $info = '';

        $contact_page = get_page_by_path( 'contact-detail' );
        $contact_page_url = $contact_page ? get_permalink( $contact_page ) : '#';

        $company_page = get_page_by_path( 'contact-detail' );
        $company_page_url = $company_page ? get_permalink( $company_page ) : '#';
        
        // Récupération de l'info Contact
        if ( ! empty( $task->contact_id ) ) {
            // Supposant que contact_id est l'ID d'un utilisateur ou d'une custom post type
            $contact_ids = array_map( 'absint', explode( ',', $task->contact_id ) );
            $contact_id = $contact_ids[0];
            
            if ( $contact_id ) {
                 // Remplacez par votre fonction de récupération du nom du contact réel
                 $contact_name = get_the_author_meta( 'display_name', $contact_id ); 
                 $info .= sprintf( '<a href="%s?user_id=%d">%s</a>', $contact_page_url, $contact_id, $contact_name );
            }
        }
        // Récupération de l'info Entreprise
        if ( ! empty( $task->company_id ) ) {
             // Remplacez par votre fonction de récupération du nom de l'entreprise réelle
             $company_name = $this->get_company_name( $task->company_id );
             $info .= ! empty( $info ) ? ' / ' : '';
             $info .= sprintf( '<a href="%s?company_id=%d">%s</a>', $company_page_url, $task->company_id, $company_name ?: __( 'Company', 'ispag-crm' ) );
        }
        
        return $info ?: __( 'N/A', 'ispag-crm' );
    }

    /**
     * Récupère le nom de l'entreprise (ou du fournisseur) par son ID.
     *
     * @param int $company_id L'ID de l'entreprise.
     * @return string|null Le nom de l'entreprise ou null si non trouvé.
     */
    private function get_company_name($company_id){
        global $wpdb;

        // Assurez-vous que l'ID est un nombre entier valide
        if ( empty( $company_id ) || ! is_numeric( $company_id ) ) {
            return null;
        }

        // Nom de votre table d'entreprises (Fournisseurs)
        $table_name = $wpdb->prefix . 'achats_fournisseurs'; 

        // Requête pour récupérer le champ 'name'
        $company_name = $wpdb->get_var( $wpdb->prepare( "
            SELECT Fournisseur
            FROM {$table_name}
            WHERE ID = %d
        ", $company_id ) );

        // Retourne le nom (ou null s'il n'y a pas de résultat)
        return $company_name ? esc_html( $company_name ) : null;
    }
    
    // --- Méthode AJAX pour la complétion ---

    public function ajax_complete_task() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NOTES;

        // Sécurité
        if ( ! check_ajax_referer( 'ispag_crm_nonce', 'security', false ) || ! current_user_can( 'read' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security or permission check failed.', 'ispag-crm' ) ) );
        }

        $task_id = absint( filter_input( INPUT_POST, 'task_id', FILTER_VALIDATE_INT ) );
        $current_user_id = get_current_user_id();
        
        if ( $task_id === 0 ) {
            wp_send_json_error( array( 'message' => __( 'Missing Task ID.', 'ispag-crm' ) ) );
        }

        // Vérification de la propriété de la tâche
        $check_sql = $wpdb->prepare(
            "SELECT ID FROM {$table} WHERE ID = %d AND author_id = %d AND is_completed = 0",
            $task_id,
            $current_user_id
        );
        if ( ! $wpdb->get_var( $check_sql ) ) {
            wp_send_json_error( array( 'message' => __( 'Task not found or already completed.', 'ispag-crm' ) ) );
        }

        // Mise à Jour
        $updated = $wpdb->update(
            $table,
            array( 
                'is_completed' => 1,
                'completed_at' => current_time( 'mysql' ),
            ),
            array( 'ID' => $task_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( $updated !== false ) {
            wp_send_json_success( array( 
                'message' => __( 'Task successfully marked as completed.', 'ispag-crm' ),
                'task_id' => $task_id 
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Database error. Task could not be updated.', 'ispag-crm' ) ) );
        }

        wp_die();
    }
    
    // --- Méthode AJAX pour le report ---

    public function ajax_reschedule_task() {
        // Logique de report (similaire à la complétion, mais update du champ due_date)
        // Vous aurez besoin d'un champ 'new_due_date' dans le POST
        
        wp_send_json_error( array( 'message' => 'Reschedule logic not implemented yet.' ) );
        wp_die();
    }
}
