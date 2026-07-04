<?php
/**
 * Gère toutes les requêtes AJAX liées aux contacts ISPAG (notamment la sauvegarde des champs éditables).
 */
class ISPAG_Contact_Ajax_Handler {

    // Constantes pour les noms des meta-keys
    const META_LEAD_FUNCTION        = 'ispag_lead_function';
    const META_COMPANY_ID           = 'ispag_company_id';
    const META_LEAD_STATUS          = 'ispag_lead_status';
    const META_LEAD_LINKEDIN_PAGE   = 'ispag_linkedin_page';
    const META_LIFECYCLE_PHASE      = 'ispag_contact_lifecycle_phase'; 
    const META_OWNER                = 'ispag_owner';
    const META_USER_ROLE            = 'wp_user_role';
    const META_HEALTH_CHECK_IGNORE  = 'ispag_ignore_health_check';
    private $table_priorities;

    public function __construct() {
        $this->table_priorities = ISPAG_Crm_Contact_Constants::TABLE_PRIORITIES_NAME; 

        // Enregistre le hook AJAX pour l'action 'save_contact_field'
        add_action( 'wp_ajax_save_contact_field', array( $this, 'ajax_save_contact_field' ) );
        add_action( 'wp_ajax_ispag_create_contact', array( $this, 'ispag_handle_create_contact') );
        add_action( 'wp_ajax_ispag_check_email_exists', array( $this, 'ispag_check_email_exists') );
        add_action('wp_ajax_save_contact_avatar', array( $this, 'ispag_save_contact_avatar') );
    }

    
    /**
     * Point d'entrée pour la sauvegarde AJAX d'un champ de contact, avec historisation.
     */
    public function ajax_save_contact_field() {
        $log_file = WP_CONTENT_DIR . '/ispag_contact_ajax.log';
        // error_log("--- -DEBUT EXECUTION  ajax_save_contact_field : " . date('Y-m-d H:i:s') . " ---\n", 3, $log_file);

        global $wpdb;

        $table_owners    = ISPAG_Crm_Contact_Constants::TABLE_CONTACT_OWNER; 

        // 1. VÉRIFICATIONS PRÉLIMINAIRES
        // ... (Vérifications de sécurité et permissions inchangées) ...

        $contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
        $field_name = isset( $_POST['field_name'] ) ? sanitize_key( $_POST['field_name'] ) : '';
        $new_value  = isset( $_POST['new_value'] ) ? sanitize_text_field( wp_unslash( $_POST['new_value'] ) ) : ''; 
        $department_id = isset( $_POST['department_id'] ) ? sanitize_key( $_POST['department_id'] ) : '';

        // error_log('contact_id ' . $contact_id . " ---\n", 3, $log_file);
        // error_log('field_name ' . $field_name . " ---\n", 3, $log_file);
        // error_log('new_value ' . $new_value . " ---\n", 3, $log_file);

        if ( $contact_id === 0 || empty( $field_name ) ) {
            wp_send_json_error( array( 'message' => 'Paramètres invalides (ID ou nom de champ manquant).' ) );
        }

        $old_value = '';
        if ( in_array( $field_name, array('user_email', 'user_login', 'display_name') ) ) {
            // Pour les champs natifs de l'objet WP_User
            $contact = get_user_by( 'ID', $contact_id );
            if ( $contact && isset($contact->$field_name) ) {
                $old_value = $contact->$field_name;
                // error_log('old_value ' . $old_value . " ---\n", 3, $log_file);
            }
        } else {
            // Pour les meta-champs
            $old_value = get_user_meta( $contact_id, $field_name, true );
            // error_log('meta-champs old_value ' . $old_value . " ---\n", 3, $log_file);
        }
        
        // Si l'ancienne valeur est identique à la nouvelle, on quitte sans loguer.
        if ( $old_value === $new_value ) {
            // On s'assure de renvoyer le succès pour que le JS puisse quitter le mode édition.
            wp_send_json_success( array( 
                'message' => 'Valeur inchangée. Aucune sauvegarde effectuée.',
                'new_value' => $new_value 
            ) );
        }

        // 2. LOGIQUE DE SAUVEGARDE VIA SWITCH
        $success = false;
        $response_data = array();

        switch ( $field_name ) {
            
            case 'department_owner':
                $new_owner_id = absint( $new_value );
                $now = current_time('mysql');

                // --- ÉTAPE A : Archiver l'ancien owner actif ---
                // On passe le statut de 'active' à 'inactive' et on note la date de fin
                $wpdb->update(
                    $table_owners,
                    array( 
                        'status'        => 'inactive', 
                        'unassigned_at' => $now 
                    ),
                    array( 
                        'contact_id'     => $contact_id, 
                        'department_key' => $department_id,
                        'status'         => 'active' // Important : on ne touche qu'à celui qui est actif
                    ),
                    array( '%s', '%s' ),
                    array( '%d', '%s', '%s' )
                );

                // --- ÉTAPE B : Insérer le nouveau si nécessaire ---
                if ( !empty( $new_owner_id ) ) {
                    $result = $wpdb->insert(
                        $table_owners,
                        array(
                            'contact_id'     => $contact_id,
                            'user_id'        => $new_owner_id,
                            'department_key' => $department_id,
                            'assigned_at'    => $now,
                            'status'         => 'active'
                        ),
                        array( '%d', '%d', '%s', '%s', '%s' )
                    );
                    $display_name = get_the_author_meta( 'display_name', $new_value );
                    $response_data['display_value'] = esc_html( $display_name );
                    $success = true;
                } else {
                    // Si la nouvelle valeur est vide, on a juste supprimé (archivé) l'owner actuel
                    $success = true;
                }
                break;
            // --- CHAMPS NATIFS WORDPRESS ---
            case 'user_email':
                if ( is_email( $new_value ) ) {
                    $result = wp_update_user( array( 'ID' => $contact_id, 'user_email' => $new_value ) );
                    
                    if ( ! is_wp_error( $result ) ) {
                        $success = true;
                        $response_data['display_value'] = esc_html( $new_value );
                    } else {
                        wp_send_json_error( array( 'message' => 'Erreur WP: ' . implode(', ', $result->get_error_messages()) ) );
                    }
                } else {
                    wp_send_json_error( array( 'message' => 'Format d\'email invalide.' ) );
                }
                break;
            
            // --- CHAMPS META AVEC SÉLECTION (META_COMPANY_ID, META_OWNER, META_LEAD_STATUS, META_LIFECYCLE_PHASE) ---
            // La logique pour la sauvegarde des meta-champs (update_user_meta) reste ici.
            // ... (TOUT LE CODE DE VOS CASES META-CHAMPS) ...
            case 'billing_phone':
                // 1. Sanitisaton/Nettoyage pour un numéro de téléphone
                // Permet les chiffres, espaces, tirets et le signe plus (+)
                $sanitized_value = preg_replace( '/[^\d\s\-\(\)\+]/', '', $new_value );

                if ( ! empty( $sanitized_value ) || empty( $new_value ) ) {
                    // Si la valeur est nettoyée ou vide (pour l'effacer)
                    $result = update_user_meta( $contact_id, $field_name, $sanitized_value );

                    if ( $result !== false ) {
                        $success = true;
                        
                        // Préparation de la valeur d'affichage (potentiellement un lien cliquable)
                        $display_value = esc_html( $sanitized_value );
                        
                        if ( ! empty( $sanitized_value ) ) {
                            // Nettoyage supplémentaire pour le lien 'tel:'
                            $tel_link = 'tel:' . preg_replace( '/[^\d\+]/', '', $sanitized_value );
                            $display_value = '<a href="' . esc_attr($tel_link) . '">' . $display_value . '</a>';
                        }
                        
                        $response_data['display_value'] = $display_value;
                        
                    } else {
                        // Échec de la mise à jour (devrait être rare car déjà géré par l'ancienne/nouvelle valeur)
                        wp_send_json_error( array( 'message' => 'Échec de la mise à jour de la meta (billing_phone).' ) );
                    }
                } else {
                    wp_send_json_error( array( 'message' => 'Format de téléphone invalide après nettoyage.' ) );
                }
                break;
            case self::META_USER_ROLE:
                // 1. Validation de base : s'assurer que la nouvelle valeur est bien une chaîne non vide
                $role_key = sanitize_key( $new_value );
                
                // 2. Vérification que la clé de rôle existe dans WordPress, sauf si c'est 'none'
                global $wp_roles;
                if ( ! isset( $wp_roles ) ) {
                    $wp_roles = new WP_Roles();
                }
                
                // Le rôle 'none' est notre indicateur pour ne pas définir de rôle principal ou le retirer
                $is_valid_role = ( $role_key === 'none' || isset( $wp_roles->role_names[ $role_key ] ) );

                if ( $is_valid_role ) {
                    
                    // La fonction wp_update_user est utilisée pour modifier les propriétés principales de l'utilisateur.
                    $update_data = ['ID' => $contact_id];
                    
                    if ( $role_key === 'none' ) {
                        // Si 'none' est sélectionné, on définit le rôle sur la valeur par défaut de WP ('subscriber')
                        // ou on peut choisir de ne rien faire, mais la modification d'un rôle est plus explicite.
                        // Pour des raisons de sécurité, nous allons définir le rôle à 'subscriber'
                        // si nous ne voulons pas de rôle spécifique, sauf si l'utilisateur n'en avait déjà aucun.
                        $update_data['role'] = 'subscriber'; // Rôle par défaut de WP
                        
                    } else {
                        // Définir le nouveau rôle
                        $update_data['role'] = $role_key;
                    }

                    $result = wp_update_user( $update_data );

                    if ( ! is_wp_error( $result ) ) {
                        
                        $success = true;
                        
                        // Récupérer le nom d'affichage du rôle sauvegardé pour la réponse AJAX
                        $saved_role_key = ( $role_key === 'none' ) ? 'subscriber' : $role_key;
                        
                        if ( isset( $wp_roles->role_names[ $saved_role_key ] ) ) {
                            $role_display_name = translate_user_role( $wp_roles->role_names[ $saved_role_key ] );
                        } else {
                            $role_display_name = $saved_role_key;
                        }

                        $response_data['display_value'] = esc_html( $role_display_name );
                        
                    } else {
                        // Erreur de sauvegarde
                        wp_send_json_error( array( 'message' => 'Error updating role : ' . $result->get_error_message() ) );
                    }
                    
                } else {
                    // Le rôle fourni n'existe pas
                    wp_send_json_error( array( 'message' => 'Unknown or invalid user role.' ) );
                }
                break;
            case self::META_LEAD_FUNCTION:
                // 1. Sauvegarde de la meta-donnée
                $result = update_user_meta( $contact_id, self::META_LEAD_FUNCTION, $new_value );
                
                if ( $result !== false ) {
                    $success = true;
                    
                    
                    // 2. Préparation de la valeur d'affichage (utilise la propriété 'Fournisseur')
                    $response_data['display_value'] = esc_html( $new_value );
                    
                } else {
                    wp_send_json_error( array( 'message' => 'Échec de la mise à jour de la meta Fonction (problème de DB ou valeur inchangée).' ) );
                }
                
                break;
            case ISPAG_Crm_Contact_Constants::META_LEAD_LINKEDIN_PAGE:
                // 1. Sauvegarde de la meta-donnée
                $result = update_user_meta( $contact_id, ISPAG_Crm_Contact_Constants::META_LEAD_LINKEDIN_PAGE, $new_value );
                
                if ( $result !== false ) {
                    $success = true;
                    
                    
                    // 2. Préparation de la valeur d'affichage (utilise la propriété 'Fournisseur')
                    $response_data['display_value'] = esc_html( $new_value );
                    
                } else {
                    wp_send_json_error( array( 'message' => 'Échec de la mise à jour de la meta Fonction (problème de DB ou valeur inchangée).' ) );
                }
                
                break;
            case self::META_COMPANY_ID:
                // La valeur doit être un ID de compagnie/fournisseur (ou 0 si géré comme tel)
                $company_id_to_save = absint( $new_value );
                
                // 1. Récupération de la map des compagnies valides
                $companies_map = $this->get_all_companies();
                
                // Si vous souhaitez permettre l'option "Aucune compagnie" (ID 0), ajoutez-la ici:
                $companies_map[0] = (object)['ID' => 0, 'Fournisseur' => 'Aucune compagnie'];
                ksort($companies_map); // Assurer que 0 est en premier

                // 2. Validation de l'ID
                if ( isset( $companies_map[ $company_id_to_save ] ) ) {
                    
                    // 3. Sauvegarde de la meta-donnée
                    $result = update_user_meta( $contact_id, self::META_COMPANY_ID, $company_id_to_save );
                    
                    if ( $result !== false ) {
                        $success = true;
                        $company_data = $companies_map[ $company_id_to_save ];
                        
                        // 4. Préparation de la valeur d'affichage (utilise la propriété 'Fournisseur')
                        $response_data['display_value'] = esc_html( $company_data->Fournisseur );
                        
                    } else {
                        wp_send_json_error( array( 'message' => 'Échec de la mise à jour de la meta COMPANY (problème de DB ou valeur inchangée).' ) );
                    }
                    
                } else {
                    wp_send_json_error( array( 'message' => 'ID de compagnie inconnu ou invalide.' ) );
                }
                
                break;
            case self::META_OWNER:
                // error_log("META_OWNER  ---\n", 3, $log_file);
                // La valeur doit être un ID d'utilisateur (ou 0 pour "Aucun propriétaire")
                $owner_id_to_save = absint( $new_value );
                
                // 1. Récupérer la map des propriétaires (y compris ceux de 'get_all_owners' + ID 0)
                // Nous avons besoin de la map complète, comme elle est utilisée dans le rendu du shortcode.
                
                // a) Récupérer les propriétaires valides (ID > 0)
                $owners_lookup = $this->get_all_owners();
                $owners_sequential = $this->get_all_owners();

                // 2. CONVERSION : Ré-indexer le tableau pour utiliser l'ID de l'utilisateur comme clé.
                $validation_map = [];
                foreach ($owners_sequential as $owner_object) {
                    // La clé devient l'ID de l'utilisateur (1, 512, 1477, etc.)
                    $validation_map[$owner_object->ID] = $owner_object;
                }
                // 3. Ajouter l'option "Aucun propriétaire" (ID 0) manuellement pour la validation.
                $validation_map[0] = (object)['display_name' => '— Aucun propriétaire —'];

                // error_log("Owners Validation Map (ID => Object) " . print_r($validation_map, true) . " ---", 3, $log_file);
    
                // 4. Validation de l'ID. Maintenant, l'accès par $owner_id_to_save fonctionne.
                if ( isset( $validation_map[ $owner_id_to_save ] ) ) {
                    
                    // Sauvegarde de la meta-donnée
                    // error_log("contact_id : {$contact_id} - META_OWNER " . self::META_OWNER . " owner_id_to_save : {$owner_id_to_save} ---", 3, $log_file);
                    
                    $result = update_user_meta( $contact_id, self::META_OWNER, $owner_id_to_save );
                    
                    if ( $result !== false ) {
                        $success = true;
                        $owner_data = $validation_map[ $owner_id_to_save ];
                        
                        // Préparation de la valeur d'affichage
                        $response_data['display_value'] = esc_html( $owner_data->display_name );
                        
                    } else {
                        wp_send_json_error( array( 'message' => 'Échec de la mise à jour de la meta (problème de DB ou valeur inchangée).' ) );
                    }
                    
                } else {
                    // ID non trouvé dans la liste des propriétaires valides.
                    wp_send_json_error( array( 'message' => 'ID de propriétaire inconnu ou invalide.' ) );
                }
                
                break;
            case self::META_LEAD_STATUS:
                // ... (Logique inchangée : validation et update_user_meta) ...
                $statuses = $this->get_lead_statuses_map();
                if ( isset( $statuses[$new_value] ) ) {
                    $result = update_user_meta( $contact_id, self::META_LEAD_STATUS, $new_value );
                    if ( $result !== false ) {
                        $success = true;
                        $status_data = $statuses[$new_value];
                        $response_data['display_value'] = $this->render_badge_html( 
                            $status_data->label, 
                            $status_data->bg_color, 
                            $status_data->text_color 
                        );
                    }
                } else {
                    wp_send_json_error( array( 'message' => 'Statut de Lead inconnu ou invalide.' ) );
                }
                break;
            case self::META_LIFECYCLE_PHASE:
                 // ... (Logique inchangée : validation et update_user_meta) ...
                $phases = $this->get_lifecycle_phases_for_display();
                if ( isset( $phases[$new_value] ) ) {
                    $result = update_user_meta( $contact_id, self::META_LIFECYCLE_PHASE, $new_value );
                    if ( $result !== false ) {
                        $success = true;
                        $phase_data = $phases[$new_value];
                        $response_data['display_value'] = $this->render_badge_html( 
                            $phase_data->phase_label, 
                            $phase_data->bg_color, 
                            $phase_data->text_color 
                        );
                    }
                } else {
                    wp_send_json_error( array( 'message' => 'Phase de cycle de vie inconnue ou invalide.' ) );
                }
                break;
            // ...
            case self::META_LEAD_LINKEDIN_PAGE:
                $result = update_user_meta( $contact_id, self::META_LEAD_LINKEDIN_PAGE, $new_value );
                $response_data['display_value'] = esc_html($new_value );
                $success = true;
                break;
            case self::META_HEALTH_CHECK_IGNORE:
                $result = update_user_meta( $contact_id, self::META_HEALTH_CHECK_IGNORE, $new_value );
                $response_data['display_value'] = esc_html($new_value );
                $success = true;
                break;
            case ISPAG_Crm_Contact_Constants::USER_BIRTHDAY:
                $result = update_user_meta( $contact_id, ISPAG_Crm_Contact_Constants::USER_BIRTHDAY, $new_value );
                $response_data['display_value'] = $new_value ;
                $success = true;
                break;
            case ISPAG_Crm_Contact_Constants::PRIORITY_LEVEL:
                $user_id = get_current_user_id();
                $table_priorities = $this->table_priorities;

                // On définit l'entité. Ici, puisque tu es dans le script company, on force 'company'
                $entity_type = 'contact';

                $updated = $wpdb->query( $wpdb->prepare(
                    "INSERT INTO $table_priorities (user_id, entity_id, entity_type, priority_level) 
                    VALUES (%d, %d, %s, %s) 
                    ON DUPLICATE KEY UPDATE priority_level = %s",
                    $user_id,            // %d
                    $contact_id,         // %d (ton entity_id)
                    $entity_type,        // %s
                    $new_value, // %s
                    $new_value  // %s (pour le UPDATE)
                ));
                $success = true;
                $response_data['display_value'] = $this->render_badge_html( 
                    $new_value, 
                    '#fff',
                    '#000'
                );
                break;
            // --- NOUVEAU : GESTION DU PRÉNOM ET NOM ---
            case 'first_name':
            case 'last_name':
                // 1. Mise à jour via wp_update_user (plus propre pour les champs natifs profils)
                $result = wp_update_user( array( 
                    'ID'         => $contact_id, 
                    $field_name  => $new_value 
                ) );

                if ( ! is_wp_error( $result ) ) {
                    $success = true;
                    
                    // Si la valeur est vide, on renvoie le placeholder pour le JS
                    if ( empty( $new_value ) ) {
                        $placeholder = ( $field_name === 'first_name' ) ? 'Prénom' : 'Nom';
                        $response_data['display_value'] = '<span class="ispag-placeholder">' . $placeholder . '</span>';
                    } else {
                        $response_data['display_value'] = esc_html( $new_value );
                    }

                    // OPTIONNEL : Recalculer le display_name complet pour mettre à jour le petit label <small>
                    $updated_user = get_userdata( $contact_id );
                    $response_data['full_display_name'] = $updated_user->display_name;
                    
                } else {
                    wp_send_json_error( array( 'message' => 'Erreur lors de la mise à jour : ' . $result->get_error_message() ) );
                }
                break;
            default:
                wp_send_json_error( array( 'message' => 'Champ inconnu: ' . $field_name ) );
                break;
        }

        // 3. ENVOI DE LA RÉPONSE ET LOGGING
        if ( $success ) {
            
            // *******************************************************
            // * NOUVEAU : ENREGISTREMENT DE LA MODIFICATION DANS L'AUDIT
            // *******************************************************
            $this->log_contact_change( 
                $contact_id, 
                $field_name, 
                $old_value, 
                $new_value     
            );

            $response_data['display_value'] .= '<span class="edit-icon">✏️</span>';

            wp_send_json_success( array_merge( $response_data, array( 
                'message'   => 'Champ mis à jour et logué avec succès.',
                'new_value' => $new_value
            ) ) );
        } else {
            wp_send_json_error( array( 'message' => 'Échec de la mise à jour du champ. La valeur est peut-être inchangée ou invalide.' ) );
        }
        // error_log("--- FIN EXECUTION  ajax_save_contact_field : " . date('Y-m-d H:i:s') . " ---\n", 3, $log_file);
        
        wp_die(); 
    }
    
