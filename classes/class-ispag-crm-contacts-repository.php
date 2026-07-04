<?php

if ( ! class_exists( 'ISPAG_Crm_Contacts_Repository' ) ) :

class ISPAG_Crm_Contacts_Repository {

    /**
     * @var string Nom des tables.
     */
    private $table_name;
    private $table_priorities;
    private $table_companies;

    /**
     * @var string Fichier de log statique.
     */
    private static $log_file = WP_CONTENT_DIR . '/ispag_contact_repository.log';
    
    /**
     * Constructeur.
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->users; 
        $this->table_priorities = ISPAG_Crm_Contact_Constants::TABLE_PRIORITIES_NAME; 
        $this->table_companies = ISPAG_Crm_Company_Constants::TABLE_NAME; 

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_ispag_assets' ) );

        add_action( 'wp_ajax_ispag_load_gemini_contact_summary', array( $this, 'ajax_load_contact_ai_summary' ) );
        add_action('wp_ajax_ispag_load_contact_meeting_prep', [$this, 'ajax_load_contact_meeting_prep']);

        // 1. Afficher le champ dans l'édition du profil
        add_action('show_user_profile', array( $this, 'ispag_add_account_status_field' ));
        add_action('edit_user_profile', array( $this, 'ispag_add_account_status_field' ));
        // 2. Sauvegarder la modification
        add_action('personal_options_update', array( $this, 'ispag_save_account_status_field' ));
        add_action('edit_user_profile_update', array( $this, 'ispag_save_account_status_field' ));

        add_action( 'show_user_profile', array( $this, 'ispag_add_user_department_field' ));
        add_action( 'edit_user_profile', array( $this, 'ispag_add_user_department_field' ));
        add_action( 'personal_options_update', array( $this, 'ispag_save_user_department_field' ));
        add_action( 'edit_user_profile_update', array( $this, 'ispag_save_user_department_field' ));
        add_action( 'wp_ajax_ispag_remove_deal_contact_association', [ $this, 'remove_deal_contact_association' ]); 
    }

    /**
     * Enqueue les styles et scripts nécessaires, incluant le script d'édition en ligne.
     */
    public function enqueue_ispag_assets() {
        
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        wp_enqueue_style( 'ispag-crm-styles', $plugin_url . 'assets/css/ispag-crm-styles.css', array(), '1.0.0' );

        wp_enqueue_style( 'ispag-contact-detail-styles', $plugin_url . 'assets/css/ispag-contact-detail-styles.css', array(), '1.0.0' );
        
        // -----------------------------------------------------------
        // --- 2. JAVASCRIPT ---
        // -----------------------------------------------------------

        wp_enqueue_script( 'ispag-crm-bulk-edit-js', $plugin_url . 'assets/js/ispag-bulk-edit.js', array( 'jquery' ), '1.0.0', true );

        wp_enqueue_script( 
            'ispag-contact-detail-edit-js', 
            $plugin_url . 'assets/js/ispag-contact-detail-edit.js', 
            array( 'jquery' ), 
            '1.0.0', 
            true 
        );

        
        
        // // Passage de la variable AJAX pour que le JS sache où envoyer les requêtes
        // wp_localize_script( 
        //     'ispag-contact-detail-edit-js', 
        //     'ispag_ajax', 
        //     array( 
        //         'ajax_url' => admin_url( 'admin-ajax.php' ),
        //         // L'action 'ispag_nonce' DOIT correspondre à l'action utilisée dans check_ajax_referer()
        //         'nonce'    => wp_create_nonce( 'ispag_crm_nonce' ) 
        //     )
        // );
    }

