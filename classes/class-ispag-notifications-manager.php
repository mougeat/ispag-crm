<?php
/**
 * Classe centrale pour la gestion et le routage des notifications ISPAG.
 * Gère les canaux : CRM (cloche), OneSignal (push), Mail, Telegram.
 * Respecte les préférences des utilisateurs et leurs rôles.
 * Logging : Toutes les actions sont loguées dans ispag_notifications_manager.log.
 */
class ISPAG_Notifications_Manager
{
    /** @var ISPAG_Logger Instance du logger. */
    private static $logger = null;
    private const LOG_NAME = 'notifications_manager';

    /**
     * S'exécute UNE SEULE FOIS lors de l'activation du plugin.
     */
    public static function activate()
    {
        $installed_ver = get_option('ispag_notifications_db_version');
        $current_ver   = '1.0.1'; // Changez la version si la table évolue

        if ($installed_ver !== $current_ver) {
            self::create_notifications_table();
            update_option('ispag_notifications_db_version', $current_ver);
        }

        // 2. Planification des tâches Cron
        if (!wp_next_scheduled('ispag_send_delayed_notifications')) {
            wp_schedule_event(time(), 'hourly', 'ispag_send_delayed_notifications');
        }

        if (!wp_next_scheduled('ispag_cleanup_old_notifications')) {
            wp_schedule_event(time(), 'daily', 'ispag_cleanup_old_notifications');
        }
    }

    /**
     * S'exécute lors de la désactivation du plugin.
     */
    public static function deactivate()
    {
        // Nettoyage des événements Cron pour éviter les tâches orphelines
        wp_clear_scheduled_hook('ispag_send_delayed_notifications');
        wp_clear_scheduled_hook('ispag_cleanup_old_notifications');
    }
    
    /**
     * Initialisation des hooks et de la base de données
     */
    public static function init()
    {
        $logger = self::get_logger();
        $user_id = get_current_user_id();

        // self::create_notifications_table();
        // $logger->log_user_action('notifications_manager', 'notifications_table_checked', [], $user_id);

        // Ajouter un cron pour envoyer les notifications différées
        // add_action('ispag_send_delayed_notifications', [__CLASS__, 'send_delayed_notifications']);
        // if (!wp_next_scheduled('ispag_send_delayed_notifications'))
        // {
        //     wp_schedule_event(time(), 'hourly', 'ispag_send_delayed_notifications');
        //     // $logger->log_user_action('notifications_manager', 'cron_event_scheduled', ['event' => 'ispag_send_delayed_notifications', 'frequency' => 'hourly'], $user_id);
        // }

        // Hooks AJAX pour la sauvegarde des préférences utilisateur
        add_action('wp_ajax_ispag_save_notification_preferences', [__CLASS__, 'save_user_preferences_ajax']);
        // $logger->log_user_action('notifications_manager', 'ajax_hook_registered', ['hook' => 'wp_ajax_ispag_save_notification_preferences'], $user_id);

        // Hooks pour l'affichage et la sauvegarde dans le profil utilisateur WP-Admin
        add_action('show_user_profile', [__CLASS__, 'render_profile_fields']);
        add_action('edit_user_profile', [__CLASS__, 'render_profile_fields']);
        add_action('personal_options_update', [__CLASS__, 'save_profile_fields']);
        add_action('edit_user_profile_update', [__CLASS__, 'save_profile_fields']);
        // $logger->log_user_action('notifications_manager', 'profile_hooks_registered', [], $user_id);

        // Hooks pour les notifications conceptuelles
        add_action('wp_ajax_ispag_get_conceptual_notifications', [__CLASS__, 'get_conceptual_notifications_ajax']);
        add_action('wp_ajax_ispag_mark_conceptual_read', [__CLASS__, 'mark_conceptual_notification_read_ajax']);
        // $logger->log_user_action('notifications_manager', 'conceptual_notification_hooks_registered', [], $user_id);

        add_action('wp_ajax_ispag_mark_notification_as_read', [__CLASS__, 'mark_notification_as_read_ajax']);
        add_action('wp_ajax_nopriv_ispag_mark_notification_as_read', [__CLASS__, 'mark_notification_as_read_ajax']);
        add_action('wp_ajax_ispag_delete_notification', [__CLASS__, 'delete_notification_ajax']);

        // Ajouter un cron pour nettoyer les anciennes notifications lues
        // add_action('ispag_cleanup_old_notifications', [__CLASS__, 'cleanup_old_notifications']);
        // if (!wp_next_scheduled('ispag_cleanup_old_notifications'))
        // {
        //     wp_schedule_event(time(), 'daily', 'ispag_cleanup_old_notifications');
        // }
    }
    
    /**
     * Initialise le logger
     */
    private static function get_logger()
    {
        if (self::$logger === null)
        {
            self::$logger = ISPAG_Logger::get_instance();
        }
        return self::$logger;
    }

    /**
     * Groupes de types de notifications
     */
    public static function get_notification_groups()
    {
        $logger = self::get_logger();
        $user_id = get_current_user_id();
        $logger->log_user_action('notifications_manager', 'get_notification_groups_called', [], $user_id);

        return [
            'contact'           => __('Contact', 'ispag-crm'),
            'company'           => __('Company', 'ispag-crm'),
            'deal'              => __('Deal', 'ispag-crm'),
            'purchase'          => __('Purchase', 'ispag-crm'),
            'datas'             => __('Data import / export', 'ispag-crm'),
            'task'              => __('Task', 'ispag-crm'),
            'form_submission'   => __('Form submission', 'ispag-crm'),
            'billing'           => __('Invoice', 'ispag-crm'),
            'email'             => __('E-mail', 'ispag-crm'),
            'automation'        => __('Automations and workflow', 'ispag-crm'),
        ];
    }

