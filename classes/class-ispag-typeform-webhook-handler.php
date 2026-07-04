<?php

if ( ! defined( 'ISPAG_CRM_TYPEFORM_SECRET' ) ) {
    // À définir dans ton wp-config.php. 
    // Typeform permet de définir un "Secret" pour signer la requête (Header: Typeform-Signature)
    define( 'ISPAG_CRM_TYPEFORM_SECRET', '6e8f4b23a1c5d9e8f0b7a2d4c6e8f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7' ); 
}

class ISPAG_Typeform_Webhook_Handler {

    const ENDPOINT_NAMESPACE = 'ispag-crm/v1';
    const ENDPOINT_ROUTE     = '/typeform-satisfaction/';
    const LOG_PREFIX         = '[ISPAG Typeform Webhook] ';

    private $note_repository;

    public function __construct( $note_repo ) {
        $this->note_repository = $note_repo;
    }

    public function register_routes() {
        register_rest_route( self::ENDPOINT_NAMESPACE, self::ENDPOINT_ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook_request' ],
            'permission_callback' => '__return_true', // La vérification se fera dans le callback via la signature
        ]);
    }

    private function _log( $message, $data = null ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
//             error_log( self::LOG_PREFIX . $message );
//             if ( $data !== null ) error_log( print_r( $data, true ) );
        }
    }

    /**
     * Traite le payload de Typeform
     */
    public function handle_webhook_request( $request ) {
        $this->_log( 'DÉBUT TEST WEBHOOK' );
        $this->_log( 'Headers reçus :', $request->get_headers() );

        // 1. Vérification de la signature Typeform (Sécurité)
        $signature = $request->get_header( 'x-typeform-signature' );
        
        if ( empty( $signature ) ) {
            $signature = $request->get_header( 'typeform-signature' );
        }
        
        // Cas désespéré (via $_SERVER)
        if ( empty( $signature ) && isset( $_SERVER['HTTP_TYPEFORM_SIGNATURE'] ) ) {
            $signature = $_SERVER['HTTP_TYPEFORM_SIGNATURE'];
        }
        $payload   = $request->get_body();
        
        if ( ! $this->verify_signature( $signature, $payload ) ) {
            $this->_log( 'ERREUR : Signature invalide.' );
            return new WP_REST_Response( [ 'message' => 'Invalid signature' ], 403 );
        }

        $data = json_decode( $payload, true );
        $form_response = $data['form_response'] ?? [];

        // 2. Extraction des données
        // Dans Typeform, on récupère souvent l'email via un champ "hidden" ou une question
        $email = $this->extract_answer_by_type( $form_response, 'email' );
        
        // Exemple : récupérer une note (champ number ou opinion_scale)
        $score = $this->extract_answer_by_type( $form_response, 'number' );

        if ( empty( $email ) ) {
            $this->_log( 'ERREUR : Email non trouvé dans le formulaire.' );
            return new WP_REST_Response( [ 'message' => 'Email missing' ], 200 ); // 200 pour éviter que Typeform réessaie inutilement
        }

        // 3. Trouver l'utilisateur ISPAG
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            $this->_log( "Utilisateur non trouvé pour : {$email}" );
            return new WP_REST_Response( [ 'message' => 'User not found' ], 200 );
        }

        // 4. Création de la note CRM
        $note_data = new stdClass();
        $note_data->contact_id    = $user->ID;
        $note_data->activity_type = 'SURVEY_RESPONSE';
        $note_data->title         = 'Enquête satisfaction : ' . ($form_response['definition']['title'] ?? 'Typeform');
        $note_data->content       = $this->format_answers_for_crm( $form_response );
        $note_data->date_time     = current_time( 'mysql' );

        $result = $this->note_repository->create_note( $note_data );

        return new WP_REST_Response( [ 'success' => true, 'note_id' => $result ], 200 );
    }

    /**
     * Vérifie que le webhook vient bien de Typeform
     */
    private function verify_signature( $received_signature, $payload ) {
        if ( empty( $received_signature ) ) return false;

        // Typeform envoie "sha256=BASE64_HASH"
        $hash = 'sha256=' . base64_encode( hash_hmac( 'sha256', $payload, ISPAG_CRM_TYPEFORM_SECRET, true ) );
        
        return hash_equals( $hash, (string)$received_signature );
    }

    /**
     * Utilitaire pour extraire une réponse par son type
     */
    private function extract_answer_by_type( $form_response, $type ) {
        foreach ( $form_response['answers'] as $answer ) {
            if ( $answer['type'] === $type ) {
                return $answer[$type];
            }
        }
        return null;
    }

    /**
     * Formate toutes les questions/réponses pour le contenu de la note
     */
    private function format_answers_for_crm( $form_response ) {
        $output = "Résultats du questionnaire :\n\n";
        $questions = [];
        
        // On mappe les IDs de questions aux titres
        foreach ( $form_response['definition']['fields'] as $field ) {
            $questions[$field['id']] = $field['title'];
        }

        foreach ( $form_response['answers'] as $answer ) {
            $field_id = $answer['field']['id'];
            $question_text = $questions[$field_id] ?? 'Question';
            $value = '';

            switch ( $answer['type'] ) {
                case 'text': $value = $answer['text']; break;
                case 'number': $value = $answer['number']; break;
                case 'choice': $value = $answer['choice']['label']; break;
                case 'email': $value = $answer['email']; break;
            }

            $output .= "- {$question_text} : {$value}\n";
        }
        return $output;
    }
}