    // ------------------------------------------------------------------
    // --- NOUVELLE MÉTHODE D'AUDIT ---
    // ------------------------------------------------------------------

    /**
     * Enregistre un événement de modification dans la table d'audit.
     */
    private function log_contact_change( $contact_id, $field_name, $old_value, $new_value ) {
        global $wpdb;

        // Si l'ancienne valeur est identique à la nouvelle (vérification de sécurité supplémentaire, bien que gérée au début)
        if ( $old_value === $new_value ) {
            return;
        }

        // Récupère l'ID de l'utilisateur WordPress qui a effectué l'action
        $current_user_id = get_current_user_id();

        // Insère les données dans la table d'audit
        $wpdb->insert(
            $wpdb->prefix . 'ispag_contact_audit',
            array(
                'contact_id'  => $contact_id,
                'user_id'     => $current_user_id,
                'field_name'  => $field_name,
                // Utilisation des valeurs brutes pour l'historique
                'old_value'   => $old_value, 
                'new_value'   => $new_value,
                'change_date' => current_time( 'mysql' ),
            ),
            array(
                '%d', // contact_id
                '%d', // user_id
                '%s', // field_name
                '%s', // old_value
                '%s', // new_value
                '%s'  // change_date
            )
        );
    }
    
    // ------------------------------------------------------------------
    // --- MÉTHODES UTILITAIRES DE DONNÉES (Basées sur DB) ---
    // ------------------------------------------------------------------

