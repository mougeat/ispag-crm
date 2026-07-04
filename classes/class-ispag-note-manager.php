<?php
/**
 * Gère les notes et tâches associées aux contacts dans l'interface ISPAG.
 * C'est le point d'entrée pour l'initialisation des autres classes.
 */
class ISPAG_Note_Manager {

    private $repository;
    private $ajax_handler;
    private $renderer;
    private $modal_view;

    const ASSET_HANDLE                  = 'ispag-contact-notes';
    const ISPAG_CREATION_MODAL_HANDLE   = 'ispag-creation-modal-script';
    const ISPAG_ACTIVITY_ACTIONS_HANDLE = 'ispag-activity-actions-script';
    const ISPAG_TASK_SIDEBAR_HANDLE     = 'ispag-task-sidebar-script';
    

    const AJAX_ACTION_SAVE_NOTE = 'ispag_save_contact_note';

    const TABLE_DEALS = ISPAG_Crm_Deal_Constants::TABLE_NAME;
    const TABLE_COMPANY = ISPAG_Crm_Company_Constants::TABLE_NAME;
    const TABLE_NOTE = 'wor9711_ispag_contact_notes';
    
    public function __construct() {

        // Assurez-vous d'inclure les autres fichiers de classe ici !
        // require_once 'class-ispag-note-repository.php';
        // require_once 'class-ispag-note-ajax-handler.php';
        // require_once 'class-ispag-note-renderer.php';
        // require_once 'class-ispag-note-modal-view.php';
        
        // 1. Initialiser le Repository (Logique BDD)
        $this->repository = new ISPAG_Note_Repository();
        
        // 2. Initialiser le Rendu
        $this->renderer = new ISPAG_Note_Renderer();

        // 3. Initialiser la Modal (Vue)
        $this->modal_view = new ISPAG_Note_Modal_View();
        
        // 4. Initialiser le Handler AJAX
        $this->ajax_handler = new ISPAG_Note_Ajax_Handler( $this->repository );
        
        // Hooks principaux
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        
        // Affichage du HTML de la modale dans le pied de page
        add_action( 'wp_footer', array( $this->modal_view, 'render_note_modal_html' ) );
        add_action( 'wp_footer', array( $this->modal_view, 'render_note_sidebar_html' ) );


        add_action( 'wp_ajax_ispag_search_contacts_select2', array( $this, 'ispag_search_contacts' ) );
        add_action( 'wp_ajax_ispag_search_company_select2', array( $this, 'ispag_search_company' ) );
        add_action( 'wp_ajax_ispag_search_deals_select2', array( $this, 'ispag_search_deals' ) );

        add_action( 'wp_ajax_ispag_complete_task', array( $this, 'handle_complete_task_ajax' ) );
    }
    