    /**
     * Types de notifications disponibles, leurs groupes, conditions et canaux par défaut
     */
    public static function get_available_notification_types() {
        $logger = self::get_logger();
        $user_id = get_current_user_id();
        $logger->log_user_action('notifications_manager', 'get_available_notification_types_called', [], $user_id);

        return [
            //*************
            /*Contact */
            //*************
            'contact_assigned' => [
                'label' => __('Contact assigned to you', 'ispag-crm'),
                'description' => __('Notification sent when a contact is assigned to you.', 'ispag-crm'),
                'group' => 'contact',
                'capability' => 'manage_order',
                'default_channels' => ['crm', 'onesignal', 'mail'],
                'retain_during_disconnection' => true, // ⬅️ À retenir pendant les périodes de déconnexion
            ],
            'contact_leadstatus' => [
                'label' => __('Contact lead status', 'ispag-crm'),
                'description' => __('Notification sent when the lead status of a contact changes.', 'ispag-crm'),
                'group' => 'contact',
                'capability' => 'manage_options',
                'default_channels' => ['crm', 'onesignal'],
                'retain_during_disconnection' => false, // ⬅️ Ignorer pendant les périodes de déconnexion
            ],
            //*************
            /*Company */
            //*************
            'company_assigned' => [
                'label' => __('Company assigned to you', 'ispag-crm'),
                'description' => __('Notification sent when a company is assigned to you.', 'ispag-crm'),
                'group' => 'company',
                'capability' => 'manage_order',
                'default_channels' => ['crm', 'onesignal', 'mail'],
                'retain_during_disconnection' => true, // ⬅️ À retenir
            ],
            'company_followup' => [
                'label' => __('Company monitoring', 'ispag-crm'),
                'description' => __('Notification sent for company follow-ups.', 'ispag-crm'),
                'group' => 'company',
                'capability' => 'manage_options',
                'default_channels' => ['crm', 'onesignal', 'conceptual_window'],
                'retain_during_disconnection' => true, // ⬅️ À retenir
            ],
            //*************
            /*Deal */
            //*************
            'deal_status_change' => [
                'label' => __('Project status tracking', 'ispag-crm'),
                'description' => __('Notification sent when the status of a project changes.', 'ispag-crm'),
                'group' => 'deal',
                'capability' => 'read_orders',
                'default_channels' => ['crm', 'onesignal', 'mail', 'conceptual_window'],
                'retain_during_disconnection' => true, // ⬅️ À retenir
            ],
            'deal_billing' => [
                'label' => __('Project tracking / Invoicing', 'ispag-crm'),
                'description' => __('Notification sent for invoicing projects.', 'ispag-crm'),
                'group' => 'deal',
                'capability' => 'manage_order',
                'default_channels' => ['crm', 'onesignal', 'mail'],
                'retain_during_disconnection' => false, // ⬅️ Ignorer
            ],
            'product_manager' => [
                'label' => __('Item / Deal Management', 'ispag-crm'),
                'description' => __('Notification sent for item management actions (drawing needs validation, drawing validated, etc.).', 'ispag-crm'),
                'group' => 'deal',
                'capability' => 'manage_order',
                'default_channels' => ['crm', 'onesignal', 'mail'],
                'retain_during_disconnection' => false, // ⬅️ Ignorer
            ],
            'product_modifications' => [
                'label' => __('Item Modifications', 'ispag-crm'),
                'description' => __('Notification sent for item management actions (product modifications, etc.).', 'ispag-crm'),
                'group' => 'deal',
                'capability' => 'manage_options',
                'default_channels' => ['crm', 'onesignal', 'mail'],
                'retain_during_disconnection' => true, // ⬅️ Ignorer
            ],
            'article_changed_by_other' => [
                'label' => __('Item modified by someone else', 'ispag-crm'),
                'description' => __('Notification sent to the project manager when another person creates, modifies or deletes an item of the project.', 'ispag-crm'),
                'group' => 'deal',
                'capability' => 'manage_order',
                'default_channels' => ['crm', 'onesignal', 'mail'],
                'retain_during_disconnection' => true, // ⬅️ À retenir
            ],
            'deal_manager' => [
                'label' => __('Project / Deal Management', 'ispag-crm'),
                'description' => __('Notification sent for project or deal management actions. (new project, etc.)', 'ispag-crm'),
                'group' => 'deal',
                'capability' => 'manage_order',
                'default_channels' => ['crm', 'onesignal'],
                'retain_during_disconnection' => true, // ⬅️ À retenir
            ],
            //*************
            /*Purchase */
            //*************
            'purchase_followup' => [
                'label' => __('Purchase tracking', 'ispag-crm'),
                'description' => __('Notification sent for purchase follow-ups.', 'ispag-crm'),
                'group' => 'purchase',
                'capability' => 'manage_order',
                'default_channels' => ['crm', 'onesignal', 'conceptual_window'],
                'retain_during_disconnection' => false, // ⬅️ Ignorer
            ],
            'purchase_order_creation' => [
                'label' => __('Purchase order created', 'ispag-crm'),
                'description' => __('Notification sent when a new purchase order is created.', 'ispag-crm'),
                'group' => 'purchase',
                'capability' => 'edit_supplier_order',
                'default_channels' => ['conceptual_window'],
                'retain_during_disconnection' => false, // ⬅️ Ignorer
            ],
            //*************
            /*Datas import / export */
            //*************
            'datas_export' => [
                'label' => __('Data import/export report', 'ispag-crm'),
                'description' => __('Notification sent when a data import or export is completed.', 'ispag-crm'),
                'group' => 'datas',
                'capability' => 'export_reports',
                'default_channels' => ['crm', 'conceptual_window'],
                'retain_during_disconnection' => true, // ⬅️ Ignorer (car déjà envoyé à la fin de l'import)
            ],
            'document_upload' => [
                'label' => __('Document upload', 'ispag-crm'),
                'description' => __('Notification sent when a document is uploaded.', 'ispag-crm'),
                'group' => 'datas',
                'capability' => 'upload_files',
                'default_channels' => ['conceptual_window'],
                'retain_during_disconnection' => false, // ⬅️ Ignorer
            ],
            'document_analysis' => [
                'label' => __('Document AI analysis', 'ispag-crm'),
                'description' => __('Notification sent when a document is analyzed by AI.', 'ispag-crm'),
                'group' => 'datas',
                'capability' => 'manage_order',
                'default_channels' => ['onesignal', 'conceptual_window'],
                'retain_during_disconnection' => false, // ⬅️ À retenir
            ],
            'article_cleanup' => [
                'label' => __('Old projects and articles cleanup', 'ispag-crm'),
                'description' => __('Notification sent when old projects or articles are cleaned up.', 'ispag-crm'),
                'group' => 'datas',
                'capability' => 'manage_options',
                'default_channels' => ['crm', 'mail'],
                'retain_during_disconnection' => false, // ⬅️ Ignorer
            ],
            //*************
            /*Task */
            //*************
            'crm_task' => [
                'label' => __('Task notification', 'ispag-crm'),
                'description' => __('Notification sent for CRM task assignments or updates.', 'ispag-crm'),
                'group' => 'task',
                'capability' => 'manage_order',
                'default_channels' => ['crm', 'onesignal', 'conceptual_window'],
                'retain_during_disconnection' => true, // ⬅️ À retenir
            ],
            //*************
            /*Form submission */
            //*************
            'form_submission' => [
                'label' => __('New form submission', 'ispag-crm'),
                'description' => __('Notification sent when a new form is submitted.', 'ispag-crm'),
                'group' => 'form_submission',
                'capability' => 'export_reports',
                'default_channels' => ['crm', 'onesignal', 'mail', 'conceptual_window'],
                'retain_during_disconnection' => false, // ⬅️ Ignorer
            ],
            //*************
            /*Invoicing */
            //*************
            'billing' => [
                'label' => __('Billing', 'ispag-crm'),
                'description' => __('Notification sent for billing-related updates.', 'ispag-crm'),
                'group' => 'billing',
                'capability' => 'manage_order',
                'default_channels' => ['crm', 'onesignal'],
                'retain_during_disconnection' => true, // ⬅️ À retenir
            ],
            //*************
            /*E-mail */
            //*************
            'email_notification' => [
                'label' => __('Email Activity', 'ispag-crm'),
                'description' => __('Notification sent for email-related activities.', 'ispag-crm'),
                'group' => 'email',
                'capability' => 'export_reports',
                'default_channels' => ['crm', 'mail', 'conceptual_window'],
                'retain_during_disconnection' => false, // ⬅️ Ignorer
            ],
            //*************
            /*Automations / Workflow */
            //*************

            'automation_workflow' => [
                'label' => __('Automation and workflow alerts', 'ispag-crm'),
                'description' => __('Notification sent for automation or workflow alerts.', 'ispag-crm'),
                'group' => 'automation',
                'capability' => 'export_reports',
                'default_channels' => ['crm', 'onesignal', 'conceptual_window'],
                'retain_during_disconnection' => true, // ⬅️ À retenir
            ]
        ];
    }

    /**
     * Canaux de diffusion supportés
     */
    public static function get_available_channels()
    {
        $logger = self::get_logger();
        $user_id = get_current_user_id();
        $logger->log_user_action('notifications_manager', 'get_available_channels_called', [], $user_id);

        return [
            'crm' => __('CRM (Cloche / Sidebar)', 'ispag-crm'),
            'onesignal' => __('Push (mobile)', 'ispag-crm'),
            'mail' => __('E-mail', 'ispag-crm'),
            'telegram' => __('Telegram', 'ispag-crm'),
            'conceptual_window' => __('Conceptual window', 'ispag-crm'),
        ];
    }

    /**
     * Vérifie si un utilisateur a déjà enregistré ses préférences de notification
     */
    public static function has_notification_preferences($user_id) {
        $prefs = get_user_meta($user_id, 'ispag_notif_prefs', true);
        return !empty($prefs) && is_array($prefs);
    }


