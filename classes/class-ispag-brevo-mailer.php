<?php
// Fichier : includes/crm/class-ispag-brevo-mailer.php

if ( ! class_exists( 'ISPAG_Brevo_Mailer' ) ) :

class ISPAG_Brevo_Mailer {

    private $api_key;
    private $api_url = 'https://api.brevo.com/v3/smtp/email';

    public function __construct() {
        // On récupère la même clé API que pour ta classe de synchro
        $this->api_key = getenv('BREVO_API_KEY');
    }

    /**
     * Envoie un e-mail via un template Brevo
     *
     * @param string $to_email    Email du destinataire
     * @param int    $template_id ID du template dans Brevo
     * @param array  $params      Variables personnalisées {{ params.NOM_VARIABLE }}
     * @return bool
     */
    public function send_template($to_email, $template_id, $params = array()) {
        
        if ( empty($this->api_key) ) {
            // error_log("ISPAG Brevo Mailer : Clé API manquante.");
            return false;
        }

        // Nettoyage des params pour éviter les valeurs NULL qui font planter le JSON
        foreach ($params as $key => $value) {
            if (is_null($value) || $value === false) {
                $params[$key] = ''; // On remplace par une chaîne vide
            }
        }

        $to_email = trim($to_email);
        if (!is_email($to_email)) {
            return false; 
        }

        $data = array(
            'sender'      => array( 'name' => 'ISPAG CRM', 'email' => 'c.barthel@ispag-asp.com' ),
            'to'          => array( array( 'email' => $to_email ) ),
            'templateId'  => (int)$template_id,
            'params'      => $params
        );

        $response = wp_remote_post( $this->api_url, array(
            'body'    => json_encode( $data ),
            'headers' => array(
                'api-key'      => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'timeout' => 15
        ));

        if ( is_wp_error( $response ) ) {
            error_log( "[ISPAG Brevo Mailer] Error : " . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) {
            error_log( "[ISPAG Brevo Mailer] API Error Code $code : " . wp_remote_retrieve_body( $response ) );
            return false;
        }

        return true;
    }
}
endif;