    /**
     * Enqueue les scripts et styles (si non fait dans la Modal View).
     */
    public function enqueue_assets() {
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        // Enregistrement et mise en file d'attente du CSS Select2
        wp_enqueue_style( 
            'select2-css', 
            $plugin_url . 'assets/css/select2.min.css', 
            array(), 
            '4.1.0-rc.0' 
        );

        // Enregistrement et mise en file d'attente du JS Select2
        wp_enqueue_script( 
            'select2-js', 
            $plugin_url . 'assets/js/select2.min.js', 
            array( 'jquery' ), 
            '4.1.0-rc.0', 
            true 
        );
        
        // Enregistrement du style CSS
        wp_register_style( 
            self::ASSET_HANDLE, 
            $plugin_url . 'assets/css/ispag-note-modal.css', 
            array(), 
            '1.0' 
        );
        wp_enqueue_style( self::ASSET_HANDLE );

        wp_enqueue_style( 
            'ispag-note-renderer', 
            $plugin_url . 'assets/css/ispag-note-renderer.css', 
            array(), 
            '1.0' 
        );
        
        wp_register_script( 
            self::ASSET_HANDLE, 
            $plugin_url . 'assets/js/ispag-data-init.js', 
            // AJOUTEZ 'editor', 'wp-i18n', et assurez-vous que 'wp-tinymce' est présent.
            array( 'jquery', 'select2-js', 'wp-tinymce', 'editor', 'wp-i18n' ), 
            '1.0', 
            true 
        );
        

        // --- 2. ispag-creation-modal.js ---
        wp_register_script( 
            self::ISPAG_CREATION_MODAL_HANDLE, 
            $plugin_url . 'assets/js/ispag-creation-modal.js', 
            // Dépend de l'initialisation des données et de Select2
            array( 'jquery', 'select2-js', self::ASSET_HANDLE ), 
            '1.0', 
            true 
        );

        // --- 3. ispag-activity-actions.js ---
        wp_register_script( 
            self::ISPAG_ACTIVITY_ACTIONS_HANDLE, 
            $plugin_url . 'assets/js/ispag-activity-actions.js', 
            // Dépend de JQuery et de l'initialisation des données
            array( 'jquery', self::ASSET_HANDLE ), 
            '1.0', 
            true 
        );

        // --- 4. ispag-task-sidebar.js ---
        wp_register_script( 
            self::ISPAG_TASK_SIDEBAR_HANDLE, 
            $plugin_url . 'assets/js/ispag-task-sidebar.js', 
            // Dépend de JQuery et de l'initialisation des données (pour closeSidebar, etc.)
            array( 'jquery', self::ASSET_HANDLE ), 
            '1.0', 
            true 
        );

        // Mise en file d'attente de tous les scripts
        wp_enqueue_script( self::ASSET_HANDLE );
        wp_enqueue_script( self::ISPAG_CREATION_MODAL_HANDLE );
        wp_enqueue_script( self::ISPAG_ACTIVITY_ACTIONS_HANDLE );
        wp_enqueue_script( self::ISPAG_TASK_SIDEBAR_HANDLE );

        // --- FUSION DES LOCALISATIONS EN UN SEUL APPEL ---
        
        $current_user = wp_get_current_user();
        $localization_data = array(
            // DONNÉES TINYMCE
            'tinymceUrl' => rtrim( includes_url( 'js/tinymce/' ), '/' ),
            
            // DONNÉES AJAX EXISTANTES
            'ajaxurl'                   => admin_url( 'admin-ajax.php' ),
            'nonce'                     => wp_create_nonce( 'ispag_crm_nonce' ),
            'action'                    => self::AJAX_ACTION_SAVE_NOTE,
            'current_user_id'           => (string) $current_user->ID,
            'current_user_name'         => $current_user->display_name,
            // ... (Autres textes de localisation)
            'textSelectCompanies'       => __('Select one or more companies...', 'ispag-crm'),
            'textSelectContacts'        => __('Select one or more participants...', 'ispag-crm'),
            'textSelectDeals'           => __('Select one or more deals...', 'ispag-crm'),
            'textCreateNote'            => __('Create note', 'ispag-crm'),
            'textCreate'                => __('Save', 'ispag-crm'),
            'textSend'                  => __('Send', 'ispag-crm'),
            'textConformCompleteTask'   => __('Do you really want to mark this task as complete?', 'ispag-crm'),
            'textConfirmDeleteLog'      => __('Are you sure you want to delete this activity? This action is irreversible.', 'ispag-crm'),
            'textEditTask'              => __('Edit task', 'ispag-crm'),
            'modalTitleEdit'            => __('Edit activity', 'ispag-crm'),
            'textUpdate'                => __('Update', 'ispag-crm'),
            'textUpdatedMeeting'        => __('Updated meeting', 'ispag-crm'),
            'textCreateNote'            => __('Create a Note', 'ispag-crm'),
            'textSaving'                => __('Saving...', 'ispag-crm'),
            'textLogMeeting'            => __('Log meeting', 'ispag-crm'),
            'textLogTask'               => __('Log task', 'ispag-crm'),
            'textLogCall'               => __('Log a call', 'ispag-crm'),
            'textLogMail'               => __('Log mail', 'ispag-crm'),
            'textApply'                 => __('Apply', 'ispag-crm'),
            'textReplaceContent'        => __('Replace the current content with the template', 'ispag-crm'),
            'textMailSubject'           => __('Mail subject', 'ispag-crm'),
            'textMailSubjectInput'      => __('Subject of your message', 'ispag-crm'),
            'textTaskTitle'             => __('Task title', 'ispag-crm'),
            'textMeetingTitle'          => __('Meeting subject', 'ispag-crm'),
            'textCallTitle'             => __('Call title', 'ispag-crm'),
            'textNoteTitle'             => __('Note title', 'ispag-crm'),
            'textNoteTitleInput'        => __('Quick summary', 'ispag-crm'),
            
        );

        wp_localize_script( 
            self::ASSET_HANDLE, 
            'ispagNoteData', 
            $localization_data 
        );
    }