    /**
     * Génère le HTML pour un badge coloré.
     */
    private function render_badge_html( $label, $bg_color, $text_color = '#fff' ) {
        return sprintf(
            '<span class="ispag-status-badge" style="background-color: %s; color: %s;">%s</span>',
            esc_attr( $bg_color ),
            esc_attr( $text_color ),
            esc_html( $label )
        );
    }
    
    /**
     * Récupère la map complète des statuts de lead depuis la DB.
     * La clé du tableau est le slug (status_key).
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
     * Récupère la map des phases de cycle de vie depuis la DB.
     * La clé du tableau est le phase_key.
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
     * Simule la récupération des compagnies/fournisseurs (clés par ID).
     * (Remplacez par votre vraie logique de CPT ou table custom)
     */
    private function get_all_companies() {
        global $wpdb;
        $table_name_fournisseur = $wpdb->prefix . 'achats_fournisseurs';
        // Récupère toutes les entreprises, clé Id, et ordonne par nom (Fournisseur)
        return $wpdb->get_results( "SELECT Id, Fournisseur, compagnyDomain, NumTel FROM {$table_name_fournisseur} ORDER BY Fournisseur ASC", OBJECT_K ); 
    }

    /**
     * Récupère la map de tous les utilisateurs pouvant être propriétaires de contacts 
     * (Commercial, Administrateur, Vente ISPAG), clés par ID.
     * * @return array Map (user_id => user_object)
     */
    public function get_all_owners() {
        return get_users( array( 
            'role__in' => array('administrator', 'commercial', 'vente_ispag'), 
            'fields' => array( 'ID', 'display_name' ),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'key' => 'ID',
        ));
    }



