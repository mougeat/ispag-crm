<?php

// Fichier : includes/crm/repositories/class-ispag-crm-company-repository.php

if ( ! class_exists( 'ISPAG_Crm_Company_Modal' ) ) :
class ISPAG_Crm_Company_Modal {



    public function __construct() {
        // Enregistrement immédiat du shortcode
                
        // Enqueue les scripts et styles pour le frontend
        // add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_ispag_assets' ) );

        add_action('wp_ajax_ispag_remove_company_association', [ $this, 'ajax_remove_company_association' ]); 
        add_action( 'wp_ajax_ispag_render_add_company_modal', array( $this, 'ajax_render_add_company_modal' ) );
        add_action( 'wp_ajax_ispag_search_companies', array( $this, 'ajax_search_companies' ) );
        add_action( 'wp_ajax_ispag_associate_company_to_contact', array( $this, 'ajax_associate_company_to_contact' ) );

        

    }


    /**
     * Gère la suppression d'une association entreprise/contact via AJAX.
     * Adapté pour le stockage en liste séparée par des virgules.
     */
    public function ajax_remove_company_association() {
        $contact_id = absint( filter_input( INPUT_POST, 'contact_id', FILTER_VALIDATE_INT ) );
        $company_id = absint( filter_input( INPUT_POST, 'company_id', FILTER_VALIDATE_INT ) );
        
        if ( $contact_id === 0 || $company_id === 0 ) {
            wp_send_json_error( array( 'message' => 'IDs de contact ou d\'entreprise manquants.' ) );
        }

        // 1. Récupérer la liste actuelle (ex: "51459,12345,67890")
        $current_meta = get_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID, true );
        
        if ( empty( $current_meta ) ) {
            wp_send_json_error( array( 'message' => 'Aucune association trouvée pour ce contact.' ) );
        }

        // 2. Transformer en tableau et nettoyer
        $existing_ids = explode( ',', $current_meta );
        $existing_ids = array_filter( array_map( 'trim', $existing_ids ) );

        // 3. Vérifier si l'ID est présent avant de tenter la suppression
        if ( ! in_array( (string)$company_id, $existing_ids ) ) {
            wp_send_json_error( array( 'message' => 'Cette entreprise n\'est pas associée à ce contact.' ) );
        }

        // 4. Supprimer l'ID spécifique de la liste
        $updated_ids = array_diff( $existing_ids, array( (string)$company_id ) );

        // 5. Reconstruire la chaîne et mettre à jour
        $new_meta_value = implode( ',', $updated_ids );

