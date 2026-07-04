<?php

class ISPAG_Contact_Detail_Shortcode {

    // Définitions des méta-clés
    const META_LEAD_PHONE           = 'billing_phone';
    const META_LEAD_FUNCTION        = 'ispag_lead_function';
    const META_LEAD_STATUS          = 'ispag_lead_status';
    const META_LEAD_LINKEDIN_PAGE   = 'ispag_linkedin_page';
    const META_LIFECYCLE_PHASE      = 'ispag_contact_lifecycle_phase';
    const META_COMPANY_ID           = 'ispag_company_id';
    const META_OWNER                = 'ispag_owner';
    const META_OPPORTUNITY          = 'ispag_opportunity';
    const META_BUYING_GOAL          = 'ispag_buying_goal';
    const META_TRANSACTION_OPEN     = 'ispag_transaction_open';
    const META_LAST_CONTACT_DATE    = 'ispag_last_contact_date';
    const META_LAST_CONTACT_SOURCE  = 'ispag_last_contact_source';
    const META_USER_ROLE            = 'wp_user_role';
    const META_HEALTH_CHECK_IGNORE  = 'ispag_ignore_health_check';

    const META_COMPANY_CITY         = 'ispag_company_city';
    const META_COMPANY_ADRESS       = 'ispag_company_adress';
    const META_COMPANY_POSTAL_CODE  = 'ispag_company_postal_code';
    const META_COMPANY_REGION       = 'ispag_company_region';
    const META_COMPANY_COUNTRY      = 'ispag_company_country';
    const META_COMPANY_INDUSTRY     = 'ispag_company_industry';

    private static $log_file = WP_CONTENT_DIR . '/ispag_contact_details_shortcode.log';

    public function __construct() {
        // Enregistrement immédiat du shortcode
        // add_shortcode( 'ispag_contact_detail', array( $this, 'render_contact_detail_shortcode' ) );
        
        // Enqueue les scripts et styles pour le frontend
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_ispag_assets' ) );

        // add_action('wp_ajax_ispag_remove_company_association', [ $this, 'ajax_remove_company_association' ]);
        // add_action( 'wp_ajax_ispag_render_add_company_modal', array( $this, 'ajax_render_add_company_modal' ) );
        // add_action( 'wp_ajax_ispag_search_companies', array( $this, 'ajax_search_companies' ) );
        // add_action( 'wp_ajax_ispag_associate_company_to_contact', array( $this, 'ajax_associate_company_to_contact' ) );

        
        
        // Enregistrement des actions AJAX
        $this->register_ajax_actions();
    }
 
    /**
     * Maintenu pour la rétrocompatibilité de l'initialisation dans le fichier principal.
     */
    public function init() {
        // ...
    }

    /**
     * Enregistre les actions AJAX pour la modification en ligne.
     */
    private function register_ajax_actions() {
        // ..
    }

    

    /**
     * Gère la suppression d'une association entreprise/contact via AJAX.
     */
    public function ajax_remove_company_association() {
        // Sécurité: Vérification du Nonce et des permissions
        // if ( ! check_ajax_referer( 'ispag_security_nonce', 'security', false ) || ! current_user_can( 'edit_users' ) ) {
        //     wp_send_json_error( array( 'message' => 'Erreur de sécurité ou permissions insuffisantes.' ) );
        // }

        $contact_id = absint( filter_input( INPUT_POST, 'contact_id', FILTER_VALIDATE_INT ) );
        $company_id = absint( filter_input( INPUT_POST, 'company_id', FILTER_VALIDATE_INT ) );
        
        if ( $contact_id === 0 || $company_id === 0 ) {
            wp_send_json_error( array( 'message' => 'IDs de contact ou d\'entreprise manquants.' ) );
        }

        // Tente de supprimer la méta-donnée spécifique
        // NOTE: Pour les meta multiples, delete_user_meta supprime seulement l'occurrence ayant la valeur exacte.
        $deleted = delete_user_meta( $contact_id, self::META_COMPANY_ID, $company_id );

        if ( $deleted ) {
            wp_send_json_success( array( 
                'message' => sprintf( 
                    'Association de l\'entreprise ID %d retirée du contact ID %d.', 
                    $company_id, 
                    $contact_id 
                ) 
            ) );
        } else {
            // Cela peut signifier que la méta n'existait pas ou qu'une erreur de base de données s'est produite
            wp_send_json_error( array( 'message' => 'Échec de la suppression de l\'association. L\'entrée n\'existait peut-être pas.' ) );
        }

        wp_die();
    }

