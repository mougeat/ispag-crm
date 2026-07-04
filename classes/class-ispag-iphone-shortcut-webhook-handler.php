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
            // error_log( self::LOG_PREFIX . $message );
            if ( $data !== null ) {
                // error_log( print_r( $data, true ) );
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
            return new WP_Error( 'shortcut_auth_fail', 'Non autorisé', [ 'status' => 401 ] );
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

        // 4. Préparation de l'objet Note
        $note_data = new stdClass();
        $note_data->contact_id    = $contact_id;
        $note_data->user_id       = 1; // ID de Cyril ou de l'utilisateur par défaut
        $note_data->company_id    = !empty($company_id) ? (string)$company_id : null;
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
     * Recherche un contact par son numéro de téléphone dans les métas
     */
    private function find_contact_by_phone( $phone ) {
        // On effectue une recherche meta. 
        // Note : si tes numéros sont stockés avec des formats variés (+33, 06, etc.)
        // il faudra peut-être normaliser la recherche.
        $args = [
            'meta_key'   => ISPAG_Crm_Contact_Constants::META_LEAD_PHONE,
            'meta_value' => $phone,
            'number'     => 1,
            'fields'     => 'ID'
        ];
        
        $users = get_users( $args );
        return ! empty( $users ) ? $users[0] : 0;
    }
}