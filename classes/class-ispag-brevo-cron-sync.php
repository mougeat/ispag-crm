<?php
// Fichier : includes/crm/class-ispag-brevo-cron-sync.php

class ISPAG_Brevo_Cron_Sync {

    private $api_key;
    private $api_url = 'https://api.brevo.com/v3/contacts';

    public function __construct() {
        $this->api_key = getenv('BREVO_API_KEY');

        add_action( 'ispag_daily_brevo_sync_event', function() {
            $this->sync_all_contacts();
        });
    }

    public function sync_all_contacts() {
        // On récupère les utilisateurs (on peut exclure les administrateurs si besoin)
        $users = get_users(array(
            'fields'  => 'all',
            'role__not_in' => array('administrator') // Optionnel : ne pas synchroniser les admins
        ));

        foreach ( $users as $user ) {
            $this->sync_one_contact( $user );
            usleep(100000); // 0.1s de pause
        }
    }

    private function sync_one_contact( $user ) {
        if ( empty( $user->user_email ) ) return;

        $phone       = get_user_meta( $user->ID, ISPAG_Crm_Contact_Constants::META_LEAD_PHONE, true );
        $owner_id    = get_user_meta( $user->ID, ISPAG_Crm_Contact_Constants::META_OWNER, true );
        $owner       = get_userdata( $owner_id );
        $job_title   = get_user_meta( $user->ID, ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION, true );
        $company_id  = get_user_meta( $user->ID, ISPAG_Crm_Contact_Constants::META_COMPANY_ID, true );
        
        $company_rep = new ISPAG_Crm_Company_Repository();
        $company     = $company_rep->get_company_by_viag_id($company_id);

        // Construction des attributs de base
        $attributes = array(
            'SMS'        => $this->format_phone( $phone ),
            'WHATSAPP'   => $this->format_phone( $phone ),
            'JOB_TITLE'  => $job_title,
            'ROLE'       => $user->roles[0] ?? '',
            'OWNER'      => $owner ? $owner->display_name : 'Not assigned',
            'ENTREPRISE' => ($company && isset($company->company_name)) ? $company->company_name : ''
        );

        // --- PROTECTION DES DONNÉES D'IDENTITÉ ---
        // On n'ajoute PRENOM et NOM que s'ils sont remplis dans WordPress.
        // Si vide dans WP, on ne les met pas dans l'array, ainsi Brevo garde ses valeurs actuelles.
        if ( ! empty( trim( $user->first_name ) ) ) {
            $attributes['PRENOM'] = $user->first_name;
        }
        if ( ! empty( trim( $user->last_name ) ) ) {
            $attributes['NOM'] = $user->last_name;
        }

        $data = array(
            'email'         => $user->user_email,
            'attributes'    => $attributes,
            'updateEnabled' => true // Crée le contact s'il n'existe pas, met à jour sinon
        );

        wp_remote_post( $this->api_url, array(
            'body'    => json_encode( $data ),
            'headers' => array(
                'api-key'      => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'timeout' => 10
        ) );
    }

    private function format_phone($phone) {
        if ( empty( $phone ) ) return '';
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Format spécifique pour Brevo (E.164 conseillé)
        // Si c'est un numéro suisse commençant par 0...
        if (strpos($phone, '0') === 0) {
            $phone = '41' . substr($phone, 1);
        }
        
        // Brevo attend souvent le format sans le + ou avec 41...
        return $phone;
    }
}