<?php

/**
 * Gère la planification et le traitement des rappels de tâches via WordPress Cron.
 */
class ISPAG_Reminder_Cron {

    /**
     * Nom du hook Cron utilisé pour l'événement.
     */
    const REMINDER_DELAY            = '60'; //Days
    const MAX_HEALTH_TASKS_PER_RUN  = 10;
    const CRON_HOOK                 = 'ispag_check_reminders_event';
    // const CRON_HOOK_HEALTH          = 'ispag_check_contact_health_status';
    const META_OWNER                = 'ispag_owner';
    const META_LAST_CONTACT_DATE    = 'ispag_last_contact_date';
    // const CRON_HOOK_DEAL_CLOSING    = 'ispag_weekly_closing_deals_report';
    const META_HEALTH_CHECK_IGNORE  = 'ispag_ignore_health_check';

    const META_LIFECYCLE = 'ispag_contact_lifecycle_phase';
    const META_STATUS    = 'ispag_lead_status';

    const DELAY_HIGH_PRIORITY = 30; // 30 jours
    const DELAY_MEDIUM_PRIORITY = 60; // 60 jours
    const DELAY_LOW_PRIORITY = 90; // 90 jours

    /**
     * Constructeur : ajoute les filtres et actions nécessaires (sans planification).
     */
    public function __construct() {
        // 1. Ajouter l'intervalle personnalisé de 15 minutes.
        add_filter( 'cron_schedules', array( $this, 'add_fifteen_min_schedule' ) );
        
        
        // 2. Attacher la fonction de traitement à l'événement Cron.
        add_action( self::CRON_HOOK, array( $this, 'process_task_reminders' ) );
        // add_action( self::CRON_HOOK_HEALTH, array( $this, 'check_contact_health_status' ) );
        // add_action( self::CRON_HOOK_DEAL_CLOSING, array( $this, 'send_weekly_closing_deals_report' ) );
    }

    /**
     * Ajoute un intervalle de 15 minutes (900 secondes) aux planifications Cron de WP.
     * @param array $schedules Les planifications existantes.
     * @return array Les planifications mises à jour.
     */
    public function add_fifteen_min_schedule( $schedules ) {
        if ( ! isset( $schedules['fifteen_minutes'] ) ) {
            $schedules['fifteen_minutes'] = array(
                'interval' => 900, // 15 minutes * 60 secondes
                'display'  => __( 'Every 15 Minutes', 'ispag-crm' )
            );
        }
        return $schedules;
    }