    // Le reste des fonctions de l'ancienne classe peut être distribué ou laissé ici
    // s'il s'agit de logique de haut niveau (comme des fonctions de support).

    public function ispag_search_contacts() {
        global $wpdb;

        // 1. Vérification de sécurité (Nonce)
        if ( ! check_ajax_referer( 'ispag_crm_nonce', 'security', false ) ) {
            wp_send_json_error( array( 'message' => 'Nonce de sécurité invalide.' ) );
            wp_die();
        }
        
        $search_term = isset($_GET['search_term']) ? sanitize_text_field($_GET['search_term']) : '';
        $contacts = [];

        if (!empty($search_term) && strlen($search_term) >= 2) {
            
            $search_pattern = '%' . $wpdb->esc_like($search_term) . '%';
            $meta_key_status = ISPAG_Crm_Contact_Constants::ACCOUNT_STATUS;

            $sql_prepared = $wpdb->prepare(
                "SELECT 
                    u.ID, 
                    u.display_name, 
                    u.user_email,
                    pm_phone.meta_value as billing_phone
                FROM {$wpdb->users} u
                -- Jointure pour le téléphone
                LEFT JOIN {$wpdb->usermeta} pm_phone ON (u.ID = pm_phone.user_id AND pm_phone.meta_key = 'billing_phone')
                -- Jointure pour vérifier le statut du compte
                LEFT JOIN {$wpdb->usermeta} pm_status ON (u.ID = pm_status.user_id AND pm_status.meta_key = %s)
                WHERE (
                    u.display_name LIKE %s 
                    OR u.user_login LIKE %s 
                    OR u.user_email LIKE %s
                )
                -- FILTRE DE SÉCURITÉ : Pas de statut 'disabled' (on accepte NULL ou tout sauf 'disabled')
                AND (pm_status.meta_value IS NULL OR pm_status.meta_value != 'disabled')
                ORDER BY u.display_name ASC 
                LIMIT 20",
                $meta_key_status,
                $search_pattern, 
                $search_pattern,
                $search_pattern
            );

            $results = $wpdb->get_results($sql_prepared);
            
            if (!empty($results)) {
                foreach ($results as $row) {
                    $contacts[] = [
                        'id'    => (string) $row->ID, 
                        'text'  => $row->display_name,
                        'email' => $row->user_email,
                        'phone' => $row->billing_phone,
                    ];
                }
            }
        }
        
        wp_send_json_success( array(
            'contacts'            => $contacts,
            'debug_term'          => $search_term,
            'debug_sql'           => isset($sql_prepared) ? $sql_prepared : 'None',
            'debug_contact_count' => count($contacts),
        ) );
    }