    public function ispag_handle_create_contact() { 
        // LOG : Début de la tentative
        // error_log('ISPAG CRM: Début création contact');

        // 1. Sécurité
        if ( ! check_ajax_referer('ispag_new_contact_nonce', 'nonce', false) ) {
            // error_log('ISPAG CRM: Échec du nonce');
            wp_send_json_error(['message' => 'Sécurité : Nonce invalide']);
        }
        
        // 2. Récupération des données (Attention aux noms dans $_POST)
        // Vérifie que ton HTML utilise bien name="email" et pas name="c_email"
        $email      = sanitize_email($_POST['email']); 
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name  = sanitize_text_field($_POST['last_name']);
        $phone      = sanitize_text_field($_POST['phone']);
        $owner_id   = intval($_POST['owner_id']);
        $job_title  = sanitize_text_field($_POST['lead_function']);

        // error_log("ISPAG CRM: Tentative pour $email ($first_name $last_name)");

        if (empty($email)) {
            wp_send_json_error(['message' => 'L\'email est obligatoire']);
        }

        // 3. Logique d'entreprise
        $domain = $this->ispag_get_domain_from_email($email);
        $company_id = 0;
        if ($domain && !$this->ispag_is_free_email($domain)) {
            $company_id = $this->ispag_find_or_create_company_by_domain($domain);
            // error_log("ISPAG CRM: Société ID trouvée/créée : $company_id");
        }

        // 4. Insertion
        $contact_repo = new ISPAG_Crm_Contacts_Repository();
        $contact_id = $contact_repo->insert([
            'email'      => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'phone'      => $phone,
            'owner_id'   => $owner_id,
            'job_title'  => $job_title,
            'company_id' => $company_id,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        if ($contact_id) {
            // error_log("ISPAG CRM: Succès ! ID: $contact_id");

            // --- AJOUTER CECI POUR LA SYNCHRO BAIKAL ---
            if (class_exists('ISPAG_Baikal_Sync')) {
                $baikal = new ISPAG_Baikal_Sync();
                $baikal->sync_contact_to_baikal($contact_id);
                // error_log("ISPAG CRM: Déclenchement manuel synchro Baïkal pour ID $contact_id");
            }
            // -------------------------------------------
            
            // Nouvelle redirection : url_du_site/contact/ID/
            $redirect_url = home_url("/contact/{$contact_id}/");

            wp_send_json_success([
                'message'      => 'Contact créé avec succès',
                'redirect_url' => $redirect_url
            ]);
        } else {
            // error_log('ISPAG CRM: Erreur SQL lors de l\'insert');
            wp_send_json_error(['message' => 'Erreur lors de la création en base de données']);
        }
    }
    /**
     * Extrait le domaine d'un email
     */
    public function ispag_get_domain_from_email($email) {
        $parts = explode('@', $email);
        return (!empty($parts[1])) ? strtolower($parts[1]) : false;
    }

    /**
     * Liste des domaines gratuits à ignorer
     */
    public function ispag_is_free_email($domain) {
        $free_domains = ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'wanadoo.fr', 'orange.fr', 'bluewin.ch'];
        return in_array($domain, $free_domains);
    }

    /**
     * Cherche une entreprise par domaine ou la crée avec un VIAG_ID provisoire
     */
    public function ispag_find_or_create_company_by_domain($domain) {
        global $wpdb;
        $table_companies = ISPAG_Crm_Company_Constants::TABLE_NAME;

        // 1. On cherche si elle existe déjà (on récupère le viag_id)
        $existing_viag_id = $wpdb->get_var($wpdb->prepare(
            "SELECT viag_id FROM $table_companies WHERE compagny_domain = %s LIMIT 1", 
            $domain
        ));

        if ($existing_viag_id) {
            // error_log("ISPAG CRM: Entreprise déjà existante. VIAG_ID: $existing_viag_id");
            return $existing_viag_id;
        }

        // 2. Génération d'un VIAG_ID provisoire (Plage 90000)
        // On cherche le plus haut ID provisoire actuel entre 90000 et 99999
        $last_provisional = $wpdb->get_var(
            "SELECT MAX(viag_id) FROM $table_companies WHERE viag_id >= 90000 AND viag_id < 100000"
        );

        // Si c'est la première, on commence à 90001, sinon on incrémente
        $new_viag_id = $last_provisional ? (int)$last_provisional + 1 : 90001;

        // 3. Création de la "coquille"
        $company_name = ucfirst(explode('.', $domain)[0]);
        
        $insert_data = [
            'company_name'    => $company_name,
            'compagny_domain' => $domain,
            'viag_id'         => $new_viag_id,
            'is_active'       => 1,
            'created_at'      => current_time('mysql')
        ];

        $result = $wpdb->insert($table_companies, $insert_data);

        if ($result === false) {
            // error_log("ISPAG CRM: Erreur SQL lors de la création de l'entreprise : " . $wpdb->last_error);
            return 0;
        }

        // error_log("ISPAG CRM: Nouvelle entreprise créée : $company_name avec VIAG_ID provisoire : $new_viag_id");

        // On retourne le viag_id (car c'est lui qui sert de lien dans ton CRM)
        return $new_viag_id;
    }

    public function ispag_check_email_exists() {
        check_ajax_referer('ispag_new_contact_nonce', 'nonce');

        $email = sanitize_email($_POST['email']);
        if (empty($email)) wp_send_json_error();

        $contact_repo = new ISPAG_Crm_Contacts_Repository();
        $exists = $contact_repo->get_by_email($email); // Assure-toi que cette méthode existe

        if ($exists) {
            wp_send_json_success([
                'exists'  => true,
                'name'    => $exists->first_name . ' ' . $exists->last_name,
                'view_url' => home_url("/contact/{$exists->id}/")
            ]);
        } else {
            wp_send_json_success(['exists' => false]);
        }
    }

    public function ispag_save_contact_avatar() {
        // 1. Vérification de sécurité (Nonce)
        check_ajax_referer('ispag_new_contact_nonce', 'nonce');

        // 2. Récupération des données
        $user_id       = isset($_POST['contact_id']) ? absint($_POST['contact_id']) : 0;
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;

        if (!$user_id || !$attachment_id) {
            wp_send_json_error(['message' => 'Missing data.']);
        }

        // 3. Mise à jour de la meta
        // On utilise 'ispag_avatar_id' pour stocker l'ID du média WordPress
        $meta_avatar = ISPAG_Crm_Contact_Constants::USER_AVATAR;
        $updated = update_user_meta($user_id, $meta_avatar, $attachment_id);

        // Optionnel : Si tu veux aussi mettre à jour la meta standard de certains plugins d'avatar
        update_user_meta($user_id, 'wp_user_avatar', $attachment_id);

        if ($updated !== false || get_user_meta($user_id, $meta_avatar, true) == $attachment_id) {
            wp_send_json_success(['message' => 'Avatar mis à jour avec succès.']);
        } else {
            wp_send_json_error(['message' => 'Erreur lors de la mise à jour en base de données.']);
        }
    }
}