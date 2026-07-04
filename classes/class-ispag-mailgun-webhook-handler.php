<?php

if ( ! defined( 'ISPAG_CRM_MAILGUN_SECRET' ) ) {
    define( 'ISPAG_CRM_MAILGUN_SECRET', 'aG1xZjdYek15V2t3c3d5RGl0V3lU' ); 
}

class ISPAG_Mailgun_Webhook_Handler {

    const ENDPOINT_NAMESPACE = 'ispag-crm/v1';
    const ENDPOINT_ROUTE     = '/mail-log/';
    const LOG_PREFIX         = '[ISPAG-DEBUG] ';

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
     * Log personnalisé qui écrit systématiquement dans wp-content/debug.log
     */
    private function _log( $message, $data = null ) {
        $output = self::LOG_PREFIX . $message;
        if ( $data !== null ) {
            $output .= " | DATA: " . print_r( $data, true );
        }
        error_log( $output );
    }

    public function verify_webhook_request( $request ) {
        $received_secret = $request->get_param( 'secret' );
        
        // Log de la tentative de connexion
        $this->_log( "Tentative de connexion Webhook. Secret reçu: " . ($received_secret ? 'OUI' : 'NON') );

        if ( empty( $received_secret ) || $received_secret !== ISPAG_CRM_MAILGUN_SECRET ) {
            $this->_log( 'ERREUR SÉCURITÉ : Secret incorrect ou manquant.' );
            return new WP_Error( 'mailgun_auth_fail', 'Unauthorized', [ 'status' => 401 ] );
        }
        return true;
    }

    public function handle_webhook_request( $request ) {
        $this->_log( '--- DÉBUT TRAITEMENT MAILGUN ---' );
        $params = $request->get_params();

        // LOG DES PARAMÈTRES CLÉS
        $this->_log( 'Headers essentiels :', [
            'sender' => $params['sender'] ?? 'NON DÉFINI',
            'To'     => $params['To'] ?? 'NON DÉFINI',
            'subject'=> $params['subject'] ?? 'SANS OBJET'
        ]);

        $sender     = sanitize_email( $params['sender'] ?? '' ); 
        $subject    = sanitize_text_field( $params['subject'] ?? 'Sans objet' );
        $body_html  = $params['body-html'] ?? '';
        $body_plain = $params['body-plain'] ?? '';
        $raw_to     = $params['To'] ?? '';

        // 1. DÉTECTION DU MODE (TRANSFERT OU DIRECT)
        $is_forward = ( 
            strpos( strtolower($raw_to), 'log@ispag-asp.com' ) !== false || 
            strpos( strtolower($raw_to), 'log@mg.ispag-asp.com' ) !== false 
        );
        
        $this->_log( 'Mode détection : ' . ($is_forward ? 'TRANSFERT' : 'DIRECT') );

        $client_to = '';
        if ( $is_forward ) {
            // Recherche d'emails dans le corps du mail
            preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $body_plain, $emails_found);
            $this->_log( 'Emails trouvés dans le body-plain :', $emails_found[0] );
            
            $excluded = [strtolower($sender), 'log@ispag-asp.com', 'log@mg.ispag-asp.com', 'cyril@ispag-asp.com'];
            foreach ( $emails_found[0] as $email ) {
                $clean_e = strtolower(trim($email));
                if ( ! in_array($clean_e, $excluded) ) {
                    $client_to = sanitize_email($clean_e);
                    break;
                }
            }
            $this->_log( 'Email client identifié via transfert : ' . ($client_to ?: 'AUCUN') );
        } else {
            if ( preg_match( '/<([^>]+)>/', $raw_to, $matches ) ) {
                $client_to = sanitize_email( $matches[1] );
            } else {
                $client_to = sanitize_email( $raw_to );
            }
            $this->_log( 'Email client identifié via "To" direct : ' . $client_to );
        }