    /**
     * Gère la requête AJAX pour rechercher des entreprises via Select2.
     * Action: ispag_search_contacts
     */
    public function ispag_search_company() {
        global $wpdb;

        // // 1. Vérification de sécurité (Nonce)
        // if ( ! check_ajax_referer( 'ispag_note_nonce', 'security', false ) ) {
        //     error_log('[ISPAG AJAX ERROR] Nonce de sécurité invalide pour ispag_search_contacts.');
        //     wp_send_json_error( array( 'message' => 'Nonce de sécurité invalide.' ) );
        //     wp_die();
        // }
        
        // 2. Récupération et nettoyage du terme de recherche
        $search_term = isset($_GET['search_term']) ? sanitize_text_field($_GET['search_term']) : '';
        $company = [];

        // Variable pour le débogage de la requête SQL
        $debug_sql = 'No search term provided or term too short.'; 
        $debug_search_term = $search_term;

        // On recherche uniquement si le terme est non vide et d'au moins 2 caractères
        if (!empty($search_term) && strlen($search_term) >= 2) {
            
            // 3. Préparation et exécution de la requête SQL (CORRECTION DU FILTRE)
            $search_pattern = '%' . $wpdb->esc_like($search_term) . '%';

            $postmeta_table = $wpdb->postmeta;
            $meta_city_key = ISPAG_Crm_Company_Constants::META_COMPANY_CITY;

            $table_fournisseur = self::TABLE_COMPANY; 

            $sql_prepared = $wpdb->prepare(
                "SELECT 
                    t1.viag_id, 
                    t1.company_name,
                    t2.meta_value AS company_city 
                FROM {$table_fournisseur} AS t1
                
                -- Jointure pour récupérer la ville
                LEFT JOIN $postmeta_table AS t2 
                    ON t1.viag_id = t2.post_id
                    AND t2.meta_key = %s
                    
                WHERE t1.company_name LIKE %s AND t1.is_active = 1
                ORDER BY t1.company_name ASC 
                LIMIT 20",
                $meta_city_key, // 1er %s: La clé pour la ville
                $search_pattern // 2ème %s: Le terme de recherche
            );

            // Stocker la requête préparée AVANT l'exécution pour le débogage
            $debug_sql = $sql_prepared; 

            // ENREGISTREMENT DANS LE LOG DU SERVEUR
            // error_log(
            //     sprintf(
            //         '[ISPAG AJAX LOG] Terme reçu: %s | Requête SQL: %s',
            //         $debug_search_term,
            //         $debug_sql
            //     )
            // );
            
            $results = $wpdb->get_results($sql_prepared);
            
            if (!empty($results)) {
                foreach ($results as $row) {

                    $city_display = !empty($row->company_city) ? ' (' . $row->company_city . ')' : '';

                    $companies[] = [
                        'id' => (string) $row->viag_id, 
                        'text' => $row->company_name . $city_display ,
                        'company_name' => $row->company_name,
                    ];
                }
//                 error_log(sprintf('[ISPAG AJAX LOG] Nombre de company trouvés: %d', count($companies)));
            } else {
//                 error_log('[ISPAG AJAX LOG] Aucune company trouvé pour le terme: ' . $debug_search_term);
            }
        }
        
        // 4. Renvoi des résultats au format JSON attendu
        // INCLUT LE DÉBOGAGE POUR L'INSPECTEUR DU NAVIGATEUR
        wp_send_json_success( array(
            'companies' => $companies,
            'debug_term' => $debug_search_term,  // Quel terme PHP a-t-il reçu ?
            'debug_sql' => $debug_sql,     // Quelle requête SQL PHP a-t-il exécutée ?
            'debug_contact_count' => count($companies), // Combien de contacts ont été trouvés ?
        ) );
    }

    /**
     * Gère la requête AJAX pour rechercher des deals filtrés par contact ou entreprise
     * Utilisation de FIND_IN_SET pour le champ text associé aux contacts
     */
    public function ispag_search_deals() { 
        global $wpdb;
    
        // 1. Vérification du Nonce (la cause probable du 403)
        if ( ! check_ajax_referer( 'ispag_crm_nonce', 'security', false ) ) {
            wp_send_json_error( 'Session expirée, veuillez rafraîchir la page.' );
        }

        $table_deals = self::TABLE_DEALS;
        
        $table_deals = self::TABLE_DEALS;
        $search_term = isset($_REQUEST['search_term']) ? sanitize_text_field($_REQUEST['search_term']) : '';
        $contact_id  = isset($_REQUEST['contact_id']) ? intval($_REQUEST['contact_id']) : 0;
        $company_id  = isset($_REQUEST['company_id']) ? intval($_REQUEST['company_id']) : 0;

        $query_args = [];
        $mandatory_conditions = []; // Conditions obligatoires (AND)
        $search_conditions = [];    // Conditions de recherche (OR)

        // --- A. RECHERCHE TEXTUELLE (OR) ---
        if (!empty($search_term)) {
            $like_term = '%' . $wpdb->esc_like($search_term) . '%';
            $search_conditions[] = "d1.project_name LIKE %s";
            $query_args[] = $like_term;
            $search_conditions[] = "d1.offer_num LIKE %s";
            $query_args[] = $like_term;
        }

        // --- B. FILTRAGE PAR CONTACT/ENTREPRISE (AND) ---
        if ($company_id > 0) {
            $mandatory_conditions[] = "d1.associated_company_id = %d";
            $query_args[] = $company_id;
        }

        if ($contact_id > 0) {
            $mandatory_conditions[] = "FIND_IN_SET(%d, d1.associated_contact_ids) > 0";
            $query_args[] = $contact_id;
        }

        // --- C. STATUTS FIXES (AND) ---
        $mandatory_conditions[] = "d1.process_type IN ('Offre', 'Commande')";
        $mandatory_conditions[] = "d1.project_db_status IN (0, 1)";

        // --- D. ASSEMBLAGE DE LA REQUÊTE ---
        $sql = "SELECT d1.deal_group_ref, d1.project_name, d1.offer_num, d1.total_excl_vat 
                FROM {$table_deals} d1 WHERE ";

        // On groupe les conditions de recherche dans des parenthèses : (name LIKE %s OR offer_num LIKE %s)
        $sql_search = !empty($search_conditions) ? "(" . implode(' OR ', $search_conditions) . ")" : "1=1";
        
        // On ajoute les conditions obligatoires avec des AND
        $sql_mandatory = !empty($mandatory_conditions) ? implode(' AND ', $mandatory_conditions) : "1=1";

        $final_sql = $sql . $sql_search . " AND " . $sql_mandatory;
        $final_sql .= " GROUP BY d1.deal_group_ref ORDER BY d1.project_name ASC LIMIT 30";

        $sql_prepared = $wpdb->prepare($final_sql, $query_args);
        // error_log('[ispag_search_deals] sql_prepared --> ' . $sql_prepared);

        $results = $wpdb->get_results($sql_prepared);
        
        if (!empty($results)) {
            foreach ($results as $row) {
                $deals[] = [
                    'id'             => (string) $row->deal_group_ref, 
                    'text'           => $row->project_name . ' (' . $row->offer_num . ')',
                    'project_name'   => $row->project_name,
                    'offer_num'      => $row->offer_num,
                    'total_excl_vat' => $row->total_excl_vat,
                ];
            }
        }
        
        wp_send_json_success([
            'deals' => $deals,
            'debug_sql' => $sql_prepared 
        ]);
    }

