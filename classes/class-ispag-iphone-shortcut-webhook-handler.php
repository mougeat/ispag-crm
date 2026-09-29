<?php

if ( ! defined( 'ISPAG_CRM_SHORTCUT_SECRET' ) ) {
    // Définissez cette clé dans votre wp-config.php
    define( 'ISPAG_CRM_SHORTCUT_SECRET', 'ISPAG-Shortcut-7x2W-k8P9-mQz5-L6vR' ); 
}

class ISPAG_Iphone_Shortcut_Webhook_Handler {

    const ENDPOINT_NAMESPACE = 'ispag-crm/v1';
    const ENDPOINT_ROUTE     = '/shortcut-note/';
    const LOG_PREFIX         = '[ISPAG iPhone Webhook] ';

    private $contact_repository;
    private $note_repository;

    public function __construct( $contact_repo, $note_repo ) {
        $this->contact_repository = $contact_repo;
        $this->note_repository    = $note_repo;
    }

    public function register_routes() {
        register_rest_route( self::ENDPOINT_NAMESPACE, self::ENDPOINT_ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_shortcut_request' ],
            'permission_callback' => [ $this, 'verify_shortcut_request' ],
        ]);
    }

    private function _log( $message, $data = null ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG === true ) {
            error_log( self::LOG_PREFIX . $message );
            if ( $data !== null ) {
                error_log( print_r( $data, true ) );
            }
        }
    }

    /**
     * Vérification de sécurité via un paramètre "secret" dans l'URL du Raccourci
     */
    public function verify_shortcut_request( $request ) {
        $received_secret = $request->get_param( 'secret' );
        
        if ( empty( $received_secret ) || $received_secret !== ISPAG_CRM_SHORTCUT_SECRET ) {
            $this->_log( 'ERREUR DE SÉCURITÉ : Secret invalide ou manquant.' );
            return new WP_Error( 'shortcut_auth_fail', 'Not authorized', [ 'status' => 401 ] );
        }

        return true;
    }

    /**
     * Traite les données envoyées par l'iPhone
     */
    public function handle_shortcut_request( $request ) {
        $this->_log( 'Réception d\'une note depuis l\'iPhone.' );
        
        $data = $request->get_json_params();
        $this->_log( 'Payload reçu :', $data );

        // 1. Extraction et assainissement
        $type         = sanitize_text_field( $data['type'] ?? 'CALL' );
        $email        = sanitize_email( $data['email'] ?? '' );
        $phone        = sanitize_text_field( $data['phone'] ?? '' );
        $contact_name = sanitize_text_field( $data['contact'] ?? 'Inconnu' );
        $content      = sanitize_textarea_field( $data['note'] ?? '' );
        $data = $request->get_json_params();

        // On récupère le résultat du menu iOS
        $outcome = sanitize_text_field( $data['outcome'] ?? 'connected' );

        // On vérifie que c'est une valeur autorisée (optionnel mais propre)
        $allowed_outcomes = ['busy', 'connected', 'left_live_message', 'left_voicemail', 'no_answer', 'wrong_number'];
        if ( ! in_array( $outcome, $allowed_outcomes ) ) {
            $outcome = 'connected';
        }

    

        $contact_id = 0;

        // 2. IDENTIFICATION DU CONTACT
        // A. Tentative par Email
        if ( ! empty( $email ) ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                $contact_id = $user->ID;
            }
        }

        // B. Tentative par Téléphone (si email non trouvé ou vide)
        if ( ! $contact_id && ! empty( $phone ) ) {
            $contact_id = $this->find_contact_by_phone( $phone );
        }

        // C. Si toujours rien, on s'arrête
        if ( ! $contact_id ) {
            $this->_log( "Contact non trouvé (Email: $email, Tel: $phone). Arrêt." );
            return new WP_REST_Response( [ 'message' => 'Contact introuvable dans le CRM.' ], 404 );
        }

        // 3. MISE À JOUR DU TÉLÉPHONE (si vide dans le CRM)
        if ( ! empty( $phone ) ) {
            $meta_key = ISPAG_Crm_Contact_Constants::META_LEAD_PHONE;
            $existing_phone = get_user_meta( $contact_id, $meta_key, true );
            
            if ( empty( $existing_phone ) ) {
                update_user_meta( $contact_id, $meta_key, $phone );
                $this->_log( "Téléphone mis à jour pour le contact ID: $contact_id" );
            }
        }

        // 4. RECHERCHE DES RELATIONS (Entreprise et Deals)
        // On récupère l'entreprise liée au contact
        $company_id = get_user_meta( $contact_id, 'ispag_company_id', true );

        // Recherche dans les commandes (projets en cours) -> retourne les hubspot_deal_id
        $matched_deals = $this->find_matching_deal( $contact_id, $company_id, $content );

        // Recherche dans le CRM des offres -> retourne les deal_group_ref
        $matched_offers = $this->find_matching_offer( $contact_id, $company_id, $content );

        // --- FUSION DES DEUX LISTES DANS DEAL_ID ---
        $all_ids = [];

        if ( ! empty( $matched_deals ) ) {
            $all_ids = array_merge( $all_ids, explode( ',', $matched_deals ) );
        }

        if ( ! empty( $matched_offers ) ) {
            $all_ids = array_merge( $all_ids, explode( ',', $matched_offers ) );
        }

        // Nettoyage : suppression des espaces, des doublons et des valeurs vides
        $all_ids = array_unique( array_filter( array_map( 'trim', $all_ids ) ) );
        $final_deal_id_string = ! empty( $all_ids ) ? implode( ',', $all_ids ) : null;

        // 4. Préparation de l'objet Note
        $note_data = new stdClass();
        $note_data->contact_id    = $contact_id;
        $note_data->user_id       = 1; // ID de Cyril ou de l'utilisateur par défaut
        $note_data->company_id    = !empty($company_id) ? (string)$company_id : null;
        $note_data->deal_id       = $final_deal_id_string;
        $note_data->outcome       = $outcome;
        $note_data->activity_type = strtoupper( $type );
        $note_data->title         = "Note iPhone : " . $type . " avec " . $contact_name;
        $note_data->content       = $content . "\n\n---\nContact : $contact_name\nTel : $phone";
        $note_data->date_time     = current_time( 'mysql' );

        // 5. Enregistrement
        $result = $this->note_repository->create_note( $note_data );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'message' => 'Erreur enregistrement note.' ], 500 );
        }

        return new WP_REST_Response( [ 'message' => 'Note enregistrée', 'id' => $result ], 200 );
    }

    /**
     * Recherche un contact par son numéro de téléphone en gérant les variantes (+41 / 0 / espaces)
     */
    private function find_contact_by_phone( $phone ) {
        if ( empty( $phone ) ) {
            return 0;
        }

        // 1. Nettoyage de base
        $clean_phone = trim( $phone );
        $variants = [ $clean_phone ];

        // 2. Gestion spécifique Suisse / International (+41 vs 0)
        // Si le numéro commence par +41, on ajoute la version avec 0
        if ( strpos( $clean_phone, '+41' ) === 0 ) {
            $local = '0' . substr( $clean_phone, 3 );
            $variants[] = $local;
            $variants[] = str_replace( ' ', '', $local ); // sans espaces
        } 
        // Si le numéro commence par 0, on ajoute la version avec +41
        elseif ( strpos( $clean_phone, '0' ) === 0 ) {
            $international = '+41' . substr( $clean_phone, 1 );
            $variants[] = $international;
            $variants[] = str_replace( ' ', '', $international ); // sans espaces
        }

        // Version sans aucun espace pour toutes les variantes
        foreach ( $variants as $v ) {
            $variants[] = str_replace( ' ', '', $v );
        }

        $variants = array_unique( array_filter( $variants ) );
        
        $this->_log( "Recherche téléphone. Variantes testées : [" . implode(', ', $variants) . "]" );

        // 3. Construction d'une requête WP_User_Query avec une relation OR sur les méta-valeurs
        $meta_query = [ 'relation' => 'OR' ];
        foreach ( $variants as $variant ) {
            $meta_query[] = [
                'key'     => ISPAG_Crm_Contact_Constants::META_LEAD_PHONE,
                'value'   => $variant,
                'compare' => '='
            ];
        }

        $args = [
            'meta_query' => $meta_query,
            'number'     => 1,
            'fields'     => 'ID'
        ];
        
        $users = get_users( $args );

        if ( ! empty( $users ) ) {
            $this->_log( "Contact trouvé par téléphone ! ID : " . $users[0] );
            return $users[0];
        }

        $this->_log( "Aucun contact trouvé pour les variantes de téléphone testées." );
        return 0;
    }
    
    /**
     * Recherche tous les deals correspondants et retourne une liste d'IDs séparés par des virgules.
     */
    private function find_matching_deal( $contact_id, $company_id, $content ) {
        global $wpdb;
        
        $table_name = 'wor9711_achats_liste_commande'; 

        $this->_log("=== DÉBUT RECHERCHE DEALS (Multiples) ===");
        $this->_log("Paramètres d'entrée -> Contact ID: " . var_export($contact_id, true) . ", Company ID: " . var_export($company_id, true));
        $this->_log("Contenu brut de la note : " . var_export($content, true));

        if ( empty( $company_id ) && empty( $contact_id ) ) {
            $this->_log("ARRÊT : Ni Company ID ni Contact ID fournis. Impossible de filtrer la table.");
            return null;
        }

        // Construction de la requête
        $sql = "SELECT hubspot_deal_id, AssociatedCompanyID, AssociatedContactIDs, ObjetCommande FROM {$table_name} WHERE 1=1";
        $params = [];

        if ( ! empty( $company_id ) ) {
            $sql .= " AND AssociatedCompanyID = %d";
            $params[] = $company_id;
        }

        if ( ! empty( $contact_id ) ) {
            $sql .= " AND (AssociatedContactIDs LIKE %s OR AssociatedContactIDs = %s)";
            $params[] = '%' . $wpdb->esc_like( (string)$contact_id ) . '%';
            $params[] = (string)$contact_id;
        }

        $sql .= " ORDER BY TimestampDateCommande DESC LIMIT 20";

        $prepared_sql = $wpdb->prepare( $sql, $params );
        $this->_log("Requête SQL générée : " . $prepared_sql);

        $deals = $wpdb->get_results( $prepared_sql );

        if ( empty( $deals ) ) {
            $this->_log("RÉSULTAT SQL : Aucun projet/deal trouvé en base pour ce Contact ID / Company ID.");
            return null;
        }

        $this->_log("RÉSULTAT SQL : " . count( $deals ) . " deal(s) potentiel(s) trouvé(s) en base.");

        $cleaned_content = mb_strtolower( trim( $content ) );
        $matching_deal_ids = []; // Tableau pour stocker tous les IDs valides

        foreach ( $deals as $index => $deal ) {
            $this->_log("--- Analyse du deal [{$index}] (HubSpot Deal ID: {$deal->hubspot_deal_id}) ---");
            $this->_log("  -> AssociatedCompanyID en base : " . $deal->AssociatedCompanyID);
            $this->_log("  -> AssociatedContactIDs en base : " . $deal->AssociatedContactIDs);
            $this->_log("  -> ObjetCommande en base : " . var_export($deal->ObjetCommande, true));

            if ( empty( $deal->ObjetCommande ) ) {
                $this->_log("  -> Ignoré : ObjetCommande est vide.");
                continue;
            }

            $objet_commande = mb_strtolower( trim( $deal->ObjetCommande ) );
            $deal_matched = false;

            // Test 1 : Correspondance textuelle directe
            if ( strpos( $cleaned_content, $objet_commande ) !== false || strpos( $objet_commande, $cleaned_content ) !== false ) {
                $this->_log("  -> [SUCCÈS] Match trouvé par sous-chaîne (Test 1) !");
                $deal_matched = true;
            } else {
                $this->_log("  -> [ÉCHEC] Pas de correspondance directe entre la note et l'ObjetCommande.");
            }

            // Test 2 : Analyse par mots-clés (uniquement si le test 1 n'a pas déjà validé)
            if ( ! $deal_matched ) {
                $words = array_filter( explode( ' ', $objet_commande ), function($word) {
                    return mb_strlen( $word ) > 3; // Ignore les petits mots
                } );

                if ( ! empty( $words ) ) {
                    $match_count = 0;
                    $matched_words = [];
                    foreach ( $words as $word ) {
                        if ( strpos( $cleaned_content, $word ) !== false ) {
                            $match_count++;
                            $matched_words[] = $word;
                        }
                    }
                    
                    $threshold = count( $words ) * 0.25;
                    $this->_log("  -> Test Mots-clés : {$match_count} mots trouvés sur un seuil requis de {$threshold}. Mots trouvés : [" . implode(', ', $matched_words) . "]");

                    if ( $match_count >= $threshold ) {
                        $this->_log("  -> [SUCCÈS] Match trouvé par similarité de mots-clés (Test 2) !");
                        $deal_matched = true;
                    }
                } else {
                    $this->_log("  -> Pas assez de mots significatifs (>3 lettres) dans l'ObjetCommande pour le test de mots-clés.");
                }
            }

            // Si le deal a validé l'un des tests, on l'ajoute à la liste
            if ( $deal_matched ) {
                $matching_deal_ids[] = (string) $deal->hubspot_deal_id;
            }
        }

        // Si on a trouvé un ou plusieurs deals correspondants
        if ( ! empty( $matching_deal_ids ) ) {
            $unique_ids = array_unique( $matching_deal_ids ); // Évite les doublons potentiels
            $result_string = implode( ',', $unique_ids );
            $this->_log("=== FIN RECHERCHE : Deals correspondants validés : [{$result_string}] ===");
            return $result_string;
        }

        $this->_log("=== FIN RECHERCHE : Aucun deal n'a validé les tests de texte ===");
        return null;
    }


    /**
     * Recherche des offres correspondantes dans wor9711_ispag_deals_list 
     * et retourne une liste de deal_group_ref séparés par des virgules.
     */
    private function find_matching_offer( $contact_id, $company_id, $content ) {
        global $wpdb;
        
        $table_name = 'wor9711_ispag_deals_list'; 

        $this->_log("=== DÉBUT RECHERCHE OFFRES (Deals List) ===");
        $this->_log("Paramètres d'entrée -> Contact ID: " . var_export($contact_id, true) . ", Company ID: " . var_export($company_id, true));

        if ( empty( $company_id ) && empty( $contact_id ) ) {
            $this->_log("ARRÊT : Ni Company ID ni Contact ID fournis pour les offres.");
            return null;
        }

        // Construction de la requête pour la table des offres
        $sql = "SELECT deal_group_ref, associated_company_id, associated_contact_ids, project_name FROM {$table_name} WHERE 1=1";
        $params = [];

        if ( ! empty( $company_id ) ) {
            $sql .= " AND associated_company_id = %d";
            $params[] = $company_id;
        }

        if ( ! empty( $contact_id ) ) {
            $sql .= " AND (associated_contact_ids LIKE %s OR associated_contact_ids = %s)";
            $params[] = '%' . $wpdb->esc_like( (string)$contact_id ) . '%';
            $params[] = (string)$contact_id;
        }

        $sql .= " ORDER BY date_creation DESC LIMIT 20";

        $prepared_sql = $wpdb->prepare( $sql, $params );
        $this->_log("Requête SQL Offres générée : " . $prepared_sql);

        $offers = $wpdb->get_results( $prepared_sql );

        if ( empty( $offers ) ) {
            $this->_log("RÉSULTAT SQL : Aucune offre trouvée en base pour ce Contact / Entreprise.");
            return null;
        }

        $this->_log("RÉSULTAT SQL : " . count( $offers ) . " offre(s) potentielle(s) trouvée(s).");

        $cleaned_content = mb_strtolower( trim( $content ) );
        $matching_refs = [];

        foreach ( $offers as $index => $offer ) {
            $this->_log("--- Analyse de l'offre [{$index}] (Ref: {$offer->deal_group_ref}) ---");
            $this->_log("  -> project_name en base : " . var_export($offer->project_name, true));

            if ( empty( $offer->project_name ) ) {
                $this->_log("  -> Ignoré : project_name est vide.");
                continue;
            }

            $project_name = mb_strtolower( trim( $offer->project_name ) );
            $offer_matched = false;

            // Test 1 : Correspondance textuelle directe
            if ( strpos( $cleaned_content, $project_name ) !== false || strpos( $project_name, $cleaned_content ) !== false ) {
                $this->_log("  -> [SUCCÈS] Match trouvé par sous-chaîne sur project_name !");
                $offer_matched = true;
            } else {
                // Test 2 : Analyse par mots-clés
                $words = array_filter( explode( ' ', $project_name ), function($word) {
                    return mb_strlen( $word ) > 3;
                } );

                if ( ! empty( $words ) ) {
                    $match_count = 0;
                    foreach ( $words as $word ) {
                        if ( strpos( $cleaned_content, $word ) !== false ) {
                            $match_count++;
                        }
                    }
                    
                    $threshold = count( $words ) * 0.25;
                    if ( $match_count >= $threshold ) {
                        $this->_log("  -> [SUCCÈS] Match trouvé par similarité de mots-clés sur project_name !");
                        $offer_matched = true;
                    }
                }
            }

            // Si l'offre matche, on récupère son deal_group_ref
            if ( $offer_matched && ! empty( $offer->deal_group_ref ) ) {
                $matching_refs[] = (string) $offer->deal_group_ref;
            }
        }

        if ( ! empty( $matching_refs ) ) {
            $unique_refs = array_unique( $matching_refs );
            $result_string = implode( ',', $unique_refs );
            $this->_log("=== FIN RECHERCHE OFFRES : Refs validées : [{$result_string}] ===");
            return $result_string;
        }

        $this->_log("=== FIN RECHERCHE OFFRES : Aucun match textuel validé ===");
        return null;
    }
}