        // 2. NETTOYAGE DU CONTENU
        if ( ! empty( $body_html ) ) {
            $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $body_html);
            $content = preg_replace('/<o:[^>]*>.*?<\/o:[^>]*>/is', '', $content);
            $content = preg_replace('/<v:[^>]*>.*?<\/v:[^>]*>/is', '', $content);
            $content = preg_replace('/<w:[^>]*>.*?<\/w:[^>]*>/is', '', $content);
            $content = preg_replace('/<img[^>]+>/i', '', $content);
            $content = wp_kses_post( $content );
            $content = trim( $content );
        } else {
            $content = wpautop( esc_html( $body_plain ) );
        }

        // 3. IDENTIFICATION DU DEAL / PROJET
        $deal_ref = null;
        $metadata = $this->parse_metadata( $body_plain );
        if ( ! empty( $metadata['deal_id'] ) ) {
            $deal_ref = $metadata['deal_id'];
            $this->_log( "Deal ID trouvé dans les métadonnées : " . $deal_ref );
        } else {
            if ( preg_match( '/(OF\d{2}-\d{5})/', $subject, $deal_matches ) ) {
                $deal_ref = $deal_matches[1];
                $this->_log( "Deal trouvé dans le sujet (OF) : " . $deal_ref );
            } elseif ( preg_match( '/KST\d+\/(\d+)/i', $subject, $proj_matches ) ) {
                $deal_ref = $this->get_deal_ref_by_project_num( $proj_matches[1] );
                $this->_log( "Deal trouvé via numéro de projet KST : " . $deal_ref );
            }
        }

        // 4. RECHERCHE DES UTILISATEURS DANS LE CRM
        $user_crm = get_user_by( 'email', $sender );
        $user_id  = $user_crm ? $user_crm->ID : 1;

        $client_user = get_user_by( 'email', $client_to );

        if ( ! $client_user ) {
             $this->_log( 'ÉCHEC : Aucun utilisateur WordPress trouvé pour l\'email : ' . $client_to );
             return new WP_REST_Response( [ 'message' => 'Client inconnu dans la base' ], 200 );
        }

        // 5. CRÉATION DE LA NOTE
        $this->_log( "SUCCÈS : Enregistrement de la note pour Client ID " . $client_user->ID );
        $media_ids = $this->handle_attachments();

        $note_data = new stdClass();
        $note_data->contact_id    = $client_user->ID;
        $note_data->user_id       = $user_id; 
        $note_data->company_id    = $metadata['company_id'] ?? null;
        $note_data->deal_id       = $deal_ref; 
        $note_data->activity_type = 'EMAIL';
        $note_data->is_task       = ! empty( $metadata['due_date'] ) ? 1 : 0;
        $note_data->due_date      = $metadata['due_date'] ?? null;
        $note_data->title         = $subject;
        $note_data->content       = $content; 
        $note_data->media_ids     = $media_ids;

        $result = $this->note_repository->create_note( $note_data );

        if ( is_wp_error( $result ) ) {
            $this->_log( 'ERREUR lors de create_note : ' . $result->get_error_message() );
            return new WP_REST_Response( [ 'message' => 'Erreur SQL' ], 500 );
        }

        $this->_log( '--- FIN DE TRAITEMENT (Note ID: '.$result.') ---' );
        return new WP_REST_Response( [ 'success' => true, 'note_id' => $result ], 200 );
    }

    private function handle_attachments() {
        if ( empty( $_FILES ) ) return null;
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        $attachment_ids = [];
        $blacklist = ['image001.png', 'image002.png', 'logo.png', 'signature.png'];

        foreach ( $_FILES as $key => $file ) {
            if ( strpos( $key, 'attachment-' ) !== false ) {
                if ( in_array(strtolower($file['name']), $blacklist) ) continue;
                if ( $file['size'] < 10240 ) continue; // Trop petit (icônes)

                $id = media_handle_upload( $key, 0 );
                if ( ! is_wp_error( $id ) ) $attachment_ids[] = $id;
            }
        }
        return ! empty( $attachment_ids ) ? implode( ',', $attachment_ids ) : null;
    }

    private function parse_metadata( $text ) {
        $meta = ['deal_id' => null, 'user_id' => null, 'company_id' => null, 'due_date' => null];
        if ( preg_match( '/\[D-([\w-]+)\]/', $text, $matches ) ) $meta['deal_id'] = $matches[1];
        if ( preg_match( '/\[U-(\d+)\]/', $text, $matches ) ) $meta['user_id'] = absint( $matches[1] );
        if ( preg_match( '/\[C-(\d+)\]/', $text, $matches ) ) $meta['company_id'] = absint( $matches[1] );
        if ( preg_match( '/\[T-(\d+)\]/', $text, $matches ) ) $meta['due_date'] = date( 'Y-m-d H:i:s', (int) $matches[1] );
        return $meta;
    }

    private function get_deal_ref_by_project_num( $project_num ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_deals_list';
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT deal_group_ref FROM $table_name WHERE project_num = %s LIMIT 1",
            $project_num
        ));
    }
}