    /**
     * Envoie les notifications différées dont la date est arrivée
     */
    public static function send_delayed_notifications()
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'send_delayed_notifications_start', [], 0);

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_delayed_notifications';
        $now = current_time('mysql');

        $delayed_notifications = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE scheduled_for <= %s ORDER BY scheduled_for ASC",
                $now
            )
        );

        $logger->log_db_change('notifications_manager', $table_name, 'FETCH_DELAYED_NOTIFICATIONS', ['count' => count($delayed_notifications)], 0);

        if (empty($delayed_notifications))
        {
            $logger->log_user_action('notifications_manager', 'no_delayed_notifications_found', [], 0);
            return;
        }

        foreach ($delayed_notifications as $notification)
        {
            $logger->log_user_action('notifications_manager', 'processing_delayed_notification', ['notification_id' => $notification->id, 'user_id' => $notification->user_id, 'type' => $notification->type], 0);

            $extra_data = maybe_unserialize($notification->extra_data);
            $result = self::send(
                [$notification->user_id],
                $notification->type,
                $notification->title,
                $notification->content,
                $notification->url,
                $notification->entity_id,
                $extra_data
            );

            $logger->log_user_action('notifications_manager', 'delayed_notification_sent', ['notification_id' => $notification->id, 'result' => $result], 0);

            // Supprimer la notification différée après envoi
            $deleted = $wpdb->delete($table_name, ['id' => $notification->id]);
            $logger->log_db_change('notifications_manager', $table_name, 'DELETE_DELAYED_NOTIFICATION', ['notification_id' => $notification->id, 'result' => $deleted], 0);
        }
    }

    /**
     * Crée la table SQL unifiée si elle n'existe pas
     */
    private static function create_notifications_table()
    {
        $logger = self::get_logger();
        // $logger->log_user_action('notifications_manager', 'create_notifications_table_start', [], 0);

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';
        $charset_collate = $wpdb->get_charset_collate();

       $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `title` TEXT NOT NULL,
            `content` TEXT NOT NULL,
            `url` TEXT,
            `type` VARCHAR(50) NOT NULL,
            `entity_id` BIGINT(20) UNSIGNED,
            `onesignal_id` VARCHAR(255),
            `is_read` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `is_deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `read_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `is_read` (`is_read`),
            KEY `is_deleted` (`is_deleted`),
            KEY `type` (`type`)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        // $logger->log_db_change('notifications_manager', $table_name, 'TABLE_CREATED_OR_CHECKED', [], 0);
    }

    /**
     * POINT D'ENTRÉE PRINCIPAL : Dispatch une notification selon les préférences et les canaux
     * Garantit qu'une seule notification est envoyée par destinataire, même si plusieurs canaux sont activés.
     */
        public static function send($user_ids, $type, $title, $content, $url = '', $entity_id = null, $extra_data = [])
    {
        global $wpdb;
        $logger = self::get_logger();
        $current_user_id = get_current_user_id();
        $logger->log_user_action('notifications_manager', 'send_notification_start', [
            'type' => $type,
            'title' => $title,
            'user_ids' => $user_ids,
            'entity_id' => $entity_id
        ], $current_user_id);

        if (!is_array($user_ids))
        {
            $user_ids = [$user_ids];
            $logger->log_user_action('notifications_manager', 'user_ids_converted_to_array', ['original' => $user_ids], $current_user_id);
        }

        $types = self::get_available_notification_types();
        if (!isset($types[$type]))
        {
            $logger->log('notifications_manager', 'ERROR: Invalid notification type - ' . $type, $current_user_id);
            return false;
        }

        $config = $types[$type];
        $sent_notifications = [];
        $current_time = current_time('timestamp');

        $logger->log_user_action('notifications_manager', 'notification_config_loaded', ['config' => $config], $current_user_id);

        foreach ($user_ids as $user_id)
        {
            $logger->log_user_action('notifications_manager', 'processing_user', ['user_id' => $user_id], $current_user_id);

            if (!user_can($user_id, $config['capability']))
            {
                $logger->log('notifications_manager', 'ERROR: User cannot receive notification - missing capability', $current_user_id, ['user_id' => $user_id, 'capability' => $config['capability']]);
                continue;
            }

            // VÉRIFIER LES PRÉFÉRENCES DE DÉCONNEXION
            if (!self::can_send_notification($user_id, $current_time)) {
                $logger->log_user_action('notifications_manager', 'user_disconnected_store_for_later', ['user_id' => $user_id], $current_user_id);

                if (isset($config['retain_during_disconnection']) && $config['retain_during_disconnection'] === true) {
                    self::store_delayed_notification($user_id, $type, $title, $content, $url, $entity_id, $extra_data);
                } else {
                    $logger->log_user_action('notifications_manager', 'notification_ignored_during_disconnection', [
                        'user_id' => $user_id,
                        'type' => $type
                    ], $current_user_id);
                }
                continue;
            }

            $notification_key = md5($user_id . $type . $entity_id);
            if (isset($sent_notifications[$notification_key]))
            {
                $logger->log_user_action('notifications_manager', 'notification_already_sent_skipping', ['notification_key' => $notification_key], $current_user_id);
                continue;
            }

            // ⬇️⬇️⬇️ SEULE LIGNE MODIFIÉE ⬇️⬇️⬇️
            // Si $extra_data['channels'] est fourni explicitement (ex: notif client forcée en 'mail'),
            // il prend le pas sur les préférences enregistrées par l'utilisateur. Sinon, comportement inchangé.
            $user_channels = !empty($extra_data['channels'])
                ? $extra_data['channels']
                : self::get_user_channel_preferences($user_id, $type);
            // ⬆️⬆️⬆️ SEULE LIGNE MODIFIÉE ⬆️⬆️⬆️

            $logger->log_user_action('notifications_manager', 'user_channels_retrieved', ['user_id' => $user_id, 'channels' => $user_channels], $current_user_id);

            $onesignal_id = null;

            if (!empty($user_channels))
            {
                if (in_array('conceptual_window', $user_channels))
                {
                    $result = self::send_to_conceptual_window($user_id, $title, $content, $url, $entity_id);
                    $logger->log_user_action('notifications_manager', 'sent_to_conceptual_window', ['user_id' => $user_id, 'result' => $result], $current_user_id);
                }

                if (in_array('onesignal', $user_channels) && class_exists('ISPAG_OneSignal_Handler'))
                {
                    $onesignal_id = ISPAG_OneSignal_Handler::send_os_push_notification($user_id, $title, $content, $url, $entity_id);
                    $logger->log_user_action('notifications_manager', 'sent_to_onesignal', ['user_id' => $user_id, 'onesignal_id' => $onesignal_id], $current_user_id);
                }

                if (in_array('crm', $user_channels))
                {
                    $bell_id = self::send_to_crm_bell($user_id, $title, $content, $url, $type, $entity_id, $onesignal_id);
                    $logger->log_db_change('notifications_manager', $wpdb->prefix . 'ispag_notifications', 'INSERT_CRM_BELL', ['user_id' => $user_id, 'bell_id' => $bell_id], $current_user_id);

                    if ($onesignal_id && $bell_id !== null)
                    {
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'ispag_notifications';
                        $updated = $wpdb->update(
                            $table_name,
                            ['onesignal_id' => $onesignal_id],
                            ['id' => $bell_id],
                            ['%s'],
                            ['%d']
                        );
                        $logger->log_db_change('notifications_manager', $table_name, 'UPDATE_ONESIGNAL_ID', ['bell_id' => $bell_id, 'onesignal_id' => $onesignal_id, 'result' => $updated], $current_user_id);
                    }
                }

                if (in_array('mail', $user_channels))
                {
                    $result = self::send_to_email($user_id, $title, $content, $url, $type, $extra_data);
                    $logger->log_user_action('notifications_manager', 'sent_to_email', ['user_id' => $user_id, 'result' => $result], $current_user_id);
                }

                if (in_array('telegram', $user_channels))
                {
                    $result = self::send_to_telegram($user_id, $title, $content, $url, $entity_id);
                    $logger->log_user_action('notifications_manager', 'sent_to_telegram', ['user_id' => $user_id, 'result' => $result], $current_user_id);
                }

                $sent_notifications[$notification_key] = true;
                $logger->log_user_action('notifications_manager', 'notification_marked_as_sent', ['notification_key' => $notification_key], $current_user_id);
            }
            else
            {
                $logger->log('notifications_manager', 'WARNING: No channels enabled for user', $current_user_id, ['user_id' => $user_id]);
            }
        }

        $logger->log_user_action('notifications_manager', 'send_notification_complete', [], $current_user_id);
        return true;
    }

    /**
     * Stocke une notification différée (pour les week-ends ou vacances)
     */
    private static function store_delayed_notification($user_id, $type, $title, $content, $url, $entity_id, $extra_data) {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'store_delayed_notification_start', [
            'user_id' => $user_id,
            'type' => $type,
            'title' => $title
        ], 0);

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_delayed_notifications';

        // Créer la table si elle n'existe pas
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `type` VARCHAR(50) NOT NULL,
            `title` TEXT NOT NULL,
            `content` TEXT NOT NULL,
            `url` TEXT,
            `entity_id` BIGINT(20) UNSIGNED,
            `extra_data` TEXT,
            `scheduled_for` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `scheduled_for` (`scheduled_for`),
            KEY `type_entity_id` (`type`, `entity_id`) // ⬅️ Index pour éviter les doublons
        ) $charset_collate;";
        dbDelta($sql);
        $logger->log_db_change('notifications_manager', $table_name, 'TABLE_CREATED_OR_CHECKED', [], 0);

        // ⬇️ Vérifier si une notification identique existe déjà (même type + entity_id)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d AND type = %s AND entity_id = %d",
            $user_id,
            $type,
            $entity_id
        ));

        if ($existing) {
            $logger->log_user_action('notifications_manager', 'duplicate_delayed_notification_skipped', [
                'user_id' => $user_id,
                'type' => $type,
                'entity_id' => $entity_id
            ], 0);
            return; // Ne pas stocker de doublon
        }

        // Calculer la prochaine date autorisée (lundi suivant ou fin de vacances)
        $next_allowed_date = self::get_next_allowed_date($user_id);
        $logger->log_user_action('notifications_manager', 'next_allowed_date_calculated', ['user_id' => $user_id, 'next_allowed_date' => $next_allowed_date], 0);

        $inserted = $wpdb->insert($table_name, [
            'user_id' => $user_id,
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'url' => $url,
            'entity_id' => $entity_id,
            'extra_data' => maybe_serialize($extra_data),
            'scheduled_for' => date('Y-m-d H:i:s', $next_allowed_date)
        ]);

        $logger->log_db_change('notifications_manager', $table_name, 'INSERT_DELAYED_NOTIFICATION', [
            'user_id' => $user_id,
            'type' => $type,
            'scheduled_for' => date('Y-m-d H:i:s', $next_allowed_date),
            'result' => $inserted
        ], 0);
    }

    /**
     * Récupère la prochaine date autorisée pour envoyer une notification à un utilisateur
     */
    private static function get_next_allowed_date($user_id)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'get_next_allowed_date_start', ['user_id' => $user_id], 0);

        $now = time();
        $prefs = self::get_disconnection_preferences($user_id);
        $logger->log_user_action('notifications_manager', 'disconnection_preferences_retrieved', ['prefs' => $prefs], 0);

        $holiday_periods = $prefs['holiday_periods'];
        $allow_weekend = $prefs['allow_weekend_notifications'];

        // Si c'est un week-end et que l'utilisateur n'autorise pas les notifications
        if (self::is_weekend($now) && !$allow_weekend)
        {
            $next_monday = strtotime('next monday', $now);
            $logger->log_user_action('notifications_manager', 'weekend_detected_scheduling_for_monday', ['next_monday' => $next_monday], 0);
            return $next_monday;
        }

        // Vérifier si on est dans une période de vacances
        foreach ($holiday_periods as $period)
        {
            if ((isset($period['start']) && !empty($period['start'])) && (isset($period['end']) && !empty($period['end'])))
            {
                $start = strtotime($period['start']);
                $end = strtotime($period['end'] . ' 23:59:59'); // Fin de journée
                if ($now >= $start && $now <= $end)
                {
                    $logger->log_user_action('notifications_manager', 'holiday_period_detected_scheduling_for_after', ['end' => $end + 1], 0);
                    return $end + 1; // Le lendemain de la fin de vacances
                }
            }
        }

        $logger->log_user_action('notifications_manager', 'no_restrictions_sending_now', [], 0);
        return $now; // Si aucune restriction, envoyer maintenant
    }

    /**
     * Récupère les canaux activés par un utilisateur pour un type donné.
     */
    public static function get_user_channel_preferences($user_id, $type)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'get_user_channel_preferences_start', ['user_id' => $user_id, 'type' => $type], 0);

        $types = self::get_available_notification_types();

        $default_channels = isset($types[$type]['default_channels']) ? $types[$type]['default_channels'] : ['crm', 'onesignal'];
        $saved_prefs = get_user_meta($user_id, 'ispag_notif_prefs', true);

        $logger->log_user_action('notifications_manager', 'default_channels_retrieved', ['type' => $type, 'default_channels' => $default_channels], 0);

        if (is_array($saved_prefs) && isset($saved_prefs[$type]) && is_array($saved_prefs[$type]))
        {
            $logger->log_user_action('notifications_manager', 'user_preferences_found', ['user_id' => $user_id, 'type' => $type, 'channels' => $saved_prefs[$type]], 0);
            return $saved_prefs[$type];
        }

        $logger->log_user_action('notifications_manager', 'using_default_channels', ['user_id' => $user_id, 'type' => $type, 'default_channels' => $default_channels], 0);
        return $default_channels;
    }

    /**
     * Canal 1 : Enregistrement en BDD pour la cloche CRM (Sidebar)
     */
    private static function send_to_crm_bell($user_id, $title, $content, $url, $type, $entity_id, $onesignal_id = null)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'send_to_crm_bell_start', [
            'user_id' => $user_id,
            'title' => $title,
            'type' => $type,
            'entity_id' => $entity_id
        ], 0);

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';

        $clean_content = wp_strip_all_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));

        // Si onesignal_id n'est pas null, on peut l'ajouter en paramètre à l'URL si besoin
        if (!empty($onesignal_id))
        {
            $url = add_query_arg('onesignal_id', $onesignal_id, $url);
            $logger->log_user_action('notifications_manager', 'onesignal_id_added_to_url', ['onesignal_id' => $onesignal_id], 0);
        }

        $inserted = $wpdb->insert($table_name, [
            'user_id' => $user_id,
            'title' => $title,
            'content' => $clean_content,
            'url' => $url,
            'type' => $type,
            'entity_id' => $entity_id,
            'onesignal_id' => $onesignal_id,
            'is_read' => 0,
            'sent_at' => current_time('mysql')
        ]);

        $logger->log_db_change('notifications_manager', $table_name, 'INSERT_CRM_BELL', [
            'user_id' => $user_id,
            'title' => $title,
            'type' => $type,
            'entity_id' => $entity_id,
            'result' => $inserted
        ], 0);

        if ($inserted)
        {
            $insert_id = $wpdb->insert_id;
            $logger->log_user_action('notifications_manager', 'crm_bell_inserted', ['insert_id' => $insert_id], 0);

            if (get_current_user_id() === (int)$user_id && !is_admin())
            {
                $logger->log_user_action('notifications_manager', 'adding_trigger_script_for_current_user', ['user_id' => $user_id], 0);
                add_action('wp_footer', function() use ($logger, $user_id)
                {
                    $logger->log_user_action('notifications_manager', 'trigger_script_output', [], $user_id);
                    echo "<script>
                        if (typeof window.ispagTriggerCrmNotification === 'function') {
                            window.ispagTriggerCrmNotification();
                        }
                    </script>";
                });
            }
        }
        else
        {
            $logger->log('notifications_manager', 'ERROR: Failed to insert CRM bell notification', 0, ['user_id' => $user_id, 'title' => $title]);
        }

        return $wpdb->insert_id;
    }

    /**
     * Canal 3 : Envoi par E-mail via ISPAG_Mail_Service ou Brevo
     */
    private static function send_to_email($user_id, $title, $content, $url, $type, $extra_data = [])
    {
        $logger = self::get_logger();
        $log_name = 'notifications_manager';
        $current_user_id = get_current_user_id();

        $logger->log_user_action($log_name, 'send_to_email_start', [
            'target_user_id' => $user_id,
            'title' => $title,
            'type' => $type,
            'extra_data' => $extra_data
        ], $current_user_id);

        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email))
        {
            $logger->log($log_name, 'ERROR: User invalid or empty email address', $current_user_id, [
                'target_user_id' => $user_id,
                'title' => $title,
                'type' => $type
            ]);
            return false;
        }

        $logger->log_user_action($log_name, 'user_data_retrieved', ['user_email' => $user->user_email], $current_user_id);

        // Cas spécial pour les notifications de type 'crm_task' ou 'deal_status_change'
        if (($type === 'crm_task' || $type === 'deal_status_change') && class_exists('ISPAG_Brevo_Mailer'))
        {
            $logger->log_user_action($log_name, 'send_to_email_via_brevo_mailer', [
                'recipient' => $user->user_email,
                'title' => $title,
                'type' => $type
            ], $current_user_id);

            $mailer = new ISPAG_Brevo_Mailer();
            $template_id = isset($extra_data['template_id']) ? $extra_data['template_id'] : 85;
            $delay = isset($extra_data['delay']) ? $extra_data['delay'] : 0;
            $params = isset($extra_data['brevo_params']) ? $extra_data['brevo_params'] : [
                'TASK_TITLE' => (string)$title,
                'DUE_DATE' => (string)$content
            ];

            $logger->log_user_action($log_name, 'brevo_params_prepared', ['template_id' => $template_id, 'params' => $params], $current_user_id);

            // Récupérer les CC depuis extra_data
            $cc_emails = [];
            if (!empty($extra_data['cc_ids']) && is_array($extra_data['cc_ids']))
            {
                foreach ($extra_data['cc_ids'] as $cc_id)
                {
                    $cc_user = get_userdata($cc_id);
                    if ($cc_user && !empty($cc_user->user_email))
                    {
                        $cc_emails[] = [
                            'email' => $cc_user->user_email,
                            'name' => trim($cc_user->first_name . ' ' . $cc_user->last_name) ?: $cc_user->display_name
                        ];
                        $logger->log_user_action($log_name, 'cc_email_added', ['cc_id' => $cc_id, 'email' => $cc_user->user_email], $current_user_id);
                    }
                    else
                    {
                        $logger->log($log_name, 'WARNING: CC user not found or empty email', $current_user_id, ['cc_id' => $cc_id]);
                    }
                }
            }

            $logger->log_user_action($log_name, 'sending_via_brevo', ['recipient' => $user->user_email, 'cc_emails' => $cc_emails], $current_user_id);

            // Envoyer l'email via Brevo avec les CC
            $sent = $mailer->send_template(
                $user->user_email,
                $template_id,
                $params,
                $cc_emails // Passer les CC
            );

            if ($sent)
            {
                $logger->log_user_action($log_name, 'brevo_mailer_sent_successfully', [
                    'recipient' => $user->user_email,
                    'template_id' => $template_id,
                    'cc_emails' => $cc_emails
                ], $current_user_id);
            }
            else
            {
                $logger->log($log_name, 'ERROR: ISPAG_Brevo_Mailer failed to send template', $current_user_id, [
                    'recipient' => $user->user_email,
                    'title' => $title,
                    'template_id' => $template_id
                ]);
            }

            return $sent;
        }

        // Cas par défaut : utiliser wp_mail avec CC
        $name = trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name;
        $subject = "[ISPAG CRM] " . $title;
        $body = $content;

        $logger->log_user_action($log_name, 'fallback_to_wp_mail_prepared', [
            'recipient' => $user->user_email,
            'subject' => $subject,
            'name' => $name
        ], $current_user_id);

        // Récupérer les CC pour le fallback
        $cc_emails = [];
        if (!empty($extra_data['cc_ids']) && is_array($extra_data['cc_ids']))
        {
            foreach ($extra_data['cc_ids'] as $cc_id)
            {
                $cc_user = get_userdata($cc_id);
                if ($cc_user && !empty($cc_user->user_email))
                {
                    $cc_emails[] = $cc_user->user_email;
                    $logger->log_user_action($log_name, 'cc_email_added_for_wp_mail', ['cc_id' => $cc_id, 'email' => $cc_user->user_email], $current_user_id);
                }
                else
                {
                    $logger->log($log_name, 'WARNING: CC user not found or empty email for wp_mail', $current_user_id, ['cc_id' => $cc_id]);
                }
            }
        }

        // Ajouter les CC aux headers
        $headers = [];
        if (!empty($cc_emails))
        {
            $headers[] = 'Cc: ' . implode(', ', $cc_emails);
            $logger->log_user_action($log_name, 'cc_headers_prepared', ['headers' => $headers], $current_user_id);
        }

        $logger->log_user_action($log_name, 'sending_via_wp_mail', [
            'recipient' => $user->user_email,
            'subject' => $subject,
            'cc_emails' => $cc_emails
        ], $current_user_id);

        $sent = wp_mail($user->user_email, $subject, wp_strip_all_tags($body), $headers);

        if (!$sent)
        {
            $error = error_get_last();
            $error_message = $error ? $error['message'] : 'Unknown error';
            $logger->log($log_name, 'ERROR: fallback wp_mail failed - ' . $error_message, $current_user_id, [
                'recipient' => $user->user_email,
                'subject' => $subject,
                'cc_emails' => $cc_emails
            ]);
        }
        else
        {
            $logger->log_user_action($log_name, 'fallback_wp_mail_sent_successfully', [
                'recipient' => $user->user_email,
                'subject' => $subject,
                'cc_emails' => $cc_emails
            ], $current_user_id);
        }

        return $sent;
    }

    /**
     * Canal 4 : Envoi par Telegram via ISPAG_Telegram_Notifier
     */
    private static function send_to_telegram($user_id, $title, $content, $url, $deal_id = null)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'send_to_telegram_start', [
            'user_id' => $user_id,
            'title' => $title,
            'deal_id' => $deal_id
        ], 0);

        if (!class_exists('ISPAG_Telegram_Notifier'))
        {
            $logger->log('notifications_manager', 'ERROR: ISPAG_Telegram_Notifier class not found', 0);
            return false;
        }

        $chat_id = get_user_meta($user_id, 'ispag_telegram_chat_id', true);

        if (empty($chat_id) && $user_id == 1)
        {
            $chat_id = getenv('ISPAG_TELEGRAM_CHAT_ID');
            $logger->log_user_action('notifications_manager', 'using_env_telegram_chat_id_for_admin', ['chat_id' => $chat_id ? 'set' : 'not set'], 0);
        }

        if (empty($chat_id))
        {
            $logger->log('notifications_manager', 'ERROR: Empty chat_id for user', 0, ['user_id' => $user_id]);
            return false;
        }

        $logger->log_user_action('notifications_manager', 'chat_id_retrieved', ['user_id' => $user_id, 'chat_id' => $chat_id], 0);

        $message = "<b>" . esc_html($title) . "</b>\n\n" . $content;
        if (!empty($url))
        {
            $full_url = (strpos($url, 'http') === 0) ? $url : home_url() . ltrim($url, '/');
            $message .= "\n\n🌐 <a href='" . esc_url($full_url) . "'>View details</a>";
            $logger->log_user_action('notifications_manager', 'url_added_to_message', ['url' => $full_url], 0);
        }

        $telegram = new ISPAG_Telegram_Notifier();
        $result = $telegram->send_to_chat($chat_id, $message, true);
        $logger->log_user_action('notifications_manager', 'telegram_message_sent', ['chat_id' => $chat_id, 'result' => $result], 0);

        return $result;
    }

    /**
     * Canal 5 : Affichage dans une fenêtre conceptuelle
     * Stocke la notification en base de données. L'affichage sera géré côté front-end.
     */
    private static function send_to_conceptual_window($user_id, $title, $content, $url, $entity_id = null)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'send_to_conceptual_window_start', [
            'user_id' => $user_id,
            'title' => $title,
            'entity_id' => $entity_id
        ], 0);

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';
        $clean_content = wp_strip_all_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));

        $inserted = $wpdb->insert($table_name, [
            'user_id' => $user_id,
            'title' => $title,
            'content' => $clean_content,
            'url' => $url,
            'type' => 'conceptual_window',
            'entity_id' => $entity_id,
            'is_read' => 0,
            'sent_at' => current_time('mysql')
        ]);

        $logger->log_db_change('notifications_manager', $table_name, 'INSERT_CONCEPTUAL_WINDOW', [
            'user_id' => $user_id,
            'title' => $title,
            'entity_id' => $entity_id,
            'result' => $inserted
        ], 0);

        if ($inserted)
        {
            $logger->log_user_action('notifications_manager', 'conceptual_window_notification_stored', ['insert_id' => $wpdb->insert_id], 0);
        }
        else
        {
            $logger->log('notifications_manager', 'ERROR: Failed to store conceptual window notification', 0, ['user_id' => $user_id, 'title' => $title]);
        }

        return true;
    }

    /**
     * Bascule l'état de lecture d'une notification (lue <-> non lue) via AJAX
     */
    public static function mark_notification_as_read_ajax() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;
        $onesignal_id = isset($_POST['onesignal_id']) ? sanitize_text_field($_POST['onesignal_id']) : '';

        if ($notification_id === 0) {
            wp_send_json_error(['message' => 'ID de notification manquant.']);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';

        // 1. Récupérer l'état actuel de la notification
        $current_state = $wpdb->get_var($wpdb->prepare(
            "SELECT is_read FROM $table_name WHERE id = %d",
            $notification_id
        ));

        if ($current_state === null) {
            wp_send_json_error(['message' => __('Notification not found.', 'ispag-crm')]);
        }

        // 2. Inverser l'état (si 1 devient 0, si 0 devient 1)
        $new_state = ($current_state == 1) ? 0 : 1;
        $read_at = ($new_state == 1) ? current_time('mysql') : null;

        $wpdb->update(
            $table_name,
            array(
                'is_read' => $new_state,
                'read_at' => $read_at
            ),
            array('id' => $notification_id),
            array('%d', '%s'),
            array('%d')
        );

        if ($new_state == 1 && class_exists('ISPAG_OneSignal_Handler')) {
            ISPAG_OneSignal_Handler::mark_as_read_on_onesignal($onesignal_id);
        }

        $msg = ($new_state == 1) 
            ? __('Notification marked as read.', 'ispag-crm') 
            : __('Notification marked as unread.', 'ispag-crm');

        wp_send_json_success(['message' => $msg, 'new_state' => $new_state]);
    }

    /**
     * Bascule l'état de suppression d'une notification (Corbeille <-> Actif) via AJAX
     */
    public static function delete_notification_ajax() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;

        if ($notification_id === 0) {
            wp_send_json_error(['message' => 'ID de notification manquant.']);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';

        // 1. Récupérer l'état actuel de suppression
        $current_deleted_state = $wpdb->get_var($wpdb->prepare(
            "SELECT is_deleted FROM $table_name WHERE id = %d",
            $notification_id
        ));

        if ($current_deleted_state === null) {
            wp_send_json_error(['message' => __('Notification not found.', 'ispag-crm')]);
        }

        // 2. Inverser l'état (si 0 met à la corbeille 1, si 1 restaure 0)
        $new_deleted_state = ($current_deleted_state == 1) ? 0 : 1;

        $updated = $wpdb->update(
            $table_name,
            array('is_deleted' => $new_deleted_state),
            array('id' => $notification_id),
            array('%d'),
            array('%d')
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('Error while changing the status.', 'ispag-crm')]);
        }

        $msg = ($new_deleted_state == 1) 
            ? __('Notification moved to the Trash.', 'ispag-crm') 
            : __('Notification restored.', 'ispag-crm');

        wp_send_json_success(['message' => $msg, 'new_state' => $new_deleted_state]);
    }

    /**
     * Affiche les champs de préférences dans le profil WordPress (Groupé)
     */
    public static function render_profile_fields($user)
    {
        $logger = self::get_logger();
        $user_id = get_current_user_id();
        $logger->log_user_action('notifications_manager', 'render_profile_fields_start', ['user_id' => $user->ID], $user_id);

        $groups = self::get_notification_groups();
        $available_types = self::get_available_notification_types();
        $available_channels = self::get_available_channels();

        $logger->log_user_action('notifications_manager', 'notification_data_loaded', [
            'groups_count' => count($groups),
            'types_count' => count($available_types),
            'channels_count' => count($available_channels)
        ], $user_id);

        // Récupérer les préférences de déconnexion
        $allow_weekend = (bool) get_user_meta($user->ID, 'ispag_allow_weekend_notifications', true);
        $holiday_periods_json = get_user_meta($user->ID, 'ispag_holiday_periods', true);
        $holiday_periods = json_decode($holiday_periods_json, true) ?: [];

        $logger->log_user_action('notifications_manager', 'disconnection_preferences_loaded', [
            'allow_weekend' => $allow_weekend,
            'holiday_periods_count' => count($holiday_periods)
        ], $user_id);

        ?>
        <h3><?php _e('ISPAG notification preferences', 'ispag-crm'); ?></h3>

        <!-- Section pour les canaux de notification -->
        <table class="form-table">
            <tr>
                <th><label>Canaux par type</label></th>
                <td>
                    <p class="description"><?php _e('Choose the method(s) by which you wish to receive each type of notification.', 'ispag-crm'); ?></p>
                    <div style="overflow-x:auto;">
                        <table class="widefat striped" style="margin-top: 10px; max-width: 800px;">
                            <thead>
                                <tr>
                                    <th><?php _e('Notification type', 'ispag-crm'); ?></th>
                                    <?php foreach ($available_channels as $channel_key => $channel_label): ?>
                                        <th style="text-align:center;"><?php echo esc_html($channel_label); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groups as $group_key => $group_label):
                                    $group_types = array_filter($available_types, function($t) use ($group_key) {
                                        return isset($t['group']) && $t['group'] === $group_key;
                                    });
                                    if (empty($group_types)) continue;
                                ?>
                                    <tr style="background: #e2e8f0;">
                                        <td colspan="<?php echo count($available_channels) + 1; ?>">
                                            <strong><?php echo esc_html($group_label); ?></strong>
                                        </td>
                                    </tr>
                                    <?php foreach ($group_types as $type_key => $type_info):
                                        if (!user_can($user->ID, $type_info['capability'])) continue;
                                        $current_channels = self::get_user_channel_preferences($user->ID, $type_key);
                                    ?>
                                        <tr>
                                            <td style="padding-left: 20px;"><?php echo esc_html($type_info['label']); ?></td>
                                            <?php foreach ($available_channels as $channel_key => $channel_label):
                                                $is_checked = in_array($channel_key, $current_channels);
                                            ?>
                                                <td style="text-align:center;">
                                                    <input type="checkbox"
                                                        name="ispag_notif_prefs[<?php echo esc_attr($type_key); ?>][]"
                                                        value="<?php echo esc_attr($channel_key); ?>"
                                                        <?php checked($is_checked, true); ?> />
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Section pour le droit à la déconnexion -->
        <h3><?php _e('Right to Disconnect', 'ispag-crm'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label><?php _e('Weekend notifications', 'ispag-crm'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox"
                            name="ispag_allow_weekend_notifications"
                            value="1"
                            <?php checked($allow_weekend, true); ?> />
                        <?php _e('Receive notifications on weekends (Saturday and Sunday)', 'ispag-crm'); ?>
                    </label>
                    <p class="description"><?php _e('If left unchecked, notifications will be delayed until Monday.', 'ispag-crm'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e('Holiday Periods', 'ispag-crm'); ?></label></th>
                <td>
                    <p class="description"><?php _e('Add periods during which you do not want to receive notifications.', 'ispag-crm'); ?></p>
                    <div id="ispag-holiday-periods-container">
                        <?php if (!empty($holiday_periods)):
                            $logger->log_user_action('notifications_manager', 'rendering_holiday_periods', ['count' => count($holiday_periods)], $user_id);
                        ?>
                            <?php foreach ($holiday_periods as $index => $period): ?>
                                <div class="ispag-holiday-period" data-index="<?php echo $index; ?>">
                                    <div class="ispag-holiday-period-fields">
                                        <label>
                                            <?php _e('From :', 'ispag-crm'); ?>
                                            <input type="date"
                                                name="ispag_holiday_periods[<?php echo $index; ?>][start]"
                                                value="<?php echo esc_attr($period['start']); ?>" />
                                        </label>
                                        <label>
                                            <?php _e('To :', 'ispag-crm'); ?>
                                            <input type="date"
                                                name="ispag_holiday_periods[<?php echo $index; ?>][end]"
                                                value="<?php echo esc_attr($period['end']); ?>" />
                                        </label>
                                        <button type="button" class="ispag-remove-holiday-period button button-secondary"><?php _e('Remove', 'ispag-crm'); ?></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="ispag-holiday-period" data-index="0">
                                <div class="ispag-holiday-period-fields">
                                    <label>
                                        <?php _e('Start Date:', 'ispag-crm'); ?>
                                        <input type="date" name="ispag_holiday_periods[0][start]" />
                                    </label>
                                    <label>
                                        <?php _e('End Date:', 'ispag-crm'); ?>
                                        <input type="date" name="ispag_holiday_periods[0][end]" />
                                    </label>
                                    <button type="button" class="ispag-remove-holiday-period button button-secondary"><?php _e('Remove', 'ispag-crm'); ?></button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" id="ispag-add-holiday-period" class="button button-primary"><?php _e('Add Holiday Period', 'ispag-crm'); ?></button>
                </td>
            </tr>
        </table>

        <script>
        jQuery(document).ready(function($) {
            <?php
            $logger->log_user_action('notifications_manager', 'profile_fields_js_loaded', [], $user_id);
?>
            // Ajouter une nouvelle période de vacances
            $('#ispag-add-holiday-period').on('click', function() {
                const index = $('#ispag-holiday-periods-container .ispag-holiday-period').length;
                const newPeriodHtml = `
                    <div class="ispag-holiday-period" data-index="${index}">
                        <div class="ispag-holiday-period-fields">
                            <label>
                                <?php _e('Start Date:', 'ispag-crm'); ?>
                                <input type="date" name="ispag_holiday_periods[${index}][start]" />
                            </label>
                            <label>
                                <?php _e('End Date:', 'ispag-crm'); ?>
                                <input type="date" name="ispag_holiday_periods[${index}][end]" />
                            </label>
                            <button type="button" class="ispag-remove-holiday-period button button-secondary"><?php _e('Delete', 'ispag-crm'); ?></button>
                        </div>
                    </div>
                `;
                $('#ispag-holiday-periods-container').append(newPeriodHtml);
            });

            // Supprimer une période de vacances
            $(document).on('click', '.ispag-remove-holiday-period', function() {
                $(this).closest('.ispag-holiday-period').remove();
                // Réindexer les périodes
                $('#ispag-holiday-periods-container .ispag-holiday-period').each(function(i) {
                    $(this).attr('data-index', i);
                    $(this).find('input[name^="ispag_holiday_periods"]').each(function() {
                        const name = $(this).attr('name');
                        $(this).attr('name', name.replace(/ispag_holiday_periods\[\d+\]/, `ispag_holiday_periods[${i}]`));
                    });
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Sauvegarde des préférences utilisateur via AJAX
     */
    public static function save_user_preferences_ajax()
    {
        $logger = self::get_logger();
        $user_id = get_current_user_id();
        $logger->log_user_action('notifications_manager', 'save_user_preferences_ajax_start', [], $user_id);

        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        if ($user_id === 0)
        {
            $logger->log('notifications_manager', 'ERROR: User not logged in', $user_id);
            wp_send_json_error(['message' => 'Not authorized.']);
        }

        $preferences = isset($_POST['preferences']) ? $_POST['preferences'] : [];
        $allow_weekend = isset($_POST['allow_weekend_notifications']) ? 1 : 0;
        $holiday_periods = isset($_POST['holiday_periods']) ? array_values($_POST['holiday_periods']) : [];

        $logger->log_user_action('notifications_manager', 'preferences_data_received', [
            'preferences_count' => count($preferences),
            'allow_weekend' => $allow_weekend,
            'holiday_periods_count' => count($holiday_periods)
        ], $user_id);

        $clean_prefs = [];
        $available_types = self::get_available_notification_types();
        $available_channels = self::get_available_channels();

        foreach ($preferences as $type => $channels)
        {
            if (isset($available_types[$type]) && is_array($channels))
            {
                if (user_can($user_id, $available_types[$type]['capability']))
                {
                    $clean_prefs[$type] = array_intersect($channels, array_keys($available_channels));
                    $logger->log_user_action('notifications_manager', 'preference_cleaned', ['type' => $type, 'channels' => $clean_prefs[$type]], $user_id);
                }
                else
                {
                    $logger->log('notifications_manager', 'WARNING: User cannot use this notification type', $user_id, ['type' => $type, 'capability' => $available_types[$type]['capability']]);
                }
            }
            else
            {
                $logger->log('notifications_manager', 'WARNING: Invalid notification type in preferences', $user_id, ['type' => $type]);
            }
        }

        $result = update_user_meta($user_id, 'ispag_notif_prefs', $clean_prefs);
        $logger->log_db_change('notifications_manager', 'usermeta', 'UPDATE_NOTIF_PREFS', ['user_id' => $user_id, 'result' => $result], $user_id);

        // Save les préférences de déconnexion
        $result1 = update_user_meta($user_id, 'ispag_allow_weekend_notifications', $allow_weekend);
        $result2 = update_user_meta($user_id, 'ispag_holiday_periods', json_encode($holiday_periods));

        $logger->log_db_change('notifications_manager', 'usermeta', 'UPDATE_DISCONNECTION_PREFS', [
            'allow_weekend' => $allow_weekend,
            'holiday_periods' => $holiday_periods,
            'result1' => $result1,
            'result2' => $result2
        ], $user_id);

        $logger->log_user_action('notifications_manager', 'save_user_preferences_ajax_complete', [], $user_id);
        wp_send_json_success(['message' => 'Preferences saved successfully.']);
    }

    /**
     * Sauvegarde des préférences depuis le profil WordPress
     */
    public static function save_profile_fields($user_id)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'save_profile_fields_start', ['user_id' => $user_id], $user_id);

        if (!current_user_can('edit_user', $user_id))
        {
            $logger->log('notifications_manager', 'ERROR: User cannot edit this profile', $user_id);
            return false;
        }

        // Save les préférences de canaux
        if (isset($_POST['ispag_notif_prefs']) && is_array($_POST['ispag_notif_prefs']))
        {
            $clean_prefs = [];
            $available_types = self::get_available_notification_types();
            $available_channels = self::get_available_channels();

            foreach ($_POST['ispag_notif_prefs'] as $type => $channels)
            {
                if (isset($available_types[$type]) && is_array($channels))
                {
                    if (user_can($user_id, $available_types[$type]['capability']))
                    {
                        $clean_prefs[$type] = array_intersect($channels, array_keys($available_channels));
                        $logger->log_user_action('notifications_manager', 'profile_preference_cleaned', ['type' => $type, 'channels' => $clean_prefs[$type]], $user_id);
                    }
                    else
                    {
                        $logger->log('notifications_manager', 'WARNING: User cannot use this notification type in profile', $user_id, ['type' => $type, 'capability' => $available_types[$type]['capability']]);
                    }
                }
                else
                {
                    $logger->log('notifications_manager', 'WARNING: Invalid notification type in profile preferences', $user_id, ['type' => $type]);
                }
            }

            $result = update_user_meta($user_id, 'ispag_notif_prefs', $clean_prefs);
            $logger->log_db_change('notifications_manager', 'usermeta', 'UPDATE_NOTIF_PREFS_FROM_PROFILE', ['user_id' => $user_id, 'result' => $result], $user_id);
        }
        else
        {
            $result = update_user_meta($user_id, 'ispag_notif_prefs', []);
            $logger->log_db_change('notifications_manager', 'usermeta', 'CLEAR_NOTIF_PREFS_FROM_PROFILE', ['user_id' => $user_id, 'result' => $result], $user_id);
        }

        // Save les préférences de déconnexion
        if (isset($_POST['ispag_allow_weekend_notifications']) || isset($_POST['ispag_holiday_periods']))
        {
            $allow_weekend = isset($_POST['ispag_allow_weekend_notifications']) ? 1 : 0;
            $holiday_periods = isset($_POST['ispag_holiday_periods']) ? array_values($_POST['ispag_holiday_periods']) : [];

            $logger->log_user_action('notifications_manager', 'disconnection_prefs_received_from_profile', [
                'allow_weekend' => $allow_weekend,
                'holiday_periods_count' => count($holiday_periods)
            ], $user_id);

            $result1 = update_user_meta($user_id, 'ispag_allow_weekend_notifications', $allow_weekend);
            $result2 = update_user_meta($user_id, 'ispag_holiday_periods', json_encode($holiday_periods));

            $logger->log_db_change('notifications_manager', 'usermeta', 'UPDATE_DISCONNECTION_PREFS_FROM_PROFILE', [
                'allow_weekend' => $allow_weekend,
                'holiday_periods' => $holiday_periods,
                'result1' => $result1,
                'result2' => $result2
            ], $user_id);
        }

        $logger->log_user_action('notifications_manager', 'save_profile_fields_complete', [], $user_id);
    }

    /**
     * Récupère les notifications de type 'conceptual_window' non lues pour l'utilisateur courant
     */
    public static function get_conceptual_notifications_ajax()
    {
        $logger = self::get_logger();
        $user_id = get_current_user_id();
        $logger->log_user_action('notifications_manager', 'get_conceptual_notifications_ajax_start', [], $user_id);

        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        if ($user_id === 0)
        {
            $logger->log('notifications_manager', 'ERROR: User not logged in', $user_id);
            wp_send_json_error(['message' => 'Not authorized.']);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';

        // Récupérer les notifications non lues et non supprimées pour ce canal
        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND type = 'conceptual_window' AND is_read = 0 AND is_deleted = 0 ORDER BY sent_at DESC",
            $user_id
        ));

        $logger->log_db_change('notifications_manager', $table_name, 'FETCH_CONCEPTUAL_NOTIFICATIONS', ['user_id' => $user_id, 'count' => count($notifications)], $user_id);

        $logger->log_user_action('notifications_manager', 'get_conceptual_notifications_ajax_complete', [], $user_id);
        wp_send_json_success($notifications);
    }

    /**
     * Marquer une notification conceptuelle comme lue
     */
    public static function mark_conceptual_notification_read_ajax()
    {
        $logger = self::get_logger();
        $user_id = get_current_user_id();
        $logger->log_user_action('notifications_manager', 'mark_conceptual_notification_read_ajax_start', [], $user_id);

        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $notif_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($user_id === 0 || !$notif_id)
        {
            $logger->log('notifications_manager', 'ERROR: Invalid user_id or notification_id', $user_id, ['notif_id' => $notif_id]);
            wp_send_json_error(['message' => 'Invalid request.']);
        }

        $logger->log_user_action('notifications_manager', 'notification_id_received', ['notif_id' => $notif_id], $user_id);

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';

        $updated = $wpdb->update(
            $table_name,
            ['is_read' => 1, 'read_at' => current_time('mysql')],
            ['id' => $notif_id, 'user_id' => $user_id]
        );

        $logger->log_db_change('notifications_manager', $table_name, 'MARK_AS_READ', ['notif_id' => $notif_id, 'user_id' => $user_id, 'result' => $updated], $user_id);

        $logger->log_user_action('notifications_manager', 'mark_conceptual_notification_read_ajax_complete', [], $user_id);
        wp_send_json_success();
    }

    /**
     * Vérifie si une date est un week-end (samedi ou dimanche)
     */
    private static function is_weekend($date = null)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'is_weekend_called', ['date' => $date], 0);

        if ($date === null)
        {
            $date = time();
        }
        elseif (is_string($date))
        {
            $date = strtotime($date);
        }

        $day_of_week = date('N', $date); // 1 (lundi) à 7 (dimanche)
        $is_weekend = ($day_of_week >= 6); // Samedi (6) ou dimanche (7)
        $logger->log_user_action('notifications_manager', 'is_weekend_result', ['day_of_week' => $day_of_week, 'is_weekend' => $is_weekend], 0);

        return $is_weekend;
    }

    /**
     * Vérifie si un utilisateur autorise les notifications à une date donnée
     */
    public static function can_send_notification($user_id, $date = null)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'can_send_notification_start', ['user_id' => $user_id, 'date' => $date], 0);

        // Vérifier si c'est un week-end
        if (self::is_weekend($date))
        {
            $logger->log_user_action('notifications_manager', 'weekend_detected_checking_preferences', ['user_id' => $user_id], 0);
            // Récupérer la préférence depuis user_meta
            $allow_weekend = (bool) get_user_meta($user_id, 'ispag_allow_weekend_notifications', true);
            $logger->log_db_change('notifications_manager', 'usermeta', 'FETCH_ALLOW_WEEKEND', ['user_id' => $user_id, 'allow_weekend' => $allow_weekend], 0);

            if (!$allow_weekend)
            {
                $logger->log_user_action('notifications_manager', 'weekend_not_allowed', ['user_id' => $user_id], 0);
                return false;
            }
        }

        // Vérifier si la date est dans une période de vacances
        if (self::is_holiday_period($user_id, $date))
        {
            $logger->log_user_action('notifications_manager', 'holiday_period_detected', ['user_id' => $user_id], 0);
            return false;
        }

        $logger->log_user_action('notifications_manager', 'can_send_notification_allowed', ['user_id' => $user_id], 0);
        return true;
    }

    /**
     * Vérifie si une date est dans une période de vacances pour un utilisateur
     */
    private static function is_holiday_period($user_id, $date = null)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'is_holiday_period_start', ['user_id' => $user_id, 'date' => $date], 0);

        if ($date === null) {
            $date = time();
        } elseif (is_string($date)) {
            $date = strtotime($date);
        }
        $date_str = date('Y-m-d', $date);
        $logger->log_user_action('notifications_manager', 'date_parsed', ['date_str' => $date_str], 0);

        // Récupérer les périodes de vacances depuis user_meta
        $holiday_periods_json = get_user_meta($user_id, 'ispag_holiday_periods', true);
        $logger->log_db_change('notifications_manager', 'usermeta', 'FETCH_HOLIDAY_PERIODS', ['user_id' => $user_id, 'holiday_periods_json' => $holiday_periods_json], 0);

        $periods = json_decode($holiday_periods_json, true);

        if (!is_array($periods))
        {
            $logger->log_user_action('notifications_manager', 'no_holiday_periods_found', ['user_id' => $user_id], 0);
            return false;
        }

        $logger->log_user_action('notifications_manager', 'checking_holiday_periods', ['periods_count' => count($periods)], 0);

        foreach ($periods as $period)
        {
           // Ignorer les périodes vides ou mal formatées
            if (empty($period['start']) || empty($period['end'])) {
                continue;
            }

            $start = strtotime($period['start']);
            $end = strtotime($period['end'] . ' 23:59:59'); // Fin de journée

            $logger->log_user_action('notifications_manager', 'checking_period', [
                'start' => $start,
                'end' => $end,
                'current_date' => $date
            ], 0);

            if ($date >= $start && $date <= $end)
            {
                $logger->log_user_action('notifications_manager', 'date_in_holiday_period', ['user_id' => $user_id, 'start' => $start, 'end' => $end], 0);
                return true;
            }
            
        }

        $logger->log_user_action('notifications_manager', 'date_not_in_holiday_period', ['user_id' => $user_id], 0);
        return false;
    }

    /**
     * Récupère les préférences de déconnexion d'un utilisateur
     */
    public static function get_disconnection_preferences($user_id)
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'get_disconnection_preferences_start', ['user_id' => $user_id], 0);

        $allow_weekend = (bool) get_user_meta($user_id, 'ispag_allow_weekend_notifications', true);
        $holiday_periods_json = get_user_meta($user_id, 'ispag_holiday_periods', true);
        $holiday_periods = json_decode($holiday_periods_json, true) ?: [];

        $logger->log_db_change('notifications_manager', 'usermeta', 'FETCH_DISCONNECTION_PREFS', [
            'user_id' => $user_id,
            'allow_weekend' => $allow_weekend,
            'holiday_periods' => $holiday_periods
        ], 0);

        $logger->log_user_action('notifications_manager', 'get_disconnection_preferences_complete', [], 0);

        return [
            'allow_weekend_notifications' => $allow_weekend,
            'holiday_periods' => $holiday_periods
        ];
    }

    /**
     * Nettoie (marque comme supprimées) les notifications lues de plus de 30 jours,
     * et supprime physiquement de la DB celles plus anciennes que la limite de hard delete et déjà dans la corbeille.
     */
    public static function cleanup_old_notifications()
    {
        $logger = self::get_logger();
        $logger->log_user_action('notifications_manager', 'cleanup_old_notifications_start', [], 0);

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';
        
        // Dates limites
        $date_limit = date('Y-m-d H:i:s', strtotime('-15 days'));
        $date_limit_hard_delete = date('Y-m-d H:i:s', strtotime('-30 days'));

        // 1. Suppression logique des notifications lues de plus de 30 jours
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table_name SET is_deleted = 1 WHERE is_read = 1 AND is_deleted = 0 AND sent_at < %s",
                $date_limit
            )
        );

        $logger->log_db_change('notifications_manager', $table_name, 'SOFT_DELETE_OLD_NOTIFICATIONS', [
            'date_limit' => $date_limit,
            'affected_rows' => $updated
        ], 0);

        // 2. Suppression physique (hard delete) des éléments déjà dans la corbeille (is_deleted = 1) 
        // et plus anciens que la date limite de suppression définitive
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE is_deleted = 1 AND sent_at < %s",
                $date_limit_hard_delete
            )
        );

        $logger->log_db_change('notifications_manager', $table_name, 'HARD_DELETE_OLD_NOTIFICATIONS', [
            'date_limit_hard_delete' => $date_limit_hard_delete,
            'affected_rows' => $deleted
        ], 0);

        $logger->log_user_action('notifications_manager', 'cleanup_old_notifications_complete', [
            'soft_deleted_rows' => $updated,
            'hard_deleted_rows' => $deleted
        ], 0);
    }
}

// Initialisation de la classe du manager
ISPAG_Notifications_Manager::init();