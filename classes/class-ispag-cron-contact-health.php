<?php
/**
 * Classe ISPAG_Cron_Contact_Health
 * Gère la santé des contacts, l'attribution des responsables et les rappels.
 */
class ISPAG_Cron_Contact_Health {

    const CRON_ACTION          = 'ispag_check_contact_health';
    const MAX_TASKS_PER_RUN    = 15;
    const DEFAULT_MANAGER_ID   = 1; // ID de Cyril ou du responsable par défaut
    
    // Seuils définis : 3 mois (90j), 6 mois (180j), 8 mois (240j)
    const DELAY_PRIORITY_A     = 90; 
    const DELAY_PRIORITY_B     = 180;
    const DELAY_PRIORITY_C     = 240;
 
    public function __construct() {
        add_action( self::CRON_ACTION, array( $this, 'run_health_checks' ) );
        
        // On s'assure que le cron est bien enregistré
        if ( ! wp_next_scheduled( self::CRON_ACTION ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_ACTION );
        }
    }

    private static function log( $message ) {
        if ( ! defined('WP_CONTENT_DIR') ) return;
        $log_file = WP_CONTENT_DIR . '/ispag_cron.log'; 
        // file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [HEALTH] $message\n", FILE_APPEND);
    }

    /**
     * Point d'entrée principal déclenché par le CRON
     */
    public function run_health_checks() {
        self::log("--- DEBUT RUN HEALTH CHECKS ---");
        
        // 1. Relance des contacts dont le délai de visite/appel est dépassé
        $this->process_contact_reminders();
        
        // 2. Alerte pour les contacts importants sans responsable assigné
        $this->check_unassigned_qualified_contacts();

        self::log("--- FIN RUN HEALTH CHECKS ---");
    }

    /**
     * Alerte si un contact important (Phase qualifiée) n'a pas de responsable.
     */
    private function check_unassigned_qualified_contacts() {
        global $wpdb;
        $notes_table    = ISPAG_Note_Manager::TABLE_NOTE;
        $lifecycle_meta = ISPAG_Crm_Contact_Constants::META_LIFECYCLE_PHASE; 
        $owner_meta     = ISPAG_Crm_Contact_Constants::META_OWNER;
        $ignore_meta    = ISPAG_Crm_Contact_Constants::META_HEALTH_CHECK_IGNORE;

        $important_phases = array('qualified_lead', 'opportunity', 'customer', 'promoter');

        $users = get_users(array(
            'meta_query'   => array(
                'relation' => 'AND',
                array(
                    'key'     => $lifecycle_meta,
                    'value'   => $important_phases,
                    'compare' => 'IN'
                ),
                array(
                    'key'     => $owner_meta,
                    'compare' => 'NOT EXISTS' 
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => $ignore_meta,
                        'value'   => '1',
                        'compare' => '!='
                    ),
                    array(
                        'key'     => $ignore_meta,
                        'compare' => 'NOT EXISTS'
                    )
                )
            )
        ));

        foreach ($users as $user) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$notes_table} WHERE contact_id = %d AND type = 'ASSIGNMENT_REQUIRED' AND is_completed = 0",
                $user->ID
            ));

            if (!$exists) {
                $wpdb->insert($notes_table, array(
                    'contact_id'   => $user->ID,
                    'user_id'      => self::DEFAULT_MANAGER_ID, 
                    'type'         => 'ASSIGNMENT_REQUIRED',
                    'title'        => '⚠️ Attribution requise : ' . $user->display_name,
                    'content'      => "Le contact **{$user->display_name}** est en phase qualifiée mais n'a pas de responsable.",
                    'is_task'      => 1,
                    'is_completed' => 0,
                    'created_at'   => current_time('mysql')
                ));
            }
        }
    }


    /**
     * Analyse la date du dernier contact réel et crée des tâches de relance si besoin.
     */
    private function process_contact_reminders() {
        global $wpdb;
        $notes_table = ISPAG_Note_Manager::TABLE_NOTE;
        $task_count  = 0;

        // 1. Récupération simple des IDs des contacts (on évite de charger tout WP_Users)
        // On peut filtrer ici par rôle si besoin pour alléger la boucle
        $contact_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->users}");

        // On récupère le repo pour utiliser ses méthodes d'enrichissement
        $repo = new ISPAG_Crm_Contacts_Repository();

        foreach ($contact_ids as $id) {
            if ($task_count >= self::MAX_TASKS_PER_RUN) break;

            // 1. Récupération via le Repo (qui gère déjà le get_user_meta en interne)
            $contact = $repo->get_contact_by_id($id);
            if (!$contact) continue;

            // 2. LE IGNOR (Exclusion si la case est cochée dans le CRM)
            if ($contact->ispag_ignore_health_check == '1') {
                continue; 
            }

            // 3. On ne relance que s'il y a un responsable assigné
            if (empty($contact->crm_owner_id)) {
                continue;
            }

            // 4. Seuil et calcul (ex: 90 jours)
            $days = $this->get_threshold($contact->ID);
            $threshold_timestamp = strtotime("-$days days");

            // 5. Comparaison avec la date enrichie par le repo
            $last_contact_ts = !empty($contact->last_contact_date) ? strtotime($contact->last_contact_date) : 0;
            
            // Si (Pas de contact du tout) OU (Dernier contact plus vieux que le seuil)
            if ($last_contact_ts === 0 || $last_contact_ts < $threshold_timestamp) {
                
                // Vérification doublon de tâche en cours
                $existing_task = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$notes_table} WHERE contact_id = %d AND type = 'HEALTH_REMINDER' AND is_completed = 0",
                    $contact->ID
                ));

                if (!$existing_task) {
                    $display_last = ($last_contact_ts > 0) ? date_i18n(get_option('date_format'), $last_contact_ts) : "jamais";
                    $prio = !empty($contact->priority_level) ? $contact->priority_level : 'Non définie'; 

                    // Récupération de l'ID entreprise (on prend la première si multi-sociétés)
                    $linked_company_id = 0;
                    if ( ! empty( $contact->companies ) && is_array( $contact->companies ) ) {
                        // On récupère le viag_id de la première entreprise liée
                        $linked_company_id = $contact->companies[0]->viag_id; 
                    }

                    $wpdb->insert($notes_table, array(
                        'contact_id'   => $contact->ID,
                        'company_id'   => $linked_company_id,
                        'user_id'      => $contact->crm_owner_id, 
                        'type'         => 'HEALTH_REMINDER',
                        'title'        => '⏳ Relance : ' . $contact->display_name . ' (Prio ' . $prio . ')',
                        'content'      => "Alerte Santé ISPAG : Aucun contact réel détecté depuis le **{$display_last}**. Délai de {$days}j dépassé.",
                        'is_task'      => 1,
                        'is_completed' => 0,
                        'due_date'     => date('Y-m-d H:i:s', strtotime('+3 days')),
                        'created_at'   => current_time('mysql')
                    ));

                    $task_count++;
                    

                }
            }
        }
    }

    /**
     * Détermine le seuil en jours selon la priorité ISPAG (A, B ou C)
     */
    private function get_threshold($contact_id) {
        $priority = strtoupper(get_user_meta($contact_id, 'ispag_priority_level', true));

        switch ($priority) {
            case 'A': return self::DELAY_PRIORITY_A; // 3 mois
            case 'B': return self::DELAY_PRIORITY_B; // 6 mois
            case 'C': return self::DELAY_PRIORITY_C; // 8 mois
            default:  return 180; // Par défaut 6 mois si non renseigné
        }
    }
}
