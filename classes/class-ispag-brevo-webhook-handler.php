<?php

if ( ! defined( 'ISPAG_CRM_BREVO_SECRET' ) ) {
    define( 'ISPAG_CRM_BREVO_SECRET', 'aG1xZjdYek15V2t3c3d5RGl0V3lU' ); 
}

class ISPAG_Brevo_Webhook_Handler {

    const ENDPOINT_NAMESPACE = 'ispag-crm/v1';
    const ENDPOINT_ROUTE     = '/brevo-webhook/';
    const LOG_PREFIX         = '[ISPAG Brevo Webhook] ';

    private $contact_repository;
    private $note_repository;

    public function __construct( $contact_repo, $note_repo ) {
        $this->contact_repository = $contact_repo;
        $this->note_repository    = $note_repo;
    }

    public function register_routes() {
        register_rest_route( self::ENDPOINT_NAMESPACE, self::ENDPOINT_ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook_request' ],
            'permission_callback' => [ $this, 'verify_webhook_request' ],
        ]);
    }

    /**
     * Système de log dédié vers wp-content/webhook_brevo.log
     */
    private function _log( $message, $data = null ) {
        $log_file = WP_CONTENT_DIR . '/webhook_brevo.log';
        $timestamp = date_i18n( 'Y-m-d H:i:s' );
        
        $output = "[{$timestamp}] " . self::LOG_PREFIX . $message . PHP_EOL;

        if ( $data !== null ) {
            $output .= print_r( $data, true ) . PHP_EOL;
        }

        // Ajoute un séparateur visuel si le message contient "DÉBUT"
        if ( strpos($message, 'DÉBUT') !== false ) {
            $output = str_repeat('-', 50) . PHP_EOL . $output;
        }

        // file_put_contents( $log_file, $output, FILE_APPEND );
    }

    /**
     * Étape 1 : Vérification de sécurité
     */
    public function verify_webhook_request( $request ) {
        $received_secret = $request->get_param( 'secret' );

        if ( empty( $received_secret ) || $received_secret !== ISPAG_CRM_BREVO_SECRET ) {
            $this->_log( 'ERREUR DE SÉCURITÉ : Secret invalide ou manquant.' );
            return new WP_Error( 
                'brevo_security_fail', 
                'Accès non autorisé.', 
                [ 'status' => 401 ] 
            );
        }

        return true;
    }

    /**
     * Étape 2 : Traitement du Webhook
     */
    public function handle_webhook_request( $request ) {
        $this->_log( 'DÉBUT du traitement.' );
        
        $data = $request->get_json_params();
        $this->_log( 'Payload complet reçu :', $data );

        $event = $data['event'] ?? null;
        $accepted_events = [
            'campaign_sent',
            'delivered',
            'delivered_for_transactional', 
            'processed'
        ];

        if ( ! in_array( $event, $accepted_events ) ) {
            $this->_log( "Événement ignoré (type : {$event})." );
            return new WP_REST_Response( [ 'message' => 'Ignored' ], 200 );
        }

        // --- 1. Extraction et typage ---
        $email = sanitize_email( $data['email'] ?? '' );
        $subject = sanitize_text_field( $data['subject'] ?? 'Sans objet' );
        $template_id = isset($data['template_id']) ? (int)$data['template_id'] : null;
        $is_transactional = (bool)$template_id;

        if ( $is_transactional ) {
            $activity_title = $subject;
            $activity_type = 'EMAIL_TRANSACTIONAL';
            $this->_log( "Classification : Email Transactionnel (ID: {$template_id})" );
        } else {
            $activity_title = 'Campagne e-mail : ' . sanitize_text_field( $data['campaignName'] ?? 'Inconnue' );
            $activity_type = 'EMAIL_CAMPAIGN';
            $this->_log( "Classification : Campagne Marketing" );
        }

        if ( empty( $email ) ) {
            $this->_log( 'ERREUR : Email manquant dans les données Brevo.' );
            return new WP_REST_Response( [ 'message' => 'Email missing' ], 400 );
        }

        // --- 2. Identification du contact ---
        $user = get_user_by( 'email', $email ); 

        if ( ! $user ) {
            $this->_log( "STOP : Aucun utilisateur WordPress trouvé pour {$email}." );
            return new WP_REST_Response( [ 'message' => 'User not found' ], 200 );
        }
        
        $this->_log( "Utilisateur WP identifié : ID {$user->ID}" );

        // --- 3. Création de la Note CRM ---
        $activity_content = sprintf(
            "Événement Brevo : %s. Objet du mail : %s.",
            $event,
            $subject
        );

        $note_data = new stdClass();
        $note_data->contact_id      = $user->ID;
        $note_data->activity_type   = $activity_type;
        $note_data->title           = $activity_title;
        $note_data->content         = $activity_content;
        $note_data->date_time       = isset($data['date']) ? $data['date'] : current_time('mysql'); 

        $this->_log( 'Tentative d\'insertion en base de données...' );
        $result = $this->note_repository->create_note( $note_data );

        if ( is_wp_error( $result ) ) {
            $this->_log( 'ERREUR DB : ' . $result->get_error_message() );
            return new WP_REST_Response( [ 'message' => 'Database error' ], 500 );
        }

        $this->_log( "SUCCÈS : Activité enregistrée. Note ID : {$result}" );

        return new WP_REST_Response( [ 
            'message' => 'Success', 
            'note_id' => $result, 
            'contact_id' => $user->ID 
        ], 200 );
    }
}