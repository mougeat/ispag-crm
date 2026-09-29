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
        $this->app_base_url = untrailingslashit(get_site_url()); // adresse du site courant (plus celle de la production)

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
        $tasks = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table_notes} 
            WHERE is_task = 1 
            AND is_completed = 0
            AND reminder_date IS NOT NULL
            AND reminder_date <= NOW()
            AND (
                notified_at IS NULL 
                OR notified_at <= DATE_SUB(NOW(), INTERVAL 1 WEEK)
            )"
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
        $contact_name = "Not specified";
        $contact_link = $this->get_app_url('contacts/');
        if ( ! empty( $task->contact_id ) ) {
            $c_ids = explode( ',', $task->contact_id );
            $first_c_id = trim($c_ids[0]);
            $contact = get_userdata( $first_c_id );
            $contact_name = $contact ? $contact->display_name : "Contact #" . $first_c_id;
            $contact_link = $this->get_app_url("contact/$first_c_id/");
        }

        // --- 2. Gestion de l'ENTREPRISE ---
        $company_name = "Not specifiede";
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

        $first_deal_id = null;
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
        $project_name = "Unlinked project";
        $project_link = $this->get_app_url('deals/');
        if ( ! empty( $task->deal_id ) ) {
            $d_ids = explode( ',', $task->deal_id );
            $first_d_id = trim($d_ids[0]);
            $project_name = get_the_title( $first_d_id );
            $project_link = $this->get_app_url("deal/$first_d_id/");
        }

        // Préparation des paramètres pour Brevo
        $due_date_formatted = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($task->due_date) );
        
        $brevo_params = array(
            'TASK_TITLE'   => (string)$task->title,
            'PROJECT_NAME' => (string)$project_name,
            'PROJECT_LINK' => (string)$project_link,
            'CONTACT_NAME' => (string)$contact_name,
            'CONTACT_LINK' => (string)$contact_link,
            'COMPANY_NAME' => (string)$company_name,
            'COMPANY_LINK' => (string)$company_link,
            'DUE_DATE'     => (string)$due_date_formatted
        );

        // Textes pour la cloche CRM et le Push
        $push_title = "Task reminder: " . $task->title;
        $push_body  = "Due: " . $due_date_formatted . " | " . $task->content;
        
        $sent_success = false;

        // --- NOTIFICATION CENTRALISÉE ---
        if (class_exists('ISPAG_Notifications_Manager')) {
            $sent_success = ISPAG_Notifications_Manager::send(
                [$target_user_id, 1], 
                'crm_task',
                "⏳ " . $push_title,  
                $push_body,
                $typ.'/'.$id, // URL ou route cible
                $id,
                ['brevo_params' => $brevo_params] // Données spécifiques transmises au canal mail
            ); 
        }

        // --- MISE À JOUR STATUT NOTIFIÉ ---
        // Si le manager a validé l'envoi global, on met à jour la date de dernière notification
        if ( $sent_success ) {
            $this->wpdb->update(
                $this->table_notes,
                array( 'notified_at' => current_time( 'mysql' ) ),
                array( 'id' => $task->id ),
                array( '%s' ),
                array( '%d' )
            );
        }
    }
}
endif;