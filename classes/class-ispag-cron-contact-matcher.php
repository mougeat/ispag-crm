<?php



/**
 * Classe gérant les automatisations CRON pour les contacts
 * Log dédié dans /wp-content/uploads/ispag_crm_matching_auto.log
 */
class ISPAG_Cron_Contact_Matcher {

    private static $log_file_name = 'ispag_crm_matching_auto.log';

    public function __construct() {
        // Hook pour le CRON
        add_action( 'ispag_crm_daily_contact_matching', array( $this, 'match_contacts_to_companies' ) );
    }

    /**
     * Système de log avec niveaux (INFO, SUCCESS, ERROR)
     */
    private function log_action( $message, $type = 'INFO' ) {
        $upload_dir = wp_upload_dir();
        $log_path = $upload_dir['basedir'] . '/' . self::$log_file_name;
        
        $timestamp = date_i18n( 'Y-m-d H:i:s' );
        $formatted_message = "[{$timestamp}] [{$type}] {$message}" . PHP_EOL;
        
        // error_log( $formatted_message, 3, $log_path );
    }

    /**
     * Logique de parcours et d'association
     */
    public function match_contacts_to_companies() {
        global $wpdb;

        $this->log_action( "--- DÉBUT DU SCAN DE MATCHING ---" );

        $meta_key_company = ISPAG_Crm_Contact_Constants::META_COMPANY_ID;
        $table_companies  = ISPAG_Crm_Company_Constants::TABLE_NAME;

        // 1. Récupérer les IDs des contacts qui n'ont PAS encore de meta association
        $contact_ids = $wpdb->get_col( $wpdb->prepare( "
            SELECT u.ID 
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um ON (u.ID = um.user_id AND um.meta_key = %s)
            WHERE um.meta_value IS NULL OR um.meta_value = ''
        ", $meta_key_company ) );

        if ( $wpdb->last_error ) {
            $this->log_action( "Erreur SQL (Récupération contacts) : " . $wpdb->last_error, 'ERROR' );
            return;
        }

        if ( empty( $contact_ids ) ) {
            $this->log_action( "Fin du scan : Aucun contact à traiter." );
            return;
        }

        $count_updated = 0;

        foreach ( $contact_ids as $user_id ) {
            $user_data = get_userdata( $user_id );
            if ( ! $user_data ) {
                $this->log_action( "Erreur : Impossible de lire les données de l'utilisateur #{$user_id}", 'ERROR' );
                continue;
            }

            $user_email = $user_data->user_email;
            
            // Extraction du domaine
            $email_parts = explode( '@', $user_email );
            $domain = isset( $email_parts[1] ) ? strtolower( trim( $email_parts[1] ) ) : '';

            if ( empty( $domain ) ) {
                $this->log_action( "Erreur : Domaine vide pour l'email '{$user_email}' (Contact #{$user_id})", 'ERROR' );
                continue;
            }

            // Exclusion des domaines génériques
            $generic_domains = array( 'gmail.com', 'outlook.com', 'hotmail.fr', 'orange.fr', 'yahoo.com', 'wanadoo.fr', 'icloud.com', 'hotmail.com', 'live.fr' );
            if ( in_array( $domain, $generic_domains ) ) {
                continue; 
            }

            // 2. Chercher la compagnie par son domaine (Colonne : compagny_domain)
            $company = $wpdb->get_row( $wpdb->prepare( "
                SELECT viag_id, company_name 
                FROM {$table_companies} 
                WHERE compagny_domain = %s 
                LIMIT 1
            ", $domain ) );

            if ( $wpdb->last_error ) {
                $this->log_action( "Erreur SQL (Recherche domaine {$domain}) : " . $wpdb->last_error, 'ERROR' );
                continue;
            }

            if ( $company ) {
                // 3. Création de la meta association
                $updated = update_user_meta( $user_id, $meta_key_company, $company->viag_id );
                
                if ( $updated ) {
                    $count_updated++;
                    $this->log_action( "SUCCESS : Contact #{$user_id} ({$user_email}) associé à '{$company->company_name}' (VIAG ID: {$company->viag_id})", 'SUCCESS' );
                } else {
                    $this->log_action( "Erreur : Échec update_user_meta pour le contact #{$user_id}", 'ERROR' );
                }
            }
        }

        $this->log_action( "--- FIN DU SCAN : {$count_updated} contacts mis à jour ---" );
    }
}