    /**
     * Gère la requête AJAX pour marquer une tâche comme complétée.
     */
    public function handle_complete_task_ajax() {
        global $wpdb;
        $table_name = self::TABLE_NOTE; 

        // 1. Vérification de sécurité
        check_ajax_referer( 'ispag_crm_nonce', 'security' );

        // 2. Récupération de l'ID
        $activity_id = isset( $_POST['activity_id'] ) ? absint( $_POST['activity_id'] ) : 0;

        if ( $activity_id === 0 ) {
            wp_send_json_error( array( 'message' => __( 'Missing activity ID.', 'ispag-crm' ) ) );
        }

        // 3. Mise à jour dans la base de données
        $data_to_update = array(
            'is_completed' => 1, // Marquer comme complété
            'completed_at' => current_time( 'mysql' ), // Enregistrer l'heure de complétion
            'updated_at' => current_time( 'mysql' ), // Mettre à jour la date de modification
        );
        // On s'assure qu'on ne met à jour que les tâches (pour la sécurité)
        $where = array( 'id' => $activity_id, 'is_task' => '1' ); 

        $result = $wpdb->update( $table_name, $data_to_update, $where );

        if ( $result === false ) {
            wp_send_json_error( array( 'message' => __( 'Database update failed or task not found.', 'ispag-crm' ) ) );
        }

        // 4. Récupérer l'élément mis à jour pour le nouveau HTML
        $log_entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $activity_id ) );

        // Générer le nouveau HTML en utilisant la fonction unifiée
        $new_item_html = ''; 

        // 5. Succès
        wp_send_json_success( array(
            'message' => __( 'Task marked as completed.', 'ispag-crm' ),
            'activity_id' => $activity_id,
            'html' => $new_item_html,
        ) );
    }

    /**
     * Récupère la dernière note/activité de contact (MEETING, CALL, EMAIL) 
     * associée à une entité (contact, deal, company).
     *
     * @param string $entity_type 'contact', 'deal', ou 'company'.
     * @param int    $entity_id   ID de l'entité.
     * @return object|null L'objet de la dernière activité (note), ou null si aucune trouvée.
     */
    public function get_last_contact( $entity_type, $entity_id ) {
        global $wpdb;
        
        // 1. Définir le mapping de colonne
        $column_map = [
            'contact' => 'contact_id',
            'deal'    => 'deal_id',
            'company' => 'company_id',
        ];

        // Vérification de base
        if ( ! isset( $column_map[ $entity_type ] ) ||  !isset($entity_id) ) {
            return null;
        }

        $safe_entity_id =  $entity_id;
        $column = $column_map[ $entity_type ];
        
        // La table des notes doit être accessible
        $table_notes = self::TABLE_NOTE; 

        // 2. Préparation du pattern de recherche pour les IDs multiples (comme fait précédemment)
        $like_pattern = $wpdb->esc_like( $safe_entity_id );
        
        // Définir les types d'activités de contact à rechercher
        $contact_types = ['MEETING', 'CALL', 'EMAIL', 'LOG_EMAIL', 'EMAIL_CAMPAIGN', 'EMAIL_TRANSACTIONAL', 'CHRISTMAS_PRESENT', 'WHATSAPP', 'SMS'];
        $type_placeholders = implode( ',', array_fill( 0, count( $contact_types ), '%s' ) );


        // 4. Construction de la requête SQL complète
        $sql = "
            SELECT * FROM {$table_notes} 
            WHERE (
                {$column} = %s 
                OR FIND_IN_SET(%s, {$column}) > 0
            )
            -- Filtrer UNIQUEMENT les types de contact
            AND type IN ({$type_placeholders}) 
            -- Trier par date de création décroissante (du plus récent au plus ancien)
            ORDER BY created_at DESC 
            -- Limiter à la première ligne (le contact le plus récent)
            LIMIT 1
        ";

        // 5. Préparation de la requête avec les valeurs de remplacement
        $prepared_values = array_merge(
            // Valeurs pour la condition de recherche d'ID
            [ 
                $safe_entity_id, 
                $like_pattern . ',%', 
                '%,' . $like_pattern, 
                '%,' . $like_pattern . ',%' 
            ],
            // Valeurs pour la condition IN (MEETING, CALL, EMAIL)
            $contact_types 
        );
        
        $sql_prepared = $wpdb->prepare( $sql, ...$prepared_values );

        // error_log('REQUETE SQL ' . $sql_prepared);
        
        // 6. Exécution et retour (récupère un seul objet)
        $last_contact = $wpdb->get_row( $sql_prepared, OBJECT ); 


        // error_log('RETOUR note last contact' . print_r($last_contact, true));

        return $last_contact;
    }


    /**
     * Enregistre une nouvelle note/activité dans la base de données.
     * Cette méthode est appelée par le Webhook Handler.
     *
     * @param object $note_data L'objet contenant les données de la note (contact_id, title, content, activity_type, date_time).
     * @return int|WP_Error L'ID de l'insertion en cas de succès, ou un objet WP_Error.
     */
    public function create_note( $note_data ) {
        global $wpdb;

        // Validation simple des données reçues
        if ( empty( $note_data->contact_id ) || empty( $note_data->content ) ) {
            return new WP_Error( 'data_missing', 'Données de contact ou contenu manquant pour la création de la note.' );
        }

        // --- Préparation des données pour l'insertion ---
        // error_log('[create_note] received datas : ' . print_r($note_data, true));
        $data = [
            'contact_id'    => $note_data->contact_id, 
            'company_id'    => $note_data->company_id, 
            'deal_id'       => $note_data->deal_id, 
            'type'          => sanitize_key( $note_data->activity_type ?? 'NOTE' ), 
            'outcome'       => sanitize_text_field( $note_data->outcome ?? '' ), 
            'content'       => wp_kses_post( $note_data->content ), 
            'title'         => sanitize_text_field( $note_data->title ?? '' ), 
            'is_task'       => absint( $note_data->is_task ?? 0 ), // AJOUTÉ car présent dans ta DB
            'due_date'      => ! empty( $note_data->due_date ) ? $note_data->due_date : null, // AJOUTÉ
            'user_id'       => absint( $note_data->user_id ?? 0 ), 
            'media_ids'     => $note_data->media_ids ?? null,
            'created_at'    => current_time( 'mysql' ), 
        ];
        // error_log('[create_note] saved datas : ' . print_r($data, true));

        // --- Formats des données pour wpdb::insert ---
        $format = [
            '%s', // contact_id
            '%s', // company_id
            '%s', // deal_id
            '%s', // type
            '%s', // outcome
            '%s', // content
            '%s', // title
            '%d', // is_task
            '%s', // due_date
            '%d', // user_id
            '%s', // media_ids (varchar dans ta DB, donc %s)
            '%s', // created_at
        ];

        // --- Insertion dans la base de données ---
        $inserted = $wpdb->insert(
            self::TABLE_NOTE, 
            $data, 
            $format 
        );

        if ( $inserted === false ) {
            // Échec de l'insertion SQL
            return new WP_Error( 'db_insert_failed', 'Erreur de base de données lors de l\'enregistrement de la note.', [ 'db_error' => $wpdb->last_error ] );
        }

        // Succès : retourne l'ID de la ligne insérée
        return $wpdb->insert_id;
    }

    
}