        // Si la liste est vide, on peut soit laisser une chaîne vide, soit supprimer la clé
        if ( empty( $new_meta_value ) ) {
            $result = delete_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID );
        } else {
            $result = update_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID, $new_meta_value );
        }

        if ( false !== $result ) {
            wp_send_json_success( array( 
                'message' => sprintf( 
                    'Association de l\'entreprise ID %d retirée avec succès.', 
                    $company_id
                ) 
            ) );
        } else {
            wp_send_json_error( array( 'message' => 'Erreur lors de la mise à jour de l\'association.' ) );
        }

        wp_die();
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
        $company_ids = get_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID, false );
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
        <div id="ispag-add-company-sidebar" class="ispag-sidebar-overlay">  
            <div class="ispag-sidebar-content">
                <div class="ispag-modal-header">
                    <h3><?php _e( 'Add existing company', 'ispag-crm' ); ?></h3>
                    <span class="ispag-modal-close">×</span>
                </div>

                <!-- <div class="ispag-modal-tabs">
                    <button class="ispag-tab-modal active" data-tab="create-new"><?php _e( 'Create new', 'ispag-crm' ); ?></button>
                    <button class="ispag-tab-modal" data-tab="add-existing"><?php _e( 'Add existing', 'ispag-crm' ); ?></button>
                </div> -->

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
                                    echo '<p>' . __( 'Currently no linked company', 'ispag-crm' ). '</p>';
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
                        <p><?php _e( 'Company creation form...', 'ispag-crm' ); ?></p>
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
     * Gère l'association d'une entreprise à un contact via AJAX (ajout de meta).
     */
    public function ajax_associate_company_to_contact() {
        $contact_id = absint( filter_input( INPUT_POST, 'contact_id', FILTER_VALIDATE_INT ) );
        $company_ids = array_map( 'absint', (array) $_POST['company_ids'] ); // Utilisation de $_POST pour plus de fiabilité sur les tableaux

        if ( $contact_id === 0 || empty( $company_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing data.', 'ispag-crm' ) ) );
        }

        // 1. Récupérer la liste actuelle des entreprises du contact
        $current_meta = get_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID, true );
        
        // 2. Transformer en tableau propre
        $existing_ids = ! empty( $current_meta ) ? explode( ',', $current_meta ) : array();
        $existing_ids = array_filter( array_map( 'trim', $existing_ids ) );

        // 3. Fusionner avec les nouveaux IDs et supprimer les doublons
        $final_ids = array_unique( array_merge( $existing_ids, $company_ids ) );

        // 4. Sauvegarder la nouvelle chaîne
        $new_meta_value = implode( ',', $final_ids );
        $result = update_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID, $new_meta_value );

        wp_send_json_success( array( 
            'message' => __( 'Association successfully updated.', 'ispag-crm' ) 
        ) );
    }


    /**
     * Effectue la recherche d'entreprises non associées via AJAX, 
     * incluant désormais la ville de l'entreprise.
     */
    public function ajax_search_companies() {
        // 1. Sécurité
        if ( ! current_user_can( 'manage_order' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
            wp_die();
        }
        
        // 2. Récupération des entrées
        $search_term = sanitize_text_field( filter_input( INPUT_POST, 'search_term' ) );
        $contact_id  = absint( filter_input( INPUT_POST, 'contact_id', FILTER_VALIDATE_INT ) );
        
        // NOUVEAU : Récupération de la page (par défaut 1)
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;

        global $wpdb;
        $table_name_fournisseur = ISPAG_Crm_Company_Constants::TABLE_NAME;
        $table_name_postmeta = $wpdb->postmeta;
        $meta_key_city = ISPAG_Crm_Company_Constants::META_COMPANY_CITY;

        // IDs à exclure
        $company_ids_to_exclude = get_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID, false );
        $company_ids_to_exclude = array_filter( array_map( 'absint', (array) $company_ids_to_exclude ) );
        
        // Jointure pour la ville
        $join_sql = " LEFT JOIN {$table_name_postmeta} AS meta_ville ON T1.viag_id = meta_ville.post_id AND meta_ville.meta_key = '{$meta_key_city}' ";

        // Clauses WHERE
        $where_clauses = [];
        $params = [];
        
        if ( ! empty( $company_ids_to_exclude ) ) {
            $ids_list = implode( ',', $company_ids_to_exclude );
            $where_clauses[] = "T1.viag_id NOT IN ({$ids_list})";
        }

        if ( ! empty( $search_term ) ) {
            $like_term = '%' . $wpdb->esc_like( $search_term ) . '%';
            $where_clauses[] = "(T1.company_name LIKE %s OR meta_ville.meta_value LIKE %s)";
            $params[] = $like_term;
            $params[] = $like_term;
        }
        
        $where_sql = empty( $where_clauses ) ? '1=1' : implode( ' AND ', $where_clauses );
        
        // 3. Calcul du TOTAL (sans LIMIT) pour la pagination
        $sql_count = "SELECT COUNT(DISTINCT T1.viag_id) FROM {$table_name_fournisseur} AS T1 {$join_sql} WHERE {$where_sql}";
        if ( ! empty( $params ) ) {
            $total_items = $wpdb->get_var( $wpdb->prepare( $sql_count, ...$params ) );
        } else {
            $total_items = $wpdb->get_var( $sql_count );
        }

        // 4. Requête principale avec LIMIT et OFFSET
        $sql_base = "
            SELECT 
                T1.viag_id AS Id, T1.company_name AS Fournisseur, 
                meta_ville.meta_value AS Ville 
            FROM {$table_name_fournisseur} AS T1
            {$join_sql}
            WHERE {$where_sql} AND T1.is_active = 1
            ORDER BY T1.company_name ASC 
            LIMIT %d OFFSET %d
        ";
        
        // Ajout des paramètres de pagination à la fin du tableau de paramètres
        $final_params = $params;
        $final_params[] = $limit;
        $final_params[] = $offset;

        $results = $wpdb->get_results( $wpdb->prepare( $sql_base, ...$final_params ) );
        
        // 5. Calcul pour savoir s'il reste des éléments
        $has_more = ( $offset + count($results) ) < $total_items;

        // 6. Retour AJAX
        wp_send_json_success( array( 
            'count'     => $total_items, // Total global
            'companies' => $results,     // Les 10 résultats de la page actuelle
            'has_more'  => $has_more     // Booléen pour le bouton JS
        ) );
        wp_die();
    }

}
endif;