    /**
     * Enqueue les styles et scripts nécessaires, incluant le script d'édition en ligne.
     */
    public function enqueue_ispag_assets() {
        
        $post = get_post( get_the_ID() );
        
        if ( ! $post ) {
            return;
        }
        
        // Vérification de la présence des shortcodes sur la page
        $contact_detail_needed = has_shortcode( $post->post_content, 'ispag_contact_detail' );
        $contact_list_needed = has_shortcode( $post->post_content, 'ispag_contact_detail' );

        if ( ! $contact_detail_needed && ! $contact_list_needed ) {
            return;
        }
        
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        // -----------------------------------------------------------
        // --- 1. CSS ---
        // -----------------------------------------------------------
        if ($contact_list_needed) {
            wp_enqueue_style( 'ispag-crm-styles', $plugin_url . 'assets/css/ispag-crm-styles.css', array(), '1.0.0' );
        }

        if ($contact_detail_needed) {
            wp_enqueue_style( 'ispag-contact-detail-styles', $plugin_url . 'assets/css/ispag-contact-detail-styles.css', array(), '1.0.0' );
        }
        
        // -----------------------------------------------------------
        // --- 2. JAVASCRIPT ---
        // -----------------------------------------------------------
        
        // JS pour la liste de contacts 
        if ($contact_list_needed) {
            wp_enqueue_script( 'ispag-crm-bulk-edit-js', $plugin_url . 'assets/js/ispag-bulk-edit.js', array( 'jquery' ), '1.0.0', true );
        }
        
        // JS pour la page de détail et l'édition en ligne
        if ($contact_detail_needed) {
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
    }
    
    // -------------------------------------------------------------------------
    // --- METHODES D'AIDE (RÉCUPÉRATION ET FORMATAGE DE DONNÉES) ---
    // -------------------------------------------------------------------------
    
    /**
     * Formate un tableau clé => objet avec propriété 'label' en chaîne pour l'attribut data-options.
     * @param array $data Map clé => objet avec une propriété spécifiée pour l'affichage.
     * @param string $label_property Le nom de la propriété contenant le label à afficher.
     * @return string Chaîne au format "key1:Label 1;key2:Label 2"
     */
    private function format_options_for_data_attr( $data, $label_property ) {
        $options = [];
        foreach ( $data as $key => $object ) {
            if ( isset( $object->{$label_property} ) ) {
                // Utilise urlencode() pour s'assurer que les caractères spéciaux dans les labels ne cassent pas le format
                $options[] = $key . ':' . ( $object->{$label_property} );
            }
        }
        // Retourne la chaîne encodée, le JS devra utiliser decodeURIComponent() pour récupérer les labels.
        return implode( ';', $options );
    }

    

    /**
     * Récupère tous les propriétaires de contact possibles (administrateurs et auteurs).
     * @return array Map (ID => UserObject)
     */
    public function get_all_owners() {
        return get_users( array( 
            'role__in' => array('administrator', 'ispag_commercial', 'vente_ispag'), 
            'fields' => array( 'ID', 'display_name' ),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'key' => 'ID'
        ) );
    }

    /**
     * Récupère la map complète des statuts de lead.
     * @return array Map (status_key => status_object)
     */
    private function get_lead_statuses_map() {
        global $wpdb;
        $return = array();
        
        $table_name = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME; 

        $full_statuses = $wpdb->get_results( 
            "SELECT status_key, status_label AS label, bg_color, text_color, status_order 
             FROM {$table_name} 
             ORDER BY status_order ASC, status_label ASC" 
        );
        
        if ( ! empty( $full_statuses ) ) {
            foreach ( $full_statuses as $status ) {
                if ( ! empty( $status->status_key ) ) {
                    $return[ $status->status_key ] = $status;
                }
            }
        }

        return $return;
    }


    /**
     * Récupère la map des phases de cycle de vie.
     * @return array Map (phase_key => phase_object)
     */
    private function get_lifecycle_phases_for_display() {
        global $wpdb;
        $return = array();
        $table_name = ISPAG_Crm_Contact_Constants::LIFECYCLE_TABLE_NAME;

        $full_phases = $wpdb->get_results( 
            "SELECT phase_key, phase_label, bg_color, text_color, phase_order 
             FROM {$table_name} 
             ORDER BY phase_order ASC, phase_label ASC" 
        );
        
        if ( ! empty( $full_phases ) ) {
            foreach ( $full_phases as $phase ) {
                if ( ! empty( $phase->phase_key ) ) {
                    $return[ $phase->phase_key ] = $phase;
                }
            }
        }

        return $return;
    }
    
    /**
     * Récupère les X dernières transactions (commandes) d'un contact réel.
     * (Méthode inchangée, conservée pour la complétude)
     * ...
     */
    private function get_contact_transactions( $contact_id, $limit = 5 ) {
        global $wpdb;
        $transactions = [];
        
        $table_name = $wpdb->prefix . 'achats_liste_commande';
        $table_project_audit = $wpdb->prefix . 'ispag_deals_audit';

        if ( $contact_id === 0 ) {
            return $transactions;
        }


        $contact_id_pattern = '%' . $wpdb->esc_like( (string) $contact_id ) . '%';
        $contact_id_pattern_abonne = '%;' . $wpdb->esc_like( (string) $contact_id ) . ';%';
        
            $query = $wpdb->prepare(
            "SELECT 
                p.hubspot_deal_id, p.ObjetCommande, p.date_creation, p.closing_date, 
                p.TimestampDateCommande, p.isQotation, p.id, p.project_status,
                tpa.status_label, tpa.Id AS status_id
                
             FROM {$table_name} AS p
             LEFT JOIN {$table_project_audit} AS tpa
               ON tpa.Id = p.project_status
             WHERE p.AssociatedContactIDs LIKE %s OR p.Abonne LIKE %s 
             ORDER BY p.closing_date DESC 
             LIMIT %d",
            $contact_id_pattern, $contact_id_pattern_abonne,
            $limit
        );

        $results = $wpdb->get_results( $query );
        
        if ( ! empty( $results ) ) {
            foreach ( $results as $result ) {
                
                $project_page = get_page_by_path( 'details-du-projet' );
                $project_page_url = $project_page ? get_permalink( $project_page ) : '#';

                $transaction = new stdClass();
                $transaction->Id = (int) $result->hubspot_deal_id;
                $transaction->deal_status = $result->project_status;
                $transaction->name = esc_html($result->ObjetCommande);
                $transaction->amount = 0;
                $transaction->date_creation = $result->date_creation;
                $transaction->close_date = $result->closing_date;
                $transaction->link = $project_page_url.'?deal_id=' . $transaction->Id;
                // Initialisation de la variable de statut pour la transaction
                $transaction->stage_label = '';
                $transaction->stage_color = '';
                // Règle 1 et 2 : Le statut est-il défini comme Actif (1) ou Clos (0) ?
                // Ces statuts prévalent s'il s'agit d'une soumission (isQotation = 1)
                if ($result->isQotation == 1) {
                    if ($result->status_id >= 100) {
                        // Règle: (isQotation == 1 AND status_id >= 10) => Offre perdue (utiliser le libellé de l'audit)
                        // Note: Assurez-vous que $result->status_label est récupéré via une jointure SQL
                        $transaction->stage_label = __('Lost offer', 'ispag-crm') . ' [ ' . $result->status_label . ' ]';
                        $transaction->stage_color = '#f7001dff';
                        
                    } elseif ($result->status_id >= 10) {
                        $transaction->stage_label = $result->status_label;
                        $transaction->stage_color = '#1ca921b3';
                    } 
                    elseif ($result->status_id < 10) {
                        // Règle: (isQotation == 1 AND status_id < 10) => Offre (s'applique aux états 2 à 9, si non couverts par 0 ou 1)
                        $transaction->stage_label = __('Quotation', 'ispag-crm');
                        $transaction->stage_color = '#8e44ad';
                    }

                } else {
                    // Si isQotation n'est pas égal à 1, nous revenons à la logique de base (ou Projet)
                    $transaction->stage_label = __('Project', 'ispag-crm');
                    $transaction->stage_color = '#e4cb10ff';
                }

                

                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }

    /**
     * Placeholder temporaire pour appeler la méthode de la Note Manager.
     * Doit être remplacé par un appel direct si vous avez accès à l'instance de la Note Manager.
     */
    protected function render_activity_tab_placeholder($contact_id) {
        // Si la classe Note Manager est disponible globalement :
        if ( class_exists( 'ISPAG_Contact_Note_Manager' ) ) {
            // NOTE: Ceci suppose que vous instanciez Note_Manager de manière accessible
            $note_manager = new ISPAG_Contact_Note_Manager(); // Instanciation pour l'exemple
            return $note_manager->render_activity_tab( $contact_id, 'contact' );
        }
        return '<p>Erreur: ISPAG_Contact_Note_Manager n\'est pas accessible pour afficher les activités.</p>';
    }
    

    /**
     * Renders the HTML content for the 'Add Company' modal.
     */
    public function ajax_render_add_company_modal() {
        // NOTE: Assurez-vous d'implémenter les vérifications de sécurité
        $contact_id = absint( filter_input( INPUT_POST, 'contact_id', FILTER_VALIDATE_INT ) );
        
        if ( $contact_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Contact ID manquant.' ) );
        }

        // Récupérer la liste des entreprises déjà associées pour l'affichage dans la modale
        $company_ids = get_user_meta( $contact_id, self::META_COMPANY_ID, false );
        $company_ids = array_filter( array_map( 'absint', (array) $company_ids ) );
        $associated_companies = [];
        
        // Utiliser get_all_companies pour le lookup
        // $all_companies_lookup = $this->get_all_companies();
        $all_companies = new ISPAG_Company_Repository();
        $all_companies_lookup = $all_companies->get_all_companies_with_meta();

        if (!empty($company_ids)) {
            foreach ($company_ids as $id) {
                if (isset($all_companies_lookup[$id])) {
                    // Stocker l'objet complet de l'entreprise
                    $associated_companies[] = $all_companies_lookup[$id]; 
                }
            }
        }
        echo $this->render_add_company_modal( $contact_id, $associated_companies );
        wp_die();
    }

    /**
     * [Hypothèse: Ajouté à la classe Company Detail]
     * Renvoie le HTML de la modale pour ajouter ou rechercher des contacts existants.
     * Cette méthode serait appelée via AJAX.
     * * @param int $company_id L'ID de l'entreprise actuelle.
     * @return string HTML de la modale.
     */
    public function render_add_company_modal( $contact_id, array $associated_companies = [] ) {
        ob_start();
        ?> 
        <div id="ispag-add-company-modal" class="ispag-modal-overlay">
            <div class="ispag-modal-content">
                <div class="ispag-modal-header">
                    <h3><?php _e( 'Add existing company', 'ispag-crm' ); ?></h3>
                    <span class="ispag-modal-close">×</span>
                </div>

                <div class="ispag-modal-tabs">
                    <button class="ispag-tab-modal active" data-tab="create-new"><?php _e( 'Create new', 'ispag-crm' ); ?></button>
                    <button class="ispag-tab-modal" data-tab="add-existing"><?php _e( 'Add existing', 'ispag-crm' ); ?></button>
                </div>

                <div class="ispag-modal-body">
                    <input type="hidden" id="modal_contact_id" value="<?php echo $contact_id; ?>">
                    
                    <div id="tab-add-existing" class="ispag-tab-modal-pane active">
                        <input type="text" id="company-search-input" placeholder="<?php _e( 'Search companies', 'ispag-crm' ); ?>" />
                        <button id="company-search-btn"><span class="dashicons dashicons-search"></span></button>

                        <div id="companies-search-results">
                            <div class="ispag-already-associated-companies">
                                <h5><?php _e( 'Currently associated companies', 'ispag-crm' ); ?></h5>
                                <?php
                                // Vous devez récupérer ici $associated_contacts_list (comme sur la page)
                                
                                // $associated_contacts_list = $this->get_associated_contacts( $company_id, 999 );
                                $associated_companies = [];
                                
                                if ( ! empty( $associated_companies ) ) {
                                    echo '<p>' . count($associated_companies). ' ' . __( 'Contacts', 'ispag-crm' ) . '</p>';
                                    foreach ( $associated_companies as $company ) {
                                        // Afficher chaque contact avec un bouton de suppression (par exemple)
                                        echo '<div class="company-tag" data-id="' . absint( $company->ID ) . '">' . esc_html( $company->display_name ) . ' <span class="remove-company">×</span></div>';
                                    }
                                } else {
                                    echo '<p>Aucune entreprise actuellement lié.</p>';
                                }
                                ?>
                            </div>
                            
                            <hr>
                            <p class="results-count">**X** <?php _e( 'Companies', 'ispag-crm' ); ?></p>
                            
                            <div class="companies-list-container">
                            </div>
                            
                            <a href="#" class="load-more-btn"><?php _e( '10 items', 'ispag-crm' ); ?> <span class="dashicons dashicons-arrow-down-alt2"></span></a>
                        </div>
                    </div>

                    <div id="tab-create-new" class="ispag-tab-modal-pane">
                        <p><?php _e( 'Formulaire de création d\'entreprise...', 'ispag-crm' ); ?></p>
                    </div>
                </div>

                <div class="ispag-modal-footer">
                    <button class="ispag-btn ispag-btn-secondary ispag-modal-cancel"><?php _e( 'Cancel', 'ispag-crm' ); ?></button>
                    <button class="ispag-btn ispag-btn-primary ispag-modal-save-company" data-contact-id="<?php echo absint($contact_id); ?>"><?php _e( 'Save', 'ispag-crm' ); ?></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }


    /**
     * Effectue la recherche d'entreprises non associées via AJAX, 
     * incluant désormais la ville de l'entreprise.
     */
    public function ajax_search_companies() {
        
        // NOTE: Assurez-vous d'implémenter les vérifications de sécurité
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
            wp_die();
        }
        
        $search_term = sanitize_text_field( filter_input( INPUT_POST, 'search_term' ) );
        $contact_id  = absint( filter_input( INPUT_POST, 'contact_id', FILTER_VALIDATE_INT ) );

        // Constantes et tables
        global $wpdb;
        $table_name_fournisseur = ISPAG_Crm_Company_Constants::TABLE_NAME;
        $table_name_postmeta = $wpdb->postmeta;
        $meta_key_city = self::META_COMPANY_CITY; // Utilisation de la constante fournie

        // 1. Récupérer les IDs des entreprises déjà associées (à exclure)
        $company_ids_to_exclude = get_user_meta( $contact_id, self::META_COMPANY_ID, false );
        $company_ids_to_exclude = array_filter( array_map( 'absint', (array) $company_ids_to_exclude ) );
        
        // 2. Préparer les Jointures
        // Jointure pour récupérer la ville de l'entreprise (alias 'meta_ville')
        $join_sql = " LEFT JOIN {$table_name_postmeta} AS meta_ville ON T1.viag_id = meta_ville.post_id AND meta_ville.meta_key = '{$meta_key_city}' ";


        // 3. Préparer la clause WHERE
        $where_clauses = [];
        $params = [];
        
        // Condition d'exclusion (Id NOT IN (id1, id2, ...))
        if ( ! empty( $company_ids_to_exclude ) ) {
            $ids_list = implode( ',', $company_ids_to_exclude );
            // Utilisez T1.Id pour référencer la table principale
            $where_clauses[] = "T1.Id NOT IN ({$ids_list})"; 
        }

        // Condition de recherche (LIKE %term%)
        if ( ! empty( $search_term ) ) {
            $like_term = '%' . $wpdb->esc_like( $search_term ) . '%';
            
            // Recherche dans Fournisseur (Nom), compagnyDomain ET la Ville (méta)
            $where_clauses[] = "(T1.company_name LIKE %s OR T1.compagnyDomain LIKE %s OR meta_ville.meta_value LIKE %s)";
            $params[] = $like_term;
            $params[] = $like_term;
            $params[] = $like_term; // Ajout du terme de recherche pour la ville
        }
        
        $where_sql = empty( $where_clauses ) ? '1=1' : implode( ' AND ', $where_clauses );
        
        // 4. Construction et exécution de la requête SQL
        $sql_base = "
            SELECT 
                T1.viag_id AS Id, T1.company_name AS Fournisseur, T1.compagnyDomain, T1.NumTel, 
                meta_ville.meta_value AS Ville 
            FROM {$table_name_fournisseur} AS T1
            {$join_sql}
            WHERE {$where_sql} 
            ORDER BY T1.company_name ASC 
            LIMIT 10
        ";
        
        // Utilisation des alias (T1 pour la table principale) et de l'alias de la méta (Ville)
        
        if (!empty($params)) {
            // La fonction prepare() insère les paramètres dans la requête et l'échappe
            $sql = $wpdb->prepare($sql_base, $params);
        } else {
            $sql = $sql_base;
        }
        
        // --- LOG DE LA REQUÊTE ---
        // error_log( 'ISPAG CRM: Requête de recherche d\'entreprise AJAX: ' . $sql );
        // --- FIN DU LOG ---
        
        $results = $wpdb->get_results( $sql );
        
        // 5. Retour AJAX
        wp_send_json_success( array( 
            'count'     => count($results), 
            'companies' => $results // 'Ville' est maintenant inclus dans chaque objet
        ) );
        wp_die();
    }

    /**
     * Gère l'association d'une entreprise à un contact via AJAX (ajout de meta).
     */
    public function ajax_associate_company_to_contact() {
        // NOTE: Assurez-vous d'implémenter les vérifications de sécurité
        
        $contact_id = absint( filter_input( INPUT_POST, 'contact_id', FILTER_VALIDATE_INT ) );
        $company_ids = array_map( 'absint', (array) filter_input( INPUT_POST, 'company_ids', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) );

        // error_log('ajax_associate_company_to_contact contact_id' . $contact_id);
        // error_log('ajax_associate_company_to_contact company_ids' . print_r($company_ids, true));
        if ( $contact_id === 0 || empty( $company_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing data.', 'ispag-crm' ) ) );
        }

        $updated_count = 0;
        foreach ( $company_ids as $company_id ) {
            // La clé utilisée est la constante META_COMPANY_ID de votre classe
            add_user_meta( $contact_id, self::META_COMPANY_ID, $company_id, false ); 
            $updated_count++;
        }

        wp_send_json_success( array( 
            'message' => sprintf( __( '%d contacts successfully linked to the company.', 'ispag-crm' ), $updated_count ) 
        ) );
        wp_die();

    }
    // -------------------------------------------------------------------------
    // --- METHODE DE RENDU PRINCIPALE (AVEC INLINE EDIT) ---
    // -------------------------------------------------------------------------

    /**
     * Gère l'affichage du détail d'un contact pour le shortcode [ispag_contact_detail] (Front-End).
     *
     * @param array $atts Attributs du shortcode.
     * @return string Le HTML du profil de contact.
     */
    public function render_contact_detail_shortcode( $atts ) {
        // $log_file = WP_CONTENT_DIR . '/ispag_contact_details_shortcode.log';
        // error_log("--- DEBUT EXECUTION  render_contact_detail_shortcode : " . date('Y-m-d H:i:s') . " ---\n", 3, $log_file);
        // Sécurité
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p class="ispag-access-denied">' . __( 'You do not have permission to view this content.', 'ispag-crm' ) . '</p>';
        }

        // Attribuer les liens
        $transaction_list_page = get_page_by_path( 'liste-des-projets-new' );
        $new_project_page = get_page_by_path( 'nouvelle-selection' );
        $company_page = get_page_by_path( 'entreprise-detail' );

        $link_transaction_list = $transaction_list_page ? get_permalink( $transaction_list_page ) : '#';
        $link_new_project = $new_project_page ? get_permalink( $new_project_page ) : '#';
        

        // Détermination de l'ID du contact 
        // $contact_id = filter_input( INPUT_GET, 'user_id', FILTER_VALIDATE_INT ); --> OBsolete avec le permalien
        $contact_id = absint( get_query_var( 'user_id' ) );

        if ( !$contact_id ) {
            $a = shortcode_atts( array( 'user_id' => 0 ), $atts );
            $contact_id = absint( $a['user_id'] );
        }
        if ( !$contact_id ) {
            $contact_id = get_current_user_id();
        }
        
        if ( $contact_id === 0 ) {
            return '<p class="ispag-error">' . __( 'Contact ID is missing.', 'ispag-crm' ) . '</p>';
        }

        $contact = get_user_by( 'id', $contact_id );
        if ( ! $contact ) {
            return '<p class="ispag-error">' . __( 'Contact not found.', 'ispag-crm' ) . '</p>';
        }

        // --- Récupération des données nécessaires ---
        $lead_statuses_map          = $this->get_lead_statuses_map(); 
        $lifecycle_phases_map       = $this->get_lifecycle_phases_for_display(); 
        // $companies_lookup           = $this->get_all_companies();
        $all_companies              = new ISPAG_Company_Repository();
        $companies_lookup           = $all_companies->get_all_companies_with_meta();
        $owners_sequential          = $this->get_all_owners();
        // // error_log('Owner Lookup ' . print_r($owners_sequential, true), 3, $log_file);
        // $transactions_list          = $this->get_contact_transactions( $contact_id, 5 ); 
        // $full_transactions_list     = $this->get_contact_transactions( $contact_id, 999 ); 

        $repo = new ISPAG_Crm_Deals_Repository(); 
        $transactions_list = $repo->get_projects_by_contact( $contact_id, 5 );
        $transactions_list_full = $repo->get_projects_by_contact( $contact_id, 999 );

 
        $deal_ids_list = '';
        $pairs = [];
        foreach ($transactions_list_full as $transaction) {
            // Le test $transaction->deal_status devient $transaction->stage_key
            if (isset($transaction->stage_key) && !empty($transaction->stage_key)) { 
                
                // Nous conservons rawurlencode pour s'assurer que le nom du deal est sûr dans l'URL/le transfert.
                // Utilisation de $transaction->stage_key (stage Kanban) au lieu de deal_status (Viag)
                $pairs[] = $transaction->id . ':' . rawurlencode($transaction->project_name) . ':' . $transaction->stage_key;
            }
        }
        $deals_string = implode(';', $pairs);

        
        
        // Fonction pour récupérer les méta-données
        $get_meta = function($key) use ($contact_id) {
            return get_user_meta( $contact_id, $key, true );
        };

        // 1. Statut de Lead
        $lead_status_key = $get_meta(self::META_LEAD_STATUS) ?: 'na';
        $lead_data_object = isset($lead_statuses_map[$lead_status_key]) ? $lead_statuses_map[$lead_status_key] : (object)[
            'label' => 'N/A', 
            'bg_color' => '#bdc3c7', 
            'text_color' => '#333'
        ];
        $lead_style = sprintf('background-color: %s; color: %s;', 
            esc_attr($lead_data_object->bg_color), 
            esc_attr($lead_data_object->text_color)
        );
        $lead_status_html_display = sprintf(
            '<span class="ispag-status-badge" style="%s">%s</span>', 
            $lead_style,
            esc_html($lead_data_object->label)
        );
        
        // Attribut data-options pour le sélecteur Lead Status
        // La clé 'label' est utilisée pour l'affichage des options
        $lead_status_data_options = $this->format_options_for_data_attr($lead_statuses_map, 'label');


        // 2. Phase de Cycle de Vie
        $lifecycle_phase_key = $get_meta(self::META_LIFECYCLE_PHASE) ?: 'na';
        $phase_data_object = isset($lifecycle_phases_map[$lifecycle_phase_key]) ? $lifecycle_phases_map[$lifecycle_phase_key] : (object)[
            'phase_label' => 'N/A', 
            'bg_color' => '#bdc3c7', 
            'text_color' => '#333'
        ];
        $lifecycle_phase_style = sprintf('background-color: %s; color: %s;', 
            esc_attr($phase_data_object->bg_color), 
            esc_attr($phase_data_object->text_color)
        );
        $lifecycle_phase_html_display = sprintf(
            '<span class="ispag-status-badge" style="%s">%s</span>', 
            $lifecycle_phase_style,
            esc_html($phase_data_object->phase_label)
        );
        
        // Attribut data-options pour le sélecteur Lifecycle phase
        // La clé 'phase_label' est utilisée pour l'affichage des options
        $lifecycle_phase_data_options = $this->format_options_for_data_attr($lifecycle_phases_map, 'phase_label');

        // 3. Sociétés / Propriétaire / function
        $contact_function = $get_meta(self::META_LEAD_FUNCTION) ?: 'N/A';
        $company_id = absint( $get_meta(self::META_COMPANY_ID) );
        $company_name = isset($companies_lookup[$company_id]) ? esc_html($companies_lookup[$company_id]->Fournisseur) : 'Aucune';
        $company_domain = isset($companies_lookup[$company_id]) ? esc_html($companies_lookup[$company_id]->compagnyDomain) : '-';
        $company_phone = isset($companies_lookup[$company_id]) ? esc_html($companies_lookup[$company_id]->NumTel) : '-';
        $company_city = get_post_meta( $company_id, self::META_COMPANY_CITY, true );
        $company_address = get_post_meta( $company_id, self::META_COMPANY_ADRESS, true );
        $company_postal_code = get_post_meta( $company_id, self::META_COMPANY_POSTAL_CODE, true );
        $company_region = get_post_meta( $company_id, self::META_COMPANY_REGION, true );
        $company_country = get_post_meta( $company_id, self::META_COMPANY_COUNTRY, true );
        $company_industry = get_post_meta( $company_id, self::META_COMPANY_INDUSTRY, true );

        $company_ids = get_user_meta( $contact_id, self::META_COMPANY_ID, false );
        $company_ids = array_filter( array_map( 'absint', (array) $company_ids ) );
        $associated_companies_list = [];
        if ( ! empty( $company_ids ) ) {
            foreach ($company_ids as $company_id) {
                $associated_companies_list[] = $companies_lookup[$company_id];
            }
        }
        
        $link_company_page = $company_page ? get_permalink( $company_page ) : '#';

        // Attribut data-options pour le sélecteur Company
        $company_options_map = [];
        foreach ($companies_lookup as $id => $data) {
            $company_options_map[$id] = (object)['label' => $data->Fournisseur];
        }
        $company_data_options = $this->format_options_for_data_attr($company_options_map, 'label');
        
        $owner_id = absint( $get_meta(self::META_OWNER) );
        $linkedin_page = ( $get_meta(self::META_LEAD_LINKEDIN_PAGE) );;
        
        $owners_lookup = [];
        foreach ($owners_sequential as $owner_object) {
            // La clé devient l'ID de l'utilisateur (1, 512, 1477, etc.)
            $owners_lookup[$owner_object->ID] = $owner_object;
        }
        // 3. Ajouter l'option "Aucun propriétaire" (ID 0) manuellement pour la validation.
        $validation_map[0] = (object)['display_name' => '— Aucun propriétaire —'];
        $owner_name = isset($owners_lookup[$owner_id]) ? esc_html($owners_lookup[$owner_id]->display_name) : 'Aucun propriétaire';
        
        // Attribut data-options pour le sélecteur Owner
        $owner_options_map = [];
        // --- AJOUTER L'OPTION 0 EN PREMIER POUR GARANTIR LA DÉSÉLECTION ---
        $owner_options_map[0] = (object)['label' => '— Aucun —'];
        if (isset($owners_lookup[0])) {
            $owner_options_map[$owners_lookup[0]->ID] = (object)['label' => $owners_lookup[0]->display_name];
        }

        // Ajouter le reste des propriétaires
        // error_log('Owner Lookup avant foreach' . print_r($owners_lookup, true), 3, $log_file);
        foreach ($owners_lookup as $id => $data) {
            // S'assurer de ne pas écraser l'ID 0 si la boucle le contient déjà
            if ($data->ID !== 0) { 
                $owner_options_map[$data->ID] = (object)['label' => $data->display_name];
                // error_log('IN foreach' . print_r($owner_options_map[$data->ID] , true), 3, $log_file);
            }
        }
        $owner_data_options = $this->format_options_for_data_attr($owner_options_map, 'label');
        // error_log('AFTER foreach owner_data_options' . print_r($owner_data_options , true), 3, $log_file);


        // 4. Autres champs (non modifiables dans cette version)
        $transaction_open = $get_meta(self::META_TRANSACTION_OPEN) ? 'Oui' : 'Non';
        $opportunity_value = esc_html($get_meta(self::META_OPPORTUNITY) ?: 'N/A');
        $buying_goal_value = esc_html($get_meta(self::META_BUYING_GOAL) ?: 'N/A');

        // $last_contact_info = $this->get_last_contact_date( $contact_id, 'contact' );
        $meta_key = self::META_LAST_CONTACT_DATE; 
        $meta_key_source = self::META_LAST_CONTACT_SOURCE; 
        $last_contact_date = get_user_meta( $contact_id, $meta_key, true );
        $last_contact_source = get_user_meta( $contact_id, $meta_key_source, true );


        // Selection du role principal sur wordpress

        $roles_to_exclude = [
            'administrator',
            'editor',
            'supplier',
            'translator',
        ];

        // Vérifier si l'utilisateur *qui effectue l'action* (l'éditeur) est un administrateur.
        // Si l'utilisateur actuel n'est PAS un administrateur, nous limitons davantage les options.
        if ( ! current_user_can( 'administrator' ) ) {
            
            // Rôles internes à ISPAG qui ne peuvent être attribués que par un administrateur.
            $ispag_roles_to_exclude = [
                'membre_ispag',
                'vente_ispag',
                'achat_ispag',
                'ispag_commercial',
            ];
            
            // Fusionner la liste d'exclusion des rôles critiques et des rôles internes à ISPAG.
            $roles_to_exclude = array_merge( $roles_to_exclude, $ispag_roles_to_exclude );
            
            // Optionnel : s'assurer qu'il n'y a pas de doublons, même si array_merge devrait suffire.
            $roles_to_exclude = array_unique( $roles_to_exclude );
        }

        // 1. Récupérer tous les rôles WordPress disponibles
        global $wp_roles;
        if ( ! isset( $wp_roles ) ) {
            $wp_roles = new WP_Roles();
        }
        // Utilise get_names() qui renvoie [cle => Nom Affiché]
        $all_roles = $wp_roles->get_names(); 

        // 2. Préparer le tableau des options avec le rôle par défaut "none"
        $role_options_data = [
            'none' => __('(No role selected)', 'ispag-crm')
        ];

        // 2b. Filtrage des rôles
        foreach ($all_roles as $key => $display_name) {
            if ( ! in_array( $key, $roles_to_exclude ) ) {
                // Le rôle est pertinent et n'est pas dans la liste d'exclusion
                $role_options_data[$key] = $display_name;
            }
        }

        // 3. Formater les options au format brut "key:Display Name; key:Display Name..."
        $formatted_options = [];
        foreach ($role_options_data as $key => $display_name) {
            // S'assurer que la chaîne est sûre pour l'attribut HTML.
            // L'échappement HTML est critique car les données vont dans un attribut data-options.
            $safe_key = esc_attr($key);
            $safe_display_name = esc_attr($display_name); 
            
            // Pour une sécurité accrue : on retire tout point-virgule ou deux-points 
            // qui pourraient casser le format brut, même si les noms de rôles WP standard sont sûrs.
            $safe_display_name = str_replace( [':', ';'], '', $safe_display_name ); 
            
            $formatted_options[] = $safe_key . ':' . $safe_display_name;
        }

        // Joindre les éléments avec un point-virgule (;)
        $role_data_options = implode(';', $formatted_options);

        // 4. Récupérer le rôle principal actuel de l'utilisateur (Logique inchangée)
        $user_info = get_userdata($contact_id);
        $current_role_key = 'none';
        $user_role_display = __('Non défini', 'ispag-crm');

        if ($user_info && !empty($user_info->roles)) {
            $current_role_key = array_shift($user_info->roles);
            
            if (isset($wp_roles->role_names[$current_role_key])) {
                $user_role_display = translate_user_role($wp_roles->role_names[$current_role_key]);
            } else {
                $user_role_display = $current_role_key;
            }
        }

        $is_ignored = get_user_meta( $contact->ID, self::META_HEALTH_CHECK_IGNORE, true );
        // Assurez-vous que la valeur est '0' si elle est vide (non définie)
        if ( empty( $is_ignored ) ) {
            $is_ignored = '0';
        }

        // 3. Déterminer le texte à afficher
        $status_text = ( $is_ignored == '1' ) ? 'Oui (Ignoré)' : 'Non (Suivi Actif)';
        
        // --- Début de la sortie HTML ---
        ob_start();
        ?>
        <div class="ispag-detail-container" data-contact-id="<?php echo absint($contact_id); ?>">
            <div class="ispag-left-panel">
                
                <div class="ispag-card ispag-header-card">
                    <div class="ispag-profile-pic">
                        <?php 
                        $first_initial = strtoupper( substr( get_user_meta( $contact->ID, 'first_name', true ), 0, 1 ) ); 
                        $last_initial = strtoupper( substr( get_user_meta( $contact->ID, 'last_name', true ), 0, 1 ) ); 
                        echo esc_html( $first_initial . $last_initial ); 
                        ?>
                    </div>
                    <div class="ispag-header-info">
                        <h4><?php echo esc_html( $contact->display_name ); ?></h4>
                        <h6 class="ispag-editable-field" 
                            data-type="text" 
                            data-name="<?php echo self::META_LEAD_FUNCTION; ?>" 
                            data-value="<?php echo esc_attr( $contact_function ); ?>"
                        >
                            <?php echo esc_html( $contact_function ); ?>
                        </h6>
                        <p 
                            class="ispag-editable-field" 
                            data-type="email" 
                            data-name="user_email" 
                            data-value="<?php echo esc_attr( $contact->user_email ); ?>"
                        >
                            <?php echo esc_html( $contact->user_email ); ?>
                            <span class="edit-icon">✏️</span>
                        </p>
                    </div>

                </div>
                <div class="ispag-actions-bar">
                    <button class="ispag-action-btn"
                        data-action="note"
                        data-user-id="<?php echo $contact->ID; ?>"
                        data-company-id="<?php echo $company_id; ?>"
                        data-deal-ids="<?php echo esc_attr($deals_string); ?>"
                        title="<?php esc_attr_e( 'Add Note', 'ispag-crm' ); ?>"
                    >
                        <span class="dashicons dashicons-text-page"></span>
                        <?php esc_html_e( 'Note', 'ispag-crm' ); ?>
                    </button>

                    <?php if ( ! empty( $contact->billing_phone ) ) : ?>
                        <a 
                            href="tel:<?php echo esc_attr( $contact->billing_phone ); ?>" 
                            title="<?php esc_attr_e( 'Call this number', 'ispag-crm' ); ?>"
                        >
                            <button class="ispag-action-btn"
                                data-action="call"
                                data-user-id="<?php echo $contact->ID; ?>"
                                data-company-id="<?php echo $company_id; ?>"
                                data-deal-ids="<?php echo esc_attr($deals_string); ?>"
                                title="<?php esc_attr_e( 'Log a call', 'ispag-crm' ); ?>"
                            >
                                <span class="dashicons dashicons-phone"></span>
                                <?php esc_html_e( 'Call', 'ispag-crm' ); ?>
                            </button>
                        </a>
                    <?php 
                    else:
                    ?>
                    <button class="ispag-action-btn"
                        data-action="call"
                        data-user-id="<?php echo $contact->ID; ?>"
                        data-company-id="<?php echo $company_id; ?>"
                        data-deal-ids="<?php echo esc_attr($deals_string); ?>"
                        title="<?php esc_attr_e( 'Log a call', 'ispag-crm' ); ?>"
                    >
                        <span class="dashicons dashicons-phone"></span>
                        <?php esc_html_e( 'Call', 'ispag-crm' ); ?>
                    </button>
                    <?php endif; ?>


                    <button class="ispag-action-btn"
                        data-action="email"
                        data-user-id="<?php echo $contact->ID; ?>"
                        data-company-id="<?php echo $company_id; ?>"
                        data-deal-ids="<?php echo esc_attr($deals_string); ?>"
                        title="<?php esc_attr_e( 'Log an Email', 'ispag-crm' ); ?>"
                    >
                        <span class="dashicons dashicons-email"></span>
                        <?php esc_html_e( 'E-mail', 'ispag-crm' ); ?>
                    </button>
                    

                    <button class="ispag-action-btn"
                        data-action="task"
                        data-user-id="<?php echo $contact->ID; ?>"
                        data-company-id="<?php echo $company_id; ?>"
                        data-deal-ids="<?php echo esc_attr($deals_string); ?>"
                        title="<?php esc_attr_e( 'Create Task', 'ispag-crm' ); ?>"
                    >
                        <span class="dashicons dashicons-list-view"></span>
                        
                        <?php esc_html_e( 'Task', 'ispag-crm' ); ?>
                    </button>

                    <button class="ispag-action-btn"
                        data-action="meeting"
                        data-user-id="<?php echo $contact->ID; ?>"
                        data-company-id="<?php echo $company_id; ?>"
                        data-deal-ids="<?php echo esc_attr($deals_string); ?>"
                        title="<?php esc_attr_e( 'Schedule Meeting', 'ispag-crm' ); ?>"
                    >
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <?php esc_html_e( 'Meeting', 'ispag-crm' ); ?>
                    </button>
                </div>

                <div class="ispag-card ispag-key-info">
                    <h5><?php _e( 'Key information', 'ispag-crm' ); ?></h5>
                    <dl class="ispag-key-info-list">
                        
                        <dt><?php _e( 'Ignore health reminder', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="checkbox" 
                            data-name="<?php echo self::META_HEALTH_CHECK_IGNORE; ?>" 
                            data-value="<?php echo esc_attr( $is_ignored ); ?>"
                        >
                            <?php echo esc_html( $status_text ); ?>
                            <span class="edit-icon">✏️</span>
                        </dd>

                        <dt><?php _e( 'Email', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="email" 
                            data-name="user_email" 
                            data-value="<?php echo esc_attr( $contact->user_email ); ?>"
                        >
                            <?php echo esc_html( $contact->user_email ); ?>
                            <span class="edit-icon">✏️</span>
                        </dd>

                        <dt><?php _e( 'Phone number', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="phone" 
                            data-name="billing_phone" 
                            data-value="<?php echo esc_attr( $contact->billing_phone ); ?>"
                            style="display: flex; align-items: center; justify-content: space-between;"
                        >
                            <span class="ispag-phone-display-value">
                                <?php echo $contact->billing_phone; ?>
                            </span>
                        </dd>

                        
                        <dt><?php _e( 'Role', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field user-role" 
                            data-type="select" 
                            data-name="<?php echo self::META_USER_ROLE; ?>" 
                            data-value="<?php echo esc_attr($current_role_key); ?>" 
                            data-options="<?php echo esc_attr($role_data_options); ?>"
                        >
                            <?php echo esc_html($user_role_display); ?>
                            <span class="edit-icon">✏️</span>
                        </dd>

                        <dt><?php _e( 'Last contacted', 'ispag-crm' ); ?></dt>
                        <dd >
                            <?php echo date_i18n( 'j F Y', strtotime( $last_contact_date ) ); ?>
                            <span class="contact-info-icon" title="<?php echo ' (' . esc_html( $last_contact_source ) . ')'; ?>">ⓘ</span>
                        </dd>

                        <dt><?php _e( 'Lead Status', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="select" 
                            data-name="<?php echo self::META_LEAD_STATUS; ?>" 
                            data-value="<?php echo esc_attr($lead_status_key); ?>" 
                            data-options="<?php echo esc_attr($lead_status_data_options); ?>"
                        >
                            <?php echo $lead_status_html_display; ?>
                            <span class="edit-icon">✏️</span>
                        </dd>
                        
                        <dt><?php _e( 'Lifecycle phase', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="select" 
                            data-name="<?php echo self::META_LIFECYCLE_PHASE; ?>" 
                            data-value="<?php echo esc_attr($lifecycle_phase_key); ?>" 
                            data-options="<?php echo esc_attr($lifecycle_phase_data_options); ?>"
                        >
                            <?php echo $lifecycle_phase_html_display; ?>
                            <span class="edit-icon">✏️</span>
                        </dd>
                        
                        <dt><?php _e( 'Transaction open', 'ispag-crm' ); ?></dt>
                        <dd><?php echo $transaction_open; ?></dd>
                        
                        <dt><?php _e( 'Contact owner', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="select" 
                            data-name="<?php echo self::META_OWNER; ?>" 
                            data-value="<?php echo absint($owner_id); ?>" 
                            data-options="<?php echo esc_attr($owner_data_options); ?>"
                        >
                            <?php echo $owner_name; ?>
                            <span class="edit-icon">✏️</span>
                        </dd>

                        <dt><?php _e( 'Linkedin page', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="text" 
                            data-name="<?php echo self::META_LEAD_LINKEDIN_PAGE; ?>" 
                            data-value="<?php echo absint($linkedin_page); ?>" 
                        >
                            <?php echo $linkedin_page; ?>
                            <span class="edit-icon">✏️</span>
                        </dd>
                    </dl>
                </div>
                
                <div class="ispag-card">
                    <h5><?php _e( 'Sales information', 'ispag-crm' ); ?></h5>
                    <dl class="ispag-key-info-list">
                        <dt><?php _e( 'Opportunity value', 'ispag-crm' ); ?></dt>
                        <dd><?php echo $opportunity_value; ?></dd>
                        <dt><?php _e( 'Buying goal', 'ispag-crm' ); ?></dt>
                        <dd><?php echo $buying_goal_value; ?></dd>
                    </dl>
                </div>
            </div>
            
            <div class="ispag-main-content">
        
        <div class="ispag-tabs-navigation">
            <button class="ispag-tab-btn active" data-tab="about">
                <?php esc_html_e( 'About', 'ispag-crm' ); ?>
            </button>
            <button class="ispag-tab-btn" data-tab="activity">
                <?php esc_html_e( 'Activity', 'ispag-crm' ); ?>
            </button>
            <button class="ispag-tab-btn" data-tab="deal">
                <?php esc_html_e( 'Deal', 'ispag-crm' ); ?>
            </button>
            <button class="ispag-tab-btn" data-tab="intelligence">
                <?php esc_html_e( 'AI Intelligence', 'ispag-crm' ); ?>
            </button>
            </div>
        
        <div class="ispag-tabs-content">
            
            <div id="ispag-tab-about" class="ispag-tab-pane active">
                

                <div class="ispag-card">
                            <h5><?php _e( 'Company Profile', 'ispag-crm' ); ?></h5>
                            <div data-company-id="<?php echo $company_id; ?>" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; font-size: 14px;">
                                
                                <div class="ispag-field-container">
                                    <strong><?php _e( 'Street adress', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_ADRESS; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_address); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_address); ?></span>
                                    </p>
                                </div>

                                <div class="ispag-field-container">
                                    <strong><?php _e( 'City', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_CITY; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_city); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_city); ?></span>
                                    </p>
                                </div>
                                
                                <div class="ispag-field-container">
                                    <strong><?php _e( 'Postal code', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_POSTAL_CODE; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_postal_code); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_postal_code); ?></span>
                                    </p>
                                </div>

                                <div class="ispag-field-container">
                                    <strong><?php _e( 'State/Region', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_REGION; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_region); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_region); ?></span>
                                    </p>
                                </div>
                                
                                <div class="ispag-field-container">
                                    <strong><?php _e( 'Country/Region', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_COUNTRY; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_country); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_country); ?></span>
                                    </p>
                                </div>

                                <div class="ispag-field-container">
                                    <strong><?php _e( 'Industry', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_INDUSTRY; ?>" 
                                            data-type="select"
                                            data-value="<?php echo esc_html($company_industry); ?>"
                                            data-options='["Installateur CVC", "Ingenieur CVC"]'
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_industry); ?></span>
                                    </p>
                                </div>
                            </div>
                        </div>
            </div>
            
            <div id="ispag-tab-activity" class="ispag-tab-pane">
                <?php 

                echo $this->render_activity_tab_placeholder($contact_id); 
                ?>
            </div>
            
            <div id="ispag-tab-deal" class="ispag-tab-pane">
                <h5>
                    <?php _e( 'Transactions', 'ispag-crm' ); ?> (<?php echo count($transactions_list_full); ?>)
                    <span style="font-size: 12px; color: #007bff; cursor: pointer;"><a href="<?php echo $link_new_project; ?>" target="_blank">+ <?php _e( 'Add', 'ispag-crm' ); ?></a></span>
                </h5>
                
                <?php 
                $deal_base_url = site_url('/deal/');
                if ( ! empty( $transactions_list_full ) ): ?>
                    <div class="ispag-transactions-list">
                        <?php foreach ( $transactions_list_full as $transaction ): 
                            $deal_url = $deal_base_url . absint($transaction->id); ?>
                            <div class="ispag-full-transaction-item">
                                <h6><a href="<?php echo esc_url($deal_url); ?>" target="_blank"><?php echo $transaction->project_name; ?></a></h6>
                                <p><?php _e( 'Amount', 'ispag-crm' ); ?>: <?php echo number_format($transaction->total_excl_vat, 2, ',', ' ') . ' CHF'; ?></p>
                                <p><?php _e( 'Closing date', 'ispag-crm' ); ?>: <?php echo date_i18n( 'j F Y', strtotime( $transaction->closing_date ) ); ?></p>
                                <p><?php _e( 'Transaction phase', 'ispag-crm' ); ?>: 
                                    <span class="ispag-status-badge" style="background-color: <?php echo esc_attr($transaction->stage_color); ?>; color: #fff;">
                                        <?php echo esc_html($transaction->stage_label); ?>
                                    </span>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($transactions_list_full) >= 999): ?>
                        <a href="<?php echo $link_transaction_list; ?>?contact_id=<?php echo absint($contact_id); ?>" class="ispag-button-link"><?php _e( 'Show all transactions', 'ispag-crm' ); ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="font-size: 14px; color: #777;"><?php _e( 'No recent transactions.', 'ispag-crm' ); ?></p>
                <?php endif; ?>
            </div>
            
            <div id="ispag-tab-intelligence" class="ispag-tab-pane">
                <div 
                    id="gemini-ai-summary-<?php echo absint($contact_id); ?>" 
                    class="ispag-ai-placeholder"
                    data-contact-id="<?php echo absint($contact_id); ?>"
                >
                    <p style="text-align: center; color: #999; padding: 20px;">
                        <span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span> 
                        <?php _e( 'Loading AI summary...', 'ispag-crm' ); ?>
                    </p>
                </div>
            </div>
            
        </div></div>

            <div class="ispag-right-panel">
                
                <div class="ispag-card ispag-company-card">
                    <h5>
                        <?php _e( 'Companies', 'ispag-crm' ); ?> (<?php echo count($associated_companies_list); ?>)
                        <span id="open-add-company-modal"
                            data-contact-id="<?php echo absint($contact_id); ?>" 
                            style="font-size: 12px; color: #007bff; cursor: pointer;">
                            + <?php _e( 'Add', 'ispag-crm' ); ?>
                        </span>
                    </h5>
                    <?php
                    $nb_companies = 0;
                    if ( $company_name !== 'Aucune' ):
                        
                    foreach ($associated_companies_list as $company) {
                        // --- NOUVELLE LOGIQUE POUR LES INITIALES (MAX 2 LETTRES) ---
                        $full_name = esc_html($company->Fournisseur) . ' (' . esc_html($company->city) .')';
                        
                        // Utilisation de preg_split pour gérer plusieurs espaces, et trim pour les espaces inutiles.
                        $name_parts = preg_split('/\s+/', trim($full_name), -1, PREG_SPLIT_NO_EMPTY);
                        $initials = '';
                        foreach ($name_parts as $part) {
                            if (!empty($part)) {
                                $initials .= strtoupper(substr($part, 0, 1));
                                if (strlen($initials) >= 2) {
                                    break;
                                }
                            }
                        }
                        $initials = substr($initials, 0, 2); // Limite finale à 2 initiales
                        $favicon = $this->get_favicon_url($company->compagnyDomain);
                        // -----------------------------------------------------------------
                        if($nb_companies < 1):
                        ?>
                        <input type="hidden" id="hidden_company_name"  value="<?php echo $company_name; ?>"/>
                        <?php
                        endif;
                        ?>
                        <div class="ispag-card" style="font-size: 14px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <strong style="color: #007bff; display: flex; align-items: center;"> 
    
                                    <div class="company-initials-avatar">
                                        <?php
                                        if ($favicon) {
                                            // Afficher l'icône :
                                            echo '<img src="' . $favicon . '" alt="Favicon" style="width:32px; height:32px;">';
                                        } else {
                                            // Afficher les deux premières lettres du nom de l'entreprise
                                            $initials = strtoupper( substr( $company_name, 0, 1 ) . substr( $company_name, strpos($company_name, ' ') + 1, 1 ) );
                                            echo esc_html( $initials ); 
                                        }
                                        ?>

                                    </div>
                                    
                                    <a href="<?php echo $link_company_page; ?>?company_id=<?php echo absint($company->Id); ?>" style="margin-left: 8px;">
                                        <?php echo $full_name; ?>
                                    </a>
                                </strong>
                                
                                <span 
                                    class="ispag-remove-association" 
                                    data-contact-id="<?php echo absint($contact_id); ?>"
                                    data-company-id="<?php echo absint($company->Id); ?>"
                                    title="<?php esc_attr_e( 'Remove association', 'ispag-crm' ); ?>"
                                    style="color: #e74c3c; cursor: pointer;"
                                >
                                    <span class="dashicons dashicons-trash"></span>
                                </span>
                            </div>
                            <p style="margin: 5px 0 0;"><?php _e( 'Company domain', 'ispag-crm' ); ?>: <?php echo $company->compagnyDomain; ?></p>
                            <p style="margin: 5px 0 0;"><?php _e( 'Company phone', 'ispag-crm' ); ?>: <?php echo $company->NumTel; ?></p>
                        </div>
                    <?php
                    $nb_companies++;
                    }
                    else: ?>
                        <p style="font-size: 14px; color: #777;"><?php _e( 'No related companies.', 'ispag-crm' ); ?></p>
                    <?php endif; ?>
                    <a href="#" class="ispag-button-link" target="_blank"><?php _e( 'Show related companies', 'ispag-crm' ); ?></a>
                </div>
                <div id="ispag-modal-container"></div>
                
                <div class="ispag-card ispag-transactions-card">
                    <h5>
                        <?php _e( 'Transactions', 'ispag-crm' ); ?> (<?php echo count($transactions_list_full); ?>)
                        <span style="font-size: 12px; color: #007bff; cursor: pointer;"><a href="<?php echo $link_new_project; ?>" target="_blank">+ <?php _e( 'Add', 'ispag-crm' ); ?></a></span>
                    </h5>
                    
                    <?php if ( ! empty( $transactions_list ) ): ?>
                        <div class="ispag-transactions-list">
                            <?php foreach ( $transactions_list as $transaction ): 
                                $deal_url = $deal_base_url . absint($transaction->id); ?>
                                <div class="ispag-transaction-item">
                                    <h6><a href="<?php echo esc_url($deal_url); ?>" target="_blank"><?php echo $transaction->project_name; ?></a></h6>
                                    <p><?php _e( 'Amount', 'ispag-crm' ); ?>: <?php echo number_format($transaction->total_excl_vat, 2, ',', ' ') . ' CHF'; ?></p>
                                    <p><?php _e( 'Closing date', 'ispag-crm' ); ?>: <?php echo date_i18n( 'j F Y', strtotime( $transaction->closing_date ) ); ?></p>
                                    <p><?php _e( 'Transaction phase', 'ispag-crm' ); ?>: 
                                        <span class="ispag-status-badge" style="background-color: <?php echo esc_attr($transaction->stage_color); ?>; color: #fff;">
                                            <?php echo esc_html($transaction->stage_label); ?>
                                        </span>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($transactions_list) >= 5): ?>
                            <a href="<?php echo $link_transaction_list; ?>?contact_id=<?php echo absint($contact_id); ?>" class="ispag-button-link"><?php _e( 'Show all transactions', 'ispag-crm' ); ?></a>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="font-size: 14px; color: #777;"><?php _e( 'No recent transactions.', 'ispag-crm' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        // error_log("--- FIN EXECUTION  render_contact_detail_shortcode : " . date('Y-m-d H:i:s') . " ---\n", 3, $log_file);
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // --- Fonctions Placeholders pour la cohérence des données ---
    // -------------------------------------------------------------------------

    /**
     * Tente de trouver l'URL du favicon d'un domaine donné.
     *
     * @param string $domain Le domaine cible (ex: "ispag.ch").
     * @param int $timeout Le délai d'expiration pour les requêtes cURL.
     * @return string|false L'URL du favicon ou false si non trouvé.
     */
    function get_favicon_url($domain, $timeout = 5) {
        // 1. Nettoyage et normalisation du domaine
        $domain = trim($domain);
        $domain = preg_replace('/^https?:\/\//', '', $domain); // Supprimer http/https
        $domain = rtrim($domain, '/'); // Supprimer le slash final

        if (empty($domain)) {
            return false;
        }
        
        // Définition de l'URL de base pour l'analyse
        $base_url = 'https://' . $domain; 
        
        // --- Fonction utilitaire pour effectuer les requêtes cURL ---
        $make_request = function($url) use ($timeout) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // À désactiver en production si possible
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (FaviconFinder)');
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            
            return [
                'code' => $http_code,
                'content_type' => $content_type,
                'body' => $response
            ];
        };

        // -----------------------------------------------------------------
        // ÉTAPE 1 : Vérification des emplacements standards (Convention)
        // -----------------------------------------------------------------

        $standard_paths = [
            $base_url . '/favicon.ico',
            $base_url . '/apple-touch-icon.png',
            $base_url . '/apple-touch-icon-precomposed.png',
        ];

        foreach ($standard_paths as $path) {
            $response = $make_request($path);

            // Si la requête est un succès (200) et qu'il s'agit d'une image
            if ($response['code'] === 200 && str_contains($response['content_type'], 'image')) {
                return $path;
            }
        }

        // -----------------------------------------------------------------
        // ÉTAPE 2 : Analyse de la page d'accueil (Méthode robuste)
        // -----------------------------------------------------------------
        
        // Récupérer le code HTML de la page d'accueil
        $html_response = $make_request($base_url);

        if ($html_response['code'] === 200) {
            // Utiliser une expression régulière pour trouver les liens de favicon dans le <head>
            // Recherche des rel="icon", rel="shortcut icon", rel="apple-touch-icon"
            $pattern = '/<link[^>]+rel=["\'](icon|shortcut icon|apple-touch-icon)["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i';
            
            if (preg_match_all($pattern, $html_response['body'], $matches)) {
                // $matches[2] contient tous les chemins href trouvés
                $potential_paths = array_unique($matches[2]);

                // Tenter de valider le chemin trouvé (en privilégiant les chemins absolus ou en testant l'accès)
                foreach ($potential_paths as $path) {
                    // Si le chemin est absolu (commence par http/https)
                    if (preg_match('/^https?:\/\//i', $path)) {
                        $favicon_url = $path;
                    } 
                    // Si le chemin est relatif (commence par / ou non)
                    else {
                        $favicon_url = $base_url . '/' . ltrim($path, '/');
                    }

                    // Vérification finale si le lien est valide (facultatif mais recommandé)
                    $check = $make_request($favicon_url);
                    if ($check['code'] === 200 && str_contains($check['content_type'], 'image')) {
                        return $favicon_url;
                    }
                }
            }
        }

        // -----------------------------------------------------------------
        // ÉTAPE 3 : Solution de secours (Service tiers)
        // -----------------------------------------------------------------
        
        // Utilisation du service Google S2 pour la fiabilité maximale
        // Attention: ceci dépend d'un service externe et peut changer
        $fallback_url = 'https://t0.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=1&url=' . urlencode($base_url) . '&size=64';
        
        // Tenter de valider le fallback (pour éviter une URL S2 cassée)
        $fallback_check = $make_request($fallback_url);
        if ($fallback_check['code'] === 200 && str_contains($fallback_check['content_type'], 'image')) {
            return $fallback_url;
        }
        
        // Aucune icône trouvée
        return false;
    }
}