    /**
     * Écrit un message dans un fichier de log dédié.
     */
    private static function log_data_to_file($message) {
        if ( ! defined('WP_CONTENT_DIR') ) return;
        $log_file = WP_CONTENT_DIR . '/ispag_cron.log'; 
        $timestamp = date('Y-m-d H:i:s');
        // file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    }

    // ------------------------------------
    // PLANIFICATION (Activation/Désactivation)
    // ------------------------------------

    /**
     * Planifie l'événement Cron de vérification des rappels (toutes les 15 minutes).
     * DOIT être appelée lors de l'activation du plugin.
     */
    public static function schedule_reminder_check() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            
            // Calcule le temps de départ pour qu'il soit synchronisé (ex: 21:15:00)
            $scheduled_time = self::ispag_round_up_to_next_quarter_hour( time() );

            // Le Cron démarrera à l'heure synchronisée et tentera de relancer toutes les 15 minutes.
            wp_schedule_event( $scheduled_time, 'fifteen_minutes', self::CRON_HOOK );
        }
    }
    /**
     * Supprime l'événement Cron de vérification des rappels.
     * DOIT être appelée lors de la désactivation du plugin.
     */
    public static function unschedule_reminder_check() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ($timestamp) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }


    // ------------------------------------
    // LOGIQUE DE TRAITEMENT (CRON)
    // ------------------------------------

    /**
     * Arrondit un timestamp donné à la prochaine tranche exacte de 15 minutes.
     * (Méthode utilitaire interne pour la planification.)
     * @param int $timestamp Le timestamp actuel.
     * @return int Le timestamp ajusté pour le prochain 00, 15, 30 ou 45.
     */
    private static function ispag_round_up_to_next_quarter_hour( $timestamp ) {
        // Calcule le nombre total de secondes écoulées depuis le début de l'heure
        $seconds_in_hour = date( 'i', $timestamp ) * 60 + date( 's', $timestamp );
        
        // 900 secondes = 15 minutes. Calcule combien de secondes il manque pour atteindre le prochain multiple de 900
        $seconds_to_next_quarter = 900 - ( $seconds_in_hour % 900 ); 
        
        // Si la valeur est 900, cela signifie que nous étions exactement à :00, :15, :30 ou :45.
        if ( $seconds_to_next_quarter === 900 ) {
            return $timestamp;
        }
        
        return $timestamp + $seconds_to_next_quarter;
    }

    /**
     * Fonction principale exécutée par Cron pour vérifier et envoyer les rappels.
     */
    public function process_task_reminders() {
        // --- NOUVEL APPEL POUR CRÉER LES TÂCHES PROACTIVES ---
        // $this->check_contact_health_status();

        
        global $wpdb;

        // 1. Définition des pages de destination (à vérifier, les slugs sont probablement différents)
        $project_page = get_page_by_path( 'details-du-projet' ); // Doit être la page de visualisation du Deal/Projet
        $project_page_url_base = $project_page ? get_permalink( $project_page ) : '#';
        
        $contact_page = get_page_by_path( 'details-du-contact' ); // Doit être la page de visualisation du Contact
        $contact_page_url_base = $contact_page ? get_permalink( $contact_page ) : '#';
        
        $company_page = get_page_by_path( 'details-de-l-entreprise' ); // Doit être la page de visualisation de l'Entreprise
        $company_page_url_base = $company_page ? get_permalink( $company_page ) : '#';


        // Fichier de log pour vérifier si le Cron se déclenche
        
        self::log_data_to_file("--- DEBUT EXECUTION process_task_reminders ---");
        
        $table_name = $wpdb->prefix . 'ispag_contact_notes'; 
        $table_fournisseurs = ISPAG_Crm_Company_Constants::TABLE_NAME;
        $table_deal = $wpdb->prefix . 'achats_liste_commande';
        
        // --- Logique de Temps Corrigée pour une fenêtre de 30 minutes ---
        // Commence il y a 15 minutes
        $start_time = date( 'Y-m-d H:i:s', strtotime( '-15 minutes', current_time( 'timestamp' ) ) );
        // Finit dans 15 minutes
        $end_time = date( 'Y-m-d H:i:s', strtotime( '+15 minutes', current_time( 'timestamp' ) ) ); 
        // -------------------------------------------------------------------
        
        $sql = $wpdb->prepare( "
            SELECT 
                t1.*, 
                t2.display_name, 
                t2.user_email,
                t3.company_name AS company_name,
                t3.viag_id AS company_id,
                t4.ObjetCommande AS project_name,
                t4.hubspot_deal_id AS project_id,
                t5.display_name AS contact_name,
                t5.ID AS contact_id
            FROM {$table_name} AS t1
            LEFT JOIN {$wpdb->users} AS t2 ON t1.user_id = t2.ID
            LEFT JOIN {$wpdb->users} AS t5 ON t1.contact_id = t5.ID
            LEFT JOIN {$table_fournisseurs} AS t3 ON t1.company_id = t3.Id
            LEFT JOIN {$table_deal} AS t4 ON t1.deal_id = t4.hubspot_deal_id 
            WHERE 
                t1.is_task = 1
                AND t1.is_completed = 0
                AND t1.reminder_date IS NOT NULL
                AND t1.reminder_date BETWEEN %s AND %s
        ", $start_time, $end_time );

        self::log_data_to_file("SQL Query: {$sql}");

        $reminders = $wpdb->get_results( $sql );
        
        if ( ! empty( $reminders ) ) {
            self::log_data_to_file("RAPPELS TROUVÉS: " . count($reminders) . "");
            foreach ( $reminders as $reminder ) {
                
                $user_email = $reminder->user_email;
                $task_content = stripslashes( $reminder->content );

                $task_page = get_page_by_path( 'task-dashboard' );
                $task_page_url = $task_page ? get_permalink( $task_page ) : '#';


                $base_url = get_home_url(); 

                $company_link = '#';
                $contact_link = '#';
                $project_link = '#';
                if ( $reminder->company_id ) {
                    // Construction de l'URL au format souhaité : URL_DU_SITE/deal/deal_id/
                    $company_link = trailingslashit( $base_url . '/company/' . $reminder->company_id );
                }
                
                if ( $reminder->contact_id ) {
                    // Construction de l'URL au format souhaité : URL_DU_SITE/deal/deal_id/
                    $contact_link = trailingslashit( $base_url . '/contact/' . $reminder->contact_id );
                }

                if ( $reminder->project_id ) {
                    // Construction de l'URL au format souhaité : URL_DU_SITE/deal/deal_id/
                    $project_link = trailingslashit( $base_url . '/deal/' . $reminder->project_id );
                }
                
                // --- Fin Création Liens ---

                //************* Envoie du mail par BREVO  ********************/
                // 1. Instancier le mailer
                $brevo_mailer = new ISPAG_Brevo_Mailer();

                
                // 2. Préparer les données pour le template Brevo
                $params = array(
                    'PRENOM'       => $reminder->display_name,
                    'TASK_TITLE'   => $reminder->title,
                    'TASK_CONTENT' => stripslashes($reminder->content),
                    'ECHEANCE'     => date_i18n('d/m/Y H:i', strtotime($reminder->due_date)),
                    'LIEN_DEAL'    => $project_link,
                    'PROJECT_NAME' => $reminder->project_name,
                    'PROJECT_LINK' => $project_link,
                    'COMPANY_NAME' => $reminder->company_name,
                    'COMPANY_LINK' => $company_link,
                    'CONTACT_NAME' => $reminder->contact_name,
                    'CONTACT_LINK' => $contact_link
                );

                // 3. Envoyer (en utilisant l'ID de ton template Brevo, ex: 5)
                $template_id = 85;
                $mail_sent = $brevo_mailer->send_template($user_email, $template_id, $params);

                //Envoie d'une notification PUSH
                ISPAG_OneSignal_Handler::send_os_push_notification($reminder->user_id, $reminder->title, $reminder->content);

                if ( $mail_sent ) {
                    // Ton code existant pour marquer comme notifié...
                    self::log_data_to_file("Rappel envoyé via Brevo à {$user_email}");
                }
                //************* FIN envoie du mail par BREVO  ********************/
                
                if ( $mail_sent ) {
                    // Marquer comme notifié (is_notified = 1)
                    $wpdb->update( 
                        $table_name, 
                        array( 'is_notified' => 1 ), 
                        array( 'id' => $reminder->id ), 
                        array( '%d' ), 
                        array( '%d' )
                    );
                    self::log_data_to_file("Rappel (ID {$reminder->id}) envoyé à {$user_email}.");
                } else {
                    self::log_data_to_file("ERREUR: Échec de l'envoi du rappel (ID {$reminder->id}) à {$user_email}.");
                }
            }
        } else {
            self::log_data_to_file("Aucun rappel trouvé dans cette fenêtre d'exécution.");
        }
        self::log_data_to_file("--- FIN EXECUTION CRON ---");
    }

    // /**
    //  * Envoie un rapport hebdomadaire aux propriétaires de deals.
    //  */
    // public function send_weekly_closing_deals_report() {
    //     self::log_data_to_file("--- DEBUT EXECUTION send_weekly_closing_deals_report ---");
        
    //     global $wpdb;
    //     $table_deal = ISPAG_Crm_Deal_Constants::TABLE_NAME;
    //     $table_company = $wpdb->prefix . 'ispag_companies';
        
    //     // 1. Fenêtre de temps robuste (Lundi 00:00 au Dimanche 23:59)
    //     $start_of_week_timestamp = strtotime('monday this week', current_time('timestamp'));
    //     $end_of_week_timestamp   = strtotime('sunday this week 23:59:59', current_time('timestamp'));

    //     // Formats pour la requête SQL
    //     $start_date_mysql = date('Y-m-d H:i:s', $start_of_week_timestamp);
    //     $end_date_mysql   = date('Y-m-d H:i:s', $end_of_week_timestamp);

    //     // Formats pour l'affichage dans l'email
    //     $start_date_display = date_i18n(get_option('date_format'), $start_of_week_timestamp);
    //     $end_date_display   = date_i18n(get_option('date_format'), $end_of_week_timestamp);


    //     // 2. Préparation et Log de la requête SQL
    //     $sql = $wpdb->prepare( "
    //         SELECT 
    //             t1.Id AS hubspot_deal_id, 
    //             t1.project_name AS deal_name, 
    //             t1.closing_date AS close_date,
    //             t1.deal_owner AS owner_id,
    //             t2.display_name AS owner_name,
    //             t2.user_email AS owner_email,
    //             t3.company_name AS company_name
    //         FROM {$table_deal} AS t1
    //         LEFT JOIN {$wpdb->users} AS t2 ON t1.deal_owner = t2.ID
    //         LEFT JOIN {$table_company} AS t3 ON t1.associated_company_id = t3.viag_id
    //         WHERE 
    //             t1.closing_date BETWEEN %s AND %s
    //             AND t1.current_stage_key NOT IN ('closed_won', 'closed_lost')
    //             AND t1.deal_owner != 0
    //         ORDER BY t1.deal_owner, t1.closing_date ASC
    //     ", $start_date_mysql, $end_date_mysql );
        
    //     self::log_data_to_file("SQL Query : " . $sql);

    //     $deals = $wpdb->get_results( $sql );
    //     self::log_data_to_file("Nombre de deals trouvés : " . count($deals));
        
    //     if ( empty( $deals ) ) {
    //         self::log_data_to_file("Sortie : Aucun deal trouvé.");
    //         return;
    //     }

    //     // 3. Regroupement par Propriétaire
    //     $deals_by_owner = [];
    //     foreach ( $deals as $deal ) {
    //         $deals_by_owner[ $deal->owner_id ]['name'] = $deal->owner_name;
    //         $deals_by_owner[ $deal->owner_id ]['email'] = $deal->owner_email;
    //         $deals_by_owner[ $deal->owner_id ]['deals'][] = $deal;
    //     }
        
    //     // Capturer les erreurs d'envoi SMTP (Action WordPress)
    //     add_action('wp_mail_failed', function($error) {
    //         $msg = "ERREUR SMTP : " . $error->get_error_message();
    //         $data = print_r($error->get_error_data(), true);
    //         self::log_data_to_file($msg . " | Détails: " . $data);
    //     });

    //     $report_sent_count = 0;
    //     $kanban_url = get_home_url() . '/kanban-deals/?closing_date=this_week';

    //     // 4. Boucle d'envoi
    //     foreach ( $deals_by_owner as $owner_id => $data ) {
    //         $owner_email = $data['email'];
    //         $owner_name = $data['name'];
    //         $owner_deals = $data['deals'];
            
    //         if ( empty($owner_email) ) {
    //             self::log_data_to_file("Saut : Email vide pour l'owner {$owner_name}");
    //             continue;
    //         }

    //         // On prépare la liste des projets
    //         $projects_data = array();
            
    //         // Construction HTML (Simplifiée pour la délivrabilité)
    //         $deals_list_html = '';
    //         foreach ( $owner_deals as $deal ) {
    //             $deal_link = trailingslashit( get_home_url() . '/deal/' . $deal->hubspot_deal_id );
    //             $close_date_formatted = date_i18n( get_option('date_format'), strtotime($deal->close_date) );

    //             $projects_data[] = array(
    //                 'PROJECT_NAME'         => esc_html($deal->deal_name),
    //                 'PROJECT_LINK'         => $deal_link,
    //                 'PROJECT_COMPANY'      => $deal->company_name,
    //                 'PROJECT_CLOSING_DATE' => $close_date_formatted,
    //                 'DEAL_LIST_URL'        => $kanban_url
    //             );
                
    //         }


    //         //************* Envoie du mail par BREVO  ********************/
    //         // 1. Instancier le mailer
    //         $brevo_mailer = new ISPAG_Brevo_Mailer();

            
    //         // 2. Préparer les données pour le template Brevo
    //         $params = array(
    //             'NB_DEALS'     => count($owner_deals),
    //             'START_DATE'   => $start_date_display,
    //             'END_DATE'     => $end_date_display,
    //             'PROJECTS'   => $projects_data
    //         );

    //         // 3. Envoyer (en utilisant l'ID de ton template Brevo, ex: 5)
    //         $template_id = 86;
    //         $mail_sent = $brevo_mailer->send_template($owner_email, $template_id, $params);

    //         if ( $mail_sent ) {
    //             // Ton code existant pour marquer comme notifié...
    //             self::log_data_to_file("Rappel envoyé via Brevo à {$owner_email}");
    //         }
    //         //************* FIN envoie du mail par BREVO  ********************/

            
    //         if ( $mail_sent ) {
    //             self::log_data_to_file("Succès : Mail envoyé à {$owner_email}");
    //             $report_sent_count++;
    //         } else {
    //             self::log_data_to_file("ECHEC : BREVO a retourné FALSE pour {$owner_email}");
    //         }
    //     }

    //     self::log_data_to_file("--- FIN EXECUTION (Total: {$report_sent_count}) ---");
    // }
    // private function send_os_push_notification($user_id, $title, $content) {
    //     // 1. NETTOYAGE DU CONTENU
    //     // On décode les entités (ex: &nbsp; devient un espace)
    //     $clean_content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
    //     // On supprime toutes les balises HTML (<p>, <br>, etc.)
    //     $clean_content = wp_strip_all_tags($clean_content);
    //     // On limite éventuellement la longueur pour que ça ne coupe pas trop mal
    //     $clean_content = mb_strimwidth($clean_content, 0, 150, "...");

    //     $app_id = getenv('CRM_ONE_SIGNAL_APP_ID');
    //     $api_key = getenv('CRM_ONE_SIGNAL_API_KEY');

    //     $fields = array(
    //         'app_id' => $app_id,
    //         'filters' => array(
    //             array("field" => "tag", "key" => "wp_user_id", "relation" => "=", "value" => (string)$user_id),
    //         ),
    //         'headings' => array("en" => $title, "fr" => $title),
    //         'contents' => array("en" => $clean_content, "fr" => $clean_content),
    //         'chrome_web_icon' => "https://app.ispag-asp.ch/wp-content/uploads/2025/03/Logo_ISPAG_RGB_F.png",
    //         'url' => get_home_url() . "/task-dashboard/",
    //         // On ajoute 'data' en plus, c'est une sécurité pour les notifications sur iPhone/Android
    //         'data' => array(
    //             'url' => get_home_url() . "/task-dashboard/"
    //         )
             
    //     );

    //     $fields_json = json_encode($fields);

    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
    //     curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    //         'Content-Type: application/json; charset=utf-8',
    //         'Authorization: Basic ' . $api_key
    //     ));
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    //     curl_setopt($ch, CURLOPT_HEADER, FALSE);
    //     curl_setopt($ch, CURLOPT_POST, TRUE);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_json);
    //     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

    //     $response = curl_exec($ch);
    //     curl_close($ch);
        
    //     return $response;
    // }

} 