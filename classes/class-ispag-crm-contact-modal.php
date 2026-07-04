<?php
// Fichier : includes/crm/repositories/class-ispag-crm-contact-modal.php

if ( ! class_exists( 'ISPAG_Crm_Contact_Modal' ) ) :
class ISPAG_Crm_Contact_Modal {

    public function __construct() {
        add_action('wp_ajax_ispag_remove_contact_association', [ $this, 'ajax_remove_contact_association' ]);
        add_action( 'wp_ajax_ispag_render_add_contact_modal', array( $this, 'ajax_render_add_contact_modal' ) );
        add_action( 'wp_ajax_ispag_search_contacts', array( $this, 'ajax_search_contacts' ) );
        add_action( 'wp_ajax_ispag_associate_contacts_to_company', array( $this, 'ajax_associate_contacts_to_company' ) );
    } 

    public function ajax_remove_contact_association() {
        $contact_id = absint( filter_input( INPUT_POST, 'contact_id', FILTER_VALIDATE_INT ) );
        $company_id = absint( filter_input( INPUT_POST, 'company_id', FILTER_VALIDATE_INT ) );
        
        if ( $contact_id === 0 || $company_id === 0 ) {
            wp_send_json_error( array( 'message' => 'IDs manquants.' ) );
        }

        $deleted = delete_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID, $company_id );