    /**
     * Effectue la récupération et l'enrichissement des métadonnées pour un contact donné.
     * C'est une fonction utilitaire interne pour éviter la duplication de code.
     *
     * @param object $contact L'objet contact brut (résultat de la requête wpdb).
     * @return object L'objet contact enrichi.
     */
    private function _enrich_contact_with_meta( $contact ) {
        global $wpdb, $current_user_department;
        
        // 1. Renommage et nettoyage de base
        $contact->email = $contact->user_email;
        unset($contact->user_email);

        // On définit une valeur par défaut si la globale est vide par sécurité
        $dept_filter = !empty($current_user_department) ? $current_user_department : 'vaulruz_ispag';

        // 2. Récupération des métadonnées CRM classiques (SAUF l'Owner)
        $meta_keys = [
            ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION      => 'lead_function',
            ISPAG_Crm_Contact_Constants::META_LEAD_STATUS        => 'lead_status',
            ISPAG_Crm_Contact_Constants::META_LEAD_LINKEDIN_PAGE => 'linkedin_page',
            ISPAG_Crm_Contact_Constants::META_LIFECYCLE_PHASE    => 'lifecycle_phase',
            ISPAG_Crm_Contact_Constants::META_COMPANY_ID         => 'ispag_company_id',
            // ISPAG_Crm_Contact_Constants::META_OWNER est retiré d'ici car on utilise la table SQL
            ISPAG_Crm_Contact_Constants::META_LAST_CONTACT_DATE   => 'last_contact_date',
            ISPAG_Crm_Contact_Constants::META_LAST_CONTACT_SOURCE => 'last_contact_source',
            ISPAG_Crm_Contact_Constants::META_HEALTH_CHECK_IGNORE => 'ispag_ignore_health_check',
            ISPAG_Crm_Contact_Constants::META_LEAD_PHONE          => 'phone',
            ISPAG_Crm_Contact_Constants::USER_AVATAR              => 'avatar_id',
            'first_name' => 'first_name',
            'last_name'  => 'last_name',
        ];

        foreach ( $meta_keys as $meta_key => $property_name ) {
            $contact->$property_name = get_user_meta( $contact->ID, $meta_key, true );
        }

        // ------------------------------------------------------------------
        // NOUVEAU : RÉCUPÉRATION DE L'OWNER DEPUIS LA NOUVELLE TABLE
        // ------------------------------------------------------------------
        $table_owners = ISPAG_Crm_Contact_Constants::TABLE_CONTACT_OWNER; // ou $wpdb->prefix . 'ispag_contacts_owners'
        
        $owner_data = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id, department_key 
            FROM {$table_owners}  
            WHERE contact_id = %d 
            AND department_key = %s 
            AND status = 'active' 
            ORDER BY assigned_at DESC LIMIT 1",
            $contact->ID,
            $dept_filter
        ));

        if ( $owner_data ) {
            $contact->crm_owner_id = $owner_data->user_id;
            $contact->owner_department = $owner_data->department_key;
            // Optionnel : Récupérer le nom pour l'affichage direct
            $contact->owner_display_name = get_the_author_meta( 'display_name', $owner_data->user_id );
        } else {
            $contact->crm_owner_id = null;
            $contact->owner_department = null;
            $contact->owner_display_name = __('Not assigned', 'ispag-crm');
        }
        // ------------------------------------------------------------------
        // NOUVEAU : TRAITEMENT MULTI-ENTREPRISES
        // ------------------------------------------------------------------
        $contact->companies = array(); // Initialisation d'un tableau d'objets entreprises

        if ( ! empty( $contact->ispag_company_id ) ) {
            
            // 1. On transforme la chaîne "12,45" en tableau [12, 45]
            $company_ids = explode( ',', $contact->ispag_company_id );
            $company_ids = array_filter( array_map( 'absint', $company_ids ) );

            if ( ! empty( $company_ids ) ) {
                $table_companies = ISPAG_Crm_Company_Constants::TABLE_NAME;
                
                // 2. On récupère les infos de toutes les entreprises liées en une seule requête
                $ids_placeholder = implode( ',', array_fill( 0, count( $company_ids ), '%d' ) );
                $companies_data = $wpdb->get_results( $wpdb->prepare(
                    "SELECT viag_id, company_name FROM {$table_companies} WHERE viag_id IN ($ids_placeholder)",
                    ...$company_ids
                ) );

                if ( $companies_data ) {
                    $contact->companies = $companies_data;
                    // Pour la compatibilité avec vos anciens scripts qui attendent un seul ID :
                    $contact->ispag_company_id = $companies_data[0]->viag_id; 
                    $contact->company_name     = $companies_data[0]->company_name;
                }
                // error_log('LISTE DES ENTREPRISES : ' . print_r($companies_data));
            }
        }
        
        // Nettoyage de la propriété brute
        unset($contact->ispag_company_ids_raw);
        
        // ------------------------------------------------------------------
        // NOUVEAU BLOC : ENRICHISSEMENT DES BADGES LIFECYCLE ET LEAD_STATUS
        // ------------------------------------------------------------------
        
        // 1. Récupérer les maps complètes (label, couleur, etc.)
        $lead_status_map = $this->get_lead_statuses_map();
        $lifecycle_map   = $this->get_lifecycle_phases_for_display();
        
        // --- Enrichissement de lead_status ---
        $status_key = $contact->lead_status;
        if ( ! empty( $status_key ) && isset( $lead_status_map[ $status_key ] ) ) {
            $status_data = $lead_status_map[ $status_key ];
            $contact->lead_status_badge = $this->generate_badge_html( 
                $status_data->label, 
                $status_data->bg_color, 
                $status_data->text_color 
            );
            $contact->lead_status_label = $status_data->label;
            $contact->lead_status_description = $status_data->lead_status_description;
        } else {
            $contact->lead_status_badge = '<span class="ispag-badge default">' . esc_html__('N/A', 'ispag-crm') . '</span>';
            $contact->lead_status_label = esc_html__('N/A', 'ispag-crm');
        }
        
        // --- Enrichissement de lifecycle_phase ---
        $phase_key = $contact->lifecycle_phase;
        if ( ! empty( $phase_key ) && isset( $lifecycle_map[ $phase_key ] ) ) {
            $phase_data = $lifecycle_map[ $phase_key ];
            $contact->lifecycle_phase_badge = $this->generate_badge_html( 
                $phase_data->phase_label, 
                $phase_data->bg_color, 
                $phase_data->text_color 
            );
            $contact->lifecycle_phase_label = $phase_data->phase_label;
            $contact->lifecycle_status_description = $phase_data->phase_description;
        } else {
            $contact->lifecycle_phase_badge = '<span class="ispag-badge default">' . esc_html__('N/A', 'ispag-crm') . '</span>';
            $contact->lifecycle_phase_label = esc_html__('N/A', 'ispag-crm');
        }

        
        
        // ------------------------------------------------------------------

        // --- NOUVEAU : AJOUT DE LA DATE D'ANNIVERSAIRE ---
        $birthday = get_user_meta( $contact->ID, ISPAG_Crm_Contact_Constants::USER_BIRTHDAY, true );
        if ( $birthday ) {
            $contact->birthday = date_i18n( get_option( 'date_format' ), strtotime( $birthday ) );
            
            // Calcul de l'âge pour aider l'IA dans son résumé
            $date = new DateTime($birthday);
            $now  = new DateTime();
            $interval = $now->diff($date);
            $contact->age = $interval->y . ' ans';
        } else {
            $contact->birthday = '-';
            $contact->age      = null;
        }
        // ------------------------------------------------
        $linkedin_url = get_user_meta( $contact->ID, ISPAG_Crm_Contact_Constants::META_LEAD_LINKEDIN_PAGE, true );
        if ( $linkedin_url ) {
            $contact->linkedin_url = $linkedin_url;
        }
        else{
            $contact->linkedin_url = null;
        }

        if (class_exists( 'ISPAG_Note_Manager' ) && !empty($contact->ID)) {
            $note_manager = new ISPAG_Note_Manager();
            $last_activity = $note_manager->get_last_contact('contact', $contact->ID);
            
            // ON VÉRIFIE SI UNE ACTIVITÉ A ÉTÉ TROUVÉE
            if ( $last_activity && isset($last_activity->created_at) ) {
                
                $contact->last_contact_date = $last_activity->created_at; 
                $meta_key = ISPAG_Crm_Contact_Constants::META_LAST_CONTACT_DATE; 
                
                update_user_meta( $contact->ID, $meta_key, $contact->last_contact_date );
                
            } else {
                // Optionnel : Tu peux décider de vider la date ou de ne rien faire
                $contact->last_contact_date = null;
            }
        }


        // $last_contact_meta_key = ISPAG_Crm_Contact_Constants::META_LAST_CONTACT_DATE; 

        // 2. Récupérer la valeur de la meta pour l'utilisateur
        // $last_contact_date = get_user_meta( $contact->ID, $last_contact_meta_key, true );

        // 3. Ajouter la propriété à l'objet Contact
        // $contact->last_contact_date = $last_contact_date;
        
        // Traitement spécial pour le rôle de l'utilisateur (inchangé)
        $user_data = get_userdata( $contact->ID );
        // ... (suite du code pour 'role_label' et 'last_contact_date') ...
        if ( $user_data && ! empty( $user_data->roles ) ) {
            $contact->role_label = array_shift( $user_data->roles );
        } else {
            $contact->role_label = 'N/A';
        }
        
        $current_user_id = get_current_user_id();

        if ( $current_user_id > 0 && ! empty( $contact->ID ) ) {
            // On utilise l'entity_type 'contact' suite à notre migration Option 2
            $priority = $wpdb->get_var( $wpdb->prepare(
                "SELECT priority_level 
                FROM {$this->table_priorities} 
                WHERE entity_id = %d 
                AND entity_type = 'contact' 
                AND user_id = %d 
                LIMIT 1",
                $contact->ID,
                $current_user_id
            ));

            // On affecte la priorité (sera NULL si aucune n'est définie)
            $contact->priority_level = $priority ? $priority : '';
        } else {
            $contact->priority_level = '';
        }

        // --- Enrichissement de priority Badge ---
        $priority = strtoupper($contact->priority_level);
                            
        $badge_configs = [
            'A' => ['color' => '#d63031', 'label' => 'A - ' . __( 'High', 'ispag-crm' )],
            'B' => ['color' => '#e67e22', 'label' => 'B - ' . __( 'Medium', 'ispag-crm' )],
            'C' => ['color' => '#2980b9', 'label' => 'C - ' . __( 'Low', 'ispag-crm' )],
        ];

        if ( isset($badge_configs[$priority]) ) : 
            $config = $badge_configs[$priority];
            
            $contact->priority_level_badge = '<span class="ispag-status-badge" style="background-color: ' . $config['color'] . '; color: #fff;">
                ' . $config['label'] . '
            </span>';
        else :
            $contact->priority_level_badge = '<span class="ispag-status-badge" style="background-color: #f0f0f0; color: #999; border: 1px dashed #ccc; font-weight: normal;">
                ' . __('None', 'ispag-crm') . '
            </span>';
        endif;

        $contact->avatar_url = $this->get_optimized_avatar_url($contact);
        
        return $contact;
    }

    /**
     * Récupère tous les contacts (utilisateurs) associés à un ID de compagnie spécifique
     * et les enrichit avec leurs métadonnées.
     *
     * @param int $company_id L'ID de la compagnie (valeur stockée dans la user_meta).
     * @return array Tableau d'objets contact enrichis.
     */
    public function get_users_by_company( $company_id ) {
        $company_id = absint( $company_id );
        
        // LOG 1 : L'ID que l'on cherche
        // error_log("[ISPAG DEBUG] get_users_by_company - Recherche pour ID : " . $company_id);

        if ( $company_id <= 0 ) {
            return [];
        }

        $args = array(
            'fields' => 'ID',
            'number' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'     => ISPAG_Crm_Contact_Constants::META_COMPANY_ID,
                    // Note : On utilise LIKE %id% donc on passe juste la valeur
                    'value'   => $company_id, 
                    'compare' => 'LIKE', 
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => ISPAG_Crm_Contact_Constants::ACCOUNT_STATUS,
                        'value'   => 'disabled',
                        'compare' => '!=',
                    ),
                    array(
                        'key'     => ISPAG_Crm_Contact_Constants::ACCOUNT_STATUS,
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            ),
        );

        $user_query = new WP_User_Query( $args );
        $contact_ids = $user_query->get_results();
        
        // LOG 2 : Les IDs trouvés en base avant enrichissement
        // error_log("[ISPAG DEBUG] get_users_by_company - IDs trouvés : " . print_r($contact_ids, true));

        if ( empty( $contact_ids ) ) {
            return [];
        }

        $enriched_contacts = $this->get_contacts_by_ids( $contact_ids );
        
        // LOG 3 : Vérification finale
        // error_log("[ISPAG DEBUG] get_users_by_company - Nombre de contacts enrichis retournés : " . count($enriched_contacts));

        return $enriched_contacts;
    }


    /**
     * Récupère un contact (utilisateur) par son ID et l'enrichit avec les métadonnées.
     *
     * @param int $contact_id L'ID de l'utilisateur WordPress à récupérer.
     * @return object|null L'objet contact enrichi s'il est trouvé, null sinon.
     */
    public function get_contact_by_id( $contact_id ) {
        global $wpdb, $current_user_department;

        $contact_id = absint( $contact_id );
        if ( $contact_id === 0 ) return null;

        $dept_filter     = !empty( $current_user_department ) ? $current_user_department : 'vaulruz_ispag';
        $current_user_id = get_current_user_id();

        $table_owners    = ISPAG_Crm_Contact_Constants::TABLE_CONTACT_OWNER;
        $table_note      = ISPAG_Note_Manager::TABLE_NOTE;
        $lifecycle_table = ISPAG_Crm_Contact_Constants::LIFECYCLE_TABLE_NAME;
        $status_table    = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME;

        // ── Une seule requête avec toutes les jointures ───────────────────────
        $sql = $wpdb->prepare(
            "SELECT
                u.ID,
                u.user_email,
                u.display_name,

                -- Metas classiques
                m_comp.meta_value   AS ispag_company_id,
                m_func.meta_value   AS lead_function,
                m_life.meta_value   AS lifecycle_phase,
                m_status.meta_value AS lead_status,
                m_ava.meta_value    AS avatar_id,
                m_phone.meta_value  AS phone,
                m_linkedin.meta_value AS linkedin_page,
                m_fn.meta_value     AS first_name,
                m_ln.meta_value     AS last_name,

                -- Descriptions depuis les tables de référence
                m_lifedesc.phase_description  AS lifecycle_description,
                m_lifedesc.phase_label        AS lifecycle_phase_label,
                m_lifedesc.bg_color           AS lifecycle_bg_color,
                m_lifedesc.text_color         AS lifecycle_text_color,
                m_statusdesc.status_description AS status_description,
                m_statusdesc.status_label     AS lead_status_label,
                m_statusdesc.bg_color         AS status_bg_color,
                m_statusdesc.text_color       AS status_text_color,

                -- Owner
                m_owner.user_id AS crm_owner_id,
                m_owner.department_key AS owner_department,

                -- Priorité
                m_prio.priority_level,

                -- Entreprise
                c.company_name,
                c.favicon AS company_favicon,

                -- Dernière activité
                (
                    SELECT MAX(created_at)
                    FROM {$table_note}
                    WHERE FIND_IN_SET(u.ID, contact_id) > 0
                    AND type IN ('EMAIL','CALL','MEETING',
                                'EMAIL_TRANSACTIONAL','CHRISTMAS_PRESENT',
                                'WHATSAPP','SMS','LINKEDIN')
                ) AS last_contact_date

            FROM {$wpdb->users} u

            LEFT JOIN {$wpdb->usermeta} m_comp    ON (u.ID = m_comp.user_id    AND m_comp.meta_key    = %s)
            LEFT JOIN {$wpdb->usermeta} m_func    ON (u.ID = m_func.user_id    AND m_func.meta_key    = %s)
            LEFT JOIN {$wpdb->usermeta} m_life    ON (u.ID = m_life.user_id    AND m_life.meta_key    = %s)
            LEFT JOIN {$wpdb->usermeta} m_status  ON (u.ID = m_status.user_id  AND m_status.meta_key  = %s)
            LEFT JOIN {$wpdb->usermeta} m_ava     ON (u.ID = m_ava.user_id     AND m_ava.meta_key     = %s)
            LEFT JOIN {$wpdb->usermeta} m_phone   ON (u.ID = m_phone.user_id   AND m_phone.meta_key   = %s)
            LEFT JOIN {$wpdb->usermeta} m_linkedin ON (u.ID = m_linkedin.user_id AND m_linkedin.meta_key = %s)
            LEFT JOIN {$wpdb->usermeta} m_fn      ON (u.ID = m_fn.user_id      AND m_fn.meta_key      = 'first_name')
            LEFT JOIN {$wpdb->usermeta} m_ln      ON (u.ID = m_ln.user_id      AND m_ln.meta_key      = 'last_name')

            LEFT JOIN {$lifecycle_table} m_lifedesc
                ON m_lifedesc.phase_key COLLATE utf8mb4_unicode_520_ci = m_life.meta_value

            LEFT JOIN {$status_table} m_statusdesc
                ON m_statusdesc.status_key COLLATE utf8mb4_unicode_520_ci = m_status.meta_value

            LEFT JOIN {$table_owners} m_owner
                ON  m_owner.contact_id     = u.ID
                AND m_owner.status         = 'active'
                AND m_owner.department_key = %s

            LEFT JOIN {$this->table_priorities} m_prio
                ON  m_prio.entity_id   = u.ID
                AND m_prio.entity_type = 'contact'
                AND m_prio.user_id     = %d

            LEFT JOIN {$this->table_companies} c
                ON c.viag_id = m_comp.meta_value

            WHERE u.ID = %d",

            // Paramètres des meta_key
            ISPAG_Crm_Contact_Constants::META_COMPANY_ID,
            ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION,
            ISPAG_Crm_Contact_Constants::META_LIFECYCLE_PHASE,
            ISPAG_Crm_Contact_Constants::META_LEAD_STATUS,
            ISPAG_Crm_Contact_Constants::USER_AVATAR,
            ISPAG_Crm_Contact_Constants::META_LEAD_PHONE,
            ISPAG_Crm_Contact_Constants::META_LEAD_LINKEDIN_PAGE,
            // Paramètres des jointures
            $dept_filter,
            $current_user_id,
            // WHERE
            $contact_id
        );

        $contact = $wpdb->get_row( $sql );
        if ( ! $contact ) return null;

        // ── Normalisation des champs ──────────────────────────────────────────
        $contact->email      = $contact->user_email;
        $contact->linkedin_url = $contact->linkedin_page ?: null;

        // Owner display name (une seule requête WP, pas de boucle)
        $contact->owner_display_name = $contact->crm_owner_id
            ? get_the_author_meta( 'display_name', $contact->crm_owner_id )
            : __( 'Not assigned', 'ispag-crm' );

        // ── Badges lead_status ────────────────────────────────────────────────
        if ( ! empty( $contact->lead_status ) && ! empty( $contact->lead_status_label ) ) {
            $contact->lead_status_badge = $this->generate_badge_html(
                $contact->lead_status_label,
                $contact->status_bg_color,
                $contact->status_text_color
            );
            $contact->lead_status_description = $contact->status_description;
        } else {
            $contact->lead_status_badge       = '<span class="ispag-badge default">' . esc_html__( 'N/A', 'ispag-crm' ) . '</span>';
            $contact->lead_status_label       = esc_html__( 'N/A', 'ispag-crm' );
            $contact->lead_status_description = '';
        }

        // ── Badges lifecycle_phase ────────────────────────────────────────────
        if ( ! empty( $contact->lifecycle_phase ) && ! empty( $contact->lifecycle_phase_label ) ) {
            $contact->lifecycle_phase_badge = $this->generate_badge_html(
                $contact->lifecycle_phase_label,
                $contact->lifecycle_bg_color,
                $contact->lifecycle_text_color
            );
            $contact->lifecycle_status_description = $contact->lifecycle_description;
        } else {
            $contact->lifecycle_phase_badge        = '<span class="ispag-badge default">' . esc_html__( 'N/A', 'ispag-crm' ) . '</span>';
            $contact->lifecycle_phase_label        = esc_html__( 'N/A', 'ispag-crm' );
            $contact->lifecycle_status_description = '';
        }

        // ── Badge priorité ────────────────────────────────────────────────────
        $priority      = strtoupper( $contact->priority_level ?? '' );
        $badge_configs = [
            'A' => ['color' => '#d63031', 'label' => 'A - ' . __( 'High',   'ispag-crm' )],
            'B' => ['color' => '#e67e22', 'label' => 'B - ' . __( 'Medium', 'ispag-crm' )],
            'C' => ['color' => '#2980b9', 'label' => 'C - ' . __( 'Low',    'ispag-crm' )],
        ];

        $contact->priority_level_badge = isset( $badge_configs[$priority] )
            ? '<span class="ispag-status-badge" style="background-color:' . $badge_configs[$priority]['color'] . ';color:#fff">' . $badge_configs[$priority]['label'] . '</span>'
            : '<span class="ispag-status-badge" style="background-color:#f0f0f0;color:#999;border:1px dashed #ccc;font-weight:normal">' . __( 'None', 'ispag-crm' ) . '</span>';

        // ── Multi-entreprises ─────────────────────────────────────────────────
        $contact->companies = [];
        if ( ! empty( $contact->ispag_company_id ) ) {
            $company_ids = array_filter( array_map( 'absint', explode( ',', $contact->ispag_company_id ) ) );
            if ( $company_ids ) {
                $placeholders   = implode( ',', array_fill( 0, count( $company_ids ), '%d' ) );
                $contact->companies = $wpdb->get_results( $wpdb->prepare(
                    "SELECT viag_id, company_name FROM {$this->table_companies} WHERE viag_id IN ($placeholders)",
                    ...$company_ids
                ) ) ?: [];
            }
        }

        // ── Anniversaire ──────────────────────────────────────────────────────
        $birthday = get_user_meta( $contact->ID, ISPAG_Crm_Contact_Constants::USER_BIRTHDAY, true );
        if ( $birthday ) {
            $contact->birthday = date_i18n( get_option( 'date_format' ), strtotime( $birthday ) );
            $interval          = ( new DateTime() )->diff( new DateTime( $birthday ) );
            $contact->age      = $interval->y . ' ans';
        } else {
            $contact->birthday = '-';
            $contact->age      = null;
        }

        // ── Rôle WordPress ───────────────────────────────────────────────────
        $user_data           = get_userdata( $contact->ID );
        $contact->role_label = ( $user_data && ! empty( $user_data->roles ) )
            ? array_shift( $user_data->roles )
            : 'N/A';

        // ── Avatar ───────────────────────────────────────────────────────────
        $contact->avatar_url = $this->get_optimized_avatar_url( $contact );

        return $contact;
    }
    // public function get_contact_by_id( $contact_id ) {
    //     global $wpdb;
        
    //     $contact_id = absint( $contact_id );
    //     $table_owners = ISPAG_Crm_Contact_Constants::TABLE_CONTACT_OWNER;

    //     if ( $contact_id === 0 ) {
    //         return null;
    //     }

    //     // FILTRE PAR RESPONSABLE (Owner)
    //     if ( ! empty( $args['owner_id'] ) ) {
    //         $where_clauses[] = "EXISTS (
    //             SELECT 1 FROM {$table_owners} ow 
    //             WHERE CAST(ow.contact_id AS CHAR) = CAST(u.ID AS CHAR) COLLATE utf8mb4_unicode_ci
    //             AND ow.user_id = %d 
    //             AND ow.status = 'active'
    //         )";
    //         $sql_args_where[] = absint( $args['owner_id'] );
    //     }
    //     // Filtre ID
    //     if ( ! empty( $contact_id ) ) {
    //         $where_clauses[] = "u.ID = %d";
    //         $sql_args_where[] = $contact_id;
    //     }

    //     // Sélection des champs de base requis : ID, user_email, display_name
    //     $where_sql = ' WHERE ' . implode( ' AND ', $where_clauses );
    //     $query = $wpdb->prepare( 
    //         "SELECT u.ID, u.user_email, u.display_name FROM {$this->table_name} u {$where_sql}", 
    //         $contact_id 
    //     );

    //     $contact = $wpdb->get_row( $query );

    //     if ( $contact ) {
    //         // Enrichir avec toutes les métadonnées
    //         return $this->_enrich_contact_with_meta( $contact );
    //     }

    //     return null;
    // }

    /**
     * Récupère une liste de contacts/utilisateurs basés sur un tableau d'IDs et les enrichit.
     *
     * @param array $contact_ids Tableau des IDs de contact (utilisateurs).
     * @return array Tableau d'objets contact enrichis.
     */
    public function get_contacts_by_ids( array $contact_ids ) {
        global $wpdb; 

        if ( empty( $contact_ids ) ) {
            return [];
        }

        $safe_ids = array_map( 'absint', $contact_ids );
        $safe_ids = array_filter( $safe_ids );

        if ( empty( $safe_ids ) ) {
            return [];
        }

        // 1. Création de la requête SQL pour la table wp_users
        $id_placeholders = implode( ',', array_fill( 0, count( $safe_ids ), '%d' ) );

        // Sélection des champs de base requis par le template : ID, user_email, display_name
        $query = "SELECT ID, user_email, display_name FROM {$this->table_name} WHERE ID IN ({$id_placeholders})";
        
        // Exécution de la requête avec la liste des IDs
        $results = $wpdb->get_results( 
            $wpdb->prepare( $query, ...$safe_ids ) 
        );

        if ( empty( $results ) ) {
            return [];
        }

        // 2. Hydratation (Enrichissement) des résultats avec les métadonnées
        foreach ($results as $key => $contact) {
            $results[$key] = $this->_enrich_contact_with_meta( $contact );
        }
        
        return $results;
    }

    /**
     * Récupère un contact par son email (via la table des utilisateurs WP)
     */
    public function get_by_email($email) {
        global $wpdb;
        // On cherche dans la table wp_users
        $user = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ID as id, user_email as email, display_name FROM {$wpdb->users} WHERE user_email = %s LIMIT 1",
                $email
            )
        );

        if ($user) {
            // On essaie de récupérer le prénom/nom en meta si disponible
            $first_name = get_user_meta($user->id, 'first_name', true);
            $last_name  = get_user_meta($user->id, 'last_name', true);
            $user->first_name = $first_name ?: $user->display_name;
            $user->last_name  = $last_name ?: '';
        }

        return $user;
    }

    /**
     * Récupère la liste des contacts avec Optimisation SQL (Favicon + Avatar ID)
     */
    public function get_contacts_list_optimized( $args ) {
        global $wpdb, $current_user_department; // Appel de la globale

        $lifecycle_table = ISPAG_Crm_Contact_Constants::LIFECYCLE_TABLE_NAME;
        $status_table = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME;

        $limit  = absint( $args['limit'] );
        $offset = absint( $args['offset'] );
        $current_user_id = get_current_user_id();
        $table_note = $wpdb->prefix . ISPAG_Note_Repository::TABLE_NOTES;
        
        // Nouvelle table des owners
        $table_owners = "wor9711_ispag_contacts_owners"; 
        $dept_filter = !empty($current_user_department) ? $current_user_department : 'vaulruz_ispag';

        $sql_args = [];
        $where = ["1=1"];

        // 1. Filtre ACCOUNT_STATUS
        $where[] = "(m_status_acc.meta_value IS NULL OR m_status_acc.meta_value != 'disabled')";

        // 2. Recherche
        if ( ! empty( $args['search'] ) ) {
            $s = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = "(u.display_name LIKE %s OR u.user_email LIKE %s)";
            $sql_args[] = $s; 
            $sql_args[] = $s;
        }

        // 3. Filtre Company
        if ( ! empty( $args['filter_company'] ) ) {
            $where[] = "m_comp.meta_value = %s";
            $sql_args[] = $args['filter_company'];
        }

        // 4. Filtre Owner (Modifié pour pointer sur la nouvelle table m_owner)
        if ( ! empty( $args['filter_owner'] ) ) {
            $where[] = "m_owner.user_id = %d"; // C'est maintenant un ID numérique dans ta table
            $sql_args[] = $args['filter_owner'];
        }

        $where_sql = "WHERE " . implode( ' AND ', $where );
        
        $query_args = $sql_args; 
        $query_args[] = $limit;
        $query_args[] = $offset;

        $order = ( strtoupper( $args['order'] ) === 'DESC' ) ? 'DESC' : 'ASC';

        // Mapping des colonnes pour le tri
        $sort_mapping = [
            'ID'                            => 'u.ID',
            'display_name'                  => 'u.display_name',
            'user_email'                    => 'u.user_email',
            'priority_level'                => 'm_prio.priority_level', // Table priorities
            'ispag_lead_status'             => 'm_status.meta_value',   // Table usermeta (m_status)
            'ispag_contact_lifecycle_phase' => 'm_life.meta_value',     // Table usermeta (m_life)
            'last_contact_date'             => 'last_contact_date',     // Alias calculé dans le SELECT
            'ispag_lead_function'           => 'm_func.meta_value',
            'ispag_company_id'              => 'm_comp.meta_value',
            'ispag_owner'                   => 'm_owner.user_id',
        ];

        // Si la colonne demandée est dans le mapping, on l'utilise, sinon défaut sur display_name
        $sort_column = isset($sort_mapping[$args['orderby']]) ? $sort_mapping[$args['orderby']] : "u.display_name";


        $query = "
            SELECT DISTINCT
                u.ID, u.user_email, u.display_name,
                m_comp.meta_value as " . ISPAG_Crm_Contact_Constants::META_COMPANY_ID . ",
                m_func.meta_value as " . ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION . ",
                m_life.meta_value as " . ISPAG_Crm_Contact_Constants::META_LIFECYCLE_PHASE . ",
                m_lifedesc.phase_description as lifecycle_description,
                m_status.meta_value as " . ISPAG_Crm_Contact_Constants::META_LEAD_STATUS . ",
                m_statusdesc.status_description as status_description,
                m_owner.user_id as " . ISPAG_Crm_Contact_Constants::META_OWNER . ", -- Récupéré de la table owners
                m_prio.priority_level as " . ISPAG_Crm_Contact_Constants::PRIORITY_LEVEL . ",
                m_ava.meta_value as avatar_id,
                c.favicon as company_favicon,
                (
                    SELECT MAX(created_at) 
                    FROM {$table_note} 
                    WHERE FIND_IN_SET(u.ID, contact_id) > 0
                    AND type IN ('EMAIL','CALL','MEETING','EMAIL_TRANSACTIONAL','CHRISTMAS_PRESENT','WHATSAPP','SMS','LINKEDIN')
                ) as last_contact_date
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} m_comp ON (u.ID = m_comp.user_id AND m_comp.meta_key = '" . ISPAG_Crm_Contact_Constants::META_COMPANY_ID . "')
            LEFT JOIN {$wpdb->usermeta} m_func ON (u.ID = m_func.user_id AND m_func.meta_key = '" . ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION . "')
            LEFT JOIN {$wpdb->usermeta} m_status_acc ON (u.ID = m_status_acc.user_id AND m_status_acc.meta_key = '" . ISPAG_Crm_Contact_Constants::ACCOUNT_STATUS . "')
            LEFT JOIN {$wpdb->usermeta} m_life ON (u.ID = m_life.user_id AND m_life.meta_key = '" . ISPAG_Crm_Contact_Constants::META_LIFECYCLE_PHASE . "')
            LEFT JOIN {$lifecycle_table} m_lifedesc ON (m_lifedesc.phase_key COLLATE utf8mb4_unicode_520_ci = m_life.meta_value)
            LEFT JOIN {$wpdb->usermeta} m_status ON (u.ID = m_status.user_id AND m_status.meta_key = '" . ISPAG_Crm_Contact_Constants::META_LEAD_STATUS . "')
            LEFT JOIN {$status_table} m_statusdesc ON (m_statusdesc.status_key  COLLATE utf8mb4_unicode_520_ci = m_status.meta_value)

            -- JOINTURE SUR LA NOUVELLE TABLE OWNERS (Filtrée par département)
            LEFT JOIN {$table_owners} m_owner ON (
                u.ID = m_owner.contact_id 
                AND m_owner.status = 'active' 
                AND m_owner.department_key = '$dept_filter'
            )
            
            LEFT JOIN {$wpdb->usermeta} m_ava ON (u.ID = m_ava.user_id AND m_ava.meta_key = '" . ISPAG_Crm_Contact_Constants::USER_AVATAR . "')
            LEFT JOIN {$this->table_priorities} m_prio ON (u.ID = m_prio.entity_id AND m_prio.entity_type = 'contact' AND m_prio.user_id = $current_user_id)
            LEFT JOIN {$this->table_companies} c ON (m_comp.meta_value = c.Id)
            {$where_sql}
            ORDER BY {$sort_column} {$order}
            LIMIT %d OFFSET %d
        ";

        $contacts = $wpdb->get_results( $wpdb->prepare( $query, $query_args ) );

        foreach ($contacts as &$contact) {
            $contact->avatar_url = $this->get_optimized_avatar_url($contact);
        }

        // Correction du COUNT avec la nouvelle table owner
        $total_query = "
            SELECT COUNT(DISTINCT u.ID) 
            FROM {$wpdb->users} u 
            LEFT JOIN {$wpdb->usermeta} m_status_acc ON (u.ID = m_status_acc.user_id AND m_status_acc.meta_key = '" . ISPAG_Crm_Contact_Constants::ACCOUNT_STATUS . "')
            LEFT JOIN {$wpdb->usermeta} m_comp ON (u.ID = m_comp.user_id AND m_comp.meta_key = '" . ISPAG_Crm_Contact_Constants::META_COMPANY_ID . "') 
            LEFT JOIN {$table_owners} m_owner ON (
                u.ID = m_owner.contact_id 
                AND m_owner.status = 'active' 
                AND m_owner.department_key = '$dept_filter'
            )
            $where_sql";
            
        $total = $wpdb->get_var( $wpdb->prepare( $total_query, $sql_args ) );

        return ['contacts' => $contacts, 'total' => absint($total)];
    }

    


    /**
     * Pont public pour obtenir le badge de statut (utilisé dans la liste optimisée)
     */
    public function get_lead_status_badge( $status_key ) {
        $map = $this->get_lead_statuses_map();
        
        if ( ! empty( $status_key ) && isset( $map[ $status_key ] ) ) {
            $data = $map[ $status_key ];
            return $this->generate_badge_html( $data->label, $data->bg_color, $data->text_color );
        }

        return '<span class="ispag-badge ispag-badge-none">' . esc_html__('N/A', 'ispag-crm') . '</span>';
    }

    /**
     * Pont public pour obtenir le badge de phase (utilisé dans la liste optimisée)
     */
    public function get_lifecycle_phase_badge( $phase_key ) {
        $map = $this->get_lifecycle_phases_for_display();
        
        if ( ! empty( $phase_key ) && isset( $map[ $phase_key ] ) ) {
            $data = $map[ $phase_key ];
            return $this->generate_badge_html( $data->phase_label, $data->bg_color, $data->text_color );
        }

        return '<span class="ispag-badge ispag-badge-none">' . esc_html__('N/A', 'ispag-crm') . '</span>';
    }


    /**
     * Récupère uniquement les owners (utilisateurs) appartenant au département actuel.
     * * @return array Tableau [ID => Nom] pour le menu déroulant.
     */
    public function get_ispag_owners_options() {
        global $current_user_department;
        
        // Utilisation de la constante pour la clé meta
        $dept_key = ISPAG_Crm_Contact_Constants::USER_DEPARTMENT;

        $user_args = array(
            'role__in'   => array('administrator', 'vente_ispag', 'ispag_commercial'),
            'fields'     => array( 'ID', 'display_name' ),
            'orderby'    => 'display_name',
            'order'      => 'ASC'
        );

        // Filtrage strict par département via la globale
        if ( ! empty( $current_user_department ) ) {
            $user_args['meta_query'] = array(
                array(
                    'key'     => $dept_key,
                    'value'   => $current_user_department,
                    'compare' => '='
                )
            );
        }

        $owners = get_users( $user_args );

        $options = ['' => esc_html__('Not assigned', 'ispag-crm')]; 
        
        if ( ! empty( $owners ) ) {
            foreach ( $owners as $owner ) {
                $options[ (string) $owner->ID ] = $owner->display_name;
            }
        }
        
        return $options;
    }
    /**
     * Récupère les responsables (owners) formatés pour l'inline edit JS
     * Utilise la logique de rôles existante (admin, vente, commercial)
     * Format : ID:Nom;ID:Nom
     */
    public static function get_ispag_owners_for_inline_edit() {
        // On instancie la classe si nécessaire ou on appelle la méthode 
        // (Ajustez selon si votre méthode est statique ou non)
        $repo = new self();
        $owners_array = $repo->get_ispag_owners_options();

        $formatted = array();
        foreach ( $owners_array as $id => $display_name ) {
            // On évite d'ajouter l'option vide "Not assigned" dans le mapping 
            // car le JS ajoute déjà un "Sélectionner..." par défaut
            if ( $id === '' ) continue;

            $formatted[] = $id . ':' . $display_name;
        }

        return implode( ';', $formatted );
    }

    // -------------------------------------------------------------------------
    // --- METHODES D'AIDE (RÉCUPÉRATION DE DONNÉES EN BASE) ---
    // -------------------------------------------------------------------------

    /**
     * Récupère la map complète des statuts de lead (clés, labels, couleurs et ordre).
     * Les données sont récupérées de la table ISPAG_Crm_Deal_Constants::LEAD_STATUS_TABLE_NAME.
     * @return array Map (status_key => status_object)
     */
    private function get_lead_statuses_map() {
        global $wpdb;
        $return = array();
        
        $table_name = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME; 

        $full_statuses = $wpdb->get_results( 
            "SELECT status_key, status_label, bg_color, text_color, status_order, status_description 
            FROM {$table_name} 
            ORDER BY status_order ASC, status_label ASC" 
        );
        
        if ( ! empty( $full_statuses ) ) {
            foreach ( $full_statuses as $status ) {
                if ( ! empty( $status->status_key ) ) {
                    
                    $status_data = new stdClass();
                    $status_data->label = $status->status_label;
                    $status_data->bg_color = $status->bg_color;
                    $status_data->text_color = $status->text_color;
                    $status_data->order = $status->status_order;
                    $status_data->lead_status_description = $status->status_description;
                    
                    $return[ $status->status_key ] = $status_data;
                }
            }
        }

        return $return;
    }

    /**
     * Récupère la map des phases de cycle de vie (avec couleurs).
     * @return array Map (phase_key => phase_object)
     */
    private function get_lifecycle_phases_for_display() {
        global $wpdb;
        $return = array();
        $table_name = ISPAG_Crm_Contact_Constants::LIFECYCLE_TABLE_NAME;

        $full_phases = $wpdb->get_results( 
            "SELECT phase_key, phase_label, bg_color, text_color, phase_order, phase_description 
            FROM {$table_name} 
            ORDER BY phase_order ASC, phase_label ASC" 
        );
        
        if ( ! empty( $full_phases ) ) {
            foreach ( $full_phases as $phase ) {
                if ( ! empty( $phase->phase_key ) ) {
                    
                    $phase_data = new stdClass();
                    $phase_data->phase_label = $phase->phase_label;
                    $phase_data->bg_color = $phase->bg_color;
                    $phase_data->text_color = $phase->text_color;
                    $phase_data->phase_order = $phase->phase_order;
                    $phase_data->phase_description = $phase->phase_description;
                    
                    
                    $return[ $phase->phase_key ] = $phase_data;
                }
            }
        }

        return $return;
    }

    /**
     * Génère le HTML d'un badge (span) avec les couleurs définies.
     *
     * @param string $label Le texte à afficher dans le badge.
     * @param string $bg_color La couleur d'arrière-plan (ex: '#007bff').
     * @param string $text_color La couleur du texte (ex: '#ffffff').
     * @return string Code HTML du badge.
     */
    private function generate_badge_html( $label, $bg_color, $text_color ) {
        // S'assurer que les couleurs sont des valeurs sûres (hex, rgb, etc.) 
        // Bien que wp_kses_post puisse être utilisé si l'entrée est externe.
        $sanitized_bg = sanitize_hex_color( $bg_color );
        $sanitized_text = sanitize_hex_color( $text_color );

        if ( empty($sanitized_bg) ) {
            $sanitized_bg = '#f0f0f0'; // Couleur par défaut si absente
        }
        if ( empty($sanitized_text) ) {
            $sanitized_text = '#333333'; // Couleur par défaut si absente
        }

        $style = sprintf(
            'background-color: %s; color: %s;', 
            $sanitized_bg,
            $sanitized_text
        );

        $label = __($label, 'ispag-crm');
        // Utilisation de esc_html pour le label
        return sprintf(
            '<span class="ispag-status-badge" style="%s">%s</span>',
            esc_attr( $style ), // Échappe les attributs style
            esc_html($label) 
        );
    }


    public static function get_lead_status_for_inline_edit() {
        global $wpdb;
        $table_name = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME;

        // On récupère les statuts triés par l'ordre défini
        $results = $wpdb->get_results( "SELECT status_key, status_label FROM {$table_name} ORDER BY status_order ASC" );

        if ( empty( $results ) ) {
            return 'none:Aucun statut défini';
        }

        $formatted = array();
        foreach ( $results as $row ) {
            // On construit la paire clé:label
            $formatted[] = $row->status_key . ':' . $row->status_label;
        }

        // Retourne : "new:New;open:Open;in_progress:In progress;..."
        return implode( ';', $formatted );
    }

    /**
     * Récupère les phases du cycle de vie pour l'inline edit JS
     * Format : key:label;key:label
     */
    public static function get_lifecycle_phases_for_inline_edit() {
        global $wpdb;
        $table_name = ISPAG_Crm_Contact_Constants::LIFECYCLE_TABLE_NAME;

        // Récupération triée par phase_order
        $results = $wpdb->get_results( "SELECT phase_key, phase_label FROM {$table_name} ORDER BY phase_order ASC" );

        if ( empty( $results ) ) {
            return 'other:Other';
        }

        $formatted = array();
        foreach ( $results as $row ) {
            // Construction de la paire clé:label
            $formatted[] = $row->phase_key . ':' . $row->phase_label;
        }

        return implode( ';', $formatted );
    }

    /**
     * Prépare les données d'un contact pour l'envoi à l'IA.
     * Utilisable indépendamment de tout contexte AJAX.
     *
     * @param int $contact_id ID de l'utilisateur WordPress
     * @return array|null ['contact' => object, 'text' => string] ou null si introuvable
     * Modes disponibles :
     *   'summary'  → résumé général + profil + actions (comportement actuel)
     *   'meeting'  → préparation de réunion : objectifs, questions, points d'attention
     */
    public function prepare_contact_ai_data(int $contact_id, string $mode = 'summary'): ?array {
        $user_info = get_userdata($contact_id);
        if (!$user_info) return null;

        $all_metas = get_user_meta($contact_id);
        $meta = fn(string $key, string $default = 'N/A') => $all_metas[$key][0] ?? $default;

        $contact_function = $meta(ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION);
        $contact_phone    = $meta(ISPAG_Crm_Contact_Constants::META_LEAD_PHONE);
        $company_id       = $meta(ISPAG_Crm_Contact_Constants::META_COMPANY_ID);
        $linkedin_url     = $meta(ISPAG_Crm_Contact_Constants::META_LEAD_LINKEDIN_PAGE, '');
        $lifecycle        = $meta(ISPAG_Crm_Contact_Constants::META_LIFECYCLE_PHASE, 'Unknown');
        $buying_goal      = $meta(ISPAG_Crm_Contact_Constants::META_BUYING_GOAL, 'Standard supply');
        $priority         = $meta(ISPAG_Crm_Contact_Constants::PRIORITY_LEVEL, 'Normal');
        $wordpress_role   = $user_info->roles[0] ?? 'N/A';

        $company_name = 'N/A';
        if (!empty($company_id) && $company_id !== 'N/A') {
            $company = (new ISPAG_Crm_Company_Repository())->get_company_by_viag_id($company_id);
            $company_name = $company->company_name ?? 'N/A';
        }

        // ── Bloc de base commun aux deux modes ───────────────────────────────
        $lines = [
            "CONTACT INFO:",
            "- Name: {$user_info->display_name}",
            "- Function: {$contact_function}",
            "- Wordpress role: {$wordpress_role}",
            "- Company: {$company_name}",
            "- Phone: {$contact_phone}",
            "- Lifecycle phase: {$lifecycle}",
            "- Buying Goal: {$buying_goal}",
            "- Priority: {$priority}",
        ];

        if (!empty($linkedin_url)) {
            $lines[] = "- LinkedIn url: {$linkedin_url}";
            $lines[] = "- Social Profile: LinkedIn active {$linkedin_url}";
        }

        // ── Projets / Deals ───────────────────────────────────────────────────
        $lines[] = "\nPROJECTS / DEALS:";
        $deals = [];
        if (class_exists('ISPAG_Crm_Deals_Repository')) {
            $deals = (new ISPAG_Crm_Deals_Repository())->get_projects_by_contact($contact_id);
            if (!empty($deals)) {
                foreach ($deals as $deal) {
                    $rejection = ($deal->stage_label === 'closed_lost' && !empty($deal->reason_for_rejection))
                        ? ' | Rejected: ' . $deal->reason_for_rejection
                        : '';
                    $lines[] = "- {$deal->project_name} | Stage: {$deal->stage_label} | Amount: {$deal->total_excl_vat} CHF | Close: {$deal->closing_date}{$rejection}";
                }
            } else {
                $lines[] = "No projects.";
            }
        }

        // ── Activités récentes ────────────────────────────────────────────────
        $lines[] = "\nRECENT ACTIVITY:";
        $activities = [];
        if (class_exists('ISPAG_Note_Repository')) {
            $activities = (new ISPAG_Note_Repository())->get_activities_for_entity('contact', $contact_id);
            if (!empty($activities)) {
                foreach (array_slice($activities, 0, 10) as $act) {
                    $lines[] = "- [{$act->created_at}] {$act->type}: " . wp_strip_all_tags($act->content);
                }
            }
        }

        // ── Instruction spécifique au mode ───────────────────────────────────
        if ($mode === 'meeting') {
            $open_deals = array_filter($deals, fn($d) => $d->stage_label !== 'closed_lost' && $d->stage_label !== 'closed_won');
            $open_count = count($open_deals);

            $lines[] = "\nMEETING PREPARATION CONTEXT:";
            $lines[] = "- Goal: Prepare a professional meeting with this contact.";
            $lines[] = "- Open deals in progress: {$open_count}";
            $lines[] = "- Please provide:";
            $lines[] = "  1. OBJECTIVES: What are the 2-3 key objectives for this meeting based on the contact profile and deal history?";
            $lines[] = "  2. KEY QUESTIONS: List 5 targeted questions to ask during the meeting.";
            $lines[] = "  3. ATTENTION POINTS: What are the risks or sensitivities to be aware of (objections, relationship history, lost deals)?";
            $lines[] = "  4. SUGGESTED AGENDA: Propose a short meeting agenda (15-30 min).";
            $lines[] = "  5. OPENING HOOK: Suggest an impactful opening sentence to start the meeting.";
        }

        return [
            'contact'          => $user_info,
            'contact_function' => $contact_function,
            'mode'             => $mode,
            'text'             => implode("\n", $lines),
        ];
    }

    /**
     * AJAX — Résumé général (comportement existant, inchangé)
     */
    public function ajax_load_contact_ai_summary(): void {
        $this->_handle_contact_ai_ajax('summary');
    }

    /**
     * AJAX — Préparation de réunion
     */
    public function ajax_load_contact_meeting_prep(): void {
        $this->_handle_contact_ai_ajax('meeting');
    }

    /**
     * Orchestrateur AJAX commun aux deux modes.
     */
    private function _handle_contact_ai_ajax(string $mode): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Access denied.', 'ispag-crm')]);
        }

        $contact_id = (int) filter_input(INPUT_POST, 'contact_id', FILTER_SANITIZE_NUMBER_INT);
        if (!$contact_id) {
            wp_send_json_error(['message' => __('Missing contact ID.', 'ispag-crm')]);
        }

        $prepared = $this->prepare_contact_ai_data($contact_id, $mode);
        if (!$prepared) {
            wp_send_json_error(['message' => __('User not found.', 'ispag-crm')]);
        }

        $ai_response = apply_filters(
            'ispag_send_to_crm_mistral',
            null,
            $prepared['contact']->display_name,
            $prepared['contact_function'],
            $prepared['text'],
            $mode   // ← le mode est transmis au filtre pour que Mistral adapte son prompt
        );

        if (ob_get_length()) ob_clean();

        if ($mode === 'meeting') {
            $this->_send_meeting_response($ai_response, $prepared['contact']->display_name);
        } else {
            $this->_send_summary_response($ai_response);
        }
    }

    /**
     * Formate et envoie la réponse JSON pour le mode summary.
     */
    private function _send_summary_response(?array $ai_response): void {
        $make_card = fn(string $icon, string $title, string $class, string $content) => sprintf(
            '<div class="ispag-card %s"><h5><span class="dashicons dashicons-%s"></span> %s</h5><div class="ai-content">%s</div></div>',
            $class, $icon, esc_html__($title, 'ispag-crm'), $content
        );

        wp_send_json_success([
            'html'                     => $make_card('visibility', 'AI Summary', 'ispag-ai-summary-card',  $ai_response['summary']  ?? 'No summary generated.'),
            'actions'                  => $make_card('yes-alt',    'AI Actions', 'ispag-ai-actions-card',  $ai_response['actions']  ?? 'No actions proposed.'),
            'profil'                   => $make_card('analytics',  'AI Profil',  'ispag-ai-profil-card',   $ai_response['profil']   ?? 'No profil generated.'),
            'health_score'             => $ai_response['dna']['health_score']            ?? 'No health score.',
            'explication_health_score' => $ai_response['dna']['explication_health_score'] ?? '-',
        ]);
    }

    /**
     * Formate et envoie la réponse JSON pour le mode meeting.
     */
    private function _send_meeting_response(?array $ai_response, string $contact_name): void {

        /**
         * Nettoie et formate le texte de l'IA (Markdown simple vers HTML)
         */
        $format_text = function(?string $text) {
            if (empty($text)) return 'N/A';
            
            // 1. Protection contre les scripts
            $text = wp_kses_post($text);
            
            // 2. Conversion des listes Markdown (- ou *) en <li>
            // On cherche les lignes commençant par - ou * et on les entoure de <ul>
            if (preg_match('/^[\s\-\*]/m', $text)) {
                $text = preg_replace('/^[\s\-\*]\s?(.*)$/m', '<li>$1</li>', $text);
                $text = preg_replace('/(<li>.*<\/li>)/s', '<ul class="ispag-ai-list">$1</ul>', $text);
            }

            // 3. Conversion du gras **texte** en <strong>
            $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);

            // 4. Conversion des sauts de ligne simples en <br> (si pas déjà dans une liste)
            return nl2br(trim($text));
        };

        $make_card = fn(string $icon, string $title, string $class, ?string $content) => sprintf(
            '<div class="ispag-card %s"><h5><span class="dashicons dashicons-%s"></span> %s</h5><div class="ai-content">%s</div></div>',
            $class, $icon, esc_html__($title, 'ispag-crm'), $format_text($content)
        );

        // ── Préparation des données formatées ──────────
        // On s'assure que chaque valeur est une string, même si l'IA renvoie un array
        $formatted_data = [
            'objectives' => is_array($ai_response['objectives'] ?? '') ? implode("\n", $ai_response['objectives']) : ($ai_response['objectives'] ?? ''),
            'questions'  => is_array($ai_response['questions'] ?? '')  ? implode("\n", $ai_response['questions'])  : ($ai_response['questions'] ?? ''),
            'attention'  => is_array($ai_response['attention'] ?? '')  ? implode("\n", $ai_response['attention'])  : ($ai_response['attention'] ?? ''),
            'agenda'     => is_array($ai_response['agenda'] ?? '')     ? implode("\n", $ai_response['agenda'])     : ($ai_response['agenda'] ?? ''),
            'hook'       => is_array($ai_response['hook'] ?? '')       ? implode("\n", $ai_response['hook'])       : ($ai_response['hook'] ?? ''),
        ];

        // ── Format tinymce : texte structuré injecté dans l'éditeur ──────────
        $tinymce_lines = [sprintf(
            '<h3 style="color: #2c3e50;">%s — %s</h3>',
            esc_html__('Meeting Preparation', 'ispag-crm'),
            esc_html($contact_name)
        )];

        $sections_labels = [
            'objectives' => '🎯 ' . __('Objectives', 'ispag-crm'),
            'questions'  => '❓ ' . __('Key Questions', 'ispag-crm'),
            'attention'  => '⚠️ ' . __('Attention Points', 'ispag-crm'),
            'agenda'     => '📋 ' . __('Agenda', 'ispag-crm'),
            'hook'       => '💬 ' . __('Opening Hook', 'ispag-crm'),
        ];

        foreach ($sections_labels as $key => $label) {
            $content = $formatted_data[$key];
            if (empty(trim(wp_strip_all_tags($content)))) continue;
            
            $tinymce_lines[] = "<h4 style='border-bottom: 1px solid #eee; padding-bottom: 5px; color: #34495e;'>{$label}</h4>";
            $tinymce_lines[] = "<div>" . $format_text($content) . "</div>";
        }

        $tinymce_content = implode("\n", $tinymce_lines);

        self::send_meeting_brief_email($contact_name, $tinymce_content);

        wp_send_json_success([
            'mode'            => 'meeting',
            'title'           => sprintf(__('Meeting prep — %s', 'ispag-crm'), esc_html($contact_name)),

            // Cartes visuelles pour affichage dans le panneau latéral CRM
            'objectives'      => $make_card('flag',         'Objectives',      'ispag-ai-meeting-objectives', $formatted_data['objectives']),
            'questions'       => $make_card('format-ul',    'Key Questions',   'ispag-ai-meeting-questions',  $formatted_data['questions']),
            'attention'       => $make_card('warning',      'Attention',       'ispag-ai-meeting-attention',  $formatted_data['attention']),
            'agenda'          => $make_card('list-view',    'Agenda',          'ispag-ai-meeting-agenda',     $formatted_data['agenda']),
            'hook'            => $make_card('format-quote', 'Opening Hook',    'ispag-ai-meeting-hook',       $formatted_data['hook']),

            // Contenu structuré prêt à injecter dans TinyMCE
            'tinymce_content' => $tinymce_content,
        ]);
    }

    /**
     * Envoie le brief de réunion par email à l'utilisateur connecté.
     */
    public static function send_meeting_brief_email(string $contact_name, string $html_content) {
        $user = wp_get_current_user();
        if (!$user->exists()) return false;

        $to = $user->user_email;
        $subject = sprintf('🚀 Brief meeting : %s', $contact_name);
        
        // On wrap le contenu dans un template HTML simple pour l'email
        $message = sprintf('
            <html>
            <head><title>%s</title></head>
            <body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6;">
                <div style="max-width: 600px; margin: 0 auto; border: 1px solid #eee; padding: 20px;">
                    <h2 style="color: #0073aa; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">%s</h2>
                    %s
                    <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
                    <p style="font-size: 12px; color: #999;">Généré par l\'assistant IA ISPAG CRM le %s</p>
                </div>
            </body>
            </html>',
            esc_html($subject),
            esc_html($subject),
            $html_content,
            date_i18n(get_option('date_format') . ' H:i')
        );

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        return wp_mail($to, $subject, $message, $headers);
    }



    public function ispag_add_account_status_field($user) {
        $status = get_user_meta($user->ID, ISPAG_Crm_Contact_Constants::ACCOUNT_STATUS, true);
        ?>
        <h3><?php _e("Paramètres ISPAG", "ispag"); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="ispag_account_status"><?php _e("Statut du compte"); ?></label></th>
                <td>
                    <select name="ispag_account_status" id="ispag_account_status">
                        <option value="active" <?php selected($status, 'active'); ?>><?php _e("✅ Actif"); ?></option>
                        <option value="disabled" <?php selected($status, 'disabled'); ?>><?php _e("🚫 Désactivé (Accès bloqué)"); ?></option>
                    </select>
                    <p class="description"><?php _e("Si désactivé, l'utilisateur ne pourra plus se connecter au CRM."); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function ispag_save_account_status_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) return false;
        update_user_meta($user_id, ISPAG_Crm_Contact_Constants::ACCOUNT_STATUS, $_POST['ispag_account_status']);
    }

    public function ispag_add_user_department_field( $user ) {
        // Liste de vos départements / succursales
        // À terme, cela pourrait venir d'une table SQL, mais commençons par un tableau simple
        $departments = array(
            'vaulruz_ispag' => 'Vaulruz - ISPAG',
            'issa_isol'     => 'ISSA - Isolation',
            'issa_co'       => 'ISSA - Coupe feu',
            'lambda_isol'   => 'Lambda - Isolation',
            'lambda_plaf'   => 'Lambda - Plafond',
            'werner_isol'   => 'Werner - Isolation',
            'werner_cp'     => 'Werner - Coupe feu',
        );

        $current_dept = get_user_meta( $user->ID, ISPAG_Crm_Contact_Constants::USER_DEPARTMENT, true );
        ?>
        <h3><?php _e("Configuration ISPAG CRM", "ispag"); ?></h3>

        <table class="form-table">
            <tr>
                <th><label for="{ispag_user_department}"><?php _e("Département / Succursale"); ?></label></th>
                <td>
                    <select name="ispag_user_department" id="ispag_user_department">
                        <option value=""><?php _e(" Sélectionner un département "); ?></option>
                        <?php foreach ( $departments as $id => $label ) : ?>
                            <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current_dept, $id ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e("Définit le contexte par défaut de cet utilisateur pour l'attribution des entreprises."); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    public function ispag_save_user_department_field( $user_id ) {
        $meta_user_department = ISPAG_Crm_Contact_Constants::USER_DEPARTMENT;
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }

        if ( isset( $_POST[$meta_user_department] ) ) {
            update_user_meta( $user_id, $meta_user_department, sanitize_key( $_POST[$meta_user_department] ) );
        }
    }

    /**
     * Génère l'URL de l'avatar (ISPAG local ou Gravatar propre)
     */
    private function get_optimized_avatar_url($contact, $size = 30) {
        // 1. Avatar personnalisé ISPAG
        
        if ( ! empty($contact->avatar_id) ) {
            $img = wp_get_attachment_image_src($contact->avatar_id, [$size, $size]);
            if ($img) return $img[0];
        }

        // 2. Gravatar avec fallback 'mp' (Mystery Person) pour éviter les erreurs 404
        // On s'assure que user_email existe bien pour éviter un hash vide
        $email = !empty($contact->user_email) ? $contact->user_email : 'default@ispag.ch';
        $email_hash = md5(strtolower(trim($email)));
        
        // Remplacement de d=404 par d=mp
        return "https://www.gravatar.com/avatar/$email_hash?s=$size&d=mp";
    }

    public function remove_deal_contact_association() {
        global $wpdb;

        // 1. Récupération et sécurisation des données
        $contact_id = isset($_POST['contact_id']) ? absint($_POST['contact_id']) : 0;
        $deal_id    = isset($_POST['deal_id']) ? absint($_POST['deal_id']) : 0;
        $table_deals = ISPAG_Crm_Deal_Constants::TABLE_NAME;

        if ( ! $contact_id || ! $deal_id ) {
            wp_send_json_error( array( 'message' => 'ID Contact ou ID Deal manquant.' ) );
        }

        // 2. Récupérer les contacts actuellement associés à ce deal
        $deal = $wpdb->get_row( $wpdb->prepare(
            "SELECT associated_contact_ids FROM {$table_deals} WHERE id = %d",
            $deal_id
        ) );

        if ( ! $deal ) {
            wp_send_json_error( array( 'message' => 'Deal introuvable.' ) );
        }

        // 3. Traitement de la liste
        $current_ids = ! empty( $deal->associated_contact_ids ) ? explode( ',', $deal->associated_contact_ids ) : array();
        
        // Nettoyage : on enlève les espaces et on s'assure que ce sont des entiers
        $current_ids = array_map( 'trim', $current_ids );
        $current_ids = array_filter( $current_ids );

        // 4. Suppression de l'ID spécifique
        // array_diff crée un nouveau tableau en excluant les valeurs passées dans le second argument
        $updated_ids = array_diff( $current_ids, array( $contact_id ) );

        // 5. Mise à jour de la base de données
        $new_ids_string = implode( ',', $updated_ids );

        $result = $wpdb->update(
            $table_deals,
            array( 'associated_contact_ids' => $new_ids_string ), // Données
            array( 'id' => $deal_id ),                           // Clause WHERE
            array( '%s' ),                                       // Format données
            array( '%d' )                                        // Format WHERE
        );

        if ( false === $result ) {
            wp_send_json_error( array( 'message' => 'Erreur lors de la mise à jour de la base de données.' ) );
        }

        wp_send_json_success( array( 'message' => 'Contact dissocié du deal avec succès.' ) );
    }

    /**
     * Crée un nouveau contact dans WordPress et ajoute ses métadonnées CRM.
     * * @param array $data Les données du contact (email, first_name, last_name, phone, etc.)
     * @return int|false L'ID du nouveau contact ou false en cas d'échec.
     */
    public function insert( $data ) {
        // 1. Préparation des données de base WordPress
        $user_data = array(
            'user_email' => $data['email'],
            'user_login' => $data['email'], // On utilise l'email comme login
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'display_name'=> $data['first_name'] . ' ' . $data['last_name'],
            'user_pass'  => wp_generate_password( 24, true ), // Mot de passe aléatoire
            'role'       => 'subscriber' // Rôle par défaut
        );

        // 2. Création de l'utilisateur
        $user_id = wp_insert_user( $user_data );

        if ( is_wp_error( $user_id ) ) {
            // error_log( 'Erreur ISPAG CRM lors de la création du contact : ' . $user_id->get_error_message() );
            return false;
        }

        // 3. Stockage des métadonnées CRM (Table wp_usermeta)
        // On utilise tes constantes pour être cohérent avec le reste du plugin
        
        if ( ! empty( $data['job_title'] ) ) {
            update_user_meta( $user_id, ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION, $data['job_title'] );
        }

        if ( ! empty( $data['phone'] ) ) {
            update_user_meta( $user_id, ISPAG_Crm_Contact_Constants::META_LEAD_PHONE, $data['phone'] );
        }

        if ( ! empty( $data['company_id'] ) ) {
            update_user_meta( $user_id, ISPAG_Crm_Contact_Constants::META_COMPANY_ID, $data['company_id'] );
        }

        if ( ! empty( $data['owner_id'] ) ) {
            update_user_meta( $user_id, ISPAG_Crm_Contact_Constants::META_OWNER, $data['owner_id'] );
        }

        // Initialisation du statut par défaut (ex: NEW_LEAD)
        update_user_meta( $user_id, ISPAG_Crm_Contact_Constants::META_LEAD_STATUS, 'NEW_LEAD' );
        update_user_meta( $user_id, ISPAG_Crm_Contact_Constants::META_LIFECYCLE_PHASE, 'LEAD' );

        return $user_id;
    }


}

endif;