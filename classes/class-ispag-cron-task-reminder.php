<?php
// Fichier : includes/crm/class-ispag-cron-task-reminder.php

if ( ! class_exists( 'ISPAG_Cron_Task_Reminder' ) ) :

class ISPAG_Cron_Task_Reminder {

    private $wpdb;
    private $table_notes = 'wor9711_ispag_contact_notes';
    private $app_base_url = 'https://app.ispag-asp.ch'; // Base flexible

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        // On lie l'action du CRON WordPress à notre méthode
        add_action( 'ispag_fifteen_minute_cron_event', array( $this, 'check_and_send_reminders' ) );
    }

    /**
     * Utilitaire pour générer des URLs propres
     */
    private function get_app_url($path = '') {
        return rtrim($this->app_base_url, '/') . '/' . ltrim($path, '/');
    }

    public function check_and_send_reminders() {
        $now = current_time('mysql');
        
        $tasks = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table_notes} 
             WHERE is_task = 1 
             AND is_notified = 0 
             AND is_completed = 0
             AND reminder_date <= %s 
             AND reminder_date IS NOT NULL",
            $now
        ));

        if ( empty( $tasks ) ) return;

        $mailer = new ISPAG_Brevo_Mailer();

        foreach ( $tasks as $task ) {
            $this->process_single_task_reminder( $task, $mailer );
        }
    }

    private function process_single_task_reminder( $task, $mailer ) {
        
        // Configuration : tout envoyer à l'ID 1 pour le moment
        $target_user_id = 1; 
        $user = get_userdata( $target_user_id );
        if ( ! $user ) return;

        // --- 1. Gestion du CONTACT ---
        $contact_name = "Non spécifié";
        $contact_link = $this->get_app_url('contacts/');
        if ( ! empty( $task->contact_id ) ) {
            $c_ids = explode( ',', $task->contact_id );
            $first_c_id = trim($c_ids[0]);
            $contact = get_userdata( $first_c_id );
            $contact_name = $contact ? $contact->display_name : "Contact #" . $first_c_id;
            $contact_link = $this->get_app_url("contact/$first_c_id/");
        }

        // --- 2. Gestion de l'ENTREPRISE ---
        $company_name = "Non spécifiée";
        $company_link = $this->get_app_url('companies/');
        if ( ! empty( $task->company_id ) ) {
            $co_ids = explode( ',', $task->company_id );
            $first_co_id = trim($co_ids[0]);
            $company = $this->wpdb->get_row( $this->wpdb->prepare(
                "SELECT company_name FROM wor9711_ispag_companies WHERE viag_id = %s",
                $first_co_id
            ));
            if ( $company ) {
                $company_name = $company->company_name;
            }
            $company_link = $this->get_app_url("company/$first_co_id/");
        }

        if( ! empty( $task->deal_id ) ){
            $deal_ids = explode( ',', $task->deal_id );
            $first_deal_id = trim($deal_ids[0]);
        }


        if(! empty($first_deal_id)){
            $typ = 'deal';
            $id = $first_deal_id;
        } elseif(! empty($first_co_id)){
            $typ = 'company';
            $id = $first_co_id;
        } elseif(! empty($first_c_id)){
            $typ = 'contact';
            $id = $first_c_id;
        } else {
            $typ = 'task-dashboard';
            $id = null;
        }

        // --- 3. Gestion du DEAL (PROJET) ---
        $project_name = "Projet non lié";
        $project_link = $this->get_app_url('deals/');
        if ( ! empty( $task->deal_id ) ) {
            $d_ids = explode( ',', $task->deal_id );
            $first_d_id = trim($d_ids[0]);
            $project_name = get_the_title( $first_d_id );
            $project_link = $this->get_app_url("deal/$first_d_id/");
        }

        // --- ENVOI EMAIL BREVO ---
        $params = array(
            'TASK_TITLE'   => (string)$task->title,
            'PROJECT_NAME' => (string)$project_name,
            'PROJECT_LINK' => (string)$project_link,
            'CONTACT_NAME' => (string)$contact_name,
            'CONTACT_LINK' => (string)$contact_link,
            'COMPANY_NAME' => (string)$company_name,
            'COMPANY_LINK' => (string)$company_link,
            'DUE_DATE'     => date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($task->due_date) )
        );

        $email_sent = $mailer->send_template( $user->user_email, 85, $params );

        // --- ENVOI PUSH ONESIGNAL ---
        // On utilise le titre de la tâche et son contenu
        $push_title = "Rappel Tâche : " . $task->title;
        $push_body  = "Échéance : " . $params['DUE_DATE'] . " | " . $task->content;
        
        // ISPAG_OneSignal_Handler::send_os_push_notification($target_user_id, $push_title, $push_body);

        // --- OPTIONNEL : NOTIFICATION ONESIGNAL AU RESPONSABLE ---
        // On envoie une notification à l'owner (crm_owner_id)
        ISPAG_OneSignal_Handler::send_os_push_notification(
            "WP_" . $target_user_id, 
            "⏳ " . $push_title,  
            $push_body,
            $typ,
            $id
        ); 

        // --- MISE À JOUR STATUT NOTIFIÉ ---
        // On considère que si l'un des deux est tenté, on ne veut pas spammer au prochain cron
        if ( $email_sent ) {
            $this->wpdb->update(
                $this->table_notes,
                array( 'is_notified' => 1 ),
                array( 'id' => $task->id ),
                array( '%d' ),
                array( '%d' )
            );
        }
    }
}
endif;