        if ( $deleted ) {
            wp_send_json_success( array( 'message' => 'Association retirée.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Échec de la suppression.' ) );
        }
        wp_die();
    }

    public function ajax_render_add_contact_modal() {
        $company_id = absint( filter_input( INPUT_POST, 'company_id', FILTER_VALIDATE_INT ) );
        $deal_group_ref = isset($_POST['deal_group_ref']) ? sanitize_text_field($_POST['deal_group_ref']) : '';
        
        if ( $company_id === 0 ) {
            wp_send_json_error( array( 'message' => __( 'Missing company ID.', 'ispag-crm' ) ) );
        }
        // error_log('[ajax_render_add_contact_modal] company_id : ' . $company_id);
        echo $this->render_add_contact_modal( $company_id, $deal_group_ref );
        // error_log('[ajax_render_add_contact_modal] ' . $this->render_add_contact_modal( $company_id, $deal_group_ref ));
        wp_die();
    }

    public function render_add_contact_modal( $company_id, $deal_group_ref ) {
        // error_log('[render_add_contact_modal] START');
        ob_start();
        ?> 
        <div id="ispag-add-contact-sidebar" class="ispag-sidebar-overlay">  
            <div class="ispag-sidebar-content">
                <div class="ispag-modal-header">
                    <h3><?php _e( 'Add existing contact', 'ispag-crm' ); ?></h3>
                    <span class="ispag-modal-close">×</span>
                </div>

                <!-- <div class="ispag-modal-tabs">
                    <button class="ispag-tab-modal active" data-tab="add-existing"><?php _e( 'Add existing', 'ispag-crm' ); ?></button>
                    <button class="ispag-tab-modal" data-tab="create-new"><?php _e( 'Create new', 'ispag-crm' ); ?></button>
                </div> -->

                <div class="ispag-modal-body">
                    <input type="hidden" id="modal_company_id" value="<?php echo $company_id; ?>">
                    
                    <div id="tab-add-existing" class="ispag-tab-modal-pane active">
                        <div class="ispag-search-wrapper">
                            <input type="text" id="contact-search-input" placeholder="<?php _e( 'Search contact name...', 'ispag-crm' ); ?>" />
                            <button id="contact-search-btn" class="ispag-btn"><span class="dashicons dashicons-search"></span></button>
                        </div>

                        <div id="contact-search-results">
                            <hr>
                            <p class="results-count">0 <?php _e( 'Contacts', 'ispag-crm' ); ?></p>
                            <div class="contact-list-container">
                                </div>
                        </div>
                    </div>

                    <div id="tab-create-new" class="ispag-tab-modal-pane">
                        <p><?php _e( 'Registration form coming soon...', 'ispag-crm' ); ?></p>
                    </div>
                </div>

                <div class="ispag-modal-footer">
                    <button class="ispag-btn ispag-btn-secondary ispag-modal-cancel"><?php _e( 'Cancel', 'ispag-crm' ); ?></button>
                    <button class="ispag-btn ispag-btn-primary ispag-modal-save-contact" data-company-id="<?php echo $company_id; ?>" data-deal-group-ref="<?php echo $deal_group_ref; ?>">
                        <?php _e( 'Link Selected Contacts', 'ispag-crm' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_search_contacts() {
        $search_term = isset($_POST['search_term']) ? sanitize_text_field( $_POST['search_term'] ) : '';
        $company_id  = absint( $_POST['company_id'] );

        // --- FILTRE DES CONTACTS ACTIFS UNIQUEMENT ---
        $active_filter = array(
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
        );
        
        $args = array(
            'number'         => 15,
            'search_columns' => array( 'display_name', 'user_email' ),
            'meta_query'     => array(
                'relation' => 'AND',
                $active_filter // Le filtre est bien là
            )
        );

        if ( empty( $search_term ) ) {
            // --- CAS 1 : On AJOUTE (avec []) la condition de l'entreprise ---
            $args['meta_query'][] = array(
                'key'     => ISPAG_Crm_Company_Constants::META_COMPANY_ID,
                'value'   => $company_id,
                'compare' => '='
            );
        } else {
            // --- CAS 2 : Recherche active ---
            $excluded_ids = get_users(array(
                'meta_key'   => ISPAG_Crm_Company_Constants::META_COMPANY_ID,
                'meta_value' => $company_id,
                'fields'     => 'ID'
            ));

            $args['search'] = '*' . $search_term . '*';
            if ( ! empty( $excluded_ids ) ) {
                $args['exclude'] = $excluded_ids;
            }
            // Ici aussi, WP_User_Query utilisera le meta_query (active_filter) défini plus haut
        }

        $user_query = new WP_User_Query( $args );
        $users = $user_query->get_results();

        $formatted_users = array();
        foreach ( $users as $user ) {
            $formatted_users[] = array(
                'id'       => $user->ID,
                'name'     => $user->display_name,
                'email'    => $user->user_email,
                'is_linked' => empty($search_term) // Indicateur pour le JS si besoin
            );
        }

        wp_send_json_success( array( 
            'count'    => count($formatted_users), 
            'contacts' => $formatted_users,
            'mode'     => empty($search_term) ? 'current' : 'search' // Pour debug dans la console
        ) );
    }
 
    public function ajax_associate_contacts_to_company() {
        global $wpdb;

        // 1. Log des données brutes reçues
        // error_log("[ISPAG DEBUG] --- Début Association Contact ---");
        // error_log("[ISPAG DEBUG] POST data: " . print_r($_POST, true));

        $company_id     = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;
        $deal_group_ref = isset($_POST['deal_group_ref']) ? sanitize_text_field($_POST['deal_group_ref']) : '';
        $contact_ids    = isset($_POST['contact_ids']) ? array_map('absint', (array) $_POST['contact_ids']) : array();

        // 2. Vérification des données critiques
        if ( ! $company_id || empty( $contact_ids ) ) {
            // error_log("[ISPAG ERROR] Données incomplètes : CompanyID=$company_id, ContactsCount=" . count($contact_ids));
            wp_send_json_error( array( 'message' => 'Données incomplètes (ID Société ou Contacts).' ) );
        }

        // --- CAS 1 : Mise à jour des DEALS (si ref existe) ---
        if ( ! empty( $deal_group_ref ) ) {
            $table_deals = ISPAG_Crm_Deal_Constants::TABLE_NAME;
            
            // error_log("[ISPAG DEBUG] Tentative de mise à jour des deals pour REF: $deal_group_ref");

            $existing_deals = $wpdb->get_results( $wpdb->prepare(
                "SELECT Id, associated_contact_ids FROM {$table_deals} WHERE deal_group_ref = %s",
                $deal_group_ref
            ) );

            if ( ! empty( $existing_deals ) ) {
                // error_log("[ISPAG DEBUG] Deals trouvés : " . count($existing_deals));
                
                foreach ( $existing_deals as $deal ) {
                    $current_ids = ! empty( $deal->associated_contact_ids ) ? explode( ',', $deal->associated_contact_ids ) : array();
                    $current_ids = array_filter( array_map( 'trim', $current_ids ) );

                    $updated_ids = array_unique( array_merge( $current_ids, $contact_ids ) );
                    $new_ids_string = implode( ',', $updated_ids );

                    $result = $wpdb->update(
                        $table_deals,
                        array( 'associated_contact_ids' => $new_ids_string ),
                        array( 'Id' => $deal->Id ),
                        array( '%s' ),
                        array( '%d' )
                    );

                    if ( false === $result ) {
                        // error_log("[ISPAG ERROR] Échec update Deal ID {$deal->Id} : " . $wpdb->last_error);
                    } else {
                        // error_log("[ISPAG DEBUG] Succès update Deal ID {$deal->Id}. Nouveaux IDs: $new_ids_string");
                    }
                }
            } else {
                // error_log("[ISPAG WARNING] Aucun deal trouvé en base pour la REF: $deal_group_ref");
            }
        } else {
            // error_log("[ISPAG INFO] Aucun deal_group_ref reçu. Mise à jour des deals ignorée.");
        }

        // --- CAS 2 : Mise à jour des USER META (Société) ---
        // error_log("[ISPAG DEBUG] Mise à jour User Meta pour " . count($contact_ids) . " contacts.");
        foreach ( $contact_ids as $contact_id ) {
            // 1. Récupérer la valeur actuelle (chaîne de caractères)
            $current_meta = get_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID, true );
            
            // 2. Transformer en tableau et nettoyer (enlever les espaces et les entrées vides)
            $company_list = ! empty( $current_meta ) ? explode( ',', $current_meta ) : array();
            $company_list = array_filter( array_map( 'trim', $company_list ) );

            // 3. Ajouter le nouvel ID s'il n'existe pas encore dans la liste
            if ( ! in_array( (string)$company_id, $company_list ) ) {
                $company_list[] = (string)$company_id;
            }

            // 4. Re-transformer en chaîne séparée par des virgules et mettre à jour
            $new_meta_value = implode( ',', $company_list );
            update_user_meta( $contact_id, ISPAG_Crm_Company_Constants::META_COMPANY_ID, $new_meta_value );
        }

        // error_log("[ISPAG DEBUG] --- Fin Association Contact ---");
        wp_send_json_success( array( 'message' => 'Liaison effectuée avec succès.' ) );
    }
}
endif;